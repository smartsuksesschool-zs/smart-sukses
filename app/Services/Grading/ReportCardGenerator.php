<?php

namespace App\Services\Grading;

use App\Enums\AttitudePredicate;
use App\Models\ClassSubject;
use App\Models\Grade;
use App\Models\GradeConfig;
use App\Models\ReportCard;
use App\Models\SchoolClass;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * NILAI-03 — "Sebagai Wali Kelas, saya dapat menerbitkan (publish) rapor untuk
 * semua siswa di kelas saya."
 * API 4.8 — POST /report-cards/generate, POST /report-cards/{id}/publish.
 *
 * Keputusan Sprint 4: rapor adalah *hasil kalkulasi* dari bobot snapshot pada
 * `grades.weight`, bukan dari konfigurasi yang berlaku saat generate berjalan.
 */
class ReportCardGenerator
{
    public function __construct(
        protected FinalScoreCalculator $calculator,
        protected ComponentScoreAggregator $aggregator,
        protected AttitudePredicateResolver $attitudeResolver,
        protected GradeWeightSnapshotter $snapshotter,
        protected GradeConfigVersionManager $versionManager,
    ) {}

    /**
     * Membuat/memperbarui draft rapor untuk seluruh siswa aktif di satu kelas.
     *
     * `incomplete` dipetakan nama siswa → (kode mapel → alasan), sehingga wali
     * kelas tahu *mengapa* sebuah mapel belum bernilai — bukan sekadar bahwa
     * mapel itu belum lengkap. Alasannya datang apa adanya dari
     * FinalScoreResult::$reason.
     *
     * `ignored` dipetakan kode mapel → komponen sumatif yang diabaikan
     * konfigurasi. Sengaja dikumpulkan per mapel, bukan per siswa: Grade Config
     * berlaku untuk satu mapel pada satu tahun ajaran, sehingga komponen yang
     * sama akan terabaikan bagi seluruh kelas — mendaftarnya per siswa hanya
     * mengulang pesan yang sama sebanyak jumlah siswa.
     *
     * @return array{created: int, updated: int, skipped: int, incomplete: array<string, array<string, string>>, ignored: array<string, array<int, string>>}
     */
    public function generateForClass(SchoolClass $class): array
    {
        $students = $this->studentsOf($class);
        $classSubjects = $class->classSubjects()->with('subject')->get();

        $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'incomplete' => [], 'ignored' => []];

        DB::transaction(function () use ($students, $classSubjects, $class, &$summary): void {
            foreach ($students as $student) {
                $existing = ReportCard::query()
                    ->where('student_id', $student->getKey())
                    ->where('academic_year_id', $class->academic_year_id)
                    ->first();

                // Rapor yang sudah terbit terkunci (NILAI-03 poin 2).
                if ($existing?->is_published) {
                    $summary['skipped']++;

                    continue;
                }

                $result = $this->buildFor($student, $class, $classSubjects);

                if ($result['missing'] !== []) {
                    $summary['incomplete'][$student->full_name] = $result['missing'];
                }

                foreach ($result['ignored'] as $code => $types) {
                    $summary['ignored'][$code] = array_values(array_unique(array_merge(
                        $summary['ignored'][$code] ?? [],
                        $types,
                    )));
                }

                if ($existing === null) {
                    ReportCard::query()->create([
                        'school_id' => $class->school_id,
                        'student_id' => $student->getKey(),
                        'class_id' => $class->getKey(),
                        'academic_year_id' => $class->academic_year_id,
                        'final_scores' => $result['final_scores'],
                        'attitude_score' => $result['attitude'],
                        'is_published' => false,
                    ]);
                    $summary['created']++;

                    continue;
                }

                $existing->update([
                    'class_id' => $class->getKey(),
                    'final_scores' => $result['final_scores'],
                    'attitude_score' => $result['attitude'],
                ]);
                $summary['updated']++;
            }
        });

        return $summary;
    }

    /**
     * Menyusun nilai akhir seluruh mapel + predikat sikap untuk satu siswa.
     *
     * @param  Collection<int, ClassSubject>  $classSubjects
     * @return array{final_scores: array<string, float>, attitude: AttitudePredicate|null, missing: array<string, string>, ignored: array<string, array<int, string>>}
     */
    public function buildFor(Student $student, SchoolClass $class, Collection $classSubjects): array
    {
        $finalScores = [];
        $missing = [];
        $ignored = [];

        foreach ($classSubjects as $classSubject) {
            $code = $classSubject->subject?->code;

            if ($code === null) {
                continue;
            }

            $grades = Grade::query()
                ->forStudent($student->getKey())
                ->forClassSubject($classSubject->getKey())
                ->get();

            // Nilai yang belum sempat mendapat snapshot bobot dilengkapi di
            // sini — backfill memutakhirkan model di memori sekaligus.
            $this->snapshotter->backfill($grades);

            $result = $this->calculator->calculate($grades);

            // Dilaporkan terlepas dari lengkap atau tidaknya nilai: mapel yang
            // nilai akhirnya sudah keluar pun bisa menyembunyikan komponen
            // sumatif yang diabaikan konfigurasi.
            if ($result->ignoredComponents !== []) {
                $ignored[$code] = $result->ignoredComponents;
            }

            if (! $result->isComplete) {
                $missing[$code] = $result->reason ?? 'Nilai akhir belum dapat dihitung.';

                continue;
            }

            $finalScores[$code] = $result->score;
        }

        $attitudeGrades = Grade::query()
            ->forStudent($student->getKey())
            ->whereIn('class_subject_id', $classSubjects->pluck('id')->all())
            ->get();

        $attitude = $this->attitudeResolver->resolve(
            $this->aggregator->attitudeAverage($attitudeGrades),
            $class->school,
        );

        return [
            'final_scores' => $finalScores,
            'attitude' => $attitude,
            'missing' => $missing,
            'ignored' => $ignored,
        ];
    }

    /**
     * NILAI-03 poin 1 — "sistem memvalidasi bahwa semua mata pelajaran sudah
     * memiliki nilai akhir".
     *
     * @return array<int, string> kode mapel yang belum punya nilai akhir
     */
    public function missingSubjectsFor(ReportCard $reportCard): array
    {
        $codes = ClassSubject::query()
            ->where('class_id', $reportCard->class_id)
            ->where('academic_year_id', $reportCard->academic_year_id)
            ->with('subject')
            ->get()
            ->map(fn (ClassSubject $classSubject) => $classSubject->subject?->code)
            ->filter()
            ->all();

        $scored = array_keys($reportCard->final_scores ?? []);

        return array_values(array_diff($codes, $scored));
    }

    /**
     * Menerbitkan satu rapor. Nilai ikut terkunci setelahnya
     * (Grade::isLocked()).
     *
     * @throws ValidationException
     */
    public function publish(ReportCard $reportCard, User $publisher): ReportCard
    {
        if ($reportCard->is_published) {
            throw ValidationException::withMessages([
                'report_card' => 'Rapor ini sudah diterbitkan.',
            ]);
        }

        $missing = $this->missingSubjectsFor($reportCard);

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'report_card' => 'Belum dapat diterbitkan — mata pelajaran berikut belum memiliki nilai akhir: '
                    .implode(', ', $missing).'.',
            ]);
        }

        $reportCard->forceFill([
            'is_published' => true,
            'published_at' => now(),
            'published_by' => $publisher->getKey(),
        ])->save();

        $this->lockConfigsFinalisedBy($reportCard);

        return $reportCard->refresh();
    }

    /**
     * Keputusan Sprint 4 butir 4 — "LOCKED setelah rapor/finalisasi semester."
     *
     * Konfigurasi dikunci begitu tidak ada lagi yang membutuhkannya: setiap
     * siswa aktif di seluruh kelas yang mengampu mata pelajaran itu sudah
     * memegang rapor terbit. Penguncian lebih awal — mis. pada rapor siswa
     * pertama — akan menghentikan penilaian siswa lain di kelas yang sama,
     * karena nilai baru tidak lagi mendapat snapshot bobot.
     *
     * @return int jumlah konfigurasi yang baru saja dikunci
     */
    public function lockConfigsFinalisedBy(ReportCard $reportCard): int
    {
        $configIds = Grade::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $reportCard->school_id)
            ->where('student_id', $reportCard->student_id)
            ->where('academic_year_id', $reportCard->academic_year_id)
            ->whereNotNull('grade_config_id')
            ->distinct()
            ->pluck('grade_config_id');

        $locked = 0;

        foreach ($configIds as $configId) {
            $config = GradeConfig::query()
                ->withoutGlobalScope(SchoolScope::class)
                ->find($configId);

            if ($config === null || ! $this->isFullyReported($config)) {
                continue;
            }

            $locked += $this->versionManager->lockIfActive($config) ? 1 : 0;
        }

        return $locked;
    }

    /**
     * Benarkah seluruh siswa yang kebijakan penilaiannya diatur konfigurasi ini
     * sudah menerima rapor terbit?
     */
    protected function isFullyReported(GradeConfig $config): bool
    {
        $classIds = ClassSubject::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $config->school_id)
            ->where('subject_id', $config->subject_id)
            ->where('academic_year_id', $config->academic_year_id)
            ->pluck('class_id')
            ->unique()
            ->values()
            ->all();

        if ($classIds === []) {
            return true;
        }

        $studentIds = Student::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $config->school_id)
            ->active()
            ->inAnyClass($classIds)
            ->pluck('id');

        if ($studentIds->isEmpty()) {
            return true;
        }

        $published = ReportCard::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $config->school_id)
            ->where('academic_year_id', $config->academic_year_id)
            ->whereIn('student_id', $studentIds)
            ->published()
            ->count();

        return $published === $studentIds->count();
    }

    /**
     * NILAI-03 — menerbitkan rapor seluruh siswa di satu kelas sekaligus.
     *
     * @return array{published: int, blocked: array<string, array<int, string>>}
     */
    public function publishClass(SchoolClass $class, User $publisher): array
    {
        $reportCards = ReportCard::query()
            ->where('class_id', $class->getKey())
            ->where('academic_year_id', $class->academic_year_id)
            ->draft()
            ->with('student')
            ->get();

        $published = 0;
        $blocked = [];

        foreach ($reportCards as $reportCard) {
            $missing = $this->missingSubjectsFor($reportCard);

            if ($missing !== []) {
                $blocked[$reportCard->student?->full_name ?? "#{$reportCard->getKey()}"] = $missing;

                continue;
            }

            $this->publish($reportCard, $publisher);
            $published++;
        }

        return ['published' => $published, 'blocked' => $blocked];
    }

    /**
     * Siswa aktif di satu kelas — dipakai UI untuk pratinjau sebelum generate.
     *
     * @return Collection<int, Student>
     */
    public function studentsOf(SchoolClass $class): Collection
    {
        return Student::query()
            ->active()
            ->inClass($class->getKey())
            ->orderBy('full_name')
            ->get();
    }
}
