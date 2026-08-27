<?php

namespace Tests\Feature\Cbt;

use App\Enums\RoleName;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamOption;
use App\Models\ExamQuestion;
use App\Models\Scopes\SchoolScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Feature\Cbt\Concerns\BuildsExamFixture;
use Tests\TestCase;

/**
 * Arsitektur 3.2 — isolasi data, diberlakukan pada kelima tabel CBT.
 *
 * Yang diuji bukan bahwa `BelongsToSchool` bekerja — itu sudah terbukti pada
 * modul lain — melainkan bahwa **tidak satu pun** dari kelima model baru ini
 * lupa memakainya. Satu model yang terlewat sudah cukup: soal, kunci jawaban,
 * dan hasil ujian satu cabang akan terbaca cabang lain, dan tidak ada yang
 * memberi tahu (butir 282).
 *
 * Karena itu daftarnya ditulis sebagai data, bukan sebagai lima test yang
 * ditulis tangan: model CBT keenam yang lupa didaftarkan di sini akan terlihat
 * dari jumlahnya.
 */
class ExamTenantIsolationTest extends TestCase
{
    use BuildsExamFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildExamFixture();
        $this->buildExamTreeInBothBranches();
    }

    /**
     * Kelima model, dan hanya kelima ini, yang membentuk CBT.
     *
     * @return array<int, class-string<Model>>
     */
    protected function cbtModels(): array
    {
        return [Exam::class, ExamQuestion::class, ExamOption::class, ExamAttempt::class, ExamAnswer::class];
    }

    public function test_every_cbt_model_scopes_to_the_school_of_the_logged_in_user(): void
    {
        $adminA = $this->userIn($this->schoolA, RoleName::SchoolAdmin);

        $this->actingAs($adminA);

        foreach ($this->cbtModels() as $model) {
            $rows = $model::query()->get();

            $this->assertCount(1, $rows, "{$model} tidak menyaring per cabang.");
            $this->assertSame(
                $this->schoolA->getKey(),
                (int) $rows->first()->school_id,
                "{$model} mengembalikan baris cabang lain.",
            );
        }
    }

    /**
     * Sisi sebaliknya: baris cabang seberang tidak dapat diambil walaupun
     * id-nya diketahui persis. Ini bentuk serangan yang sesungguhnya —
     * penyerang tidak menebak daftar, ia menebak angka.
     */
    public function test_a_school_cannot_reach_the_cbt_rows_of_another_school_by_id(): void
    {
        $adminA = $this->userIn($this->schoolA, RoleName::SchoolAdmin);

        $this->actingAs($adminA);

        foreach ($this->cbtModels() as $model) {
            $foreign = $model::query()
                ->withoutGlobalScope(SchoolScope::class)
                ->where('school_id', $this->schoolB->getKey())
                ->firstOrFail();

            $this->assertNull(
                $model::query()->find($foreign->getKey()),
                "{$model} dapat diambil lintas cabang lewat id.",
            );
        }
    }

    /**
     * Butir 127 — akun School Level tanpa cabang tidak memiliki satu baris pun.
     * Keadaan ini pernah jatuh ke sisi yang salah dan justru membaca seluruh
     * cabang; CBT tidak boleh mengulanginya.
     */
    public function test_a_branchless_school_level_account_sees_no_cbt_rows_at_all(): void
    {
        $orphan = User::factory()->withRole(RoleName::SchoolAdmin)->create(['school_id' => null]);

        $this->actingAs($orphan);

        foreach ($this->cbtModels() as $model) {
            $this->assertSame(0, $model::query()->count(), "{$model} bocor ke akun tanpa cabang.");
        }
    }

    /**
     * Arsitektur 3.2.2 — Super Admin memang lintas cabang. Yang diuji di sini
     * adalah bahwa CBT tidak mengubah perilaku itu ke arah mana pun.
     */
    public function test_super_admin_still_reads_every_branch(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        foreach ($this->cbtModels() as $model) {
            $this->assertSame(2, $model::query()->count(), "{$model} menyembunyikan cabang dari Super Admin.");
        }
    }

    /**
     * Tanpa sesi, scope memang tidak memasang batasan — itu jalur seeder,
     * perintah artisan, dan worker antrean (lihat SchoolScope). Diuji supaya
     * perilakunya disadari, bukan ditemukan.
     */
    public function test_without_a_session_the_scope_imposes_nothing(): void
    {
        Auth::logout();

        foreach ($this->cbtModels() as $model) {
            $this->assertSame(2, $model::query()->count());
        }
    }

    /**
     * `school_id` yang ditulis eksplisit tidak pernah ditimpa — trait hanya
     * mengisi yang masih NULL. Tanpa sifat ini, test lintas cabang di suite
     * integritas akan diam-diam diperbaiki dan berhenti menguji apa pun.
     */
    public function test_an_explicit_school_id_is_never_overwritten_by_the_session(): void
    {
        $this->actingAs($this->userIn($this->schoolA, RoleName::SchoolAdmin));

        $exam = Exam::factory()->create([
            'class_subject_id' => $this->classSubjectB->getKey(),
            'school_id' => $this->schoolB->getKey(),
            'academic_year_id' => $this->yearB->getKey(),
            'created_by' => $this->teacherB->getKey(),
        ]);

        $this->assertSame($this->schoolB->getKey(), (int) $exam->school_id);
    }

    /**
     * Satu pohon CBT lengkap di masing-masing cabang: ujian, soal, pilihan,
     * pengerjaan, jawaban.
     */
    protected function buildExamTreeInBothBranches(): void
    {
        foreach ([
            [$this->schoolA, $this->classSubjectA, $this->studentA],
            [$this->schoolB, $this->classSubjectB, $this->studentB],
        ] as [$school, $classSubject, $student]) {
            $exam = $this->examIn($classSubject);

            $question = ExamQuestion::factory()->create([
                'exam_id' => $exam->getKey(),
                'school_id' => $school->getKey(),
            ]);

            ExamOption::factory()->correct()->create([
                'exam_question_id' => $question->getKey(),
                'school_id' => $school->getKey(),
            ]);

            $attempt = ExamAttempt::factory()->create([
                'exam_id' => $exam->getKey(),
                'school_id' => $school->getKey(),
                'student_id' => $student->getKey(),
            ]);

            ExamAnswer::factory()->create([
                'exam_attempt_id' => $attempt->getKey(),
                'exam_question_id' => $question->getKey(),
                'school_id' => $school->getKey(),
            ]);
        }
    }
}
