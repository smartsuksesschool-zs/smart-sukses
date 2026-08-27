<?php

namespace Tests\Feature\Cbt;

use App\Enums\AuditAction;
use App\Enums\ExamAttemptStatus;
use App\Models\AuditLog;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamOption;
use App\Services\Cbt\ExamAttemptService;
use App\Services\Cbt\ExamPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Cbt\Concerns\BuildsStudentExamFixture;
use Tests\TestCase;

/**
 * Aturan pengerjaan: memulai, melanjutkan, menyimpan jawaban.
 *
 * Yang dijaga di sini adalah dua janji yang paling mudah dilanggar tanpa
 * disadari — **satu pengerjaan per siswa per ujian**, dan **waktu milik
 * server**. Keduanya tidak menghasilkan error ketika dilanggar; yang terjadi
 * hanya nilai yang salah, pada siswa yang tidak akan pernah tahu.
 */
class StudentExamAttemptTest extends TestCase
{
    use BuildsStudentExamFixture, RefreshDatabase;

    protected ExamAttemptService $attempts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStudentExamFixture();
        $this->attempts = app(ExamAttemptService::class);
    }

    // ------------------------------------------------------------- memulai

    public function test_an_eligible_student_can_start(): void
    {
        $exam = $this->openExamIn($this->classSubjectA);

        $attempt = $this->attempts->startOrResume($this->studentUserA, $exam->getKey());

        $this->assertSame(ExamAttemptStatus::InProgress, $attempt->status);
        $this->assertSame($this->studentA->getKey(), (int) $attempt->student_id);
        $this->assertSame($this->schoolA->getKey(), (int) $attempt->school_id);
        $this->assertSame(1, ExamAttempt::query()->count());
    }

    public function test_starting_twice_resumes_the_same_attempt(): void
    {
        $exam = $this->openExamIn($this->classSubjectA);

        $first = $this->attempts->startOrResume($this->studentUserA, $exam->getKey());
        $second = $this->attempts->startOrResume($this->studentUserA, $exam->getKey());

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, ExamAttempt::query()->count());
        // Waktu mulainya tidak digeser oleh pembukaan kedua — kalau digeser,
        // membuka ulang halaman akan memperpanjang ujian.
        $this->assertEquals($first->expires_at, $second->expires_at);
    }

    /**
     * Butir 271 — pemeriksaan di PHP tidak cukup pada dua request yang datang
     * bersamaan. Yang menutupnya UNIQUE, dan yang kalah melanjutkan pengerjaan
     * pemenangnya alih-alih gagal.
     */
    public function test_a_concurrent_duplicate_start_resumes_instead_of_failing(): void
    {
        $exam = $this->openExamIn($this->classSubjectA);

        $winner = $this->attempts->startOrResume($this->studentUserA, $exam->getKey());

        // Menirukan balapan: baris kedua ditulis langsung, melewati service.
        try {
            ExamAttempt::query()->create([
                'school_id' => $this->schoolA->getKey(),
                'exam_id' => $exam->getKey(),
                'student_id' => $this->studentA->getKey(),
                'status' => ExamAttemptStatus::InProgress->value,
                'started_at' => now(),
                'expires_at' => now()->addHour(),
            ]);
            $this->fail('UNIQUE seharusnya menolak pengerjaan kedua.');
        } catch (QueryException) {
            // sebagaimana mestinya
        }

        $resumed = $this->attempts->startOrResume($this->studentUserA, $exam->getKey());

        $this->assertSame($winner->getKey(), $resumed->getKey());
        $this->assertSame(1, ExamAttempt::query()->count());
    }

    public function test_an_upcoming_exam_cannot_be_started(): void
    {
        $exam = $this->openExamIn($this->classSubjectA, [
            'available_from' => now()->addDay(),
            'available_until' => now()->addDays(2),
        ]);

        $this->assertRefused(
            fn () => $this->attempts->startOrResume($this->studentUserA, $exam->getKey()),
            'Ujian ini belum dibuka.',
        );

        $this->assertSame(0, ExamAttempt::query()->count());
    }

    public function test_an_exam_whose_window_has_passed_cannot_be_started(): void
    {
        $exam = $this->openExamIn($this->classSubjectA, [
            'available_from' => now()->subDays(2),
            'available_until' => now()->subDay(),
        ]);

        $this->assertRefused(
            fn () => $this->attempts->startOrResume($this->studentUserA, $exam->getKey()),
            'Waktu ujian ini sudah berakhir.',
        );

        $this->assertSame(0, ExamAttempt::query()->count());
    }

    public function test_a_closed_exam_cannot_be_started(): void
    {
        $exam = $this->openExamIn($this->classSubjectA);

        app(ExamPublisher::class)->close($exam, $this->teacherA);

        $this->assertRefused(
            fn () => $this->attempts->startOrResume($this->studentUserA, $exam->getKey()),
            'Ujian ini sudah ditutup.',
        );

        $this->assertSame(0, ExamAttempt::query()->count());
    }

    public function test_an_exam_of_another_class_cannot_be_started(): void
    {
        $otherClass = $this->classIn($this->schoolA, $this->yearA);
        $exam = $this->openExamIn($this->classSubjectIn($this->schoolA, $otherClass, $this->teacherA));

        $this->expectException(ModelNotFoundException::class);

        $this->attempts->startOrResume($this->studentUserA, $exam->getKey());
    }

    public function test_an_exam_of_another_school_cannot_be_started(): void
    {
        $exam = $this->openExamIn($this->classSubjectB);

        $this->expectException(ModelNotFoundException::class);

        $this->attempts->startOrResume($this->studentUserA, $exam->getKey());
    }

    public function test_a_submitted_exam_cannot_be_restarted(): void
    {
        $exam = $this->openExamIn($this->classSubjectA);

        $this->attempts->startOrResume($this->studentUserA, $exam->getKey());
        $this->attempts->submit($this->studentUserA, $exam->getKey());

        $this->assertRefused(
            fn () => $this->attempts->startOrResume($this->studentUserA, $exam->getKey()),
            'Ujian ini sudah Anda kumpulkan.',
        );

        $this->assertSame(1, ExamAttempt::query()->count());
    }

    // --------------------------------------------------------- batas waktu

    public function test_expires_at_is_the_start_plus_the_duration(): void
    {
        CarbonImmutable::setTestNow('2026-08-27 08:00:00');

        $exam = $this->openExamIn($this->classSubjectA, [
            'available_from' => now()->subHour(),
            'available_until' => now()->addHours(5),
            'duration_minutes' => 45,
        ]);

        $attempt = $this->attempts->startOrResume($this->studentUserA, $exam->getKey());

        $this->assertSame('2026-08-27 08:45:00', $attempt->expires_at->toDateTimeString());

        CarbonImmutable::setTestNow();
    }

    /**
     * Butir 317 — siswa yang memulai menjelang penutupan hanya mendapat sisa
     * jendelanya, bukan durasi penuh. Tanpa ini, ujian yang ditutup pukul 10.00
     * masih dapat dikerjakan sampai 10.45.
     */
    public function test_expires_at_is_capped_by_the_exam_window(): void
    {
        CarbonImmutable::setTestNow('2026-08-27 09:45:00');

        $exam = $this->openExamIn($this->classSubjectA, [
            'available_from' => CarbonImmutable::parse('2026-08-27 08:00:00'),
            'available_until' => CarbonImmutable::parse('2026-08-27 10:00:00'),
            'duration_minutes' => 60,
        ]);

        $attempt = $this->attempts->startOrResume($this->studentUserA, $exam->getKey());

        $this->assertSame('2026-08-27 10:00:00', $attempt->expires_at->toDateTimeString());

        CarbonImmutable::setTestNow();
    }

    public function test_resuming_works_while_time_remains(): void
    {
        $exam = $this->openExamIn($this->classSubjectA);
        $attempt = $this->attempts->startOrResume($this->studentUserA, $exam->getKey());

        $resumed = $this->attempts->startOrResume($this->studentUserA, $exam->getKey());

        $this->assertFalse($resumed->isFinal());
        $this->assertSame($attempt->getKey(), $resumed->getKey());
        $this->assertGreaterThan(0, $this->attempts->remainingSeconds($resumed));
    }

    public function test_the_remaining_time_comes_from_the_server_clock(): void
    {
        $exam = $this->openExamIn($this->classSubjectA, ['duration_minutes' => 30]);

        $attempt = $this->attempts->startOrResume($this->studentUserA, $exam->getKey());

        $this->assertSame(0, $this->attempts->remainingSeconds(
            $attempt,
            CarbonImmutable::instance($attempt->expires_at)->addMinute(),
        ));

        $this->assertGreaterThan(1700, $this->attempts->remainingSeconds(
            $attempt,
            CarbonImmutable::instance($attempt->started_at),
        ));
    }

    // ------------------------------------------------------ simpan jawaban

    public function test_choosing_an_option_saves_it(): void
    {
        [$exam, $questions] = $this->openExamWithQuestions([1.0]);
        $this->attempts->startOrResume($this->studentUserA, $exam->getKey());

        $option = $this->wrongOptionOf($questions[0]);

        $this->attempts->saveAnswer(
            $this->studentUserA,
            $exam->getKey(),
            $questions[0]->getKey(),
            $option->getKey(),
        );

        $this->assertDatabaseHas('exam_answers', [
            'exam_question_id' => $questions[0]->getKey(),
            'exam_option_id' => $option->getKey(),
        ]);
        $this->assertSame(1, ExamAnswer::query()->count());
    }

    public function test_changing_the_answer_updates_the_same_row(): void
    {
        [$exam, $questions] = $this->openExamWithQuestions([1.0]);
        $this->attempts->startOrResume($this->studentUserA, $exam->getKey());

        foreach ([$this->wrongOptionOf($questions[0]), $this->correctOptionOf($questions[0])] as $option) {
            $this->attempts->saveAnswer(
                $this->studentUserA,
                $exam->getKey(),
                $questions[0]->getKey(),
                $option->getKey(),
            );
        }

        $this->assertSame(1, ExamAnswer::query()->count());
        $this->assertSame(
            $this->correctOptionOf($questions[0])->getKey(),
            (int) ExamAnswer::query()->first()->exam_option_id,
        );
    }

    public function test_saving_the_same_answer_twice_creates_no_duplicate(): void
    {
        [$exam, $questions] = $this->openExamWithQuestions([1.0]);
        $this->attempts->startOrResume($this->studentUserA, $exam->getKey());

        $option = $this->correctOptionOf($questions[0]);

        for ($i = 0; $i < 3; $i++) {
            $this->attempts->saveAnswer(
                $this->studentUserA,
                $exam->getKey(),
                $questions[0]->getKey(),
                $option->getKey(),
            );
        }

        $this->assertSame(1, ExamAnswer::query()->count());
    }

    public function test_a_question_from_another_exam_is_refused(): void
    {
        [$exam] = $this->openExamWithQuestions([1.0]);
        [, $strayQuestions] = $this->openExamWithQuestions([1.0]);

        $this->attempts->startOrResume($this->studentUserA, $exam->getKey());

        $this->assertRefused(
            fn () => $this->attempts->saveAnswer(
                $this->studentUserA,
                $exam->getKey(),
                $strayQuestions[0]->getKey(),
                $this->correctOptionOf($strayQuestions[0])->getKey(),
            ),
            'Soal ini bukan bagian dari ujian yang sedang Anda kerjakan.',
        );

        $this->assertSame(0, ExamAnswer::query()->count());
    }

    /**
     * Bentuk kecurangan yang paling langsung: menukar id pilihan dengan milik
     * soal lain yang kebetulan bertanda benar.
     */
    public function test_an_option_belonging_to_another_question_is_refused(): void
    {
        [$exam, $questions] = $this->openExamWithQuestions([1.0, 1.0]);

        $this->attempts->startOrResume($this->studentUserA, $exam->getKey());

        $this->assertRefused(
            fn () => $this->attempts->saveAnswer(
                $this->studentUserA,
                $exam->getKey(),
                $questions[0]->getKey(),
                $this->correctOptionOf($questions[1])->getKey(),
            ),
            'Pilihan jawaban ini bukan milik soal tersebut.',
        );

        $this->assertSame(0, ExamAnswer::query()->count());
    }

    public function test_an_option_from_another_school_is_refused(): void
    {
        [$exam, $questions] = $this->openExamWithQuestions([1.0]);
        [, $foreignQuestions] = $this->openExamWithQuestions([1.0], $this->classSubjectB);

        $this->attempts->startOrResume($this->studentUserA, $exam->getKey());

        $this->assertRefused(
            fn () => $this->attempts->saveAnswer(
                $this->studentUserA,
                $exam->getKey(),
                $questions[0]->getKey(),
                $this->correctOptionOf($foreignQuestions[0])->getKey(),
            ),
            'Pilihan jawaban ini bukan milik soal tersebut.',
        );
    }

    public function test_an_answer_after_submission_is_refused(): void
    {
        [$exam, $questions] = $this->openExamWithQuestions([1.0]);

        $this->attempts->startOrResume($this->studentUserA, $exam->getKey());
        $this->attempts->submit($this->studentUserA, $exam->getKey());

        $this->assertRefused(
            fn () => $this->attempts->saveAnswer(
                $this->studentUserA,
                $exam->getKey(),
                $questions[0]->getKey(),
                $this->correctOptionOf($questions[0])->getKey(),
            ),
            'Ujian ini sudah dikumpulkan, jawabannya tidak dapat diubah lagi.',
        );

        $this->assertNull(ExamAnswer::query()->first()->exam_option_id);
    }

    public function test_an_answer_after_expiry_is_refused_and_the_attempt_closes(): void
    {
        [$exam, $questions] = $this->openExamWithQuestions([1.0]);

        $attempt = $this->attempts->startOrResume($this->studentUserA, $exam->getKey());

        // Batas waktunya dimundurkan langsung di database — menirukan siswa yang
        // membiarkan tab terbuka melewati waktunya.
        $attempt->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->assertRefused(
            fn () => $this->attempts->saveAnswer(
                $this->studentUserA,
                $exam->getKey(),
                $questions[0]->getKey(),
                $this->correctOptionOf($questions[0])->getKey(),
            ),
            'Ujian ini sudah dikumpulkan, jawabannya tidak dapat diubah lagi.',
        );

        $this->assertSame(ExamAttemptStatus::Submitted, $attempt->fresh()->status);
    }

    /**
     * `is_correct`, `points_earned`, dan `score` bukan parameter jalur
     * penyimpanan jawaban sama sekali — tidak ada bentuk request apa pun yang
     * dapat menyentuhnya (butir 311).
     */
    public function test_the_answer_path_accepts_no_scoring_field_at_all(): void
    {
        $method = new \ReflectionMethod(ExamAttemptService::class, 'saveAnswer');

        $parameters = array_map(fn ($p) => $p->getName(), $method->getParameters());

        $this->assertSame(['user', 'examId', 'questionId', 'optionId', 'now'], $parameters);

        foreach (['isCorrect', 'is_correct', 'points', 'pointsEarned', 'score'] as $forbidden) {
            $this->assertNotContains($forbidden, $parameters);
        }
    }

    public function test_a_saved_answer_carries_no_score_until_submission(): void
    {
        [$exam, $questions] = $this->openExamWithQuestions([1.0]);

        $this->attempts->startOrResume($this->studentUserA, $exam->getKey());
        $this->attempts->saveAnswer(
            $this->studentUserA,
            $exam->getKey(),
            $questions[0]->getKey(),
            $this->correctOptionOf($questions[0])->getKey(),
        );

        $answer = ExamAnswer::query()->firstOrFail();

        $this->assertNull($answer->is_correct);
        $this->assertNull($answer->points_earned);
        $this->assertNull(ExamAttempt::query()->first()->score);
    }

    public function test_saved_answers_are_restored_on_resume(): void
    {
        [$exam, $questions] = $this->openExamWithQuestions([1.0, 1.0]);

        $attempt = $this->attempts->startOrResume($this->studentUserA, $exam->getKey());

        $chosen = $this->wrongOptionOf($questions[0]);
        $this->attempts->saveAnswer(
            $this->studentUserA,
            $exam->getKey(),
            $questions[0]->getKey(),
            $chosen->getKey(),
        );

        $this->assertSame(
            [$questions[0]->getKey() => $chosen->getKey()],
            $this->attempts->savedAnswers($attempt),
        );
    }

    // ---------------------------------------------------------------- audit

    /**
     * Jejak audit CUD yang sudah ada merekam pengerjaan dan jawabannya tanpa
     * kode tambahan, karena seluruh penyimpanan berjalan lewat model
     * (butir 299). Aktornya akun siswa yang bersangkutan — tidak ada aktor
     * palsu yang dikarang (butir 250).
     */
    public function test_starting_answering_and_submitting_leave_an_audit_trail(): void
    {
        [$exam, $questions] = $this->openExamWithQuestions([1.0]);

        $this->actingAs($this->studentUserA);

        $attempt = $this->attempts->startOrResume($this->studentUserA, $exam->getKey());

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => ExamAttempt::class,
            'auditable_id' => $attempt->getKey(),
            'action' => AuditAction::Created->value,
            'school_id' => $this->schoolA->getKey(),
            'user_id' => $this->studentUserA->getKey(),
        ]);

        $this->attempts->saveAnswer(
            $this->studentUserA,
            $exam->getKey(),
            $questions[0]->getKey(),
            $this->wrongOptionOf($questions[0])->getKey(),
        );

        $answer = ExamAnswer::query()->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => ExamAnswer::class,
            'auditable_id' => $answer->getKey(),
            'action' => AuditAction::Created->value,
            'user_id' => $this->studentUserA->getKey(),
        ]);

        // Mengganti pilihan tercatat sebagai perubahan pada baris yang sama.
        $this->attempts->saveAnswer(
            $this->studentUserA,
            $exam->getKey(),
            $questions[0]->getKey(),
            $this->correctOptionOf($questions[0])->getKey(),
        );

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => ExamAnswer::class,
            'auditable_id' => $answer->getKey(),
            'action' => AuditAction::Updated->value,
        ]);

        $this->attempts->submit($this->studentUserA, $exam->getKey());

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => ExamAttempt::class,
            'auditable_id' => $attempt->getKey(),
            'action' => AuditAction::Updated->value,
        ]);
    }

    /**
     * Jejak audit di project ini menyimpan **metadata saja** — tabel, id, aksi,
     * cabang, pengguna, alamat IP. Tidak ada kolom yang dapat menampung isi,
     * sehingga kunci jawaban maupun jawaban siswa tidak mungkin ikut tercatat
     * di sana (butir 321).
     */
    public function test_the_audit_trail_stores_no_answer_content(): void
    {
        [$exam, $questions] = $this->openExamWithQuestions([1.0]);

        $this->actingAs($this->studentUserA);
        $this->attempts->startOrResume($this->studentUserA, $exam->getKey());
        $this->attempts->saveAnswer(
            $this->studentUserA,
            $exam->getKey(),
            $questions[0]->getKey(),
            $this->correctOptionOf($questions[0])->getKey(),
        );

        $columns = Schema::getColumnListing('audit_logs');
        sort($columns);

        $this->assertSame(
            ['action', 'auditable_id', 'auditable_type', 'created_at', 'id', 'ip_address', 'school_id', 'user_id'],
            $columns,
        );

        foreach (AuditLog::query()->get() as $log) {
            $this->assertStringNotContainsString('Kunci soal', json_encode($log->toArray()));
        }
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

    protected function optionsOf(int $questionId): array
    {
        return ExamOption::query()->where('exam_question_id', $questionId)->pluck('id')->all();
    }
}
