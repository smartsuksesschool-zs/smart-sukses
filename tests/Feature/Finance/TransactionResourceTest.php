<?php

namespace Tests\Feature\Finance;

use App\Enums\AuditAction;
use App\Enums\RoleName;
use App\Enums\TransactionType;
use App\Filament\Resources\TransactionResource;
use App\Filament\Resources\TransactionResource\Pages\CreateTransaction;
use App\Filament\Resources\TransactionResource\Pages\EditTransaction;
use App\Filament\Resources\TransactionResource\Pages\ListTransactions;
use App\Filament\Resources\TransactionResource\Pages\ViewTransaction;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\TransactionRecorder;
use Database\Seeders\RolePermissionSeeder;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Buku Kas di panel Filament: siapa yang melihatnya, apa yang dapat diubah,
 * dan apa yang sengaja tidak tersedia.
 */
class TransactionResourceTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected User $bendaharaA;

    protected Transaction $mine;

    protected Transaction $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        Storage::fake(TransactionRecorder::PROOF_DISK);

        $this->schoolA = School::factory()->create();
        $this->schoolB = School::factory()->create();

        $this->bendaharaA = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::Bendahara)->create();

        $this->mine = Transaction::factory()->create([
            'school_id' => $this->schoolA->id,
            'created_by' => $this->bendaharaA->id,
            'category' => 'Dana BOS',
        ]);

        $this->theirs = Transaction::factory()->create([
            'school_id' => $this->schoolB->id,
            'created_by' => User::factory()->forSchool($this->schoolB)->create()->id,
            'category' => 'Sumbangan Cabang Lain',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function formInput(array $overrides = []): array
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

    // ------------------------------------------------------------- akses UI

    /**
     * @return array<string, array{RoleName}>
     */
    public static function rolesThatMaySeeTheCashBook(): array
    {
        return [
            'bendahara' => [RoleName::Bendahara],
            'admin sekolah' => [RoleName::SchoolAdmin],
            // Matriks "Akuntansi & Kas": KEPALA = ⭕.
            'kepala sekolah' => [RoleName::KepalaSekolah],
        ];
    }

    #[DataProvider('rolesThatMaySeeTheCashBook')]
    public function test_authorized_roles_open_the_cash_book(RoleName $role): void
    {
        $this->actingAs(User::factory()->forSchool($this->schoolA)->withRole($role)->create());

        $this->get(TransactionResource::getUrl('index'))->assertSuccessful();

        Livewire::test(ListTransactions::class)->assertCanSeeTableRecords([$this->mine]);
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function rolesWithoutAccess(): array
    {
        return [
            'guru' => [RoleName::Guru],
            'wali kelas' => [RoleName::WaliKelas],
        ];
    }

    #[DataProvider('rolesWithoutAccess')]
    public function test_roles_outside_the_module_are_rejected(RoleName $role): void
    {
        $this->actingAs(User::factory()->forSchool($this->schoolA)->withRole($role)->create());

        $this->assertFalse(TransactionResource::canAccess());
        $this->get(TransactionResource::getUrl('index'))->assertForbidden();
        $this->get(TransactionResource::getUrl('create'))->assertForbidden();
        $this->get(TransactionResource::getUrl('view', ['record' => $this->mine]))->assertForbidden();
    }

    public function test_students_and_parents_never_reach_the_cash_book(): void
    {
        foreach ([RoleName::Siswa, RoleName::OrangTua] as $role) {
            $this->actingAs(User::factory()->forSchool($this->schoolA)->withRole($role)->create());

            $this->get(TransactionResource::getUrl('index'))->assertForbidden();
        }
    }

    public function test_the_cash_book_is_tenant_isolated(): void
    {
        $this->actingAs($this->bendaharaA);

        Livewire::test(ListTransactions::class)
            ->assertCanSeeTableRecords([$this->mine])
            ->assertCanNotSeeTableRecords([$this->theirs]);

        $this->get(TransactionResource::getUrl('view', ['record' => $this->theirs]))->assertNotFound();
        $this->get(TransactionResource::getUrl('edit', ['record' => $this->theirs]))->assertNotFound();
    }

    public function test_super_admin_sees_every_branch(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(ListTransactions::class)
            ->assertCanSeeTableRecords([$this->mine, $this->theirs]);
    }

    public function test_kepala_sekolah_may_read_but_not_reach_the_write_pages(): void
    {
        $this->actingAs(User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::KepalaSekolah)->create());

        $this->get(TransactionResource::getUrl('view', ['record' => $this->mine]))->assertSuccessful();
        $this->get(TransactionResource::getUrl('create'))->assertForbidden();
        $this->get(TransactionResource::getUrl('edit', ['record' => $this->mine]))->assertForbidden();
    }

    // ---------------------------------------------------------- create/edit

    public function test_creating_a_transaction_through_the_form(): void
    {
        $this->actingAs($this->bendaharaA);

        Livewire::test(CreateTransaction::class)
            ->fillForm($this->formInput([
                'type' => TransactionType::Expense->value,
                'category' => 'Pembelian Alat',
                'description' => 'Proyektor ruang guru',
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('transactions', [
            'school_id' => $this->schoolA->id,
            'created_by' => $this->bendaharaA->id,
            'type' => TransactionType::Expense->value,
            'category' => 'Pembelian Alat',
            'amount' => '2500000.00',
        ]);
    }

    public function test_the_form_rejects_a_non_positive_amount(): void
    {
        $this->actingAs($this->bendaharaA);

        Livewire::test(CreateTransaction::class)
            ->fillForm($this->formInput(['amount' => 0]))
            ->call('create')
            ->assertHasFormErrors(['amount']);

        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_the_form_requires_a_category_and_a_date(): void
    {
        $this->actingAs($this->bendaharaA);

        Livewire::test(CreateTransaction::class)
            ->fillForm($this->formInput(['category' => '', 'transaction_date' => null]))
            ->call('create')
            ->assertHasFormErrors(['category', 'transaction_date']);
    }

    /**
     * Aturan validasi KAS-01 mewajibkan keterangan dan nomor referensi,
     * walaupun ERD memberi kedua kolomnya NULL (butir 81).
     */
    public function test_the_form_requires_a_description(): void
    {
        $this->actingAs($this->bendaharaA);

        Livewire::test(CreateTransaction::class)
            ->fillForm($this->formInput(['description' => '']))
            ->call('create')
            ->assertHasFormErrors(['description']);

        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_the_form_requires_a_reference_number(): void
    {
        $this->actingAs($this->bendaharaA);

        Livewire::test(CreateTransaction::class)
            ->fillForm($this->formInput(['reference_number' => '']))
            ->call('create')
            ->assertHasFormErrors(['reference_number']);

        $this->assertDatabaseCount('transactions', 2);
    }

    /**
     * Aturannya juga berlaku saat mengubah, bukan hanya saat mencatat.
     */
    public function test_the_edit_form_requires_them_too(): void
    {
        $this->actingAs($this->bendaharaA);

        Livewire::test(EditTransaction::class, ['record' => $this->mine->getKey()])
            ->fillForm(['description' => '', 'reference_number' => ''])
            ->call('save')
            ->assertHasFormErrors(['description', 'reference_number']);
    }

    /**
     * Peran School Level tidak pernah melihat field cabang; apa pun yang muncul
     * di state Livewire adalah selundupan.
     */
    public function test_a_smuggled_branch_in_the_create_form_is_ignored(): void
    {
        $this->actingAs($this->bendaharaA);

        Livewire::test(CreateTransaction::class)
            ->fillForm($this->formInput())
            ->set('data.school_id', $this->schoolB->id)
            ->set('data.created_by', User::factory()->forSchool($this->schoolB)->create()->id)
            ->call('create')
            ->assertHasNoFormErrors();

        $created = Transaction::query()->latest('id')->first();

        $this->assertSame($this->schoolA->id, $created->school_id);
        $this->assertSame($this->bendaharaA->id, $created->created_by);
    }

    public function test_editing_keeps_the_original_creator(): void
    {
        $admin = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::SchoolAdmin)->create();

        $this->actingAs($admin);

        Livewire::test(EditTransaction::class, ['record' => $this->mine->getKey()])
            ->fillForm(['category' => 'Koreksi Kategori'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $this->mine->fresh();

        $this->assertSame('Koreksi Kategori', $fresh->category);
        $this->assertSame($this->bendaharaA->id, $fresh->created_by);
        $this->assertSame($this->schoolA->id, $fresh->school_id);
    }

    // --------------------------------------------------------------- delete

    /**
     * API 4.9 menyebut DELETE /transactions/{id} sebagai "soft delete", tetapi
     * ERD tidak memuat kolomnya dan mekanismenya tidak dijelaskan di mana pun.
     * Sampai itu ada, tidak ada jalur hapus sama sekali.
     */
    public function test_the_cash_book_offers_no_delete_path(): void
    {
        $this->actingAs($this->bendaharaA);

        $this->assertSame(
            ['index', 'create', 'view', 'edit'],
            array_keys(TransactionResource::getPages()),
        );

        Livewire::test(ListTransactions::class)
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('forceDelete');

        Livewire::test(EditTransaction::class, ['record' => $this->mine->getKey()])
            ->assertActionDoesNotExist('delete');

        Livewire::test(ViewTransaction::class, ['record' => $this->mine->getKey()])
            ->assertActionDoesNotExist('delete');

        $this->assertFalse($this->bendaharaA->can('delete', $this->mine));
        $this->assertDatabaseCount('transactions', 2);
    }

    // --------------------------------------------------------------- upload

    protected function proofUpload(): FileUpload
    {
        $component = collect(TransactionResource::form(app(Form::class, [
            'livewire' => Livewire::test(CreateTransaction::class)->instance(),
        ]))->getComponents())->first(fn ($component) => $component instanceof FileUpload);

        $this->assertInstanceOf(FileUpload::class, $component);

        return $component;
    }

    public function test_proof_is_stored_on_the_private_disk(): void
    {
        $this->actingAs($this->bendaharaA);

        $component = $this->proofUpload();

        $this->assertSame('local', TransactionRecorder::PROOF_DISK);
        $this->assertSame(
            storage_path('app/private'),
            config('filesystems.disks.'.TransactionRecorder::PROOF_DISK.'.root'),
        );
        $this->assertNull(config('filesystems.disks.'.TransactionRecorder::PROOF_DISK.'.url'));
        $this->assertSame(TransactionRecorder::PROOF_DISK, $component->getDiskName());
        $this->assertSame('private', $component->getVisibility());
    }

    /**
     * Security 3.4 — "Hanya JPG/PNG/PDF diperbolehkan"; batas ukurannya
     * mengikuti pola bukti pembayaran (SPP-03).
     */
    public function test_only_jpg_png_and_pdf_are_accepted(): void
    {
        $this->actingAs($this->bendaharaA);

        $accepted = $this->proofUpload()->getAcceptedFileTypes();

        $this->assertEqualsCanonicalizing(
            ['image/jpeg', 'image/png', 'application/pdf'],
            $accepted,
        );

        foreach (['image/webp', 'image/svg+xml', 'application/x-httpd-php', 'application/zip'] as $rejected) {
            $this->assertNotContains($rejected, $accepted);
        }

        $this->assertSame(5120, $this->proofUpload()->getMaxSize());
    }

    public function test_upload_rules_reject_an_oversized_or_wrong_type_file(): void
    {
        $rules = [
            'proof' => ['file', 'max:'.TransactionRecorder::PROOF_MAX_KILOBYTES,
                'mimetypes:'.implode(',', TransactionRecorder::PROOF_MIME_TYPES)],
        ];

        $this->assertTrue(
            validator(['proof' => UploadedFile::fake()->create('nota.pdf', 6 * 1024, 'application/pdf')], $rules)->fails(),
        );
        $this->assertTrue(
            validator(['proof' => UploadedFile::fake()->create('nota.zip', 100, 'application/zip')], $rules)->fails(),
        );
        $this->assertFalse(
            validator(['proof' => UploadedFile::fake()->create('nota.pdf', 512, 'application/pdf')], $rules)->fails(),
        );
    }

    public function test_a_stored_proof_lives_in_its_own_branch_directory(): void
    {
        $path = TransactionRecorder::proofDirectory((int) $this->schoolA->id).'/'.Str::uuid().'.pdf';
        Storage::disk(TransactionRecorder::PROOF_DISK)->put($path, 'isi-nota');

        $transaction = app(TransactionRecorder::class)->record([
            'type' => TransactionType::Expense->value,
            'category' => 'Pembelian Alat',
            'amount' => 500_000,
            'transaction_date' => '2026-08-05',
            'description' => 'Proyektor ruang guru',
            'reference_number' => 'NOTA-11',
            'proof_url' => $path,
        ], $this->bendaharaA);

        $this->assertSame($path, $transaction->proof_url);
        $this->assertStringStartsWith(
            TransactionRecorder::PROOF_DIRECTORY.'/'.$this->schoolA->id.'/',
            $transaction->proof_url,
        );
        $this->assertTrue($transaction->hasDownloadableProof());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function craftedProofPaths(): array
    {
        return [
            'cabang lain' => ['transaction-proofs/999999/nota.pdf'],
            'keluar direktori' => ['transaction-proofs/../../.env'],
            'akar penyimpanan' => ['.env'],
            'bukti pembayaran' => ['payment-proofs/1/bukti.pdf'],
        ];
    }

    #[DataProvider('craftedProofPaths')]
    public function test_crafted_proof_paths_are_rejected(string $path): void
    {
        try {
            app(TransactionRecorder::class)->record([
                'type' => TransactionType::Expense->value,
                'category' => 'Pembelian Alat',
                'amount' => 500_000,
                'transaction_date' => '2026-08-05',
                'description' => 'Proyektor ruang guru',
                'reference_number' => 'NOTA-11',
                'proof_url' => $path,
            ], $this->bendaharaA);
            $this->fail("Jalur bukti {$path} seharusnya ditolak.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('proof_url', $e->errors());
        }

        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_proof_download_is_authorized_and_tenant_safe(): void
    {
        $withProof = Transaction::factory()->create([
            'school_id' => $this->schoolA->id,
            'created_by' => $this->bendaharaA->id,
            'proof_url' => TransactionRecorder::proofDirectory((int) $this->schoolA->id).'/abc.pdf',
        ]);

        $foreignBendahara = User::factory()->forSchool($this->schoolB)
            ->withRole(RoleName::Bendahara)->create();
        $guru = User::factory()->forSchool($this->schoolA)->withRole(RoleName::Guru)->create();
        $kepala = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::KepalaSekolah)->create();

        $this->assertTrue($this->bendaharaA->can('downloadProof', $withProof));
        $this->assertTrue($kepala->can('downloadProof', $withProof));
        $this->assertFalse($foreignBendahara->can('downloadProof', $withProof));
        $this->assertFalse($guru->can('downloadProof', $withProof));
    }

    public function test_the_download_filename_is_derived_not_taken_from_the_upload(): void
    {
        $withProof = Transaction::factory()->create([
            'school_id' => $this->schoolA->id,
            'created_by' => $this->bendaharaA->id,
            'proof_url' => TransactionRecorder::proofDirectory((int) $this->schoolA->id).'/abc.pdf',
        ]);

        $this->assertSame(
            "bukti-transaksi-{$withProof->id}.pdf",
            TransactionResource::proofFilenameFor($withProof),
        );
    }

    public function test_a_transaction_without_proof_is_valid(): void
    {
        $transaction = app(TransactionRecorder::class)->record([
            'type' => TransactionType::Income->value,
            'category' => 'Sumbangan',
            'amount' => 100_000,
            'transaction_date' => '2026-08-05',
            'description' => 'Sumbangan wali murid kelas 9A',
            'reference_number' => 'KWT-2026-04',
        ], $this->bendaharaA);

        $this->assertNull($transaction->proof_url);
        $this->assertFalse($transaction->hasDownloadableProof());
    }

    // ---------------------------------------------------------------- audit

    public function test_creating_writes_a_created_audit_row(): void
    {
        $this->actingAs($this->bendaharaA);

        $transaction = app(TransactionRecorder::class)->record($this->formInput(), $this->bendaharaA);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Transaction::class,
            'auditable_id' => $transaction->id,
            'action' => AuditAction::Created->value,
            'user_id' => $this->bendaharaA->id,
            'school_id' => $this->schoolA->id,
        ]);
    }

    public function test_editing_writes_an_updated_audit_row(): void
    {
        $this->actingAs($this->bendaharaA);

        app(TransactionRecorder::class)->update(
            $this->mine->getKey(),
            $this->formInput(['category' => 'Koreksi']),
            $this->bendaharaA,
        );

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Transaction::class,
            'auditable_id' => $this->mine->id,
            'action' => AuditAction::Updated->value,
            'user_id' => $this->bendaharaA->id,
            'school_id' => $this->schoolA->id,
        ]);
    }

    public function test_a_rejected_operation_writes_no_audit_row(): void
    {
        $this->actingAs($this->bendaharaA);

        $before = AuditLog::query()->count();

        try {
            app(TransactionRecorder::class)->record($this->formInput(['amount' => -1]), $this->bendaharaA);
        } catch (ValidationException) {
            // diharapkan
        }

        try {
            app(TransactionRecorder::class)->update(
                $this->theirs->getKey(),
                $this->formInput(),
                $this->bendaharaA,
            );
        } catch (ValidationException) {
            // diharapkan
        }

        $this->assertSame($before, AuditLog::query()->count());
    }

    // ---------------------------------------------------------- performance

    public function test_the_list_does_not_query_per_row(): void
    {
        $this->actingAs($this->bendaharaA);

        // Render pemanasan: pemuatan izin Spatie dan sesi hanya terjadi sekali.
        Livewire::test(ListTransactions::class)->assertSuccessful();

        DB::enableQueryLog();
        Livewire::test(ListTransactions::class)->assertSuccessful();
        $withOne = count(DB::getQueryLog());

        for ($i = 0; $i < 9; $i++) {
            Transaction::factory()->create([
                'school_id' => $this->schoolA->id,
                'created_by' => User::factory()->forSchool($this->schoolA)->create()->id,
            ]);
        }

        DB::flushQueryLog();
        Livewire::test(ListTransactions::class)->assertSuccessful();
        $withTen = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $withOne,
            $withTen,
            'Jumlah query buku kas harus konstan terhadap jumlah baris.',
        );
    }

    public function test_the_list_eager_loads_only_what_it_renders(): void
    {
        $this->assertEqualsCanonicalizing(
            ['createdBy', 'school'],
            array_keys(TransactionResource::getEloquentQuery()->getEagerLoads()),
        );
    }
}
