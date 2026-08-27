<?php

namespace Tests\Feature\Cbt;

use App\Enums\ExamQuestionType;
use App\Enums\ExamStatus;
use App\Enums\RoleName;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamOption;
use App\Models\ExamQuestion;
use App\Models\User;
use App\Services\Cbt\ExamPublisher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Cbt\Concerns\BuildsExamFixture;
use Tests\TestCase;

/**
 * Syarat terbit, tarik kembali, dan tutup.
 *
 * Terbit adalah satu-satunya pintu menuju siswa. Setiap syarat yang lolos di
 * sini menjadi ujian yang tidak dapat dinilai — soal tanpa kunci, kunci ganda,
 * total bobot nol — dan yang menemukannya adalah siswa, di tengah mengerjakan.
 * Karena itu setiap syarat diuji sendiri-sendiri, bukan sebagai satu kasus
 * "ujian tidak lengkap" (butir 286).
 */
class ExamPublishingTest extends TestCase
{
    use BuildsExamFixture, RefreshDatabase;

    protected ExamPublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildExamFixture();
        $this->publisher = app(ExamPublisher::class);
    }

    // ------------------------------------------------------------ jalur bersih

    public function test_a_complete_draft_publishes(): void
    {
        $exam = $this->readyExam();

        $published = $this->publisher->publish($exam, $this->teacherA);

        $this->assertSame(ExamStatus::Published, $published->status);
        $this->assertDatabaseHas('exams', [
            'id' => $exam->getKey(),
            'status' => ExamStatus::Published->value,
        ]);
    }

    /**
     * Terbit kedua tidak boleh menghasilkan efek apa pun. Policy sudah menolak
     * lebih dulu karena statusnya bukan draf lagi — dan penolakan itu berupa
     * AuthorizationException, bukan perpindahan status diam-diam.
     */
    public function test_publishing_twice_is_refused_and_changes_nothing(): void
    {
        $exam = $this->readyExam();

        $this->publisher->publish($exam, $this->teacherA);
        $updatedAt = $exam->fresh()->updated_at;

        $this->expectException(AuthorizationException::class);

        try {
            $this->publisher->publish($exam->fresh(), $this->teacherA);
        } finally {
            $this->assertSame(ExamStatus::Published, $exam->fresh()->status);
            $this->assertEquals($updatedAt, $exam->fresh()->updated_at);
        }
    }

    // ----------------------------------------------------- syarat isi ujian

    public function test_an_exam_without_questions_cannot_publish(): void
    {
        $this->assertRefusal(
            $this->examIn($this->classSubjectA),
            'Ujian belum memiliki satu soal pun.',
        );
    }

    public function test_a_question_with_a_single_option_cannot_publish(): void
    {
        $exam = $this->examIn($this->classSubjectA);
        $question = $this->questionOn($exam);
        $this->optionOn($question, 'A', correct: true);

        $this->assertRefusal($exam, 'Soal nomor 1: soal pilihan ganda membutuhkan minimal 2 pilihan jawaban.');
    }

    public function test_a_question_without_a_correct_option_cannot_publish(): void
    {
        $exam = $this->examIn($this->classSubjectA);
        $question = $this->questionOn($exam);
        $this->optionOn($question, 'A');
        $this->optionOn($question, 'B');

        $this->assertRefusal($exam, 'Soal nomor 1: belum ada pilihan yang ditandai sebagai kunci jawaban.');
    }

    public function test_a_question_with_two_correct_options_cannot_publish(): void
    {
        $exam = $this->examIn($this->classSubjectA);
        $question = $this->questionOn($exam);
        $this->optionOn($question, 'A', correct: true);
        $this->optionOn($question, 'B', correct: true);

        $this->assertRefusal($exam, 'Soal nomor 1: hanya boleh ada satu kunci jawaban.');
    }

    public function test_a_question_with_an_empty_option_cannot_publish(): void
    {
        $exam = $this->examIn($this->classSubjectA);
        $question = $this->questionOn($exam);
        $this->optionOn($question, 'A', correct: true);
        $this->optionOn($question, '   ');

        $this->assertRefusal($exam, 'Soal nomor 1: setiap pilihan jawaban harus diisi.');
    }

    public function test_a_question_worth_zero_points_cannot_publish(): void
    {
        $exam = $this->examIn($this->classSubjectA);
        $question = $this->questionOn($exam, points: 0);
        $this->optionOn($question, 'A', correct: true);
        $this->optionOn($question, 'B');

        $this->assertRefusal($exam, 'Soal nomor 1: bobot soal harus lebih dari 0.');
    }

    /**
     * Uraian ada di skema tetapi tidak didukung rilis ini. Skema menerimanya,
     * jadi penolakannya adalah tanggung jawab aplikasi dan harus diuji sebagai
     * aturan aplikasi (butir 266).
     */
    public function test_an_essay_question_cannot_publish_in_this_release(): void
    {
        $exam = $this->examIn($this->classSubjectA);
        $question = $this->questionOn($exam);
        $question->forceFill(['question_type' => ExamQuestionType::Essay->value])->save();

        $this->assertRefusal($exam, 'Soal nomor 1 bertipe Uraian, yang belum didukung pada rilis ini.');
    }

    public function test_the_offending_question_is_named_by_its_number(): void
    {
        $exam = $this->examIn($this->classSubjectA);

        $first = $this->questionOn($exam, position: 1);
        $this->optionOn($first, 'A', correct: true);
        $this->optionOn($first, 'B');

        $third = $this->questionOn($exam, position: 3);
        $this->optionOn($third, 'A');
        $this->optionOn($third, 'B');

        $this->assertRefusal($exam, 'Soal nomor 3: belum ada pilihan yang ditandai sebagai kunci jawaban.');
    }

    // -------------------------------------------------------- syarat jadwal

    public function test_an_exam_whose_window_closes_before_it_opens_cannot_publish(): void
    {
        $exam = $this->readyExam();
        $exam->forceFill([
            'available_from' => now()->addDay(),
            'available_until' => now()->addHour(),
        ])->save();

        $this->assertRefusal($exam->fresh(), 'Waktu tutup harus setelah waktu buka.');
    }

    public function test_an_exam_with_a_zero_length_window_cannot_publish(): void
    {
        $moment = now()->addDay();
        $exam = $this->readyExam();
        $exam->forceFill(['available_from' => $moment, 'available_until' => $moment])->save();

        $this->assertRefusal($exam->fresh(), 'Waktu tutup harus setelah waktu buka.');
    }

    public function test_an_exam_without_duration_cannot_publish(): void
    {
        $exam = $this->readyExam();
        $exam->forceFill(['duration_minutes' => 0])->save();

        $this->assertRefusal($exam->fresh(), 'Durasi pengerjaan harus lebih dari 0 menit.');
    }

    // ------------------------------------------------- syarat keterkaitan

    /**
     * Rule 3 dan 4 tidak ditulis ulang di ExamPublisher — keduanya milik
     * ExamIntegrity. Yang diuji di sini adalah bahwa terbit benar-benar
     * memanggilnya.
     */
    public function test_publishing_refuses_an_exam_whose_academic_year_drifted(): void
    {
        $exam = $this->readyExam();
        $exam->forceFill(['academic_year_id' => $this->yearIn($this->schoolA)->getKey()])->save();

        $this->assertRefusal(
            $exam->fresh(),
            'Tahun ajaran ujian berbeda dari tahun ajaran kelas–mata pelajarannya.',
        );
    }

    // ------------------------------------------------------ tarik kembali

    public function test_a_published_exam_with_no_attempts_may_be_pulled_back(): void
    {
        $exam = $this->publishedExam();

        $draft = $this->publisher->unpublish($exam, $this->teacherA);

        $this->assertSame(ExamStatus::Draft, $draft->status);
    }

    /**
     * Butir 287 — inti seluruh aturan keabadian. Menarik kembali ujian yang
     * sudah dikerjakan membuka kuncinya untuk diubah sementara ada jawaban yang
     * menunggu dinilai dengan kunci itu.
     */
    public function test_a_published_exam_that_has_been_attempted_may_not_be_pulled_back(): void
    {
        $exam = $this->publishedExam();
        $this->attemptOn($exam);

        try {
            $this->publisher->unpublish($exam->fresh(), $this->teacherA);
            $this->fail('Ujian yang sudah dikerjakan seharusnya tidak dapat ditarik kembali.');
        } catch (AuthorizationException|ValidationException) {
            // Kedua bentuk penolakan sah: policy menutup lebih dulu, service
            // menutup lagi bila dipanggil langsung.
        }

        $this->assertSame(ExamStatus::Published, $exam->fresh()->status);
    }

    public function test_a_draft_exam_cannot_be_pulled_back(): void
    {
        $exam = $this->readyExam();

        $this->expectException(AuthorizationException::class);

        $this->publisher->unpublish($exam, $this->teacherA);
    }

    // -------------------------------------------------------------- tutup

    public function test_a_published_exam_may_be_closed(): void
    {
        $exam = $this->publishedExam();

        $closed = $this->publisher->close($exam, $this->teacherA);

        $this->assertSame(ExamStatus::Closed, $closed->status);
    }

    /**
     * Menutup tidak menghapus apa pun: soal, pilihan, dan pengerjaannya tetap
     * ada. Yang berubah hanya statusnya.
     */
    public function test_closing_destroys_nothing(): void
    {
        $exam = $this->publishedExam();
        $attempt = $this->attemptOn($exam);

        $this->publisher->close($exam->fresh(), $this->teacherA);

        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->getKey()]);
        $this->assertSame(1, $exam->questions()->count());
        $this->assertSame(2, ExamOption::query()->count());
    }

    public function test_a_closed_exam_cannot_be_reopened_or_closed_again(): void
    {
        $exam = $this->publishedExam();
        $this->publisher->close($exam, $this->teacherA);

        foreach (['publish', 'unpublish', 'close'] as $transition) {
            try {
                $this->publisher->{$transition}($exam->fresh(), $this->teacherA);
                $this->fail("Ujian tertutup seharusnya menolak {$transition}.");
            } catch (AuthorizationException|ValidationException) {
                // ditolak sebagaimana mestinya
            }
        }

        $this->assertSame(ExamStatus::Closed, $exam->fresh()->status);
    }

    public function test_a_draft_exam_cannot_be_closed(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->publisher->close($this->readyExam(), $this->teacherA);
    }

    // ------------------------------------------------------- kewenangan aktor

    public function test_a_teacher_cannot_publish_an_exam_of_a_class_they_do_not_teach(): void
    {
        $exam = $this->readyExam();
        $stranger = $this->userIn($this->schoolA, RoleName::Guru);

        $this->expectException(AuthorizationException::class);

        $this->publisher->publish($exam, $stranger);
    }

    public function test_a_head_teacher_cannot_publish(): void
    {
        $exam = $this->readyExam();

        $this->expectException(AuthorizationException::class);

        $this->publisher->publish($exam, $this->userIn($this->schoolA, RoleName::KepalaSekolah));
    }

    public function test_a_school_admin_from_another_branch_cannot_publish(): void
    {
        $exam = $this->readyExam();

        $this->expectException(AuthorizationException::class);

        $this->publisher->publish($exam, $this->userIn($this->schoolB, RoleName::SchoolAdmin));
    }

    public function test_a_super_admin_may_publish(): void
    {
        $exam = $this->readyExam();

        $published = $this->publisher->publish($exam, User::factory()->superAdmin()->create());

        $this->assertSame(ExamStatus::Published, $published->status);
    }

    // ------------------------------------------------------------- penunjang

    /**
     * @param  ValidationException|string  $expected  potongan pesan yang harus muncul
     */
    protected function assertRefusal(Exam $exam, string $expected): void
    {
        $this->assertSame($expected, $this->publisher->reasonToRefusePublishing($exam));

        try {
            $this->publisher->publish($exam, $this->teacherA);
            $this->fail("Ujian seharusnya ditolak: {$expected}");
        } catch (ValidationException $exception) {
            $this->assertSame($expected, (string) collect($exception->errors())->flatten()->first());
        }

        $this->assertSame(
            ExamStatus::Draft,
            $exam->fresh()->status,
            'Ujian yang ditolak harus tetap draf.',
        );
    }

    /**
     * Ujian draf yang seluruh syarat isinya sudah terpenuhi.
     */
    protected function readyExam(): Exam
    {
        $exam = $this->examIn($this->classSubjectA);
        $question = $this->questionOn($exam);
        $this->optionOn($question, 'Benar', correct: true);
        $this->optionOn($question, 'Salah');

        return $exam->fresh();
    }

    protected function publishedExam(): Exam
    {
        return $this->publisher->publish($this->readyExam(), $this->teacherA);
    }

    protected function questionOn(Exam $exam, float $points = 1.0, int $position = 1): ExamQuestion
    {
        return ExamQuestion::factory()->create([
            'exam_id' => $exam->getKey(),
            'school_id' => $exam->school_id,
            'points' => $points,
            'position' => $position,
        ]);
    }

    protected function optionOn(ExamQuestion $question, string $text, bool $correct = false): ExamOption
    {
        return ExamOption::factory()->create([
            'exam_question_id' => $question->getKey(),
            'school_id' => $question->school_id,
            'option_text' => $text,
            'is_correct' => $correct,
        ]);
    }

    protected function attemptOn(Exam $exam): ExamAttempt
    {
        return ExamAttempt::factory()->create([
            'exam_id' => $exam->getKey(),
            'school_id' => $exam->school_id,
            'student_id' => $this->studentA->getKey(),
        ]);
    }
}
