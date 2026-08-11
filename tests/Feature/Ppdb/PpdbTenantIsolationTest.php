<?php

namespace Tests\Feature\Ppdb;

use App\Enums\RoleName;
use App\Filament\Resources\PpdbRegistrationResource;
use App\Filament\Resources\PpdbRegistrationResource\Pages\ListPpdbRegistrations;
use App\Livewire\Ppdb\RegistrationForm;
use App\Livewire\Ppdb\StatusCheck;
use App\Models\PpdbRegistration;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * AUTH-02 / Arsitektur 3.2 — isolasi data PPDB antar cabang.
 * NFR 1.4: "Data isolation 100% — tidak ada kebocoran data antar tenant."
 */
class PpdbTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create(['code' => 'MADANI']);
        $this->schoolB = School::factory()->create(['code' => 'PUSAT']);
    }

    public function test_admin_only_sees_registrations_of_its_own_school(): void
    {
        $own = PpdbRegistration::factory()->create(['school_id' => $this->schoolA->id]);
        $foreign = PpdbRegistration::factory()->create(['school_id' => $this->schoolB->id]);

        $admin = User::factory()->forSchool($this->schoolA)->withRole(RoleName::SchoolAdmin)->create();

        $this->actingAs($admin);

        $this->assertSame(1, PpdbRegistration::query()->count());

        Livewire::test(ListPpdbRegistrations::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign]);
    }

    public function test_admin_cannot_act_on_a_registration_of_another_school(): void
    {
        $foreign = PpdbRegistration::factory()->passed()->create(['school_id' => $this->schoolB->id]);
        $admin = User::factory()->forSchool($this->schoolA)->withRole(RoleName::SchoolAdmin)->create();

        $this->assertFalse($admin->can('view', $foreign));
        $this->assertFalse($admin->can('changeStatus', $foreign));
        $this->assertFalse($admin->can('generateWaLink', $foreign));
        $this->assertFalse($admin->can('enroll', $foreign));
    }

    public function test_detail_page_of_another_school_is_not_reachable(): void
    {
        $foreign = PpdbRegistration::factory()->create(['school_id' => $this->schoolB->id]);
        $admin = User::factory()->forSchool($this->schoolA)->withRole(RoleName::SchoolAdmin)->create();

        $this->actingAs($admin)
            ->get(PpdbRegistrationResource::getUrl('view', ['record' => $foreign]))
            ->assertNotFound();
    }

    public function test_super_admin_sees_registrations_of_all_schools(): void
    {
        PpdbRegistration::factory()->create(['school_id' => $this->schoolA->id]);
        PpdbRegistration::factory()->create(['school_id' => $this->schoolB->id]);

        $this->actingAs(User::factory()->superAdmin()->create());

        $this->assertSame(2, PpdbRegistration::query()->count());
    }

    public function test_public_registration_is_recorded_under_the_school_in_the_url(): void
    {
        Livewire::test(RegistrationForm::class, ['schoolCode' => 'PUSAT'])
            ->set([
                'full_name' => 'Siti Aminah',
                'gender' => 'P',
                'birth_date' => '2010-01-01',
                'parent_name' => 'Ibu Aminah',
                'parent_phone' => '081234567890',
            ])
            ->call('submit')
            ->assertHasNoErrors();

        $registration = PpdbRegistration::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame($this->schoolB->id, $registration->school_id);
    }

    public function test_public_status_check_is_not_affected_by_a_logged_in_users_tenant(): void
    {
        // Halaman publik menjangkau seluruh cabang; kuncinya nomor pendaftaran + tanggal lahir.
        $foreign = PpdbRegistration::factory()->create([
            'school_id' => $this->schoolB->id,
            'reg_number' => 'PUSAT-2026-0001',
            'birth_date' => '2010-01-01',
        ]);

        $admin = User::factory()->forSchool($this->schoolA)->withRole(RoleName::SchoolAdmin)->create();

        $this->actingAs($admin);

        Livewire::test(StatusCheck::class)
            ->set('regNumber', 'PUSAT-2026-0001')
            ->set('birthDate', '2010-01-01')
            ->call('check')
            ->assertHasNoErrors()
            ->assertSet('result.reg_number', $foreign->reg_number);
    }
}
