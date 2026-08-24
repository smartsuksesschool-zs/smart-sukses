<?php

namespace Tests\Feature\Portal;

use App\Enums\DayOfWeek;
use App\Enums\RoleName;
use App\Enums\StudentClassStatus;
use App\Enums\StudentStatus;
use App\Filament\Pages\InputNilai;
use App\Filament\Resources\ScheduleResource;
use App\Filament\Resources\StudentResource;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Schedule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Halaman Teacher Portal, pintasan PORTAL-02, dan pagar barisnya.
 *
 * Termasuk yang paling penting: guru tidak boleh memeriksa kelas sembarangan
 * dengan mengganti angka di alamat, dan tidak boleh melihat siswa maupun jadwal
 * guru lain lewat panel.
 */
class TeacherPortalUiTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected AcademicYear $yearA;

    protected User $teacherA;

    protected User $teacherB;

    protected SchoolClass $classA;

    protected SchoolClass $classB;

    protected Student $studentA;

    protected Student $studentB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create(['name' => 'SMP Madani', 'primary_color' => '#123456']);
        $this->schoolB = School::factory()->create();

        $this->yearA = AcademicYear::factory()->create([
            'school_id' => $this->schoolA->id,
            'is_active' => true,
            'name' => '2026/2027',
        ]);

        $this->teacherA = $this->userIn($this->schoolA, RoleName::Guru, ['name' => 'Pak Rudi']);
        $this->teacherB = $this->userIn($this->schoolA, RoleName::Guru, ['name' => 'Bu Sari']);

        $this->classA = $this->classIn('7A');
        $this->classB = $this->classIn('7B');

        $this->assign($this->teacherA, $this->classA, 'Matematika', 'MTK');
        $this->assign($this->teacherB, $this->classB, 'IPA', 'IPA');

        $this->studentA = $this->enrol($this->classA, 'Siswa Kelas A');
        $this->studentB = $this->enrol($this->classB, 'Siswa Kelas B');
    }

    protected function userIn(School $school, RoleName $role, array $overrides = []): User
    {
        return User::factory()->forSchool($school)->withRole($role)->create($overrides);
    }

    protected function classIn(string $name, ?AcademicYear $year = null, ?School $school = null): SchoolClass
    {
        $school ??= $this->schoolA;
        $year ??= $this->yearA;

        return SchoolClass::factory()->create([
            'school_id' => $school->id,
            'academic_year_id' => $year->id,
            'name' => $name,
        ]);
    }

    protected function assign(User $teacher, SchoolClass $class, string $subjectName, string $code): ClassSubject
    {
        $subject = Subject::factory()->create([
            'school_id' => $class->school_id,
            'name' => $subjectName,
            'code' => $code,
        ]);

        return ClassSubject::factory()->create([
            'school_id' => $class->school_id,
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $class->academic_year_id,
            'teacher_id' => $teacher->getKey(),
        ]);
    }

    protected function enrol(SchoolClass $class, string $name, StudentStatus $status = StudentStatus::Active): Student
    {
        $student = Student::factory()->create([
            'school_id' => $class->school_id,
            'full_name' => $name,
            'status' => $status->value,
        ]);

        StudentClass::factory()->create([
            'school_id' => $class->school_id,
            'student_id' => $student->id,
            'class_id' => $class->id,
            'academic_year_id' => $class->academic_year_id,
            'status' => StudentClassStatus::Active->value,
        ]);

        return $student;
    }

    // ----------------------------------------------------------- akses web

    /**
     * @return array<string, array{string}>
     */
    public static function teacherPages(): array
    {
        return [
            'dasbor' => ['teacher.dashboard'],
            'kelas ajar' => ['teacher.classes'],
            'jadwal' => ['teacher.schedule'],
        ];
    }

    #[DataProvider('teacherPages')]
    public function test_a_teacher_reaches_every_portal_page(string $route): void
    {
        $this->actingAs($this->teacherA);

        $this->get(route($route))->assertOk();
    }

    public function test_a_wali_kelas_reaches_the_portal(): void
    {
        $this->actingAs($this->userIn($this->schoolA, RoleName::WaliKelas));

        $this->get(route('teacher.dashboard'))->assertOk();
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function nonTeacherRoles(): array
    {
        return [
            'school admin' => [RoleName::SchoolAdmin],
            'kepala sekolah' => [RoleName::KepalaSekolah],
            'bendahara' => [RoleName::Bendahara],
            'siswa' => [RoleName::Siswa],
            'orang tua' => [RoleName::OrangTua],
        ];
    }

    #[DataProvider('nonTeacherRoles')]
    public function test_no_other_role_reaches_the_teacher_portal(RoleName $role): void
    {
        $this->actingAs($this->userIn($this->schoolA, $role));

        $this->get(route('teacher.dashboard'))->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_existing_login(): void
    {
        $this->get(route('teacher.dashboard'))
            ->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_a_deactivated_teacher_is_refused(): void
    {
        $this->actingAs($this->userIn($this->schoolA, RoleName::Guru, ['is_active' => false]));

        $this->get(route('teacher.dashboard'))->assertForbidden();
    }

    public function test_a_school_less_teacher_is_refused(): void
    {
        $this->actingAs(User::factory()->withRole(RoleName::Guru)->create(['school_id' => null]));

        $this->get(route('teacher.dashboard'))->assertForbidden();
    }

    // ------------------------------------------- must_change_password

    /**
     * Portal berada di luar panel, jadi EnsurePasswordIsChanged tidak berlaku
     * di sini. Aturannya sama dengan portal orang tua (butir 158, 177).
     */
    public function test_a_temporary_password_blocks_the_teacher_portal(): void
    {
        $this->actingAs($this->userIn($this->schoolA, RoleName::Guru, [
            'must_change_password' => true,
        ]));

        $this->get(route('teacher.dashboard'))->assertForbidden();
    }

    public function test_an_existing_session_stops_once_a_reset_is_required(): void
    {
        $this->actingAs($this->teacherA);

        $this->get(route('teacher.dashboard'))->assertOk();

        $this->teacherA->forceFill(['must_change_password' => true])->save();

        $this->get(route('teacher.dashboard'))->assertForbidden();
    }

    // -------------------------------------------------------------- dasbor

    public function test_the_dashboard_shows_today_and_the_active_classes(): void
    {
        $this->actingAs($this->teacherA);

        $response = $this->get(route('teacher.dashboard'))->assertOk();

        $response->assertSee('Jadwal Hari Ini');
        $response->assertSee('Kelas Aktif');
        $response->assertSee('7A');
        $response->assertSee('Matematika');
        // Kelas guru lain tidak muncul.
        $response->assertDontSee('7B');
    }

    /**
     * API 4.11 menyebut "notifikasi masuk". Sampai Sprint 7 dasbor menyatakan
     * keadaan itu belum tersedia; sejak Batch 8.2 keadaannya nyata, dan yang
     * dijaga di sini adalah hilangnya kalimat penangguhan beserta hadirnya
     * tautan ke daftar penuhnya (butir 214).
     */
    public function test_the_dashboard_shows_real_notification_state(): void
    {
        $this->actingAs($this->teacherA);

        $this->get(route('teacher.dashboard'))
            ->assertOk()
            ->assertSee('Notifikasi Masuk')
            ->assertDontSee('Notifikasi belum tersedia')
            ->assertDontSee('Modul notifikasi belum aktif pada tahap ini.')
            ->assertSee('href="'.route('teacher.notifications').'"', false);
    }

    // ---------------------------------------------------------- pintasan

    public function test_the_grade_entry_shortcut_points_at_the_existing_workflow(): void
    {
        $this->actingAs($this->teacherA);

        $this->get(route('teacher.dashboard'))
            ->assertOk()
            ->assertSee('Input Nilai')
            ->assertSee(InputNilai::getUrl(), false);

        // Alur penilaiannya memang boleh dibuka guru.
        $this->assertTrue(InputNilai::canAccess());
    }

    public function test_the_class_students_shortcut_points_at_a_real_page(): void
    {
        $this->actingAs($this->teacherA);

        $this->get(route('teacher.dashboard'))
            ->assertOk()
            ->assertSee(route('teacher.classes'), false);

        $this->get(route('teacher.classes'))
            ->assertOk()
            ->assertSee(route('teacher.class-students', ['classId' => $this->classA->id]), false);
    }

    /**
     * PORTAL-02 meminta pintasan "Buat Pengumuman", tetapi NOTIF-01 memberi
     * pembuatan pengumuman kepada Admin Sekolah dan matriks menandai GURU/WALI
     * ❌. Yang bersifat kewenangan menang: pintasannya tampil tetapi tidak
     * dapat diklik (butir 175).
     */
    public function test_the_announcement_shortcut_is_shown_but_not_clickable(): void
    {
        $this->actingAs($this->teacherA);

        $response = $this->get(route('teacher.dashboard'))->assertOk();

        $response->assertSee('Buat Pengumuman');
        $response->assertSee('kewenangan Admin Sekolah');
        $response->assertSee('aria-disabled="true"', false);
    }

    /**
     * Sprint 8 membuka halaman pengumuman di panel admin, bukan di portal guru.
     * Yang diperiksa karena itu adalah izin gurunya dan rute portalnya — bukan
     * lagi seluruh tabel rute aplikasi (butir 201).
     */
    public function test_no_notification_permission_was_granted_to_teachers(): void
    {
        $this->assertFalse($this->teacherA->can('notification.manage'));
        $this->assertFalse($this->teacherA->can('notification.view'));

        $portalUris = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'teacher.'))
            ->map(fn ($route) => $route->uri());

        foreach ($portalUris as $uri) {
            $this->assertStringNotContainsString('pengumuman', $uri);
            $this->assertStringNotContainsString('notification', $uri);
        }
    }

    // ------------------------------------------------- daftar siswa kelas

    public function test_a_teacher_opens_their_own_class_student_list(): void
    {
        $this->actingAs($this->teacherA);

        $response = $this->get(route('teacher.class-students', ['classId' => $this->classA->id]))
            ->assertOk();

        $response->assertSee('Siswa Kelas A');
        $response->assertDontSee('Siswa Kelas B');
    }

    public function test_only_active_students_are_listed(): void
    {
        $this->enrol($this->classA, 'Sudah Lulus', StudentStatus::Graduated);

        $this->actingAs($this->teacherA);

        $this->get(route('teacher.class-students', ['classId' => $this->classA->id]))
            ->assertOk()
            ->assertSee('Siswa Kelas A')
            ->assertDontSee('Sudah Lulus');
    }

    public function test_another_teachers_class_is_a_404(): void
    {
        $this->actingAs($this->teacherA);

        $this->get(route('teacher.class-students', ['classId' => $this->classB->id]))
            ->assertNotFound();
    }

    public function test_a_class_from_another_branch_is_a_404(): void
    {
        $otherYear = AcademicYear::factory()->create([
            'school_id' => $this->schoolB->id,
            'is_active' => true,
        ]);
        $foreign = $this->classIn('7A', $otherYear, $this->schoolB);

        $this->actingAs($this->teacherA);

        $this->get(route('teacher.class-students', ['classId' => $foreign->id]))
            ->assertNotFound();
    }

    public function test_an_unknown_class_is_a_404(): void
    {
        $this->actingAs($this->teacherA);

        $this->get(route('teacher.class-students', ['classId' => 999999]))->assertNotFound();
    }

    /**
     * SIS-04 poin 2 meminta status kehadiran hari ini; sumbernya belum ada di
     * Phase 1 (butir 152).
     */
    public function test_attendance_is_stated_as_unavailable(): void
    {
        $this->actingAs($this->teacherA);

        $this->get(route('teacher.class-students', ['classId' => $this->classA->id]))
            ->assertOk()
            ->assertSee('Kehadiran belum tersedia')
            ->assertDontSee('Hadir<');
    }

    public function test_the_student_list_shows_no_private_fields(): void
    {
        $this->studentA->forceFill([
            'parent_phone' => '081234567890',
            'address' => 'Jalan Rahasia 1',
        ])->save();

        $response = $this->actingAs($this->teacherA)
            ->get(route('teacher.class-students', ['classId' => $this->classA->id]))
            ->assertOk();

        $response->assertDontSee('081234567890');
        $response->assertDontSee('Jalan Rahasia 1');
    }

    // ------------------------------------------------------------- jadwal

    public function test_the_schedule_page_shows_only_the_teachers_own_lessons(): void
    {
        $mine = ClassSubject::query()->where('teacher_id', $this->teacherA->getKey())->firstOrFail();
        $theirs = ClassSubject::query()->where('teacher_id', $this->teacherB->getKey())->firstOrFail();

        Schedule::factory()->create([
            'school_id' => $this->schoolA->id, 'class_subject_id' => $mine->id,
            'day_of_week' => DayOfWeek::Monday->value,
            'start_time' => '07:00:00', 'end_time' => '08:30:00', 'room' => 'R1',
        ]);
        Schedule::factory()->create([
            'school_id' => $this->schoolA->id, 'class_subject_id' => $theirs->id,
            'day_of_week' => DayOfWeek::Monday->value,
            'start_time' => '09:00:00', 'end_time' => '10:30:00', 'room' => 'R9',
        ]);

        $response = $this->actingAs($this->teacherA)->get(route('teacher.schedule'))->assertOk();

        $response->assertSee('Matematika');
        $response->assertSee('R1');
        $response->assertDontSee('IPA');
        $response->assertDontSee('R9');
    }

    // --------------------------------- pagar baris di panel (butir 176)

    public function test_the_panel_student_list_is_limited_to_taught_classes(): void
    {
        $this->actingAs($this->teacherA);

        $visible = StudentResource::getEloquentQuery()->pluck('full_name')->all();

        $this->assertSame(['Siswa Kelas A'], $visible);
    }

    public function test_a_wali_also_sees_their_homeroom_class_students(): void
    {
        $wali = $this->userIn($this->schoolA, RoleName::WaliKelas);
        $homeroom = $this->classIn('8A');
        $homeroom->forceFill(['homeroom_teacher_id' => $wali->getKey()])->save();

        $this->enrol($homeroom, 'Siswa Perwalian');

        $this->actingAs($wali);

        $visible = StudentResource::getEloquentQuery()->pluck('full_name')->all();

        $this->assertSame(['Siswa Perwalian'], $visible);
    }

    public function test_a_teacher_without_assignments_sees_no_students(): void
    {
        $this->actingAs($this->userIn($this->schoolA, RoleName::Guru));

        $this->assertSame(0, StudentResource::getEloquentQuery()->count());
    }

    public function test_the_panel_schedule_list_is_limited_to_own_lessons(): void
    {
        $mine = ClassSubject::query()->where('teacher_id', $this->teacherA->getKey())->firstOrFail();
        $theirs = ClassSubject::query()->where('teacher_id', $this->teacherB->getKey())->firstOrFail();

        Schedule::factory()->create([
            'school_id' => $this->schoolA->id, 'class_subject_id' => $mine->id,
            'day_of_week' => DayOfWeek::Monday->value,
            'start_time' => '07:00:00', 'end_time' => '08:30:00',
        ]);
        Schedule::factory()->create([
            'school_id' => $this->schoolA->id, 'class_subject_id' => $theirs->id,
            'day_of_week' => DayOfWeek::Monday->value,
            'start_time' => '09:00:00', 'end_time' => '10:30:00',
        ]);

        $this->actingAs($this->teacherA);

        $visible = ScheduleResource::getEloquentQuery()->get();

        $this->assertCount(1, $visible);
        $this->assertSame($mine->id, $visible->first()->class_subject_id);
    }

    /**
     * Peran administratif tetap melihat seluruh cabangnya.
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
    public function test_staff_roles_still_see_every_student_and_schedule(RoleName $role): void
    {
        $mine = ClassSubject::query()->where('teacher_id', $this->teacherA->getKey())->firstOrFail();

        Schedule::factory()->create([
            'school_id' => $this->schoolA->id, 'class_subject_id' => $mine->id,
            'day_of_week' => DayOfWeek::Monday->value,
            'start_time' => '07:00:00', 'end_time' => '08:30:00',
        ]);

        $this->actingAs($this->userIn($this->schoolA, $role));

        $this->assertSame(2, StudentResource::getEloquentQuery()->count());
        $this->assertSame(1, ScheduleResource::getEloquentQuery()->count());
    }

    public function test_a_super_admin_still_sees_everything(): void
    {
        $this->actingAs(User::factory()->withRole(RoleName::SuperAdmin)->create(['school_id' => null]));

        $this->assertSame(2, StudentResource::getEloquentQuery()->count());
    }

    // ------------------------------------------------- branding & mobile

    public function test_the_portal_keeps_the_school_branding_and_mobile_structure(): void
    {
        $this->actingAs($this->teacherA);

        $response = $this->get(route('teacher.dashboard'))->assertOk();

        $response->assertSee('SMP Madani');
        $response->assertSee('--color-primary: #123456', false);
        $response->assertSee('width=device-width, initial-scale=1', false);
        $response->assertSee('grid-template-columns: 1fr', false);
        $response->assertSee('overflow-x: hidden', false);
        $response->assertSee('min-height: 2.75rem', false);
    }

    public function test_the_teacher_navigation_carries_no_parent_menu(): void
    {
        $this->actingAs($this->teacherA);

        $response = $this->get(route('teacher.dashboard'))->assertOk();

        $response->assertSee('Kelas Ajar');
        $response->assertDontSee('Tagihan');
        $response->assertDontSee('Ringkasan');
    }

    /**
     * Guru berbagi sesi dengan panel, jadi keluarnya lewat rute keluar panel —
     * satu login, satu keluar (butir 179).
     */
    public function test_logout_uses_the_shared_panel_session(): void
    {
        $this->actingAs($this->teacherA);

        $this->get(route('teacher.dashboard'))
            ->assertOk()
            ->assertSee(route('filament.admin.auth.logout'), false);

        $this->post(route('filament.admin.auth.logout'))->assertRedirect();

        $this->assertGuest();
    }

    // ------------------------------------------------------------ performa

    public function test_the_class_student_page_does_not_query_per_student(): void
    {
        $this->actingAs($this->teacherA);

        $url = route('teacher.class-students', ['classId' => $this->classA->id]);

        $this->get($url);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->get($url)->assertOk();

        $small = count(DB::getQueryLog());

        for ($i = 0; $i < 8; $i++) {
            $this->enrol($this->classA, "Tambahan {$i}");
        }

        DB::flushQueryLog();

        $this->get($url)->assertOk();

        DB::disableQueryLog();

        $this->assertSame($small, count(DB::getQueryLog()));
    }
}
