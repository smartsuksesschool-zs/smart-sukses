<?php

namespace Tests\Feature\Cbt;

use App\Enums\RoleName;
use App\Models\ClassSubject;
use App\Models\Exam;
use App\Models\Scopes\SchoolScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Cbt\Concerns\BuildsExamFixture;
use Tests\TestCase;

/**
 * Kewenangan atas ujian online, diuji peran demi peran.
 *
 * CBT tidak punya barisnya sendiri di matriks PRD 1.1.2 — fitur ini dipercepat
 * dari Phase 2 — sehingga yang dipakai adalah izin modul "Input Nilai". Test
 * ini yang menjadikan pemetaan itu keputusan yang terlihat: bila suatu hari
 * matriks izin bergeser, yang jatuh adalah baris peran yang persis bergeser,
 * bukan sesuatu yang samar di tempat lain (butir 277).
 *
 * Dua hal yang paling mudah salah dan karena itu diuji tersendiri:
 *  - Kepala Sekolah **melihat** tetapi tidak mengelola (⭕, bukan ✅);
 *  - Wali Kelas berwenang karena **mengajar**, bukan karena menjadi wali.
 */
class ExamPolicyTest extends TestCase
{
    use BuildsExamFixture, RefreshDatabase;

    protected Exam $examA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildExamFixture();

        $this->examA = $this->examIn($this->classSubjectA);
    }

    /**
     * @return array<string, array{0: RoleName, 1: bool, 2: bool}>
     */
    public static function roleMatrix(): array
    {
        // [peran, boleh melihat, boleh mengelola kelas yang diampunya]
        return [
            'Admin Sekolah' => [RoleName::SchoolAdmin, true, true],
            'Kepala Sekolah' => [RoleName::KepalaSekolah, true, false],
            'Guru' => [RoleName::Guru, true, true],
            'Wali Kelas' => [RoleName::WaliKelas, true, true],
            'Bendahara' => [RoleName::Bendahara, false, false],
            'Orang Tua' => [RoleName::OrangTua, false, false],
            'Siswa' => [RoleName::Siswa, false, false],
        ];
    }

    #[DataProvider('roleMatrix')]
    public function test_the_role_matrix_holds(RoleName $role, bool $mayView, bool $mayManage): void
    {
        $user = $this->userIn($this->schoolA, $role);

        // Guru dan Wali Kelas hanya berwenang atas kelas yang mereka ampu, jadi
        // penugasan itulah yang diberikan — bukan kelas orang lain.
        $exam = $mayManage && ! $user->isSchoolAdmin()
            ? $this->examIn($this->classSubjectIn($this->schoolA, $this->classA, $user))
            : $this->examA;

        $this->assertSame($mayView, $user->can('viewAny', Exam::class), 'viewAny');
        $this->assertSame($mayView, $user->can('view', $exam), 'view');
        $this->assertSame($mayManage, $user->can('create', Exam::class), 'create');
        $this->assertSame($mayManage, $user->can('update', $exam), 'update');
        $this->assertSame($mayManage, $user->can('delete', $exam), 'delete');
    }

    public function test_super_admin_keeps_full_authority_through_gate_before(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->assertTrue($superAdmin->can('viewAny', Exam::class));
        $this->assertTrue($superAdmin->can('view', $this->examA));
        $this->assertTrue($superAdmin->can('update', $this->examA));
        $this->assertTrue($superAdmin->can('author', [Exam::class, $this->classSubjectA]));
    }

    // ------------------------------------------------------- kelas yang diampu

    public function test_a_teacher_may_only_manage_exams_of_the_class_subject_they_teach(): void
    {
        $other = $this->userIn($this->schoolA, RoleName::Guru);

        $this->assertTrue($this->teacherA->can('update', $this->examA));
        $this->assertFalse($other->can('update', $this->examA));
        $this->assertFalse($other->can('author', [Exam::class, $this->classSubjectA]));
    }

    /**
     * Butir 278 — menjadi wali kelas tidak memberi kewenangan membuat ujian.
     * Yang memberi adalah `class_subjects.teacher_id`, bukan
     * `classes.homeroom_teacher_id`.
     */
    public function test_a_homeroom_teacher_who_teaches_nothing_may_not_author_exams(): void
    {
        $wali = $this->userIn($this->schoolA, RoleName::WaliKelas);

        $this->classA->forceFill(['homeroom_teacher_id' => $wali->getKey()])->save();

        $this->assertFalse($wali->can('author', [Exam::class, $this->classSubjectA]));
        $this->assertFalse($wali->can('update', $this->examA));
    }

    public function test_a_homeroom_teacher_who_also_teaches_may_author_for_that_class_subject(): void
    {
        $wali = $this->userIn($this->schoolA, RoleName::WaliKelas);

        $this->classA->forceFill(['homeroom_teacher_id' => $wali->getKey()])->save();

        $taught = $this->classSubjectIn($this->schoolA, $this->classA, $wali);

        $this->assertTrue($wali->can('author', [Exam::class, $taught]));
    }

    public function test_a_school_admin_may_author_for_any_class_subject_of_its_own_school(): void
    {
        $admin = $this->userIn($this->schoolA, RoleName::SchoolAdmin);

        $this->assertTrue($admin->can('author', [Exam::class, $this->classSubjectA]));
    }

    // -------------------------------------------------------------- lintas cabang

    public function test_no_school_level_role_may_touch_an_exam_of_another_school(): void
    {
        $examB = $this->examIn($this->classSubjectB);

        foreach ([RoleName::SchoolAdmin, RoleName::KepalaSekolah, RoleName::Guru, RoleName::WaliKelas] as $role) {
            $user = $this->userIn($this->schoolA, $role);

            $this->assertFalse($user->can('view', $examB), $role->value.' melihat ujian cabang lain.');
            $this->assertFalse($user->can('update', $examB), $role->value.' mengubah ujian cabang lain.');
        }
    }

    public function test_a_school_admin_may_not_author_for_a_class_subject_of_another_school(): void
    {
        $admin = $this->userIn($this->schoolA, RoleName::SchoolAdmin);

        $this->assertFalse($admin->can('author', [Exam::class, $this->classSubjectB]));
    }

    /**
     * Butir 127 — akun School Level tanpa cabang gagal tertutup, bukan terbuka.
     */
    public function test_a_branchless_school_level_account_is_refused(): void
    {
        $orphan = User::factory()->withRole(RoleName::SchoolAdmin)->create(['school_id' => null]);

        $this->assertFalse($orphan->can('view', $this->examA));
        $this->assertFalse($orphan->can('update', $this->examA));
        $this->assertFalse($orphan->can('author', [Exam::class, $this->classSubjectA]));
    }

    // ------------------------------------------------------------- kemandirian

    /**
     * ExamPolicy tidak boleh memanggil atau mewarisi GradePolicy. Yang dibagi
     * hanyalah izinnya; perilakunya milik sendiri, supaya pergeseran pada
     * penilaian tidak menyeret kewenangan CBT tanpa terlihat (butir 223).
     */
    public function test_the_exam_policy_does_not_delegate_to_the_grade_policy(): void
    {
        // Komentarnya dibuang lebih dulu: dokumentasi policy ini justru
        // menjelaskan mengapa ia **tidak** memanggil GradePolicy, dan penjelasan
        // itu tidak boleh menjatuhkan test yang memeriksa kodenya.
        $code = collect(token_get_all(file_get_contents(app_path('Policies/ExamPolicy.php'))))
            ->reject(fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
            ->map(fn ($token) => is_array($token) ? $token[1] : $token)
            ->implode('');

        $this->assertStringNotContainsString('GradePolicy', $code);
        $this->assertStringNotContainsString('extends', $code);
    }

    /**
     * Kewenangan mengerjakan ujian bukan urusan policy ini, dan siswa memang
     * ditolak seluruh method-nya. Jalur pengerjaan dibangun pada batch
     * tersendiri lewat identitas portal.
     */
    public function test_a_student_account_is_refused_every_authoring_ability(): void
    {
        $student = $this->userIn($this->schoolA, RoleName::Siswa);

        foreach (['viewAny', 'create'] as $ability) {
            $this->assertFalse($student->can($ability, Exam::class), $ability);
        }

        $this->assertFalse($student->can('view', $this->examA));
        $this->assertFalse($student->can('update', $this->examA));
        $this->assertFalse($student->can('author', [Exam::class, $this->classSubjectA]));
    }

    /**
     * `author()` menerima ClassSubject apa pun yang diberikan pemanggil,
     * termasuk yang dimuat tanpa global scope. Karena itu cabangnya dibandingkan
     * langsung di dalam policy, bukan digantung pada scope.
     */
    public function test_authoring_is_refused_even_when_the_class_subject_was_loaded_across_scopes(): void
    {
        $admin = $this->userIn($this->schoolA, RoleName::SchoolAdmin);

        $foreign = ClassSubject::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->findOrFail($this->classSubjectB->getKey());

        $this->assertFalse($admin->can('author', [Exam::class, $foreign]));
    }
}
