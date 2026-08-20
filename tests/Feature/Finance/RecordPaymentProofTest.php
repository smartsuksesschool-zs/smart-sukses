<?php

namespace Tests\Feature\Finance;

use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Filament\Resources\StudentFeeResource;
use App\Filament\Resources\StudentFeeResource\RelationManagers\PaymentsRelationManager;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use App\Services\Finance\PaymentRecorder;
use Database\Seeders\RolePermissionSeeder;
use Filament\Forms\Components\FileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SPP-03 poin 2 — "bukti transfer dapat diunggah (JPG/PNG/PDF, maks 5 MB)" —
 * dan Security 3.4: "hanya JPG/PNG/PDF diperbolehkan; disimpan di storage/ (di
 * luar web root)".
 */
class RecordPaymentProofTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected User $bendahara;

    protected StudentFee $fee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        Storage::fake(PaymentRecorder::PROOF_DISK);

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

    protected function proofUpload(): FileUpload
    {
        $component = collect(StudentFeeResource::paymentFormSchema($this->fee))
            ->first(fn ($component) => $component instanceof FileUpload);

        $this->assertInstanceOf(FileUpload::class, $component);

        return $component;
    }

    /**
     * Disknya privat (`storage/app/private`), bukan `public`: berkas ini tidak
     * boleh punya URL statis.
     */
    public function test_proof_is_stored_outside_the_public_web_root(): void
    {
        $this->assertSame('local', PaymentRecorder::PROOF_DISK);
        $this->assertSame(
            storage_path('app/private'),
            config('filesystems.disks.'.PaymentRecorder::PROOF_DISK.'.root'),
        );
        $this->assertNull(config('filesystems.disks.'.PaymentRecorder::PROOF_DISK.'.url'));

        $component = $this->proofUpload();

        $this->assertSame(PaymentRecorder::PROOF_DISK, $component->getDiskName());
        $this->assertSame('private', $component->getVisibility());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function acceptedMimeTypes(): array
    {
        return [
            'jpg' => ['image/jpeg'],
            'png' => ['image/png'],
            'pdf' => ['application/pdf'],
        ];
    }

    #[DataProvider('acceptedMimeTypes')]
    public function test_accepted_proof_types(string $mimeType): void
    {
        $this->assertContains($mimeType, $this->proofUpload()->getAcceptedFileTypes());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedMimeTypes(): array
    {
        return [
            'webp' => ['image/webp'],
            'svg' => ['image/svg+xml'],
            'php' => ['application/x-httpd-php'],
            'zip' => ['application/zip'],
            'html' => ['text/html'],
        ];
    }

    #[DataProvider('rejectedMimeTypes')]
    public function test_rejected_proof_types(string $mimeType): void
    {
        $this->assertNotContains($mimeType, $this->proofUpload()->getAcceptedFileTypes());
    }

    public function test_proof_is_capped_at_five_megabytes(): void
    {
        $this->assertSame(5120, PaymentRecorder::PROOF_MAX_KILOBYTES);
        $this->assertSame(5120, $this->proofUpload()->getMaxSize());
    }

    /**
     * Berkas 6 MB harus gagal validasi Laravel yang sama dengan yang dipasang
     * FileUpload — bukan sekadar tertolak di sisi klien.
     */
    public function test_a_file_above_the_limit_fails_the_upload_rules(): void
    {
        $oversized = UploadedFile::fake()->create('bukti.pdf', 6 * 1024, 'application/pdf');

        $validator = validator(
            ['proof' => $oversized],
            ['proof' => ['file', 'max:'.PaymentRecorder::PROOF_MAX_KILOBYTES, 'mimetypes:'.implode(',', PaymentRecorder::PROOF_MIME_TYPES)]],
        );

        $this->assertTrue($validator->fails());
    }

    public function test_an_allowed_file_within_the_limit_passes_the_upload_rules(): void
    {
        $accepted = UploadedFile::fake()->create('bukti.pdf', 512, 'application/pdf');

        $validator = validator(
            ['proof' => $accepted],
            ['proof' => ['file', 'max:'.PaymentRecorder::PROOF_MAX_KILOBYTES, 'mimetypes:'.implode(',', PaymentRecorder::PROOF_MIME_TYPES)]],
        );

        $this->assertFalse($validator->fails());
    }

    public function test_proof_path_is_stored_per_branch(): void
    {
        $path = PaymentRecorder::proofDirectory((int) $this->school->id).'/'.Str::uuid().'.pdf';
        Storage::disk(PaymentRecorder::PROOF_DISK)->put($path, 'isi-bukti');

        $payment = app(PaymentRecorder::class)->record($this->fee->getKey(), [
            'payment_method' => PaymentMethod::Transfer->value,
            'amount' => 100_000,
            'payment_date' => '2026-08-05',
            'proof_url' => $path,
        ], $this->bendahara);

        $this->assertSame($path, $payment->proof_url);
        $this->assertStringStartsWith(
            PaymentRecorder::PROOF_DIRECTORY.'/'.$this->school->id.'/',
            $payment->proof_url,
        );
        $this->assertTrue($payment->hasDownloadableProof());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function craftedProofPaths(): array
    {
        return [
            'cabang lain' => ['payment-proofs/999999/bukti.pdf'],
            'keluar direktori' => ['payment-proofs/../../.env'],
            'akar penyimpanan' => ['.env'],
            'berkas rapor' => ['report-cards/rapor.pdf'],
        ];
    }

    /**
     * Jalur bukti tidak pernah dipercaya apa adanya: hanya berkas di dalam
     * direktori milik cabang tagihannya yang diterima.
     */
    #[DataProvider('craftedProofPaths')]
    public function test_crafted_proof_paths_are_rejected(string $path): void
    {
        try {
            app(PaymentRecorder::class)->record($this->fee->getKey(), [
                'payment_method' => PaymentMethod::Transfer->value,
                'amount' => 100_000,
                'payment_date' => '2026-08-05',
                'proof_url' => $path,
            ], $this->bendahara);
            $this->fail("Jalur bukti {$path} seharusnya ditolak.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('proof_url', $e->errors());
        }

        $this->assertDatabaseCount('payments', 0);
    }

    /**
     * SPP-03 mewajibkan bukti "dapat diunggah", bukan wajib diunggah — dan
     * pembayaran tunai memang tidak punya bukti transfer.
     */
    public function test_a_payment_without_proof_is_still_valid(): void
    {
        $payment = app(PaymentRecorder::class)->record($this->fee->getKey(), [
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => 100_000,
            'payment_date' => '2026-08-05',
        ], $this->bendahara);

        $this->assertNull($payment->proof_url);
        $this->assertFalse($payment->hasDownloadableProof());
    }

    /**
     * Nama unduhan dibentuk dari data pembayaran, bukan dari nama unggahan asli.
     */
    public function test_download_filename_is_derived_not_taken_from_the_upload(): void
    {
        $payment = Payment::factory()->create([
            'school_id' => $this->school->id,
            'student_fee_id' => $this->fee->id,
            'student_id' => $this->fee->student_id,
            'received_by' => $this->bendahara->id,
            'proof_url' => PaymentRecorder::proofDirectory((int) $this->school->id).'/abc123.pdf',
        ]);

        $this->assertSame(
            "bukti-pembayaran-{$payment->id}.pdf",
            PaymentsRelationManager::proofFilenameFor($payment),
        );
    }

    /**
     * Bukti hanya dapat diunduh oleh yang boleh melihat pembayarannya, di
     * cabangnya sendiri.
     */
    public function test_proof_download_is_authorized_and_tenant_safe(): void
    {
        $payment = Payment::factory()->create([
            'school_id' => $this->school->id,
            'student_fee_id' => $this->fee->id,
            'student_id' => $this->fee->student_id,
            'received_by' => $this->bendahara->id,
            'proof_url' => PaymentRecorder::proofDirectory((int) $this->school->id).'/abc123.pdf',
        ]);

        $foreignBendahara = User::factory()->forSchool(School::factory()->create())
            ->withRole(RoleName::Bendahara)->create();
        $guru = User::factory()->forSchool($this->school)->withRole(RoleName::Guru)->create();

        $this->assertTrue($this->bendahara->can('downloadProof', $payment));
        $this->assertFalse($foreignBendahara->can('downloadProof', $payment));
        $this->assertFalse($guru->can('downloadProof', $payment));
    }
}
