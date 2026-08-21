<?php

namespace Tests\Feature\Portal;

use App\Enums\GradeType;
use App\Enums\RoleName;
use App\Enums\StudentClassStatus;
use App\Enums\StudentFeeStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\ClassSubject;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentFee;
use App\Models\Subject;
use App\Models\User;
use App\Services\Portal\ParentPortalService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * API 4.11 — GET /parent/children dan
 * GET /parent/children/{studentId}/summary (PORTAL-01).
 *
 * Yang paling dijaga di sini bukan bentuk responsnya, melainkan pagar
 * kepemilikannya: seorang orang tua hanya boleh melihat anaknya sendiri, dan
 * anak orang lain tidak boleh terkonfirmasi keberadaannya lewat jalur mana pun.
 */
class ParentPortalApiTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected AcademicYear $yearA;

    protected User $parentA;

    protected Student $childOne;

    protected Student $childTwo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create();
        $this->schoolB = School::factory()->create();

        $this->yearA = AcademicYear::factory()->create([
            'school_id' => $this->schoolA->id,
            'is_active' => true,
        ]);

        $this->parentA = $this->userIn($this->schoolA, RoleName::OrangTua);

        $this->childOne = $this->childOf($this->parentA, $this->schoolA, 'Ahmad Fauzi');
        $this->childTwo = $this->childOf($this->parentA, $this->schoolA, 'Budi Santoso');
    }

    protected function asUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }

    protected function userIn(School $school, RoleName $role, array $overrides = []): User
    {
        return User::factory()->forSchool($school)->withRole($role)->create($overrides);
    }

    protected function childOf(?User $parent, School $school, string $name, array $overrides = []): Student
    {
        return Student::factory()->create([
            'school_id' => $school->id,
            'parent_user_id' => $parent?->getKey(),
            'full_name' => $name,
            'status' => StudentStatus::Active->value,
            ...$overrides,
        ]);
    }

    protected function placeInClass(Student $student, AcademicYear $year, string $className = '7A'): SchoolClass
    {
        $class = SchoolClass::factory()->create([
            'school_id' => $student->school_id,
            'academic_year_id' => $year->id,
            'name' => $className,
        ]);

        StudentClass::factory()->create([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'class_id' => $class->id,
            'academic_year_id' => $year->id,
            'status' => StudentClassStatus::Active->value,
        ]);

        return $class;
    }

    protected function gradeFor(Student $student, SchoolClass $class, string $subjectName, float $score, GradeType $type = GradeType::Daily): Grade
    {
        $subject = Subject::factory()->create([
            'school_id' => $student->school_id,
            'name' => $subjectName,
        ]);

        $classSubject = ClassSubject::factory()->create([
            'school_id' => $student->school_id,
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $class->academic_year_id,
        ]);

        return Grade::factory()->create([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'class_subject_id' => $classSubject->id,
            'academic_year_id' => $class->academic_year_id,
            'grade_type' => $type->value,
            'score' => $score,
        ]);
    }

    protected function feeFor(Student $student, array $overrides = []): StudentFee
    {
        return StudentFee::factory()->create([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'fee_type_id' => FeeType::factory()->create(['school_id' => $student->school_id])->id,
            'amount' => '1000000',
            'amount_paid' => '0',
            'period' => '2026-08',
            'status' => StudentFeeStatus::Unpaid->value,
            ...$overrides,
        ]);
    }

    // -------------------------------------------------------------- akses

    /**
     * @return array<string, array{RoleName}>
     */
    public static function nonParentRoles(): array
    {
        return [
            'super admin' => [RoleName::SuperAdmin],
            'school admin' => [RoleName::SchoolAdmin],
            'kepala sekolah' => [RoleName::KepalaSekolah],
            'bendahara' => [RoleName::Bendahara],
            'guru' => [RoleName::Guru],
            'wali kelas' => [RoleName::WaliKelas],
            'siswa' => [RoleName::Siswa],
        ];
    }

    public function test_the_children_endpoint_requires_a_token(): void
    {
        $this->getJson('/api/v1/parent/children')->assertStatus(401);
        $this->getJson("/api/v1/parent/children/{$this->childOne->id}/summary")->assertStatus(401);
    }

    /**
     * "Auth Level: Auth" berarti wajib token, bukan boleh dibaca semua peran.
     * Admin sekolah tidak menjadi orang tua siapa pun (butir 148).
     */
    #[DataProvider('nonParentRoles')]
    public function test_no_other_role_may_use_the_parent_endpoints(RoleName $role): void
    {
        $actor = $role === RoleName::SuperAdmin
            ? User::factory()->withRole($role)->create(['school_id' => null])
            : $this->userIn($this->schoolA, $role);

        $this->asUser($actor)->getJson('/api/v1/parent/children')->assertStatus(403);
        $this->asUser($actor)
            ->getJson("/api/v1/parent/children/{$this->childOne->id}/summary")
            ->assertStatus(403);
    }

    public function test_a_school_less_parent_is_refused(): void
    {
        $orphan = User::factory()->withRole(RoleName::OrangTua)->create(['school_id' => null]);

        $this->asUser($orphan)->getJson('/api/v1/parent/children')->assertStatus(403);
    }

    public function test_a_deactivated_parent_is_refused(): void
    {
        $inactive = $this->userIn($this->schoolA, RoleName::OrangTua, ['is_active' => false]);
        $this->childOf($inactive, $this->schoolA, 'Anak Nonaktif');

        $this->asUser($inactive)->getJson('/api/v1/parent/children')->assertStatus(403);
    }

    // ------------------------------------------------------------ children

    public function test_a_parent_sees_only_their_own_children(): void
    {
        // Anak orang tua lain di sekolah yang sama.
        $otherParent = $this->userIn($this->schoolA, RoleName::OrangTua);
        $this->childOf($otherParent, $this->schoolA, 'Anak Orang Lain');

        // Anak di sekolah lain, tertaut ke akun orang tua yang sama sekali lain.
        $this->childOf(
            $this->userIn($this->schoolB, RoleName::OrangTua),
            $this->schoolB,
            'Anak Cabang Lain',
        );

        // Siswa tanpa akun orang tua sama sekali.
        $this->childOf(null, $this->schoolA, 'Anak Tanpa Wali');

        $body = $this->asUser($this->parentA)->getJson('/api/v1/parent/children')->assertOk();

        $this->assertSame(
            ['Ahmad Fauzi', 'Budi Santoso'],
            array_column($body->json('data'), 'full_name'),
        );
    }

    /**
     * Kasus paling berbahaya: anak yang tertaut ke akun ini **tetapi** berada di
     * cabang lain. Tanpa syarat `school_id`, ia akan ikut terbaca (butir 148).
     */
    public function test_a_child_of_this_parent_in_another_branch_is_excluded(): void
    {
        $strayChild = $this->childOf($this->parentA, $this->schoolB, 'Anak Nyasar');

        $body = $this->asUser($this->parentA)->getJson('/api/v1/parent/children')->assertOk();

        $this->assertNotContains('Anak Nyasar', array_column($body->json('data'), 'full_name'));

        $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$strayChild->id}/summary")
            ->assertStatus(404);
    }

    public function test_the_child_resource_leaks_no_internal_fields(): void
    {
        $this->childOne->update(['photo_url' => 'student-photos/1/rahasia.jpg']);

        $body = $this->asUser($this->parentA)->getJson('/api/v1/parent/children')->assertOk();

        $this->assertSame(
            ['id', 'nis', 'nisn', 'full_name', 'status', 'has_photo', 'current_class'],
            array_keys($body->json('data.0')),
        );

        $encoded = json_encode($body->json());

        // Pagar kepemilikan dan jalur berkas privat tidak pernah keluar.
        $this->assertStringNotContainsString('parent_user_id', $encoded);
        $this->assertStringNotContainsString('school_id', $encoded);
        $this->assertStringNotContainsString('student-photos', $encoded);
        $this->assertStringNotContainsString('rahasia', $encoded);
    }

    public function test_the_active_class_is_reported_when_there_is_one(): void
    {
        $this->placeInClass($this->childOne, $this->yearA, '7A');

        $body = $this->asUser($this->parentA)->getJson('/api/v1/parent/children')->assertOk();

        $children = collect($body->json('data'))->keyBy('full_name');

        $this->assertSame('7A', $children['Ahmad Fauzi']['current_class']['name']);
        $this->assertSame($this->yearA->name, $children['Ahmad Fauzi']['current_class']['academic_year']);
        // Anak kedua belum ditempatkan: NULL, bukan kelas karangan.
        $this->assertNull($children['Budi Santoso']['current_class']);
    }

    /**
     * Dokumen tidak menetapkan penyaringan status, dan anak yang sudah lulus
     * tetap punya riwayat yang perlu dibaca orang tuanya (butir 149).
     */
    public function test_a_graduated_child_does_not_disappear(): void
    {
        $this->childOf($this->parentA, $this->schoolA, 'Citra Lulus', [
            'status' => StudentStatus::Graduated->value,
        ]);

        $body = $this->asUser($this->parentA)->getJson('/api/v1/parent/children')->assertOk();

        $names = array_column($body->json('data'), 'full_name');

        $this->assertContains('Citra Lulus', $names);
        $this->assertCount(3, $names);
    }

    // ------------------------------------------------------------- summary

    public function test_a_parent_reads_the_summary_of_their_own_child(): void
    {
        $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->childOne->id}/summary")
            ->assertOk()
            ->assertJsonPath('data.child.id', $this->childOne->id)
            ->assertJsonStructure([
                'data' => ['child', 'latest_grades', 'attendance', 'pending_fees'],
            ]);
    }

    public function test_another_parents_child_in_the_same_branch_is_a_404(): void
    {
        $otherParent = $this->userIn($this->schoolA, RoleName::OrangTua);
        $foreign = $this->childOf($otherParent, $this->schoolA, 'Bukan Anak Saya');

        $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$foreign->id}/summary")
            ->assertStatus(404);
    }

    public function test_a_child_from_another_branch_is_a_404(): void
    {
        $foreign = $this->childOf(
            $this->userIn($this->schoolB, RoleName::OrangTua),
            $this->schoolB,
            'Anak Cabang B',
        );

        $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$foreign->id}/summary")
            ->assertStatus(404);
    }

    public function test_an_unknown_child_is_a_404(): void
    {
        $this->asUser($this->parentA)
            ->getJson('/api/v1/parent/children/999999/summary')
            ->assertStatus(404);
    }

    public function test_a_smuggled_school_id_changes_nothing(): void
    {
        $foreign = $this->childOf(
            $this->userIn($this->schoolB, RoleName::OrangTua),
            $this->schoolB,
            'Anak Cabang B',
        );

        $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$foreign->id}/summary?school_id={$this->schoolB->id}")
            ->assertStatus(404);
    }

    // --------------------------------------------------------------- nilai

    public function test_the_summary_returns_at_most_five_subjects(): void
    {
        $class = $this->placeInClass($this->childOne, $this->yearA);

        foreach (['Matematika', 'IPA', 'IPS', 'Bahasa', 'Seni', 'Olahraga', 'Agama'] as $subject) {
            $this->gradeFor($this->childOne, $class, $subject, 80);
        }

        $body = $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->childOne->id}/summary")
            ->assertOk();

        $this->assertCount(5, $body->json('data.latest_grades'));
    }

    /**
     * "Lima mapel terbaru", bukan lima baris penilaian: beberapa ulangan pada
     * mapel yang sama tetap satu entri.
     */
    public function test_repeated_assessments_do_not_consume_subject_slots(): void
    {
        $class = $this->placeInClass($this->childOne, $this->yearA);

        $subject = Subject::factory()->create([
            'school_id' => $this->schoolA->id,
            'name' => 'Matematika',
        ]);
        $classSubject = ClassSubject::factory()->create([
            'school_id' => $this->schoolA->id,
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $this->yearA->id,
        ]);

        foreach ([70, 80, 90, 85, 95] as $score) {
            Grade::factory()->create([
                'school_id' => $this->schoolA->id,
                'student_id' => $this->childOne->id,
                'class_subject_id' => $classSubject->id,
                'academic_year_id' => $this->yearA->id,
                'grade_type' => GradeType::Daily->value,
                'score' => $score,
            ]);
        }

        $this->gradeFor($this->childOne, $class, 'IPA', 88);

        $body = $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->childOne->id}/summary")
            ->assertOk();

        $names = array_column($body->json('data.latest_grades'), 'subject_name');

        $this->assertCount(2, $names);
        $this->assertEqualsCanonicalizing(['Matematika', 'IPA'], $names);
    }

    public function test_grades_from_another_academic_year_are_not_shown(): void
    {
        $class = $this->placeInClass($this->childOne, $this->yearA);
        $this->gradeFor($this->childOne, $class, 'Matematika', 80);

        $oldYear = AcademicYear::factory()->create([
            'school_id' => $this->schoolA->id,
            'is_active' => false,
        ]);
        $oldClass = SchoolClass::factory()->create([
            'school_id' => $this->schoolA->id,
            'academic_year_id' => $oldYear->id,
        ]);
        $this->gradeFor($this->childOne, $oldClass, 'Sejarah Lama', 60);

        $body = $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->childOne->id}/summary")
            ->assertOk();

        $this->assertSame(
            ['Matematika'],
            array_column($body->json('data.latest_grades'), 'subject_name'),
        );
    }

    /**
     * Cabang tanpa tahun ajaran aktif adalah keadaan yang wajar, bukan
     * kesalahan: daftar nilainya kosong dan sisa ringkasan tetap terkirim
     * (butir 153).
     */
    public function test_a_branch_without_an_active_academic_year_still_answers(): void
    {
        $this->yearA->forceFill(['is_active' => false])->save();

        $this->feeFor($this->childOne, ['amount' => '500000']);

        $body = $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->childOne->id}/summary")
            ->assertOk();

        $this->assertSame([], $body->json('data.latest_grades'));
        // Tagihan tidak bergantung pada tahun ajaran aktif.
        $body->assertJsonPath('data.pending_fees.outstanding_amount', '500000.00');
    }

    public function test_reading_the_summary_writes_nothing(): void
    {
        $class = $this->placeInClass($this->childOne, $this->yearA);
        $grade = $this->gradeFor($this->childOne, $class, 'Matematika', 80);

        $before = $grade->fresh()->getAttributes();
        AuditLog::query()->withoutGlobalScope(SchoolScope::class)->delete();

        $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->childOne->id}/summary")
            ->assertOk();

        // Portal hanya membaca: tidak ada nilai yang berubah dan tidak ada
        // satu pun baris audit yang lahir (butir 151).
        $this->assertSame($before, $grade->fresh()->getAttributes());
        $this->assertSame(0, AuditLog::query()->withoutGlobalScope(SchoolScope::class)->count());
    }

    // ----------------------------------------------------------- kehadiran

    /**
     * PORTAL-01 meminta kehadiran bulan ini, dan Phase 1 tidak punya sumbernya.
     * Yang dikembalikan keadaan sebenarnya, bukan angka nol (butir 152).
     */
    public function test_attendance_is_reported_as_unavailable_rather_than_zero(): void
    {
        $body = $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->childOne->id}/summary")
            ->assertOk();

        $body->assertJsonPath('data.attendance.available', false);
        $body->assertJsonPath('data.attendance.present_count', null);

        $this->assertNotSame(0, $body->json('data.attendance.present_count'));
    }

    public function test_no_attendance_table_was_introduced(): void
    {
        foreach (['attendances', 'attendance', 'presences', 'absensi', 'student_attendances'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
    }

    // -------------------------------------------------------------- tagihan

    public function test_pending_fees_count_unpaid_and_partial_only(): void
    {
        $this->feeFor($this->childOne, [
            'amount' => '1000000', 'amount_paid' => '0',
            'status' => StudentFeeStatus::Unpaid->value,
        ]);
        $this->feeFor($this->childOne, [
            'amount' => '1000000', 'amount_paid' => '400000',
            'status' => StudentFeeStatus::Partial->value,
        ]);
        $this->feeFor($this->childOne, [
            'amount' => '1000000', 'amount_paid' => '1000000',
            'status' => StudentFeeStatus::Paid->value,
        ]);
        $this->feeFor($this->childOne, [
            'amount' => '1000000', 'amount_paid' => '0',
            'status' => StudentFeeStatus::Waived->value, 'waive_reason' => 'Beasiswa',
        ]);

        $body = $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->childOne->id}/summary")
            ->assertOk();

        // Dua tagihan tertunggak; sisanya 1.000.000 + 600.000.
        $body->assertJsonPath('data.pending_fees.count', 2);
        $body->assertJsonPath('data.pending_fees.outstanding_amount', '1600000.00');
    }

    public function test_a_sibling_fee_never_counts_towards_this_child(): void
    {
        $this->feeFor($this->childOne, ['amount' => '100000']);
        $this->feeFor($this->childTwo, ['amount' => '900000']);

        $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->childOne->id}/summary")
            ->assertOk()
            ->assertJsonPath('data.pending_fees.outstanding_amount', '100000.00');
    }

    public function test_the_outstanding_amount_is_a_two_decimal_string(): void
    {
        $this->feeFor($this->childOne, ['amount' => '1234567.89', 'amount_paid' => '0.11']);

        $body = $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->childOne->id}/summary")
            ->assertOk();

        $this->assertSame('1234567.78', $body->json('data.pending_fees.outstanding_amount'));
        $this->assertIsString($body->json('data.pending_fees.outstanding_amount'));
    }

    public function test_a_child_without_fees_reports_zero(): void
    {
        $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->childOne->id}/summary")
            ->assertOk()
            ->assertJsonPath('data.pending_fees.count', 0)
            ->assertJsonPath('data.pending_fees.outstanding_amount', '0.00');
    }

    // ------------------------------------------------------------- performa

    /**
     * Orang tua dengan sepuluh anak tidak boleh menghasilkan satu query per
     * anak untuk kelas maupun tahun ajarannya.
     */
    public function test_the_children_query_count_does_not_follow_the_number_of_children(): void
    {
        $this->placeInClass($this->childOne, $this->yearA);
        $this->placeInClass($this->childTwo, $this->yearA, '7B');

        $this->asUser($this->parentA)->getJson('/api/v1/parent/children');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->asUser($this->parentA)->getJson('/api/v1/parent/children')->assertOk();

        $withTwo = count(DB::getQueryLog());

        for ($i = 0; $i < 8; $i++) {
            $child = $this->childOf($this->parentA, $this->schoolA, "Anak Ke-{$i}");
            $this->placeInClass($child, $this->yearA, "8-{$i}");
        }

        DB::flushQueryLog();

        $this->asUser($this->parentA)->getJson('/api/v1/parent/children')->assertOk();

        $withTen = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertSame($withTwo, $withTen);
    }

    public function test_the_summary_query_count_does_not_follow_the_data(): void
    {
        $class = $this->placeInClass($this->childOne, $this->yearA);
        $this->gradeFor($this->childOne, $class, 'Matematika', 80);
        $this->feeFor($this->childOne);

        $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->childOne->id}/summary");

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->childOne->id}/summary")->assertOk();

        $small = count(DB::getQueryLog());

        foreach (['IPA', 'IPS', 'Bahasa', 'Seni', 'Olahraga'] as $subject) {
            $this->gradeFor($this->childOne, $class, $subject, 85);
        }
        for ($i = 0; $i < 10; $i++) {
            $this->feeFor($this->childOne);
        }

        DB::flushQueryLog();

        $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->childOne->id}/summary")->assertOk();

        $large = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertSame($small, $large);
    }

    // -------------------------------------------------- panggilan langsung

    /**
     * Pagarnya ada di service, bukan hanya di rute: halaman portal memanggil
     * service ini langsung.
     */
    public function test_the_service_refuses_a_non_parent_actor_directly(): void
    {
        $admin = $this->userIn($this->schoolA, RoleName::SchoolAdmin);

        $this->expectException(AuthorizationException::class);

        app(ParentPortalService::class)->children($admin);
    }
}
