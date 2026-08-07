<?php

namespace Tests\Feature\MasterData;

use App\Enums\RoleName;
use App\Enums\StudentStatus;
use App\Filament\Resources\StudentResource;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SIS-01 s/d SIS-04 — pembuatan dan validasi data siswa.
 */
class StudentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create();
        $this->admin = User::factory()->forSchool($this->school)->withRole(RoleName::SchoolAdmin)->create();

        $this->actingAs($this->admin);
    }

    public function test_admin_creates_a_student_in_its_own_school(): void
    {
        Livewire::test(StudentResource\Pages\CreateStudent::class)
            ->fillForm([
                'nis' => '2024001',
                'nisn' => '1234567890',
                'full_name' => 'Ahmad Fauzi',
                'gender' => 'L',
                'status' => StudentStatus::Active->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $student = Student::where('nis', '2024001')->first();

        $this->assertNotNull($student);
        $this->assertSame($this->school->id, $student->school_id);
        $this->assertSame(StudentStatus::Active, $student->status);
    }

    public function test_nisn_must_be_exactly_ten_digits(): void
    {
        // SIS-01 poin 2: validasi format NISN (10 digit angka).
        Livewire::test(StudentResource\Pages\CreateStudent::class)
            ->fillForm([
                'nis' => '2024002',
                'nisn' => '123',
                'full_name' => 'Siswa Uji',
                'gender' => 'P',
                'status' => StudentStatus::Active->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['nisn']);
    }

    public function test_nis_must_be_unique_within_the_school(): void
    {
        Student::factory()->create(['school_id' => $this->school->id, 'nis' => '2024003']);

        Livewire::test(StudentResource\Pages\CreateStudent::class)
            ->fillForm([
                'nis' => '2024003',
                'full_name' => 'Siswa Kembar',
                'gender' => 'L',
                'status' => StudentStatus::Active->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['nis']);
    }

    public function test_the_same_nis_may_exist_in_another_school(): void
    {
        // ERD: NIS "lokal, unik per sekolah" — bukan unik global.
        $other = School::factory()->create();

        Student::factory()->create(['school_id' => $other->id, 'nis' => '2024004']);

        Livewire::test(StudentResource\Pages\CreateStudent::class)
            ->fillForm([
                'nis' => '2024004',
                'full_name' => 'Siswa Cabang Lain',
                'gender' => 'L',
                'status' => StudentStatus::Active->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, Student::query()->withoutGlobalScopes()->where('nis', '2024004')->count());
    }

    public function test_status_change_action_updates_the_student(): void
    {
        $student = Student::factory()->create(['school_id' => $this->school->id]);

        Livewire::test(StudentResource\Pages\ListStudents::class)
            ->callTableAction('changeStatus', $student, ['status' => StudentStatus::Graduated->value]);

        $this->assertSame(StudentStatus::Graduated, $student->fresh()->status);
    }

    public function test_student_list_only_shows_the_current_school(): void
    {
        $own = Student::factory()->create(['school_id' => $this->school->id]);
        $foreign = Student::factory()->create(['school_id' => School::factory()->create()->id]);

        Livewire::test(StudentResource\Pages\ListStudents::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign]);
    }
}
