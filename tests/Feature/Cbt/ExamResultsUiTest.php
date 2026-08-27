<?php

namespace Tests\Feature\Cbt;

use App\Enums\AssessmentType;
use App\Enums\ExamAttemptStatus;
use App\Enums\GradeType;
use App\Enums\RoleName;
use App\Filament\Resources\ExamResource\Pages\ViewExam;
use App\Filament\Resources\ExamResource\RelationManagers\AttemptsRelationManager;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\Grade;
use App\Models\ReportCard;
use App\Models\Student;
use App\Services\Cbt\ExamAttemptService;
use App\Services\Cbt\ExamGradeBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Cbt\Concerns\BuildsStudentExamFixture;
use Tests\TestCase;

/**
 * Daftar hasil ujian untuk guru dan admin.
 *
 * Tabel ini menampilkan nama siswa beserta nilainya, dan menyediakan satu-satunya
 * pintu menuju nilai akademik. Dua kewenangan berbeda bertemu di sini, dan
 * keduanya diuji terpisah: yang boleh **membaca** tidak otomatis boleh
 * **menulis** (butir 324, 325).
 */
class ExamResultsUiTest extends TestCase
{
    use BuildsStudentExamFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStudentExamFixture();
    }

    // ------------------------------------------------------------ kewenangan

    public function test_the_teacher_of_the_class_subject_sees_the_results(): void
    {
        [$exam, $attempt] = $this->examWithSubmittedAttempt();

        $this->actingAs($this->teacherA);

        $this->results($exam)
            ->assertOk()
            ->assertCanSeeTableRecords([$attempt]);
    }

    public function test_a_teacher_of_another_class_subject_cannot_open_the_results(): void
    {
        [$exam] = $this->examWithSubmittedAttempt();

        $stranger = $this->userIn($this->schoolA, RoleName::Guru);

        $this->actingAs($stranger);

        $this->assertFalse(AttemptsRelationManager::canViewForRecord($exam, ViewExam::class));
    }

    /**
     * Butir 278 — menjadi wali kelas tidak memberi kewenangan apa pun atas
     * ujiannya. Yang memberi adalah penugasan mengajar.
     */
    public function test_a_homeroom_teacher_who_teaches_nothing_cannot_open_the_results(): void
    {
        [$exam] = $this->examWithSubmittedAttempt();

        $wali = $this->userIn($this->schoolA, RoleName::WaliKelas);
        $this->classA->forceFill(['homeroom_teacher_id' => $wali->getKey()])->save();

        $this->assertFalse(AttemptsRelationManager::canViewForRecord($exam, ViewExam::class));

        $this->actingAs($wali);

        $this->assertFalse(AttemptsRelationManager::canViewForRecord($exam, ViewExam::class));
        $this->assertFalse($wali->can('bridgeToGrade', $exam));
    }

    public function test_a_homeroom_teacher_who_also_teaches_sees_the_results(): void
    {
        $wali = $this->userIn($this->schoolA, RoleName::WaliKelas);
        $taught = $this->classSubjectIn($this->schoolA, $this->classA, $wali);

        [$exam] = $this->examWithSubmittedAttempt($taught);

        $this->actingAs($wali);

        $this->assertTrue(AttemptsRelationManager::canViewForRecord($exam, ViewExam::class));
        $this->assertTrue($wali->can('bridgeToGrade', $exam));
    }

    public function test_a_school_admin_of_the_same_branch_sees_the_results(): void
    {
        [$exam, $attempt] = $this->examWithSubmittedAttempt();

        $this->actingAs($this->userIn($this->schoolA, RoleName::SchoolAdmin));

        $this->results($exam)->assertOk()->assertCanSeeTableRecords([$attempt]);
    }

    public function test_a_school_admin_of_another_branch_is_refused(): void
    {
        [$exam] = $this->examWithSubmittedAttempt();

        $this->actingAs($this->userIn($this->schoolB, RoleName::SchoolAdmin));

        $this->assertFalse(AttemptsRelationManager::canViewForRecord($exam, ViewExam::class));
    }

    /**
     * Kepala Sekolah mengawasi: ia melihat hasilnya, tetapi tidak menemukan
     * satu pun tombol yang memindahkannya ke nilai (butir 325).
     */
    public function test_a_head_teacher_reads_the_results_without_the_bridge_action(): void
    {
        [$exam, $attempt] = $this->examWithSubmittedAttempt();

        $this->actingAs($this->userIn($this->schoolA, RoleName::KepalaSekolah));

        $this->results($exam)
            ->assertOk()
            ->assertCanSeeTableRecords([$attempt])
            ->assertTableActionHidden('bridgeToGrade', $attempt)
            ->assertDontSee('Masukkan ke Nilai');
    }

    public function test_a_treasurer_cannot_open_the_results(): void
    {
        [$exam] = $this->examWithSubmittedAttempt();

        $this->actingAs($this->userIn($this->schoolA, RoleName::Bendahara));

        $this->assertFalse(AttemptsRelationManager::canViewForRecord($exam, ViewExam::class));
    }

    public function test_students_and_parents_have_no_filament_result_page(): void
    {
        [$exam] = $this->examWithSubmittedAttempt();

        foreach ([RoleName::Siswa, RoleName::OrangTua] as $role) {
            $user = $this->userIn($this->schoolA, $role);

            $this->actingAs($user);

            $this->assertFalse(AttemptsRelationManager::canViewForRecord($exam, ViewExam::class));
            $this->assertFalse($user->can('viewResults', $exam));
        }
    }

    // --------------------------------------------------------------- isinya

    public function test_the_table_shows_the_student_the_score_and_the_grade_status(): void
    {
        [$exam, $attempt] = $this->examWithSubmittedAttempt();

        $this->studentA->forceFill(['full_name' => 'Ahmad Fauzi'])->save();

        $this->actingAs($this->teacherA);

        $this->results($exam)
            ->assertSee('Ahmad Fauzi')
            ->assertSee('100.00')
            ->assertSee('Belum masuk nilai');

        app(ExamGradeBridge::class)->bridge(
            $attempt->fresh(),
            $this->teacherA,
            GradeType::Daily,
            AssessmentType::Formative,
        );

        $this->results($exam->fresh())->assertSee('Sudah masuk nilai');
    }

    /**
     * Kunci jawaban tidak punya urusan di tabel hasil — yang ditampilkan hanya
     * angka akhirnya (butir 292, 310).
     */
    public function test_the_result_table_leaks_no_answer_key(): void
    {
        [$exam] = $this->examWithSubmittedAttempt();

        $this->actingAs($this->teacherA);

        $html = $this->results($exam)->html();

        foreach (['is_correct', 'correctOption', 'exam_option_id'] as $needle) {
            $this->assertStringNotContainsString($needle, $html);
        }
    }

    public function test_an_active_attempt_stays_in_progress(): void
    {
        $exam = $this->openExamIn($this->classSubjectA);

        $attempt = app(ExamAttemptService::class)->startOrResume($this->studentUserA, $exam->getKey());

        $this->actingAs($this->teacherA);

        $this->results($exam)->assertOk();

        $this->assertSame(ExamAttemptStatus::InProgress, $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->score);
    }

    // --------------------------------------------- penyelesaian kedaluwarsa

    /**
     * Butir 334 — titik penyelesaian kedua. Tanpa ini, pengerjaan siswa yang
     * tidak pernah kembali akan tampak "sedang dikerjakan" selamanya di layar
     * guru.
     */
    public function test_opening_the_results_settles_an_expired_attempt(): void
    {
        [$exam, $questions] = $this->openExamWithQuestions([1.0]);

        $attempts = app(ExamAttemptService::class);
        $attempt = $attempts->startOrResume($this->studentUserA, $exam->getKey());
        $attempts->saveAnswer(
            $this->studentUserA,
            $exam->getKey(),
            $questions[0]->getKey(),
            $this->correctOptionOf($questions[0])->getKey(),
        );

        $expiresAt = now()->subMinutes(10);
        $attempt->forceFill(['expires_at' => $expiresAt])->save();

        $this->actingAs($this->teacherA);

        $this->results($exam)->assertOk()->assertSee('100.00');

        $settled = $attempt->fresh();

        $this->assertSame(ExamAttemptStatus::Submitted, $settled->status);
        $this->assertSame('100.00', $settled->score);
        // Semantik C3 dipertahankan: dicap pada saat berakhirnya (butir 316).
        $this->assertSame($expiresAt->toDateTimeString(), $settled->submitted_at->toDateTimeString());
    }

    public function test_settlement_touches_only_the_exam_being_viewed(): void
    {
        [$first] = $this->openExamWithQuestions([1.0]);
        [$second] = $this->openExamWithQuestions([1.0]);

        $attempts = app(ExamAttemptService::class);

        $peer = $this->studentIn($this->schoolA);
        $peerUser = $this->linkStudent($peer, $this->classA);

        $one = $attempts->startOrResume($this->studentUserA, $first->getKey());
        $two = $attempts->startOrResume($peerUser, $second->getKey());

        foreach ([$one, $two] as $attempt) {
            $attempt->forceFill(['expires_at' => now()->subMinutes(5)])->save();
        }

        $this->actingAs($this->teacherA);

        $this->results($first)->assertOk();

        $this->assertSame(ExamAttemptStatus::Submitted, $one->fresh()->status);
        // Ujian lain tidak ikut disapu.
        $this->assertSame(ExamAttemptStatus::InProgress, $two->fresh()->status);
    }

    public function test_settlement_never_reaches_another_school(): void
    {
        [$examA] = $this->openExamWithQuestions([1.0]);
        [$examB] = $this->openExamWithQuestions([1.0], $this->classSubjectB);

        $attempts = app(ExamAttemptService::class);

        $mine = $attempts->startOrResume($this->studentUserA, $examA->getKey());
        $theirs = $attempts->startOrResume($this->studentUserB, $examB->getKey());

        foreach ([$mine, $theirs] as $attempt) {
            $attempt->forceFill(['expires_at' => now()->subMinutes(5)])->save();
        }

        app(ExamAttemptService::class)->settleExpiredForExam($examA);

        $this->assertSame(ExamAttemptStatus::Submitted, $mine->fresh()->status);
        $this->assertSame(ExamAttemptStatus::InProgress, $theirs->fresh()->status);
    }

    // ------------------------------------------------------------ jembatan UI

    public function test_the_bridge_action_creates_the_grade_from_the_table(): void
    {
        [$exam, $attempt] = $this->examWithSubmittedAttempt();

        $this->actingAs($this->teacherA);

        $this->results($exam)
            ->callTableAction('bridgeToGrade', $attempt, data: [
                'grade_type' => GradeType::Daily->value,
                'assessment_type' => AssessmentType::Summative->value,
            ])
            ->assertHasNoTableActionErrors();

        $grade = Grade::query()->firstOrFail();

        $this->assertSame(GradeType::Daily, $grade->grade_type);
        $this->assertSame(AssessmentType::Summative, $grade->assessment_type);
        $this->assertSame($grade->getKey(), (int) $attempt->fresh()->grade_id);
    }

    /**
     * Butir 336 — bawaan formulirnya FORMATIF. Guru yang menekan simpan tanpa
     * membaca tidak boleh diam-diam menggeser nilai rapor siswanya.
     */
    public function test_the_bridge_form_defaults_to_formative(): void
    {
        [$exam, $attempt] = $this->examWithSubmittedAttempt();

        $this->actingAs($this->teacherA);

        $this->results($exam)
            ->mountTableAction('bridgeToGrade', $attempt)
            ->assertTableActionDataSet(['assessment_type' => AssessmentType::Formative->value]);
    }

    public function test_the_action_disappears_once_the_result_is_bridged(): void
    {
        [$exam, $attempt] = $this->examWithSubmittedAttempt();

        $this->actingAs($this->teacherA);

        $this->results($exam)->assertTableActionVisible('bridgeToGrade', $attempt);

        app(ExamGradeBridge::class)->bridge($attempt->fresh(), $this->teacherA, GradeType::Daily, AssessmentType::Formative);

        $this->results($exam->fresh())->assertTableActionHidden('bridgeToGrade', $attempt->fresh());
    }

    public function test_the_action_is_hidden_while_the_attempt_is_unfinished(): void
    {
        $exam = $this->openExamIn($this->classSubjectA);
        $attempt = app(ExamAttemptService::class)->startOrResume($this->studentUserA, $exam->getKey());

        // Batas waktunya masih jauh, jadi membuka hasil tidak menutupnya.
        $this->actingAs($this->teacherA);

        $this->results($exam)->assertTableActionHidden('bridgeToGrade', $attempt);
    }

    public function test_the_action_is_hidden_when_the_report_card_is_published(): void
    {
        [$exam, $attempt] = $this->examWithSubmittedAttempt();

        ReportCard::factory()->create([
            'school_id' => $this->schoolA->getKey(),
            'student_id' => $this->studentA->getKey(),
            'class_id' => $this->classA->getKey(),
            'academic_year_id' => $this->yearA->getKey(),
            'is_published' => true,
        ]);

        $this->actingAs($this->teacherA);

        $this->results($exam)->assertTableActionHidden('bridgeToGrade', $attempt);
        $this->assertSame(0, Grade::query()->count());
    }

    // ------------------------------------------------------- jembatan massal

    public function test_the_bulk_action_bridges_every_selected_result(): void
    {
        [$exam, $attempts] = $this->examWithSeveralAttempts(3);

        $this->actingAs($this->teacherA);

        $this->results($exam)
            ->callTableBulkAction('bridgeSelectedToGrade', $attempts, data: [
                'grade_type' => GradeType::Assignment->value,
                'assessment_type' => AssessmentType::Formative->value,
            ]);

        $this->assertSame(3, Grade::query()->count());

        foreach ($attempts as $attempt) {
            $this->assertNotNull($attempt->fresh()->grade_id);
        }
    }

    /**
     * Butir 337 — kegagalan sebagian dilaporkan, bukan disembunyikan.
     */
    public function test_the_bulk_action_skips_what_it_cannot_bridge(): void
    {
        [$exam, $attempts] = $this->examWithSeveralAttempts(2);

        // Yang pertama sudah masuk nilai; yang kedua rapornya sudah terbit.
        app(ExamGradeBridge::class)->bridge($attempts[0]->fresh(), $this->teacherA, GradeType::Daily, AssessmentType::Formative);

        ReportCard::factory()->create([
            'school_id' => $this->schoolA->getKey(),
            'student_id' => $attempts[1]->student_id,
            'class_id' => $this->classA->getKey(),
            'academic_year_id' => $this->yearA->getKey(),
            'is_published' => true,
        ]);

        $this->actingAs($this->teacherA);

        $this->results($exam)
            ->callTableBulkAction('bridgeSelectedToGrade', $attempts, data: [
                'grade_type' => GradeType::Assignment->value,
                'assessment_type' => AssessmentType::Formative->value,
            ]);

        // Hanya satu nilai yang pernah ada — yang dibuat sebelum aksi massal.
        $this->assertSame(1, Grade::query()->count());
        $this->assertNull($attempts[1]->fresh()->grade_id);
    }

    public function test_a_head_teacher_gets_no_bulk_action(): void
    {
        [$exam, $attempt] = $this->examWithSubmittedAttempt();

        $this->actingAs($this->userIn($this->schoolA, RoleName::KepalaSekolah));

        $this->results($exam)->assertTableBulkActionHidden('bridgeSelectedToGrade');

        $this->assertNotNull($attempt);
    }

    // ------------------------------------------------------------- performa

    /**
     * Butir 335 — siswa dan nilai yang tertaut dimuat sekali, bukan sekali per
     * baris.
     */
    public function test_the_result_table_does_not_grow_a_query_per_attempt(): void
    {
        [$exam] = $this->examWithSeveralAttempts(1);

        $this->actingAs($this->teacherA);

        // Pemanasan: render pertama memuat izin sekali untuk seluruh proses.
        $this->results($exam);

        $withOne = $this->countQueries(fn () => $this->results($exam));

        $this->addAttempts($exam, 5);

        $withSix = $this->countQueries(fn () => $this->results($exam));

        $this->assertSame(
            $withOne,
            $withSix,
            "Tabel hasil membayar query tambahan per baris: {$withOne} untuk satu, {$withSix} untuk enam.",
        );
    }

    // ------------------------------------------------------------- penunjang

    protected function results(Exam $exam): Testable
    {
        return Livewire::test(AttemptsRelationManager::class, [
            'ownerRecord' => $exam->fresh(),
            'pageClass' => ViewExam::class,
        ]);
    }

    /**
     * @return array{0: Exam, 1: ExamAttempt}
     */
    protected function examWithSubmittedAttempt($classSubject = null): array
    {
        // Gurunya diturunkan dari kelas-mapelnya oleh openExamWithQuestions();
        // tidak perlu diberikan terpisah.
        [$exam, $questions] = $this->openExamWithQuestions([1.0], $classSubject);

        $attempts = app(ExamAttemptService::class);
        $attempts->startOrResume($this->studentUserA, $exam->getKey());
        $attempts->saveAnswer(
            $this->studentUserA,
            $exam->getKey(),
            $questions[0]->getKey(),
            $this->correctOptionOf($questions[0])->getKey(),
        );

        $attempt = $attempts->submit($this->studentUserA, $exam->getKey());

        return [$exam->fresh(), $attempt];
    }

    /**
     * @return array{0: Exam, 1: array<int, ExamAttempt>}
     */
    protected function examWithSeveralAttempts(int $count): array
    {
        [$exam, $questions] = $this->openExamWithQuestions([1.0]);

        $attempts = [];
        $service = app(ExamAttemptService::class);

        $service->startOrResume($this->studentUserA, $exam->getKey());
        $service->saveAnswer(
            $this->studentUserA,
            $exam->getKey(),
            $questions[0]->getKey(),
            $this->correctOptionOf($questions[0])->getKey(),
        );
        $attempts[] = $service->submit($this->studentUserA, $exam->getKey());

        for ($index = 1; $index < $count; $index++) {
            $attempts[] = $this->extraAttempt($exam, $questions[0]);
        }

        return [$exam->fresh(), $attempts];
    }

    protected function addAttempts(Exam $exam, int $count): void
    {
        $question = ExamQuestion::query()->where('exam_id', $exam->getKey())->firstOrFail();

        for ($index = 0; $index < $count; $index++) {
            $this->extraAttempt($exam, $question);
        }
    }

    protected function extraAttempt(Exam $exam, $question): ExamAttempt
    {
        $peer = $this->studentIn($this->schoolA);
        $peerUser = $this->linkStudent($peer, $this->classA);

        $service = app(ExamAttemptService::class);
        $service->startOrResume($peerUser, $exam->getKey());
        $service->saveAnswer(
            $peerUser,
            $exam->getKey(),
            $question->getKey(),
            $this->correctOptionOf($question)->getKey(),
        );

        return $service->submit($peerUser, $exam->getKey());
    }

    protected function countQueries(\Closure $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $work();

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $count;
    }

    protected function studentNames(): array
    {
        return Student::query()->pluck('full_name')->all();
    }
}
