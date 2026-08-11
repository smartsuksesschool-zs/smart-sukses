<?php

namespace Tests\Feature\Ppdb;

use App\Enums\PpdbStatus;
use App\Enums\RoleName;
use App\Filament\Resources\PpdbRegistrationResource;
use App\Filament\Resources\PpdbRegistrationResource\Pages\ListPpdbRegistrations;
use App\Models\PpdbRegistration;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PRD 1.1.2 — baris "PPDB Online":
 * SUPER_ADMIN ✅, SCHOOL_ADMIN ✅, KEPALA ⭕, GURU/WALI ❌, BENDAHARA ❌, SISWA ❌, ORTU ❌.
 */
class PpdbRbacTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create();
    }

    protected function userWith(RoleName $role): User
    {
        return User::factory()->forSchool($this->school)->withRole($role)->create();
    }

    public function test_school_admin_has_full_access(): void
    {
        $admin = $this->userWith(RoleName::SchoolAdmin);
        $registration = PpdbRegistration::factory()->passed()->create(['school_id' => $this->school->id]);

        $this->actingAs($admin);

        $this->get(PpdbRegistrationResource::getUrl('index'))->assertSuccessful();

        $this->assertTrue($admin->can('view', $registration));
        $this->assertTrue($admin->can('changeStatus', $registration));
        $this->assertTrue($admin->can('generateWaLink', $registration));
        $this->assertTrue($admin->can('enroll', $registration));
    }

    public function test_super_admin_has_full_access(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $registration = PpdbRegistration::factory()->passed()->create(['school_id' => $this->school->id]);

        $this->actingAs($superAdmin);

        $this->get(PpdbRegistrationResource::getUrl('index'))->assertSuccessful();

        $this->assertTrue($superAdmin->can('changeStatus', $registration));
    }

    public function test_kepala_sekolah_has_read_only_access(): void
    {
        // Matriks: KEPALA ⭕ — akses baca/view saja.
        $kepala = $this->userWith(RoleName::KepalaSekolah);
        $registration = PpdbRegistration::factory()->passed()->create(['school_id' => $this->school->id]);

        $this->actingAs($kepala);

        $this->get(PpdbRegistrationResource::getUrl('index'))->assertSuccessful();

        $this->assertTrue($kepala->can('view', $registration));
        $this->assertFalse($kepala->can('update', $registration));
        $this->assertFalse($kepala->can('changeStatus', $registration));
        $this->assertFalse($kepala->can('enroll', $registration));

        Livewire::test(ListPpdbRegistrations::class)
            ->assertTableActionHidden('changeStatus', $registration)
            ->assertTableActionHidden('waLink', $registration)
            ->assertTableActionHidden('enroll', $registration);
    }

    public function test_roles_without_ppdb_access_are_blocked(): void
    {
        // Matriks: GURU/WALI ❌, BENDAHARA ❌.
        foreach ([RoleName::Guru, RoleName::WaliKelas, RoleName::Bendahara] as $role) {
            $user = $this->userWith($role);
            $registration = PpdbRegistration::factory()->create(['school_id' => $this->school->id]);

            $this->assertFalse($user->can('viewAny', PpdbRegistration::class), $role->value);
            $this->assertFalse($user->can('view', $registration), $role->value);

            $this->actingAs($user)
                ->get(PpdbRegistrationResource::getUrl('index'))
                ->assertForbidden();
        }
    }

    public function test_student_and_parent_have_no_ppdb_access(): void
    {
        // Matriks: SISWA ❌, ORTU ❌.
        foreach ([RoleName::Siswa, RoleName::OrangTua] as $role) {
            $user = $this->userWith($role);
            $registration = PpdbRegistration::factory()->create(['school_id' => $this->school->id]);

            $this->assertFalse($user->can('viewAny', PpdbRegistration::class), $role->value);
            $this->assertFalse($user->can('view', $registration), $role->value);
        }
    }

    public function test_the_ppdb_navigation_item_is_hidden_from_roles_without_access(): void
    {
        $guru = $this->userWith(RoleName::Guru);

        $this->actingAs($guru);

        $this->assertFalse(PpdbRegistrationResource::canViewAny());
    }

    public function test_guest_cannot_reach_the_admin_ppdb_page(): void
    {
        PpdbRegistration::factory()->status(PpdbStatus::Passed)->create(['school_id' => $this->school->id]);

        $this->get(PpdbRegistrationResource::getUrl('index'))->assertRedirect('/admin/login');
    }
}
