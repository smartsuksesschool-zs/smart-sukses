<?php

namespace Tests\Feature\Finance;

use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Filament\Resources\StudentFeeResource;
use App\Filament\Resources\StudentFeeResource\Pages\ListStudentFees;
use App\Filament\Resources\StudentFeeResource\Pages\ViewStudentFee;
use App\Filament\Resources\StudentFeeResource\RelationManagers\PaymentsRelationManager;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use App\Services\Finance\PaymentRecorder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * API 4.9 — GET /student-fees/{id}: "detail satu tagihan + riwayat pembayaran".
 */
class PaymentHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected User $bendahara;

    protected StudentFee $fee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create();
        $this->bendahara = User::factory()->forSchool($this->school)
            ->withRole(RoleName::Bendahara)->create();

        $this->fee = StudentFee::factory()->create([
            'school_id' => $this->school->id,
            'student_id' => Student::factory()->create(['school_id' => $this->school->id])->id,
            'fee_type_id' => FeeType::factory()->create(['school_id' => $this->school->id])->id,
            'amount' => 1_000_000,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function pay(array $overrides = []): Payment
    {
        return app(PaymentRecorder::class)->record($this->fee->getKey(), [
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => 100_000,
            'payment_date' => '2026-08-05',
            ...$overrides,
        ], $this->bendahara);
    }

    public function test_history_lists_every_installment(): void
    {
        $first = $this->pay(['amount' => 400_000]);
        $second = $this->pay(['amount' => 600_000, 'payment_method' => PaymentMethod::Transfer->value]);

        $this->actingAs($this->bendahara);

        Livewire::test(PaymentsRelationManager::class, [
            'ownerRecord' => $this->fee->fresh(),
            'pageClass' => ViewStudentFee::class,
        ])->assertCanSeeTableRecords([$first, $second]);
    }

    /**
     * Cicilan terbaru lebih dulu.
     */
    public function test_history_shows_the_newest_installment_first(): void
    {
        $first = $this->pay(['amount' => 400_000]);
        $second = $this->pay(['amount' => 600_000]);

        $this->actingAs($this->bendahara);

        Livewire::test(PaymentsRelationManager::class, [
            'ownerRecord' => $this->fee->fresh(),
            'pageClass' => ViewStudentFee::class,
        ])->assertCanSeeTableRecords([$second, $first], inOrder: true);
    }

    /**
     * Riwayat mengikuti tagihannya, dan tagihan cabang lain tidak dapat dibuka
     * sejak awal — sehingga riwayatnya pun tidak.
     */
    public function test_history_is_tenant_safe(): void
    {
        $otherSchool = School::factory()->create();
        $foreignFee = StudentFee::factory()->create([
            'school_id' => $otherSchool->id,
            'student_id' => Student::factory()->create(['school_id' => $otherSchool->id])->id,
            'fee_type_id' => FeeType::factory()->create(['school_id' => $otherSchool->id])->id,
        ]);

        $foreignPayment = Payment::factory()->create([
            'school_id' => $otherSchool->id,
            'student_fee_id' => $foreignFee->id,
            'student_id' => $foreignFee->student_id,
            'received_by' => User::factory()->forSchool($otherSchool)->create()->id,
        ]);

        $mine = $this->pay();

        $this->actingAs($this->bendahara);

        Livewire::test(PaymentsRelationManager::class, [
            'ownerRecord' => $this->fee->fresh(),
            'pageClass' => ViewStudentFee::class,
        ])
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$foreignPayment]);

        $this->get(StudentFeeResource::getUrl('view', ['record' => $foreignFee]))
            ->assertNotFound();
    }

    /**
     * Riwayat pembayaran tidak menyediakan jalur ubah maupun hapus: ERD hanya
     * memberi `payments` sebuah `created_at`, dan API 4.9 tidak memuat
     * PUT/DELETE /payments.
     */
    public function test_history_offers_no_edit_or_delete_path(): void
    {
        $payment = $this->pay();

        $this->actingAs($this->bendahara);

        Livewire::test(PaymentsRelationManager::class, [
            'ownerRecord' => $this->fee->fresh(),
            'pageClass' => ViewStudentFee::class,
        ])
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');

        $this->assertFalse($this->bendahara->can('update', $payment));
        $this->assertFalse($this->bendahara->can('delete', $payment));
    }

    /**
     * Daftar tagihan memuat nama siswa dan jenis tagihan pada setiap baris;
     * tanpa eager load jumlah query-nya tumbuh mengikuti jumlah baris.
     */
    public function test_the_student_fee_list_does_not_query_per_row(): void
    {
        $this->actingAs($this->bendahara);

        $makeFees = function (int $count): void {
            for ($i = 0; $i < $count; $i++) {
                StudentFee::factory()->create([
                    'school_id' => $this->school->id,
                    'student_id' => Student::factory()->create(['school_id' => $this->school->id])->id,
                    'fee_type_id' => FeeType::factory()->create(['school_id' => $this->school->id])->id,
                ]);
            }
        };

        // Render pemanasan: pemuatan izin Spatie dan sesi hanya terjadi sekali,
        // dan menghitungnya akan mengaburkan yang sedang diukur.
        Livewire::test(ListStudentFees::class)->assertSuccessful();

        DB::enableQueryLog();
        Livewire::test(ListStudentFees::class)->assertSuccessful();
        $withOneFee = count(DB::getQueryLog());

        $makeFees(9);
        DB::flushQueryLog();

        Livewire::test(ListStudentFees::class)->assertSuccessful();
        $withTenFees = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $withOneFee,
            $withTenFees,
            'Jumlah query daftar tagihan harus konstan terhadap jumlah baris.',
        );
    }

    /**
     * Riwayat pembayaran sengaja tidak ikut di-eager-load pada daftar: halaman
     * itu hanya menampilkan ringkasannya.
     */
    public function test_the_list_query_eager_loads_only_what_the_list_shows(): void
    {
        $eagerLoads = array_keys(StudentFeeResource::getEloquentQuery()->getEagerLoads());

        $this->assertEqualsCanonicalizing(['student', 'feeType'], $eagerLoads);
    }
}
