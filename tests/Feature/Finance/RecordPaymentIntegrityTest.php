<?php

namespace Tests\Feature\Finance;

use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Enums\StudentFeeStatus;
use App\Filament\Resources\StudentFeeResource;
use App\Models\AuditLog;
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
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Invarian data pembayaran: pembebasan tidak dapat dibatalkan diam-diam,
 * transaksi tidak meninggalkan setengah pekerjaan, dan akumulasi tidak bisa
 * kehilangan pembayaran karena dua pencatatan bersamaan.
 */
class RecordPaymentIntegrityTest extends TestCase
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
    protected function pay(array $overrides = [], ?StudentFee $fee = null): Payment
    {
        return app(PaymentRecorder::class)->record(($fee ?? $this->fee)->getKey(), [
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => 100_000,
            'payment_date' => '2026-08-05',
            ...$overrides,
        ], $this->bendahara);
    }

    // ---------------------------------------------------------------- WAIVED

    /**
     * UI pembebasan belum ada pada batch ini, tetapi statusnya sudah ada di ERD
     * dan pembayaran tidak boleh membatalkannya diam-diam.
     */
    public function test_a_waived_fee_cannot_receive_a_payment(): void
    {
        $this->fee->forceFill([
            'status' => StudentFeeStatus::Waived->value,
            'waive_reason' => 'Siswa yatim',
        ])->save();

        try {
            $this->pay();
            $this->fail('Tagihan WAIVED seharusnya menolak pembayaran.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $fee = $this->fee->fresh();

        $this->assertSame(StudentFeeStatus::Waived, $fee->status);
        $this->assertSame('0.00', (string) $fee->amount_paid);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_the_record_payment_action_is_hidden_for_a_waived_fee(): void
    {
        $this->fee->forceFill(['status' => StudentFeeStatus::Waived->value])->save();

        $this->actingAs($this->bendahara);

        $this->assertFalse(StudentFeeResource::canRecordPaymentFor($this->fee->fresh()));
    }

    // ----------------------------------------------------------------- AUDIT

    /**
     * Satu pembayaran normal mengubah dua entity bisnis, jadi dua baris audit
     * memang benar: Payment CREATED dan StudentFee UPDATED.
     */
    public function test_a_payment_writes_both_audit_rows(): void
    {
        $this->actingAs($this->bendahara);

        $payment = $this->pay(['amount' => 400_000]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Payment::class,
            'auditable_id' => $payment->id,
            'action' => AuditAction::Created->value,
            'user_id' => $this->bendahara->id,
            'school_id' => $this->school->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => StudentFee::class,
            'auditable_id' => $this->fee->id,
            'action' => AuditAction::Updated->value,
            'user_id' => $this->bendahara->id,
            'school_id' => $this->school->id,
        ]);
    }

    /**
     * Berbeda dari worker penerbitan massal, yang jejak auditnya ber-`user_id`
     * NULL karena antrean tidak punya sesi (butir 56), pencatatan pembayaran
     * selalu punya pencatat yang nyata.
     */
    public function test_the_audit_trail_names_the_actual_recorder(): void
    {
        $this->actingAs($this->bendahara);

        $payment = $this->pay();

        $log = AuditLog::query()
            ->where('auditable_type', Payment::class)
            ->where('auditable_id', $payment->id)
            ->firstOrFail();

        $this->assertSame($this->bendahara->id, $log->user_id);
        $this->assertSame($this->bendahara->id, $payment->received_by);
    }

    public function test_a_rejected_payment_writes_no_audit_row(): void
    {
        $this->actingAs($this->bendahara);

        $before = AuditLog::query()->count();

        try {
            $this->pay(['amount' => 2_000_000]);
        } catch (ValidationException) {
            // diharapkan
        }

        $this->assertSame($before, AuditLog::query()->count());
        $this->assertDatabaseCount('payments', 0);
    }

    // ----------------------------------------------------------- TRANSACTION

    /**
     * Bila pembaruan tagihan gagal setelah pembayaran ditulis, tidak boleh ada
     * Payment yatim yang tertinggal.
     */
    public function test_a_failure_after_insert_rolls_the_payment_back(): void
    {
        $this->actingAs($this->bendahara);

        $before = AuditLog::query()->count();

        StudentFee::updating(function (): void {
            throw new \RuntimeException('gagal memperbarui tagihan');
        });

        try {
            $this->pay(['amount' => 400_000]);
            $this->fail('Kegagalan pembaruan tagihan seharusnya menggagalkan seluruh operasi.');
        } catch (\RuntimeException $e) {
            $this->assertSame('gagal memperbarui tagihan', $e->getMessage());
        } finally {
            StudentFee::flushEventListeners();
            StudentFee::boot();
        }

        $fee = $this->fee->fresh();

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('0.00', (string) $fee->amount_paid);
        $this->assertSame(StudentFeeStatus::Unpaid, $fee->status);
        // Baris audit Payment CREATED ikut tergulung: ia ditulis di dalam
        // transaksi yang sama.
        $this->assertSame($before, AuditLog::query()->count());
    }

    // ----------------------------------------------------------- CONCURRENCY

    /**
     * Pembacaan sisa tagihan harus terjadi di bawah row lock, bukan sebelum
     * transaksi dibuka.
     */
    public function test_the_student_fee_row_is_locked_before_its_balance_is_read(): void
    {
        $this->actingAs($this->bendahara);

        $locked = false;

        DB::listen(function ($query) use (&$locked): void {
            if (str_contains($query->sql, 'student_fees') && str_contains(strtolower($query->sql), 'for update')) {
                $locked = true;
            }
        });

        $this->pay();

        // SQLite tidak mengenal FOR UPDATE, jadi asersinya hanya berlaku pada
        // grammar yang memang menghasilkannya; MySQL adalah target produksi.
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->assertTrue($locked, 'SELECT tagihan harus memakai FOR UPDATE.');
        } else {
            $this->assertFalse($locked);
        }
    }

    public function test_every_payment_runs_inside_a_single_transaction(): void
    {
        $this->actingAs($this->bendahara);

        $depth = null;

        StudentFee::updating(function () use (&$depth): void {
            $depth = DB::transactionLevel();
        });

        $this->pay();

        StudentFee::flushEventListeners();
        StudentFee::boot();

        $this->assertNotNull($depth);
        $this->assertGreaterThanOrEqual(1, $depth);
    }

    /**
     * Dua cicilan berurutan tidak boleh saling menimpa: yang kedua membaca
     * akumulasi hasil yang pertama, bukan nilai basi sebelum keduanya.
     */
    public function test_sequential_installments_never_lose_an_update(): void
    {
        $stale = $this->fee->fresh();

        $this->pay(['amount' => 300_000]);

        // Instance basi sengaja dipakai lagi: layanan meresolusi ulang tagihan
        // dari database, sehingga keadaan lama di memori tidak berpengaruh.
        $this->pay(['amount' => 200_000], $stale);

        $fee = $this->fee->fresh();

        $this->assertSame('500000.00', (string) $fee->amount_paid);
        $this->assertSame(2, $fee->payments()->count());
        $this->assertSame(StudentFeeStatus::Partial, $fee->status);
    }

    /**
     * Dua pencatatan yang masing-masing sah sendiri tetapi bersama-sama
     * melampaui tagihan: yang kedua harus ditolak, bukan menghasilkan
     * `amount_paid` di atas `amount`.
     */
    public function test_two_payments_cannot_together_exceed_the_fee(): void
    {
        $this->pay(['amount' => 700_000]);

        try {
            $this->pay(['amount' => 700_000]);
            $this->fail('Akumulasi di atas nominal tagihan seharusnya ditolak.');
        } catch (ValidationException) {
            // diharapkan
        }

        $fee = $this->fee->fresh();

        $this->assertSame('700000.00', (string) $fee->amount_paid);
        $this->assertTrue(bccomp((string) $fee->amount_paid, (string) $fee->amount, 2) <= 0);
    }
}
