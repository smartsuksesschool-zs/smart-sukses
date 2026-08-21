<?php

namespace Tests\Feature\Finance;

use App\Enums\AuditAction;
use App\Enums\RoleName;
use App\Enums\TransactionType;
use App\Filament\Resources\TransactionResource\Pages\ListTransactions;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\Transaction;
use App\Models\User;
use App\Policies\TransactionPolicy;
use App\Services\Finance\CashLedgerExporter;
use App\Services\Finance\FinanceSummaryService;
use App\Services\Finance\TransactionRecorder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * API 4.9.2 — DELETE /transactions/{id}: "Hapus transaksi (soft delete)".
 *
 * Kolom penyimpannya tidak ada di ERD dan ditambahkan sebagai keputusan
 * implementasi Phase 1 (butir 128). Yang diuji di sini bukan hanya bahwa
 * barisnya tersembunyi, melainkan bahwa ia hilang dari **setiap** pembacanya —
 * buku kas, saldo, tren, ringkasan, dan ekspor — tanpa satu pun angka
 * tertinggal.
 */
class DeleteTransactionTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected User $schoolAdminA;

    protected User $bendaharaA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create();
        $this->schoolB = School::factory()->create();

        $this->schoolAdminA = $this->userIn($this->schoolA, RoleName::SchoolAdmin);
        $this->bendaharaA = $this->userIn($this->schoolA, RoleName::Bendahara);
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

    protected function remove(Transaction $transaction, User $actor): Transaction
    {
        return app(TransactionRecorder::class)->delete($transaction->getKey(), $actor);
    }

    // ------------------------------------------------------- siapa yang boleh

    public function test_a_school_admin_deletes_a_transaction(): void
    {
        $transaction = $this->transactionIn($this->schoolA);

        $this->remove($transaction, $this->schoolAdminA);

        $this->assertNotNull(Transaction::withTrashed()->find($transaction->id)?->deleted_at);
    }

    public function test_a_super_admin_deletes_a_transaction(): void
    {
        $superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create(['school_id' => null]);
        $transaction = $this->transactionIn($this->schoolA);

        $this->remove($transaction, $superAdmin);

        $this->assertNotNull(Transaction::withTrashed()->find($transaction->id)?->deleted_at);
    }

    /**
     * Bendahara memegang `accounting.manage` dan mencatat serta mengoreksi buku
     * kas, tetapi tidak menghapusnya: tidak ada user story penghapusan di PRD,
     * sehingga label "Admin" pada API tidak tertimpa apa pun (butir 129).
     */
    public function test_bendahara_cannot_delete(): void
    {
        $transaction = $this->transactionIn($this->schoolA);

        $this->expectException(AuthorizationException::class);

        try {
            $this->remove($transaction, $this->bendaharaA);
        } finally {
            $this->assertNull($transaction->fresh()->deleted_at);
        }
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function deniedRoles(): array
    {
        return [
            'bendahara' => [RoleName::Bendahara],
            'kepala sekolah' => [RoleName::KepalaSekolah],
            'guru' => [RoleName::Guru],
            'wali kelas' => [RoleName::WaliKelas],
            'siswa' => [RoleName::Siswa],
            'orang tua' => [RoleName::OrangTua],
        ];
    }

    #[DataProvider('deniedRoles')]
    public function test_the_role_matrix_decides_who_may_delete(RoleName $role): void
    {
        $transaction = $this->transactionIn($this->schoolA);
        $actor = $this->userIn($this->schoolA, $role);

        $this->expectException(AuthorizationException::class);

        try {
            $this->remove($transaction, $actor);
        } finally {
            $this->assertNull($transaction->fresh()->deleted_at);
        }
    }

    public function test_bendahara_may_still_create_and_edit(): void
    {
        $transaction = $this->transactionIn($this->schoolA);

        $updated = app(TransactionRecorder::class)->update($transaction->getKey(), [
            'type' => TransactionType::Expense->value,
            'category' => 'Gaji',
            'amount' => 500000,
            'transaction_date' => '2026-08-06',
            'description' => 'Koreksi nominal',
            'reference_number' => 'K-1',
        ], $this->bendaharaA);

        $this->assertSame('Gaji', $updated->category);
        $this->assertNull($updated->deleted_at);
    }

    /**
     * Konvensi yang sama dengan `update()` pada rute yang sama: keberadaan
     * transaksi cabang lain tidak dikonfirmasi, dan pemeriksaan izin tidak
     * pernah tercapai (butir 130).
     */
    public function test_a_transaction_from_another_branch_is_never_reached(): void
    {
        $foreign = $this->transactionIn($this->schoolB);

        $this->expectException(ValidationException::class);

        try {
            $this->remove($foreign, $this->schoolAdminA);
        } finally {
            $this->assertNull($foreign->fresh()->deleted_at);
        }
    }

    // ------------------------------------------------------- apa yang terjadi

    public function test_the_row_survives_and_only_deleted_at_changes(): void
    {
        $transaction = $this->transactionIn($this->schoolA, [
            'category' => 'Dana BOS',
            'description' => 'Pencairan triwulan III',
            'reference_number' => 'BOS-1',
        ]);
        // Dibaca ulang dari database supaya perbandingannya adil: nilai hasil
        // factory belum tentu berformat sama dengan nilai yang dikembalikan
        // driver.
        $before = $transaction->fresh()->getAttributes();

        $this->remove($transaction, $this->schoolAdminA);

        $this->assertDatabaseCount('transactions', 1);

        $after = Transaction::withTrashed()->findOrFail($transaction->id)->getAttributes();

        $this->assertNotNull($after['deleted_at']);

        $columns = [
            'school_id', 'type', 'category', 'amount', 'description',
            'reference_number', 'proof_url', 'transaction_date', 'created_by', 'created_at',
        ];

        foreach ($columns as $column) {
            $this->assertSame(
                $before[$column] ?? null,
                $after[$column] ?? null,
                "Kolom {$column} ikut berubah saat penghapusan.",
            );
        }
    }

    public function test_the_proof_file_is_kept(): void
    {
        Storage::fake(TransactionRecorder::PROOF_DISK);

        $path = TransactionRecorder::proofDirectory($this->schoolA->id).'/nota.pdf';
        Storage::disk(TransactionRecorder::PROOF_DISK)->put($path, '%PDF-1.4');

        $transaction = $this->transactionIn($this->schoolA, ['proof_url' => $path]);

        $this->remove($transaction, $this->schoolAdminA);

        // Nota adalah dokumen sumber; transaksi yang dihapus karena salah catat
        // justru sering perlu ditelusuri kembali lewat buktinya (butir 132).
        Storage::disk(TransactionRecorder::PROOF_DISK)->assertExists($path);
        $this->assertSame($path, Transaction::withTrashed()->find($transaction->id)?->proof_url);
    }

    public function test_nothing_is_hard_deleted_and_no_recovery_ability_is_granted(): void
    {
        $transaction = $this->transactionIn($this->schoolA);

        $this->remove($transaction, $this->schoolAdminA);

        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);

        $policy = app(TransactionPolicy::class);

        $this->assertFalse($policy->forceDelete($this->schoolAdminA, $transaction));
        $this->assertFalse($policy->restore($this->schoolAdminA, $transaction));
    }

    public function test_no_restore_or_trashed_route_is_exposed(): void
    {
        foreach (collect(Route::getRoutes())->map(fn ($route) => $route->uri())->all() as $uri) {
            $this->assertStringNotContainsString('restore', $uri);
            $this->assertStringNotContainsString('trashed', $uri);
        }
    }

    public function test_the_only_added_column_is_deleted_at(): void
    {
        $this->assertTrue(Schema::hasColumn('transactions', 'deleted_at'));

        foreach (['status', 'is_active', 'is_deleted', 'voided_at', 'void_reason', 'deleted_by'] as $column) {
            $this->assertFalse(Schema::hasColumn('transactions', $column));
        }
    }

    // ------------------------------------------------------------------ audit

    public function test_deleting_writes_exactly_one_audit_row(): void
    {
        $transaction = $this->transactionIn($this->schoolA);

        $this->actingAs($this->schoolAdminA);
        AuditLog::query()->withoutGlobalScope(SchoolScope::class)->delete();

        $this->remove($transaction, $this->schoolAdminA);

        $rows = AuditLog::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('auditable_type', Transaction::class)
            ->where('auditable_id', $transaction->id)
            ->get();

        // Soft delete menulis lewat query builder di dalam SoftDeletes, sehingga
        // tidak ada event `updated` yang ikut terpicu: satu baris DELETED, bukan
        // UPDATED + DELETED (butir 138).
        $this->assertCount(1, $rows);
        $this->assertSame(AuditAction::Deleted, $rows->first()->action);
        $this->assertSame($this->schoolA->id, $rows->first()->school_id);
        $this->assertSame($this->schoolAdminA->id, $rows->first()->user_id);
    }

    public function test_a_refused_delete_writes_no_audit_row(): void
    {
        $transaction = $this->transactionIn($this->schoolA);

        $this->actingAs($this->bendaharaA);
        AuditLog::query()->withoutGlobalScope(SchoolScope::class)->delete();

        try {
            $this->remove($transaction, $this->bendaharaA);
        } catch (AuthorizationException) {
            // Diharapkan.
        }

        $this->assertSame(0, AuditLog::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('auditable_type', Transaction::class)
            ->count());
    }

    // ----------------------------------------- hilang dari seluruh pembacanya

    public function test_a_deleted_transaction_leaves_the_default_query(): void
    {
        $transaction = $this->transactionIn($this->schoolA);

        $this->remove($transaction, $this->schoolAdminA);

        $this->assertSame(0, Transaction::query()->withoutGlobalScope(SchoolScope::class)->count());
        $this->assertSame(1, Transaction::withTrashed()->withoutGlobalScope(SchoolScope::class)->count());
    }

    public function test_it_disappears_from_the_filament_list(): void
    {
        $kept = $this->transactionIn($this->schoolA, ['category' => 'Tetap']);
        $removed = $this->transactionIn($this->schoolA, ['category' => 'Dihapus']);

        $this->remove($removed, $this->schoolAdminA);

        $this->actingAs($this->bendaharaA);

        Livewire::test(ListTransactions::class)
            ->assertCanSeeTableRecords([$kept])
            ->assertCanNotSeeTableRecords([$removed]);
    }

    public function test_it_disappears_from_the_cash_ledger_export(): void
    {
        $kept = $this->transactionIn($this->schoolA, ['category' => 'Tetap']);
        $removed = $this->transactionIn($this->schoolA, ['category' => 'Dihapus']);

        $this->remove($removed, $this->schoolAdminA);

        $ids = app(CashLedgerExporter::class)->query($this->bendaharaA, [
            'school_id' => $this->schoolA->id,
            'date_from' => '2026-08-01',
            'date_until' => '2026-08-31',
        ])->pluck('id')->all();

        $this->assertSame([$kept->id], $ids);
    }

    public function test_it_leaves_the_kas_02_balance_and_trend(): void
    {
        $this->transactionIn($this->schoolA, ['amount' => '1000000']);
        $removed = $this->transactionIn($this->schoolA, ['amount' => '250000']);

        $this->remove($removed, $this->schoolAdminA);

        $summary = app(FinanceSummaryService::class)->summarize($this->schoolA->id, '2026-08');

        $this->assertSame('1000000.00', $summary['cash_balance']);

        $august = collect($summary['trend'])->firstWhere('period', '2026-08');

        $this->assertSame('1000000.00', $august['income']);
    }

    public function test_it_leaves_the_finance_summary(): void
    {
        $this->transactionIn($this->schoolA, [
            'type' => TransactionType::Expense->value,
            'amount' => '400000',
        ]);
        $removed = $this->transactionIn($this->schoolA, [
            'type' => TransactionType::Expense->value,
            'amount' => '100000',
        ]);

        $this->remove($removed, $this->schoolAdminA);

        $summary = app(FinanceSummaryService::class)->monthlySummary($this->schoolA->id, 2026, 8);

        $this->assertSame('400000.00', $summary['expense']);
        $this->assertSame('-400000.00', $summary['balance']);
    }

    // ------------------------------------------------------------------ panel

    public function test_the_delete_action_is_hidden_from_bendahara(): void
    {
        $transaction = $this->transactionIn($this->schoolA);

        $this->actingAs($this->bendaharaA);

        Livewire::test(ListTransactions::class)
            ->assertTableActionHidden('softDelete', $transaction);
    }

    public function test_a_school_admin_deletes_from_the_panel(): void
    {
        $transaction = $this->transactionIn($this->schoolA);

        $this->actingAs($this->schoolAdminA);

        Livewire::test(ListTransactions::class)
            ->assertTableActionVisible('softDelete', $transaction)
            ->callTableAction('softDelete', $transaction);

        $this->assertNotNull(Transaction::withTrashed()->find($transaction->id)?->deleted_at);
    }

    public function test_the_panel_has_no_bulk_delete(): void
    {
        $transaction = $this->transactionIn($this->schoolA);

        $this->actingAs($this->schoolAdminA);

        Livewire::test(ListTransactions::class)
            ->assertTableBulkActionDoesNotExist('delete');

        $this->assertNull($transaction->fresh()->deleted_at);
    }
}
