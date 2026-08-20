<?php

namespace Tests\Feature\Finance;

use App\Enums\FeeFrequency;
use App\Enums\RoleName;
use App\Filament\Resources\FeeTypeResource;
use App\Filament\Resources\FeeTypeResource\Pages\CreateFeeType;
use App\Filament\Resources\FeeTypeResource\Pages\EditFeeType;
use App\Filament\Resources\FeeTypeResource\Pages\ListFeeTypes;
use App\Models\AcademicYear;
use App\Models\FeeType;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Arsitektur 3.2 — isolasi data per `school_id` pada modul Tagihan SPP.
 */
class FeeTypeTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected User $bendaharaA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create();
        $this->schoolB = School::factory()->create();

        $this->bendaharaA = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::Bendahara)->create();
    }

    public function test_tenant_only_sees_its_own_fee_types(): void
    {
        $mine = FeeType::factory()->create(['school_id' => $this->schoolA->id, 'name' => 'SPP A']);
        $foreign = FeeType::factory()->create(['school_id' => $this->schoolB->id, 'name' => 'SPP B']);

        $this->actingAs($this->bendaharaA);

        Livewire::test(ListFeeTypes::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$foreign]);
    }

    /**
     * URL edit milik cabang lain tidak boleh terbuka walaupun id-nya ditebak.
     */
    public function test_direct_url_to_another_tenant_record_is_refused(): void
    {
        $foreign = FeeType::factory()->create(['school_id' => $this->schoolB->id]);

        $this->actingAs($this->bendaharaA);

        // Global scope membuat recordnya tidak dapat di-resolve sama sekali.
        $this->get(FeeTypeResource::getUrl('edit', ['record' => $foreign->getRouteKey()]))
            ->assertNotFound();

        $this->assertFalse($this->bendaharaA->can('view', $foreign));
        $this->assertFalse($this->bendaharaA->can('update', $foreign));
    }

    /**
     * `school_id` tidak pernah dipercaya dari payload klien: field itu hanya
     * dirender untuk Super Admin, dan nilainya diabaikan untuk peran lain.
     */
    public function test_school_id_from_the_payload_is_ignored(): void
    {
        $this->actingAs($this->bendaharaA);

        Livewire::test(CreateFeeType::class)
            ->fillForm([
                'name' => 'SPP',
                'amount' => 150000,
                'frequency' => FeeFrequency::Monthly->value,
            ])
            // Selundupan langsung ke state Livewire, melewati form.
            ->set('data.school_id', $this->schoolB->id)
            ->call('create')
            ->assertHasNoFormErrors();

        $feeType = FeeType::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame($this->schoolA->id, $feeType->school_id);
    }

    public function test_school_id_cannot_be_moved_on_edit(): void
    {
        $feeType = FeeType::factory()->create(['school_id' => $this->schoolA->id]);

        $this->actingAs($this->bendaharaA);

        Livewire::test(EditFeeType::class, ['record' => $feeType->getRouteKey()])
            ->fillForm(['name' => 'SPP Reguler'])
            ->set('data.school_id', $this->schoolB->id)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($this->schoolA->id, $feeType->fresh()->school_id);
    }

    /**
     * Tahun ajaran cabang lain ditolak di lapisan validasi, bukan sekadar
     * disembunyikan dari daftar opsi.
     */
    public function test_academic_year_of_another_tenant_is_rejected(): void
    {
        $foreignYear = AcademicYear::factory()->create(['school_id' => $this->schoolB->id]);

        $this->actingAs($this->bendaharaA);

        Livewire::test(CreateFeeType::class)
            ->fillForm([
                'name' => 'SPP',
                'amount' => 150000,
                'frequency' => FeeFrequency::Monthly->value,
                'academic_year_id' => $foreignYear->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['academic_year_id']);

        $this->assertSame(0, FeeType::query()->withoutGlobalScopes()->count());
    }

    /**
     * Super Admin adalah peran Platform Level dengan `school_id` NULL, sehingga
     * cabangnya harus datang dari form — dan tahun ajaran mengikuti pilihan itu.
     */
    public function test_super_admin_creates_a_fee_type_for_the_chosen_branch(): void
    {
        $yearB = AcademicYear::factory()->create(['school_id' => $this->schoolB->id]);

        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(CreateFeeType::class)
            ->fillForm([
                'school_id' => $this->schoolB->id,
                'name' => 'SPP',
                'amount' => 150000,
                'frequency' => FeeFrequency::Monthly->value,
                'academic_year_id' => $yearB->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $feeType = FeeType::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame($this->schoolB->id, $feeType->school_id);
        $this->assertSame($yearB->id, $feeType->academic_year_id);
    }

    public function test_super_admin_must_choose_a_branch(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(CreateFeeType::class)
            ->fillForm([
                'name' => 'SPP',
                'amount' => 150000,
                'frequency' => FeeFrequency::Monthly->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['school_id']);

        $this->assertSame(0, FeeType::query()->withoutGlobalScopes()->count());
    }

    /**
     * Selector tahun ajaran Super Admin mengikuti cabang terpilih: tahun ajaran
     * cabang lain tetap ditolak walaupun cabang sudah dipilih eksplisit.
     */
    public function test_super_admin_cannot_mix_branches(): void
    {
        $yearA = AcademicYear::factory()->create(['school_id' => $this->schoolA->id]);

        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(CreateFeeType::class)
            ->fillForm([
                'school_id' => $this->schoolB->id,
                'name' => 'SPP',
                'amount' => 150000,
                'frequency' => FeeFrequency::Monthly->value,
                'academic_year_id' => $yearA->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['academic_year_id']);

        $this->assertSame(0, FeeType::query()->withoutGlobalScopes()->count());
    }
}
