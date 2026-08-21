<?php

namespace Tests\Feature\Finance;

use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Filament\Resources\StudentFeeResource\Pages\ViewStudentFee;
use App\Filament\Resources\StudentFeeResource\RelationManagers\PaymentsRelationManager;
use App\Models\AuditLog;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use App\Services\Finance\PaymentProofAttacher;
use App\Services\Finance\PaymentRecorder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Keputusan implementasi Phase 1 yang disetujui (butir 113 nomor 9):
 * bukti pembayaran boleh dilampirkan **setelah** Payment tercatat.
 *
 * Kelonggarannya sesempit mungkin — satu kolom, satu arah, tanpa penimpaan —
 * dan seluruh sifat append-only lainnya harus tetap berlaku.
 */
class AttachPaymentProofTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected User $bendaharaA;

    protected StudentFee $feeA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        Storage::fake(PaymentRecorder::PROOF_DISK);

        $this->schoolA = School::factory()->create();
        $this->schoolB = School::factory()->create();

        $this->bendaharaA = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::Bendahara)->create();

        $this->feeA = $this->feeIn($this->schoolA);
    }

    protected function feeIn(School $school): StudentFee
    {
        return StudentFee::factory()->create([
            'school_id' => $school->id,
            'student_id' => Student::factory()->create(['school_id' => $school->id])->id,
            'fee_type_id' => FeeType::factory()->create(['school_id' => $school->id])->id,
            'amount' => '1000000',
        ]);
    }

    protected function paymentFor(StudentFee $fee, ?User $actor = null): Payment
    {
        return app(PaymentRecorder::class)->record($fee->getKey(), [
            'payment_method' => PaymentMethod::Transfer->value,
            'amount' => '400000',
            'payment_date' => '2026-08-05',
            'reference_number' => 'TRF-1',
            'notes' => 'Catatan awal',
        ], $actor ?? $this->bendaharaA);
    }

    protected function jpg(): UploadedFile
    {
        return UploadedFile::fake()->image('bukti.jpg', 100, 100);
    }

    protected function pdf(int $kilobytes = 200): UploadedFile
    {
        return UploadedFile::fake()->create('bukti.pdf', $kilobytes, 'application/pdf');
    }

    // ------------------------------------------------------------- happy path

    /**
     * @return array<string, array{string}>
     */
    public static function acceptedFormats(): array
    {
        return ['jpg' => ['jpg'], 'png' => ['png'], 'pdf' => ['pdf']];
    }

    #[DataProvider('acceptedFormats')]
    public function test_an_accepted_format_can_be_attached_afterwards(string $format): void
    {
        $payment = $this->paymentFor($this->feeA);

        $this->assertNull($payment->proof_url);

        $file = match ($format) {
            'jpg' => UploadedFile::fake()->image('bukti.jpg'),
            'png' => UploadedFile::fake()->image('bukti.png'),
            default => $this->pdf(),
        };

        $updated = app(PaymentProofAttacher::class)->attach($payment->getKey(), $file, $this->bendaharaA);

        $this->assertNotNull($updated->proof_url);
        $this->assertStringStartsWith(
            PaymentRecorder::proofDirectory((int) $this->schoolA->id).'/',
            $updated->proof_url,
        );
        Storage::disk(PaymentRecorder::PROOF_DISK)->assertExists($updated->proof_url);
        $this->assertTrue($updated->hasDownloadableProof());
    }

    public function test_the_stored_file_lives_on_the_private_disk_with_a_random_name(): void
    {
        $payment = $this->paymentFor($this->feeA);

        $updated = app(PaymentProofAttacher::class)
            ->attach($payment->getKey(), $this->pdf(), $this->bendaharaA);

        $this->assertSame('local', PaymentRecorder::PROOF_DISK);
        $this->assertNull(config('filesystems.disks.local.url'));

        // Nama unggahan asli tidak pernah dipakai membentuk jalur.
        $this->assertStringNotContainsString('bukti.pdf', $updated->proof_url);
        $this->assertMatchesRegularExpression(
            '#^payment-proofs/'.$this->schoolA->id.'/[0-9a-f\-]{36}\.pdf$#',
            $updated->proof_url,
        );
    }

    // ------------------------------------------ hanya proof_url yang berubah

    public function test_no_other_payment_field_changes(): void
    {
        $payment = $this->paymentFor($this->feeA);
        $before = $payment->only([
            'student_fee_id', 'student_id', 'school_id', 'payment_method',
            'amount_paid', 'reference_number', 'payment_date', 'received_by', 'notes',
        ]);

        $admin = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::SchoolAdmin)->create();

        app(PaymentProofAttacher::class)->attach($payment->getKey(), $this->pdf(), $admin);

        $after = $payment->fresh()->only(array_keys($before));

        $this->assertEquals($before, $after);
        // Pencatat aslinya tidak ditimpa oleh yang melampirkan bukti.
        $this->assertSame($this->bendaharaA->id, $payment->fresh()->received_by);
    }

    public function test_the_student_fee_totals_are_untouched(): void
    {
        $payment = $this->paymentFor($this->feeA);
        $before = $this->feeA->fresh()->only(['amount', 'amount_paid', 'status']);

        app(PaymentProofAttacher::class)->attach($payment->getKey(), $this->pdf(), $this->bendaharaA);

        $this->assertEquals($before, $this->feeA->fresh()->only(['amount', 'amount_paid', 'status']));
    }

    // ------------------------------------------------------------- penimpaan

    public function test_an_existing_proof_is_never_overwritten(): void
    {
        $payment = $this->paymentFor($this->feeA);

        $first = app(PaymentProofAttacher::class)
            ->attach($payment->getKey(), $this->pdf(), $this->bendaharaA);

        $originalPath = $first->proof_url;

        try {
            app(PaymentProofAttacher::class)
                ->attach($payment->getKey(), $this->jpg(), $this->bendaharaA);
            $this->fail('Bukti kedua seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('proof', $e->errors());
        }

        $this->assertSame($originalPath, $payment->fresh()->proof_url);
        Storage::disk(PaymentRecorder::PROOF_DISK)->assertExists($originalPath);
    }

    /**
     * Percobaan penimpaan yang gagal tidak boleh meninggalkan berkas yatim.
     */
    public function test_a_rejected_second_attempt_leaves_no_orphan_file(): void
    {
        $payment = $this->paymentFor($this->feeA);

        app(PaymentProofAttacher::class)->attach($payment->getKey(), $this->pdf(), $this->bendaharaA);

        $before = count(Storage::disk(PaymentRecorder::PROOF_DISK)->allFiles());

        try {
            app(PaymentProofAttacher::class)->attach($payment->getKey(), $this->pdf(), $this->bendaharaA);
        } catch (ValidationException) {
            // diharapkan
        }

        $this->assertSame($before, count(Storage::disk(PaymentRecorder::PROOF_DISK)->allFiles()));
    }

    // -------------------------------------------------------------- validasi

    public function test_a_file_above_five_megabytes_is_rejected(): void
    {
        $payment = $this->paymentFor($this->feeA);

        try {
            app(PaymentProofAttacher::class)
                ->attach($payment->getKey(), $this->pdf(6 * 1024), $this->bendaharaA);
            $this->fail('Berkas di atas 5 MB seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('proof', $e->errors());
        }

        $this->assertNull($payment->fresh()->proof_url);
        $this->assertSame([], Storage::disk(PaymentRecorder::PROOF_DISK)->allFiles());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedFormats(): array
    {
        return [
            'zip' => ['arsip.zip', 'application/zip'],
            'php' => ['skrip.php', 'application/x-httpd-php'],
            'html' => ['halaman.html', 'text/html'],
        ];
    }

    #[DataProvider('rejectedFormats')]
    public function test_a_disallowed_format_is_rejected(string $name, string $mime): void
    {
        $payment = $this->paymentFor($this->feeA);

        try {
            app(PaymentProofAttacher::class)->attach(
                $payment->getKey(),
                UploadedFile::fake()->create($name, 50, $mime),
                $this->bendaharaA,
            );
            $this->fail("Format {$mime} seharusnya ditolak.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('proof', $e->errors());
        }

        $this->assertNull($payment->fresh()->proof_url);
        $this->assertSame([], Storage::disk(PaymentRecorder::PROOF_DISK)->allFiles());
    }

    // ------------------------------------------------------------------ RBAC

    /**
     * Kewenangannya mengikuti "Catat Pembayaran": yang boleh melengkapi bukti
     * adalah yang boleh mencatat pembayarannya.
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
    public function test_the_ability_follows_the_record_payment_matrix(RoleName $role, bool $allowed): void
    {
        $payment = $this->paymentFor($this->feeA);
        $user = User::factory()->forSchool($this->schoolA)->withRole($role)->create();

        $this->assertSame($allowed, $user->can('attachProof', $payment));
    }

    public function test_an_unauthorized_role_is_refused_by_the_service(): void
    {
        $payment = $this->paymentFor($this->feeA);
        $kepala = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::KepalaSekolah)->create();

        $this->expectException(AuthorizationException::class);

        app(PaymentProofAttacher::class)->attach($payment->getKey(), $this->pdf(), $kepala);
    }

    public function test_a_payment_from_another_branch_cannot_be_touched(): void
    {
        $foreignFee = $this->feeIn($this->schoolB);
        $foreignBendahara = User::factory()->forSchool($this->schoolB)
            ->withRole(RoleName::Bendahara)->create();
        $foreignPayment = $this->paymentFor($foreignFee, $foreignBendahara);

        try {
            app(PaymentProofAttacher::class)
                ->attach($foreignPayment->getKey(), $this->pdf(), $this->bendaharaA);
            $this->fail('Pembayaran cabang lain seharusnya tidak dapat disentuh.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment', $e->errors());
        }

        $this->assertNull($foreignPayment->fresh()->proof_url);
    }

    public function test_a_school_less_account_cannot_attach_anything(): void
    {
        $payment = $this->paymentFor($this->feeA);
        $orphan = User::factory()->withRole(RoleName::Bendahara)->create(['school_id' => null]);

        $this->expectException(ValidationException::class);

        app(PaymentProofAttacher::class)->attach($payment->getKey(), $this->pdf(), $orphan);
    }

    // ----------------------------------------------------------------- audit

    public function test_a_successful_attach_writes_an_updated_audit_row(): void
    {
        $payment = $this->paymentFor($this->feeA);

        $this->actingAs($this->bendaharaA);

        app(PaymentProofAttacher::class)->attach($payment->getKey(), $this->pdf(), $this->bendaharaA);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Payment::class,
            'auditable_id' => $payment->id,
            'action' => AuditAction::Updated->value,
            'user_id' => $this->bendaharaA->id,
            'school_id' => $this->schoolA->id,
        ]);
    }

    public function test_a_failed_attach_writes_no_audit_row(): void
    {
        $payment = $this->paymentFor($this->feeA);

        $this->actingAs($this->bendaharaA);

        $before = AuditLog::query()->count();

        try {
            app(PaymentProofAttacher::class)
                ->attach($payment->getKey(), $this->pdf(6 * 1024), $this->bendaharaA);
        } catch (ValidationException) {
            // diharapkan
        }

        $this->assertSame($before, AuditLog::query()->count());
    }

    // -------------------------------------------------------------------- UI

    protected function relationManager(): Testable
    {
        return Livewire::test(PaymentsRelationManager::class, [
            'ownerRecord' => $this->feeA->fresh(),
            'pageClass' => ViewStudentFee::class,
        ]);
    }

    public function test_the_ui_action_appears_only_while_the_proof_is_missing(): void
    {
        $payment = $this->paymentFor($this->feeA);

        $this->actingAs($this->bendaharaA);

        $this->relationManager()->assertTableActionVisible('attachProof', $payment);

        app(PaymentProofAttacher::class)->attach($payment->getKey(), $this->pdf(), $this->bendaharaA);

        $this->relationManager()
            ->assertTableActionHidden('attachProof', $payment->fresh())
            // Tidak ada aksi ganti bukti; yang tersisa hanya mengunduhnya.
            ->assertTableActionVisible('downloadProof', $payment->fresh());
    }

    public function test_the_ui_action_is_hidden_from_roles_that_may_not_attach(): void
    {
        $payment = $this->paymentFor($this->feeA);

        $this->actingAs(User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::KepalaSekolah)->create());

        $this->relationManager()->assertTableActionHidden('attachProof', $payment);
    }

    public function test_there_is_no_replace_proof_action_at_all(): void
    {
        $payment = $this->paymentFor($this->feeA);

        $this->actingAs($this->bendaharaA);

        $this->relationManager()
            ->assertTableActionDoesNotExist('replaceProof')
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete');
    }

    /**
     * Melonggarkan `proof_url` tidak membuka pengubahan pembayaran secara umum.
     */
    public function test_generic_payment_update_remains_forbidden(): void
    {
        $payment = $this->paymentFor($this->feeA);

        foreach ([RoleName::Bendahara, RoleName::SchoolAdmin] as $role) {
            $user = User::factory()->forSchool($this->schoolA)->withRole($role)->create();

            $this->assertFalse($user->can('update', $payment));
            $this->assertFalse($user->can('delete', $payment));
        }
    }

    public function test_attaching_through_the_panel_uses_the_stored_path_entry_point(): void
    {
        $payment = $this->paymentFor($this->feeA);

        $path = PaymentRecorder::proofDirectory((int) $this->schoolA->id).'/'.Str::uuid().'.pdf';
        Storage::disk(PaymentRecorder::PROOF_DISK)->put($path, 'isi-bukti');

        $updated = app(PaymentProofAttacher::class)
            ->attachStoredPath($payment->getKey(), $path, $this->bendaharaA);

        $this->assertSame($path, $updated->proof_url);
    }

    /**
     * Jalur panel tetap melewati pagar jalur yang sama: berkas cabang lain atau
     * di luar direktori bukti tidak diterima.
     */
    public function test_a_crafted_path_is_rejected_on_the_panel_entry_point(): void
    {
        $payment = $this->paymentFor($this->feeA);

        foreach (['payment-proofs/999999/bukti.pdf', 'payment-proofs/../../.env', '.env'] as $crafted) {
            try {
                app(PaymentProofAttacher::class)
                    ->attachStoredPath($payment->getKey(), $crafted, $this->bendaharaA);
                $this->fail("Jalur {$crafted} seharusnya ditolak.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('proof', $e->errors());
            }
        }

        $this->assertNull($payment->fresh()->proof_url);
    }

    public function test_the_panel_entry_point_requires_a_file(): void
    {
        $payment = $this->paymentFor($this->feeA);

        try {
            app(PaymentProofAttacher::class)->attachStoredPath($payment->getKey(), null, $this->bendaharaA);
            $this->fail('Jalur kosong seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('proof', $e->errors());
        }
    }
}
