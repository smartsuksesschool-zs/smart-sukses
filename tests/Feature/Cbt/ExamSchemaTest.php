<?php

namespace Tests\Feature\Cbt;

use App\Enums\ExamAttemptStatus;
use App\Enums\RoleName;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamOption;
use App\Models\ExamQuestion;
use App\Models\Grade;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Cbt\Concerns\BuildsExamFixture;
use Tests\TestCase;

/**
 * Jaminan yang diletakkan di database, bukan di aplikasi.
 *
 * Suite ini menguji apa yang tetap benar walaupun seluruh kode di atasnya
 * keliru: satu percobaan per ujian per siswa, satu jawaban per soal, rentang
 * nilai yang muat, dan perilaku hapus yang tidak menghancurkan pekerjaan siswa
 * karena sebuah akun dihapus.
 *
 * Seluruh test di sini berjalan pada kedua mesin. Itu bukan pengulangan:
 * SQLite dan MySQL menegakkan UNIQUE dan ON DELETE lewat jalan yang berbeda,
 * dan yang lolos di satu mesin belum tentu lolos di mesin yang lain — sedangkan
 * yang berjalan di produksi hanya salah satunya (butir 281).
 */
class ExamSchemaTest extends TestCase
{
    use BuildsExamFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildExamFixture();
    }

    // ------------------------------------------------ satu percobaan per ujian

    /**
     * Butir 271 — aturan "satu percobaan" berada di database supaya dua request
     * yang datang bersamaan tidak dapat menghasilkan dua percobaan.
     */
    public function test_a_student_can_only_have_one_attempt_per_exam(): void
    {
        $exam = $this->examIn($this->classSubjectA);

        ExamAttempt::factory()->create([
            'exam_id' => $exam->getKey(),
            'school_id' => $this->schoolA->getKey(),
            'student_id' => $this->studentA->getKey(),
        ]);

        $this->expectException(QueryException::class);

        ExamAttempt::factory()->create([
            'exam_id' => $exam->getKey(),
            'school_id' => $this->schoolA->getKey(),
            'student_id' => $this->studentA->getKey(),
        ]);
    }

    public function test_the_same_student_may_attempt_different_exams(): void
    {
        $first = $this->examIn($this->classSubjectA);
        $second = $this->examIn($this->classSubjectA);

        foreach ([$first, $second] as $exam) {
            ExamAttempt::factory()->create([
                'exam_id' => $exam->getKey(),
                'school_id' => $this->schoolA->getKey(),
                'student_id' => $this->studentA->getKey(),
            ]);
        }

        $this->assertSame(
            2,
            ExamAttempt::query()->forStudent($this->studentA->getKey())->count(),
        );
    }

    public function test_two_students_may_attempt_the_same_exam(): void
    {
        $exam = $this->examIn($this->classSubjectA);
        $peer = $this->studentIn($this->schoolA);

        foreach ([$this->studentA, $peer] as $student) {
            ExamAttempt::factory()->create([
                'exam_id' => $exam->getKey(),
                'school_id' => $this->schoolA->getKey(),
                'student_id' => $student->getKey(),
            ]);
        }

        $this->assertSame(2, ExamAttempt::query()->where('exam_id', $exam->getKey())->count());
    }

    // -------------------------------------------------- satu jawaban per soal

    /**
     * Butir 273 — UNIQUE ini yang membuat penyimpanan otomatis saat siswa
     * berpindah soal dapat berupa satu upsert, bukan baca-lalu-tulis.
     */
    public function test_one_answer_per_question_per_attempt_is_enforced(): void
    {
        [$attempt, $question] = $this->attemptWithQuestion();

        ExamAnswer::factory()->create([
            'exam_attempt_id' => $attempt->getKey(),
            'exam_question_id' => $question->getKey(),
            'school_id' => $this->schoolA->getKey(),
        ]);

        $this->expectException(QueryException::class);

        ExamAnswer::factory()->create([
            'exam_attempt_id' => $attempt->getKey(),
            'exam_question_id' => $question->getKey(),
            'school_id' => $this->schoolA->getKey(),
        ]);
    }

    public function test_one_attempt_may_answer_many_questions(): void
    {
        [$attempt, $first] = $this->attemptWithQuestion();

        $second = ExamQuestion::factory()->create([
            'exam_id' => $attempt->exam_id,
            'school_id' => $this->schoolA->getKey(),
            'position' => 2,
        ]);

        foreach ([$first, $second] as $question) {
            ExamAnswer::factory()->create([
                'exam_attempt_id' => $attempt->getKey(),
                'exam_question_id' => $question->getKey(),
                'school_id' => $this->schoolA->getKey(),
            ]);
        }

        $this->assertSame(2, $attempt->answers()->count());
    }

    // ------------------------------------------------------------ rentang nilai

    /**
     * Kedua ujung skala harus muat apa adanya — nilai sempurna dan nilai nol
     * sama-sama hasil yang wajar, dan keduanya pernah menjadi tempat pembulatan
     * diam-diam menggeser angka.
     */
    public function test_the_score_column_stores_the_whole_zero_to_one_hundred_range(): void
    {
        $exam = $this->examIn($this->classSubjectA);

        foreach ([0.00, 55.55, 99.99, 100.00] as $index => $score) {
            $attempt = ExamAttempt::factory()->submitted($score)->create([
                'exam_id' => $exam->getKey(),
                'school_id' => $this->schoolA->getKey(),
                'student_id' => $this->studentIn($this->schoolA)->getKey(),
            ]);

            $this->assertSame(
                number_format($score, 2, '.', ''),
                $attempt->fresh()->score,
                "Nilai {$score} tidak tersimpan utuh (baris ke-{$index}).",
            );
        }
    }

    /**
     * Butir 272 — jembatan "Masukkan ke Nilai" nanti tidak boleh mengubah skala
     * apa pun. Syaratnya kedua skala memang sama, dan itu dikunci di sini
     * supaya salah satunya tidak dapat bergeser sendirian.
     */
    public function test_the_attempt_score_scale_matches_the_academic_grade_scale(): void
    {
        $this->assertSame(Grade::MIN_SCORE, ExamAttempt::MIN_SCORE);
        $this->assertSame(Grade::MAX_SCORE, ExamAttempt::MAX_SCORE);
    }

    public function test_question_points_and_earned_points_share_the_score_scale(): void
    {
        [$attempt, $question] = $this->attemptWithQuestion();

        $question->forceFill(['points' => 12.50])->save();

        $answer = ExamAnswer::factory()->scored(true, 12.50)->create([
            'exam_attempt_id' => $attempt->getKey(),
            'exam_question_id' => $question->getKey(),
            'school_id' => $this->schoolA->getKey(),
        ]);

        $this->assertSame('12.50', $question->fresh()->points);
        $this->assertSame('12.50', $answer->fresh()->points_earned);
    }

    // ------------------------------------------------------- perilaku hapus

    /**
     * Butir 272 — jembatan "Masukkan ke Nilai" belum ada, tetapi kolomnya sudah
     * harus berperilaku benar: menghapus baris nilai tidak boleh ikut menghapus
     * hasil ujiannya.
     */
    public function test_deleting_a_linked_grade_leaves_the_attempt_intact(): void
    {
        $grade = Grade::factory()->create([
            'school_id' => $this->schoolA->getKey(),
            'student_id' => $this->studentA->getKey(),
            'class_subject_id' => $this->classSubjectA->getKey(),
            'academic_year_id' => $this->yearA->getKey(),
            'graded_by' => $this->teacherA->getKey(),
        ]);

        $attempt = ExamAttempt::factory()->submitted(90.00)->create([
            'exam_id' => $this->examIn($this->classSubjectA)->getKey(),
            'school_id' => $this->schoolA->getKey(),
            'student_id' => $this->studentA->getKey(),
            'grade_id' => $grade->getKey(),
        ]);

        $grade->delete();

        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->getKey()]);

        $attempt->refresh();

        $this->assertNull($attempt->grade_id);
        $this->assertSame('90.00', $attempt->score);
    }

    /**
     * Butir 269 — akun guru yang dihapus tidak boleh ikut menghapus ujiannya,
     * karena di dalam ujian itu ada pekerjaan siswa. Pola yang sama dengan
     * `report_cards.published_by`, bukan `grades.graded_by`.
     */
    public function test_deleting_the_creator_account_keeps_the_exam_and_its_attempts(): void
    {
        $author = $this->userIn($this->schoolA, RoleName::Guru);

        $exam = $this->examIn($this->classSubjectA, ['created_by' => $author->getKey()]);

        $attempt = ExamAttempt::factory()->submitted(75.00)->create([
            'exam_id' => $exam->getKey(),
            'school_id' => $this->schoolA->getKey(),
            'student_id' => $this->studentA->getKey(),
        ]);

        $author->delete();

        $this->assertDatabaseHas('exams', ['id' => $exam->getKey(), 'created_by' => null]);
        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->getKey(), 'score' => 75.00]);
    }

    /**
     * Menghapus ujiannya sendiri memang menghapus seluruh isinya. Itu bukan
     * kelalaian melainkan pilihan yang sama dengan `grades.class_subject_id` di
     * project ini — dan karena itu diuji, bukan diasumsikan.
     */
    public function test_deleting_an_exam_removes_its_questions_attempts_and_answers(): void
    {
        [$attempt, $question] = $this->attemptWithQuestion();

        $answer = ExamAnswer::factory()->create([
            'exam_attempt_id' => $attempt->getKey(),
            'exam_question_id' => $question->getKey(),
            'school_id' => $this->schoolA->getKey(),
        ]);

        Exam::query()->whereKey($attempt->exam_id)->first()->delete();

        $this->assertDatabaseMissing('exam_questions', ['id' => $question->getKey()]);
        $this->assertDatabaseMissing('exam_attempts', ['id' => $attempt->getKey()]);
        $this->assertDatabaseMissing('exam_answers', ['id' => $answer->getKey()]);
    }

    // ------------------------------------------- skema lama tidak tersentuh

    /**
     * Batch ini tidak boleh mengubah satu kolom pun pada penilaian yang sudah
     * berjalan. Daftar kolomnya ditulis penuh: perubahan apa pun — tambah
     * maupun hilang — akan menjatuhkan test ini, dan itu memang tujuannya.
     */
    public function test_the_grade_schema_is_unchanged(): void
    {
        $columns = Schema::getColumnListing('grades');
        sort($columns);

        $expected = [
            'academic_year_id', 'assessment_type', 'class_subject_id', 'created_at',
            'description', 'grade_config_id', 'grade_type', 'graded_at', 'graded_by',
            'id', 'school_id', 'score', 'student_id', 'updated_at', 'weight',
        ];

        $this->assertSame($expected, $columns);
    }

    public function test_the_report_card_schema_is_unchanged(): void
    {
        $columns = Schema::getColumnListing('report_cards');
        sort($columns);

        $expected = [
            'academic_year_id', 'attend_absent', 'attend_permission', 'attend_present',
            'attend_sick', 'attitude_score', 'class_id', 'created_at', 'final_scores',
            'homeroom_notes', 'id', 'is_published', 'pdf_generated_at', 'pdf_path',
            'pdf_status', 'published_at', 'published_by', 'rank_in_class', 'school_id',
            'student_id', 'updated_at',
        ];

        $this->assertSame($expected, $columns);
    }

    /**
     * Arah ketergantungannya satu arah saja: CBT tahu tentang `grades`, tetapi
     * `grades` tidak tahu apa pun tentang CBT. Itulah yang membuat batch ini
     * tidak dapat menyentuh perhitungan rapor.
     */
    public function test_the_grade_table_carries_no_reference_back_to_cbt(): void
    {
        foreach (Schema::getColumnListing('grades') as $column) {
            $this->assertStringNotContainsString('exam', $column);
        }
    }

    public function test_all_five_cbt_tables_exist(): void
    {
        foreach (['exams', 'exam_questions', 'exam_options', 'exam_attempts', 'exam_answers'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Tabel {$table} belum ada.");
            $this->assertTrue(Schema::hasColumn($table, 'school_id'), "Tabel {$table} tidak punya school_id.");
        }
    }

    /**
     * @return array{0: ExamAttempt, 1: ExamQuestion}
     */
    protected function attemptWithQuestion(): array
    {
        $exam = $this->examIn($this->classSubjectA);

        $attempt = ExamAttempt::factory()->create([
            'exam_id' => $exam->getKey(),
            'school_id' => $this->schoolA->getKey(),
            'student_id' => $this->studentA->getKey(),
            'status' => ExamAttemptStatus::InProgress->value,
        ]);

        $question = ExamQuestion::factory()->create([
            'exam_id' => $exam->getKey(),
            'school_id' => $this->schoolA->getKey(),
        ]);

        ExamOption::factory()->correct()->create([
            'exam_question_id' => $question->getKey(),
            'school_id' => $this->schoolA->getKey(),
        ]);

        return [$attempt, $question];
    }
}
