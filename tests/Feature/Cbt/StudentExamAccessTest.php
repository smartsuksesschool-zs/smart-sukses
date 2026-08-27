<?php

namespace Tests\Feature\Cbt;

use App\Enums\ExamStatus;
use App\Enums\RoleName;
use App\Enums\StudentExamState;
use App\Livewire\Student\StudentExams;
use App\Models\Exam;
use App\Models\User;
use App\Services\Cbt\ExamAttemptService;
use App\Services\Cbt\ExamPublisher;
use App\Services\Cbt\StudentExamService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Cbt\Concerns\BuildsStudentExamFixture;
use Tests\TestCase;

/**
 * Siapa yang boleh melihat ujian mana.
 *
 * Alur siswa punya bentuk kebocoran yang tidak ada di alur guru: siswa saling
 * berbagi kelas, cabang, dan alamat halaman, sehingga satu id yang diganti di
 * bilah alamat adalah percobaan yang paling mungkin benar-benar terjadi. Karena
 * itu setiap penolakan diuji lewat jalur yang sesungguhnya, bukan lewat policy
 * saja (butir 308).
 */
class StudentExamAccessTest extends TestCase
{
    use BuildsStudentExamFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStudentExamFixture();
    }

    // ------------------------------------------------------------------ rute

    public function test_the_exam_routes_carry_no_student_identifier(): void
    {
        foreach (['student.exams', 'student.exam', 'student.exam-result'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route, "Rute {$name} belum terdaftar.");
            $this->assertStringNotContainsString('{studentId}', $route->uri());
            $this->assertStringNotContainsString('{student}', $route->uri());
        }

        $this->assertSame('siswa/ujian', app('router')->getRoutes()->getByName('student.exams')->uri());
    }

    public function test_the_exam_menu_appears_in_the_student_portal(): void
    {
        $this->actingAs($this->studentUserA);

        $response = $this->get(route('student.dashboard'))->assertOk();

        $response->assertSee('Ujian');
        $response->assertSee('href="'.route('student.exams').'"', false);
    }

    public function test_a_guest_is_sent_to_the_student_login(): void
    {
        $this->get(route('student.exams'))->assertRedirect(route('student.login'));
    }

    // ------------------------------------------------------- peran lain

    public function test_a_parent_cannot_use_the_student_exam_routes(): void
    {
        $this->actingAs($this->userIn($this->schoolA, RoleName::OrangTua));

        $this->get(route('student.exams'))->assertForbidden();
        $this->get(route('student.exam', ['examId' => 1]))->assertForbidden();
        $this->get(route('student.exam-result', ['examId' => 1]))->assertForbidden();
    }

    /**
     * Guru dan admin punya panel; portal siswa bukan pintu kedua bagi mereka.
     * Sesi mereka tidak pernah menjelma menjadi identitas seorang siswa.
     */
    public function test_a_teacher_or_admin_session_cannot_become_a_student(): void
    {
        foreach ([RoleName::Guru, RoleName::SchoolAdmin, RoleName::KepalaSekolah] as $role) {
            $this->actingAs($this->userIn($this->schoolA, $role));

            $this->get(route('student.exams'))->assertForbidden();
        }
    }

    public function test_a_branchless_student_account_is_refused(): void
    {
        $orphan = User::factory()->withRole(RoleName::Siswa)->create(['school_id' => null]);

        $this->actingAs($orphan);

        $this->get(route('student.exams'))->assertForbidden();
    }

    /**
     * Akun berperan SISWA yang belum tertaut ke data siswa mendapat keterangan,
     * bukan halaman kesalahan — dan bukan data siswa orang lain (butir 182).
     */
    public function test_an_unlinked_student_account_sees_an_explanation(): void
    {
        $unlinked = $this->userIn($this->schoolA, RoleName::Siswa);

        $this->actingAs($unlinked);

        $this->get(route('student.exams'))->assertOk();
    }

    // ------------------------------------------------------- kelayakan ujian

    public function test_the_board_shows_only_exams_of_the_own_class(): void
    {
        $mine = $this->openExamIn($this->classSubjectA, ['title' => 'UH Kelas Saya']);

        // Kelas lain di cabang yang sama.
        $otherClass = $this->classIn($this->schoolA, $this->yearA);
        $otherClassSubject = $this->classSubjectIn($this->schoolA, $otherClass, $this->teacherA);
        $this->openExamIn($otherClassSubject, ['title' => 'UH Kelas Lain']);

        $rows = app(StudentExamService::class)->board($this->studentUserA);

        $this->assertCount(1, $rows);
        $this->assertSame($mine->getKey(), $rows[0]['id']);
    }

    public function test_a_student_never_sees_an_exam_of_another_school(): void
    {
        $this->openExamIn($this->classSubjectB, ['title' => 'UH Seberang']);

        $this->assertSame([], app(StudentExamService::class)->board($this->studentUserA));
    }

    public function test_a_draft_exam_is_invisible_and_unreachable(): void
    {
        $draft = $this->readyExamIn($this->classSubjectA);

        $this->assertSame(ExamStatus::Draft, $draft->status);
        $this->assertSame([], app(StudentExamService::class)->board($this->studentUserA));

        $this->expectException(ModelNotFoundException::class);

        app(StudentExamService::class)->examFor($this->studentUserA, $draft->getKey());
    }

    public function test_an_exam_of_another_school_is_not_found_rather_than_forbidden(): void
    {
        $foreign = $this->openExamIn($this->classSubjectB);

        $this->expectException(ModelNotFoundException::class);

        app(StudentExamService::class)->examFor($this->studentUserA, $foreign->getKey());
    }

    public function test_a_closed_exam_keeps_its_result_visible(): void
    {
        [$exam, $questions] = $this->openExamWithQuestions([1.0]);

        app(ExamAttemptService::class)->startOrResume($this->studentUserA, $exam->getKey());
        app(ExamAttemptService::class)->saveAnswer(
            $this->studentUserA,
            $exam->getKey(),
            $questions[0]->getKey(),
            $this->correctOptionOf($questions[0])->getKey(),
        );
        app(ExamAttemptService::class)->submit($this->studentUserA, $exam->getKey());

        app(ExamPublisher::class)->close($exam->fresh(), $this->teacherA);

        $rows = app(StudentExamService::class)->board($this->studentUserA);

        $this->assertCount(1, $rows);
        $this->assertSame(StudentExamState::Submitted, $rows[0]['state']);
        $this->assertSame(100.0, $rows[0]['score']);
    }

    public function test_a_student_without_an_active_class_sees_nothing(): void
    {
        $this->openExamIn($this->classSubjectA);

        // Penempatan kelasnya dicabut; izinnya tidak berubah.
        $this->studentA->studentClasses()->delete();

        $this->assertSame([], app(StudentExamService::class)->board($this->studentUserA));
    }

    // ---------------------------------------------------------- keadaan baris

    public function test_the_board_derives_each_state(): void
    {
        $upcoming = $this->openExamIn($this->classSubjectA, [
            'title' => 'Nanti',
            'available_from' => now()->addDay(),
            'available_until' => now()->addDays(2),
        ]);

        $available = $this->openExamIn($this->classSubjectA, ['title' => 'Sekarang']);

        $missed = $this->openExamIn($this->classSubjectA, [
            'title' => 'Lewat',
            'available_from' => now()->subDays(2),
            'available_until' => now()->subDay(),
        ]);

        $states = collect(app(StudentExamService::class)->board($this->studentUserA))
            ->pluck('state', 'id');

        $this->assertSame(StudentExamState::Upcoming, $states[$upcoming->getKey()]);
        $this->assertSame(StudentExamState::Available, $states[$available->getKey()]);
        $this->assertSame(StudentExamState::Missed, $states[$missed->getKey()]);
    }

    public function test_an_in_progress_attempt_shows_as_in_progress(): void
    {
        $exam = $this->openExamIn($this->classSubjectA);

        app(ExamAttemptService::class)->startOrResume($this->studentUserA, $exam->getKey());

        $rows = app(StudentExamService::class)->board($this->studentUserA);

        $this->assertSame(StudentExamState::InProgress, $rows[0]['state']);
        $this->assertNull($rows[0]['score']);
    }

    public function test_the_board_renders_for_the_logged_in_student(): void
    {
        $exam = $this->openExamIn($this->classSubjectA, ['title' => 'Ulangan Bab Satu']);

        $this->actingAs($this->studentUserA);

        Livewire::test(StudentExams::class)
            ->assertOk()
            ->assertSee('Ulangan Bab Satu')
            ->assertSee('Mulai Ujian');

        $this->assertNotNull($exam);
    }

    /**
     * Siswa lain di kelas yang sama tidak boleh melihat pengerjaan milik
     * temannya — bukan nilainya, bukan jawabannya, bukan keadaannya.
     */
    public function test_one_student_never_sees_the_attempt_of_another(): void
    {
        $exam = $this->openExamIn($this->classSubjectA);
        $peer = $this->studentIn($this->schoolA);
        $peerUser = $this->linkStudent($peer, $this->classA);

        app(ExamAttemptService::class)->startOrResume($peerUser, $exam->getKey());

        $rows = app(StudentExamService::class)->board($this->studentUserA);

        $this->assertSame(StudentExamState::Available, $rows[0]['state']);
        $this->assertNull($rows[0]['score']);
        $this->assertNull($rows[0]['submitted_at']);
    }

    public function test_a_student_cannot_submit_the_attempt_of_another_student(): void
    {
        $exam = $this->openExamIn($this->classSubjectA);
        $peer = $this->studentIn($this->schoolA);
        $peerUser = $this->linkStudent($peer, $this->classA);

        app(ExamAttemptService::class)->startOrResume($peerUser, $exam->getKey());

        // Siswa lain menekan Kumpulkan pada ujian yang sama: ia tidak punya
        // pengerjaan sendiri, dan tidak pernah menyentuh milik temannya.
        try {
            app(ExamAttemptService::class)->submit($this->studentUserA, $exam->getKey());
            $this->fail('Seharusnya ditolak karena belum memulai.');
        } catch (ValidationException) {
            // sebagaimana mestinya
        }

        $this->assertSame(1, DB::table('exam_attempts')->count());
        $this->assertNull(DB::table('exam_attempts')->value('submitted_at'));
    }

    // ------------------------------------------------------------- performa

    /**
     * Daftar ujian tidak boleh menanyakan mata pelajaran, kelas, jumlah soal,
     * dan pengerjaan sekali per baris (butir 312).
     */
    public function test_the_board_does_not_grow_a_query_per_exam(): void
    {
        $this->openExamIn($this->classSubjectA);

        // Pemanasan: pemuatan izin dan sejenisnya sekali untuk seluruh proses.
        app(StudentExamService::class)->board($this->studentUserA);

        $withOne = $this->countQueries(fn () => app(StudentExamService::class)->board($this->studentUserA));

        for ($index = 0; $index < 5; $index++) {
            $this->openExamIn($this->classSubjectA);
        }

        $withSix = $this->countQueries(fn () => app(StudentExamService::class)->board($this->studentUserA));

        $this->assertSame(
            $withOne,
            $withSix,
            "Daftar ujian membayar query tambahan per baris: {$withOne} untuk satu, {$withSix} untuk enam.",
        );
    }

    public function test_the_taking_page_loads_questions_in_bounded_queries(): void
    {
        [$exam] = $this->openExamWithQuestions([1.0]);

        $service = app(StudentExamService::class);
        $service->questionsFor($exam);

        $withOne = $this->countQueries(fn () => $service->questionsFor($exam));

        [$bigger] = $this->openExamWithQuestions([1.0, 1.0, 1.0, 1.0, 1.0, 1.0]);

        $withSix = $this->countQueries(fn () => $service->questionsFor($bigger));

        $this->assertSame(
            $withOne,
            $withSix,
            "Soal dimuat per baris: {$withOne} untuk satu soal, {$withSix} untuk enam.",
        );
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

    protected function examTitles(): array
    {
        return Exam::query()->pluck('title')->all();
    }
}
