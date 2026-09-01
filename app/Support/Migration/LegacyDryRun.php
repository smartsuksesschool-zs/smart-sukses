<?php

namespace App\Support\Migration;

use App\Models\AcademicYear;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/**
 * M2 — analisis kering (dry run) berkas sekolah terhadap skema yang berlaku.
 *
 * Kelas ini **tidak pernah menulis**. Tidak ada mode terap di sini, dan tidak
 * ada jalan menuju satu pun `save()`/`create()`. Mode terap adalah pekerjaan M3
 * dan menuntut keputusan sekolah yang hari ini belum ada (butir 454).
 *
 * Semua query membawa `school_id` eksplisit: perintah artisan berjalan tanpa
 * sesi login sehingga SchoolScope tidak aktif (docs/data-migration-readiness.md
 * §3).
 */
class LegacyDryRun
{
    public const MASTER_READY = 'STUDENT_MASTER_READY';

    public const ACCOUNT_READY = 'ACCOUNT_READY';

    public const ACCOUNT_BLOCKED = 'ACCOUNT_BLOCKED';

    /**
     * Kolom `students` yang NOT NULL tanpa default, karenanya wajib ada di
     * sumber sebelum satu baris pun boleh ditulis.
     *
     * @var array<int, string>
     */
    public const REQUIRED_STUDENT_FIELDS = ['nis', 'full_name', 'gender'];

    protected AssignmentClassifier $classifier;

    public function __construct(
        protected LegacyWorkbook $workbook,
        protected School $school,
        protected ?AcademicYear $year = null,
    ) {
        $this->classifier = new AssignmentClassifier;
    }

    /**
     * @return array<string, mixed>
     */
    public function students(): array
    {
        $read = $this->workbook->students();

        $rejected = [];
        $missing = [];
        $create = [];
        $match = [];
        $duplicates = [];
        $nameCollisions = [];
        $readiness = [self::MASTER_READY => 0, self::ACCOUNT_READY => 0, self::ACCOUNT_BLOCKED => 0];
        $accountBlockers = [];
        $classLabels = [];
        $seenNis = [];
        $seenName = [];
        $valid = 0;

        foreach ($read['rows'] as $row) {
            $line = $row['source_line'];
            $label = trim((string) ($row['class_label'] ?? ''));

            if ($label !== '') {
                $classLabels[$label] = ($classLabels[$label] ?? 0) + 1;
            }

            $gender = $this->normaliseGender((string) ($row['gender'] ?? ''));
            $nis = trim((string) ($row['nis'] ?? ''));

            $absent = [];

            foreach (self::REQUIRED_STUDENT_FIELDS as $field) {
                $value = $field === 'gender' ? $gender : trim((string) ($row[$field] ?? ''));

                if ($value === '') {
                    $absent[] = $field;
                    $missing[$field] = ($missing[$field] ?? 0) + 1;
                }
            }

            if ($label === '') {
                $absent[] = 'class_label';
                $missing['class_label'] = ($missing['class_label'] ?? 0) + 1;
            }

            // Nama sama bukan duplikat — dilaporkan sebagai peringatan saja,
            // tidak pernah sebagai identitas (docs/data-migration-readiness.md
            // §6.2 butir 3).
            $fold = mb_strtolower(trim((string) ($row['full_name'] ?? '')));

            if ($fold !== '' && isset($seenName[$fold])) {
                $nameCollisions[] = ['line' => $line, 'first_seen' => $seenName[$fold]];
            } elseif ($fold !== '') {
                $seenName[$fold] = $line;
            }

            if ($absent !== []) {
                $rejected[] = ['line' => $line, 'reasons' => $absent];
                $readiness[self::ACCOUNT_BLOCKED]++;
                $accountBlockers['master_data_belum_lengkap'] = ($accountBlockers['master_data_belum_lengkap'] ?? 0) + 1;

                continue;
            }

            if (isset($seenNis[$nis])) {
                $duplicates[] = ['line' => $line, 'first_seen' => $seenNis[$nis]];

                continue;
            }

            $seenNis[$nis] = $line;
            $valid++;
            $readiness[self::MASTER_READY]++;

            $existing = Student::query()
                ->where('school_id', $this->school->id)
                ->where('nis', $nis)
                ->exists();

            $existing ? $match[] = $line : $create[] = $line;

            [$state, $blocker] = $this->accountReadiness($row);

            if ($state === self::ACCOUNT_READY) {
                $readiness[self::ACCOUNT_READY]++;
            } else {
                $readiness[self::ACCOUNT_BLOCKED]++;
                $accountBlockers[$blocker] = ($accountBlockers[$blocker] ?? 0) + 1;
            }
        }

        return [
            'source_rows' => count($read['rows']),
            'valid_rows' => $valid,
            'create_candidates' => count($create),
            'match_candidates' => count($match),
            'rejected_rows' => count($rejected),
            'rejected' => $rejected,
            'duplicates' => $duplicates,
            'name_collisions' => $nameCollisions,
            'missing_required' => $missing,
            'readiness' => $readiness,
            'account_blockers' => $accountBlockers,
            'class_placement' => $this->classPlacement($classLabels),
            'declared_totals' => $read['declared_totals'],
            'ignored_rows' => $read['ignored_rows'],
            'unknown_headings' => $read['unknown_headings'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function teachers(): array
    {
        $read = $this->workbook->teachers();

        $people = [];
        $roles = [];
        $subjects = [];
        $nonAcademic = [];
        $ambiguous = [];
        $readiness = [self::ACCOUNT_READY => 0, self::ACCOUNT_BLOCKED => 0];
        $accountBlockers = [];
        $seenName = [];
        $collisions = [];

        foreach ($read['rows'] as $row) {
            $line = $row['source_line'];
            $classified = $this->classifier->classify((string) ($row['assignment'] ?? ''));
            $role = $this->classifier->roleFor($classified);

            $roles[$role] = ($roles[$role] ?? 0) + 1;

            foreach ($classified as $item) {
                match ($item['kind']) {
                    AssignmentClassifier::SUBJECT => $subjects[$item['canonical']] = ($subjects[$item['canonical']] ?? 0) + 1,
                    AssignmentClassifier::NON_ACADEMIC => $nonAcademic[$item['canonical']] = ($nonAcademic[$item['canonical']] ?? 0) + 1,
                    AssignmentClassifier::AMBIGUOUS => $ambiguous[$item['canonical']] = ($ambiguous[$item['canonical']] ?? 0) + 1,
                    default => null,
                };
            }

            $fold = mb_strtolower(trim((string) ($row['full_name'] ?? '')));

            if ($fold !== '' && isset($seenName[$fold])) {
                $collisions[] = ['line' => $line, 'first_seen' => $seenName[$fold]];
            } elseif ($fold !== '') {
                $seenName[$fold] = $line;
            }

            $email = trim((string) ($row['account_email'] ?? ''));
            $matched = false;

            if ($email !== '' && Validator::make(['e' => $email], ['e' => ['email']])->passes()) {
                $user = User::query()->where('email', $email)->first();

                if ($user === null) {
                    $readiness[self::ACCOUNT_READY]++;
                } elseif ((int) $user->school_id === (int) $this->school->id) {
                    $readiness[self::ACCOUNT_READY]++;
                    $matched = true;
                } else {
                    // Surel unik lintas cabang: bentrok adalah galat yang harus
                    // terlihat, bukan diselesaikan importer (§6.2 butir 6).
                    $readiness[self::ACCOUNT_BLOCKED]++;
                    $accountBlockers['surel_dipakai_cabang_lain'] = ($accountBlockers['surel_dipakai_cabang_lain'] ?? 0) + 1;
                }
            } else {
                $readiness[self::ACCOUNT_BLOCKED]++;
                $key = $email === '' ? 'surel_tidak_ada_di_sumber' : 'format_surel_tidak_valid';
                $accountBlockers[$key] = ($accountBlockers[$key] ?? 0) + 1;
            }

            $people[] = ['line' => $line, 'role' => $role, 'matched' => $matched];
        }

        ksort($subjects);
        ksort($nonAcademic);
        ksort($ambiguous);

        return [
            'source_rows' => count($read['rows']),
            'people' => count($people),
            'distinct_people' => count($seenName),
            'name_collisions' => $collisions,
            'roles' => $roles,
            'subject_candidates' => $this->subjectCandidates($subjects),
            'non_academic_tokens' => $nonAcademic,
            'ambiguous_tokens' => $ambiguous,
            'readiness' => $readiness,
            'account_blockers' => $accountBlockers,
            'match_candidates' => count(array_filter($people, fn ($p) => $p['matched'])),
            'create_candidates' => count(array_filter($people, fn ($p) => ! $p['matched'])),
            'unknown_headings' => $read['unknown_headings'],
        ];
    }

    /**
     * Akun siswa menuntut satu baris `users`, dan `users.email` NOT NULL lagi
     * unik global. Selama surel siswa belum terkumpul, penyediaan akun mustahil
     * — dan itu **tidak** menghalangi impor master siswa, karena
     * `students.user_id` nullable (butir 455).
     *
     * @param  array<string, mixed>  $row
     * @return array{0: string, 1: string}
     */
    protected function accountReadiness(array $row): array
    {
        $email = trim((string) ($row['account_email'] ?? ''));

        if ($email === '') {
            return [self::ACCOUNT_BLOCKED, 'surel_tidak_ada_di_sumber'];
        }

        if (Validator::make(['e' => $email], ['e' => ['email']])->fails()) {
            return [self::ACCOUNT_BLOCKED, 'format_surel_tidak_valid'];
        }

        $user = User::query()->where('email', $email)->first();

        if ($user !== null && (int) $user->school_id !== (int) $this->school->id) {
            return [self::ACCOUNT_BLOCKED, 'surel_dipakai_cabang_lain'];
        }

        return [self::ACCOUNT_READY, ''];
    }

    /**
     * @param  array<string, int>  $labels
     * @return array<int, array<string, mixed>>
     */
    protected function classPlacement(array $labels): array
    {
        ksort($labels);
        $out = [];

        foreach ($labels as $label => $count) {
            // PHP mengubah kunci array berupa string angka menjadi integer, jadi
            // label "10" kembali sebagai int 10. Dikembalikan ke string supaya
            // pencocokan ke `classes.name` tetap membandingkan jenis yang sama.
            $label = (string) $label;
            $grade = $this->gradeLevel($label);

            $exists = null;

            if ($this->year !== null) {
                $exists = SchoolClass::query()
                    ->where('school_id', $this->school->id)
                    ->where('academic_year_id', $this->year->id)
                    ->where('name', $label)
                    ->exists();
            }

            $out[] = [
                'label' => $label,
                'students' => $count,
                'grade_level' => $grade,
                'exists_in_target_year' => $exists,
                // Tidak ada nama rombel di sumber. Satu label = satu kelas;
                // memecahnya jadi "10-A"/"10-B" akan mengarang rombel yang tidak
                // pernah dinyatakan sekolah (butir 456).
                'blocker' => match (true) {
                    $grade === null => 'tingkat_kelas_tidak_terbaca',
                    $this->year === null => 'tahun_ajaran_tujuan_belum_ada',
                    $exists === false => 'kelas_belum_ada_di_tahun_ajaran',
                    default => null,
                },
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $subjects
     * @return array<int, array<string, mixed>>
     */
    protected function subjectCandidates(array $subjects): array
    {
        $out = [];

        foreach ($subjects as $name => $teachers) {
            $code = $this->classifier->proposeCode($name);

            $out[] = [
                'name' => $name,
                'proposed_code' => $code,
                'teachers' => $teachers,
                'exists_by_name' => Subject::query()
                    ->where('school_id', $this->school->id)
                    ->where('name', $name)
                    ->exists(),
                'code_taken' => Subject::query()
                    ->where('school_id', $this->school->id)
                    ->where('code', $code)
                    ->exists(),
            ];
        }

        return $out;
    }

    protected function gradeLevel(string $label): ?int
    {
        $key = mb_strtoupper(trim($label));
        $key = preg_replace('/^KELAS\s+/', '', $key) ?? $key;

        return match ($key) {
            '10', 'X' => 10,
            '11', 'XI' => 11,
            '12', 'XII' => 12,
            default => null,
        };
    }

    protected function normaliseGender(string $value): string
    {
        return match (mb_strtoupper(trim($value))) {
            'L', 'LAKI-LAKI', 'LAKI LAKI', 'PRIA' => 'L',
            'P', 'PEREMPUAN', 'WANITA' => 'P',
            default => '',
        };
    }
}
