<?php

namespace Tests\Feature\Finance;

use App\Enums\AuditAction;
use App\Enums\FeeFrequency;
use App\Enums\RoleName;
use App\Filament\Resources\FeeTypeResource;
use App\Filament\Resources\FeeTypeResource\Pages\CreateFeeType;
use App\Filament\Resources\FeeTypeResource\Pages\EditFeeType;
use App\Filament\Resources\FeeTypeResource\Pages\ListFeeTypes;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\FeeType;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SPP-01 — "membuat jenis tagihan baru (SPP, Uang Gedung, dll.) dengan jumlah
 * dan frekuensi tertentu", dan "dapat dinonaktifkan tanpa menghapus histori".
 */
class FeeTypeManagementTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected User $bendahara;

    protected AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create();
        $this->bendahara = User::factory()->forSchool($this->school)->withRole(RoleName::Bendahara)->create();
        $this->year = AcademicYear::factory()->create(['school_id' => $this->school->id, 'is_active' => true]);

        $this->actingAs($this->bendahara);
    }

    public function test_bendahara_creates_a_fee_type(): void
    {
        Livewire::test(CreateFeeType::class)
            ->fillForm([
                'name' => 'SPP',
                'amount' => 150000,
                'frequency' => FeeFrequency::Monthly->value,
                'academic_year_id' => $this->year->id,
                'description' => 'Iuran bulanan',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $feeType = FeeType::query()->firstOrFail();

        $this->assertSame('SPP', $feeType->name);
        $this->assertSame('150000.00', $feeType->amount);
        $this->assertSame(FeeFrequency::Monthly, $feeType->frequency);
        $this->assertSame($this->year->id, $feeType->academic_year_id);
        $this->assertSame($this->school->id, $feeType->school_id);
        $this->assertTrue($feeType->is_active);
    }

    /**
     * ERD: "NULL untuk tagihan berulang" — tahun ajaran memang opsional.
     */
    public function test_fee_type_can_be_created_without_an_academic_year(): void
    {
        Livewire::test(CreateFeeType::class)
            ->fillForm([
                'name' => 'Uang Gedung',
                'amount' => 5000000,
                'frequency' => FeeFrequency::Once->value,
                'academic_year_id' => null,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(FeeType::query()->firstOrFail()->academic_year_id);
    }

    public function test_bendahara_edits_a_fee_type(): void
    {
        $feeType = FeeType::factory()->create([
            'school_id' => $this->school->id,
            'name' => 'SPP',
            'amount' => 150000,
        ]);

        Livewire::test(EditFeeType::class, ['record' => $feeType->getRouteKey()])
            ->fillForm([
                'name' => 'SPP Reguler',
                'amount' => 175000,
                'frequency' => FeeFrequency::Yearly->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $feeType->refresh();

        $this->assertSame('SPP Reguler', $feeType->name);
        $this->assertSame('175000.00', $feeType->amount);
        $this->assertSame(FeeFrequency::Yearly, $feeType->frequency);
    }

    /**
     * SPP-01 poin 2 — penonaktifan menggantikan penghapusan.
     */
    public function test_fee_type_can_be_disabled_and_enabled_again(): void
    {
        $feeType = FeeType::factory()->create(['school_id' => $this->school->id]);

        Livewire::test(ListFeeTypes::class)
            ->callTableAction('toggleActive', $feeType);

        $this->assertFalse($feeType->refresh()->is_active);
        // Histori tetap ada — recordnya tidak hilang dari database.
        $this->assertDatabaseHas('fee_types', ['id' => $feeType->id]);

        Livewire::test(ListFeeTypes::class)
            ->callTableAction('toggleActive', $feeType);

        $this->assertTrue($feeType->refresh()->is_active);
    }

    public function test_fee_types_have_no_delete_path_at_all(): void
    {
        $feeType = FeeType::factory()->create(['school_id' => $this->school->id]);

        // Policy menolak secara mutlak, termasuk untuk peran dengan fee.manage.
        $this->assertFalse($this->bendahara->can('delete', $feeType));

        // Tidak ada aksi hapus — baik per baris maupun massal.
        Livewire::test(ListFeeTypes::class)
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');

        // Dan tidak ada rute hapus yang bisa dipanggil langsung.
        $this->assertArrayNotHasKey('delete', FeeTypeResource::getPages());
    }

    public function test_name_is_required(): void
    {
        Livewire::test(CreateFeeType::class)
            ->fillForm([
                'name' => null,
                'amount' => 150000,
                'frequency' => FeeFrequency::Monthly->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required']);

        $this->assertSame(0, FeeType::query()->count());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonPositiveAmounts(): array
    {
        return [
            'nol' => [0],
            'negatif' => [-1],
        ];
    }

    #[DataProvider('nonPositiveAmounts')]
    public function test_amount_must_be_greater_than_zero(mixed $amount): void
    {
        Livewire::test(CreateFeeType::class)
            ->fillForm([
                'name' => 'SPP',
                'amount' => $amount,
                'frequency' => FeeFrequency::Monthly->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['amount']);

        $this->assertSame(0, FeeType::query()->count());
    }

    public function test_amount_must_be_numeric(): void
    {
        Livewire::test(CreateFeeType::class)
            ->fillForm([
                'name' => 'SPP',
                'amount' => 'seratus ribu',
                'frequency' => FeeFrequency::Monthly->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['amount']);

        $this->assertSame(0, FeeType::query()->count());
    }

    /**
     * Frekuensi di luar ENUM ERD ditolak sebelum menyentuh database.
     */
    public function test_frequency_outside_the_enum_is_rejected(): void
    {
        Livewire::test(CreateFeeType::class)
            ->fillForm([
                'name' => 'SPP',
                'amount' => 150000,
                'frequency' => 'WEEKLY',
            ])
            ->call('create')
            ->assertHasFormErrors(['frequency']);

        $this->assertSame(0, FeeType::query()->count());
    }

    public function test_creating_a_fee_type_is_recorded_as_created(): void
    {
        Livewire::test(CreateFeeType::class)
            ->fillForm([
                'name' => 'SPP',
                'amount' => 150000,
                'frequency' => FeeFrequency::Monthly->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $feeType = FeeType::query()->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'school_id' => $this->school->id,
            'user_id' => $this->bendahara->id,
            'action' => AuditAction::Created->value,
            'auditable_type' => FeeType::class,
            'auditable_id' => $feeType->id,
        ]);
    }

    public function test_editing_a_fee_type_is_recorded_as_updated(): void
    {
        $feeType = FeeType::factory()->create(['school_id' => $this->school->id]);

        Livewire::test(EditFeeType::class, ['record' => $feeType->getRouteKey()])
            ->fillForm(['name' => 'SPP Reguler'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('audit_logs', [
            'school_id' => $this->school->id,
            'user_id' => $this->bendahara->id,
            'action' => AuditAction::Updated->value,
            'auditable_type' => FeeType::class,
            'auditable_id' => $feeType->id,
        ]);
    }

    /**
     * Penonaktifan ditulis lewat model, bukan mass update, sehingga event
     * `updated` tetap terpicu dan jejaknya tercatat (butir 46).
     */
    public function test_toggling_active_is_recorded_as_updated(): void
    {
        $feeType = FeeType::factory()->create(['school_id' => $this->school->id]);

        Livewire::test(ListFeeTypes::class)
            ->callTableAction('toggleActive', $feeType);

        $this->assertSame(1, AuditLog::query()
            ->where('auditable_type', FeeType::class)
            ->where('auditable_id', $feeType->id)
            ->where('action', AuditAction::Updated->value)
            ->count());
    }

    public function test_list_can_be_searched_and_filtered(): void
    {
        $spp = FeeType::factory()->create([
            'school_id' => $this->school->id,
            'name' => 'SPP',
            'frequency' => FeeFrequency::Monthly->value,
        ]);

        $gedung = FeeType::factory()->inactive()->create([
            'school_id' => $this->school->id,
            'name' => 'Uang Gedung',
            'frequency' => FeeFrequency::Once->value,
        ]);

        Livewire::test(ListFeeTypes::class)
            ->assertCanSeeTableRecords([$spp, $gedung])
            ->searchTable('Gedung')
            ->assertCanSeeTableRecords([$gedung])
            ->assertCanNotSeeTableRecords([$spp])
            ->searchTable(null)
            ->filterTable('is_active', true)
            ->assertCanSeeTableRecords([$spp])
            ->assertCanNotSeeTableRecords([$gedung]);
    }
}
