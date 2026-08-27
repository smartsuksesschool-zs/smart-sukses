<?php

namespace Tests\Feature\Cbt;

use App\Enums\AssessmentType;
use App\Enums\AuditAction;
use App\Enums\GradeType;
use App\Enums\RoleName;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\Grade;
use App\Models\GradeConfig;
use App\Models\ReportCard;
use App\Models\Scopes\SchoolScope;
use App\Models\User;
use App\Services\Cbt\ExamAttemptService;
use App\Services\Cbt\ExamGradeBridge;
use App\Services\Grading\ComponentScoreAggregator;
use App\Services\Grading\FinalScoreCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Cbt\Concerns\BuildsStudentExamFixture;
use Tests\TestCase;

/**
 * Jembatan dari hasil CBT ke nilai akademik.
 *
 * Ini satu-satunya tempat CBT menyentuh penilaian yang sudah berjalan, dan
 * karena itu satu-satunya tempat kesalahan CBT dapat berakhir di rapor seorang
 * siswa. Yang diuji bukan hanya bahwa nilainya terbentuk, melainkan bahwa
 * seluruh semantik penilaian yang sudah ada tetap yang berlaku — snapshot
 * bobot, pembedaan formatif/sumatif, dan kunci rapor terbit.
 */
class ExamGradeBridgeTest extends TestCase
{
    use BuildsStudentExamFixture, RefreshDatabase;

    protected ExamGradeBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStudentExamFixture();
        $this->bridge = app(ExamGradeBridge::class);
    }

    // ------------------------------------------------------------ jalur bersih

    public function test_a_submitted_attempt_becomes_an_academic_grade(): void
    {
        [$attempt, $exam] = $this->submittedAttempt();

        $grade = $this->bridge->bridge(
            $attempt,
            $this->teacherA,
            GradeType::Daily,
            AssessmentType::Formative,
        );

        $this->assertSame($this->schoolA->getKey(), (int) $grade->school_id);
        $this->assertSame($this->studentA->getKey(), (int) $grade->student_id);
        $this->assertSame($this->classSubjectA->getKey(), (int) $grade->class_subject_id);
        $this->assertSame($this->yearA->getKey(), (int) $grade->academic_year_id);
        $this->assertSame(GradeType::Daily, $grade->grade_type);
        $this->assertSame(AssessmentType::Formative, $grade->assessment_type);
        $this->assertSame($this->teacherA->getKey(), (int) $grade->graded_by);
        $this->assertSame('CBT: '.$exam->title, $grade->description);

        // Tautannya dua arah: pengerjaan menunjuk nilainya.
        $this->assertSame($grade->getKey(), (int) $attempt->fresh()->grade_id);
    }

    public function test_the_score_is_copied_exactly(): void
    {
        // Dua dari tiga soal benar → 66.67, angka yang pembulatannya terlihat.
        [$attempt] = $this->submittedAttempt([1.0, 1.0, 1.0], correctCount: 2);

        $this->assertSame('66.67', $attempt->score);

        $grade = $this->bridge->bridge($attempt, $this->teacherA, GradeType::Daily, AssessmentType::Formative);

        $this->assertSame('66.67', $grade->score);
    }

    public function test_a_custom_description_is_kept(): void
    {
        [$attempt] = $this->submittedAttempt();

        $grade = $this->bridge->bridge(
            $attempt,
            $this->teacherA,
            GradeType::Assignment,
            AssessmentType::Formative,
            'Tugas daring pekan ke-3',
        );

        $this->assertSame('Tugas daring pekan ke-3', $grade->description);
    }

    // --------------------------------------------------------- jenis nilai

    /**
     * @return array<string, array{0: GradeType}>
     */
    public static function allowedTypes(): array
    {
        return [
            'Harian' => [GradeType::Daily],
            'Tugas' => [GradeType::Assignment],
            'UTS' => [GradeType::Midterm],
            'UAS' => [GradeType::Final],
        ];
    }

    #[DataProvider('allowedTypes')]
    public function test_each_allowed_grade_type_is_accepted(GradeType $type): void
    {
        [$attempt] = $this->submittedAttempt();

        $grade = $this->bridge->bridge($attempt, $this->teacherA, $type, AssessmentType::Formative);

        $this->assertSame($type, $grade->grade_type);
    }

    public function test_attitude_is_refused(): void
    {
        [$attempt] = $this->submittedAttempt();

        $this->assertRefused(
            fn () => $this->bridge->bridge($attempt, $this->teacherA, GradeType::Attitude, AssessmentType::Formative),
            'Jenis nilai ini tidak dapat dipakai untuk hasil ujian online.',
        );
    }

    /**
     * Butir 328 — SKILL disebut sumber tepat satu kali, sebagai nilai enum di
     * ERD. Tidak ada satu pun sumber yang menyatakan tes objektif termasuk
     * penilaian keterampilan, jadi ia tidak ditawarkan.
     */
    public function test_skill_is_not_offered_for_cbt(): void
    {
        [$attempt] = $this->submittedAttempt();

        $this->assertArrayNotHasKey(GradeType::Skill->value, ExamGradeBridge::gradeTypeOptions());
        $this->assertArrayNotHasKey(GradeType::Attitude->value, ExamGradeBridge::gradeTypeOptions());
        $this->assertSame(
            ['DAILY', 'ASSIGNMENT', 'MIDTERM', 'FINAL'],
            array_keys(ExamGradeBridge::gradeTypeOptions()),
        );

        $this->assertRefused(
            fn () => $this->bridge->bridge($attempt, $this->teacherA, GradeType::Skill, AssessmentType::Formative),
            'Jenis nilai ini tidak dapat dipakai untuk hasil ujian online.',
        );
    }

    public function test_both_assessment_types_are_accepted(): void
    {
        foreach ([AssessmentType::Formative, AssessmentType::Summative] as $assessment) {
            [$attempt] = $this->submittedAttempt();

            $grade = $this->bridge->bridge($attempt, $this->teacherA, GradeType::Daily, $assessment);

            $this->assertSame($assessment, $grade->assessment_type);
        }
    }

    // ------------------------------------------------------- keadaan pengerjaan

    public function test_an_attempt_still_in_progress_cannot_bridge(): void
    {
        $exam = $this->openExamIn($this->classSubjectA);
        $attempt = app(ExamAttemptService::class)->startOrResume($this->studentUserA, $exam->getKey());

        $this->assertRefused(
            fn () => $this->bridge->bridge($attempt, $this->teacherA, GradeType::Daily, AssessmentType::Formative),
            'Hanya pengerjaan yang sudah dikumpulkan yang dapat dimasukkan ke nilai.',
        );

        $this->assertSame(0, Grade::query()->count());
    }

    public function test_an_attempt_without_a_score_cannot_bridge(): void
    {
        [$attempt] = $this->submittedAttempt();

        $attempt->forceFill(['score' => null])->save();

        $this->assertRefused(
            fn () => $this->bridge->bridge($attempt->fresh(), $this->teacherA, GradeType::Daily, AssessmentType::Formative),
            'Pengerjaan ini belum memiliki nilai.',
        );
    }

    // ------------------------------------------------------------ kewenangan

    public function test_a_teacher_of_another_class_subject_is_refused(): void
    {
        [$attempt] = $this->submittedAttempt();

        $stranger = $this->userIn($this->schoolA, RoleName::Guru);

        $this->assertRefused(
            fn () => $this->bridge->bridge($attempt, $stranger, GradeType::Daily, AssessmentType::Formative),
            'Anda tidak berwenang memasukkan hasil ujian ini ke nilai.',
        );

        $this->assertSame(0, Grade::query()->count());
    }

    public function test_an_actor_from_another_school_is_refused(): void
    {
        [$attempt] = $this->submittedAttempt();

        $this->assertRefused(
            fn () => $this->bridge->bridge(
                $attempt,
                $this->userIn($this->schoolB, RoleName::SchoolAdmin),
                GradeType::Daily,
                AssessmentType::Formative,
            ),
            'Anda tidak berwenang memasukkan hasil ujian ini ke nilai.',
        );
    }

    /**
     * Butir 325 — melihat hasil dan memasukkannya ke nilai adalah dua
     * kewenangan berbeda.
     */
    public function test_a_head_teacher_may_read_results_but_not_bridge(): void
    {
        [$attempt, $exam] = $this->submittedAttempt();

        $kepala = $this->userIn($this->schoolA, RoleName::KepalaSekolah);

        $this->assertTrue($kepala->can('viewResults', $exam));
        $this->assertFalse($kepala->can('bridgeToGrade', $exam));

        $this->assertRefused(
            fn () => $this->bridge->bridge($attempt, $kepala, GradeType::Daily, AssessmentType::Formative),
            'Anda tidak berwenang memasukkan hasil ujian ini ke nilai.',
        );
    }

    public function test_a_treasurer_is_refused(): void
    {
        [$attempt] = $this->submittedAttempt();

        $this->assertRefused(
            fn () => $this->bridge->bridge(
                $attempt,
                $this->userIn($this->schoolA, RoleName::Bendahara),
                GradeType::Daily,
                AssessmentType::Formative,
            ),
            'Anda tidak berwenang memasukkan hasil ujian ini ke nilai.',
        );
    }

    public function test_a_school_admin_of_the_same_branch_may_bridge(): void
    {
        [$attempt] = $this->submittedAttempt();

        $admin = $this->userIn($this->schoolA, RoleName::SchoolAdmin);

        $grade = $this->bridge->bridge($attempt, $admin, GradeType::Daily, AssessmentType::Formative);

        $this->assertSame($admin->getKey(), (int) $grade->graded_by);
    }

    /**
     * Id pengerjaan yang ditukar dengan milik cabang lain gagal tertutup —
     * bahkan bagi aktor yang berwenang penuh di cabangnya sendiri.
     */
    public function test_a_tampered_cross_tenant_attempt_fails_closed(): void
    {
        [$foreignAttempt] = $this->submittedAttempt(classSubject: $this->classSubjectB, student: $this->studentB, user: $this->studentUserB);

        $this->assertRefused(
            fn () => $this->bridge->bridge(
                $foreignAttempt,
                $this->userIn($this->schoolA, RoleName::SchoolAdmin),
                GradeType::Daily,
                AssessmentType::Formative,
            ),
            'Anda tidak berwenang memasukkan hasil ujian ini ke nilai.',
        );

        $this->assertSame(0, Grade::query()->withoutGlobalScope(SchoolScope::class)->count());
    }

    // ----------------------------------------------------- siklus hidup Grade

    /**
     * Butir 330 — nilainya dibuat lewat `Grade::query()->create()`, sehingga
     * `Grade::booted()` mengambil snapshot bobot dan grade_config_id persis
     * seperti pada input nilai biasa. Insert massal akan melewatinya.
     */
    public function test_the_grade_receives_its_weight_snapshot(): void
    {
        $config = $this->activeConfigFor(GradeType::Daily, 0.40);

        [$attempt] = $this->submittedAttempt();

        $grade = $this->bridge->bridge($attempt, $this->teacherA, GradeType::Daily, AssessmentType::Summative);

        $this->assertSame('0.40', $grade->weight);
        $this->assertSame($config->getKey(), (int) $grade->grade_config_id);
    }

    /**
     * Butir 331 — tanpa konfigurasi aktif, nilainya tetap tersimpan dengan
     * bobot NULL. Itu perilaku input nilai yang sudah ada, dan jembatan ini
     * tidak mengubahnya.
     */
    public function test_without_an_active_config_the_grade_is_stored_without_weight(): void
    {
        [$attempt] = $this->submittedAttempt();

        $grade = $this->bridge->bridge($attempt, $this->teacherA, GradeType::Daily, AssessmentType::Summative);

        $this->assertNull($grade->weight);
        $this->assertNull($grade->grade_config_id);
        $this->assertSame(1, Grade::query()->count());
    }

    /**
     * Konfigurasi LOCKED: perilaku yang sudah ada dipertahankan — nilai tetap
     * tersimpan tanpa bobot, dan tidak ada konfigurasi yang dibuka otomatis
     * (butir 338).
     */
    public function test_a_locked_config_still_stores_the_grade_without_weight(): void
    {
        GradeConfig::factory()->locked()->create([
            'school_id' => $this->schoolA->getKey(),
            'subject_id' => $this->classSubjectA->subject_id,
            'academic_year_id' => $this->yearA->getKey(),
            'created_by' => $this->teacherA->getKey(),
        ]);

        [$attempt] = $this->submittedAttempt();

        $grade = $this->bridge->bridge($attempt, $this->teacherA, GradeType::Daily, AssessmentType::Summative);

        $this->assertNull($grade->weight);
        $this->assertSame(1, Grade::query()->count());
    }

    // ------------------------------------------------- rapor yang sudah terbit

    /**
     * Butir 332 — pagar lokal. `GradePolicy::create()` tidak menanyakan rapor
     * terbit, dan jembatan ini tidak boleh memanfaatkan celah itu.
     */
    public function test_a_published_report_card_blocks_the_bridge(): void
    {
        [$attempt] = $this->submittedAttempt();

        $card = ReportCard::factory()->create([
            'school_id' => $this->schoolA->getKey(),
            'student_id' => $this->studentA->getKey(),
            'class_id' => $this->classA->getKey(),
            'academic_year_id' => $this->yearA->getKey(),
            'is_published' => true,
        ]);

        $snapshot = $card->fresh()->only(['final_scores', 'is_published', 'published_at', 'updated_at']);

        $this->assertRefused(
            fn () => $this->bridge->bridge($attempt, $this->teacherA, GradeType::Daily, AssessmentType::Formative),
            'Rapor siswa ini pada tahun ajaran tersebut sudah diterbitkan, sehingga nilai baru tidak dapat ditambahkan.',
        );

        // Tidak ada yang berubah sedikit pun.
        $this->assertSame(0, Grade::query()->count());
        $this->assertNull($attempt->fresh()->grade_id);
        $this->assertEquals($snapshot, $card->fresh()->only(['final_scores', 'is_published', 'published_at', 'updated_at']));
    }

    public function test_an_unpublished_report_card_does_not_block(): void
    {
        [$attempt] = $this->submittedAttempt();

        ReportCard::factory()->create([
            'school_id' => $this->schoolA->getKey(),
            'student_id' => $this->studentA->getKey(),
            'class_id' => $this->classA->getKey(),
            'academic_year_id' => $this->yearA->getKey(),
            'is_published' => false,
        ]);

        $grade = $this->bridge->bridge($attempt, $this->teacherA, GradeType::Daily, AssessmentType::Formative);

        $this->assertSame(1, Grade::query()->count());
        $this->assertNotNull($grade->getKey());
    }

    /**
     * Pagar jembatan sependapat dengan `Grade::isLocked()` — keduanya membaca
     * rapor terbit untuk pasangan siswa + tahun ajaran yang sama.
     */
    public function test_the_fence_agrees_with_the_existing_grade_lock(): void
    {
        [$attempt] = $this->submittedAttempt();

        $grade = $this->bridge->bridge($attempt, $this->teacherA, GradeType::Daily, AssessmentType::Formative);

        $this->assertFalse($grade->isLocked());

        ReportCard::factory()->create([
            'school_id' => $this->schoolA->getKey(),
            'student_id' => $this->studentA->getKey(),
            'class_id' => $this->classA->getKey(),
            'academic_year_id' => $this->yearA->getKey(),
            'is_published' => true,
        ]);

        $this->assertTrue($grade->fresh()->isLocked());
    }

    public function test_bridging_never_regenerates_a_report_card(): void
    {
        [$attempt] = $this->submittedAttempt();

        $before = ReportCard::query()->count();

        $this->bridge->bridge($attempt, $this->teacherA, GradeType::Daily, AssessmentType::Summative);

        $this->assertSame($before, ReportCard::query()->count());
        $this->assertSame(0, ReportCard::query()->count());
    }

    // ------------------------------------------------------ ganda & bersamaan

    public function test_a_second_bridge_creates_no_second_grade(): void
    {
        [$attempt] = $this->submittedAttempt();

        $first = $this->bridge->bridge($attempt, $this->teacherA, GradeType::Daily, AssessmentType::Formative);

        $this->assertRefused(
            fn () => $this->bridge->bridge($attempt->fresh(), $this->teacherA, GradeType::Final, AssessmentType::Summative),
            'Hasil ini sudah dimasukkan ke nilai.',
        );

        $this->assertSame(1, Grade::query()->count());
        $this->assertSame($first->getKey(), (int) $attempt->fresh()->grade_id);
        // Jenis nilainya pun tidak tergeser oleh percobaan kedua.
        $this->assertSame(GradeType::Daily, $first->fresh()->grade_type);
    }

    /**
     * Butir 329 — klik ganda. Barisnya dikunci di dalam transaksi, lalu
     * syaratnya diperiksa ulang.
     */
    public function test_two_immediate_calls_produce_exactly_one_grade(): void
    {
        [$attempt] = $this->submittedAttempt();

        $succeeded = 0;

        foreach ([$attempt, $attempt] as $candidate) {
            try {
                $this->bridge->bridge($candidate->fresh(), $this->teacherA, GradeType::Daily, AssessmentType::Formative);
                $succeeded++;
            } catch (ValidationException) {
                // yang kedua ditolak sebagaimana mestinya
            }
        }

        $this->assertSame(1, $succeeded);
        $this->assertSame(1, Grade::query()->count());
    }

    /**
     * Butir 339 — `exam_attempts.grade_id` adalah nullOnDelete. Menghapus
     * nilainya melepaskan tautannya, dan hasil itu kembali dapat dimasukkan.
     * Tidak ada skema batu nisan yang ditambahkan untuk mencegahnya.
     */
    public function test_deleting_the_linked_grade_allows_a_deliberate_re_bridge(): void
    {
        [$attempt] = $this->submittedAttempt();

        $grade = $this->bridge->bridge($attempt, $this->teacherA, GradeType::Daily, AssessmentType::Formative);

        $grade->delete();

        $this->assertNull($attempt->fresh()->grade_id);

        $again = $this->bridge->bridge($attempt->fresh(), $this->teacherA, GradeType::Midterm, AssessmentType::Summative);

        $this->assertSame(1, Grade::query()->count());
        $this->assertSame(GradeType::Midterm, $again->grade_type);
    }

    // --------------------------------------------- formatif vs sumatif

    /**
     * Butir 340 — nilai formatif **tidak** menggeser nilai akhir. Yang
     * membuktikannya bukan kode CBT melainkan `ComponentScoreAggregator` yang
     * sudah ada: ia menyaring `assessment_type` sebelum menghitung apa pun.
     */
    public function test_a_formative_bridge_does_not_move_the_final_score(): void
    {
        $this->activeConfigFor(GradeType::Daily, 1.00);

        $existing = $this->existingGrade(GradeType::Daily, 80.0, AssessmentType::Summative);

        $before = $this->finalScoreOf();

        [$attempt] = $this->submittedAttempt([1.0], correctCount: 0); // nilai 0

        $grade = $this->bridge->bridge($attempt, $this->teacherA, GradeType::Daily, AssessmentType::Formative);

        $after = $this->finalScoreOf();

        $this->assertSame('0.00', $grade->score);
        $this->assertSame(80.0, $before);
        $this->assertSame(80.0, $after, 'Nilai formatif ikut menghitung nilai akhir.');
        $this->assertNotNull($existing->fresh());
    }

    /**
     * Sumatif mengikuti semantik yang sudah ada: ia ikut dirata-rata bersama
     * nilai sumatif lain pada komponen yang sama, lalu dikalikan bobot dari
     * Grade Config. Tidak ada jalur perhitungan khusus CBT.
     */
    public function test_a_summative_bridge_follows_the_existing_aggregation(): void
    {
        $this->activeConfigFor(GradeType::Daily, 1.00);

        $this->existingGrade(GradeType::Daily, 80.0, AssessmentType::Summative);

        [$attempt] = $this->submittedAttempt([1.0], correctCount: 0); // nilai 0

        $this->bridge->bridge($attempt, $this->teacherA, GradeType::Daily, AssessmentType::Summative);

        // Rata-rata 80 dan 0 adalah 40 — aturan rata-rata komponen yang sudah
        // ada sejak Sprint 4, bukan aturan baru.
        $this->assertSame(40.0, $this->finalScoreOf());
    }

    public function test_existing_grades_are_never_touched(): void
    {
        $this->activeConfigFor(GradeType::Daily, 1.00);

        $existing = $this->existingGrade(GradeType::Daily, 80.0, AssessmentType::Summative);
        $snapshot = $existing->fresh()->only(['score', 'weight', 'grade_type', 'assessment_type', 'description', 'updated_at']);

        [$attempt] = $this->submittedAttempt();

        $this->bridge->bridge($attempt, $this->teacherA, GradeType::Daily, AssessmentType::Summative);

        $this->assertEquals(
            $snapshot,
            $existing->fresh()->only(['score', 'weight', 'grade_type', 'assessment_type', 'description', 'updated_at']),
        );
        $this->assertSame(2, Grade::query()->count());
    }

    // ------------------------------------------------- terlihat di mana-mana

    /**
     * Nilai hasil jembatan muncul lewat permukaan yang **sudah ada**, bukan
     * lewat layar CBT tersendiri. Itulah gunanya menempuh jalur `grades`:
     * portal siswa, portal orang tua, dan panel tidak perlu tahu apa pun
     * tentang CBT (butir 342).
     */
    public function test_a_bridged_grade_appears_on_the_existing_student_grade_page(): void
    {
        [$attempt, $exam] = $this->submittedAttempt();

        $this->bridge->bridge($attempt, $this->teacherA, GradeType::Daily, AssessmentType::Formative);

        $this->actingAs($this->studentUserA);

        $this->get(route('student.grades'))
            ->assertOk()
            // Keterangannya membuat asal-usulnya terbaca siswa (butir 333).
            ->assertSee('CBT: '.$exam->title)
            // Angkanya dirender pemformat Indonesia yang sudah dipakai halaman
            // itu — bukan format khusus CBT.
            ->assertSee('100,00');
    }

    public function test_a_bridged_grade_is_an_ordinary_row_in_the_grade_table(): void
    {
        [$attempt] = $this->submittedAttempt();

        $grade = $this->bridge->bridge($attempt, $this->teacherA, GradeType::Daily, AssessmentType::Formative);

        // Tidak ada kolom, penanda, maupun tabel khusus CBT pada nilainya.
        $this->assertSame(
            [],
            array_diff(array_keys($grade->fresh()->getAttributes()), Schema::getColumnListing('grades')),
        );
    }

    // --------------------------------------------------------------- audit

    public function test_bridging_is_recorded_with_the_real_actor(): void
    {
        [$attempt] = $this->submittedAttempt();

        $this->actingAs($this->teacherA);

        $grade = $this->bridge->bridge($attempt, $this->teacherA, GradeType::Daily, AssessmentType::Formative);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Grade::class,
            'auditable_id' => $grade->getKey(),
            'action' => AuditAction::Created->value,
            'user_id' => $this->teacherA->getKey(),
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => ExamAttempt::class,
            'auditable_id' => $attempt->getKey(),
            'action' => AuditAction::Updated->value,
        ]);
    }

    // ----------------------------------------------- kemandirian arsitektur

    /**
     * Butir 326 — penjagaan C3 tetap bermakna: ketiga layanan CBT lainnya masih
     * tidak mengetahui apa pun tentang nilai akademik. Yang mengetahuinya hanya
     * jembatan ini.
     */
    public function test_only_the_bridge_knows_about_academic_grades(): void
    {
        foreach ([
            'ExamAttemptService.php',
            'ExamScoringService.php',
            'StudentExamService.php',
            'ExamPublisher.php',
            'ExamIntegrity.php',
        ] as $file) {
            $code = $this->sourceWithoutComments(app_path('Services/Cbt/'.$file));

            $this->assertStringNotContainsString('Models\\Grade', $code, $file);
            $this->assertStringNotContainsString('GradeConfig', $code, $file);
            $this->assertStringNotContainsString('ReportCard', $code, $file);
        }

        $bridgeCode = $this->sourceWithoutComments(app_path('Services/Cbt/ExamGradeBridge.php'));

        $this->assertStringContainsString('Models\\Grade', $bridgeCode);
        $this->assertStringContainsString('ReportCard', $bridgeCode);
    }

    // ------------------------------------------------------------- penunjang

    protected function sourceWithoutComments(string $path): string
    {
        return collect(token_get_all(file_get_contents($path)))
            ->reject(fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
            ->map(fn ($token) => is_array($token) ? $token[1] : $token)
            ->implode('');
    }

    /**
     * Pengerjaan yang sudah dikumpulkan dan bernilai.
     *
     * @param  array<int, float>  $points
     * @return array{0: ExamAttempt, 1: Exam}
     */
    protected function submittedAttempt(
        array $points = [1.0],
        ?int $correctCount = null,
        $classSubject = null,
        $student = null,
        ?User $user = null,
    ): array {
        $classSubject ??= $this->classSubjectA;
        $student ??= $this->studentA;
        $user ??= $this->studentUserA;

        [$exam, $questions] = $this->openExamWithQuestions($points, $classSubject);

        $attempts = app(ExamAttemptService::class);
        $attempts->startOrResume($user, $exam->getKey());

        $correctCount ??= count($questions);

        foreach ($questions as $index => $question) {
            $option = $index < $correctCount
                ? $this->correctOptionOf($question)
                : $this->wrongOptionOf($question);

            $attempts->saveAnswer($user, $exam->getKey(), $question->getKey(), $option->getKey());
        }

        return [$attempts->submit($user, $exam->getKey()), $exam->fresh()];
    }

    protected function activeConfigFor(GradeType $type, float $weight): GradeConfig
    {
        return GradeConfig::factory()
            ->active()
            ->components([['type' => $type->value, 'weight' => $weight]])
            ->create([
                'school_id' => $this->schoolA->getKey(),
                'subject_id' => $this->classSubjectA->subject_id,
                'academic_year_id' => $this->yearA->getKey(),
                'created_by' => $this->teacherA->getKey(),
            ]);
    }

    protected function existingGrade(GradeType $type, float $score, AssessmentType $assessment): Grade
    {
        return Grade::query()->create([
            'school_id' => $this->schoolA->getKey(),
            'student_id' => $this->studentA->getKey(),
            'class_subject_id' => $this->classSubjectA->getKey(),
            'academic_year_id' => $this->yearA->getKey(),
            'grade_type' => $type->value,
            'assessment_type' => $assessment->value,
            'score' => $score,
            'graded_by' => $this->teacherA->getKey(),
            'graded_at' => now(),
        ]);
    }

    /**
     * Nilai akhir mata pelajaran ini menurut kalkulator yang sudah ada.
     */
    protected function finalScoreOf(): ?float
    {
        $grades = Grade::query()
            ->where('student_id', $this->studentA->getKey())
            ->where('class_subject_id', $this->classSubjectA->getKey())
            ->orderBy('graded_at')
            ->orderBy('id')
            ->get();

        return app(FinalScoreCalculator::class)->calculate($grades)->score;
    }

    protected function assertRefused(\Closure $work, string $expected): void
    {
        try {
            $work();
            $this->fail("Seharusnya ditolak: {$expected}");
        } catch (ValidationException $exception) {
            $this->assertSame($expected, (string) collect($exception->errors())->flatten()->first());
        }
    }

    protected function aggregator(): ComponentScoreAggregator
    {
        return app(ComponentScoreAggregator::class);
    }

    protected function questionsOf(Exam $exam): array
    {
        return ExamQuestion::query()->where('exam_id', $exam->getKey())->get()->all();
    }
}
