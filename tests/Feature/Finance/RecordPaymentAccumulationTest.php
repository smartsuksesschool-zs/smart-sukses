<?php

namespace Tests\Feature\Finance;

use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Enums\StudentFeeStatus;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use App\Services\Finance\PaymentRecorder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SPP-03 poin 3 — "status tagihan otomatis berubah ke PAID/PARTIAL", dan ERD
 * `payments`: "satu tagihan dapat memiliki beberapa pembayaran (cicilan)".
 */
class RecordPaymentAccumulationTest extends TestCase
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
            'amount_paid' => 0,
            'status' => StudentFeeStatus::Unpaid->value,
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

    public function test_a_fee_without_payments_stays_unpaid(): void
    {
        $this->assertSame('0.00', (string) $this->fee->amount_paid);
        $this->assertSame(StudentFeeStatus::Unpaid, $this->fee->status);
    }

    public function test_cash_payment_is_recorded(): void
    {
        $payment = $this->pay(['payment_method' => PaymentMethod::Cash->value, 'amount' => 400_000]);

        $this->assertSame(PaymentMethod::Cash, $payment->payment_method);
        $this->assertSame('400000.00', (string) $payment->amount_paid);
        $this->assertSame('2026-08-05', $payment->payment_date->toDateString());
        $this->assertSame($this->bendahara->id, $payment->received_by);
    }

    public function test_transfer_payment_is_recorded_with_its_reference(): void
    {
        $payment = $this->pay([
            'payment_method' => PaymentMethod::Transfer->value,
            'amount' => 400_000,
            'reference_number' => 'TRF-2026-0001',
        ]);

        $this->assertSame(PaymentMethod::Transfer, $payment->payment_method);
        $this->assertSame('TRF-2026-0001', $payment->reference_number);
    }

    public function test_first_partial_payment_moves_the_fee_to_partial(): void
    {
        $this->pay(['amount' => 400_000]);

        $fee = $this->fee->fresh();

        $this->assertSame('400000.00', (string) $fee->amount_paid);
        $this->assertSame(StudentFeeStatus::Partial, $fee->status);
    }

    /**
     * Invarian batch: 400.000 lalu 600.000 atas tagihan 1.000.000.
     */
    public function test_second_installment_accumulates_and_settles_the_fee(): void
    {
        $this->pay(['amount' => 400_000]);
        $this->pay(['amount' => 600_000]);

        $fee = $this->fee->fresh();

        $this->assertSame('1000000.00', (string) $fee->amount_paid);
        $this->assertSame(StudentFeeStatus::Paid, $fee->status);
        $this->assertSame(2, $fee->payments()->count());
    }

    public function test_exact_settlement_in_one_payment_marks_the_fee_paid(): void
    {
        $this->pay(['amount' => 1_000_000]);

        $this->assertSame(StudentFeeStatus::Paid, $this->fee->fresh()->status);
    }

    public function test_amount_paid_always_equals_the_sum_of_payments(): void
    {
        $this->pay(['amount' => 150_000]);
        $this->pay(['amount' => 250_500]);
        $this->pay(['amount' => 99_500]);

        $fee = $this->fee->fresh();

        $this->assertSame(
            number_format((float) $fee->payments()->sum('amount_paid'), 2, '.', ''),
            (string) $fee->amount_paid,
        );
        $this->assertSame('500000.00', (string) $fee->amount_paid);
    }

    /**
     * Nominal pecahan tidak boleh menyisakan sisa tagihan semu karena aritmetika
     * floating point.
     */
    public function test_decimal_installments_settle_exactly(): void
    {
        $fee = StudentFee::factory()->create([
            'school_id' => $this->school->id,
            'student_id' => Student::factory()->create(['school_id' => $this->school->id])->id,
            'fee_type_id' => FeeType::factory()->create(['school_id' => $this->school->id])->id,
            'amount' => '0.30',
        ]);

        app(PaymentRecorder::class)->record($fee->getKey(), [
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => '0.10',
            'payment_date' => '2026-08-05',
        ], $this->bendahara);

        app(PaymentRecorder::class)->record($fee->getKey(), [
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => '0.20',
            'payment_date' => '2026-08-05',
        ], $this->bendahara);

        $fee->refresh();

        $this->assertSame('0.30', (string) $fee->amount_paid);
        $this->assertSame(StudentFeeStatus::Paid, $fee->status);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidAmounts(): array
    {
        return [
            'nol' => [0],
            'negatif' => [-50_000],
            'bukan angka' => ['seratus ribu'],
        ];
    }

    /**
     * @param  mixed  $amount
     */
    #[DataProvider('invalidAmounts')]
    public function test_non_positive_or_non_numeric_amounts_are_rejected($amount): void
    {
        try {
            $this->pay(['amount' => $amount]);
            $this->fail('Jumlah tidak sah seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(StudentFeeStatus::Unpaid, $this->fee->fresh()->status);
    }

    /**
     * Kelebihan bayar tidak diatur blueprint; yang dijaga adalah invarian yang
     * memang tertulis — `amount_paid` tidak melampaui `amount`.
     */
    public function test_payment_beyond_the_remaining_balance_is_rejected(): void
    {
        $this->pay(['amount' => 900_000]);

        try {
            $this->pay(['amount' => 200_000]);
            $this->fail('Pembayaran melebihi sisa tagihan seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $fee = $this->fee->fresh();

        $this->assertSame('900000.00', (string) $fee->amount_paid);
        $this->assertSame(StudentFeeStatus::Partial, $fee->status);
        $this->assertSame(1, $fee->payments()->count());
    }

    public function test_a_settled_fee_cannot_receive_another_payment(): void
    {
        $this->pay(['amount' => 1_000_000]);

        try {
            $this->pay(['amount' => 1]);
            $this->fail('Tagihan lunas seharusnya menolak pembayaran baru.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $this->assertSame(1, $this->fee->fresh()->payments()->count());
    }

    /**
     * PAYMENT_GATEWAY tetap ada di ERD dan di enum, tetapi tidak dapat dipakai
     * pada pencatatan manual Phase 1.
     */
    public function test_payment_gateway_is_rejected_by_the_phase_one_workflow(): void
    {
        $this->assertContains(
            PaymentMethod::PaymentGateway,
            PaymentMethod::cases(),
            'PAYMENT_GATEWAY harus tetap ada di enum: ia bagian dari ERD.',
        );

        $this->assertNotContains(PaymentMethod::PaymentGateway, PaymentRecorder::allowedMethods());
        $this->assertArrayNotHasKey(
            PaymentMethod::PaymentGateway->value,
            PaymentRecorder::methodOptions(),
        );

        try {
            $this->pay(['payment_method' => PaymentMethod::PaymentGateway->value]);
            $this->fail('PAYMENT_GATEWAY seharusnya ditolak pada Phase 1.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment_method', $e->errors());
        }

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_unknown_payment_methods_are_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->pay(['payment_method' => 'BARTER']);
    }

    public function test_payment_date_is_required(): void
    {
        try {
            $this->pay(['payment_date' => null]);
            $this->fail('Tanggal pembayaran wajib diisi.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment_date', $e->errors());
        }

        $this->assertDatabaseCount('payments', 0);
    }

    /**
     * Bila `amount_paid` pernah menyimpang dari riwayatnya, pencatatan
     * berikutnya mengembalikannya alih-alih meneruskan simpangan itu.
     */
    public function test_a_drifted_amount_paid_is_recomputed_from_the_payment_history(): void
    {
        $this->pay(['amount' => 400_000]);

        // Simpangan disuntikkan tanpa event, meniru data yang rusak dari luar.
        StudentFee::query()->whereKey($this->fee->getKey())->update(['amount_paid' => 999_999]);

        $this->pay(['amount' => 600_000]);

        $fee = $this->fee->fresh();

        $this->assertSame('1000000.00', (string) $fee->amount_paid);
        $this->assertSame(StudentFeeStatus::Paid, $fee->status);
    }

    /**
     * Fungsi status murni: nol → UNPAID, sebagian → PARTIAL, penuh → PAID.
     */
    public function test_status_mapping_is_derived_from_the_accumulated_total(): void
    {
        $this->assertSame(StudentFeeStatus::Unpaid, PaymentRecorder::statusFor('0.00', '1000.00'));
        $this->assertSame(StudentFeeStatus::Partial, PaymentRecorder::statusFor('999.99', '1000.00'));
        $this->assertSame(StudentFeeStatus::Paid, PaymentRecorder::statusFor('1000.00', '1000.00'));
    }
}
