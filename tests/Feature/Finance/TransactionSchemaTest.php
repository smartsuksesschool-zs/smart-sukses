<?php

namespace Tests\Feature\Finance;

use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Enums\TransactionType;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\PaymentRecorder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ERD 2.2 — Tabel: transactions. "Buku kas sekolah. Mencatat semua pemasukan
 * dan pengeluaran umum di luar tagihan SPP."
 */
class TransactionSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Kolom ERD, ditambah `deleted_at` — satu-satunya tambahan, dan tambahan
     * yang disengaja: tanpa kolom itu kata "soft delete" pada API 4.9.2 tidak
     * punya tempat penyimpanan sama sekali (butir 128).
     */
    public function test_the_table_has_exactly_the_columns_the_erd_lists_plus_deleted_at(): void
    {
        $this->assertEqualsCanonicalizing([
            'deleted_at',
            'id',
            'school_id',
            'type',
            'category',
            'amount',
            'description',
            'reference_number',
            'proof_url',
            'transaction_date',
            'created_by',
            'created_at',
        ], Schema::getColumnListing('transactions'));
    }

    /**
     * ERD memberi `transactions` hanya `created_at`, persis seperti `payments`.
     */
    public function test_the_table_has_no_updated_at(): void
    {
        $this->assertFalse(Schema::hasColumn('transactions', 'updated_at'));
        $this->assertNull(Transaction::UPDATED_AT);
    }

    /**
     * "Soft delete" dipenuhi dengan cara yang paling kecil: satu `deleted_at`
     * dan trait bawaan Laravel. Tidak ada kosakata baru yang dikarang — tidak
     * status VOID, tidak flag aktif, tidak alasan penghapusan (butir 128).
     */
    public function test_soft_delete_uses_deleted_at_and_invents_nothing_else(): void
    {
        $this->assertTrue(Schema::hasColumn('transactions', 'deleted_at'));

        $this->assertContains(
            SoftDeletes::class,
            class_uses_recursive(Transaction::class),
        );

        foreach (['status', 'is_active', 'voided_at', 'is_deleted', 'void_reason', 'deleted_by'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('transactions', $column),
                "Kolom {$column} tidak ada di ERD dan tidak boleh ditambahkan.",
            );
        }
    }

    public function test_the_type_enum_holds_only_income_and_expense(): void
    {
        $this->assertSame(['INCOME', 'EXPENSE'], TransactionType::values());
        $this->assertCount(2, TransactionType::cases());
    }

    public function test_amount_keeps_two_decimal_places(): void
    {
        $transaction = Transaction::factory()->create(['amount' => '1234567.89']);

        $this->assertSame('1234567.89', (string) $transaction->fresh()->amount);
    }

    public function test_the_model_casts_type_and_date(): void
    {
        $transaction = Transaction::factory()->expense()->create(['transaction_date' => '2026-08-05']);

        $this->assertSame(TransactionType::Expense, $transaction->fresh()->type);
        $this->assertSame('2026-08-05', $transaction->fresh()->transaction_date->toDateString());
    }

    public function test_the_creator_relation_resolves(): void
    {
        $user = User::factory()->create();
        $transaction = Transaction::factory()->create(['created_by' => $user->id]);

        $this->assertSame($user->id, $transaction->fresh()->createdBy->id);
    }

    public function test_the_tenant_scope_applies_to_transactions(): void
    {
        $schoolA = School::factory()->create();
        $schoolB = School::factory()->create();

        $mine = Transaction::factory()->create(['school_id' => $schoolA->id]);
        $theirs = Transaction::factory()->create(['school_id' => $schoolB->id]);

        $this->actingAs(User::factory()->forSchool($schoolA)->create());

        $ids = Transaction::query()->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    /**
     * ERD menyebut buku kas ini "di luar tagihan SPP". Tidak ada kolom
     * penghubung ke `payments`, dan tidak ada relasi Eloquent yang dikarang.
     */
    public function test_transactions_have_no_business_link_to_payments(): void
    {
        foreach (['payment_id', 'student_fee_id', 'student_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('transactions', $column));
        }

        foreach (['payment', 'payments', 'studentFee'] as $relation) {
            $this->assertFalse(
                method_exists(Transaction::class, $relation),
                "Relasi {$relation} tidak ada di ERD dan tidak boleh dibuat.",
            );
        }

        foreach (['transaction', 'transactions'] as $relation) {
            $this->assertFalse(method_exists(Payment::class, $relation));
        }

        $this->assertFalse(Schema::hasColumn('payments', 'transaction_id'));
    }

    /**
     * ERD: buku kas mencatat pemasukan dan pengeluaran "**di luar** tagihan
     * SPP". Cara merekonsiliasi penerimaan SPP ke buku kas belum dijelaskan
     * blueprint, jadi tidak ada yang dikarang: mencatat pembayaran tidak
     * menghasilkan satu pun baris `transactions`.
     */
    public function test_recording_a_payment_creates_no_cash_book_entry(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $school = School::factory()->create();
        $bendahara = User::factory()->forSchool($school)
            ->withRole(RoleName::Bendahara)->create();

        $fee = StudentFee::factory()->create([
            'school_id' => $school->id,
            'student_id' => Student::factory()->create(['school_id' => $school->id])->id,
            'fee_type_id' => FeeType::factory()->create(['school_id' => $school->id])->id,
            'amount' => 1_000_000,
        ]);

        app(PaymentRecorder::class)->record($fee->getKey(), [
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => 400_000,
            'payment_date' => '2026-08-05',
        ], $bendahara);

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_income_and_expense_both_store_a_positive_amount(): void
    {
        $income = Transaction::factory()->income()->create(['amount' => 500_000]);
        $expense = Transaction::factory()->expense()->create(['amount' => 500_000]);

        $this->assertSame('500000.00', (string) $income->fresh()->amount);
        $this->assertSame('500000.00', (string) $expense->fresh()->amount);
        $this->assertTrue($income->isIncome());
        $this->assertFalse($expense->isIncome());
    }
}
