<?php

namespace Tests\Feature\Portal;

use App\Enums\AssessmentType;
use App\Enums\DayOfWeek;
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
use App\Models\ReportCard;
use App\Models\Schedule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentFee;
use App\Models\Subject;
use App\Models\User;
use App\Services\Portal\StudentPortalService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * API 4.11 — /student/dashboard, /student/schedule, /student/grades
 * (PORTAL-03).
 *
 * Yang paling dijaga: identitas siswa **selalu** dari token, tidak pernah dari
 * parameter — dan tidak satu pun endpoint menjadi celah ke data siswa lain
 * maupun ke keuangan, yang memang tertutup bagi SISWA.
 */
class StudentPortalApiTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected AcademicYear $yearA;

    protected User $userA;

    protected User $userB;

    protected Student $studentA;

    protected Student $studentB;

    protected SchoolClass $classA;

    protected SchoolClass $classB;

    protected int $subjectSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create();
        $this->schoolB = School::factory()->create();

        $this->yearA = AcademicYear::factory()->create([
            'school_id' => $this->schoolA->id,
            'is_active' => true,
            'name' => '2026/2027 Semester 1',
            'semester' => 1,
        ]);

        $this->userA = $this->userIn($this->schoolA, RoleName::Siswa);
        $this->userB = $this->userIn($this->schoolA, RoleName::Siswa);

        $this->classA = $this->classIn('7A');
        $this->classB = $this->classIn('7B');

        $this->studentA = $this->studentFor($this->userA, 'Siswa A', $this->classA);
        $this->studentB = $this->studentFor($this->userB, 'Siswa B', $this->classB);
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

    protected function studentFor(?User $user, string $name, ?SchoolClass $class = null, ?School $school = null): Student
    {
        $school ??= $this->schoolA;

        $student = Student::factory()->create([
            'school_id' => $school->id,
            'user_id' => $user?->getKey(),
            'full_name' => $name,
            'status' => StudentStatus::Active->value,
        ]);

        if ($class !== null) {
            StudentClass::factory()->create([
                'school_id' => $school->id,
                'student_id' => $student->id,
                'class_id' => $class->id,
                'academic_year_id' => $class->academic_year_id,
                'status' => StudentClassStatus::Active->value,
            ]);
        }

        return $student;
    }

    protected function classSubject(SchoolClass $class, string $subjectName, ?User $teacher = null): ClassSubject
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
            'teacher_id' => $teacher?->getKey()
                ?? $this->userIn(School::find($class->school_id), RoleName::Guru)->id,
        ]);
    }

    protected function gradeFor(Student $student, ClassSubject $classSubject, float $score, GradeType $type = GradeType::Daily, ?AssessmentType $assessment = null): Grade
    {
        return Grade::factory()->create([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'class_subject_id' => $classSubject->id,
            'academic_year_id' => $classSubject->academic_year_id,
            'grade_type' => $type->value,
            'assessment_type' => $assessment?->value ?? AssessmentType::Formative->value,
            'score' => $score,
        ]);
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
    public static function studentEndpoints(): array
    {
        return [
            'dashboard' => ['dashboard'],
            'schedule' => ['schedule'],
            'grades' => ['grades'],
        ];
    }

    #[DataProvider('studentEndpoints')]
    public function test_each_endpoint_requires_a_token(string $endpoint): void
    {
        $this->getJson("/api/v1/student/{$endpoint}")->assertStatus(401);
    }

    #[DataProvider('studentEndpoints')]
    public function test_a_student_reads_their_own_data(string $endpoint): void
    {
        $this->asUser($this->userA)
            ->getJson("/api/v1/student/{$endpoint}")
            ->assertOk()
            ->assertJsonPath('data.student.id', $this->studentA->id);
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function nonStudentRoles(): array
    {
        return [
            'orang tua' => [RoleName::OrangTua],
            'guru' => [RoleName::Guru],
            'wali kelas' => [RoleName::WaliKelas],
            'school admin' => [RoleName::SchoolAdmin],
            'kepala sekolah' => [RoleName::KepalaSekolah],
            'bendahara' => [RoleName::Bendahara],
        ];
    }

    #[DataProvider('nonStudentRoles')]
    public function test_no_other_role_may_use_the_student_endpoints(RoleName $role): void
    {
        $actor = $this->userIn($this->schoolA, $role);

        foreach (['dashboard', 'schedule', 'grades'] as $endpoint) {
            $this->asUser($actor)->getJson("/api/v1/student/{$endpoint}")->assertStatus(403);
        }
    }

    /**
     * Endpoint ini melekat pada peran, bukan pada tingkat kewenangan
     * (butir 171).
     */
    public function test_a_super_admin_is_refused(): void
    {
        $superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create(['school_id' => null]);

        $this->asUser($superAdmin)->getJson('/api/v1/student/dashboard')->assertStatus(403);
    }

    public function test_a_school_less_student_is_refused(): void
    {
        $orphan = User::factory()->withRole(RoleName::Siswa)->create(['school_id' => null]);

        $this->asUser($orphan)->getJson('/api/v1/student/dashboard')->assertStatus(403);
    }

    public function test_a_deactivated_student_is_refused(): void
    {
        $inactive = $this->userIn($this->schoolA, RoleName::Siswa, ['is_active' => false]);
        $this->studentFor($inactive, 'Nonaktif', $this->classA);

        $this->asUser($inactive)->getJson('/api/v1/student/dashboard')->assertStatus(403);
    }

    /**
     * `students.user_id` boleh NULL, jadi akun yang belum tertaut adalah
     * keadaan wajar — bukan 500, dan bukan alasan mengambil siswa lain
     * (butir 182).
     */
    #[DataProvider('studentEndpoints')]
    public function test_an_account_without_a_linked_student_is_a_404(string $endpoint): void
    {
        $unlinked = $this->userIn($this->schoolA, RoleName::Siswa);

        $this->asUser($unlinked)
            ->getJson("/api/v1/student/{$endpoint}")
            ->assertStatus(404);
    }

    // ------------------------------------------------------ identitas diri

    public function test_a_student_never_resolves_a_peer_even_with_injected_parameters(): void
    {
        $body = $this->asUser($this->userA)
            ->getJson('/api/v1/student/dashboard?student_id='.$this->studentB->id
                .'&nis='.$this->studentB->nis
                .'&nisn='.$this->studentB->nisn)
            ->assertOk();

        $body->assertJsonPath('data.student.id', $this->studentA->id);
        $body->assertJsonPath('data.student.full_name', 'Siswa A');
    }

    public function test_a_link_pointing_at_another_branch_is_not_honoured(): void
    {
        $stray = $this->userIn($this->schoolA, RoleName::Siswa);
        // Tertaut ke akun ini, tetapi datanya di cabang lain.
        $this->studentFor($stray, 'Siswa Nyasar', null, $this->schoolB);

        $this->asUser($stray)->getJson('/api/v1/student/dashboard')->assertStatus(404);
    }

    public function test_the_service_refuses_a_non_student_directly(): void
    {
        $this->expectException(AuthorizationException::class);

        app(StudentPortalService::class)->dashboard($this->userIn($this->schoolA, RoleName::Guru));
    }

    public function test_the_service_cannot_be_pointed_at_another_student(): void
    {
        $service = app(StudentPortalService::class);

        // Satu-satunya argumennya adalah akun; tidak ada jalan memilih siswa.
        $this->assertSame($this->studentA->id, $service->student($this->userA)->getKey());
        $this->assertSame($this->studentB->id, $service->student($this->userB)->getKey());
    }

    // ------------------------------------------------------------ dashboard

    public function test_the_dashboard_reports_the_current_class_and_academic_year(): void
    {
        $body = $this->asUser($this->userA)->getJson('/api/v1/student/dashboard')->assertOk();

        $body->assertJsonPath('data.current_class.name', '7A');
        $body->assertJsonPath('data.academic_year.name', '2026/2027 Semester 1');
        $body->assertJsonPath('data.academic_year.semester', 1);
    }

    public function test_the_dashboard_returns_at_most_five_subjects(): void
    {
        foreach (['MTK', 'IPA', 'IPS', 'BIN', 'SEN', 'OR', 'AGM'] as $name) {
            $this->gradeFor($this->studentA, $this->classSubject($this->classA, $name), 80);
        }

        $this->asUser($this->userA)
            ->getJson('/api/v1/student/dashboard')
            ->assertOk()
            ->assertJsonCount(5, 'data.latest_grades');
    }

    public function test_repeated_assessments_consume_one_subject_slot(): void
    {
        $math = $this->classSubject($this->classA, 'Matematika');

        foreach ([70, 80, 90, 85, 95] as $score) {
            $this->gradeFor($this->studentA, $math, $score);
        }

        $this->gradeFor($this->studentA, $this->classSubject($this->classA, 'IPA'), 88);

        $names = array_column(
            $this->asUser($this->userA)->getJson('/api/v1/student/dashboard')->assertOk()
                ->json('data.latest_grades'),
            'subject_name',
        );

        $this->assertCount(2, $names);
        $this->assertEqualsCanonicalizing(['Matematika', 'IPA'], $names);
    }

    public function test_no_active_academic_year_answers_safely(): void
    {
        $this->yearA->forceFill(['is_active' => false])->save();

        $body = $this->asUser($this->userA)->getJson('/api/v1/student/dashboard')->assertOk();

        $body->assertJsonPath('data.academic_year', null);
        $body->assertJsonPath('data.current_class', null);
        $body->assertJsonPath('data.today_schedule', []);
        $body->assertJsonPath('data.latest_grades', []);
    }

    public function test_a_student_without_a_class_answers_safely(): void
    {
        $loose = $this->userIn($this->schoolA, RoleName::Siswa);
        $this->studentFor($loose, 'Belum Berkelas');

        $body = $this->asUser($loose)->getJson('/api/v1/student/dashboard')->assertOk();

        $body->assertJsonPath('data.current_class', null);
        $body->assertJsonPath('data.today_schedule', []);
    }

    /**
     * API 4.11 meminta notifikasi; subsistemnya milik Sprint 8 (butir 183).
     */
    public function test_notifications_are_reported_as_unavailable_rather_than_zero(): void
    {
        $body = $this->asUser($this->userA)->getJson('/api/v1/student/dashboard')->assertOk();

        $body->assertJsonPath('data.notifications.available', false);
        $body->assertJsonPath('data.notifications.unread_count', null);
        $body->assertJsonPath('data.notifications.items', []);

        $this->assertNotSame(0, $body->json('data.notifications.unread_count'));
    }

    public function test_no_notification_table_or_permission_exists_yet(): void
    {
        $this->assertFalse(Schema::hasTable('notifications'));
        $this->assertFalse($this->userA->can('notification.view'));
        $this->assertFalse($this->userA->can('notification.manage'));
    }

    /**
     * Matriks PRD 1.1.2 — Tagihan SPP: SISWA ❌. Portal siswa tidak boleh
     * membawa satu pun angka keuangan (butir 189).
     */
    #[DataProvider('studentEndpoints')]
    public function test_no_finance_information_leaks_into_any_student_endpoint(string $endpoint): void
    {
        StudentFee::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_id' => $this->studentA->id,
            'fee_type_id' => FeeType::factory()->create(['school_id' => $this->schoolA->id])->id,
            'amount' => '1234567', 'amount_paid' => '0', 'period' => '2026-08',
            'status' => StudentFeeStatus::Unpaid->value,
        ]);

        $encoded = json_encode(
            $this->asUser($this->userA)->getJson("/api/v1/student/{$endpoint}")->assertOk()->json()
        );

        foreach (['tagihan', 'fee', 'outstanding', 'amount_paid', 'payment', '1234567'] as $needle) {
            $this->assertStringNotContainsString($needle, $encoded);
        }
    }

    public function test_private_student_fields_are_not_exposed(): void
    {
        $this->studentA->update(['photo_url' => 'student-photos/1/rahasia.jpg']);

        $body = $this->asUser($this->userA)->getJson('/api/v1/student/dashboard')->assertOk();

        $this->assertSame(
            ['id', 'nis', 'nisn', 'full_name', 'status', 'has_photo', 'school_name'],
            array_keys($body->json('data.student')),
        );

        $encoded = json_encode($body->json());

        foreach (['user_id', 'parent_user_id', 'school_id', 'student-photos', 'rahasia'] as $needle) {
            $this->assertStringNotContainsString($needle, $encoded);
        }
    }

    // -------------------------------------------------------------- jadwal

    public function test_the_schedule_covers_the_students_own_class_only(): void
    {
        $mine = $this->classSubject($this->classA, 'Matematika');
        $theirs = $this->classSubject($this->classB, 'IPA');

        $this->scheduleOn($mine, DayOfWeek::Monday, '07:00:00', '08:30:00', 'R1');
        $this->scheduleOn($theirs, DayOfWeek::Monday, '09:00:00', '10:30:00', 'R9');

        $week = $this->asUser($this->userA)
            ->getJson('/api/v1/student/schedule')->assertOk()->json('data.week');

        $this->assertCount(1, $week);
        $this->assertSame('Matematika', $week[0]['subject_name']);
        $this->assertSame('R1', $week[0]['room']);
    }

    public function test_the_week_is_ordered_by_weekday_then_time(): void
    {
        $mine = $this->classSubject($this->classA, 'Matematika');

        $this->scheduleOn($mine, DayOfWeek::Tuesday, '07:00:00', '08:30:00');
        $this->scheduleOn($mine, DayOfWeek::Monday, '09:00:00', '10:30:00');
        $this->scheduleOn($mine, DayOfWeek::Monday, '07:00:00', '08:30:00');

        $week = $this->asUser($this->userA)
            ->getJson('/api/v1/student/schedule')->assertOk()->json('data.week');

        $this->assertSame(
            [[1, '07:00'], [1, '09:00'], [2, '07:00']],
            array_map(fn ($l) => [$l['day_of_week'], $l['start_time']], $week),
        );
    }

    public function test_today_is_the_subset_of_the_week(): void
    {
        $mine = $this->classSubject($this->classA, 'Matematika');
        $today = $this->todayDay();
        $other = DayOfWeek::from($today->value === 1 ? 2 : 1);

        $this->scheduleOn($mine, $today, '07:00:00', '08:30:00');
        $this->scheduleOn($mine, $other, '09:00:00', '10:30:00');

        $body = $this->asUser($this->userA)->getJson('/api/v1/student/schedule')->assertOk();

        $this->assertCount(2, $body->json('data.week'));
        $this->assertCount(1, $body->json('data.today'));
        $this->assertSame($today->value, $body->json('data.today.0.day_of_week'));
    }

    public function test_a_schedule_from_a_finished_year_is_not_shown(): void
    {
        $oldYear = AcademicYear::factory()->create([
            'school_id' => $this->schoolA->id, 'is_active' => false, 'semester' => 2,
        ]);
        $oldClass = $this->classIn('6A', $oldYear);

        StudentClass::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_id' => $this->studentA->id,
            'class_id' => $oldClass->id,
            'academic_year_id' => $oldYear->id,
            'status' => StudentClassStatus::Active->value,
        ]);

        $this->scheduleOn($this->classSubject($oldClass, 'Lama'), DayOfWeek::Monday, '07:00:00', '08:30:00');

        $body = $this->asUser($this->userA)->getJson('/api/v1/student/schedule')->assertOk();

        $body->assertJsonPath('data.current_class.name', '7A');
        $body->assertJsonPath('data.week', []);
    }

    public function test_only_the_teacher_name_is_exposed(): void
    {
        $teacher = $this->userIn($this->schoolA, RoleName::Guru, [
            'name' => 'Pak Rudi',
            'email' => 'rudi@example.test',
            'phone' => '081234567890',
        ]);

        $this->scheduleOn(
            $this->classSubject($this->classA, 'Matematika', $teacher),
            DayOfWeek::Monday, '07:00:00', '08:30:00',
        );

        $body = $this->asUser($this->userA)->getJson('/api/v1/student/schedule')->assertOk();

        $body->assertJsonPath('data.week.0.teacher_name', 'Pak Rudi');

        $encoded = json_encode($body->json());

        $this->assertStringNotContainsString('rudi@example.test', $encoded);
        $this->assertStringNotContainsString('081234567890', $encoded);
    }

    // --------------------------------------------------------------- nilai

    public function test_grades_are_grouped_per_subject_with_components(): void
    {
        $math = $this->classSubject($this->classA, 'Matematika');
        $this->gradeFor($this->studentA, $math, 80, GradeType::Daily, AssessmentType::Formative);
        $this->gradeFor($this->studentA, $math, 95, GradeType::Final, AssessmentType::Summative);
        $this->gradeFor($this->studentA, $this->classSubject($this->classA, 'IPA'), 75);

        $subjects = collect(
            $this->asUser($this->userA)->getJson('/api/v1/student/grades')->assertOk()->json('data.subjects')
        )->keyBy('subject_name');

        $this->assertCount(2, $subjects);
        $this->assertCount(2, $subjects['Matematika']['components']);

        $components = $subjects['Matematika']['components'];

        $this->assertEqualsCanonicalizing(
            [GradeType::Daily->value, GradeType::Final->value],
            array_column($components, 'grade_type'),
        );
        $this->assertEqualsCanonicalizing(
            [AssessmentType::Formative->value, AssessmentType::Summative->value],
            array_column($components, 'assessment_type'),
        );
    }

    public function test_another_students_grades_never_appear(): void
    {
        $this->gradeFor($this->studentA, $this->classSubject($this->classA, 'Matematika'), 80);
        $this->gradeFor($this->studentB, $this->classSubject($this->classB, 'Milik B'), 99);

        $names = array_column(
            $this->asUser($this->userA)->getJson('/api/v1/student/grades')->assertOk()->json('data.subjects'),
            'subject_name',
        );

        $this->assertSame(['Matematika'], $names);
    }

    public function test_grades_from_another_year_are_excluded(): void
    {
        $this->gradeFor($this->studentA, $this->classSubject($this->classA, 'Matematika'), 80);

        $oldYear = AcademicYear::factory()->create([
            'school_id' => $this->schoolA->id, 'is_active' => false, 'semester' => 2,
        ]);
        $this->gradeFor($this->studentA, $this->classSubject($this->classIn('6A', $oldYear), 'Lama'), 60);

        $names = array_column(
            $this->asUser($this->userA)->getJson('/api/v1/student/grades')->assertOk()->json('data.subjects'),
            'subject_name',
        );

        $this->assertSame(['Matematika'], $names);
    }

    public function test_the_semester_comes_from_the_academic_year(): void
    {
        $this->yearA->forceFill(['semester' => 2])->save();

        $this->asUser($this->userA)
            ->getJson('/api/v1/student/grades')
            ->assertOk()
            ->assertJsonPath('data.academic_year.semester', 2);
    }

    public function test_reading_grades_mutates_nothing(): void
    {
        $grade = $this->gradeFor($this->studentA, $this->classSubject($this->classA, 'Matematika'), 80);
        $before = $grade->fresh()->getAttributes();

        AuditLog::query()->withoutGlobalScope(SchoolScope::class)->delete();

        $this->asUser($this->userA)->getJson('/api/v1/student/grades')->assertOk();

        $this->assertSame($before, $grade->fresh()->getAttributes());
        $this->assertSame(0, AuditLog::query()->withoutGlobalScope(SchoolScope::class)->count());
    }

    public function test_internal_grade_fields_are_absent(): void
    {
        $this->gradeFor($this->studentA, $this->classSubject($this->classA, 'Matematika'), 80);

        $encoded = json_encode(
            $this->asUser($this->userA)->getJson('/api/v1/student/grades')->assertOk()->json()
        );

        foreach (['grade_config_id', 'graded_by', 'class_subject_id', 'weight'] as $field) {
            $this->assertStringNotContainsString($field, $encoded);
        }
    }

    // ------------------------------------------------------------ rapor PDF

    protected function reportCard(Student $student, bool $published, ?SchoolClass $class = null): ReportCard
    {
        $class ??= $this->classA;

        return ReportCard::factory()->create([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'class_id' => $class->id,
            'academic_year_id' => $class->academic_year_id,
            'final_scores' => ['MTK' => 85],
            'is_published' => $published,
            'published_at' => $published ? CarbonImmutable::now() : null,
        ]);
    }

    public function test_a_published_report_card_is_shown_and_downloadable(): void
    {
        $reportCard = $this->reportCard($this->studentA, published: true);

        $this->asUser($this->userA)
            ->getJson('/api/v1/student/grades')
            ->assertOk()
            ->assertJsonPath('data.report_card.id', $reportCard->id);

        $this->actingAs($this->userA)
            ->get(route('student.report-card', ['reportCardId' => $reportCard->id]))
            ->assertOk()
            ->assertDownload();
    }

    public function test_a_draft_report_card_is_neither_shown_nor_downloadable(): void
    {
        $draft = $this->reportCard($this->studentA, published: false);

        $this->asUser($this->userA)
            ->getJson('/api/v1/student/grades')
            ->assertOk()
            ->assertJsonPath('data.report_card', null);

        $this->actingAs($this->userA)
            ->get(route('student.report-card', ['reportCardId' => $draft->id]))
            ->assertNotFound();
    }

    public function test_a_peers_report_card_cannot_be_downloaded(): void
    {
        $foreign = $this->reportCard($this->studentB, published: true, class: $this->classB);

        $this->actingAs($this->userA)
            ->get(route('student.report-card', ['reportCardId' => $foreign->id]))
            ->assertNotFound();
    }

    public function test_the_report_card_payload_never_carries_a_storage_path(): void
    {
        $reportCard = $this->reportCard($this->studentA, published: true);
        $reportCard->forceFill(['pdf_path' => 'rapor/1/rahasia.pdf'])->save();

        $encoded = json_encode(
            $this->asUser($this->userA)->getJson('/api/v1/student/grades')->assertOk()->json()
        );

        $this->assertStringNotContainsString('rapor/', $encoded);
        $this->assertStringNotContainsString('pdf_path', $encoded);
    }

    // ---------------------------------------------- penolakan keuangan

    public function test_a_student_cannot_use_the_finance_endpoints(): void
    {
        $fee = StudentFee::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_id' => $this->studentA->id,
            'fee_type_id' => FeeType::factory()->create(['school_id' => $this->schoolA->id])->id,
            'amount' => '1000000', 'amount_paid' => '0', 'period' => '2026-08',
            'status' => StudentFeeStatus::Unpaid->value,
        ]);

        // Matriks: Tagihan SPP ❌ dan Catat Pembayaran ❌ untuk SISWA.
        $this->asUser($this->userA)->getJson('/api/v1/student-fees')->assertStatus(403);
        $this->asUser($this->userA)->getJson('/api/v1/payments')->assertStatus(403);
        // Tagihan ini memang miliknya, jadi barisnya lolos pagar baris
        // (StudentVisibility) dan penolakannya datang dari izin: 403. Tagihan
        // milik siswa lain disaring lebih dulu dan menjadi 404, sehingga
        // keberadaannya tidak terkonfirmasi (butir 170) — diuji di bawah.
        $this->asUser($this->userA)->getJson('/api/v1/student-fees/'.$fee->id)->assertStatus(403);

        $peerFee = StudentFee::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_id' => $this->studentB->id,
            'fee_type_id' => FeeType::factory()->create(['school_id' => $this->schoolA->id])->id,
            'amount' => '1000000', 'amount_paid' => '0', 'period' => '2026-08',
            'status' => StudentFeeStatus::Unpaid->value,
        ]);

        $this->asUser($this->userA)->getJson('/api/v1/student-fees/'.$peerFee->id)->assertStatus(404);
    }

    public function test_a_student_cannot_use_the_parent_endpoints(): void
    {
        $this->asUser($this->userA)->getJson('/api/v1/parent/children')->assertStatus(403);
        $this->asUser($this->userA)
            ->getJson("/api/v1/parent/children/{$this->studentB->id}/grades")
            ->assertStatus(403);
    }

    public function test_a_student_cannot_use_the_teacher_endpoints(): void
    {
        $this->asUser($this->userA)->getJson('/api/v1/teacher/dashboard')->assertStatus(403);
        $this->asUser($this->userA)->getJson('/api/v1/teacher/classes')->assertStatus(403);
    }

    // ------------------------------------------------- row-level regression

    /**
     * SISWA memegang `student.view` (matriks — Data Siswa: SISWA ⭕), sehingga
     * izin + cabang saja akan meloloskannya ke seluruh siswa cabang
     * (butir 188).
     */
    public function test_the_student_policy_is_limited_to_the_student_themselves(): void
    {
        $this->assertTrue($this->userA->can('view', $this->studentA));
        $this->assertFalse($this->userA->can('view', $this->studentB));

        $foreign = $this->studentFor(null, 'Siswa Cabang Lain', null, $this->schoolB);

        $this->assertFalse($this->userA->can('view', $foreign));
    }

    public function test_staff_keep_their_existing_student_access(): void
    {
        foreach ([RoleName::SchoolAdmin, RoleName::KepalaSekolah] as $role) {
            $staff = $this->userIn($this->schoolA, $role);

            $this->assertTrue($staff->can('view', $this->studentB), $role->value);
        }

        $superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create(['school_id' => null]);

        $this->assertTrue($superAdmin->can('view', $this->studentB));
    }

    public function test_no_generic_student_grade_or_schedule_api_exists(): void
    {
        $reachable = collect(app('router')->getRoutes())
            ->map(fn ($route) => $route->uri())
            ->filter(fn (string $uri) => str_starts_with($uri, 'api/'))
            ->values()
            ->all();

        // Tidak ada endpoint generik yang dapat dipakai siswa untuk membaca
        // baris milik orang lain; jalur bacanya hanya /student/* miliknya.
        $this->assertNotContains('api/v1/students', $reachable);
        $this->assertNotContains('api/v1/students/{id}', $reachable);
        $this->assertNotContains('api/v1/grades', $reachable);
        $this->assertNotContains('api/v1/schedules', $reachable);
    }

    // ------------------------------------------------------------- performa

    public function test_the_grades_query_count_does_not_follow_the_subjects(): void
    {
        $this->gradeFor($this->studentA, $this->classSubject($this->classA, 'Matematika'), 80);

        $this->asUser($this->userA)->getJson('/api/v1/student/grades');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->asUser($this->userA)->getJson('/api/v1/student/grades')->assertOk();

        $small = count(DB::getQueryLog());

        foreach (['IPA', 'IPS', 'BIN', 'SEN', 'OR'] as $name) {
            $subject = $this->classSubject($this->classA, $name);
            $this->gradeFor($this->studentA, $subject, 85);
            $this->gradeFor($this->studentA, $subject, 75, GradeType::Midterm);
        }

        DB::flushQueryLog();

        $this->asUser($this->userA)->getJson('/api/v1/student/grades')->assertOk();

        DB::disableQueryLog();

        $this->assertSame($small, count(DB::getQueryLog()));
    }

    public function test_the_schedule_query_count_does_not_follow_the_week(): void
    {
        $mine = $this->classSubject($this->classA, 'Matematika');
        $this->scheduleOn($mine, DayOfWeek::Monday, '07:00:00', '08:30:00');

        $this->asUser($this->userA)->getJson('/api/v1/student/schedule');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->asUser($this->userA)->getJson('/api/v1/student/schedule')->assertOk();

        $small = count(DB::getQueryLog());

        foreach ([DayOfWeek::Tuesday, DayOfWeek::Wednesday, DayOfWeek::Thursday] as $day) {
            $this->scheduleOn($mine, $day, '07:00:00', '08:30:00');
            $this->scheduleOn($mine, $day, '09:00:00', '10:30:00');
        }

        DB::flushQueryLog();

        $this->asUser($this->userA)->getJson('/api/v1/student/schedule')->assertOk();

        DB::disableQueryLog();

        $this->assertSame($small, count(DB::getQueryLog()));
    }

    public function test_the_dashboard_query_count_does_not_follow_the_data(): void
    {
        $this->gradeFor($this->studentA, $this->classSubject($this->classA, 'Matematika'), 80);

        $this->asUser($this->userA)->getJson('/api/v1/student/dashboard');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->asUser($this->userA)->getJson('/api/v1/student/dashboard')->assertOk();

        $small = count(DB::getQueryLog());

        foreach (['IPA', 'IPS', 'BIN'] as $name) {
            $this->gradeFor($this->studentA, $this->classSubject($this->classA, $name), 85);
        }

        DB::flushQueryLog();

        $this->asUser($this->userA)->getJson('/api/v1/student/dashboard')->assertOk();

        DB::disableQueryLog();

        $this->assertSame($small, count(DB::getQueryLog()));
    }
}
