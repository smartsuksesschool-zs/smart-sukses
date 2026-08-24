<?php

namespace Tests\Feature\Portal;

use App\Enums\DayOfWeek;
use App\Enums\GradeType;
use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Enums\StudentClassStatus;
use App\Enums\StudentFeeStatus;
use App\Enums\StudentStatus;
use App\Livewire\Portal\ParentDashboard;
use App\Livewire\Portal\ParentFees;
use App\Livewire\Portal\ParentGrades;
use App\Livewire\Portal\ParentSchedule;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentFee;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Halaman Nilai, Tagihan, dan Jadwal di Parent Portal, beserta navigasinya.
 *
 * Yang paling dijaga: satu mekanisme pemilihan anak dipakai keempat halaman,
 * dan tidak satu pun halaman baru menjadi celah ke anak orang lain.
 */
class ParentPortalPagesTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected AcademicYear $yearA;

    protected User $parentA;

    protected Student $childOne;

    protected Student $childTwo;

    protected SchoolClass $classOne;

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

        $this->parentA = $this->userIn($this->schoolA, RoleName::OrangTua);
        $this->childOne = $this->childOf($this->parentA, $this->schoolA, 'Ahmad Fauzi');
        $this->childTwo = $this->childOf($this->parentA, $this->schoolA, 'Budi Santoso');
        $this->classOne = $this->placeInClass($this->childOne, $this->yearA, '7A');
    }

    protected function userIn(School $school, RoleName $role, array $overrides = []): User
    {
        return User::factory()->forSchool($school)->withRole($role)->create($overrides);
    }

    protected function childOf(?User $parent, School $school, string $name): Student
    {
        return Student::factory()->create([
            'school_id' => $school->id,
            'parent_user_id' => $parent?->getKey(),
            'full_name' => $name,
            'status' => StudentStatus::Active->value,
        ]);
    }

    protected function placeInClass(Student $student, AcademicYear $year, string $name): SchoolClass
    {
        $class = SchoolClass::factory()->create([
            'school_id' => $student->school_id,
            'academic_year_id' => $year->id,
            'name' => $name,
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

    protected function classSubject(SchoolClass $class, string $subjectName, ?User $teacher = null): ClassSubject
    {
        $subject = Subject::factory()->create([
            'school_id' => $class->school_id,
            'name' => $subjectName,
        ]);

        return ClassSubject::factory()->create([
            'school_id' => $class->school_id,
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $class->academic_year_id,
            'teacher_id' => $teacher?->getKey() ?? $this->userIn($this->schoolA, RoleName::Guru)->id,
        ]);
    }

    protected function gradeFor(Student $student, ClassSubject $classSubject, float $score): Grade
    {
        return Grade::factory()->create([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'class_subject_id' => $classSubject->id,
            'academic_year_id' => $classSubject->academic_year_id,
            'grade_type' => GradeType::Daily->value,
            'score' => $score,
        ]);
    }

    protected function feeFor(Student $student, string $amount, array $overrides = []): StudentFee
    {
        return StudentFee::factory()->create([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'fee_type_id' => FeeType::factory()->create([
                'school_id' => $student->school_id,
                'name' => 'SPP',
            ])->id,
            'amount' => $amount,
            'amount_paid' => '0',
            'period' => '2026-08',
            'status' => StudentFeeStatus::Unpaid->value,
            ...$overrides,
        ]);
    }

    // ---------------------------------------------------------- navigasi

    /**
     * @return array<string, array{string}>
     */
    public static function portalPages(): array
    {
        return [
            'ringkasan' => ['portal.dashboard'],
            'nilai' => ['portal.grades'],
            'tagihan' => ['portal.fees'],
            'jadwal' => ['portal.schedule'],
        ];
    }

    #[DataProvider('portalPages')]
    public function test_every_page_shows_the_full_navigation(string $route): void
    {
        $this->actingAs($this->parentA);

        $response = $this->get(route($route))->assertOk();

        foreach (['Ringkasan', 'Nilai', 'Tagihan', 'Jadwal'] as $label) {
            $response->assertSee($label);
        }

        $response->assertSee(route('portal.grades'), false);
        $response->assertSee(route('portal.fees'), false);
        $response->assertSee(route('portal.schedule'), false);
    }

    /**
     * Sampai Sprint 7 menu Notifikasi sengaja tidak ada di portal orang tua,
     * karena subsistemnya belum ada dan tautan mati lebih buruk daripada menu
     * yang tidak muncul (butir 168). Sejak Batch 8.2 halamannya ada, jadi
     * menunya hadir sebagai tautan sungguhan — dan menu portal lain tetap tidak
     * pernah muncul di sini (butir 208).
     */
    public function test_the_notification_menu_is_live_and_no_other_portal_menu_appears(): void
    {
        $this->actingAs($this->parentA);

        $response = $this->get(route('portal.dashboard'))->assertOk();

        $response->assertSee('Notifikasi');
        $response->assertSee('href="'.route('portal.notifications').'"', false);
        $response->assertDontSee('Portal Guru');
        $response->assertDontSee('Portal Siswa');
    }

    #[DataProvider('portalPages')]
    public function test_every_page_keeps_the_school_branding(string $route): void
    {
        $this->actingAs($this->parentA);

        $this->get(route($route))
            ->assertOk()
            ->assertSee('SMP Madani')
            ->assertSee('--color-primary: #123456', false);
    }

    #[DataProvider('portalPages')]
    public function test_every_page_is_refused_to_a_non_parent(string $route): void
    {
        $this->actingAs($this->userIn($this->schoolA, RoleName::SchoolAdmin));

        $this->get(route($route))->assertForbidden();
    }

    #[DataProvider('portalPages')]
    public function test_every_page_keeps_the_mobile_first_structure(string $route): void
    {
        $this->actingAs($this->parentA);

        $response = $this->get(route($route))->assertOk();

        $response->assertSee('width=device-width, initial-scale=1', false);
        $response->assertSee('grid-template-columns: 1fr', false);
        $response->assertSee('overflow-x: hidden', false);
        $response->assertSee('min-height: 2.75rem', false);
    }

    // ------------------------------------------------- pemilihan anak

    /**
     * @return array<string, array{class-string}>
     */
    public static function portalComponents(): array
    {
        return [
            'ringkasan' => [ParentDashboard::class],
            'nilai' => [ParentGrades::class],
            'tagihan' => [ParentFees::class],
            'jadwal' => [ParentSchedule::class],
        ];
    }

    #[DataProvider('portalComponents')]
    public function test_a_single_child_is_selected_automatically(string $component): void
    {
        $lonelyParent = $this->userIn($this->schoolA, RoleName::OrangTua);
        $onlyChild = $this->childOf($lonelyParent, $this->schoolA, 'Anak Tunggal');

        $this->actingAs($lonelyParent);

        Livewire::test($component)
            ->assertSet('selectedChildId', $onlyChild->id)
            ->assertDontSee('Pilih anak');
    }

    #[DataProvider('portalComponents')]
    public function test_more_than_one_child_gets_a_switcher(string $component): void
    {
        $this->actingAs($this->parentA);

        Livewire::test($component)
            ->assertSee('Ahmad Fauzi')
            ->assertSee('Budi Santoso')
            ->assertSeeHtml('wire:click="selectChild('.$this->childTwo->id.')"');
    }

    /**
     * Satu pilihan, dipakai bersama keempat halaman — bukan empat pemilih yang
     * berdiri sendiri (butir 167).
     */
    public function test_the_selected_child_is_shared_across_pages(): void
    {
        $this->feeFor($this->childOne, '100000');
        $this->feeFor($this->childTwo, '750000');

        $this->actingAs($this->parentA);

        Livewire::test(ParentFees::class)
            ->call('selectChild', $this->childTwo->id)
            ->assertSee('750.000');

        // Halaman lain membuka anak yang sama tanpa dipilih ulang.
        Livewire::test(ParentGrades::class)
            ->assertSet('selectedChildId', $this->childTwo->id)
            ->assertSee('Budi Santoso');

        Livewire::test(ParentSchedule::class)
            ->assertSet('selectedChildId', $this->childTwo->id);

        Livewire::test(ParentDashboard::class)
            ->assertSet('selectedChildId', $this->childTwo->id);
    }

    #[DataProvider('portalComponents')]
    public function test_a_crafted_child_id_is_ignored_on_every_page(string $component): void
    {
        $otherParent = $this->userIn($this->schoolA, RoleName::OrangTua);
        $foreign = $this->childOf($otherParent, $this->schoolA, 'Anak Orang Lain');

        $this->feeFor($foreign, '999999');

        $this->actingAs($this->parentA);

        Livewire::test($component)
            ->call('selectChild', $foreign->id)
            ->assertSet('selectedChildId', $this->childOne->id)
            ->assertDontSee('Anak Orang Lain')
            ->assertDontSee('999.999');
    }

    #[DataProvider('portalComponents')]
    public function test_a_cross_school_child_is_ignored_on_every_page(string $component): void
    {
        $foreign = $this->childOf(
            $this->userIn($this->schoolB, RoleName::OrangTua),
            $this->schoolB,
            'Anak Cabang Lain',
        );

        $this->actingAs($this->parentA);

        Livewire::test($component)
            ->call('selectChild', $foreign->id)
            ->assertSet('selectedChildId', $this->childOne->id)
            ->assertDontSee('Anak Cabang Lain');
    }

    /**
     * Id yang tersimpan di sesi tetap diperiksa ulang: tautan anak dapat
     * dicabut setelah pilihannya tersimpan.
     */
    public function test_a_stale_session_selection_falls_back_safely(): void
    {
        $this->actingAs($this->parentA);

        Livewire::test(ParentFees::class)->call('selectChild', $this->childTwo->id);

        $this->childTwo->forceFill(['parent_user_id' => null])->save();

        Livewire::test(ParentFees::class)
            ->assertSet('selectedChildId', $this->childOne->id)
            ->assertDontSee('Budi Santoso');
    }

    // ------------------------------------------------------- halaman nilai

    public function test_the_grades_page_shows_the_selected_childs_subjects(): void
    {
        $this->gradeFor($this->childOne, $this->classSubject($this->classOne, 'Matematika'), 82.5);

        $this->actingAs($this->parentA);

        $this->get(route('portal.grades'))
            ->assertOk()
            ->assertSee('Matematika')
            ->assertSee('82,50')
            ->assertSee('2026/2027');
    }

    public function test_the_grades_page_states_when_there_is_no_active_year(): void
    {
        $this->yearA->forceFill(['is_active' => false])->save();

        $this->actingAs($this->parentA);

        $this->get(route('portal.grades'))
            ->assertOk()
            ->assertSee('Belum ada tahun ajaran aktif');
    }

    // ----------------------------------------------------- halaman tagihan

    public function test_the_fees_page_shows_amounts_and_status(): void
    {
        $fee = $this->feeFor($this->childOne, '1000000', [
            'amount_paid' => '400000',
            'status' => StudentFeeStatus::Partial->value,
        ]);

        Payment::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_fee_id' => $fee->id,
            'student_id' => $this->childOne->id,
            'amount_paid' => '400000',
            'payment_date' => '2026-08-05',
            'payment_method' => PaymentMethod::Transfer->value,
            'received_by' => $this->userIn($this->schoolA, RoleName::Bendahara)->id,
            'proof_url' => 'payment-proofs/1/rahasia.pdf',
        ]);

        $this->actingAs($this->parentA);

        $response = $this->get(route('portal.fees'))->assertOk();

        $response->assertSee('SPP');
        $response->assertSee('600.000');
        $response->assertSee('Riwayat pembayaran (1)');
        // Jalur bukti tidak pernah sampai ke halaman.
        $response->assertDontSee('payment-proofs', false);
        $response->assertDontSee('rahasia', false);
    }

    public function test_a_waived_fee_is_shown_distinctly_from_a_paid_one(): void
    {
        $this->feeFor($this->childOne, '1000000', [
            'status' => StudentFeeStatus::Waived->value,
            'waive_reason' => 'Beasiswa penuh',
        ]);

        $this->actingAs($this->parentA);

        $response = $this->get(route('portal.fees'))->assertOk();

        $response->assertSee(StudentFeeStatus::Waived->label());
        $response->assertSee('Beasiswa penuh');
        $response->assertDontSee(StudentFeeStatus::Paid->label());
    }

    // ------------------------------------------------------ halaman jadwal

    public function test_the_schedule_page_lists_the_week(): void
    {
        $teacher = $this->userIn($this->schoolA, RoleName::Guru, ['name' => 'Pak Rudi']);

        Schedule::factory()->create([
            'school_id' => $this->schoolA->id,
            'class_subject_id' => $this->classSubject($this->classOne, 'Matematika', $teacher)->id,
            'day_of_week' => DayOfWeek::Monday->value,
            'start_time' => '07:00:00',
            'end_time' => '08:30:00',
            'room' => 'R1',
        ]);

        $this->actingAs($this->parentA);

        $response = $this->get(route('portal.schedule'))->assertOk();

        $response->assertSee('Jadwal Mingguan');
        $response->assertSee(DayOfWeek::Monday->label());
        $response->assertSee('Matematika');
        $response->assertSee('Pak Rudi');
        $response->assertSee('R1');
        $response->assertSee('07:00');
    }

    public function test_the_schedule_page_states_when_the_child_has_no_class(): void
    {
        $this->actingAs($this->parentA);

        // Anak kedua belum ditempatkan di kelas mana pun.
        Livewire::test(ParentSchedule::class)
            ->call('selectChild', $this->childTwo->id)
            ->assertSee('belum memiliki kelas');
    }

    // ------------------------------------------------------------ regresi

    public function test_logout_is_still_post_only(): void
    {
        $this->actingAs($this->parentA);

        $this->get('/portal/keluar')->assertStatus(405);
        $this->post(route('portal.logout'))->assertRedirect(route('portal.login'));
    }

    public function test_a_parent_still_cannot_reach_the_admin_panel(): void
    {
        $this->assertFalse($this->parentA->canAccessPanel(filament()->getPanel('admin')));
    }

    public function test_an_admin_keeps_panel_access(): void
    {
        $admin = $this->userIn($this->schoolA, RoleName::SchoolAdmin);

        $this->assertTrue($admin->canAccessPanel(filament()->getPanel('admin')));
    }

    /**
     * Memuat halaman tidak boleh menghasilkan satu query per anak.
     */
    public function test_the_initial_page_load_does_not_query_per_child(): void
    {
        $this->actingAs($this->parentA);

        $this->get(route('portal.fees'));

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->get(route('portal.fees'))->assertOk();

        $withTwo = count(DB::getQueryLog());

        for ($i = 0; $i < 6; $i++) {
            $child = $this->childOf($this->parentA, $this->schoolA, "Anak Ke-{$i}");
            $this->placeInClass($child, $this->yearA, "8-{$i}");
        }

        DB::flushQueryLog();

        $this->get(route('portal.fees'))->assertOk();

        DB::disableQueryLog();

        $this->assertSame($withTwo, count(DB::getQueryLog()));
    }
}
