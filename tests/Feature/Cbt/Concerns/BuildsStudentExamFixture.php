<?php

namespace Tests\Feature\Cbt\Concerns;

use App\Enums\RoleName;
use App\Enums\StudentClassStatus;
use App\Models\ClassSubject;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamOption;
use App\Models\ExamQuestion;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Services\Cbt\ExamPublisher;
use Carbon\CarbonInterface;

/**
 * Fiksur alur siswa: dua cabang utuh (dari BuildsExamFixture), ditambah akun
 * portal siswa yang tertaut dan penempatan kelasnya.
 *
 * Yang membedakan alur siswa dari alur guru adalah **identitas**: seluruh
 * pemeriksaan bergantung pada `students.user_id` dan penempatan kelas aktif,
 * bukan pada peran saja. Fiksur yang melewatkan salah satunya akan membuat
 * setiap test lulus karena alasan yang keliru (butir 319).
 */
trait BuildsStudentExamFixture
{
    use BuildsExamFixture;

    protected User $studentUserA;

    protected User $studentUserB;

    protected function buildStudentExamFixture(): void
    {
        $this->buildExamFixture();

        $this->studentUserA = $this->linkStudent($this->studentA, $this->classA);
        $this->studentUserB = $this->linkStudent($this->studentB, $this->classB);
    }

    /**
     * Menautkan satu data siswa ke akun portalnya, sekaligus menempatkannya di
     * sebuah kelas pada tahun ajaran kelas itu.
     */
    protected function linkStudent(Student $student, SchoolClass $class): User
    {
        $user = User::factory()
            ->forSchool($student->school)
            ->withRole(RoleName::Siswa)
            ->create();

        $student->forceFill(['user_id' => $user->getKey()])->save();

        StudentClass::factory()->create([
            'school_id' => $student->school_id,
            'student_id' => $student->getKey(),
            'class_id' => $class->getKey(),
            'academic_year_id' => $class->academic_year_id,
            'status' => StudentClassStatus::Active->value,
        ]);

        return $user;
    }

    /**
     * Ujian siap terbit: satu soal pilihan ganda dengan dua pilihan dan satu
     * kunci. Dipakai bila isinya tidak sedang diuji.
     */
    protected function readyExamIn(ClassSubject $classSubject, array $overrides = []): Exam
    {
        $exam = $this->examIn($classSubject, $overrides);

        $question = $this->questionOn($exam);
        $this->optionOn($question, 'Benar', correct: true);
        $this->optionOn($question, 'Salah');

        return $exam->fresh();
    }

    /**
     * Ujian yang sudah terbit dan sedang dalam rentang waktunya.
     */
    protected function openExamIn(ClassSubject $classSubject, array $overrides = []): Exam
    {
        $exam = $this->readyExamIn($classSubject, $overrides + [
            'available_from' => now()->subHour(),
            'available_until' => now()->addHours(3),
            'duration_minutes' => 60,
        ]);

        // Diterbitkan oleh guru pengampu kelas-mapel itu sendiri, bukan selalu
        // guru cabang A: ujian cabang seberang harus dibangun dengan aktor
        // cabang seberang, kalau tidak yang diuji justru kewenangan gurunya.
        return app(ExamPublisher::class)->publish($exam, $this->teacherFor($classSubject));
    }

    /**
     * Ujian terbit dengan sejumlah soal berbobot, masing-masing satu kunci.
     *
     * @param  array<int, float>  $points  bobot tiap soal, berurutan
     * @return array{0: Exam, 1: array<int, ExamQuestion>}
     */
    protected function openExamWithQuestions(array $points, ?ClassSubject $classSubject = null): array
    {
        $exam = $this->examIn($classSubject ?? $this->classSubjectA, [
            'available_from' => now()->subHour(),
            'available_until' => now()->addHours(3),
            'duration_minutes' => 60,
        ]);

        $questions = [];

        foreach (array_values($points) as $index => $weight) {
            $question = $this->questionOn($exam, $weight, $index + 1);
            $this->optionOn($question, 'Kunci soal '.($index + 1), correct: true);
            $this->optionOn($question, 'Pengecoh soal '.($index + 1));
            $questions[] = $question->fresh();
        }

        $published = app(ExamPublisher::class)->publish(
            $exam->fresh(),
            $classSubject === null ? $this->teacherA : $this->teacherFor($classSubject),
        );

        return [$published, $questions];
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
            'position' => $question->options()->count() + 1,
        ]);
    }

    /**
     * Kunci jawaban satu soal — dipakai test penilaian, tidak pernah oleh alur
     * siswa.
     */
    protected function correctOptionOf(ExamQuestion $question): ExamOption
    {
        return $question->options()->where('is_correct', true)->firstOrFail();
    }

    protected function wrongOptionOf(ExamQuestion $question): ExamOption
    {
        return $question->options()->where('is_correct', false)->firstOrFail();
    }

    protected function attemptFor(Student $student, Exam $exam, ?CarbonInterface $expiresAt = null): ExamAttempt
    {
        return ExamAttempt::factory()->create([
            'exam_id' => $exam->getKey(),
            'school_id' => $exam->school_id,
            'student_id' => $student->getKey(),
            'started_at' => now(),
            'expires_at' => $expiresAt ?? now()->addHour(),
        ]);
    }

    protected function teacherFor(ClassSubject $classSubject): User
    {
        return User::query()->findOrFail($classSubject->teacher_id);
    }
}
