<?php

namespace Tests\Feature\Finance;

use App\Enums\RoleName;
use App\Enums\TransactionType;
use App\Models\School;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\TransactionRecorder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * KAS-01 — "Sebagai Bendahara, saya dapat mencatat pemasukan dan pengeluaran
 * kas sekolah", dan PRD 1.1.2 modul "Akuntansi & Kas": SUPER_ADMIN ✅,
 * SCHOOL_ADMIN ✅, KEPALA ⭕, GURU/WALI ❌, BENDAHARA ✅.
 */
class TransactionRecorderTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function input(array $overrides = []): array
    {
        return [
            'type' => TransactionType::Income->value,
            'category' => 'Dana BOS',
            'amount' => 2_500_000,
            'transaction_date' => '2026-08-05',
            // Wajib menurut aturan validasi KAS-01 (butir 81).
            'description' => 'Pencairan dana BOS triwulan III',
            'reference_number' => 'BOS-2026-III',
            ...$overrides,
        ];
    }

    protected function record(array $overrides = [], ?User $actor = null): Transaction
    {
        return app(TransactionRecorder::class)
            ->record($this->input($overrides), $actor ?? $this->bendaharaA);
    }

    // --------------------------------------------------------------- create

    public function test_bendahara_records_an_income(): void
    {
        $transaction = $this->record();

        $this->assertSame(TransactionType::Income, $transaction->type);
        $this->assertSame('Dana BOS', $transaction->category);
        $this->assertSame('2500000.00', (string) $transaction->amount);
        $this->assertSame('2026-08-05', $transaction->transaction_date->toDateString());
        $this->assertSame($this->schoolA->id, $transaction->school_id);
        $this->assertSame($this->bendaharaA->id, $transaction->created_by);
    }

    public function test_bendahara_records_an_expense(): void
    {
        $transaction = $this->record([
            'type' => TransactionType::Expense->value,
            'category' => 'Gaji',
            'amount' => 7_500_000,
            'description' => 'Gaji guru Agustus',
            'reference_number' => 'NOTA-08',
        ]);

        $this->assertSame(TransactionType::Expense, $transaction->type);
        // Pengeluaran tetap tersimpan positif; arahnya dari `type`.
        $this->assertSame('7500000.00', (string) $transaction->amount);
        $this->assertSame('Gaji guru Agustus', $transaction->description);
        $this->assertSame('NOTA-08', $transaction->reference_number);
    }

    public function test_school_admin_records_a_transaction(): void
    {
        $admin = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::SchoolAdmin)->create();

        $transaction = $this->record(actor: $admin);

        $this->assertSame($this->schoolA->id, $transaction->school_id);
        $this->assertSame($admin->id, $transaction->created_by);
    }

    /**
     * Super Admin tidak punya `school_id` (Arsitektur 3.2.2), sehingga cabang
     * wajib dipilih eksplisit.
     */
    public function test_super_admin_records_a_transaction_for_an_explicit_branch(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $transaction = $this->record(['school_id' => $this->schoolB->id], $superAdmin);

        $this->assertSame($this->schoolB->id, $transaction->school_id);
        $this->assertSame($superAdmin->id, $transaction->created_by);
    }

    public function test_super_admin_must_choose_a_branch(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        foreach ([null, '', 'bukan-angka', 999_999] as $value) {
            try {
                $this->record(['school_id' => $value], $superAdmin);
                $this->fail('Cabang tidak sah seharusnya ditolak.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('school_id', $e->errors());
            }
        }

        $this->assertDatabaseCount('transactions', 0);
    }

    // ----------------------------------------------------------------- RBAC

    /**
     * @return array<string, array{RoleName}>
     */
    public static function rolesThatMayNotRecord(): array
    {
        return [
            // Matriks: "Akuntansi & Kas" KEPALA = ⭕ — melihat, tidak mengelola.
            'kepala sekolah' => [RoleName::KepalaSekolah],
            'guru' => [RoleName::Guru],
            'wali kelas' => [RoleName::WaliKelas],
        ];
    }

    #[DataProvider('rolesThatMayNotRecord')]
    public function test_unauthorized_roles_cannot_record(RoleName $role): void
    {
        $user = User::factory()->forSchool($this->schoolA)->withRole($role)->create();

        $this->assertFalse($user->can('create', Transaction::class));

        $this->expectException(AuthorizationException::class);

        $this->record(actor: $user);
    }

    public function test_kepala_sekolah_may_view_but_not_manage(): void
    {
        $kepala = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::KepalaSekolah)->create();
        $transaction = Transaction::factory()->create([
            'school_id' => $this->schoolA->id,
            'created_by' => $this->bendaharaA->id,
        ]);

        $this->assertTrue($kepala->can('viewAny', Transaction::class));
        $this->assertTrue($kepala->can('view', $transaction));
        $this->assertFalse($kepala->can('create', Transaction::class));
        $this->assertFalse($kepala->can('update', $transaction));
        $this->assertFalse($kepala->can('delete', $transaction));
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function rolesWithoutAccountingAccess(): array
    {
        return [
            'guru' => [RoleName::Guru],
            'wali kelas' => [RoleName::WaliKelas],
        ];
    }

    #[DataProvider('rolesWithoutAccountingAccess')]
    public function test_roles_outside_the_module_cannot_even_read(RoleName $role): void
    {
        $user = User::factory()->forSchool($this->schoolA)->withRole($role)->create();
        $transaction = Transaction::factory()->create([
            'school_id' => $this->schoolA->id,
            'created_by' => $this->bendaharaA->id,
        ]);

        $this->assertFalse($user->can('viewAny', Transaction::class));
        $this->assertFalse($user->can('view', $transaction));
    }

    // ---------------------------------------------------------- validation

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidAmounts(): array
    {
        return [
            'nol' => [0],
            'negatif' => [-100_000],
            'bukan angka' => ['dua juta'],
            'melebihi kolom' => ['99999999999.99'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amounts_are_rejected(mixed $amount): void
    {
        try {
            $this->record(['amount' => $amount]);
            $this->fail('Jumlah tidak sah seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $this->assertDatabaseCount('transactions', 0);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidTypes(): array
    {
        return [
            'kosong' => [null],
            'tidak dikenal' => ['TRANSFER'],
            'huruf kecil' => ['income'],
            'angka' => [1],
        ];
    }

    #[DataProvider('invalidTypes')]
    public function test_invalid_types_are_rejected(mixed $type): void
    {
        try {
            $this->record(['type' => $type]);
            $this->fail('Jenis transaksi tidak sah seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('type', $e->errors());
        }

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_category_is_required(): void
    {
        foreach ([null, '', '   ', 12345] as $category) {
            try {
                $this->record(['category' => $category]);
                $this->fail('Kategori kosong seharusnya ditolak.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('category', $e->errors());
            }
        }
    }

    public function test_category_is_capped_at_the_erd_column_length(): void
    {
        try {
            $this->record(['category' => str_repeat('a', 101)]);
            $this->fail('Kategori di atas 100 karakter seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('category', $e->errors());
        }

        $accepted = $this->record(['category' => str_repeat('a', 100)]);

        $this->assertSame(100, mb_strlen($accepted->category));
    }

    /**
     * ERD: "Kategori: Gaji, Pembelian Alat, Dana BOS, Sumbangan, **dll.**" —
     * teks bebas, bukan enum.
     */
    public function test_categories_outside_the_erd_examples_are_accepted(): void
    {
        foreach (['Konsumsi Rapat Komite', 'Perbaikan Atap Aula', 'Zakat Maal'] as $category) {
            $transaction = $this->record(['category' => $category]);

            $this->assertSame($category, $transaction->category);
        }

        $this->assertDatabaseCount('transactions', 3);
    }

    /**
     * Aturan validasi KAS-01 mewajibkan keterangan dan nomor referensi,
     * walaupun ERD memberi kedua kolomnya NULL — nullability database dan
     * aturan alur kerja adalah dua hal berbeda (butir 81).
     *
     * @return array<string, array{string}>
     */
    public static function requiredWorkflowFields(): array
    {
        return [
            'keterangan' => ['description'],
            'nomor referensi' => ['reference_number'],
        ];
    }

    #[DataProvider('requiredWorkflowFields')]
    public function test_a_workflow_required_field_cannot_be_omitted(string $field): void
    {
        $input = $this->input();
        unset($input[$field]);

        try {
            app(TransactionRecorder::class)->record($input, $this->bendaharaA);
            $this->fail("Transaksi tanpa {$field} seharusnya ditolak.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }

        $this->assertDatabaseCount('transactions', 0);
    }

    #[DataProvider('requiredWorkflowFields')]
    public function test_a_workflow_required_field_cannot_be_blank(string $field): void
    {
        foreach ([null, '', '   ', 12345] as $value) {
            try {
                $this->record([$field => $value]);
                $this->fail("Transaksi dengan {$field} kosong seharusnya ditolak.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }

        $this->assertDatabaseCount('transactions', 0);
    }

    /**
     * Payload yang dirakit sendiri — persis yang dapat dikirim melewati form —
     * tetap ditolak layanan, bukan hanya oleh validasi UI.
     */
    public function test_a_crafted_service_call_without_either_field_is_rejected(): void
    {
        try {
            app(TransactionRecorder::class)->record([
                'type' => TransactionType::Expense->value,
                'category' => 'Pembelian Alat',
                'amount' => 500_000,
                'transaction_date' => '2026-08-05',
            ], $this->bendaharaA);
            $this->fail('Transaksi tanpa keterangan dan referensi seharusnya ditolak.');
        } catch (ValidationException $e) {
            // Yang pertama gagal sudah cukup menghentikannya; keduanya diuji
            // satu per satu oleh test di atas.
            $this->assertNotEmpty(
                array_intersect(['description', 'reference_number'], array_keys($e->errors())),
            );
        }

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_reference_number_is_capped_at_the_erd_column_length(): void
    {
        try {
            $this->record(['reference_number' => str_repeat('R', 101)]);
            $this->fail('Nomor referensi di atas 100 karakter seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reference_number', $e->errors());
        }

        $accepted = $this->record(['reference_number' => str_repeat('R', 100)]);

        $this->assertSame(100, mb_strlen($accepted->reference_number));
    }

    /**
     * Kewajiban ini ada di alur kerja, bukan di skema: kolomnya tetap NULL.
     */
    public function test_the_migration_nullability_was_not_changed(): void
    {
        $transaction = Transaction::factory()->create([
            'description' => null,
            'reference_number' => null,
        ]);

        $this->assertNull($transaction->fresh()->description);
        $this->assertNull($transaction->fresh()->reference_number);
    }

    /**
     * Aturan KAS-01 juga berlaku saat mengubah — termasuk untuk baris lama yang
     * terlanjur tersimpan tanpa keduanya.
     */
    public function test_editing_a_legacy_record_must_supply_the_required_fields(): void
    {
        $legacy = Transaction::factory()->create([
            'school_id' => $this->schoolA->id,
            'created_by' => $this->bendaharaA->id,
            'description' => null,
            'reference_number' => null,
        ]);

        $input = $this->input();
        unset($input['description'], $input['reference_number']);

        try {
            app(TransactionRecorder::class)->update($legacy->getKey(), $input, $this->bendaharaA);
            $this->fail('Perubahan tanpa keterangan dan referensi seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty(
                array_intersect(['description', 'reference_number'], array_keys($e->errors())),
            );
        }

        $this->assertNull($legacy->fresh()->description);

        $updated = app(TransactionRecorder::class)
            ->update($legacy->getKey(), $this->input(), $this->bendaharaA);

        $this->assertSame('Pencairan dana BOS triwulan III', $updated->description);
        $this->assertSame('BOS-2026-III', $updated->reference_number);
    }

    public function test_transaction_date_is_required_and_must_be_valid(): void
    {
        foreach ([null, '', 'bukan-tanggal'] as $value) {
            try {
                $this->record(['transaction_date' => $value]);
                $this->fail('Tanggal tidak sah seharusnya ditolak.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('transaction_date', $e->errors());
            }
        }

        $this->assertDatabaseCount('transactions', 0);
    }

    // ------------------------------------------------------ payload tepercaya

    /**
     * `created_by` dan `school_id` tidak pernah dibaca dari payload.
     */
    public function test_smuggled_identity_fields_are_ignored(): void
    {
        $someoneElse = User::factory()->forSchool($this->schoolB)
            ->withRole(RoleName::Bendahara)->create();

        $transaction = $this->record([
            'created_by' => $someoneElse->id,
            'school_id' => $this->schoolB->id,
            'id' => 999,
        ]);

        $this->assertSame($this->bendaharaA->id, $transaction->created_by);
        $this->assertSame($this->schoolA->id, $transaction->school_id);
    }

    public function test_a_school_level_account_without_a_branch_cannot_record(): void
    {
        $orphan = User::factory()->withRole(RoleName::Bendahara)->create(['school_id' => null]);

        try {
            $this->record(actor: $orphan);
            $this->fail('Akun tanpa cabang seharusnya tidak dapat mencatat.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('school_id', $e->errors());
        }

        $this->assertDatabaseCount('transactions', 0);
    }

    // ----------------------------------------------------------------- edit

    public function test_bendahara_edits_a_transaction_in_its_own_branch(): void
    {
        $transaction = $this->record();

        $updated = app(TransactionRecorder::class)->update($transaction->getKey(), $this->input([
            'category' => 'Sumbangan',
            'amount' => 3_000_000,
            'type' => TransactionType::Expense->value,
        ]), $this->bendaharaA);

        $this->assertSame('Sumbangan', $updated->category);
        $this->assertSame('3000000.00', (string) $updated->amount);
        $this->assertSame(TransactionType::Expense, $updated->type);
    }

    /**
     * Pencatat aslinya tidak ditimpa editor; siapa yang mengubah tercatat di
     * `audit_logs`.
     */
    public function test_editing_never_rewrites_the_original_creator_or_branch(): void
    {
        $transaction = $this->record();

        $admin = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::SchoolAdmin)->create();

        $updated = app(TransactionRecorder::class)->update($transaction->getKey(), $this->input([
            'category' => 'Koreksi',
            'created_by' => $admin->id,
            'school_id' => $this->schoolB->id,
        ]), $admin);

        $this->assertSame($this->bendaharaA->id, $updated->created_by);
        $this->assertSame($this->schoolA->id, $updated->school_id);
    }

    public function test_a_foreign_branch_transaction_cannot_be_edited(): void
    {
        $foreign = Transaction::factory()->create([
            'school_id' => $this->schoolB->id,
            'created_by' => User::factory()->forSchool($this->schoolB)->create()->id,
        ]);

        try {
            app(TransactionRecorder::class)
                ->update($foreign->getKey(), $this->input(), $this->bendaharaA);
            $this->fail('Transaksi cabang lain seharusnya tidak dapat diubah.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('id', $e->errors());
        }

        $this->assertSame('Dana BOS', $foreign->fresh()->category);
    }

    #[DataProvider('rolesThatMayNotRecord')]
    public function test_unauthorized_roles_cannot_edit(RoleName $role): void
    {
        $transaction = $this->record();
        $user = User::factory()->forSchool($this->schoolA)->withRole($role)->create();

        $this->expectException(AuthorizationException::class);

        app(TransactionRecorder::class)->update($transaction->getKey(), $this->input([
            'category' => 'Diubah diam-diam',
        ]), $user);
    }

    public function test_a_rejected_edit_leaves_the_record_untouched(): void
    {
        $transaction = $this->record();

        try {
            app(TransactionRecorder::class)
                ->update($transaction->getKey(), $this->input(['amount' => -1]), $this->bendaharaA);
        } catch (ValidationException) {
            // diharapkan
        }

        $this->assertSame('2500000.00', (string) $transaction->fresh()->amount);
    }

    // --------------------------------------------------------------- delete

    /**
     * Penghapusan ada sejak Batch 6.7, dan kewenangannya lebih sempit daripada
     * pencatatan: Bendahara mencatat dan mengoreksi, Admin Sekolah yang
     * menghapus (butir 129). Perilaku lengkapnya diuji di
     * DeleteTransactionTest; yang dijaga di sini adalah batas kewenangannya
     * tepat pada service yang sama dengan pencatatan.
     */
    public function test_only_administrators_may_delete_a_transaction(): void
    {
        $transaction = $this->record();

        foreach ([RoleName::Bendahara, RoleName::KepalaSekolah] as $role) {
            $user = User::factory()->forSchool($this->schoolA)->withRole($role)->create();

            $this->assertFalse($user->can('delete', $transaction));
        }

        $schoolAdmin = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::SchoolAdmin)->create();

        $this->assertTrue($schoolAdmin->can('delete', $transaction));

        app(TransactionRecorder::class)->delete($transaction->getKey(), $schoolAdmin);

        // Soft delete: barisnya tetap ada, hanya tidak lagi terbaca.
        $this->assertDatabaseCount('transactions', 1);
        $this->assertNull(Transaction::query()->find($transaction->getKey()));
    }
}
