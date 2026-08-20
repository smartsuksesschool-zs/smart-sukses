<?php

namespace Tests\Feature\Finance;

use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Enums\TransactionType;
use App\Exports\TransactionsExport;
use App\Filament\Resources\TransactionResource\Pages\ListTransactions;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\CashLedgerExporter;
use App\Services\Finance\PaymentRecorder;
use App\Services\Finance\TransactionRecorder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * API 4.9.2 — GET /finance/export: "Export laporan keuangan ke Excel".
 *
 * Isinya buku kas (`transactions`) satu cabang. Tagihan SPP punya ekspornya
 * sendiri (SPP-05) dan tidak ikut di sini.
 */
class CashLedgerExportTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected User $bendaharaA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        Storage::fake('local');

        $this->schoolA = School::factory()->create(['code' => 'ALPHA01']);
        $this->schoolB = School::factory()->create(['code' => 'BETA02']);

        $this->bendaharaA = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::Bendahara)->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function transaction(School $school, array $overrides = [], ?User $creator = null): Transaction
    {
        return Transaction::factory()->create([
            'school_id' => $school->id,
            'created_by' => ($creator ?? $this->bendaharaA)->id,
            'type' => TransactionType::Income->value,
            'category' => 'Dana BOS',
            'amount' => '2500000',
            'description' => 'Pencairan dana BOS triwulan III',
            'reference_number' => 'BOS-2026-III',
            'transaction_date' => '2026-08-05',
            ...$overrides,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function filters(array $overrides = []): array
    {
        return [
            'date_from' => '2026-08-01',
            'date_until' => '2026-08-31',
            ...$overrides,
        ];
    }

    /**
     * Menulis .xlsx sungguhan lalu membacanya ulang dengan PhpSpreadsheet —
     * yang diuji berkasnya, bukan array yang kebetulan dikembalikan exporter.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<int, mixed>>
     */
    protected function exportRows(array $filters, ?User $actor = null): array
    {
        $exporter = app(CashLedgerExporter::class);
        $export = new TransactionsExport($exporter->query($actor ?? $this->bendaharaA, $filters));

        Excel::store($export, 'buku-kas-test.xlsx', 'local');

        $path = Storage::disk('local')->path('buku-kas-test.xlsx');

        $this->assertFileExists($path);

        return IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<int, mixed>>
     */
    protected function dataRows(array $filters, ?User $actor = null): array
    {
        $rows = $this->exportRows($filters, $actor);

        array_shift($rows);

        return array_values($rows);
    }

    // --------------------------------------------------------------- akses

    /**
     * PRD 1.1.1 menyebut "akuntansi & laporan keuangan" sebagai tanggung jawab
     * Bendahara; matriks "Laporan Keuangan" memberi Kepala Sekolah ⭕ saja.
     *
     * @return array<string, array{RoleName, bool}>
     */
    public static function roleExpectations(): array
    {
        return [
            'bendahara' => [RoleName::Bendahara, true],
            'admin sekolah' => [RoleName::SchoolAdmin, true],
            'kepala sekolah' => [RoleName::KepalaSekolah, false],
            'guru' => [RoleName::Guru, false],
            'wali kelas' => [RoleName::WaliKelas, false],
        ];
    }

    #[DataProvider('roleExpectations')]
    public function test_the_export_ability_follows_the_financial_report_matrix(RoleName $role, bool $allowed): void
    {
        $user = User::factory()->forSchool($this->schoolA)->withRole($role)->create();

        $this->assertSame($allowed, $user->can('export', Transaction::class));
    }

    public function test_bendahara_may_export(): void
    {
        $this->transaction($this->schoolA);

        $this->assertCount(1, $this->dataRows($this->filters()));
    }

    public function test_school_admin_may_export(): void
    {
        $admin = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::SchoolAdmin)->create();

        $this->transaction($this->schoolA);

        $this->assertCount(1, $this->dataRows($this->filters(), $admin));
    }

    public function test_super_admin_may_export(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->transaction($this->schoolA);

        $rows = $this->dataRows($this->filters(['school_id' => $this->schoolA->id]), $superAdmin);

        $this->assertCount(1, $rows);
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function rolesThatMayNotExport(): array
    {
        return [
            'kepala sekolah' => [RoleName::KepalaSekolah],
            'guru' => [RoleName::Guru],
            'wali kelas' => [RoleName::WaliKelas],
        ];
    }

    /**
     * Menyembunyikan tombol bukan proteksinya: layanannya menolak juga.
     */
    #[DataProvider('rolesThatMayNotExport')]
    public function test_unauthorized_roles_are_refused_by_the_service(RoleName $role): void
    {
        $user = User::factory()->forSchool($this->schoolA)->withRole($role)->create();

        $this->expectException(AuthorizationException::class);

        app(CashLedgerExporter::class)->download($user, $this->filters());
    }

    public function test_the_action_visibility_follows_the_ability(): void
    {
        $this->actingAs($this->bendaharaA);
        Livewire::test(ListTransactions::class)->assertActionVisible('export');

        $this->actingAs(User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::KepalaSekolah)->create());
        Livewire::test(ListTransactions::class)->assertActionHidden('export');
    }

    // ----------------------------------------------------------------- isi

    public function test_the_header_is_the_seven_ledger_columns(): void
    {
        $header = $this->exportRows($this->filters())[0] ?? [];

        $this->assertSame([
            'Tanggal',
            'Jenis',
            'Kategori',
            'Jumlah',
            'Keterangan',
            'Nomor Referensi',
            'Dicatat Oleh',
        ], $header);
    }

    public function test_a_row_carries_the_documented_values(): void
    {
        $creator = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::Bendahara)->create(['name' => 'Ibu Sari']);

        $this->transaction($this->schoolA, [
            'type' => TransactionType::Expense->value,
            'category' => 'Pembelian Alat',
            'amount' => '1750000.50',
            'description' => 'Proyektor ruang guru',
            'reference_number' => 'NOTA-11',
            'transaction_date' => '2026-08-12',
        ], $creator);

        $row = $this->dataRows($this->filters())[0];

        $this->assertSame('2026-08-12', $row[0]);
        $this->assertSame(TransactionType::Expense->label(), $row[1]);
        $this->assertSame('Pembelian Alat', $row[2]);
        $this->assertEqualsWithDelta(1750000.50, $row[3], 0.001);
        $this->assertSame('Proyektor ruang guru', $row[4]);
        $this->assertSame('NOTA-11', $row[5]);
        $this->assertSame('Ibu Sari', $row[6]);
    }

    /**
     * Nominal tetap sel angka supaya masih dapat dijumlahkan dan diurutkan;
     * rupiahnya hanya format tampilan.
     */
    public function test_the_amount_column_stays_numeric(): void
    {
        $this->transaction($this->schoolA, ['amount' => '2500000']);

        $row = $this->dataRows($this->filters())[0];

        $this->assertIsNumeric($row[3]);
        $this->assertIsNotString($row[3]);

        $formats = (new TransactionsExport(Transaction::query()))->columnFormats();

        $this->assertSame(['D'], array_keys($formats));
    }

    /**
     * Arah kas dibaca dari kolom Jenis; nominalnya tidak pernah dibalik menjadi
     * negatif untuk pengeluaran.
     */
    public function test_expense_amounts_stay_positive(): void
    {
        $this->transaction($this->schoolA, [
            'type' => TransactionType::Expense->value,
            'amount' => '500000',
        ]);

        $row = $this->dataRows($this->filters())[0];

        $this->assertGreaterThan(0, $row[3]);
        $this->assertSame(TransactionType::Expense->label(), $row[1]);
    }

    /**
     * Jalur berkas bukti disimpan di disk privat dan tidak boleh keluar di
     * dalam berkas yang dapat diteruskan ke siapa pun.
     */
    public function test_the_proof_storage_path_never_leaks_into_the_workbook(): void
    {
        $proof = TransactionRecorder::proofDirectory((int) $this->schoolA->id).'/rahasia.pdf';

        $this->transaction($this->schoolA, ['proof_url' => $proof]);

        $rows = $this->exportRows($this->filters());

        $this->assertCount(7, $rows[0]);

        foreach ($rows as $row) {
            foreach ($row as $cell) {
                $this->assertStringNotContainsString('transaction-proofs', (string) $cell);
                $this->assertStringNotContainsString('rahasia.pdf', (string) $cell);
            }
        }
    }

    public function test_the_ledger_is_ordered_by_date(): void
    {
        $this->transaction($this->schoolA, ['transaction_date' => '2026-08-20', 'category' => 'Ketiga']);
        $this->transaction($this->schoolA, ['transaction_date' => '2026-08-02', 'category' => 'Pertama']);
        $this->transaction($this->schoolA, ['transaction_date' => '2026-08-11', 'category' => 'Kedua']);

        $rows = $this->dataRows($this->filters());

        $this->assertSame(['Pertama', 'Kedua', 'Ketiga'], array_column($rows, 2));
    }

    // ------------------------------------------------------------- filter

    public function test_the_date_range_bounds_are_inclusive(): void
    {
        $this->transaction($this->schoolA, ['transaction_date' => '2026-08-01', 'category' => 'Awal']);
        $this->transaction($this->schoolA, ['transaction_date' => '2026-08-31', 'category' => 'Akhir']);

        $this->assertSame(['Awal', 'Akhir'], array_column($this->dataRows($this->filters()), 2));
    }

    public function test_records_outside_the_range_are_excluded(): void
    {
        $this->transaction($this->schoolA, ['transaction_date' => '2026-07-31', 'category' => 'Sebelum']);
        $this->transaction($this->schoolA, ['transaction_date' => '2026-08-15', 'category' => 'Di dalam']);
        $this->transaction($this->schoolA, ['transaction_date' => '2026-09-01', 'category' => 'Sesudah']);

        $this->assertSame(['Di dalam'], array_column($this->dataRows($this->filters()), 2));
    }

    public function test_a_narrower_range_narrows_the_report(): void
    {
        $this->transaction($this->schoolA, ['transaction_date' => '2026-08-05', 'category' => 'Awal Bulan']);
        $this->transaction($this->schoolA, ['transaction_date' => '2026-08-25', 'category' => 'Akhir Bulan']);

        $rows = $this->dataRows($this->filters(['date_from' => '2026-08-20', 'date_until' => '2026-08-31']));

        $this->assertSame(['Akhir Bulan'], array_column($rows, 2));
    }

    public function test_the_income_filter_narrows_the_report(): void
    {
        $this->transaction($this->schoolA, ['type' => TransactionType::Income->value, 'category' => 'Masuk']);
        $this->transaction($this->schoolA, ['type' => TransactionType::Expense->value, 'category' => 'Keluar']);

        $rows = $this->dataRows($this->filters(['type' => TransactionType::Income->value]));

        $this->assertSame(['Masuk'], array_column($rows, 2));
    }

    public function test_the_expense_filter_narrows_the_report(): void
    {
        $this->transaction($this->schoolA, ['type' => TransactionType::Income->value, 'category' => 'Masuk']);
        $this->transaction($this->schoolA, ['type' => TransactionType::Expense->value, 'category' => 'Keluar']);

        $rows = $this->dataRows($this->filters(['type' => TransactionType::Expense->value]));

        $this->assertSame(['Keluar'], array_column($rows, 2));
    }

    public function test_the_category_filter_matches_exactly(): void
    {
        $this->transaction($this->schoolA, ['category' => 'Gaji']);
        $this->transaction($this->schoolA, ['category' => 'Gaji Honorer']);

        $rows = $this->dataRows($this->filters(['category' => 'Gaji']));

        $this->assertSame(['Gaji'], array_column($rows, 2));
    }

    public function test_all_filters_combine(): void
    {
        $this->transaction($this->schoolA, ['type' => TransactionType::Expense->value, 'category' => 'Gaji', 'transaction_date' => '2026-08-10']);
        $this->transaction($this->schoolA, ['type' => TransactionType::Income->value, 'category' => 'Gaji', 'transaction_date' => '2026-08-10']);
        $this->transaction($this->schoolA, ['type' => TransactionType::Expense->value, 'category' => 'Dana BOS', 'transaction_date' => '2026-08-10']);
        $this->transaction($this->schoolA, ['type' => TransactionType::Expense->value, 'category' => 'Gaji', 'transaction_date' => '2026-09-10']);

        $rows = $this->dataRows($this->filters([
            'type' => TransactionType::Expense->value,
            'category' => 'Gaji',
        ]));

        $this->assertCount(1, $rows);
        $this->assertSame('2026-08-10', $rows[0][0]);
    }

    public function test_a_blank_type_or_category_means_no_filter(): void
    {
        $this->transaction($this->schoolA, ['type' => TransactionType::Income->value]);
        $this->transaction($this->schoolA, ['type' => TransactionType::Expense->value]);

        foreach ([null, '', '   '] as $blank) {
            $rows = $this->dataRows($this->filters(['type' => $blank, 'category' => $blank]));

            $this->assertCount(2, $rows);
        }
    }

    /**
     * @return array<string, array{mixed, mixed, string}>
     */
    public static function invalidRanges(): array
    {
        return [
            'tanpa tanggal mulai' => [null, '2026-08-31', 'date_from'],
            'tanpa tanggal akhir' => ['2026-08-01', null, 'date_until'],
            'mulai kosong' => ['', '2026-08-31', 'date_from'],
            'akhir tidak valid' => ['2026-08-01', 'bukan-tanggal', 'date_until'],
            'akhir mendahului mulai' => ['2026-08-31', '2026-08-01', 'date_until'],
        ];
    }

    #[DataProvider('invalidRanges')]
    public function test_an_invalid_range_is_rejected(mixed $from, mixed $until, string $errorKey): void
    {
        try {
            $this->exportRows(['date_from' => $from, 'date_until' => $until]);
            $this->fail('Rentang tanggal tidak sah seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($errorKey, $e->errors());
        }
    }

    public function test_a_single_day_range_is_allowed(): void
    {
        $this->transaction($this->schoolA, ['transaction_date' => '2026-08-05']);

        $rows = $this->dataRows(['date_from' => '2026-08-05', 'date_until' => '2026-08-05']);

        $this->assertCount(1, $rows);
    }

    public function test_an_unknown_type_is_rejected(): void
    {
        try {
            $this->exportRows($this->filters(['type' => 'TRANSFER']));
            $this->fail('Jenis transaksi tidak dikenal seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('type', $e->errors());
        }
    }

    public function test_category_options_come_from_the_report_branch(): void
    {
        $this->transaction($this->schoolA, ['category' => 'Dana BOS']);
        $this->transaction($this->schoolA, ['category' => 'Dana BOS']);
        $this->transaction($this->schoolB, ['category' => 'Kategori Cabang Lain'], User::factory()->forSchool($this->schoolB)->create());

        $options = app(CashLedgerExporter::class)->categoryOptions((int) $this->schoolA->id);

        $this->assertSame(['Dana BOS'], array_values($options));
        $this->assertSame([], app(CashLedgerExporter::class)->categoryOptions(null));
    }

    // -------------------------------------------------------------- tenant

    public function test_another_branch_never_appears_in_the_report(): void
    {
        $this->transaction($this->schoolA, ['category' => 'Milik Saya']);
        $this->transaction($this->schoolB, ['category' => 'Cabang Lain'], User::factory()->forSchool($this->schoolB)->create());

        $rows = $this->dataRows($this->filters());

        $this->assertSame(['Milik Saya'], array_column($rows, 2));
    }

    public function test_a_crafted_school_id_cannot_move_the_report(): void
    {
        $this->transaction($this->schoolA, ['category' => 'Milik Saya']);
        $this->transaction($this->schoolB, ['category' => 'Cabang Lain'], User::factory()->forSchool($this->schoolB)->create());

        $rows = $this->dataRows($this->filters(['school_id' => $this->schoolB->id]));

        $this->assertSame(['Milik Saya'], array_column($rows, 2));
    }

    public function test_a_school_level_account_without_a_branch_cannot_export(): void
    {
        $orphan = User::factory()->withRole(RoleName::Bendahara)->create(['school_id' => null]);

        try {
            $this->exportRows($this->filters(), $orphan);
            $this->fail('Akun tanpa cabang seharusnya tidak dapat mengekspor.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('school_id', $e->errors());
        }
    }

    public function test_super_admin_must_choose_a_branch(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        foreach ([null, '', 'bukan-angka', 999_999] as $value) {
            try {
                $this->exportRows($this->filters(['school_id' => $value]), $superAdmin);
                $this->fail('Cabang tidak sah seharusnya ditolak.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('school_id', $e->errors());
            }
        }
    }

    public function test_super_admin_exports_one_branch_at_a_time(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->transaction($this->schoolA, ['category' => 'Milik Alpha']);
        $this->transaction($this->schoolB, ['category' => 'Milik Beta'], User::factory()->forSchool($this->schoolB)->create());

        $rows = $this->dataRows($this->filters(['school_id' => $this->schoolB->id]), $superAdmin);

        $this->assertSame(['Milik Beta'], array_column($rows, 2));
    }

    // ---------------------------------------------------- domain terpisah

    /**
     * Pembayaran SPP tidak pernah muncul di buku kas: keduanya dua jalur
     * terpisah, dan tagihan punya ekspornya sendiri (SPP-05).
     */
    public function test_payments_never_appear_in_the_cash_ledger(): void
    {
        $fee = StudentFee::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_id' => Student::factory()->create(['school_id' => $this->schoolA->id])->id,
            'fee_type_id' => FeeType::factory()->create(['school_id' => $this->schoolA->id])->id,
            'amount' => '1000000',
        ]);

        app(PaymentRecorder::class)->record($fee->getKey(), [
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => '400000',
            'payment_date' => '2026-08-05',
        ], $this->bendaharaA);

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame([], $this->dataRows($this->filters()));
    }

    public function test_no_schema_or_payment_transaction_relation_was_added(): void
    {
        foreach (['deleted_at', 'is_active', 'status', 'voided_at', 'payment_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('transactions', $column));
        }

        $this->assertFalse(Schema::hasColumn('payments', 'transaction_id'));
        $this->assertFalse(method_exists(Transaction::class, 'payments'));
        $this->assertFalse(method_exists(Payment::class, 'transaction'));
        $this->assertFalse(Schema::hasTable('finance_exports'));
    }

    // ---------------------------------------------------------- nama berkas

    public function test_the_file_name_follows_the_existing_convention(): void
    {
        $name = app(CashLedgerExporter::class)->fileName([
            'school_id' => $this->schoolA->id,
            'date_from' => '2026-08-01',
            'date_until' => '2026-08-31',
        ]);

        $this->assertSame('buku-kas_alpha01_2026-08-01_2026-08-31.xlsx', $name);
    }

    public function test_the_file_name_carries_no_unsafe_characters(): void
    {
        $messy = School::factory()->create(['code' => 'A B/C:01']);

        $name = app(CashLedgerExporter::class)->fileName([
            'school_id' => $messy->id,
            'date_from' => '2026-08-01',
            'date_until' => '2026-08-31',
        ]);

        $this->assertMatchesRegularExpression('/^buku-kas_[a-z0-9\-]+_\d{4}-\d{2}-\d{2}_\d{4}-\d{2}-\d{2}\.xlsx$/', $name);
        $this->assertStringNotContainsString('/', $name);
        $this->assertStringNotContainsString(':', $name);
        $this->assertStringNotContainsString(' ', $name);
    }

    // --------------------------------------------------------- performance

    public function test_the_query_count_does_not_grow_with_the_number_of_rows(): void
    {
        $makeRows = function (int $count): void {
            for ($i = 0; $i < $count; $i++) {
                $this->transaction(
                    $this->schoolA,
                    ['transaction_date' => '2026-08-05'],
                    User::factory()->forSchool($this->schoolA)->create(),
                );
            }
        };

        $makeRows(1);

        // Pemanasan: izin Spatie dimuat lazy sekali saja.
        $this->dataRows($this->filters());

        DB::enableQueryLog();
        $this->dataRows($this->filters());
        $withOneRow = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        // Setiap baris punya pencatat berbeda — persis kondisi yang akan
        // memunculkan N+1 bila `createdBy` tidak di-eager-load.
        $makeRows(19);

        DB::enableQueryLog();
        $rows = $this->dataRows($this->filters());
        $withTwentyRows = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(20, $rows);
        $this->assertSame(
            $withOneRow,
            $withTwentyRows,
            'Jumlah query export harus konstan terhadap jumlah baris.',
        );
    }

    public function test_every_row_resolves_its_creator(): void
    {
        foreach (['Ibu Sari', 'Pak Budi', 'Ibu Rina'] as $name) {
            $this->transaction(
                $this->schoolA,
                [],
                User::factory()->forSchool($this->schoolA)->create(['name' => $name]),
            );
        }

        $creators = array_column($this->dataRows($this->filters()), 6);

        sort($creators);

        $this->assertSame(['Ibu Rina', 'Ibu Sari', 'Pak Budi'], $creators);
    }
}
