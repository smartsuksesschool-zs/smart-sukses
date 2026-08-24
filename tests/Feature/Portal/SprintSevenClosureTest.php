<?php

namespace Tests\Feature\Portal;

use App\Enums\RoleName;
use App\Enums\StudentClassStatus;
use App\Enums\StudentFeeStatus;
use App\Enums\StudentStatus;
use App\Filament\Resources\StudentResource;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\FeeType;
use App\Models\ReportCard;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentFee;
use App\Models\Subject;
use App\Models\User;
use App\Support\PortalEligibility;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Penutupan Sprint 7 — bukti untuk docs/sprint-7-closure.md.
 *
 * Test lain sudah membuktikan tiap portal secara terpisah. Yang dikumpulkan di
 * sini adalah hal-hal yang hanya terlihat bila ketiganya dibaca bersama:
 * kelengkapan endpoint §4.11, tidak adanya tautan mati di navigasi mana pun,
 * matriks pagar baris lintas peran, dan konsistensi aturan masuk portal.
 *
 * Berkas ini sengaja tidak mengulang perilaku yang sudah diuji di tempatnya;
 * ia menguji **kesatuannya**.
 */
class SprintSevenClosureTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected AcademicYear $yearA;

    protected User $parentA;

    protected User $teacherA;

    protected User $studentUserA;

    protected Student $childA;

    protected Student $peerB;

    protected SchoolClass $classA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create(['name' => 'SMP Madani']);
        $this->schoolB = School::factory()->create();

        $this->yearA = AcademicYear::factory()->create([
            'school_id' => $this->schoolA->id,
            'is_active' => true,
            'semester' => 1,
        ]);

        $this->parentA = $this->userIn(RoleName::OrangTua);
        $this->teacherA = $this->userIn(RoleName::Guru);
        $this->studentUserA = $this->userIn(RoleName::Siswa);

        $this->classA = SchoolClass::factory()->create([
            'school_id' => $this->schoolA->id,
            'academic_year_id' => $this->yearA->id,
            'name' => '7A',
        ]);

        // Satu siswa yang sekaligus anak parentA dan pemilik akun studentUserA,
        // supaya ketiga portal dapat diperiksa atas record yang sama.
        $this->childA = $this->studentIn('Anak A', $this->classA, [
            'parent_user_id' => $this->parentA->getKey(),
            'user_id' => $this->studentUserA->getKey(),
        ]);

        // Siswa lain di sekolah yang sama — tidak boleh terlihat oleh siapa pun
        // di atas.
        $this->peerB = $this->studentIn('Siswa Lain', $this->classA);

        $subject = Subject::factory()->create([
            'school_id' => $this->schoolA->id, 'name' => 'Matematika', 'code' => 'MTK',
        ]);
        ClassSubject::factory()->create([
            'school_id' => $this->schoolA->id,
            'class_id' => $this->classA->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $this->yearA->id,
            'teacher_id' => $this->teacherA->getKey(),
        ]);
    }

    protected function userIn(RoleName $role, array $overrides = []): User
    {
        return User::factory()->forSchool($this->schoolA)->withRole($role)->create($overrides);
    }

    protected function studentIn(string $name, ?SchoolClass $class = null, array $overrides = []): Student
    {
        $student = Student::factory()->create([
            'school_id' => $this->schoolA->id,
            'full_name' => $name,
            'status' => StudentStatus::Active->value,
            ...$overrides,
        ]);

        if ($class !== null) {
            StudentClass::factory()->create([
                'school_id' => $this->schoolA->id,
                'student_id' => $student->id,
                'class_id' => $class->id,
                'academic_year_id' => $class->academic_year_id,
                'status' => StudentClassStatus::Active->value,
            ]);
        }

        return $student;
    }

    protected function asToken(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('closure')->plainTextToken);
    }

    // ------------------------------------------- inventaris endpoint 4.11

    /**
     * @return array<string, array{string, string}>
     */
    public static function portalEndpoints(): array
    {
        return [
            'parent children' => ['api/v1/parent/children', 'GET'],
            'parent summary' => ['api/v1/parent/children/{studentId}/summary', 'GET'],
            'parent grades' => ['api/v1/parent/children/{studentId}/grades', 'GET'],
            'parent fees' => ['api/v1/parent/children/{studentId}/fees', 'GET'],
            'parent schedule' => ['api/v1/parent/children/{studentId}/schedule', 'GET'],
            'teacher dashboard' => ['api/v1/teacher/dashboard', 'GET'],
            'teacher classes' => ['api/v1/teacher/classes', 'GET'],
            'student dashboard' => ['api/v1/student/dashboard', 'GET'],
            'student schedule' => ['api/v1/student/schedule', 'GET'],
            'student grades' => ['api/v1/student/grades', 'GET'],
        ];
    }

    #[DataProvider('portalEndpoints')]
    public function test_every_portal_endpoint_of_section_4_11_is_registered(string $uri, string $method): void
    {
        $route = collect(app('router')->getRoutes())
            ->first(fn ($route) => $route->uri() === $uri && in_array($method, $route->methods(), true));

        $this->assertNotNull($route, "Endpoint {$method} {$uri} belum terdaftar.");
    }

    public function test_the_api_surface_is_exactly_thirty_three_routes(): void
    {
        $api = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
            ->count();

        $this->assertSame(33, $api);
    }

    /**
     * Subsistem notifikasi milik Sprint 8; tidak boleh ada placeholder-nya.
     */
    public function test_no_notification_endpoint_or_table_exists_yet(): void
    {
        foreach (collect(app('router')->getRoutes())->map(fn ($r) => $r->uri())->all() as $uri) {
            $this->assertStringNotContainsString('notification', $uri);
            $this->assertStringNotContainsString('notifikasi', $uri);
            $this->assertStringNotContainsString('pengumuman', $uri);
        }

        $this->assertFalse(Schema::hasTable('notifications'));
    }

    // ------------------------------------------------- navigasi & tautan

    /**
     * Setiap tautan pada navigasi portal harus benar-benar dapat dibuka oleh
     * peran yang melihatnya — bukan sekadar terdaftar.
     *
     * @return array<string, array{RoleName, string, array<int, string>}>
     */
    public static function portalNavigation(): array
    {
        return [
            'orang tua' => [
                RoleName::OrangTua,
                'portal.dashboard',
                ['portal.dashboard', 'portal.grades', 'portal.fees', 'portal.schedule'],
            ],
            'guru' => [
                RoleName::Guru,
                'teacher.dashboard',
                ['teacher.dashboard', 'teacher.classes', 'teacher.schedule'],
            ],
            'siswa' => [
                RoleName::Siswa,
                'student.dashboard',
                ['student.schedule', 'student.grades', 'student.profile'],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $expectedLinks
     */
    #[DataProvider('portalNavigation')]
    public function test_every_navigation_link_resolves_and_opens(RoleName $role, string $entry, array $expectedLinks): void
    {
        $user = match ($role) {
            RoleName::OrangTua => $this->parentA,
            RoleName::Guru => $this->teacherA,
            default => $this->studentUserA,
        };

        $this->actingAs($user);

        $page = $this->get(route($entry))->assertOk();

        foreach ($expectedLinks as $link) {
            // Tautannya benar-benar dirender…
            $page->assertSee(route($link), false);
            // …dan halaman di baliknya benar-benar terbuka bagi peran ini.
            $this->get(route($link))->assertOk();
        }
    }

    /**
     * Navigasi satu portal tidak boleh membawa menu portal lain maupun modul
     * yang tidak berhak dibuka perannya.
     *
     * @return array<string, array{RoleName, string, array<int, string>}>
     */
    public static function forbiddenMenus(): array
    {
        return [
            'orang tua tanpa rute guru/siswa' => [
                RoleName::OrangTua, 'portal.dashboard',
                ['teacher.dashboard', 'teacher.classes', 'student.dashboard', 'student.grades'],
            ],
            'guru tanpa rute orang tua/siswa' => [
                RoleName::Guru, 'teacher.dashboard',
                ['portal.dashboard', 'portal.fees', 'student.dashboard', 'student.grades'],
            ],
            'siswa tanpa rute orang tua/guru' => [
                RoleName::Siswa, 'student.dashboard',
                ['portal.fees', 'portal.dashboard', 'teacher.classes', 'teacher.dashboard'],
            ],
        ];
    }

    /**
     * Dicocokkan pada **rute**, bukan pada teks: label seperti "Profil Anak"
     * di dasbor orang tua sah adanya dan bukan tautan ke portal siswa.
     *
     * @param  array<int, string>  $forbiddenRoutes
     */
    #[DataProvider('forbiddenMenus')]
    public function test_no_portal_links_to_another_portals_module(RoleName $role, string $entry, array $forbiddenRoutes): void
    {
        $user = match ($role) {
            RoleName::OrangTua => $this->parentA,
            RoleName::Guru => $this->teacherA,
            default => $this->studentUserA,
        };

        $this->actingAs($user);

        $page = $this->get(route($entry))->assertOk();

        foreach ($forbiddenRoutes as $forbidden) {
            $page->assertDontSee('href="'.route($forbidden).'"', false);
        }
    }

    /**
     * Dua tempat yang sengaja menampilkan menu/pintasan tanpa tautan: menu
     * Notifikasi siswa (butir 183) dan pintasan Buat Pengumuman guru
     * (butir 175). Keduanya harus tetap tanpa href.
     */
    public function test_deferred_items_are_shown_without_a_dead_link(): void
    {
        $this->actingAs($this->studentUserA);

        $student = $this->get(route('student.dashboard'))->assertOk();
        $student->assertSee('Notifikasi');
        $student->assertSee('aria-disabled="true"', false);
        $student->assertDontSee('href="/siswa/notifikasi"', false);

        $this->actingAs($this->teacherA);

        $teacher = $this->get(route('teacher.dashboard'))->assertOk();
        $teacher->assertSee('Buat Pengumuman');
        $teacher->assertSee('aria-disabled="true"', false);
        $teacher->assertSee('kewenangan Admin Sekolah');
    }

    // --------------------------------------------- matriks pagar baris

    /**
     * ORANG_TUA: hanya anaknya sendiri, dan tidak menyentuh pembayaran.
     */
    public function test_row_level_matrix_for_a_parent(): void
    {
        $this->assertTrue($this->parentA->can('view', $this->childA));
        $this->assertFalse($this->parentA->can('view', $this->peerB));

        $this->assertTrue($this->parentA->can('view', $this->reportCardFor($this->childA)));
        $this->assertFalse($this->parentA->can('view', $this->reportCardFor($this->peerB)));

        $this->assertTrue($this->parentA->can('view', $this->feeFor($this->childA)));
        $this->assertFalse($this->parentA->can('view', $this->feeFor($this->peerB)));

        $this->asToken($this->parentA)->getJson('/api/v1/payments')->assertStatus(403);
    }

    /**
     * SISWA: hanya dirinya sendiri, dan keuangan tertutup sepenuhnya.
     */
    public function test_row_level_matrix_for_a_student(): void
    {
        $this->assertTrue($this->studentUserA->can('view', $this->childA));
        $this->assertFalse($this->studentUserA->can('view', $this->peerB));

        $this->assertTrue($this->studentUserA->can('view', $this->reportCardFor($this->childA)));
        $this->assertFalse($this->studentUserA->can('view', $this->reportCardFor($this->peerB)));

        $this->asToken($this->studentUserA)->getJson('/api/v1/student-fees')->assertStatus(403);
        $this->asToken($this->studentUserA)->getJson('/api/v1/payments')->assertStatus(403);
    }

    /**
     * GURU: hanya siswa kelas ajarnya, lewat pagar query panel (butir 176).
     */
    public function test_row_level_matrix_for_a_teacher(): void
    {
        $otherClass = SchoolClass::factory()->create([
            'school_id' => $this->schoolA->id,
            'academic_year_id' => $this->yearA->id,
            'name' => '7B',
        ]);
        $outsider = $this->studentIn('Bukan Murid Saya', $otherClass);

        $this->actingAs($this->teacherA);

        $visible = StudentResource::getEloquentQuery()
            ->pluck('full_name')->all();

        $this->assertContains('Anak A', $visible);
        $this->assertContains('Siswa Lain', $visible);
        $this->assertNotContains($outsider->full_name, $visible);
    }

    /**
     * Peran administratif tidak ikut terbatasi oleh pagar mana pun.
     *
     * @return array<string, array{RoleName}>
     */
    public static function staffRoles(): array
    {
        return [
            'school admin' => [RoleName::SchoolAdmin],
            'kepala sekolah' => [RoleName::KepalaSekolah],
        ];
    }

    #[DataProvider('staffRoles')]
    public function test_staff_behaviour_is_unchanged(RoleName $role): void
    {
        $staff = $this->userIn($role);

        $this->assertTrue($staff->can('view', $this->childA));
        $this->assertTrue($staff->can('view', $this->peerB));
        $this->assertTrue($staff->can('view', $this->reportCardFor($this->peerB)));
    }

    public function test_a_super_admin_still_reads_across_branches(): void
    {
        $superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create(['school_id' => null]);
        $foreign = Student::factory()->create([
            'school_id' => $this->schoolB->id,
            'status' => StudentStatus::Active->value,
        ]);

        $this->assertTrue($superAdmin->can('view', $foreign));
        $this->assertTrue($superAdmin->can('view', $this->peerB));
    }

    /**
     * Tidak ada satu pun peran portal yang menembus batas cabang.
     */
    public function test_no_portal_role_crosses_a_tenant_boundary(): void
    {
        $foreign = Student::factory()->create([
            'school_id' => $this->schoolB->id,
            'status' => StudentStatus::Active->value,
        ]);

        foreach ([$this->parentA, $this->teacherA, $this->studentUserA] as $actor) {
            $this->assertFalse($actor->can('view', $foreign));
        }
    }

    protected function reportCardFor(Student $student): ReportCard
    {
        return ReportCard::factory()->create([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'class_id' => $this->classA->id,
            'academic_year_id' => $this->yearA->id,
            'is_published' => true,
            'published_at' => CarbonImmutable::now(),
        ]);
    }

    protected function feeFor(Student $student): StudentFee
    {
        return StudentFee::factory()->create([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'fee_type_id' => FeeType::factory()->create(['school_id' => $student->school_id])->id,
            'amount' => '1000000',
            'amount_paid' => '0',
            'period' => '2026-08',
            'status' => StudentFeeStatus::Unpaid->value,
        ]);
    }

    // ------------------------------------------------ konsistensi auth

    /**
     * Ketiga portal memakai aturan kelayakan yang sama (butir 180); yang
     * berbeda hanya daftar perannya.
     *
     * @return array<string, array{RoleName, array<int, RoleName>}>
     */
    public static function eligibilityRoles(): array
    {
        return [
            'orang tua' => [RoleName::OrangTua, [RoleName::OrangTua]],
            'guru' => [RoleName::Guru, [RoleName::Guru, RoleName::WaliKelas]],
            'siswa' => [RoleName::Siswa, [RoleName::Siswa]],
        ];
    }

    /**
     * @param  array<int, RoleName>  $allowed
     */
    #[DataProvider('eligibilityRoles')]
    public function test_every_portal_applies_the_same_four_conditions(RoleName $role, array $allowed): void
    {
        $ok = $this->userIn($role);
        $this->assertNull(PortalEligibility::refusalReasonFor($ok, $allowed));

        // 1. akun nonaktif
        $this->assertNotNull(PortalEligibility::refusalReasonFor(
            $this->userIn($role, ['is_active' => false]), $allowed
        ));

        // 2. peran keliru
        $this->assertNotNull(PortalEligibility::refusalReasonFor(
            $this->userIn(RoleName::Bendahara), $allowed
        ));

        // 3. tanpa cabang
        $this->assertNotNull(PortalEligibility::refusalReasonFor(
            User::factory()->withRole($role)->create(['school_id' => null]), $allowed
        ));

        // 4. kata sandi sementara belum diganti
        $this->assertSame(
            PortalEligibility::PASSWORD_CHANGE_REQUIRED,
            PortalEligibility::refusalReasonFor(
                $this->userIn($role, ['must_change_password' => true]), $allowed
            ),
        );
    }

    /**
     * Pesan untuk nonaktif, peran keliru, dan tanpa cabang harus identik —
     * membedakannya membocorkan keadaan akun (butir 157).
     */
    public function test_refusal_messages_do_not_distinguish_account_state(): void
    {
        $allowed = [RoleName::Siswa];

        $messages = [
            PortalEligibility::refusalReasonFor($this->userIn(RoleName::Siswa, ['is_active' => false]), $allowed),
            PortalEligibility::refusalReasonFor($this->userIn(RoleName::Guru), $allowed),
            PortalEligibility::refusalReasonFor(
                User::factory()->withRole(RoleName::Siswa)->create(['school_id' => null]), $allowed
            ),
        ];

        $this->assertSame([PortalEligibility::REFUSED], array_values(array_unique($messages)));
    }

    /**
     * Ketiga portal menolak sesi berpenanda ganti kata sandi, termasuk sesi
     * yang sudah berjalan (butir 158, 177).
     *
     * @return array<string, array{RoleName, string}>
     */
    public static function portalEntryPoints(): array
    {
        return [
            'orang tua' => [RoleName::OrangTua, 'portal.dashboard'],
            'guru' => [RoleName::Guru, 'teacher.dashboard'],
            'siswa' => [RoleName::Siswa, 'student.dashboard'],
        ];
    }

    #[DataProvider('portalEntryPoints')]
    public function test_a_temporary_password_blocks_every_portal(RoleName $role, string $entry): void
    {
        $user = $this->userIn($role, ['must_change_password' => true]);

        if ($role === RoleName::Siswa) {
            $this->studentIn('Sandi Sementara', $this->classA, ['user_id' => $user->getKey()]);
        }

        $this->actingAs($user);

        $this->get(route($entry))->assertForbidden();
    }

    // --------------------------------------------------- struktur responsif

    /**
     * Yang dibuktikan di sini **struktur markup dan CSS**, bukan perilaku
     * peramban sungguhan pada perangkat nyata. Pengujian lintas perangkat
     * adalah pekerjaan QA Sprint 9 (butir 190).
     *
     * @return array<string, array{RoleName, string}>
     */
    #[DataProvider('portalEntryPoints')]
    public function test_every_portal_ships_the_same_mobile_first_structure(RoleName $role, string $entry): void
    {
        $user = $this->userIn($role);

        if ($role === RoleName::Siswa) {
            $this->studentIn('Siswa Responsif', $this->classA, ['user_id' => $user->getKey()]);
        }

        $this->actingAs($user);

        $page = $this->get(route($entry))->assertOk();

        $page->assertSee('width=device-width, initial-scale=1', false);
        $page->assertSee('grid-template-columns: 1fr', false);
        $page->assertSee('@media (min-width: 48rem)', false);
        $page->assertSee('overflow-x: hidden', false);
        $page->assertSee('min-height: 2.75rem', false);
    }

    #[DataProvider('portalEntryPoints')]
    public function test_every_portal_carries_the_school_branding(RoleName $role, string $entry): void
    {
        $user = $this->userIn($role);

        if ($role === RoleName::Siswa) {
            $this->studentIn('Siswa Branding', $this->classA, ['user_id' => $user->getKey()]);
        }

        $this->actingAs($user);

        $this->get(route($entry))->assertOk()->assertSee('SMP Madani');
    }
}
