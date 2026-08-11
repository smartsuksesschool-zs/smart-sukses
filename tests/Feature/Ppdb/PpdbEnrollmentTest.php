<?php

namespace Tests\Feature\Ppdb;

use App\Enums\PpdbStatus;
use App\Enums\RoleName;
use App\Enums\StudentStatus;
use App\Filament\Resources\PpdbRegistrationResource\Pages\ListPpdbRegistrations;
use App\Models\PpdbRegistration;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PPDB-05 — konversi pendaftar LULUS menjadi siswa aktif.
 * API 4.7 — POST /admin/ppdb/{id}/enroll.
 */
class PpdbEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create(['code' => 'MADANI']);
        $this->admin = User::factory()->forSchool($this->school)->withRole(RoleName::SchoolAdmin)->create();

        $this->actingAs($this->admin);
    }

    protected function passedRegistration(): PpdbRegistration
    {
        return PpdbRegistration::factory()->passed()->create([
            'school_id' => $this->school->id,
            'full_name' => 'Ahmad Fauzi',
            'gender' => 'L',
            'birth_date' => '2010-05-17',
            'parent_name' => 'Bapak Fauzi',
            'parent_phone' => '081234567890',
            'parent_email' => 'ortu@example.test',
        ]);
    }

    public function test_enroll_form_is_prefilled_from_the_registration(): void
    {
        // PPDB-05 poin 1 — data formulir PPDB otomatis mengisi form siswa baru.
        $registration = $this->passedRegistration();

        Livewire::test(ListPpdbRegistrations::class)
            ->mountTableAction('enroll', $registration)
            ->assertTableActionDataSet([
                'full_name' => 'Ahmad Fauzi',
                'gender' => 'L',
                'parent_name' => 'Bapak Fauzi',
                'parent_phone' => '081234567890',
                'parent_email' => 'ortu@example.test',
            ]);
    }

    public function test_enrolling_creates_an_active_student_and_marks_the_registration(): void
    {
        $registration = $this->passedRegistration();

        Livewire::test(ListPpdbRegistrations::class)
            ->callTableAction('enroll', $registration, [
                'nis' => '2026001',
                'nisn' => '1234567890',
                'full_name' => 'Ahmad Fauzi',
                'gender' => 'L',
                'birth_date' => '2010-05-17',
                'parent_name' => 'Bapak Fauzi',
                'parent_phone' => '081234567890',
                'parent_email' => 'ortu@example.test',
                'entry_year' => 2026,
            ])
            ->assertHasNoTableActionErrors();

        $student = Student::query()->where('nis', '2026001')->firstOrFail();

        $this->assertSame($this->school->id, $student->school_id);
        $this->assertSame('Ahmad Fauzi', $student->full_name);
        $this->assertSame(StudentStatus::Active, $student->status);

        $registration->refresh();

        // PPDB-05 poin 3 — status PPDB berubah menjadi ENROLLED.
        $this->assertSame(PpdbStatus::Enrolled, $registration->status);
        $this->assertSame($student->id, $registration->converted_student_id);
    }

    public function test_admin_may_complete_the_data_before_confirming(): void
    {
        // PPDB-05 poin 2 — Admin dapat melengkapi data sebelum konfirmasi.
        $registration = $this->passedRegistration();

        Livewire::test(ListPpdbRegistrations::class)
            ->callTableAction('enroll', $registration, [
                'nis' => '2026002',
                'full_name' => 'Ahmad Fauzi Rahman',
                'gender' => 'L',
                'birth_place' => 'Serang',
                'religion' => 'Islam',
                'address' => 'Jl. Merdeka No. 1',
                'entry_year' => 2026,
            ])
            ->assertHasNoTableActionErrors();

        $student = Student::query()->where('nis', '2026002')->firstOrFail();

        $this->assertSame('Ahmad Fauzi Rahman', $student->full_name);
        $this->assertSame('Serang', $student->birth_place);
        $this->assertSame('Jl. Merdeka No. 1', $student->address);
    }

    public function test_nis_must_be_unique_within_the_school(): void
    {
        // SIS-01 poin 3 — NIS unik dalam satu sekolah.
        Student::factory()->create(['school_id' => $this->school->id, 'nis' => '2026003']);

        $registration = $this->passedRegistration();

        Livewire::test(ListPpdbRegistrations::class)
            ->callTableAction('enroll', $registration, [
                'nis' => '2026003',
                'full_name' => 'Ahmad Fauzi',
                'gender' => 'L',
            ])
            ->assertHasTableActionErrors(['nis']);

        $this->assertSame(PpdbStatus::Passed, $registration->fresh()->status);
    }

    public function test_only_passed_registrations_can_be_enrolled(): void
    {
        foreach ([PpdbStatus::Registered, PpdbStatus::DocumentReview, PpdbStatus::Failed, PpdbStatus::Enrolled] as $status) {
            $registration = PpdbRegistration::factory()->status($status)->create(['school_id' => $this->school->id]);

            $this->assertFalse($this->admin->can('enroll', $registration));

            Livewire::test(ListPpdbRegistrations::class)
                ->assertTableActionHidden('enroll', $registration);
        }
    }

    public function test_a_registration_cannot_be_enrolled_twice(): void
    {
        $registration = $this->passedRegistration();
        $student = Student::factory()->create(['school_id' => $this->school->id]);

        $registration->update(['converted_student_id' => $student->id]);

        $this->assertFalse($this->admin->can('enroll', $registration->fresh()));
    }

    public function test_enrolling_requires_the_student_management_permission(): void
    {
        // Aksi ini membuat record students, jadi izin SIS ikut diperiksa.
        $kepala = User::factory()->forSchool($this->school)->withRole(RoleName::KepalaSekolah)->create();
        $registration = $this->passedRegistration();

        $this->assertFalse($kepala->can('enroll', $registration));
    }
}
