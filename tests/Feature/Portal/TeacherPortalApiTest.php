<?php

namespace Tests\Feature\Portal;

use App\Enums\DayOfWeek;
use App\Enums\RoleName;
use App\Enums\StudentClassStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Schedule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\Portal\TeacherPortalService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * API 4.11 — GET /teacher/dashboard dan GET /teacher/classes (PORTAL-02).
 *
 * Yang paling dijaga: arti "kelas yang diampu" — penugasan mengajar pada tahun
 * ajaran aktif, bukan seluruh kelas cabang, bukan perwalian, dan bukan
 * penugasan tahun lalu.
 */
class TeacherPortalApiTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected AcademicYear $yearA;

    protected User $teacherA;

    protected User $teacherB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create();
        $this->schoolB = School::factory()->create();

        $this->yearA = AcademicYear::factory()->create([
            'school_id' => $this->schoolA->id,
            'is_active' => true,
            'name' => '2026/2027',
        ]);

        $this->teacherA = $this->userIn($this->schoolA, RoleName::Guru, ['name' => 'Pak Rudi']);
        $this->teacherB = $this->userIn($this->schoolA, RoleName::Guru, ['name' => 'Bu Sari']);
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

    protected function classIn(School $school, AcademicYear $year, string $name, array $overrides = []): SchoolClass
    {
        return SchoolClass::factory()->create([
            'school_id' => $school->id,
            'academic_year_id' => $year->id,
            'name' => $name,
            ...$overrides,
        ]);
    }

    protected int $subjectSequence = 0;

    /**
     * Kode mapel diberikan eksplisit: factory-nya memilih nama dari daftar
     * pendek yang unik, dan test performa di bawah membuat lebih banyak mapel
     * daripada isi daftar itu.
     */
    protected function assign(User $teacher, SchoolClass $class, string $subjectName): ClassSubject
    {
        $subject = Subject::factory()->create([
            'school_id' => $class->school_id,
            'name' => $subjectName,
            'code' => 'SUB'.str_pad((string) ++$this->subjectSequence, 3, '0', STR_PAD_LEFT),
        ]);

        return ClassSubject::factory()->create([
            'school_id' => $class->school_id,
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $class->academic_year_id,
            'teacher_id' => $teacher->getKey(),
        ]);
    }

    /**
     * Menugaskan mata pelajaran yang sudah ada. Dipakai test performa: factory
     * mapel memilih nama dari daftar pendek yang unik, dan membuat belasan
     * mapel baru akan menghabiskannya.
     */
    protected function assignExisting(User $teacher, SchoolClass $class, Subject $subject): ClassSubject
    {
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

    protected function scheduleOn(ClassSubject $assignment, DayOfWeek $day, string $start, string $end, ?string $room = null): Schedule
    {
        return Schedule::factory()->create([
            'school_id' => $assignment->school_id,
            'class_subject_id' => $assignment->id,
            'day_of_week' => $day->value,
            'start_time' => $start,
            'end_time' => $end,
            'room' => $room,
        ]);
    }

    protected function todayDay(): DayOfWeek
    {
        $day = (int) CarbonImmutable::now()->dayOfWeek;

        return DayOfWeek::from($day === 0 ? 7 : $day);
    }

    // --------------------------------------------------------------- akses

    /**
     * @return array<string, array{string}>
     */
    public static function teacherEndpoints(): array
    {
        return [
            'dashboard' => ['dashboard'],
            'classes' => ['classes'],
        ];
    }

    #[DataProvider('teacherEndpoints')]
    public function test_each_endpoint_requires_a_token(string $endpoint): void
    {
        $this->getJson("/api/v1/teacher/{$endpoint}")->assertStatus(401);
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function teacherRoles(): array
    {
        return [
            'guru' => [RoleName::Guru],
            'wali kelas' => [RoleName::WaliKelas],
        ];
    }

    #[DataProvider('teacherRoles')]
    public function test_teaching_roles_may_read_both_endpoints(RoleName $role): void
    {
        $teacher = $this->userIn($this->schoolA, $role);

        $this->asUser($teacher)->getJson('/api/v1/teacher/dashboard')->assertOk();
        $this->asUser($teacher)->getJson('/api/v1/teacher/classes')->assertOk();
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
    public function test_no_other_role_may_use_the_teacher_endpoints(RoleName $role): void
    {
        $actor = $this->userIn($this->schoolA, $role);

        $this->asUser($actor)->getJson('/api/v1/teacher/dashboard')->assertStatus(403);
        $this->asUser($actor)->getJson('/api/v1/teacher/classes')->assertStatus(403);
    }

    /**
     * Endpoint ini melekat pada peran, bukan pada tingkat kewenangan: Super
     * Admin tidak mengajar kelas mana pun (butir 171).
     */
    public function test_a_super_admin_is_refused_because_the_endpoint_is_role_specific(): void
    {
        $superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create(['school_id' => null]);

        $this->asUser($superAdmin)->getJson('/api/v1/teacher/dashboard')->assertStatus(403);
    }

    public function test_a_school_less_teacher_is_refused(): void
    {
        $orphan = User::factory()->withRole(RoleName::Guru)->create(['school_id' => null]);

        $this->asUser($orphan)->getJson('/api/v1/teacher/classes')->assertStatus(403);
    }

    public function test_a_deactivated_teacher_is_refused(): void
    {
        $inactive = $this->userIn($this->schoolA, RoleName::Guru, ['is_active' => false]);

        $this->asUser($inactive)->getJson('/api/v1/teacher/classes')->assertStatus(403);
    }

    // ------------------------------------------------------------- kelas

    public function test_only_classes_taught_in_the_active_year_are_listed(): void
    {
        $mine = $this->classIn($this->schoolA, $this->yearA, '7A');
        $this->assign($this->teacherA, $mine, 'Matematika');

        // Kelas guru lain.
        $this->assign($this->teacherB, $this->classIn($this->schoolA, $this->yearA, '7B'), 'IPA');

        // Penugasan tahun lalu.
        $oldYear = AcademicYear::factory()->create([
            'school_id' => $this->schoolA->id,
            'is_active' => false,
        ]);
        $this->assign($this->teacherA, $this->classIn($this->schoolA, $oldYear, '6A'), 'Matematika');

        // Kelas cabang lain.
        $otherYear = AcademicYear::factory()->create([
            'school_id' => $this->schoolB->id,
            'is_active' => true,
        ]);
        $this->assign(
            $this->userIn($this->schoolB, RoleName::Guru),
            $this->classIn($this->schoolB, $otherYear, '7A'),
            'Matematika',
        );

        $body = $this->asUser($this->teacherA)->getJson('/api/v1/teacher/classes')->assertOk();

        $this->assertCount(1, $body->json('data'));
        $body->assertJsonPath('data.0.class.id', $mine->id);
    }

    public function test_a_class_with_two_taught_subjects_appears_once(): void
    {
        $class = $this->classIn($this->schoolA, $this->yearA, '7A');
        $this->assign($this->teacherA, $class, 'Matematika');
        $this->assign($this->teacherA, $class, 'Fisika');

        $body = $this->asUser($this->teacherA)->getJson('/api/v1/teacher/classes')->assertOk();

        $this->assertCount(1, $body->json('data'));
        $this->assertSame(
            ['Fisika', 'Matematika'],
            array_column($body->json('data.0.subjects'), 'name'),
        );
    }

    public function test_the_student_count_only_counts_active_students(): void
    {
        $class = $this->classIn($this->schoolA, $this->yearA, '7A');
        $this->assign($this->teacherA, $class, 'Matematika');

        $this->enrol($class, 'Aktif Satu');
        $this->enrol($class, 'Aktif Dua');
        $this->enrol($class, 'Sudah Lulus', StudentStatus::Graduated);

        $this->asUser($this->teacherA)
            ->getJson('/api/v1/teacher/classes')
            ->assertOk()
            ->assertJsonPath('data.0.student_count', 2);
    }

    public function test_classes_are_ordered_deterministically(): void
    {
        foreach ([['9A', 9], ['7B', 7], ['7A', 7]] as [$name, $level]) {
            $class = $this->classIn($this->schoolA, $this->yearA, $name, ['grade_level' => $level]);
            $this->assign($this->teacherA, $class, 'Mapel '.$name);
        }

        $names = array_map(
            fn ($row) => $row['class']['name'],
            $this->asUser($this->teacherA)->getJson('/api/v1/teacher/classes')->assertOk()->json('data'),
        );

        $this->assertSame(['7A', '7B', '9A'], $names);
    }

    public function test_a_teacher_without_assignments_gets_an_empty_list(): void
    {
        $this->asUser($this->teacherA)
            ->getJson('/api/v1/teacher/classes')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_no_active_academic_year_answers_safely(): void
    {
        $this->assign($this->teacherA, $this->classIn($this->schoolA, $this->yearA, '7A'), 'Matematika');
        $this->yearA->forceFill(['is_active' => false])->save();

        $this->asUser($this->teacherA)
            ->getJson('/api/v1/teacher/classes')
            ->assertOk()
            ->assertJsonPath('data', []);

        $body = $this->asUser($this->teacherA)->getJson('/api/v1/teacher/dashboard')->assertOk();

        $body->assertJsonPath('data.academic_year', null);
        $body->assertJsonPath('data.active_classes', []);
        $body->assertJsonPath('data.today_schedule', []);
    }

    public function test_the_class_payload_carries_no_internal_fields(): void
    {
        $class = $this->classIn($this->schoolA, $this->yearA, '7A', [
            'homeroom_teacher_id' => $this->teacherB->getKey(),
        ]);
        $this->assign($this->teacherA, $class, 'Matematika');

        $body = $this->asUser($this->teacherA)->getJson('/api/v1/teacher/classes')->assertOk();

        $this->assertSame(
            ['id', 'name', 'grade_level', 'room'],
            array_keys($body->json('data.0.class')),
        );

        $encoded = json_encode($body->json());

        foreach (['school_id', 'homeroom_teacher_id', 'academic_year_id', 'teacher_id'] as $field) {
            $this->assertStringNotContainsString($field, $encoded);
        }
    }

    // ------------------------------------------------------------- jadwal

    public function test_todays_schedule_only_contains_todays_lessons(): void
    {
        $class = $this->classIn($this->schoolA, $this->yearA, '7A');
        $assignment = $this->assign($this->teacherA, $class, 'Matematika');

        $today = $this->todayDay();
        $other = DayOfWeek::from($today->value === 1 ? 2 : 1);

        $this->scheduleOn($assignment, $today, '07:00:00', '08:30:00', 'R1');
        $this->scheduleOn($assignment, $other, '09:00:00', '10:30:00');

        $body = $this->asUser($this->teacherA)->getJson('/api/v1/teacher/dashboard')->assertOk();

        $this->assertCount(1, $body->json('data.today_schedule'));
        $body->assertJsonPath('data.today_schedule.0.subject_name', 'Matematika');
        $body->assertJsonPath('data.today_schedule.0.class_name', '7A');
        $body->assertJsonPath('data.today_schedule.0.room', 'R1');
        $body->assertJsonPath('data.today_schedule.0.start_time', '07:00');
        $body->assertJsonPath('data.today.day_of_week', $today->value);
    }

    public function test_todays_schedule_is_ordered_by_time(): void
    {
        $class = $this->classIn($this->schoolA, $this->yearA, '7A');
        $assignment = $this->assign($this->teacherA, $class, 'Matematika');
        $today = $this->todayDay();

        $this->scheduleOn($assignment, $today, '10:00:00', '11:30:00');
        $this->scheduleOn($assignment, $today, '07:00:00', '08:30:00');

        $times = array_column(
            $this->asUser($this->teacherA)->getJson('/api/v1/teacher/dashboard')->assertOk()
                ->json('data.today_schedule'),
            'start_time',
        );

        $this->assertSame(['07:00', '10:00'], $times);
    }

    public function test_another_teachers_schedule_never_appears(): void
    {
        $class = $this->classIn($this->schoolA, $this->yearA, '7A');
        $today = $this->todayDay();

        // Guru lain mengajar di kelas yang sama.
        $this->scheduleOn($this->assign($this->teacherB, $class, 'IPA'), $today, '07:00:00', '08:30:00');
        $this->scheduleOn($this->assign($this->teacherA, $class, 'Matematika'), $today, '09:00:00', '10:30:00');

        $subjects = array_column(
            $this->asUser($this->teacherA)->getJson('/api/v1/teacher/dashboard')->assertOk()
                ->json('data.today_schedule'),
            'subject_name',
        );

        $this->assertSame(['Matematika'], $subjects);
    }

    public function test_a_schedule_from_a_finished_year_never_appears(): void
    {
        $oldYear = AcademicYear::factory()->create([
            'school_id' => $this->schoolA->id,
            'is_active' => false,
        ]);
        $oldClass = $this->classIn($this->schoolA, $oldYear, '6A');
        $this->scheduleOn(
            $this->assign($this->teacherA, $oldClass, 'Pelajaran Lama'),
            $this->todayDay(),
            '07:00:00',
            '08:30:00',
        );

        $this->asUser($this->teacherA)
            ->getJson('/api/v1/teacher/dashboard')
            ->assertOk()
            ->assertJsonPath('data.today_schedule', []);
    }

    // ------------------------------------------------ wali kelas & notifikasi

    /**
     * Perwalian tanpa penugasan mengajar tidak boleh dipalsukan menjadi kelas
     * ajar (butir 172).
     */
    public function test_a_homeroom_class_is_reported_separately_from_teaching_classes(): void
    {
        $wali = $this->userIn($this->schoolA, RoleName::WaliKelas);

        $homeroom = $this->classIn($this->schoolA, $this->yearA, '8A', [
            'homeroom_teacher_id' => $wali->getKey(),
        ]);

        $body = $this->asUser($wali)->getJson('/api/v1/teacher/dashboard')->assertOk();

        $body->assertJsonPath('data.homeroom_class.id', $homeroom->id);
        // Tidak diajarnya, jadi tidak muncul sebagai kelas ajar.
        $body->assertJsonPath('data.active_classes', []);

        $this->asUser($wali)->getJson('/api/v1/teacher/classes')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_a_wali_who_also_teaches_sees_the_class_in_both_places(): void
    {
        $wali = $this->userIn($this->schoolA, RoleName::WaliKelas);

        $homeroom = $this->classIn($this->schoolA, $this->yearA, '8A', [
            'homeroom_teacher_id' => $wali->getKey(),
        ]);
        $this->assign($wali, $homeroom, 'Bahasa Indonesia');

        $body = $this->asUser($wali)->getJson('/api/v1/teacher/dashboard')->assertOk();

        $body->assertJsonPath('data.homeroom_class.id', $homeroom->id);
        $body->assertJsonPath('data.active_classes.0.class.id', $homeroom->id);
    }

    /**
     * API 4.11 meminta "notifikasi masuk"; modulnya milik Sprint 8. Yang
     * dikembalikan keadaan sebenarnya, bukan angka nol (butir 175).
     */
    public function test_notifications_are_reported_as_unavailable_rather_than_zero(): void
    {
        $body = $this->asUser($this->teacherA)->getJson('/api/v1/teacher/dashboard')->assertOk();

        $body->assertJsonPath('data.notifications.available', false);
        $body->assertJsonPath('data.notifications.unread_count', null);
        $body->assertJsonPath('data.notifications.items', []);

        $this->assertNotSame(0, $body->json('data.notifications.unread_count'));
    }

    public function test_no_notification_table_or_permission_was_introduced(): void
    {
        $this->assertFalse(Schema::hasTable('notifications'));
        $this->assertFalse($this->teacherA->can('notification.manage'));
        $this->assertFalse($this->teacherA->can('notification.view'));
    }

    public function test_the_dashboard_does_not_leak_teacher_contact_details(): void
    {
        $teacher = $this->userIn($this->schoolA, RoleName::Guru, [
            'name' => 'Pak Budi',
            'email' => 'budi@example.test',
            'phone' => '081234567890',
        ]);

        $body = $this->asUser($teacher)->getJson('/api/v1/teacher/dashboard')->assertOk();

        $body->assertJsonPath('data.teacher.name', 'Pak Budi');

        $encoded = json_encode($body->json());

        $this->assertStringNotContainsString('budi@example.test', $encoded);
        $this->assertStringNotContainsString('081234567890', $encoded);
        $this->assertStringNotContainsString('school_id', $encoded);
    }

    // ------------------------------------------------ panggilan langsung

    public function test_the_service_refuses_a_non_teacher_directly(): void
    {
        $this->expectException(AuthorizationException::class);

        app(TeacherPortalService::class)->classes($this->userIn($this->schoolA, RoleName::SchoolAdmin));
    }

    public function test_the_service_refuses_a_class_the_teacher_does_not_teach(): void
    {
        $foreign = $this->classIn($this->schoolA, $this->yearA, '7B');
        $this->assign($this->teacherB, $foreign, 'IPA');

        $this->expectException(ModelNotFoundException::class);

        app(TeacherPortalService::class)->teachingClass($this->teacherA, $foreign->getKey());
    }

    // ------------------------------------------------------------- performa

    public function test_the_classes_query_count_does_not_follow_the_assignments(): void
    {
        $class = $this->classIn($this->schoolA, $this->yearA, '7A');
        $this->assign($this->teacherA, $class, 'Matematika');
        $this->enrol($class, 'Siswa Satu');

        $this->asUser($this->teacherA)->getJson('/api/v1/teacher/classes');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->asUser($this->teacherA)->getJson('/api/v1/teacher/classes')->assertOk();

        $small = count(DB::getQueryLog());

        $extraOne = Subject::factory()->create(['school_id' => $this->schoolA->id, 'code' => 'X1']);
        $extraTwo = Subject::factory()->create(['school_id' => $this->schoolA->id, 'code' => 'X2']);

        for ($i = 0; $i < 5; $i++) {
            $another = $this->classIn($this->schoolA, $this->yearA, "8-{$i}");
            $this->assignExisting($this->teacherA, $another, $extraOne);
            $this->assignExisting($this->teacherA, $another, $extraTwo);
            $this->enrol($another, "Siswa {$i}");
        }

        DB::flushQueryLog();

        $this->asUser($this->teacherA)->getJson('/api/v1/teacher/classes')->assertOk();

        DB::disableQueryLog();

        $this->assertSame($small, count(DB::getQueryLog()));
    }

    public function test_the_dashboard_query_count_does_not_follow_the_schedule(): void
    {
        $class = $this->classIn($this->schoolA, $this->yearA, '7A');
        $assignment = $this->assign($this->teacherA, $class, 'Matematika');
        $this->scheduleOn($assignment, $this->todayDay(), '07:00:00', '08:30:00');

        $this->asUser($this->teacherA)->getJson('/api/v1/teacher/dashboard');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->asUser($this->teacherA)->getJson('/api/v1/teacher/dashboard')->assertOk();

        $small = count(DB::getQueryLog());

        foreach ([DayOfWeek::Monday, DayOfWeek::Tuesday, DayOfWeek::Wednesday] as $day) {
            $this->scheduleOn($assignment, $day, '09:00:00', '10:30:00');
            $this->scheduleOn($assignment, $day, '11:00:00', '12:30:00');
        }

        DB::flushQueryLog();

        $this->asUser($this->teacherA)->getJson('/api/v1/teacher/dashboard')->assertOk();

        DB::disableQueryLog();

        $this->assertSame($small, count(DB::getQueryLog()));
    }
}
