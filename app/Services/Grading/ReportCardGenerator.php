<?php

namespace App\Services\Grading;

use App\Enums\AttitudePredicate;
use App\Enums\NotificationType;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Grade;
use App\Models\GradeConfig;
use App\Models\ReportCard;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\User;
use App\Services\Notification\SystemNotificationPublisher;
use App\Support\StudentWaTemplate;
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
    /**
     * Teks WA bawaan bila `schools.wa_template_rapor` belum diisi.
     *
     * Copy implementasi, bukan keputusan pemilik: kolomnya disebut blueprint,
     * bunyinya tidak. Hanya memakai token yang sudah mapan (butir 238, 239).
     */
    protected const DEFAULT_REPORT_TEMPLATE = 'Assalamu\'alaikum Bapak/Ibu [ortu]. '
        .'Rapor ananda [nama] di [sekolah] telah diterbitkan dan dapat dilihat pada portal orang tua. '
        .'Terima kasih.';

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

        // NFR 1.4 — "Response time API < 500ms untuk 95% request" pada VPS
        // 2C/2GB. Satu kelas berisi ratusan entri nilai, dan memuatnya per siswa
        // per mata pelajaran membuat satu generate menembus target itu jauh
        // sebelum kelasnya penuh. Seluruh nilai dan rapor kelas ini karena itu
        // diambil sekali di luar perulangan, lalu dibagikan ke tiap siswa.
        // Isinya persis sama dengan yang dulu diambil satu per satu.
        $gradesByStudent = $this->gradesOf($students, $classSubjects);
        $existingCards = $this->reportCardsOf($students, $class);

        $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'incomplete' => [], 'ignored' => []];

        DB::transaction(function () use ($students, $classSubjects, $class, $gradesByStudent, $existingCards, &$summary): void {
            foreach ($students as $student) {
                $existing = $existingCards->get($student->getKey());

                // Rapor yang sudah terbit terkunci (NILAI-03 poin 2).
                if ($existing?->is_published) {
                    $summary['skipped']++;

                    continue;
                }

                $result = $this->buildFor(
                    $student,
                    $class,
                    $classSubjects,
                    $gradesByStudent->get($student->getKey()) ?? collect(),
                );

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
    public function buildFor(
        Student $student,
        SchoolClass $class,
        Collection $classSubjects,
        ?Collection $studentGrades = null,
    ): array {
        // Dipanggil tanpa nilai yang sudah dimuat: ambil sendiri, sehingga
        // pemanggilan untuk satu siswa tetap berdiri sendiri.
        $studentGrades ??= Grade::query()
            ->forStudent($student->getKey())
            ->whereIn('class_subject_id', $classSubjects->pluck('id')->all())
            ->get();

        $finalScores = [];
        $missing = [];
        $ignored = [];

        foreach ($classSubjects as $classSubject) {
            $code = $classSubject->subject?->code;

            if ($code === null) {
                continue;
            }

            $grades = $studentGrades
                ->where('class_subject_id', $classSubject->getKey())
                ->values();

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

        // Sikap diambil dari himpunan nilai yang sama; `attitudeAverage()`
        // sendiri yang menyaring ATTITUDE dan hanya membaca skor serta jenis
        // penilaiannya, sehingga hasilnya identik dengan query terpisah.
        $attitude = $this->attitudeResolver->resolve(
            $this->aggregator->attitudeAverage($studentGrades),
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
     * Seluruh nilai satu kelas, dikelompokkan per siswa.
     *
     * `classSubject` ikut dimuat karena GradeWeightSnapshotter membacanya saat
     * melengkapi snapshot yang masih kosong; tanpa itu setiap baris nilai
     * memicu satu query relasi sendiri.
     *
     * @param  Collection<int, Student>  $students
     * @param  Collection<int, ClassSubject>  $classSubjects
     * @return Collection<int, Collection<int, Grade>>
     */
    protected function gradesOf(Collection $students, Collection $classSubjects): Collection
    {
        if ($students->isEmpty() || $classSubjects->isEmpty()) {
            return collect();
        }

        return Grade::query()
            ->with('classSubject')
            ->whereIn('student_id', $students->pluck('id')->all())
            ->whereIn('class_subject_id', $classSubjects->pluck('id')->all())
            ->get()
            ->groupBy('student_id');
    }

    /**
     * Rapor yang sudah ada untuk siswa-siswa ini, dipetakan per siswa.
     * Unique index (student_id, academic_year_id) menjamin paling banyak satu.
     *
     * @param  Collection<int, Student>  $students
     * @return Collection<int, ReportCard>
     */
    protected function reportCardsOf(Collection $students, SchoolClass $class): Collection
    {
        if ($students->isEmpty()) {
            return collect();
        }

        return ReportCard::query()
            ->whereIn('student_id', $students->pluck('id')->all())
            ->where('academic_year_id', $class->academic_year_id)
            ->get()
            ->keyBy('student_id');
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

        // Peralihan keadaan dan notifikasinya satu transaksi. Rapor yang
        // terbit tanpa notifikasinya masih dapat diperbaiki; notifikasi yang
        // terbit tanpa rapornya memberi tahu orang tua sesuatu yang tidak ada
        // (butir 244).
        DB::transaction(function () use ($reportCard, $publisher): void {
            $reportCard->forceFill([
                'is_published' => true,
                'published_at' => now(),
                'published_by' => $publisher->getKey(),
            ])->save();

            $this->lockConfigsFinalisedBy($reportCard);

            // NOTIF-03 poin 1 — "rapor diterbitkan". Hanya di sini, yaitu tepat
            // pada peralihan is_published false → true: generate draf,
            // membuka, mengunduh PDF, dan penerbitan kedua yang ditolak di atas
            // tidak pernah sampai ke baris ini (butir 246).
            $this->announcePublishedReport($reportCard);
        });

        return $reportCard->refresh();
    }

    /**
     * Notifikasi otomatis kepada orang tua siswa pemilik rapor.
     *
     * NOTIF-03 memusatkan alur ini pada orang tua, jadi akun siswa **tidak**
     * dipakai sebagai pengganti ketika orang tuanya belum punya akun portal.
     * Rapornya tetap terbit; yang tidak ada hanyalah notifikasinya
     * (butir 240).
     */
    protected function announcePublishedReport(ReportCard $reportCard): void
    {
        $student = $reportCard->student()->withoutGlobalScope(SchoolScope::class)->first();

        if ($student === null) {
            return;
        }

        $schoolId = (int) $reportCard->school_id;

        $school = School::query()->withoutGlobalScope(SchoolScope::class)->find($schoolId);

        $parent = $student->parent_user_id === null
            ? null
            : User::query()
                ->withoutGlobalScope(SchoolScope::class)
                ->where('school_id', $schoolId)
                ->active()
                ->find((int) $student->parent_user_id);

        $year = AcademicYear::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->find($reportCard->academic_year_id);

        app(SystemNotificationPublisher::class)->toUser(
            $parent,
            $schoolId,
            // Tidak ada pemetaan kategori eksplisit di sumber; ACADEMIC dipilih
            // karena rapor adalah hasil akademik (butir 236).
            NotificationType::Academic,
            'Rapor diterbitkan',
            $year === null
                ? sprintf('Rapor %s telah diterbitkan dan dapat dilihat pada portal orang tua.', $student->full_name)
                : sprintf(
                    'Rapor %s semester %s tahun ajaran %s telah diterbitkan dan dapat dilihat pada portal orang tua.',
                    $student->full_name,
                    (string) $year->semester,
                    (string) $year->name,
                ),
            StudentWaTemplate::render(
                $school?->wa_template_rapor,
                self::DEFAULT_REPORT_TEMPLATE,
                $student,
                $school,
                $parent,
            ),
        );
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
