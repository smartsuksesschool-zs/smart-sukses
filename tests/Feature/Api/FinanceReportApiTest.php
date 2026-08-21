<?php

namespace Tests\Feature\Api;

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
use App\Services\Finance\PaymentRecorder;
use App\Services\Finance\SppReportService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * API 4.9.2 — GET /finance/summary dan GET /finance/spp-report.
 *
 * Dua laporan yang sengaja tidak berbagi angka: yang pertama membaca buku kas
 * (`transactions`), yang kedua membaca tagihan (`student_fees`). ERD memisahkan
 * keduanya (butir 75), dan test di sini menjaga pemisahan itu tetap terlihat —
 * pembayaran SPP tidak boleh menggelembungkan `income`, dan transaksi kas tidak
 * boleh menyentuh angka tunggakan.
 */
class FinanceReportApiTest extends TestCase
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

        $this->bendaharaA = $this->userIn($this->schoolA, RoleName::Bendahara);
    }

    protected function asUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }

    protected function userIn(School $school, RoleName $role): User
    {
        return User::factory()->forSchool($school)->withRole($role)->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function transactionIn(School $school, array $overrides = []): Transaction
    {
        return Transaction::factory()->create([
            'school_id' => $school->id,
            'created_by' => $this->userIn($school, RoleName::Bendahara)->id,
            'type' => TransactionType::Income->value,
            'amount' => '1000000',
            'transaction_date' => '2026-08-05',
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function studentFeeIn(School $school, array $overrides = []): StudentFee
    {
        return StudentFee::factory()->create([
            'school_id' => $school->id,
            'student_id' => Student::factory()->create(['school_id' => $school->id])->id,
            'fee_type_id' => FeeType::factory()->create(['school_id' => $school->id])->id,
            'amount' => '1000000',
            'amount_paid' => '0',
            'period' => '2026-08',
            'status' => StudentFeeStatus::Unpaid->value,
            ...$overrides,
        ]);
    }

    // ------------------------------------------------ finance summary: akses

    /**
     * @return array<string, array{RoleName}>
     */
    public static function allowedRoles(): array
    {
        return [
            'school admin' => [RoleName::SchoolAdmin],
            'kepala sekolah' => [RoleName::KepalaSekolah],
            'bendahara' => [RoleName::Bendahara],
        ];
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function deniedRoles(): array
    {
        return [
            'guru' => [RoleName::Guru],
            'wali kelas' => [RoleName::WaliKelas],
            'siswa' => [RoleName::Siswa],
            'orang tua' => [RoleName::OrangTua],
        ];
    }

    #[DataProvider('allowedRoles')]
    public function test_roles_holding_the_financial_report_permission_may_read_the_summary(RoleName $role): void
    {
        $this->asUser($this->userIn($this->schoolA, $role))
            ->getJson('/api/v1/finance/summary?year=2026&month=8')
            ->assertOk();
    }

    /**
     * "Auth Level: Auth" berarti wajib token, bukan boleh dibaca siapa saja
     * yang punya token. PRD 1.1.2 baris Laporan Keuangan yang menentukan
     * (butir 137).
     */
    #[DataProvider('deniedRoles')]
    public function test_roles_without_the_financial_report_permission_are_refused(RoleName $role): void
    {
        $this->asUser($this->userIn($this->schoolA, $role))
            ->getJson('/api/v1/finance/summary?year=2026&month=8')
            ->assertStatus(403);

        $this->asUser($this->userIn($this->schoolA, $role))
            ->getJson('/api/v1/finance/spp-report')
            ->assertStatus(403);
    }

    public function test_the_summary_requires_a_token(): void
    {
        $this->getJson('/api/v1/finance/summary?year=2026&month=8')->assertStatus(401);
        $this->getJson('/api/v1/finance/spp-report')->assertStatus(401);
    }

    // ------------------------------------------- finance summary: perhitungan

    public function test_income_and_expense_come_from_the_selected_month(): void
    {
        $this->transactionIn($this->schoolA, [
            'type' => TransactionType::Income->value,
            'amount' => '1500000',
            'transaction_date' => '2026-08-10',
        ]);
        $this->transactionIn($this->schoolA, [
            'type' => TransactionType::Expense->value,
            'amount' => '400000',
            'transaction_date' => '2026-08-20',
        ]);
        // Bulan lain: tidak ikut income/expense bulan terpilih.
        $this->transactionIn($this->schoolA, [
            'type' => TransactionType::Income->value,
            'amount' => '999000',
            'transaction_date' => '2026-07-31',
        ]);

        $body = $this->asUser($this->bendaharaA)
            ->getJson('/api/v1/finance/summary?year=2026&month=8')
            ->assertOk();

        $body->assertJsonPath('data.year', 2026);
        $body->assertJsonPath('data.month', 8);
        $body->assertJsonPath('data.income', '1500000.00');
        $body->assertJsonPath('data.expense', '400000.00');
    }

    /**
     * Saldo memakai tanggal potong yang sudah disetujui: posisi sampai akhir
     * bulan terpilih, bukan selisih bulan itu saja (butir 82). Juli ikut,
     * September tidak.
     */
    public function test_the_balance_is_cumulative_through_the_end_of_the_month(): void
    {
        $this->transactionIn($this->schoolA, [
            'type' => TransactionType::Income->value,
            'amount' => '1000000',
            'transaction_date' => '2026-07-01',
        ]);
        $this->transactionIn($this->schoolA, [
            'type' => TransactionType::Income->value,
            'amount' => '500000',
            'transaction_date' => '2026-08-15',
        ]);
        $this->transactionIn($this->schoolA, [
            'type' => TransactionType::Expense->value,
            'amount' => '200000',
            'transaction_date' => '2026-08-31',
        ]);
        $this->transactionIn($this->schoolA, [
            'type' => TransactionType::Income->value,
            'amount' => '777000',
            'transaction_date' => '2026-09-01',
        ]);

        $body = $this->asUser($this->bendaharaA)
            ->getJson('/api/v1/finance/summary?year=2026&month=8')
            ->assertOk();

        $body->assertJsonPath('data.income', '500000.00');
        $body->assertJsonPath('data.expense', '200000.00');
        // 1.000.000 + 500.000 − 200.000; September belum terjadi pada potongan ini.
        $body->assertJsonPath('data.balance', '1300000.00');
    }

    public function test_a_transaction_from_the_future_never_enters_a_past_balance(): void
    {
        $this->transactionIn($this->schoolA, [
            'amount' => '1000000',
            'transaction_date' => '2026-12-31',
        ]);

        $this->asUser($this->bendaharaA)
            ->getJson('/api/v1/finance/summary?year=2026&month=8')
            ->assertOk()
            ->assertJsonPath('data.balance', '0.00');
    }

    /**
     * Penerimaan SPP dibaca dari `payments` dan tidak pernah menjadi `income`
     * buku kas: keduanya jalur terpisah, dan menjumlahkannya akan menghitung
     * uang yang sama dua kali (butir 134).
     */
    public function test_spp_payments_never_inflate_income(): void
    {
        $fee = $this->studentFeeIn($this->schoolA);

        app(PaymentRecorder::class)->record($fee->getKey(), [
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => '750000',
            'payment_date' => '2026-08-10',
        ], $this->bendaharaA);

        $this->asUser($this->bendaharaA)
            ->getJson('/api/v1/finance/summary?year=2026&month=8')
            ->assertOk()
            ->assertJsonPath('data.income', '0.00')
            ->assertJsonPath('data.balance', '0.00');
    }

    public function test_an_empty_month_reports_zeros(): void
    {
        $this->asUser($this->bendaharaA)
            ->getJson('/api/v1/finance/summary?year=2026&month=3')
            ->assertOk()
            ->assertJsonPath('data.income', '0.00')
            ->assertJsonPath('data.expense', '0.00')
            ->assertJsonPath('data.balance', '0.00');
    }

    public function test_money_keeps_two_decimals_as_strings(): void
    {
        $this->transactionIn($this->schoolA, ['amount' => '1234567.89']);

        $body = $this->asUser($this->bendaharaA)
            ->getJson('/api/v1/finance/summary?year=2026&month=8')
            ->assertOk();

        $this->assertSame('1234567.89', $body->json('data.income'));
        $this->assertIsString($body->json('data.income'));
        $this->assertIsString($body->json('data.balance'));
    }

    // ------------------------------------------------ finance summary: filter

    /**
     * @return array<string, array{string}>
     */
    public static function invalidSummaryFilters(): array
    {
        return [
            'tanpa filter' => [''],
            'bulan nol' => ['?year=2026&month=0'],
            'bulan tiga belas' => ['?year=2026&month=13'],
            'bulan bukan angka' => ['?year=2026&month=agustus'],
            'tahun terlalu kecil' => ['?year=1899&month=8'],
            'tahun terlalu besar' => ['?year=99999&month=8'],
            'tanpa bulan' => ['?year=2026'],
            'tanpa tahun' => ['?month=8'],
        ];
    }

    #[DataProvider('invalidSummaryFilters')]
    public function test_invalid_filters_are_rejected_with_the_api_envelope(string $query): void
    {
        $this->asUser($this->bendaharaA)
            ->getJson('/api/v1/finance/summary'.$query)
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'errors']);
    }

    /**
     * `period` adalah nama internal service; kontrak publiknya `year` dan
     * `month` (butir 123).
     */
    public function test_the_internal_period_name_is_not_a_public_filter(): void
    {
        $this->asUser($this->bendaharaA)
            ->getJson('/api/v1/finance/summary?period=2026-08')
            ->assertStatus(422);
    }

    public function test_the_summary_runs_a_constant_number_of_queries(): void
    {
        Transaction::factory()->count(30)->create([
            'school_id' => $this->schoolA->id,
            'created_by' => $this->bendaharaA->id,
            'transaction_date' => '2026-08-05',
        ]);

        // Pemanasan: izin Spatie dan resolusi token tidak ikut terhitung.
        $this->asUser($this->bendaharaA)->getJson('/api/v1/finance/summary?year=2026&month=8');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->asUser($this->bendaharaA)
            ->getJson('/api/v1/finance/summary?year=2026&month=8')
            ->assertOk();

        $withThirty = count(DB::getQueryLog());

        Transaction::factory()->count(90)->create([
            'school_id' => $this->schoolA->id,
            'created_by' => $this->bendaharaA->id,
            'transaction_date' => '2026-08-05',
        ]);

        DB::flushQueryLog();

        $this->asUser($this->bendaharaA)
            ->getJson('/api/v1/finance/summary?year=2026&month=8')
            ->assertOk();

        $withHundredTwenty = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertSame($withThirty, $withHundredTwenty);
    }

    // ------------------------------------------------ tenant / school context

    public function test_the_summary_only_ever_covers_the_callers_branch(): void
    {
        $this->transactionIn($this->schoolA, ['amount' => '100000']);
        $this->transactionIn($this->schoolB, ['amount' => '900000']);

        $this->asUser($this->bendaharaA)
            ->getJson('/api/v1/finance/summary?year=2026&month=8')
            ->assertOk()
            ->assertJsonPath('data.income', '100000.00')
            ->assertJsonPath('data.school_id', $this->schoolA->id);
    }

    public function test_a_crafted_school_id_cannot_move_the_report(): void
    {
        $this->transactionIn($this->schoolA, ['amount' => '100000']);
        $this->transactionIn($this->schoolB, ['amount' => '900000']);

        $this->asUser($this->bendaharaA)
            ->getJson("/api/v1/finance/summary?year=2026&month=8&school_id={$this->schoolB->id}")
            ->assertOk()
            ->assertJsonPath('data.income', '100000.00')
            ->assertJsonPath('data.school_id', $this->schoolA->id);
    }

    /**
     * Endpoint ini satu cabang. Super Admin karena itu wajib memilih, dan tidak
     * pernah diam-diam menerima gabungan seluruh cabang — yang lintas cabang
     * adalah KAS-03, domain yang berbeda.
     */
    public function test_a_super_admin_must_choose_one_branch(): void
    {
        $superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create(['school_id' => null]);

        $this->transactionIn($this->schoolA, ['amount' => '100000']);
        $this->transactionIn($this->schoolB, ['amount' => '900000']);

        $this->asUser($superAdmin)
            ->getJson('/api/v1/finance/summary?year=2026&month=8')
            ->assertStatus(422);

        $this->asUser($superAdmin)
            ->getJson("/api/v1/finance/summary?year=2026&month=8&school_id={$this->schoolB->id}")
            ->assertOk()
            ->assertJsonPath('data.income', '900000.00')
            ->assertJsonPath('data.school_id', $this->schoolB->id);
    }

    // ------------------------------------------------------------- spp report

    public function test_the_report_totals_billed_collected_and_arrears(): void
    {
        $this->studentFeeIn($this->schoolA, [
            'amount' => '1000000',
            'amount_paid' => '0',
            'status' => StudentFeeStatus::Unpaid->value,
        ]);
        $this->studentFeeIn($this->schoolA, [
            'amount' => '1000000',
            'amount_paid' => '400000',
            'status' => StudentFeeStatus::Partial->value,
        ]);
        $this->studentFeeIn($this->schoolA, [
            'amount' => '1000000',
            'amount_paid' => '1000000',
            'status' => StudentFeeStatus::Paid->value,
        ]);

        $body = $this->asUser($this->bendaharaA)
            ->getJson('/api/v1/finance/spp-report?period=2026-08')
            ->assertOk();

        $body->assertJsonPath('data.periods.0.period', '2026-08');
        $body->assertJsonPath('data.periods.0.total_billed', '3000000.00');
        $body->assertJsonPath('data.periods.0.total_collected', '1400000.00');
        // UNPAID 1.000.000 + PARTIAL 600.000; PAID tidak menyumbang apa pun.
        $body->assertJsonPath('data.periods.0.arrears', '1600000.00');
    }

    /**
     * Tagihan yang dibebaskan bukan tunggakan: uangnya memang tidak akan
     * ditagih. Nominalnya tetap masuk `total_billed` sebagai angka historis
     * yang pernah diterbitkan (butir 135).
     */
    public function test_a_waived_fee_is_billed_but_never_arrears(): void
    {
        $this->studentFeeIn($this->schoolA, [
            'amount' => '1000000',
            'amount_paid' => '0',
            'status' => StudentFeeStatus::Waived->value,
            'waive_reason' => 'Beasiswa penuh',
        ]);

        $body = $this->asUser($this->bendaharaA)
            ->getJson('/api/v1/finance/spp-report?period=2026-08')
            ->assertOk();

        $body->assertJsonPath('data.periods.0.total_billed', '1000000.00');
        $body->assertJsonPath('data.periods.0.arrears', '0.00');

        // Kalau tunggakan dihitung buta sebagai billed − collected, angkanya
        // akan menjadi 1.000.000 dan laporannya berbohong.
        $this->assertNotSame(
            $body->json('data.periods.0.total_billed'),
            $body->json('data.periods.0.arrears'),
        );
    }

    /**
     * Cicilan yang sudah masuk sebelum tagihan dibebaskan tetap uang yang
     * benar-benar diterima, jadi tetap dihitung sebagai terkumpul.
     */
    public function test_a_waived_fee_keeps_the_instalments_it_already_received(): void
    {
        $this->studentFeeIn($this->schoolA, [
            'amount' => '1000000',
            'amount_paid' => '250000',
            'status' => StudentFeeStatus::Waived->value,
            'waive_reason' => 'Pindah sekolah',
        ]);

        $body = $this->asUser($this->bendaharaA)
            ->getJson('/api/v1/finance/spp-report?period=2026-08')
            ->assertOk();

        $body->assertJsonPath('data.periods.0.total_collected', '250000.00');
        $body->assertJsonPath('data.periods.0.arrears', '0.00');
    }

    /**
     * Angka tunggakan agregat harus sama dengan penjumlahan
     * `StudentFee::remaining()` baris per baris — rumusnya tidak boleh
     * bercabang hanya karena dihitung di SQL.
     */
    public function test_the_aggregate_arrears_agree_with_the_model_helper(): void
    {
        $fees = [
            $this->studentFeeIn($this->schoolA, ['amount' => '1000000', 'amount_paid' => '0', 'status' => StudentFeeStatus::Unpaid->value]),
            $this->studentFeeIn($this->schoolA, ['amount' => '1000000', 'amount_paid' => '333333', 'status' => StudentFeeStatus::Partial->value]),
            $this->studentFeeIn($this->schoolA, ['amount' => '1000000', 'amount_paid' => '1000000', 'status' => StudentFeeStatus::Paid->value]),
            $this->studentFeeIn($this->schoolA, ['amount' => '1000000', 'amount_paid' => '0', 'status' => StudentFeeStatus::Waived->value, 'waive_reason' => 'x']),
        ];

        $expected = '0.00';

        foreach ($fees as $fee) {
            if (in_array($fee->status, [StudentFeeStatus::Unpaid, StudentFeeStatus::Partial], true)) {
                $expected = bcadd($expected, $fee->remaining(), 2);
            }
        }

        $this->asUser($this->bendaharaA)
            ->getJson('/api/v1/finance/spp-report?period=2026-08')
            ->assertOk()
            ->assertJsonPath('data.periods.0.arrears', $expected);
    }

    public function test_without_a_period_every_period_is_reported_newest_first(): void
    {
        $this->studentFeeIn($this->schoolA, ['period' => '2026-06', 'amount' => '100000']);
        $this->studentFeeIn($this->schoolA, ['period' => '2026-07', 'amount' => '200000']);
        $this->studentFeeIn($this->schoolA, ['period' => '2026-08', 'amount' => '300000']);

        $body = $this->asUser($this->bendaharaA)
            ->getJson('/api/v1/finance/spp-report')
            ->assertOk();

        $this->assertSame(
            ['2026-08', '2026-07', '2026-06'],
            array_column($body->json('data.periods'), 'period'),
        );
        $body->assertJsonPath('data.period', null);
        $body->assertJsonPath('data.truncated', false);
    }

    public function test_a_period_filter_narrows_the_report_to_one_period(): void
    {
        $this->studentFeeIn($this->schoolA, ['period' => '2026-07', 'amount' => '200000']);
        $this->studentFeeIn($this->schoolA, ['period' => '2026-08', 'amount' => '300000']);

        $body = $this->asUser($this->bendaharaA)
            ->getJson('/api/v1/finance/spp-report?period=2026-07')
            ->assertOk();

        $body->assertJsonCount(1, 'data.periods');
        $body->assertJsonPath('data.period', '2026-07');
        $body->assertJsonPath('data.periods.0.total_billed', '200000.00');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPeriods(): array
    {
        return [
            'bulan nol' => ['2026-00'],
            'bulan tiga belas' => ['2026-13'],
            'tanpa pemisah' => ['202608'],
            'kata' => ['agustus'],
            'terlalu panjang' => ['2026-08-01'],
        ];
    }

    #[DataProvider('invalidPeriods')]
    public function test_an_invalid_period_is_rejected(string $period): void
    {
        $this->asUser($this->bendaharaA)
            ->getJson('/api/v1/finance/spp-report?period='.$period)
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_a_period_with_no_fees_reports_no_rows(): void
    {
        $this->asUser($this->bendaharaA)
            ->getJson('/api/v1/finance/spp-report?period=2026-01')
            ->assertOk()
            ->assertJsonCount(0, 'data.periods');
    }

    public function test_the_report_only_covers_the_callers_branch(): void
    {
        $this->studentFeeIn($this->schoolA, ['amount' => '100000']);
        $this->studentFeeIn($this->schoolB, ['amount' => '900000']);

        $this->asUser($this->bendaharaA)
            ->getJson('/api/v1/finance/spp-report?period=2026-08')
            ->assertOk()
            ->assertJsonPath('data.periods.0.total_billed', '100000.00')
            ->assertJsonPath('data.school_id', $this->schoolA->id);
    }

    public function test_a_super_admin_reads_one_branch_at_a_time(): void
    {
        $superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create(['school_id' => null]);

        $this->studentFeeIn($this->schoolA, ['amount' => '100000']);
        $this->studentFeeIn($this->schoolB, ['amount' => '900000']);

        $this->asUser($superAdmin)
            ->getJson('/api/v1/finance/spp-report?period=2026-08')
            ->assertStatus(422);

        $this->asUser($superAdmin)
            ->getJson("/api/v1/finance/spp-report?period=2026-08&school_id={$this->schoolB->id}")
            ->assertOk()
            ->assertJsonPath('data.periods.0.total_billed', '900000.00');
    }

    /**
     * Buku kas tidak tahu apa-apa tentang tagihan siapa yang menunggak, dan
     * sebaliknya (butir 75).
     */
    public function test_cash_transactions_never_touch_the_spp_report(): void
    {
        $this->studentFeeIn($this->schoolA, ['amount' => '100000']);
        $this->transactionIn($this->schoolA, ['amount' => '5000000']);

        $this->asUser($this->bendaharaA)
            ->getJson('/api/v1/finance/spp-report?period=2026-08')
            ->assertOk()
            ->assertJsonPath('data.periods.0.total_billed', '100000.00')
            ->assertJsonPath('data.periods.0.total_collected', '0.00');
    }

    public function test_the_report_keeps_two_decimals_as_strings(): void
    {
        $this->studentFeeIn($this->schoolA, ['amount' => '1234567.89', 'amount_paid' => '0.11']);

        $body = $this->asUser($this->bendaharaA)
            ->getJson('/api/v1/finance/spp-report?period=2026-08')
            ->assertOk();

        $this->assertSame('1234567.89', $body->json('data.periods.0.total_billed'));
        $this->assertSame('0.11', $body->json('data.periods.0.total_collected'));
        $this->assertIsString($body->json('data.periods.0.arrears'));
    }

    /**
     * Satu query agregat, berapa pun banyaknya tagihan dan berapa pun
     * banyaknya periode — tidak ada query per tagihan maupun per periode.
     */
    public function test_the_report_runs_a_bounded_number_of_queries(): void
    {
        foreach (['2026-06', '2026-07', '2026-08'] as $period) {
            $this->studentFeeIn($this->schoolA, ['period' => $period]);
        }

        $this->asUser($this->bendaharaA)->getJson('/api/v1/finance/spp-report');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->asUser($this->bendaharaA)->getJson('/api/v1/finance/spp-report')->assertOk();

        $withThree = count(DB::getQueryLog());

        foreach (['2026-01', '2026-02', '2026-03', '2026-04', '2026-05'] as $period) {
            for ($i = 0; $i < 5; $i++) {
                $this->studentFeeIn($this->schoolA, ['period' => $period]);
            }
        }

        DB::flushQueryLog();

        $this->asUser($this->bendaharaA)->getJson('/api/v1/finance/spp-report')->assertOk();

        $withMany = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertSame($withThree, $withMany);
    }

    public function test_the_period_list_is_bounded(): void
    {
        $this->assertSame(60, SppReportService::MAX_PERIODS);
    }
}
