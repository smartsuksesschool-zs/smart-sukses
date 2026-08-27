<?php

namespace Tests\Feature\Cbt;

use App\Enums\ExamAttemptStatus;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\Grade;
use App\Models\ReportCard;
use App\Services\Cbt\ExamAttemptService;
use App\Services\Cbt\ExamScoringService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Cbt\Concerns\BuildsStudentExamFixture;
use Tests\TestCase;

/**
 * Penilaian otomatis.
 *
 * Nilai adalah satu-satunya keluaran CBT yang dilihat orang, dan satu-satunya
 * yang tidak dapat diperiksa ulang oleh siswa. Karena itu rumusnya diuji dari
 * kedua ujungnya — semua benar dan semua salah — lalu di antaranya, lalu pada
 * bobot yang tidak sama rata, lalu pada soal yang dilewati.
 */
class StudentExamScoringTest extends TestCase
{
    use BuildsStudentExamFixture, RefreshDatabase;

    protected ExamAttemptService $attempts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStudentExamFixture();
        $this->attempts = app(ExamAttemptService::class);
    }

    // --------------------------------------------------------------- rumus

    public function test_all_correct_scores_one_hundred(): void
    {
        [$exam, $questions] = $this->openExamWithQuestions([1.0, 1.0, 1.0, 1.0]);

        $attempt = $this->answerAll($exam, $questions, correct: true);

        $this->assertSame('100.00', $attempt->score);
    }

    public function test_all_wrong_scores_zero(): void
    {
        [$exam, $questions] = $this->openExamWithQuestions([1.0, 1.0, 1.0, 1.0]);

        $attempt = $this->answerAll($exam, $questions, correct: false);

        $this->assertSame('0.00', $attempt->score);
    }

    public function test_a_mixed_answer_sheet_scores_the_right_percentage(): void
    {
        [$exam, $questions] = $this->openExamWithQuestions([1.0, 1.0, 1.0, 1.0]);

        $this->attempts->startOrResume($this->studentUserA, $exam->getKey());

        // Tiga benar dari empat.
        foreach ([0, 1, 2] as $index) {
            $this->answer($exam, $questions[$index], correct: true);
        }
        $this->answer($exam, $questions[3], correct: false);

        $attempt = $this->attempts->submit($this->studentUserA, $exam->getKey());

        $this->assertSame('75.00', $attempt->score);
    }

    public function test_an_unanswered_question_contributes_nothing(): void
    {
        [$exam, $questions] = $this->openExamWithQuestions([1.0, 1.0]);

        $this->attempts->startOrResume($this->studentUserA, $exam->getKey());
        $this->answer($exam, $questions[0], correct: true);

        $attempt = $this->attempts->submit($this->studentUserA, $exam->getKey());

        $this->assertSame('50.00', $attempt->score);

        // Soal yang dilewati tetap mendapat barisnya, bernilai nol (butir 314).
        $skipped = ExamAnswer::query()->where('exam_question_id', $questions[1]->getKey())->firstOrFail();

        $this->assertNull($skipped->exam_option_id);
        $this->assertFalse($skipped->is_correct);
        $this->assertSame('0.00', $skipped->points_earned);
        $this->assertTrue($skipped->isUnanswered());
    }

    public function test_an_empty_answer_sheet_scores_zero(): void
    {
        [$exam] = $this->openExamWithQuestions([1.0, 1.0, 1.0]);

        $this->attempts->startOrResume($this->studentUserA, $exam->getKey());

        $attempt = $this->attempts->submit($this->studentUserA, $exam->getKey());

        $this->assertSame('0.00', $attempt->score);
        $this->assertSame(3, ExamAnswer::query()->count());
    }

    /**
     * Bobot yang tidak sama rata tetap dinormalisasi ke 0–100. Menjawab benar
     * soal bernilai 3 dari total 5 berarti 60, bukan 3.
     */
    public function test_weighted_questions_normalise_to_one_hundred(): void
    {
        [$exam, $questions] = $this->openExamWithQuestions([3.0, 2.0]);

        $this->attempts->startOrResume($this->studentUserA, $exam->getKey());
        $this->answer($exam, $questions[0], correct: true);
        $this->answer($exam, $questions[1], correct: false);

        $attempt = $this->attempts->submit($this->studentUserA, $exam->getKey());

        $this->assertSame('60.00', $attempt->score);
        $this->assertSame('3.00', ExamAnswer::query()
            ->where('exam_question_id', $questions[0]->getKey())
            ->value('points_earned'));
    }

    /**
     * Satu benar dari tiga adalah 33.333…, dan yang disimpan 33.33 — pembulatan
     * yang sama dengan yang dipakai perhitungan nilai rapor.
     */
    public function test_the_score_is_rounded_to_two_decimals(): void
    {
        [$exam, $questions] = $this->openExamWithQuestions([1.0, 1.0, 1.0]);

        $this->attempts->startOrResume($this->studentUserA, $exam->getKey());
        $this->answer($exam, $questions[0], correct: true);

        $attempt = $this->attempts->submit($this->studentUserA, $exam->getKey());

        $this->assertSame('33.33', $attempt->score);
    }

    public function test_the_formula_is_exposed_as_one_function(): void
    {
        $scoring = app(ExamScoringService::class);

        $this->assertSame(100.0, $scoring->scoreFrom(5.0, 5.0));
        $this->assertSame(0.0, $scoring->scoreFrom(0.0, 5.0));
        $this->assertSame(33.33, $scoring->scoreFrom(1.0, 3.0));
        // Total nol tidak boleh menjadi kesalahan fatal saat siswa menekan
        // Kumpulkan, walaupun penerbitan sudah mencegahnya (butir 286).
        $this->assertSame(0.0, $scoring->scoreFrom(0.0, 0.0));
    }

    // ------------------------------------------------- server yang menilai

    public function test_correctness_and_points_are_written_by_the_server(): void
    {
        [$exam, $questions] = $this->openExamWithQuestions([2.0, 2.0]);

        $this->attempts->startOrResume($this->studentUserA, $exam->getKey());
        $this->answer($exam, $questions[0], correct: true);
        $this->answer($exam, $questions[1], correct: false);

        $this->attempts->submit($this->studentUserA, $exam->getKey());

        $right = ExamAnswer::query()->where('exam_question_id', $questions[0]->getKey())->firstOrFail();
        $wrong = ExamAnswer::query()->where('exam_question_id', $questions[1]->getKey())->firstOrFail();

        $this->assertTrue($right->is_correct);
        $this->assertSame('2.00', $right->points_earned);
        $this->assertFalse($wrong->is_correct);
        $this->assertSame('0.00', $wrong->points_earned);
    }

    /**
     * Nilai yang sudah tersimpan tidak dapat digeser dengan menulis ke baris
     * jawaban: penilaian membacanya ulang dari kunci, bukan dari kolom itu.
     */
    public function test_tampering_with_a_stored_answer_flag_cannot_change_the_score(): void
    {
        [$exam, $questions] = $this->openExamWithQuestions([1.0, 1.0]);

        $this->attempts->startOrResume($this->studentUserA, $exam->getKey());
        $this->answer($exam, $questions[0], correct: false);
        $this->answer($exam, $questions[1], correct: false);

        // Menirukan payload yang berhasil menyelundupkan is_correct/points.
        ExamAnswer::query()->update(['is_correct' => true, 'points_earned' => 99]);

        $attempt = $this->attempts->submit($this->studentUserA, $exam->getKey());

        $this->assertSame('0.00', $attempt->score);
    }

    public function test_submission_marks_the_attempt_and_stamps_the_server_time(): void
    {
        CarbonImmutable::setTestNow('2026-08-27 09:30:00');

        [$exam] = $this->openExamWithQuestions([1.0]);

        $this->attempts->startOrResume($this->studentUserA, $exam->getKey());
        $attempt = $this->attempts->submit($this->studentUserA, $exam->getKey());

        $this->assertSame(ExamAttemptStatus::Submitted, $attempt->status);
        $this->assertSame('2026-08-27 09:30:00', $attempt->submitted_at->toDateTimeString());

        CarbonImmutable::setTestNow();
    }

    // ------------------------------------------------------- idempotensi

    /**
     * Butir 315 — klik ganda tidak boleh menghasilkan nilai kedua.
     */
    public function test_submitting_twice_has_no_second_effect(): void
    {
        [$exam, $questions] = $this->openExamWithQuestions([1.0]);

        $this->attempts->startOrResume($this->studentUserA, $exam->getKey());
        $this->answer($exam, $questions[0], correct: true);

        $first = $this->attempts->submit($this->studentUserA, $exam->getKey());
        $submittedAt = $first->submitted_at;

        $second = $this->attempts->submit($this->studentUserA, $exam->getKey());

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame('100.00', $second->score);
        $this->assertEquals($submittedAt, $second->fresh()->submitted_at);
        $this->assertSame(1, ExamAnswer::query()->count());
        $this->assertSame(1, ExamAttempt::query()->count());
    }

    // ---------------------------------------------------------- kedaluwarsa

    /**
     * Butir 313 — tanpa penjadwal. Pengerjaan yang lewat batas ditutup memakai
     * jawaban yang sudah tersimpan, lewat mesin penilaian yang sama.
     */
    public function test_an_expired_attempt_is_finalised_with_the_saved_answers(): void
    {
        [$exam, $questions] = $this->openExamWithQuestions([1.0, 1.0]);

        $attempt = $this->attempts->startOrResume($this->studentUserA, $exam->getKey());
        $this->answer($exam, $questions[0], correct: true);

        $expiresAt = CarbonImmutable::now()->subMinutes(5);
        $attempt->forceFill(['expires_at' => $expiresAt])->save();

        $finalised = $this->attempts->finalizeIfExpired($attempt->fresh());

        $this->assertSame(ExamAttemptStatus::Submitted, $finalised->status);
        $this->assertSame('50.00', $finalised->score);
        // Butir 316 — waktu pengumpulannya adalah detik pengerjaannya berakhir,
        // bukan detik seseorang kebetulan membuka halaman lagi.
        $this->assertSame($expiresAt->toDateTimeString(), $finalised->submitted_at->toDateTimeString());
        $this->assertSame($expiresAt->toDateTimeString(), $finalised->expires_at->toDateTimeString());
    }

    public function test_finalising_an_unexpired_attempt_changes_nothing(): void
    {
        [$exam] = $this->openExamWithQuestions([1.0]);

        $attempt = $this->attempts->startOrResume($this->studentUserA, $exam->getKey());

        $unchanged = $this->attempts->finalizeIfExpired($attempt);

        $this->assertSame(ExamAttemptStatus::InProgress, $unchanged->status);
        $this->assertNull($unchanged->score);
    }

    public function test_the_board_finalises_a_stale_attempt(): void
    {
        [$exam, $questions] = $this->openExamWithQuestions([1.0]);

        $attempt = $this->attempts->startOrResume($this->studentUserA, $exam->getKey());
        $this->answer($exam, $questions[0], correct: true);
        $attempt->forceFill(['expires_at' => now()->subHour()])->save();

        $this->assertSame(1, $this->attempts->finalizeExpiredFor($this->studentA));

        $this->assertSame(ExamAttemptStatus::Submitted, $attempt->fresh()->status);
        $this->assertSame('100.00', $attempt->fresh()->score);
    }

    public function test_submitting_after_expiry_uses_the_expiry_moment(): void
    {
        [$exam, $questions] = $this->openExamWithQuestions([1.0]);

        $attempt = $this->attempts->startOrResume($this->studentUserA, $exam->getKey());
        $this->answer($exam, $questions[0], correct: true);

        $expiresAt = CarbonImmutable::now()->subMinutes(3);
        $attempt->forceFill(['expires_at' => $expiresAt])->save();

        $submitted = $this->attempts->submit($this->studentUserA, $exam->getKey());

        $this->assertSame('100.00', $submitted->score);
        $this->assertSame($expiresAt->toDateTimeString(), $submitted->submitted_at->toDateTimeString());
    }

    // ------------------------------------------------- nilai akademik aman

    /**
     * Keputusan pemilik (R-1): pengumpulan siswa **tidak pernah** membuat baris
     * `grades`. Jembatan "Masukkan ke Nilai" adalah pekerjaan tersendiri, dan
     * sampai jembatan itu ada, CBT tidak menyentuh penilaian akademik sama
     * sekali (butir 320).
     */
    public function test_submitting_creates_no_academic_grade(): void
    {
        [$exam, $questions] = $this->openExamWithQuestions([1.0]);

        $before = Grade::query()->count();

        $this->attempts->startOrResume($this->studentUserA, $exam->getKey());
        $this->answer($exam, $questions[0], correct: true);
        $attempt = $this->attempts->submit($this->studentUserA, $exam->getKey());

        $this->assertSame('100.00', $attempt->score);
        $this->assertSame($before, Grade::query()->count());
        $this->assertSame(0, Grade::query()->count());
        $this->assertNull($attempt->grade_id);
    }

    public function test_submitting_leaves_an_existing_grade_untouched(): void
    {
        $grade = Grade::factory()->create([
            'school_id' => $this->schoolA->getKey(),
            'student_id' => $this->studentA->getKey(),
            'class_subject_id' => $this->classSubjectA->getKey(),
            'academic_year_id' => $this->yearA->getKey(),
            'graded_by' => $this->teacherA->getKey(),
            'score' => 70,
        ]);

        $snapshot = $grade->fresh()->only(['score', 'weight', 'grade_type', 'assessment_type', 'updated_at']);

        [$exam, $questions] = $this->openExamWithQuestions([1.0]);

        $this->attempts->startOrResume($this->studentUserA, $exam->getKey());
        $this->answer($exam, $questions[0], correct: true);
        $this->attempts->submit($this->studentUserA, $exam->getKey());

        $this->assertSame(1, Grade::query()->count());
        $this->assertEquals($snapshot, $grade->fresh()->only(['score', 'weight', 'grade_type', 'assessment_type', 'updated_at']));
    }

    public function test_submitting_touches_no_report_card(): void
    {
        $card = ReportCard::factory()->create([
            'school_id' => $this->schoolA->getKey(),
            'student_id' => $this->studentA->getKey(),
            'class_id' => $this->classA->getKey(),
            'academic_year_id' => $this->yearA->getKey(),
            'is_published' => true,
        ]);

        $snapshot = $card->fresh()->only(['final_scores', 'is_published', 'published_at', 'updated_at']);

        [$exam, $questions] = $this->openExamWithQuestions([1.0]);

        $this->attempts->startOrResume($this->studentUserA, $exam->getKey());
        $this->answer($exam, $questions[0], correct: true);
        $this->attempts->submit($this->studentUserA, $exam->getKey());

        $this->assertEquals($snapshot, $card->fresh()->only(['final_scores', 'is_published', 'published_at', 'updated_at']));
        $this->assertSame(1, ReportCard::query()->count());
    }

    public function test_the_cbt_write_path_never_mentions_the_grade_model(): void
    {
        foreach ([
            app_path('Services/Cbt/ExamAttemptService.php'),
            app_path('Services/Cbt/ExamScoringService.php'),
            app_path('Services/Cbt/StudentExamService.php'),
        ] as $file) {
            $code = collect(token_get_all(file_get_contents($file)))
                ->reject(fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
                ->map(fn ($token) => is_array($token) ? $token[1] : $token)
                ->implode('');

            $this->assertStringNotContainsString('Models\\Grade', $code, basename($file));
            $this->assertStringNotContainsString('GradeConfig', $code, basename($file));
            $this->assertStringNotContainsString('ReportCard', $code, basename($file));
        }
    }

    // ------------------------------------------------------------ penunjang

    /**
     * @param  array<int, ExamQuestion>  $questions
     */
    protected function answerAll(Exam $exam, array $questions, bool $correct): ExamAttempt
    {
        $this->attempts->startOrResume($this->studentUserA, $exam->getKey());

        foreach ($questions as $question) {
            $this->answer($exam, $question, $correct);
        }

        return $this->attempts->submit($this->studentUserA, $exam->getKey());
    }

    protected function answer(Exam $exam, ExamQuestion $question, bool $correct): void
    {
        $option = $correct ? $this->correctOptionOf($question) : $this->wrongOptionOf($question);

        $this->attempts->saveAnswer(
            $this->studentUserA,
            $exam->getKey(),
            $question->getKey(),
            $option->getKey(),
        );
    }
}
