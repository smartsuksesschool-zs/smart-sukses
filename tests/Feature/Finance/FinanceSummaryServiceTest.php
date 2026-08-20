<?php

namespace Tests\Feature\Finance;

use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Enums\StudentFeeStatus;
use App\Enums\TransactionType;
use App\Models\FeeType;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\FinanceSummaryService;
use App\Services\Finance\PaymentRecorder;
use App\Services\Finance\StudentFeeWaiver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * KAS-02 poin 1 — "saldo kas, total penerimaan SPP bulan ini, total pengeluaran
 * bulan ini" — dan poin 2 — "grafik tren 6 bulan terakhir".
 */
class FinanceSummaryServiceTest extends TestCase
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

    protected function summarize(string $period = '2026-08', ?School $school = null): array
    {
        return app(FinanceSummaryService::class)
            ->summarize((int) ($school ?? $this->schoolA)->id, $period);
    }

    protected function transaction(TransactionType $type, string $amount, string $date, ?School $school = null): Transaction
    {
        return Transaction::factory()->create([
            'school_id' => ($school ?? $this->schoolA)->id,
            'created_by' => $this->bendaharaA->id,
            'type' => $type->value,
            'amount' => $amount,
            'transaction_date' => $date,
        ]);
    }

    protected function feeFor(School $school, string $amount = '1000000'): StudentFee
    {
        return StudentFee::factory()->create([
            'school_id' => $school->id,
            'student_id' => Student::factory()->create(['school_id' => $school->id])->id,
            'fee_type_id' => FeeType::factory()->create(['school_id' => $school->id])->id,
            'amount' => $amount,
        ]);
    }

    protected function pay(StudentFee $fee, string $amount, string $date, ?User $actor = null): void
    {
        app(PaymentRecorder::class)->record($fee->getKey(), [
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => $amount,
            'payment_date' => $date,
        ], $actor ?? $this->bendaharaA);
    }

    // --------------------------------------------------------- saldo kas

    public function test_cash_balance_is_income_minus_expense(): void
    {
        $this->transaction(TransactionType::Income, '5000000', '2026-08-03');
        $this->transaction(TransactionType::Income, '1000000', '2026-08-20');
        $this->transaction(TransactionType::Expense, '2000000', '2026-08-10');

        $this->assertSame('4000000.00', $this->summarize()['cash_balance']);
    }

    /**
     * Saldo adalah posisi, bukan pergerakan bulan itu: kas dari bulan-bulan
     * sebelumnya tetap terhitung.
     */
    public function test_cash_balance_carries_earlier_months_forward(): void
    {
        $this->transaction(TransactionType::Income, '3000000', '2026-06-15');
        $this->transaction(TransactionType::Expense, '500000', '2026-07-02');
        $this->transaction(TransactionType::Income, '1000000', '2026-08-05');

        $this->assertSame('3500000.00', $this->summarize()['cash_balance']);
    }

    /**
     * Transaksi setelah akhir periode terpilih tidak boleh ikut terhitung —
     * memilih bulan lampau harus menampilkan saldo sebagaimana adanya saat itu.
     */
    public function test_cash_balance_stops_at_the_end_of_the_selected_period(): void
    {
        $this->transaction(TransactionType::Income, '2000000', '2026-08-31');
        $this->transaction(TransactionType::Income, '9000000', '2026-09-01');

        $this->assertSame('2000000.00', $this->summarize('2026-08')['cash_balance']);
        $this->assertSame('11000000.00', $this->summarize('2026-09')['cash_balance']);
    }

    public function test_cash_balance_may_be_negative(): void
    {
        $this->transaction(TransactionType::Expense, '750000', '2026-08-04');

        $this->assertSame('-750000.00', $this->summarize()['cash_balance']);
    }

    /**
     * Saldo hanya membaca `transactions`. Penerimaan SPP tidak ditambahkan ke
     * kas: rekonsiliasinya belum dijelaskan blueprint (butir 75).
     */
    public function test_cash_balance_ignores_payments_entirely(): void
    {
        $this->pay($this->feeFor($this->schoolA), '400000', '2026-08-05');

        $summary = $this->summarize();

        $this->assertSame('0.00', $summary['cash_balance']);
        $this->assertSame('400000.00', $summary['spp_received']);
        $this->assertDatabaseCount('transactions', 0);
    }

    // ------------------------------------------------------- penerimaan SPP

    public function test_spp_received_sums_actual_payments(): void
    {
        $fee = $this->feeFor($this->schoolA);

        $this->pay($fee, '400000', '2026-08-05');
        $this->pay($fee, '250000', '2026-08-20');

        $this->assertSame('650000.00', $this->summarize()['spp_received']);
    }

    /**
     * Penyaringnya `payment_date`, bukan periode tagihannya.
     */
    public function test_spp_received_filters_by_payment_date(): void
    {
        $fee = $this->feeFor($this->schoolA);

        $this->pay($fee, '400000', '2026-07-28');
        $this->pay($fee, '300000', '2026-08-01');
        $this->pay($fee, '100000', '2026-09-02');

        $this->assertSame('400000.00', $this->summarize('2026-07')['spp_received']);
        $this->assertSame('300000.00', $this->summarize('2026-08')['spp_received']);
        $this->assertSame('100000.00', $this->summarize('2026-09')['spp_received']);
    }

    /**
     * Tagihan yang dibebaskan tidak punya baris `payments` sama sekali,
     * sehingga tidak pernah terhitung sebagai penerimaan.
     */
    public function test_a_waived_fee_adds_nothing_to_receipts(): void
    {
        $admin = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::SchoolAdmin)->create();
        $fee = $this->feeFor($this->schoolA);

        app(StudentFeeWaiver::class)->waive($fee->getKey(), 'Beasiswa penuh', $admin);

        $this->assertSame(StudentFeeStatus::Waived, $fee->fresh()->status);
        $this->assertSame('0.00', $this->summarize()['spp_received']);
    }

    /**
     * Tagihan PARTIAL menyumbang persis cicilan yang sudah masuk, bukan
     * nominal tagihannya.
     */
    public function test_a_partial_fee_contributes_only_what_was_actually_paid(): void
    {
        $fee = $this->feeFor($this->schoolA, '1000000');

        $this->pay($fee, '400000', '2026-08-05');

        $this->assertSame(StudentFeeStatus::Partial, $fee->fresh()->status);
        $this->assertSame('400000.00', $this->summarize()['spp_received']);
    }

    public function test_receipts_keep_decimal_precision(): void
    {
        $fee = $this->feeFor($this->schoolA, '0.30');

        $this->pay($fee, '0.10', '2026-08-05');
        $this->pay($fee, '0.20', '2026-08-06');

        $this->assertSame('0.30', $this->summarize()['spp_received']);
    }

    // --------------------------------------------------------- pengeluaran

    public function test_expenses_sum_only_expense_transactions_in_the_period(): void
    {
        $this->transaction(TransactionType::Expense, '750000', '2026-08-04');
        $this->transaction(TransactionType::Expense, '250000', '2026-08-30');
        $this->transaction(TransactionType::Income, '9000000', '2026-08-10');
        $this->transaction(TransactionType::Expense, '400000', '2026-07-31');
        $this->transaction(TransactionType::Expense, '600000', '2026-09-01');

        $this->assertSame('1000000.00', $this->summarize()['expenses']);
    }

    public function test_expenses_filter_by_transaction_date(): void
    {
        $this->transaction(TransactionType::Expense, '400000', '2026-07-15');

        $this->assertSame('400000.00', $this->summarize('2026-07')['expenses']);
        $this->assertSame('0.00', $this->summarize('2026-08')['expenses']);
    }

    // ---------------------------------------------------------------- tren

    public function test_the_trend_always_covers_six_months_ending_on_the_selected_one(): void
    {
        $trend = $this->summarize('2026-08')['trend'];

        $this->assertCount(6, $trend);
        $this->assertSame(
            ['2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08'],
            array_column($trend, 'period'),
        );
    }

    public function test_the_trend_runs_oldest_to_newest(): void
    {
        $periods = array_column($this->summarize('2026-01')['trend'], 'period');

        $this->assertSame(
            ['2025-08', '2025-09', '2025-10', '2025-11', '2025-12', '2026-01'],
            $periods,
        );
        $sorted = $periods;
        sort($sorted);
        $this->assertSame($sorted, $periods);
    }

    public function test_months_without_data_are_zero_not_missing(): void
    {
        $this->transaction(TransactionType::Income, '1000000', '2026-08-05');

        $trend = collect($this->summarize('2026-08')['trend'])->keyBy('period');

        $this->assertSame('0.00', $trend['2026-05']['income']);
        $this->assertSame('0.00', $trend['2026-05']['expense']);
        $this->assertSame('1000000.00', $trend['2026-08']['income']);
    }

    public function test_the_trend_separates_income_and_expense_per_month(): void
    {
        $this->transaction(TransactionType::Income, '1000000', '2026-06-10');
        $this->transaction(TransactionType::Expense, '250000', '2026-06-20');
        $this->transaction(TransactionType::Income, '2000000', '2026-08-01');
        $this->transaction(TransactionType::Expense, '500000', '2026-08-02');
        $this->transaction(TransactionType::Expense, '100000', '2026-08-28');

        $trend = collect($this->summarize('2026-08')['trend'])->keyBy('period');

        $this->assertSame('1000000.00', $trend['2026-06']['income']);
        $this->assertSame('250000.00', $trend['2026-06']['expense']);
        $this->assertSame('0.00', $trend['2026-07']['income']);
        $this->assertSame('2000000.00', $trend['2026-08']['income']);
        $this->assertSame('600000.00', $trend['2026-08']['expense']);
    }

    /**
     * Tren hanya memuat dua seri; tidak ada proyeksi, pertumbuhan persen, atau
     * garis saldo yang dikarang.
     */
    public function test_the_trend_carries_no_invented_series(): void
    {
        foreach ($this->summarize()['trend'] as $month) {
            $this->assertSame(['period', 'label', 'income', 'expense'], array_keys($month));
        }
    }

    public function test_the_trend_crosses_the_year_boundary_correctly(): void
    {
        $this->transaction(TransactionType::Income, '500000', '2025-12-15');

        $trend = collect($this->summarize('2026-02')['trend'])->keyBy('period');

        $this->assertArrayHasKey('2025-09', $trend);
        $this->assertSame('500000.00', $trend['2025-12']['income']);
    }

    // ------------------------------------------------------------- tenant

    public function test_another_branch_transactions_never_leak_in(): void
    {
        $this->transaction(TransactionType::Income, '1000000', '2026-08-05');
        $this->transaction(TransactionType::Income, '9999999', '2026-08-05', $this->schoolB);
        $this->transaction(TransactionType::Expense, '8888888', '2026-08-06', $this->schoolB);

        $summary = $this->summarize();

        $this->assertSame('1000000.00', $summary['cash_balance']);
        $this->assertSame('0.00', $summary['expenses']);
        $this->assertSame('1000000.00', collect($summary['trend'])->firstWhere('period', '2026-08')['income']);
    }

    public function test_another_branch_payments_never_leak_in(): void
    {
        $bendaharaB = User::factory()->forSchool($this->schoolB)
            ->withRole(RoleName::Bendahara)->create();

        $this->pay($this->feeFor($this->schoolA), '400000', '2026-08-05');
        $this->pay($this->feeFor($this->schoolB, '9000000'), '7777777', '2026-08-05', $bendaharaB);

        $this->assertSame('400000.00', $this->summarize()['spp_received']);
        $this->assertSame('7777777.00', $this->summarize('2026-08', $this->schoolB)['spp_received']);
    }

    /**
     * Layanan menghitung untuk cabang yang diberikan sebagai argumen — sesi
     * pengguna tidak boleh mengubah hasilnya.
     */
    public function test_the_result_depends_on_the_argument_not_on_the_session(): void
    {
        $this->transaction(TransactionType::Income, '1000000', '2026-08-05');
        $this->transaction(TransactionType::Income, '2000000', '2026-08-05', $this->schoolB);

        $this->actingAs($this->bendaharaA);

        $this->assertSame('2000000.00', $this->summarize('2026-08', $this->schoolB)['cash_balance']);
    }

    // ------------------------------------------------------------- periode

    public function test_an_invalid_period_is_rejected(): void
    {
        foreach (['2026-13', '2026-00', '26-08', '2026/08', '', 'agustus'] as $period) {
            try {
                $this->summarize($period);
                $this->fail("Periode {$period} seharusnya ditolak.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('period', $e->errors());
            }
        }
    }

    public function test_the_summary_reports_its_period_boundaries(): void
    {
        $summary = $this->summarize('2026-02');

        $this->assertSame('2026-02', $summary['period']);
        $this->assertSame('2026-02-01', $summary['period_start']);
        // 2026 bukan tahun kabisat.
        $this->assertSame('2026-02-28', $summary['period_end']);
    }

    public function test_the_summary_exposes_only_the_documented_metrics(): void
    {
        $this->assertSame(
            ['period', 'period_start', 'period_end', 'cash_balance', 'spp_received', 'expenses', 'trend'],
            array_keys($this->summarize()),
        );
    }

    // --------------------------------------------------------- performance

    /**
     * Ringkasan memakai agregat SQL, bukan pemuatan seluruh record: jumlah
     * query-nya sama berapa pun banyaknya transaksi dan pembayaran.
     */
    public function test_the_query_count_stays_constant_as_data_grows(): void
    {
        $this->transaction(TransactionType::Income, '1000', '2026-08-05');

        DB::enableQueryLog();
        $this->summarize();
        $withOneRow = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        $fee = $this->feeFor($this->schoolA, '9000000');

        for ($i = 1; $i <= 20; $i++) {
            $this->transaction(TransactionType::Income, '1000', '2026-08-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
            $this->transaction(TransactionType::Expense, '500', '2026-07-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
            $this->pay($fee, '1000', '2026-08-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        DB::enableQueryLog();
        $this->summarize();
        $withManyRows = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $withOneRow,
            $withManyRows,
            'Jumlah query ringkasan harus konstan terhadap jumlah record.',
        );
        // Satu agregat saldo + satu penerimaan SPP + satu pengeluaran periode
        // + satu per bulan tren.
        $this->assertLessThanOrEqual(9, $withManyRows);
    }

    /**
     * Batch ini tidak menambah tabel, kolom, maupun relasi bisnis apa pun.
     */
    public function test_no_new_schema_or_relation_was_introduced(): void
    {
        foreach (['finance_summaries', 'monthly_summaries', 'cash_balances'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }

        foreach (['deleted_at', 'is_active', 'status', 'voided_at'] as $column) {
            $this->assertFalse(Schema::hasColumn('transactions', $column));
        }

        $this->assertFalse(Schema::hasColumn('transactions', 'payment_id'));
        $this->assertFalse(Schema::hasColumn('payments', 'transaction_id'));
        $this->assertFalse(method_exists(Transaction::class, 'payments'));
    }
}
