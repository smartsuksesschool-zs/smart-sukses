<?php

namespace Tests\Feature\Finance;

use App\Enums\RoleName;
use App\Enums\StudentFeeStatus;
use App\Models\AcademicYear;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\CrossSchoolFinanceSummaryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * KAS-03 — "ringkasan keuangan semua cabang dalam satu dashboard": per cabang
 * total tagihan, total terkumpul, persentase lunas; filter tahun ajaran/bulan.
 */
class CrossSchoolFinanceSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create(['name' => 'Cabang Alpha']);
        $this->schoolB = School::factory()->create(['name' => 'Cabang Beta']);
        $this->superAdmin = User::factory()->superAdmin()->create();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function summarize(?string $academicYear = null, ?string $period = null, ?User $actor = null): array
    {
        return app(CrossSchoolFinanceSummaryService::class)
            ->summarize($actor ?? $this->superAdmin, $academicYear, $period);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function fee(School $school, array $overrides = []): StudentFee
    {
        return StudentFee::factory()->create([
            'school_id' => $school->id,
            'student_id' => Student::factory()->create(['school_id' => $school->id])->id,
            'fee_type_id' => FeeType::factory()->create(['school_id' => $school->id])->id,
            'amount' => '1000000',
            'amount_paid' => '0',
            'status' => StudentFeeStatus::Unpaid->value,
            'period' => '2026-08',
            ...$overrides,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rowFor(array $rows, School $school): array
    {
        $row = collect($rows)->firstWhere('school_id', $school->id);

        $this->assertNotNull($row, "Cabang {$school->name} seharusnya muncul di ringkasan.");

        return $row;
    }

    // --------------------------------------------------------------- akses

    public function test_super_admin_may_read_the_cross_branch_summary(): void
    {
        $this->fee($this->schoolA);

        $rows = $this->summarize();

        $this->assertCount(2, $rows);
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function rolesThatMayNotRead(): array
    {
        return [
            'admin sekolah' => [RoleName::SchoolAdmin],
            'kepala sekolah' => [RoleName::KepalaSekolah],
            'bendahara' => [RoleName::Bendahara],
            'guru' => [RoleName::Guru],
            'wali kelas' => [RoleName::WaliKelas],
        ];
    }

    /**
     * Peran School Level tidak dapat memanggil agregasi lintas cabang walaupun
     * langsung ke layanannya — bukan hanya tidak melihat menunya.
     */
    #[DataProvider('rolesThatMayNotRead')]
    public function test_school_level_roles_cannot_call_the_aggregation(RoleName $role): void
    {
        $user = User::factory()->forSchool($this->schoolA)->withRole($role)->create();

        $this->expectException(AuthorizationException::class);

        $this->summarize(actor: $user);
    }

    #[DataProvider('rolesThatMayNotRead')]
    public function test_school_level_roles_cannot_list_academic_year_options(RoleName $role): void
    {
        $user = User::factory()->forSchool($this->schoolA)->withRole($role)->create();

        $this->expectException(AuthorizationException::class);

        app(CrossSchoolFinanceSummaryService::class)->academicYearOptions($user);
    }

    // ----------------------------------------------------------- agregasi

    public function test_total_billed_and_collected_are_summed_per_branch(): void
    {
        $this->fee($this->schoolA, ['amount' => '1000000', 'amount_paid' => '400000', 'status' => StudentFeeStatus::Partial->value]);
        $this->fee($this->schoolA, ['amount' => '500000', 'amount_paid' => '500000', 'status' => StudentFeeStatus::Paid->value]);
        $this->fee($this->schoolB, ['amount' => '2000000', 'amount_paid' => '250000', 'status' => StudentFeeStatus::Partial->value]);

        $rows = $this->summarize();

        $alpha = $this->rowFor($rows, $this->schoolA);
        $beta = $this->rowFor($rows, $this->schoolB);

        $this->assertSame('1500000.00', $alpha['total_billed']);
        $this->assertSame('900000.00', $alpha['total_collected']);
        $this->assertSame('2000000.00', $beta['total_billed']);
        $this->assertSame('250000.00', $beta['total_collected']);
    }

    public function test_branches_never_bleed_into_each_other(): void
    {
        $this->fee($this->schoolA, ['amount' => '1000000']);
        $this->fee($this->schoolB, ['amount' => '9999999']);

        $rows = $this->summarize();

        $this->assertSame('1000000.00', $this->rowFor($rows, $this->schoolA)['total_billed']);
        $this->assertSame('9999999.00', $this->rowFor($rows, $this->schoolB)['total_billed']);
    }

    public function test_three_branches_produce_three_separate_rows(): void
    {
        $schoolC = School::factory()->create(['name' => 'Cabang Gamma']);

        $this->fee($this->schoolA, ['amount' => '100000']);
        $this->fee($this->schoolB, ['amount' => '200000']);
        $this->fee($schoolC, ['amount' => '300000']);

        $rows = $this->summarize();

        $this->assertCount(3, $rows);
        $this->assertSame(
            ['100000.00', '200000.00', '300000.00'],
            [
                $this->rowFor($rows, $this->schoolA)['total_billed'],
                $this->rowFor($rows, $this->schoolB)['total_billed'],
                $this->rowFor($rows, $schoolC)['total_billed'],
            ],
        );
    }

    public function test_a_branch_without_data_reports_zeros_not_an_error(): void
    {
        $row = $this->rowFor($this->summarize(), $this->schoolA);

        $this->assertSame('0.00', $row['total_billed']);
        $this->assertSame('0.00', $row['total_collected']);
        $this->assertSame('0.00', $row['paid_percentage']);
        $this->assertSame(0, $row['paid_count']);
        $this->assertSame(0, $row['billable_count']);
    }

    public function test_amounts_keep_two_decimal_places(): void
    {
        $this->fee($this->schoolA, ['amount' => '1234567.89', 'amount_paid' => '0.01']);

        $row = $this->rowFor($this->summarize(), $this->schoolA);

        $this->assertSame('1234567.89', $row['total_billed']);
        $this->assertSame('0.01', $row['total_collected']);
    }

    // ---------------------------------------------------------- persentase

    public function test_every_fee_paid_is_one_hundred_percent(): void
    {
        $this->fee($this->schoolA, ['amount' => '100000', 'amount_paid' => '100000', 'status' => StudentFeeStatus::Paid->value]);
        $this->fee($this->schoolA, ['amount' => '200000', 'amount_paid' => '200000', 'status' => StudentFeeStatus::Paid->value]);

        $this->assertSame('100.00', $this->rowFor($this->summarize(), $this->schoolA)['paid_percentage']);
    }

    public function test_no_fee_paid_is_zero_percent(): void
    {
        $this->fee($this->schoolA);
        $this->fee($this->schoolA, ['amount_paid' => '400000', 'status' => StudentFeeStatus::Partial->value]);

        $this->assertSame('0.00', $this->rowFor($this->summarize(), $this->schoolA)['paid_percentage']);
    }

    public function test_a_mixed_branch_reports_the_paid_proportion(): void
    {
        $this->fee($this->schoolA, ['status' => StudentFeeStatus::Paid->value, 'amount_paid' => '1000000']);
        $this->fee($this->schoolA, ['status' => StudentFeeStatus::Partial->value, 'amount_paid' => '400000']);
        $this->fee($this->schoolA, ['status' => StudentFeeStatus::Unpaid->value]);
        $this->fee($this->schoolA, ['status' => StudentFeeStatus::Unpaid->value]);

        $row = $this->rowFor($this->summarize(), $this->schoolA);

        $this->assertSame(1, $row['paid_count']);
        $this->assertSame(4, $row['billable_count']);
        $this->assertSame('25.00', $row['paid_percentage']);
    }

    /**
     * Persentase lunas adalah proporsi tagihan berstatus PAID, bukan
     * `amount_paid / amount` — keduanya berbeda jauh ketika tagihan dicicil.
     */
    public function test_a_partially_paid_fee_is_not_counted_as_paid(): void
    {
        $this->fee($this->schoolA, [
            'amount' => '1000000',
            'amount_paid' => '999999',
            'status' => StudentFeeStatus::Partial->value,
        ]);

        $row = $this->rowFor($this->summarize(), $this->schoolA);

        $this->assertSame(0, $row['paid_count']);
        $this->assertSame('0.00', $row['paid_percentage']);
        // Tingkat penagihannya hampir 100%, tetapi tidak satu pun tagihan lunas.
        $this->assertSame('999999.00', $row['total_collected']);
    }

    public function test_a_zero_denominator_does_not_divide_by_zero(): void
    {
        $this->fee($this->schoolA, ['status' => StudentFeeStatus::Waived->value, 'waive_reason' => 'Beasiswa']);

        $row = $this->rowFor($this->summarize(), $this->schoolA);

        $this->assertSame(0, $row['billable_count']);
        $this->assertSame('0.00', $row['paid_percentage']);
    }

    // -------------------------------------------------------------- WAIVED

    /**
     * WAIVED dikeluarkan dari penyebut: tagihan yang dibebaskan bukan kewajiban
     * yang perlu dilunasi, dan memasukkannya membuat cabang yang banyak memberi
     * keringanan terlihat buruk justru karena kebijakannya.
     */
    public function test_waived_fees_leave_the_denominator(): void
    {
        $this->fee($this->schoolA, ['status' => StudentFeeStatus::Paid->value, 'amount_paid' => '1000000']);
        $this->fee($this->schoolA, ['status' => StudentFeeStatus::Waived->value, 'waive_reason' => 'Beasiswa']);

        $row = $this->rowFor($this->summarize(), $this->schoolA);

        $this->assertSame(1, $row['paid_count']);
        $this->assertSame(1, $row['billable_count']);
        $this->assertSame('100.00', $row['paid_percentage']);
    }

    public function test_a_waived_fee_is_never_counted_as_paid(): void
    {
        $this->fee($this->schoolA, ['status' => StudentFeeStatus::Waived->value, 'waive_reason' => 'Beasiswa']);
        $this->fee($this->schoolA, ['status' => StudentFeeStatus::Unpaid->value]);

        $row = $this->rowFor($this->summarize(), $this->schoolA);

        $this->assertSame(0, $row['paid_count']);
        $this->assertSame(1, $row['billable_count']);
        $this->assertSame('0.00', $row['paid_percentage']);
    }

    /**
     * Nominalnya tetap masuk total tagihan: recordnya nyata dan pernah
     * diterbitkan.
     */
    public function test_a_waived_fee_still_counts_towards_total_billed(): void
    {
        $this->fee($this->schoolA, [
            'amount' => '750000',
            'status' => StudentFeeStatus::Waived->value,
            'waive_reason' => 'Beasiswa',
        ]);

        $row = $this->rowFor($this->summarize(), $this->schoolA);

        $this->assertSame('750000.00', $row['total_billed']);
        $this->assertSame('0.00', $row['total_collected']);
    }

    // --------------------------------------------------------------- filter

    public function test_the_month_filter_narrows_to_that_period(): void
    {
        $this->fee($this->schoolA, ['amount' => '100000', 'period' => '2026-07']);
        $this->fee($this->schoolA, ['amount' => '200000', 'period' => '2026-08']);

        $this->assertSame('100000.00', $this->rowFor($this->summarize(period: '2026-07'), $this->schoolA)['total_billed']);
        $this->assertSame('200000.00', $this->rowFor($this->summarize(period: '2026-08'), $this->schoolA)['total_billed']);
        // Tanpa filter bulan, keduanya terhitung.
        $this->assertSame('300000.00', $this->rowFor($this->summarize(), $this->schoolA)['total_billed']);
    }

    /**
     * Tahun ajaran adalah baris milik masing-masing cabang; yang dicocokkan
     * namanya, sehingga filternya bekerja lintas cabang.
     */
    public function test_the_academic_year_filter_matches_each_branch_own_row(): void
    {
        $yearA = AcademicYear::factory()->create(['school_id' => $this->schoolA->id, 'name' => '2026/2027 Ganjil']);
        $yearB = AcademicYear::factory()->create(['school_id' => $this->schoolB->id, 'name' => '2026/2027 Ganjil']);
        $otherA = AcademicYear::factory()->create(['school_id' => $this->schoolA->id, 'name' => '2025/2026 Genap']);

        // Id-nya berbeda walaupun namanya sama — inilah alasan filter memakai nama.
        $this->assertNotSame($yearA->id, $yearB->id);

        $this->fee($this->schoolA, ['amount' => '100000', 'academic_year_id' => $yearA->id]);
        $this->fee($this->schoolB, ['amount' => '200000', 'academic_year_id' => $yearB->id]);
        $this->fee($this->schoolA, ['amount' => '900000', 'academic_year_id' => $otherA->id]);

        $rows = $this->summarize(academicYear: '2026/2027 Ganjil');

        $this->assertSame('100000.00', $this->rowFor($rows, $this->schoolA)['total_billed']);
        $this->assertSame('200000.00', $this->rowFor($rows, $this->schoolB)['total_billed']);
    }

    public function test_fees_outside_the_selected_academic_year_are_excluded(): void
    {
        $year = AcademicYear::factory()->create(['school_id' => $this->schoolA->id, 'name' => '2026/2027 Ganjil']);

        $this->fee($this->schoolA, ['amount' => '100000', 'academic_year_id' => $year->id]);
        // Tagihan berulang tanpa tahun ajaran (ERD: NULL diperbolehkan).
        $this->fee($this->schoolA, ['amount' => '500000', 'academic_year_id' => null]);

        $this->assertSame('100000.00', $this->rowFor($this->summarize(academicYear: '2026/2027 Ganjil'), $this->schoolA)['total_billed']);
        $this->assertSame('600000.00', $this->rowFor($this->summarize(), $this->schoolA)['total_billed']);
    }

    public function test_both_filters_combine(): void
    {
        $year = AcademicYear::factory()->create(['school_id' => $this->schoolA->id, 'name' => '2026/2027 Ganjil']);

        $this->fee($this->schoolA, ['amount' => '100000', 'academic_year_id' => $year->id, 'period' => '2026-08']);
        $this->fee($this->schoolA, ['amount' => '200000', 'academic_year_id' => $year->id, 'period' => '2026-09']);
        $this->fee($this->schoolA, ['amount' => '400000', 'academic_year_id' => null, 'period' => '2026-08']);

        $rows = $this->summarize(academicYear: '2026/2027 Ganjil', period: '2026-08');

        $this->assertSame('100000.00', $this->rowFor($rows, $this->schoolA)['total_billed']);
    }

    public function test_an_unknown_academic_year_name_matches_nothing(): void
    {
        $this->fee($this->schoolA, ['amount' => '100000']);

        $this->assertSame('0.00', $this->rowFor($this->summarize(academicYear: 'Tidak Ada'), $this->schoolA)['total_billed']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPeriods(): array
    {
        return [
            'bulan 13' => ['2026-13'],
            'bulan 00' => ['2026-00'],
            'tahun 2 digit' => ['26-08'],
            'pemisah salah' => ['2026/08'],
            'teks' => ['agustus'],
        ];
    }

    #[DataProvider('invalidPeriods')]
    public function test_an_invalid_month_is_rejected(string $period): void
    {
        try {
            $this->summarize(period: $period);
            $this->fail("Bulan {$period} seharusnya ditolak.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('period', $e->errors());
        }
    }

    public function test_a_blank_filter_means_no_filter(): void
    {
        $this->fee($this->schoolA, ['amount' => '100000']);

        foreach ([null, '', '   '] as $blank) {
            $rows = $this->summarize(academicYear: $blank, period: $blank);

            $this->assertSame('100000.00', $this->rowFor($rows, $this->schoolA)['total_billed']);
        }
    }

    public function test_academic_year_options_are_collected_across_branches(): void
    {
        AcademicYear::factory()->create(['school_id' => $this->schoolA->id, 'name' => '2026/2027 Ganjil']);
        AcademicYear::factory()->create(['school_id' => $this->schoolB->id, 'name' => '2026/2027 Ganjil']);
        AcademicYear::factory()->create(['school_id' => $this->schoolB->id, 'name' => '2025/2026 Genap']);

        $options = app(CrossSchoolFinanceSummaryService::class)->academicYearOptions($this->superAdmin);

        // Nama yang sama di dua cabang muncul sekali saja.
        $this->assertSame(['2026/2027 Ganjil', '2025/2026 Genap'], array_values($options));
    }

    // ------------------------------------------------------------- cakupan

    public function test_an_inactive_branch_with_history_still_appears(): void
    {
        $closed = School::factory()->create(['name' => 'Cabang Tutup', 'is_active' => false]);

        $this->fee($closed, ['amount' => '750000']);

        $row = $this->rowFor($this->summarize(), $closed);

        $this->assertFalse($row['is_active']);
        $this->assertSame('750000.00', $row['total_billed']);
    }

    public function test_an_inactive_branch_without_data_is_left_out(): void
    {
        School::factory()->create(['name' => 'Cabang Kosong', 'is_active' => false]);

        $names = array_column($this->summarize(), 'school_name');

        $this->assertNotContains('Cabang Kosong', $names);
        $this->assertContains('Cabang Alpha', $names);
    }

    public function test_an_active_branch_without_data_still_appears(): void
    {
        $names = array_column($this->summarize(), 'school_name');

        $this->assertContains('Cabang Alpha', $names);
        $this->assertContains('Cabang Beta', $names);
    }

    // --------------------------------------------------------- performance

    public function test_the_query_count_is_constant_as_branches_grow(): void
    {
        $this->fee($this->schoolA);

        // Pemanggilan pemanasan: peran Spatie dimuat lazy sekali saja, dan
        // menghitungnya akan mengaburkan yang sedang diukur.
        $this->summarize();

        DB::enableQueryLog();
        $this->summarize();
        $withTwoBranches = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        for ($i = 0; $i < 8; $i++) {
            $extra = School::factory()->create();
            $this->fee($extra);
        }

        DB::enableQueryLog();
        $rows = $this->summarize();
        $withTenBranches = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(10, $rows);
        $this->assertSame($withTwoBranches, $withTenBranches, 'Jumlah query harus konstan terhadap jumlah cabang.');
        // Satu query cabang + satu agregat tagihan.
        $this->assertLessThanOrEqual(2, $withTenBranches);
    }

    public function test_the_query_count_is_constant_as_fees_grow(): void
    {
        $this->fee($this->schoolA);

        $this->summarize();

        DB::enableQueryLog();
        $this->summarize();
        $withOneFee = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        for ($i = 0; $i < 30; $i++) {
            $this->fee($this->schoolA, ['amount' => '10000']);
            $this->fee($this->schoolB, ['amount' => '20000']);
        }

        DB::enableQueryLog();
        $this->summarize();
        $withManyFees = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($withOneFee, $withManyFees, 'Jumlah query harus konstan terhadap jumlah tagihan.');
    }

    // -------------------------------------------------------------- batasan

    /**
     * KAS-03 mengukur tagihan, bukan buku kas — tidak ada transaksi KAS-01 yang
     * ikut terhitung.
     */
    public function test_cash_book_transactions_do_not_affect_the_summary(): void
    {
        Transaction::factory()->create([
            'school_id' => $this->schoolA->id,
            'created_by' => User::factory()->forSchool($this->schoolA)->create()->id,
            'amount' => '9999999',
        ]);

        $row = $this->rowFor($this->summarize(), $this->schoolA);

        $this->assertSame('0.00', $row['total_billed']);
        $this->assertSame('0.00', $row['total_collected']);
    }

    public function test_no_schema_or_payment_transaction_relation_was_added(): void
    {
        foreach (['school_finance_summaries', 'branch_summaries'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }

        $this->assertFalse(Schema::hasColumn('transactions', 'payment_id'));
        $this->assertFalse(Schema::hasColumn('payments', 'transaction_id'));
        $this->assertFalse(method_exists(Transaction::class, 'payments'));
        $this->assertFalse(method_exists(Payment::class, 'transaction'));
    }

    /**
     * Ringkasan hanya menampilkan ketiga metric yang diminta KAS-03, ditambah
     * identitas cabang dan pembilang/penyebut persentasenya.
     */
    public function test_each_row_exposes_only_the_documented_shape(): void
    {
        $this->fee($this->schoolA);

        foreach ($this->summarize() as $row) {
            $this->assertSame([
                'school_id',
                'school_code',
                'school_name',
                'is_active',
                'total_billed',
                'total_collected',
                'paid_count',
                'billable_count',
                'paid_percentage',
            ], array_keys($row));
        }
    }
}
