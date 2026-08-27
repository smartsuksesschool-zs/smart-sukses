<?php

namespace Tests\Feature\Cbt;

use App\Enums\AuditAction;
use App\Enums\ExamQuestionType;
use App\Enums\ExamStatus;
use App\Enums\RoleName;
use App\Filament\Resources\ExamResource\Pages\EditExam;
use App\Filament\Resources\ExamResource\Pages\ListExams;
use App\Filament\Resources\ExamResource\RelationManagers\QuestionsRelationManager;
use App\Models\ClassSubject;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamOption;
use App\Models\ExamQuestion;
use App\Services\Cbt\ExamPublisher;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Cbt\Concerns\BuildsExamFixture;
use Tests\TestCase;

/**
 * Pagar yang tidak terlihat dari layar: kewenangan siklus hidup, kunci jawaban,
 * biaya query, dan jejak audit.
 *
 * Dipisahkan dari ExamAuthoringUiTest yang menguji apa yang dirender. Yang di
 * sini menguji apa yang tetap berlaku ketika seseorang tidak lewat layar sama
 * sekali.
 */
class ExamAuthoringGuardsTest extends TestCase
{
    use BuildsExamFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildExamFixture();
    }

    // ---------------------------------------------- kewenangan siklus hidup

    /**
     * @return array<string, array{0: RoleName, 1: bool}>
     */
    public static function lifecycleRoles(): array
    {
        // [peran, boleh mengelola siklus hidup ujian yang ia ampu]
        return [
            'Admin Sekolah' => [RoleName::SchoolAdmin, true],
            'Guru' => [RoleName::Guru, true],
            'Wali Kelas' => [RoleName::WaliKelas, true],
            'Kepala Sekolah' => [RoleName::KepalaSekolah, false],
            'Bendahara' => [RoleName::Bendahara, false],
            'Siswa' => [RoleName::Siswa, false],
            'Orang Tua' => [RoleName::OrangTua, false],
        ];
    }

    #[DataProvider('lifecycleRoles')]
    public function test_the_lifecycle_abilities_follow_the_role_matrix(RoleName $role, bool $mayManage): void
    {
        $user = $this->userIn($this->schoolA, $role);

        $classSubject = $mayManage && ! $user->isSchoolAdmin()
            ? $this->classSubjectIn($this->schoolA, $this->classA, $user)
            : $this->classSubjectA;

        $draft = $this->readyExam($classSubject);

        $this->assertSame($mayManage, $user->can('publish', $draft), 'publish');
        $this->assertSame($mayManage, $user->can('delete', $draft), 'delete');
        $this->assertSame($mayManage, $user->can('viewAnswerKey', $draft), 'viewAnswerKey');

        $published = app(ExamPublisher::class)->publish($draft, $this->userIn($this->schoolA, RoleName::SchoolAdmin));

        $this->assertSame($mayManage, $user->can('unpublish', $published), 'unpublish');
        $this->assertSame($mayManage, $user->can('close', $published), 'close');
    }

    /**
     * Butir 292 — Kepala Sekolah mengawasi tanpa mengetahui kuncinya. Melihat
     * ujiannya boleh; melihat kunci jawabannya tidak.
     */
    public function test_a_head_teacher_may_view_the_exam_but_not_the_answer_key(): void
    {
        $exam = $this->readyExam($this->classSubjectA);
        $kepala = $this->userIn($this->schoolA, RoleName::KepalaSekolah);

        $this->assertTrue($kepala->can('view', $exam));
        $this->assertFalse($kepala->can('viewAnswerKey', $exam));
    }

    public function test_the_author_may_see_the_answer_key(): void
    {
        $exam = $this->readyExam($this->classSubjectA);

        $this->assertTrue($this->teacherA->can('viewAnswerKey', $exam));
    }

    public function test_nobody_from_another_branch_may_see_the_answer_key(): void
    {
        $exam = $this->readyExam($this->classSubjectA);

        foreach ([RoleName::SchoolAdmin, RoleName::KepalaSekolah, RoleName::Guru] as $role) {
            $this->assertFalse(
                $this->userIn($this->schoolB, $role)->can('viewAnswerKey', $exam),
                $role->value.' cabang seberang melihat kunci jawaban.',
            );
        }
    }

    // ------------------------------------------------------ keadaan mengunci

    public function test_content_becomes_uneditable_the_moment_the_exam_is_published(): void
    {
        $exam = $this->readyExam($this->classSubjectA);

        $this->assertTrue($this->teacherA->can('update', $exam));
        $this->assertTrue($exam->isContentEditable());

        $published = app(ExamPublisher::class)->publish($exam, $this->teacherA);

        $this->assertFalse($this->teacherA->can('update', $published));
        $this->assertFalse($published->isContentEditable());
    }

    public function test_content_stays_uneditable_after_an_attempt_even_if_the_status_were_reset(): void
    {
        $exam = app(ExamPublisher::class)->publish($this->readyExam($this->classSubjectA), $this->teacherA);
        $this->attemptOn($exam);

        // Menyetel status kembali ke draf langsung di database — jalan yang
        // tidak disediakan aplikasi mana pun — tetap tidak membuka isinya,
        // karena syarat keduanya adalah ketiadaan pengerjaan.
        $exam->forceFill(['status' => ExamStatus::Draft->value])->save();

        $this->assertFalse($exam->fresh()->isContentEditable());
        $this->assertFalse($this->teacherA->can('update', $exam->fresh()));
        $this->assertFalse($this->teacherA->can('delete', $exam->fresh()));
    }

    // ------------------------------------------------------------- performa

    /**
     * NFR 1.4 — daftar ujian tidak boleh menambah query per baris.
     *
     * Setiap baris menampilkan mata pelajaran, kelas, tahun ajaran, jumlah soal,
     * dan jumlah pengerjaan — dan aksinya menimbang `hasAttempts()`. Tanpa
     * eager loading, satu halaman berisi dua puluh ujian membayarnya lebih dari
     * seratus kali (butir 289).
     */
    public function test_the_exam_list_does_not_grow_a_query_per_row(): void
    {
        $this->actingAs($this->userIn($this->schoolA, RoleName::SchoolAdmin));

        $this->seedExams(1);

        // Pemanasan: render pertama memuat izin Spatie dan sejenisnya sekali
        // untuk seluruh proses. Tanpa ini yang terukur adalah cache dingin,
        // bukan biaya per baris (butir 304).
        Livewire::test(ListExams::class)->assertOk();

        $withOne = $this->countQueries(fn () => Livewire::test(ListExams::class)->assertOk());

        $this->seedExams(5);
        $withSix = $this->countQueries(fn () => Livewire::test(ListExams::class)->assertOk());

        $this->assertSame(
            $withOne,
            $withSix,
            "Daftar ujian membayar query tambahan per baris: {$withOne} untuk satu, {$withSix} untuk enam.",
        );
    }

    public function test_the_question_list_counts_options_without_a_query_per_question(): void
    {
        $exam = $this->readyExam($this->classSubjectA);

        $this->actingAs($this->teacherA);

        $this->renderQuestions($exam);

        $withOne = $this->countQueries(fn () => $this->renderQuestions($exam));

        for ($index = 2; $index <= 6; $index++) {
            $question = ExamQuestion::factory()->create([
                'exam_id' => $exam->getKey(),
                'school_id' => $exam->school_id,
                'position' => $index,
            ]);

            ExamOption::factory()->correct()->create([
                'exam_question_id' => $question->getKey(),
                'school_id' => $exam->school_id,
            ]);
        }

        $withSix = $this->countQueries(fn () => $this->renderQuestions($exam));

        $this->assertSame($withOne, $withSix, 'Daftar soal membayar query tambahan per soal.');
    }

    // ---------------------------------------------------------------- audit

    /**
     * Jejak audit CUD yang sudah ada (AppServiceProvider::recordAuditTrail)
     * merekam CBT tanpa satu baris pun kode tambahan — syaratnya penyimpanan
     * berjalan lewat model, bukan query massal (butir 299).
     */
    public function test_authoring_leaves_an_audit_trail(): void
    {
        $this->actingAs($this->teacherA);

        $exam = $this->examIn($this->classSubjectA);

        $question = ExamQuestion::query()->create([
            'exam_id' => $exam->getKey(),
            'school_id' => $exam->school_id,
            'question_type' => ExamQuestionType::MultipleChoice->value,
            'question_text' => 'Soal audit',
            'points' => 1,
            'position' => 1,
        ]);

        $option = ExamOption::query()->create([
            'exam_question_id' => $question->getKey(),
            'school_id' => $exam->school_id,
            'option_text' => 'A',
            'is_correct' => true,
            'position' => 1,
        ]);

        foreach ([Exam::class => $exam, ExamQuestion::class => $question, ExamOption::class => $option] as $type => $model) {
            $this->assertDatabaseHas('audit_logs', [
                'auditable_type' => $type,
                'auditable_id' => $model->getKey(),
                'action' => AuditAction::Created->value,
                'school_id' => $this->schoolA->getKey(),
                'user_id' => $this->teacherA->getKey(),
            ]);
        }
    }

    public function test_publishing_is_recorded_as_an_update(): void
    {
        $exam = $this->readyExam($this->classSubjectA);

        $this->actingAs($this->teacherA);

        app(ExamPublisher::class)->publish($exam, $this->teacherA);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Exam::class,
            'auditable_id' => $exam->getKey(),
            'action' => AuditAction::Updated->value,
            'user_id' => $this->teacherA->getKey(),
        ]);
    }

    public function test_deleting_an_option_is_recorded(): void
    {
        $exam = $this->readyExam($this->classSubjectA);
        $option = ExamOption::query()->where('school_id', $this->schoolA->getKey())->firstOrFail();

        $this->actingAs($this->teacherA);

        $id = $option->getKey();
        $option->delete();

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => ExamOption::class,
            'auditable_id' => $id,
            'action' => AuditAction::Deleted->value,
        ]);
    }

    /**
     * Jejak audit tidak pernah mencatat aktor palsu. Perubahan tanpa pengguna
     * yang login — seeder, perintah artisan — memang berbuat `user_id` NULL,
     * dan itu keadaan yang sah (butir 250).
     */
    public function test_no_fake_actor_is_invented_without_a_session(): void
    {
        $exam = $this->examIn($this->classSubjectA);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Exam::class,
            'auditable_id' => $exam->getKey(),
            'user_id' => null,
        ]);
    }

    // ------------------------------------------------------------- penunjang

    protected function countQueries(Closure $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $work();

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $count;
    }

    protected function renderQuestions(Exam $exam): void
    {
        Livewire::test(
            QuestionsRelationManager::class,
            [
                'ownerRecord' => $exam->fresh(),
                'pageClass' => EditExam::class,
            ],
        )->assertOk();
    }

    protected function seedExams(int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            $exam = $this->readyExam($this->classSubjectA);

            // Sebagian sudah dikerjakan, supaya `hasAttempts()` benar-benar
            // ditimbang saat aksi per baris dirender.
            if ($index % 2 === 0) {
                app(ExamPublisher::class)->publish($exam, $this->teacherA);
                $this->attemptOn($exam->fresh());
            }
        }
    }

    protected function readyExam(ClassSubject $classSubject): Exam
    {
        $exam = $this->examIn($classSubject);

        $question = ExamQuestion::factory()->create([
            'exam_id' => $exam->getKey(),
            'school_id' => $exam->school_id,
        ]);

        ExamOption::factory()->correct()->create([
            'exam_question_id' => $question->getKey(),
            'school_id' => $exam->school_id,
            'option_text' => 'Benar',
        ]);

        ExamOption::factory()->create([
            'exam_question_id' => $question->getKey(),
            'school_id' => $exam->school_id,
            'option_text' => 'Salah',
            'position' => 2,
        ]);

        return $exam->fresh();
    }

    protected function attemptOn(Exam $exam): ExamAttempt
    {
        return ExamAttempt::factory()->create([
            'exam_id' => $exam->getKey(),
            'school_id' => $exam->school_id,
            'student_id' => $this->studentIn($this->schoolA)->getKey(),
        ]);
    }
}
