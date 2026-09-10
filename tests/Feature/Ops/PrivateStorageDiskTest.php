<?php

namespace Tests\Feature\Ops;

use App\Jobs\GenerateReportCardPdf;
use App\Models\AcademicYear;
use App\Models\Payment;
use App\Models\ReportCard;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SiteSetting;
use App\Models\Student;
use App\Models\Transaction;
use App\Services\Finance\PaymentRecorder;
use App\Services\Finance\TransactionRecorder;
use App\Services\Grading\ReportCardPdfRenderer;
use App\Support\PpdbDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Disk penyimpanan privat yang dapat dikonfigurasi (M10A).
 *
 * Yang dijaga di sini satu hal: **jalur tulis dan jalur baca selalu menunjuk
 * disk yang sama**. Kegagalan yang paling mungkin bukan salah nama disk,
 * melainkan setengah jalur ikut pindah dan setengahnya tertinggal — worker
 * menulis PDF ke penyimpanan objek sementara web masih memeriksa berkas
 * sistemnya sendiri, lalu tombol unduhnya tidak pernah muncul dan tidak ada
 * satu pun galat yang menjelaskan mengapa (butir 585).
 *
 * Tidak ada satu pun test di sini yang membutuhkan bucket sungguhan.
 */
class PrivateStorageDiskTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------- bawaan

    public function test_tanpa_konfigurasi_baru_seluruhnya_tetap_local(): void
    {
        $this->assertSame('local', ReportCard::pdfDisk());
        $this->assertSame('local', PaymentRecorder::proofDisk());
        $this->assertSame('local', TransactionRecorder::proofDisk());
        $this->assertSame('local', PpdbDocument::disk());
    }

    public function test_konstanta_dan_bawaan_config_tidak_boleh_menyimpang(): void
    {
        /*
         * Konstantanya masih dipakai puluhan test sebagai nama disk. Selama
         * keduanya sepakat, test lama tetap menguji hal yang sama; begitu
         * menyimpang, test itu diam-diam menguji disk yang berbeda dari yang
         * dipakai aplikasi.
         */
        $this->assertSame(ReportCard::PDF_DISK, config('storage.report_card_disk'));
        $this->assertSame(PaymentRecorder::PROOF_DISK, config('storage.payment_proof_disk'));
        $this->assertSame(TransactionRecorder::PROOF_DISK, config('storage.transaction_proof_disk'));
        $this->assertSame(PpdbDocument::DISK, config('storage.ppdb_document_disk'));
    }

    public function test_disk_dapat_diarahkan_lewat_config(): void
    {
        config([
            'storage.report_card_disk' => 's3',
            'storage.payment_proof_disk' => 's3',
            'storage.transaction_proof_disk' => 's3',
            'storage.ppdb_document_disk' => 's3',
        ]);

        $this->assertSame('s3', ReportCard::pdfDisk());
        $this->assertSame('s3', PaymentRecorder::proofDisk());
        $this->assertSame('s3', TransactionRecorder::proofDisk());
        $this->assertSame('s3', PpdbDocument::disk());
    }

    // ------------------------------------------------ paritas web / worker

    protected function reportCard(): ReportCard
    {
        $school = School::factory()->create();
        $year = AcademicYear::factory()->create(['school_id' => $school->id, 'is_active' => true]);
        $class = SchoolClass::factory()->create([
            'school_id' => $school->id,
            'academic_year_id' => $year->id,
        ]);
        $student = Student::factory()->create(['school_id' => $school->id]);

        return ReportCard::factory()->create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'class_id' => $class->id,
            'academic_year_id' => $year->id,
        ]);
    }

    /** Perender palsu: yang diuji tempat berkasnya mendarat, bukan isinya. */
    protected function fakeRenderer(): ReportCardPdfRenderer
    {
        $renderer = $this->createMock(ReportCardPdfRenderer::class);
        $renderer->method('render')->willReturn('%PDF-sintetis');

        $this->app->instance(ReportCardPdfRenderer::class, $renderer);

        return $renderer;
    }

    protected function jalankanJob(ReportCard $reportCard): void
    {
        (new GenerateReportCardPdf($reportCard->id, (int) $reportCard->school_id))
            ->handle($this->fakeRenderer());
    }

    public function test_worker_menulis_pdf_ke_disk_yang_dikonfigurasi(): void
    {
        config(['storage.report_card_disk' => 'rapor-uji']);
        Storage::fake('rapor-uji');
        Storage::fake('local');

        $reportCard = $this->reportCard();
        $this->jalankanJob($reportCard);

        $path = $reportCard->fresh()->pdf_path;

        $this->assertNotNull($path);
        Storage::disk('rapor-uji')->assertExists($path);

        // Dan tidak tertinggal di disk lamanya.
        Storage::disk('local')->assertMissing($path);
    }

    public function test_web_memeriksa_pdf_pada_disk_yang_sama_dengan_worker(): void
    {
        /*
         * Inilah paritas yang sebenarnya: satu-satunya cara test ini lulus
         * adalah bila hasDownloadablePdf() membaca disk yang sama dengan yang
         * ditulis job.
         */
        config(['storage.report_card_disk' => 'rapor-uji']);
        Storage::fake('rapor-uji');
        Storage::fake('local');

        $reportCard = $this->reportCard();

        $this->assertFalse($reportCard->hasDownloadablePdf());

        $this->jalankanJob($reportCard);

        $this->assertTrue($reportCard->fresh()->hasDownloadablePdf());
    }

    public function test_pdf_di_disk_lama_tidak_dianggap_siap_unduh(): void
    {
        // Sisi sebaliknya: berkas yang tertinggal di disk lama tidak boleh
        // membuat tombol unduh muncul setelah disknya dipindah.
        Storage::fake('local');
        Storage::fake('rapor-uji');

        $reportCard = $this->reportCard();
        $this->jalankanJob($reportCard);

        $this->assertTrue($reportCard->fresh()->hasDownloadablePdf());

        config(['storage.report_card_disk' => 'rapor-uji']);

        $this->assertFalse($reportCard->fresh()->hasDownloadablePdf());
    }

    // ------------------------------------------------------ bukti keuangan

    public function test_keberadaan_bukti_pembayaran_mengikuti_disk_yang_dikonfigurasi(): void
    {
        config(['storage.payment_proof_disk' => 'bukti-uji']);
        Storage::fake('bukti-uji');
        Storage::fake('local');

        $payment = Payment::factory()->create(['proof_url' => 'payment-proofs/1/uji.pdf']);

        $this->assertFalse($payment->hasDownloadableProof());

        Storage::disk('bukti-uji')->put('payment-proofs/1/uji.pdf', 'sintetis');

        $this->assertTrue($payment->fresh()->hasDownloadableProof());

        // Salinan yang tertinggal di disk lama tidak pernah dipakai.
        Storage::disk('bukti-uji')->delete('payment-proofs/1/uji.pdf');
        Storage::disk('local')->put('payment-proofs/1/uji.pdf', 'sintetis');

        $this->assertFalse($payment->fresh()->hasDownloadableProof());
    }

    public function test_keberadaan_bukti_transaksi_mengikuti_disk_yang_dikonfigurasi(): void
    {
        config(['storage.transaction_proof_disk' => 'bukti-uji']);
        Storage::fake('bukti-uji');
        Storage::fake('local');

        $transaction = Transaction::factory()->create(['proof_url' => 'transaction-proofs/1/uji.pdf']);

        $this->assertFalse($transaction->hasDownloadableProof());

        Storage::disk('bukti-uji')->put('transaction-proofs/1/uji.pdf', 'sintetis');

        $this->assertTrue($transaction->fresh()->hasDownloadableProof());
    }

    // ------------------------------------------------- yang TIDAK ikut pindah

    public function test_impor_excel_sementara_tetap_local(): void
    {
        /*
         * Jalur impor memanggil Storage::disk('local')->path() — lintasan
         * berkas sungguhan yang tidak dimiliki objek S3. Ia sengaja tidak ikut
         * dapat dikonfigurasi, dan config penyimpanan privat tidak boleh
         * menyeretnya.
         */
        config([
            'storage.report_card_disk' => 's3',
            'storage.payment_proof_disk' => 's3',
        ]);

        $sumber = (string) file_get_contents(
            base_path('app/Filament/Resources/StudentResource/Pages/ListStudents.php'),
        );

        $this->assertStringContainsString("->disk('local')", $sumber);
        $this->assertStringNotContainsString('storage.report_card_disk', $sumber);
        $this->assertStringNotContainsString('proofDisk()', $sumber);
    }

    public function test_media_situs_publik_tetap_pada_kontraknya(): void
    {
        config([
            'storage.report_card_disk' => 's3',
            'storage.ppdb_document_disk' => 's3',
        ]);

        $this->assertSame('public', SiteSetting::MEDIA_DISK);
        $this->assertSame('public', School::LOGO_DISK);
        $this->assertSame('local', config('filesystems.default'));
    }

    public function test_berkas_privat_tidak_menjadi_dapat_dialamati_publik(): void
    {
        /*
         * Disk privat tidak boleh punya URL dasar. Begitu ia punya, satu
         * pemanggilan Storage::url() di mana pun akan menghasilkan tautan yang
         * dapat dibuka tanpa melewati policy.
         */
        foreach ([
            'storage.report_card_disk',
            'storage.payment_proof_disk',
            'storage.transaction_proof_disk',
            'storage.ppdb_document_disk',
        ] as $kunci) {
            $disk = (string) config($kunci);

            $this->assertNotSame('public', $disk, "{$kunci} tidak boleh menunjuk disk publik.");
            $this->assertNull(
                config("filesystems.disks.{$disk}.url"),
                "Disk {$disk} tidak boleh punya URL dasar.",
            );
        }

        // Dan tidak satu pun dilayani lewat symlink publik.
        $this->assertNotContains(
            storage_path('app/private'),
            array_keys(config('filesystems.links')),
        );
    }
}
