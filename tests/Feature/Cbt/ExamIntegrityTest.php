<?php

namespace Tests\Feature\Cbt;

use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamOption;
use App\Models\ExamQuestion;
use App\Models\Student;
use App\Models\User;
use App\Services\Cbt\ExamIntegrity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Cbt\Concerns\BuildsExamFixture;
use Tests\TestCase;

/**
 * Keterkaitan yang foreign key **tidak** menjamin.
 *
 * Setiap baris di bawah ini dapat disimpan database tanpa satu keberatan pun:
 * id-nya ada, tabelnya benar, tipenya cocok. Yang salah adalah maknanya — soal
 * dari ujian lain, siswa dari cabang lain, pilihan jawaban milik soal yang
 * berbeda. Justru karena database menerimanya, aplikasilah yang harus menolak,
 * dan penolakan itu harus diuji dengan baris yang benar-benar tersimpan
 * (butir 274).
 */
class ExamIntegrityTest extends TestCase
{
    use BuildsExamFixture, RefreshDatabase;

    protected ExamIntegrity $integrity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildExamFixture();
        $this->integrity = app(ExamIntegrity::class);
    }

    // ------------------------------------------------------------------ ujian

    public function test_a_consistent_exam_is_accepted(): void
    {
        $exam = $this->examIn($this->classSubjectA);

        $this->assertNull($this->integrity->reasonToRejectExam($exam));
        $this->assertTrue($this->integrity->accepts($exam));
    }

    public function test_an_exam_pointing_at_a_class_subject_of_another_school_is_rejected(): void
    {
        $exam = Exam::factory()->create([
            'class_subject_id' => $this->classSubjectB->getKey(),
            'school_id' => $this->schoolA->getKey(),
            'academic_year_id' => $this->yearB->getKey(),
            'created_by' => $this->teacherA->getKey(),
        ]);

        $this->assertSame(
            'Kelas–mata pelajaran berasal dari cabang lain.',
            $this->integrity->reasonToRejectExam($exam),
        );
    }

    public function test_an_exam_whose_academic_year_differs_from_its_class_subject_is_rejected(): void
    {
        $otherYear = $this->yearIn($this->schoolA);

        $exam = $this->examIn($this->classSubjectA, ['academic_year_id' => $otherYear->getKey()]);

        $this->assertSame(
            'Tahun ajaran ujian berbeda dari tahun ajaran kelas–mata pelajarannya.',
            $this->integrity->reasonToRejectExam($exam),
        );
    }

    public function test_an_exam_created_by_an_account_from_another_school_is_rejected(): void
    {
        $exam = $this->examIn($this->classSubjectA, ['created_by' => $this->teacherB->getKey()]);

        $this->assertSame(
            'Akun pembuat ujian berasal dari cabang lain.',
            $this->integrity->reasonToRejectExam($exam),
        );
    }

    /**
     * Butir 276 — akun Platform Level punya `school_id` NULL menurut Arsitektur
     * 3.2.2. Itu bukan ketidaksesuaian cabang, dan menolaknya akan membuat Super
     * Admin tidak dapat membuat apa pun.
     */
    public function test_an_exam_created_by_a_platform_level_account_is_accepted(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $exam = $this->examIn($this->classSubjectA, ['created_by' => $superAdmin->getKey()]);

        $this->assertNull($this->integrity->reasonToRejectExam($exam));
    }

    public function test_an_exam_without_a_creator_is_accepted(): void
    {
        $exam = $this->examIn($this->classSubjectA, ['created_by' => null]);

        $this->assertNull($this->integrity->reasonToRejectExam($exam));
    }

    // ------------------------------------------------------- soal & pilihan

    public function test_a_question_from_another_school_than_its_exam_is_rejected(): void
    {
        $exam = $this->examIn($this->classSubjectA);

        $question = ExamQuestion::factory()->create([
            'exam_id' => $exam->getKey(),
            'school_id' => $this->schoolB->getKey(),
        ]);

        $this->assertSame(
            'Soal dan ujiannya berasal dari cabang berbeda.',
            $this->integrity->reasonToRejectQuestion($question),
        );
    }

    public function test_a_consistent_question_and_option_are_accepted(): void
    {
        $question = $this->questionIn($this->examIn($this->classSubjectA));

        $option = ExamOption::factory()->correct()->create([
            'exam_question_id' => $question->getKey(),
            'school_id' => $this->schoolA->getKey(),
        ]);

        $this->assertNull($this->integrity->reasonToRejectQuestion($question));
        $this->assertNull($this->integrity->reasonToRejectOption($option));
    }

    public function test_an_option_from_another_school_than_its_question_is_rejected(): void
    {
        $question = $this->questionIn($this->examIn($this->classSubjectA));

        $option = ExamOption::factory()->create([
            'exam_question_id' => $question->getKey(),
            'school_id' => $this->schoolB->getKey(),
        ]);

        $this->assertSame(
            'Pilihan jawaban dan soalnya berasal dari cabang berbeda.',
            $this->integrity->reasonToRejectOption($option),
        );
    }

    // ------------------------------------------------------------ pengerjaan

    public function test_a_consistent_attempt_is_accepted(): void
    {
        $attempt = $this->attemptOn($this->examIn($this->classSubjectA), $this->studentA);

        $this->assertNull($this->integrity->reasonToRejectAttempt($attempt));
    }

    public function test_an_attempt_by_a_student_of_another_school_is_rejected(): void
    {
        $attempt = ExamAttempt::factory()->create([
            'exam_id' => $this->examIn($this->classSubjectA)->getKey(),
            'school_id' => $this->schoolA->getKey(),
            'student_id' => $this->studentB->getKey(),
        ]);

        $this->assertSame(
            'Siswa ini terdaftar di cabang lain.',
            $this->integrity->reasonToRejectAttempt($attempt),
        );
    }

    public function test_an_attempt_on_an_exam_of_another_school_is_rejected(): void
    {
        $attempt = ExamAttempt::factory()->create([
            'exam_id' => $this->examIn($this->classSubjectB)->getKey(),
            'school_id' => $this->schoolA->getKey(),
            'student_id' => $this->studentA->getKey(),
        ]);

        $this->assertSame(
            'Ujian ini milik cabang lain.',
            $this->integrity->reasonToRejectAttempt($attempt),
        );
    }

    // --------------------------------------------------------------- jawaban

    public function test_a_consistent_answer_is_accepted(): void
    {
        $exam = $this->examIn($this->classSubjectA);
        $question = $this->questionIn($exam);
        $option = ExamOption::factory()->correct()->create([
            'exam_question_id' => $question->getKey(),
            'school_id' => $this->schoolA->getKey(),
        ]);

        $answer = ExamAnswer::factory()->create([
            'exam_attempt_id' => $this->attemptOn($exam, $this->studentA)->getKey(),
            'exam_question_id' => $question->getKey(),
            'exam_option_id' => $option->getKey(),
            'school_id' => $this->schoolA->getKey(),
        ]);

        $this->assertNull($this->integrity->reasonToRejectAnswer($answer));
    }

    /**
     * Soal yang dijawab harus bagian dari ujian yang sedang dikerjakan. Tanpa
     * pemeriksaan ini, id soal dari ujian mana pun — termasuk ujian mata
     * pelajaran lain — akan diterima foreign key tanpa keberatan.
     */
    public function test_an_answer_cannot_point_at_a_question_from_another_exam(): void
    {
        $attempt = $this->attemptOn($this->examIn($this->classSubjectA), $this->studentA);
        $strayQuestion = $this->questionIn($this->examIn($this->classSubjectA));

        $answer = ExamAnswer::factory()->create([
            'exam_attempt_id' => $attempt->getKey(),
            'exam_question_id' => $strayQuestion->getKey(),
            'school_id' => $this->schoolA->getKey(),
        ]);

        $this->assertSame(
            'Soal ini bukan bagian dari ujian yang sedang dikerjakan.',
            $this->integrity->reasonToRejectAnswer($answer),
        );
    }

    /**
     * Bentuk kecurangan yang paling langsung: menukar id pilihan jawaban dengan
     * pilihan milik soal lain, yang kebetulan bertanda benar.
     */
    public function test_an_answer_cannot_point_at_an_option_of_another_question(): void
    {
        $exam = $this->examIn($this->classSubjectA);
        $answered = $this->questionIn($exam);
        $other = $this->questionIn($exam, 2);

        $foreignOption = ExamOption::factory()->correct()->create([
            'exam_question_id' => $other->getKey(),
            'school_id' => $this->schoolA->getKey(),
        ]);

        $answer = ExamAnswer::factory()->create([
            'exam_attempt_id' => $this->attemptOn($exam, $this->studentA)->getKey(),
            'exam_question_id' => $answered->getKey(),
            'exam_option_id' => $foreignOption->getKey(),
            'school_id' => $this->schoolA->getKey(),
        ]);

        $this->assertSame(
            'Pilihan jawaban ini milik soal lain.',
            $this->integrity->reasonToRejectAnswer($answer),
        );
    }

    /**
     * Soal yang dilewati bernilai nol, bukan tidak sah — dan tidak boleh
     * tersangkut pemeriksaan pilihan jawaban.
     */
    public function test_an_unanswered_question_is_accepted(): void
    {
        $exam = $this->examIn($this->classSubjectA);
        $question = $this->questionIn($exam);

        $answer = ExamAnswer::factory()->create([
            'exam_attempt_id' => $this->attemptOn($exam, $this->studentA)->getKey(),
            'exam_question_id' => $question->getKey(),
            'exam_option_id' => null,
            'school_id' => $this->schoolA->getKey(),
        ]);

        $this->assertNull($this->integrity->reasonToRejectAnswer($answer));
        $this->assertTrue($answer->isUnanswered());
    }

    public function test_an_answer_from_another_school_than_its_attempt_is_rejected(): void
    {
        $exam = $this->examIn($this->classSubjectA);

        $answer = ExamAnswer::factory()->create([
            'exam_attempt_id' => $this->attemptOn($exam, $this->studentA)->getKey(),
            'exam_question_id' => $this->questionIn($exam)->getKey(),
            'school_id' => $this->schoolB->getKey(),
        ]);

        $this->assertSame(
            'Jawaban dan pengerjaannya berasal dari cabang berbeda.',
            $this->integrity->reasonToRejectAnswer($answer),
        );
    }

    // ------------------------------------------------------------- pembungkus

    public function test_the_generic_entry_point_routes_to_the_right_check(): void
    {
        $exam = $this->examIn($this->classSubjectA);

        $this->assertTrue($this->integrity->accepts($exam));
        $this->assertTrue($this->integrity->accepts($this->questionIn($exam)));
        $this->assertTrue($this->integrity->accepts($this->attemptOn($exam, $this->studentA)));

        // Model di luar CBT ditolak, bukan diterima diam-diam.
        $this->assertFalse($this->integrity->accepts($this->studentA));
    }

    protected function questionIn(Exam $exam, int $position = 1): ExamQuestion
    {
        return ExamQuestion::factory()->create([
            'exam_id' => $exam->getKey(),
            'school_id' => $exam->school_id,
            'position' => $position,
        ]);
    }

    protected function attemptOn(Exam $exam, Student $student): ExamAttempt
    {
        return ExamAttempt::factory()->create([
            'exam_id' => $exam->getKey(),
            'school_id' => $exam->school_id,
            'student_id' => $student->getKey(),
        ]);
    }
}
