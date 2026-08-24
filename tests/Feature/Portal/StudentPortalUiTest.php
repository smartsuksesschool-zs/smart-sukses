<?php

namespace Tests\Feature\Portal;

use App\Enums\GradeType;
use App\Enums\RoleName;
use App\Enums\StudentClassStatus;
use App\Enums\StudentStatus;
use App\Livewire\Student\StudentLogin;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Grade;
use App\Models\ReportCard;
use App\Models\Schedule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\Subject;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Halaman Student Portal, navigasi PORTAL-03, dan keamanan sesinya.
 *
 * Termasuk yang paling penting: tidak ada id siswa di alamat mana pun, sehingga
 * tidak ada yang dapat diganti untuk melihat data siswa lain.
 */
class StudentPortalUiTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected AcademicYear $yearA;

    protected User $userA;

    protected Student $studentA;

    protected SchoolClass $classA;

    protected int $subjectSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create(['name' => 'SMP Madani', 'primary_color' => '#123456']);

        $this->yearA = AcademicYear::factory()->create([
            'school_id' => $this->schoolA->id,
            'is_active' => true,
            'name' => '2026/2027 Semester 1',
            'semester' => 1,
        ]);

        $this->userA = $this->userIn(RoleName::Siswa);
        $this->classA = $this->classIn('7A');
        $this->studentA = $this->studentFor($this->userA, 'Ahmad Fauzi', $this->classA);
    }

    protected function userIn(RoleName $role, array $overrides = []): User
    {
        return User::factory()->forSchool($this->schoolA)->withRole($role)->create($overrides);
    }

    protected function classIn(string $name): SchoolClass
    {
        return SchoolClass::factory()->create([
            'school_id' => $this->schoolA->id,
            'academic_year_id' => $this->yearA->id,
            'name' => $name,
        ]);
    }

    protected function studentFor(?User $user, string $name, ?SchoolClass $class = null): Student
    {
        $student = Student::factory()->create([
            'school_id' => $this->schoolA->id,
            'user_id' => $user?->getKey(),
            'full_name' => $name,
            'status' => StudentStatus::Active->value,
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
            'teacher_id' => $teacher?->getKey() ?? $this->userIn(RoleName::Guru)->id,
        ]);
    }

    // ----------------------------------------------------------- akses web

    /**
     * @return array<string, array{string}>
     */
    public static function studentPages(): array
    {
        return [
            'beranda' => ['student.dashboard'],
            'jadwal' => ['student.schedule'],
            'nilai' => ['student.grades'],
            'profil' => ['student.profile'],
        ];
    }

    #[DataProvider('studentPages')]
    public function test_a_student_reaches_every_page(string $route): void
    {
        $this->actingAs($this->userA);

        $this->get(route($route))->assertOk();
    }

    #[DataProvider('studentPages')]
    public function test_a_guest_is_sent_to_the_student_login(string $route): void
    {
        $this->get(route($route))->assertRedirect(route('student.login'));
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
    public function test_no_other_role_reaches_the_student_portal(RoleName $role): void
    {
        $this->actingAs($this->userIn($role));

        $this->get(route('student.dashboard'))->assertForbidden();
    }

    public function test_a_student_still_cannot_reach_the_admin_panel(): void
    {
        $this->assertFalse($this->userA->canAccessPanel(filament()->getPanel('admin')));
    }

    public function test_an_account_without_a_linked_student_sees_an_explanation(): void
    {
        $this->actingAs($this->userIn(RoleName::Siswa));

        $this->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Akun belum terhubung');
    }

    // ---------------------------------------------------------------- login

    public function test_a_student_signs_in_through_the_portal(): void
    {
        $user = $this->userIn(RoleName::Siswa, [
            'email' => 'siswa@example.test',
            'password' => bcrypt('rahasia123'),
        ]);
        $this->studentFor($user, 'Siswa Login', $this->classA);

        $this->startSession();
        $before = session()->getId();

        Livewire::test(StudentLogin::class)
            ->set('email', 'siswa@example.test')
            ->set('password', 'rahasia123')
            ->call('authenticate')
            ->assertHasNoErrors()
            ->assertRedirect(route('student.dashboard'));

        $this->assertAuthenticatedAs($user);
        // Fiksasi sesi tertutup (butir 157).
        $this->assertNotSame($before, session()->getId());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function refusedAccounts(): array
    {
        return [
            'nonaktif' => [['is_active' => false]],
            'wajib ganti kata sandi' => [['must_change_password' => true]],
            'tanpa cabang' => [['school_id' => null]],
        ];
    }

    #[DataProvider('refusedAccounts')]
    public function test_a_refused_account_is_never_left_authenticated(array $overrides): void
    {
        $this->userIn(RoleName::Siswa, [
            'email' => 'siswa@example.test',
            'password' => bcrypt('rahasia123'),
            ...$overrides,
        ]);

        Livewire::test(StudentLogin::class)
            ->set('email', 'siswa@example.test')
            ->set('password', 'rahasia123')
            ->call('authenticate')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_non_student_cannot_sign_in_here(): void
    {
        $this->userIn(RoleName::Guru, [
            'email' => 'guru@example.test',
            'password' => bcrypt('rahasia123'),
        ]);

        Livewire::test(StudentLogin::class)
            ->set('email', 'guru@example.test')
            ->set('password', 'rahasia123')
            ->call('authenticate')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_unknown_email_and_a_wrong_password_look_the_same(): void
    {
        $this->userIn(RoleName::Siswa, [
            'email' => 'siswa@example.test',
            'password' => bcrypt('rahasia123'),
        ]);

        $wrong = Livewire::test(StudentLogin::class)
            ->set('email', 'siswa@example.test')->set('password', 'salah')
            ->call('authenticate')->errors()->get('email');

        $unknown = Livewire::test(StudentLogin::class)
            ->set('email', 'tidakada@example.test')->set('password', 'apa saja')
            ->call('authenticate')->errors()->get('email');

        $this->assertSame($wrong, $unknown);
        $this->assertGuest();
    }

    public function test_the_login_throttle_stops_at_five_attempts(): void
    {
        $this->userIn(RoleName::Siswa, [
            'email' => 'siswa@example.test',
            'password' => bcrypt('rahasia123'),
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            Livewire::test(StudentLogin::class)
                ->set('email', 'siswa@example.test')->set('password', 'salah')
                ->call('authenticate')->assertHasErrors('email');
        }

        $errors = Livewire::test(StudentLogin::class)
            ->set('email', 'siswa@example.test')->set('password', 'rahasia123')
            ->call('authenticate')->errors()->get('email');

        $this->assertStringContainsString('Terlalu banyak percobaan', $errors[0]);
        $this->assertGuest();
    }

    public function test_no_query_parameter_can_steer_the_redirect(): void
    {
        foreach (['redirect', 'next', 'return', 'intended'] as $parameter) {
            $this->get(route('student.login').'?'.$parameter.'=https://jahat.example.com')
                ->assertOk();
        }
    }

    // -------------------------------------------- must_change_password

    public function test_a_temporary_password_blocks_the_portal(): void
    {
        $user = $this->userIn(RoleName::Siswa, ['must_change_password' => true]);
        $this->studentFor($user, 'Sandi Sementara', $this->classA);

        $this->actingAs($user);

        $this->get(route('student.dashboard'))->assertForbidden();
    }

    public function test_an_existing_session_stops_once_a_reset_is_required(): void
    {
        $this->actingAs($this->userA);

        $this->get(route('student.dashboard'))->assertOk();

        $this->userA->forceFill(['must_change_password' => true])->save();

        $this->get(route('student.dashboard'))->assertForbidden();
    }

    // ------------------------------------------------------------- keluar

    public function test_logout_is_post_only_and_clears_the_session(): void
    {
        $this->actingAs($this->userA);
        $this->startSession();

        $sessionBefore = session()->getId();
        $tokenBefore = session()->token();

        $this->get('/siswa/keluar')->assertStatus(405);

        $this->post(route('student.logout'))->assertRedirect(route('student.login'));

        $this->assertGuest();
        $this->assertNotSame($sessionBefore, session()->getId());
        $this->assertNotSame($tokenBefore, session()->token());
    }

    public function test_the_logout_route_is_csrf_protected(): void
    {
        $route = collect(app('router')->getRoutes())
            ->first(fn ($route) => $route->getName() === 'student.logout');

        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains(
            ValidateCsrfToken::class,
            app('router')->getMiddlewareGroups()['web'],
        );
    }

    // ---------------------------------------------------------- navigasi

    #[DataProvider('studentPages')]
    public function test_the_navigation_carries_the_four_required_menus(string $route): void
    {
        $this->actingAs($this->userA);

        $response = $this->get(route($route))->assertOk();

        foreach (['Jadwal', 'Nilai', 'Notifikasi', 'Profil'] as $menu) {
            $response->assertSee($menu);
        }

        $response->assertSee(route('student.schedule'), false);
        $response->assertSee(route('student.grades'), false);
        $response->assertSee(route('student.profile'), false);
        // Keempat menu PORTAL-03 kini bertautan sungguhan, termasuk Notifikasi.
        $response->assertSee(route('student.notifications'), false);
    }

    /**
     * PORTAL-03 poin 1 meminta empat menu. Sampai Sprint 7 menu Notifikasi
     * tampil tanpa tautan karena subsistemnya belum ada; sejak Batch 8.2
     * halamannya ada, jadi yang dijaga sekarang kebalikannya — menu itu harus
     * benar-benar dapat dibuka, dan tidak ada lagi penanda mati (butir 208).
     */
    public function test_the_notification_menu_is_now_a_live_link(): void
    {
        $this->actingAs($this->userA);

        $response = $this->get(route('student.dashboard'))->assertOk();

        $response->assertSee('href="'.route('student.notifications').'"', false);
        $response->assertDontSee('Notifikasi belum tersedia');
        $response->assertDontSee('Tersedia setelah modul notifikasi aktif');

        // Dan halamannya memang terbuka, bukan hanya tertaut.
        $this->get(route('student.notifications'))->assertOk();
    }

    public function test_the_student_navigation_carries_no_other_portal_menu(): void
    {
        $this->actingAs($this->userA);

        $response = $this->get(route('student.dashboard'))->assertOk();

        $response->assertDontSee('Tagihan');
        $response->assertDontSee('Kelas Ajar');
        $response->assertDontSee('Ringkasan');
        $response->assertDontSee('Input Nilai');
    }

    /**
     * Matriks: Tagihan SPP ❌ untuk SISWA (butir 189).
     */
    #[DataProvider('studentPages')]
    public function test_no_finance_link_or_amount_appears_anywhere(string $route): void
    {
        $this->actingAs($this->userA);

        $response = $this->get(route($route))->assertOk();

        $response->assertDontSee('Tagihan');
        $response->assertDontSee('tagihan');
        $response->assertDontSee('Pembayaran');
        $response->assertDontSee(route('portal.fees'), false);
    }

    // -------------------------------------------------------------- konten

    public function test_the_dashboard_shows_todays_lessons_and_latest_grades(): void
    {
        $math = $this->classSubject($this->classA, 'Matematika');

        $day = (int) CarbonImmutable::now()->dayOfWeek;
        Schedule::factory()->create([
            'school_id' => $this->schoolA->id,
            'class_subject_id' => $math->id,
            'day_of_week' => $day === 0 ? 7 : $day,
            'start_time' => '07:00:00', 'end_time' => '08:30:00', 'room' => 'R1',
        ]);

        Grade::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_id' => $this->studentA->id,
            'class_subject_id' => $math->id,
            'academic_year_id' => $this->yearA->id,
            'grade_type' => GradeType::Daily->value,
            'score' => 82.5,
        ]);

        $response = $this->actingAs($this->userA)->get(route('student.dashboard'))->assertOk();

        $response->assertSee('Ahmad Fauzi');
        $response->assertSee('Jadwal Hari Ini');
        $response->assertSee('Matematika');
        $response->assertSee('R1');
        $response->assertSee('Nilai Terbaru');
        // Satu komponen harian belum membentuk nilai akhir yang lengkap, dan
        // FinalScoreCalculator menyatakannya apa adanya — bukan mengarang
        // angka dari komponen tunggal (butir 151).
        $response->assertSee('belum lengkap');
    }

    public function test_the_grades_page_shows_the_semester_from_the_academic_year(): void
    {
        $this->actingAs($this->userA);

        $this->get(route('student.grades'))
            ->assertOk()
            ->assertSee('Semester 1');
    }

    public function test_the_grades_page_offers_the_report_card_only_when_published(): void
    {
        $draft = ReportCard::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_id' => $this->studentA->id,
            'class_id' => $this->classA->id,
            'academic_year_id' => $this->yearA->id,
            'is_published' => false,
        ]);

        $this->actingAs($this->userA);

        $this->get(route('student.grades'))->assertOk()->assertDontSee('Unduh Rapor');

        $draft->forceFill([
            'is_published' => true,
            'published_at' => CarbonImmutable::now(),
        ])->save();

        $this->get(route('student.grades'))
            ->assertOk()
            ->assertSee('Unduh Rapor')
            ->assertSee(route('student.report-card', ['reportCardId' => $draft->id]), false);
    }

    public function test_the_schedule_page_states_when_there_is_no_class(): void
    {
        $loose = $this->userIn(RoleName::Siswa);
        $this->studentFor($loose, 'Belum Berkelas');

        $this->actingAs($loose);

        $this->get(route('student.schedule'))
            ->assertOk()
            ->assertSee('belum memiliki kelas');
    }

    // --------------------------------------------------------------- profil

    /**
     * Tidak ada id siswa pada alamatnya, sehingga tidak ada yang dapat diganti
     * (butir 186).
     */
    public function test_the_profile_url_carries_no_student_id(): void
    {
        $this->assertSame('/siswa/profil', parse_url(route('student.profile'), PHP_URL_PATH));

        foreach (collect(app('router')->getRoutes())->map(fn ($r) => $r->uri())->all() as $uri) {
            if (str_starts_with($uri, 'siswa/')) {
                $this->assertStringNotContainsString('{studentId}', $uri);
                $this->assertStringNotContainsString('{student}', $uri);
            }
        }
    }

    public function test_the_profile_shows_only_the_students_own_data(): void
    {
        $peer = $this->studentFor($this->userIn(RoleName::Siswa), 'Siswa Lain', $this->classA);
        $this->studentA->forceFill(['nisn' => '1234567890'])->save();

        $response = $this->actingAs($this->userA)->get(route('student.profile'))->assertOk();

        $response->assertSee('Ahmad Fauzi');
        $response->assertSee('1234567890');
        $response->assertSee('7A');
        $response->assertSee('SMP Madani');
        $response->assertDontSee('Siswa Lain');
        $response->assertDontSee((string) $peer->nis);
    }

    public function test_the_profile_hides_internal_relations(): void
    {
        $response = $this->actingAs($this->userA)->get(route('student.profile'))->assertOk();

        $response->assertDontSee('parent_user_id', false);
        $response->assertDontSee('user_id', false);
        $response->assertDontSee('school_id', false);
    }

    // ------------------------------------------------- branding & mobile

    #[DataProvider('studentPages')]
    public function test_every_page_keeps_the_branding_and_mobile_structure(string $route): void
    {
        $this->actingAs($this->userA);

        $response = $this->get(route($route))->assertOk();

        $response->assertSee('SMP Madani');
        $response->assertSee('--color-primary: #123456', false);
        $response->assertSee('width=device-width, initial-scale=1', false);
        $response->assertSee('grid-template-columns: 1fr', false);
        $response->assertSee('overflow-x: hidden', false);
        $response->assertSee('min-height: 2.75rem', false);
    }

    // ------------------------------------------------------------ performa

    public function test_the_profile_page_query_count_is_constant(): void
    {
        $this->actingAs($this->userA);

        $this->get(route('student.profile'));

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->get(route('student.profile'))->assertOk();

        $first = count(DB::getQueryLog());

        for ($i = 0; $i < 5; $i++) {
            $this->studentFor($this->userIn(RoleName::Siswa), "Siswa Lain {$i}", $this->classA);
        }

        DB::flushQueryLog();

        $this->get(route('student.profile'))->assertOk();

        DB::disableQueryLog();

        $this->assertSame($first, count(DB::getQueryLog()));
    }
}
