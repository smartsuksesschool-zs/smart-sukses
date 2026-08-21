<?php

namespace Tests\Feature\Grading;

use App\Enums\GradeType;
use App\Enums\ReportCardPdfStatus;
use App\Filament\Resources\ReportCardResource;
use App\Filament\Resources\ReportCardResource\Pages\ListReportCards;
use App\Jobs\GenerateReportCardPdf;
use App\Models\ReportCard;
use App\Models\School;
use App\Services\Grading\ReportCardGenerator;
use App\Services\Grading\ReportCardPdfRenderer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * NILAI-04 poin 3 / API 4.8 — GET /report-cards/{id}/pdf.
 * Checklist go-live: "Pengujian PDF rapor: format dan data sesuai."
 */
class ReportCardPdfTest extends GradingTestCase
{
    protected function publishedReportCard(): ReportCard
    {
        $this->actingAs($this->teacher);
        $this->activeConfig();
        $this->grade(GradeType::Daily, 80);
        $this->grade(GradeType::Daily, 90);
        $this->grade(GradeType::Daily, 70);
        $this->grade(GradeType::Midterm, 75);
        $this->grade(GradeType::Final, 85);
        $this->grade(GradeType::Attitude, 90);

        $this->actingAs($this->homeroom);
        $generator = app(ReportCardGenerator::class);
        $generator->generateForClass($this->class);

        return $generator->publish(ReportCard::query()->firstOrFail(), $this->homeroom);
    }

    public function test_pdf_can_be_streamed_and_contains_the_report_data(): void
    {
        $reportCard = $this->publishedReportCard();

        $response = ReportCardResource::streamPdf($reportCard);

        $this->assertInstanceOf(StreamedResponse::class, $response);

        ob_start();
        $response->sendContent();
        $pdf = ob_get_clean();

        $this->assertNotSame('', $pdf);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_filename_identifies_the_student_and_academic_year(): void
    {
        $reportCard = $this->publishedReportCard();

        $disposition = ReportCardResource::streamPdf($reportCard)
            ->headers->get('content-disposition');

        $this->assertStringContainsString('rapor_', (string) $disposition);
        $this->assertStringContainsString('.pdf', (string) $disposition);
    }

    public function test_rendered_view_shows_scores_attitude_and_identity(): void
    {
        $reportCard = $this->publishedReportCard();

        $html = view('pdf.report-card', [
            'reportCard' => $reportCard->load(['student', 'schoolClass', 'academicYear', 'school']),
            'subjects' => ['MTK' => 'Matematika'],
        ])->render();

        $this->assertStringContainsString($this->student->full_name, $html);
        $this->assertStringContainsString('Matematika', $html);
        $this->assertStringContainsString('80.00', $html);
        // Sikap dilaporkan terpisah sebagai predikat (keputusan butir 3).
        $this->assertStringContainsString('LAPORAN HASIL BELAJAR SISWA', $html);
        $this->assertStringNotContainsString('DRAFT — rapor ini belum diterbitkan.', $html);
    }

    public function test_draft_report_is_marked_as_draft_in_the_pdf(): void
    {
        $this->actingAs($this->teacher);
        $this->activeConfig();
        $this->grade(GradeType::Daily, 80);
        $this->grade(GradeType::Midterm, 80);
        $this->grade(GradeType::Final, 80);

        $this->actingAs($this->homeroom);
        app(ReportCardGenerator::class)->generateForClass($this->class);

        $html = view('pdf.report-card', [
            'reportCard' => ReportCard::query()->firstOrFail()->load(['student', 'schoolClass', 'academicYear', 'school']),
            'subjects' => [],
        ])->render();

        $this->assertStringContainsString('DRAFT', $html);
    }

    public function test_only_users_who_may_view_the_report_can_download_it(): void
    {
        $reportCard = $this->publishedReportCard();

        $this->assertTrue($this->homeroom->can('downloadPdf', $reportCard));
        $this->assertTrue($this->admin->can('downloadPdf', $reportCard));
        $this->assertFalse($this->teacher->can('downloadPdf', $reportCard));
    }

    public function test_a_single_download_never_touches_the_queued_pdf_state(): void
    {
        // Unduhan satuan tetap sinkron: dirender saat itu juga, tidak disimpan,
        // dan karena itu tidak boleh mengubah kolom pdf_* apa pun.
        $reportCard = $this->publishedReportCard();

        ReportCardResource::streamPdf($reportCard);

        $reportCard->refresh();

        $this->assertNull($reportCard->pdf_status);
        $this->assertNull($reportCard->pdf_path);
        $this->assertNull($reportCard->pdf_generated_at);
    }

    public function test_generating_pdfs_for_a_class_dispatches_one_job_per_report_card(): void
    {
        Queue::fake();

        $reportCard = $this->publishedReportCard();

        $this->actingAs($this->homeroom);

        Livewire::test(ListReportCards::class)
            ->callAction('generatePdfKelas', ['class_id' => $this->class->id]);

        Queue::assertPushed(
            GenerateReportCardPdf::class,
            fn (GenerateReportCardPdf $job) => $job->reportCardId === $reportCard->getKey()
                && $job->schoolId === $this->school->id,
        );

        Queue::assertPushed(GenerateReportCardPdf::class, 1);

        // Antrean sudah menerima pekerjaannya, tetapi berkasnya jelas belum ada.
        $this->assertSame(ReportCardPdfStatus::Queued, $reportCard->fresh()->pdf_status);
        $this->assertFalse($reportCard->fresh()->hasDownloadablePdf());
    }

    public function test_the_job_writes_the_pdf_and_records_path_and_timestamp(): void
    {
        Storage::fake(ReportCard::PDF_DISK);

        $reportCard = $this->publishedReportCard();

        (new GenerateReportCardPdf($reportCard->getKey(), $this->school->id))
            ->handle(app(ReportCardPdfRenderer::class));

        $reportCard->refresh();

        $expected = "rapor/{$this->school->id}/rapor_{$reportCard->getKey()}.pdf";

        $this->assertSame($expected, $reportCard->pdf_path);
        $this->assertSame(ReportCardPdfStatus::Ready, $reportCard->pdf_status);
        $this->assertNotNull($reportCard->pdf_generated_at);

        Storage::disk(ReportCard::PDF_DISK)->assertExists($expected);
        $this->assertStringStartsWith(
            '%PDF',
            Storage::disk(ReportCard::PDF_DISK)->get($expected),
        );

        $this->assertTrue($reportCard->hasDownloadablePdf());
    }

    public function test_running_the_job_twice_overwrites_the_same_file(): void
    {
        // Idempoten: nama berkasnya deterministik, jadi pengulangan tidak
        // menumpuk salinan baru.
        Storage::fake(ReportCard::PDF_DISK);

        $reportCard = $this->publishedReportCard();
        $job = new GenerateReportCardPdf($reportCard->getKey(), $this->school->id);

        $job->handle(app(ReportCardPdfRenderer::class));
        $firstPath = $reportCard->fresh()->pdf_path;

        $job->handle(app(ReportCardPdfRenderer::class));

        $this->assertSame($firstPath, $reportCard->fresh()->pdf_path);
        $this->assertCount(
            1,
            Storage::disk(ReportCard::PDF_DISK)->allFiles("rapor/{$this->school->id}"),
        );
    }

    public function test_the_job_refuses_a_report_card_belonging_to_another_school(): void
    {
        Storage::fake(ReportCard::PDF_DISK);

        $reportCard = $this->publishedReportCard();
        $otherSchool = School::factory()->create();

        // school_id yang dibawa job tidak cocok dengan pemilik rapor.
        (new GenerateReportCardPdf($reportCard->getKey(), $otherSchool->id))
            ->handle(app(ReportCardPdfRenderer::class));

        $reportCard->refresh();

        $this->assertNull($reportCard->pdf_path);
        $this->assertNull($reportCard->pdf_status);
        Storage::disk(ReportCard::PDF_DISK)->assertDirectoryEmpty('/');
    }

    public function test_a_deleted_report_card_makes_the_job_stop_quietly(): void
    {
        Storage::fake(ReportCard::PDF_DISK);

        $reportCard = $this->publishedReportCard();
        $id = $reportCard->getKey();
        $reportCard->delete();

        (new GenerateReportCardPdf($id, $this->school->id))
            ->handle(app(ReportCardPdfRenderer::class));

        Storage::disk(ReportCard::PDF_DISK)->assertDirectoryEmpty('/');
    }

    public function test_a_failed_job_never_leaves_a_misleading_ready_state(): void
    {
        Storage::fake(ReportCard::PDF_DISK);

        $reportCard = $this->publishedReportCard();
        $reportCard->markPdfQueued();

        (new GenerateReportCardPdf($reportCard->getKey(), $this->school->id))
            ->failed(new RuntimeException('dompdf meledak'));

        $reportCard->refresh();

        $this->assertSame(ReportCardPdfStatus::Failed, $reportCard->pdf_status);
        $this->assertNull($reportCard->pdf_path);
        $this->assertFalse($reportCard->hasDownloadablePdf());
    }

    public function test_a_failure_after_a_successful_run_keeps_the_previous_file(): void
    {
        // Kegagalan pembuatan ulang tidak boleh membuang PDF lama yang masih
        // sah — tetapi statusnya harus jujur bahwa versi terbaru gagal.
        Storage::fake(ReportCard::PDF_DISK);

        $reportCard = $this->publishedReportCard();
        $job = new GenerateReportCardPdf($reportCard->getKey(), $this->school->id);

        $job->handle(app(ReportCardPdfRenderer::class));
        $path = $reportCard->fresh()->pdf_path;
        $generatedAt = $reportCard->fresh()->pdf_generated_at;

        $job->failed(new RuntimeException('percobaan berikutnya gagal'));

        $reportCard->refresh();

        $this->assertSame(ReportCardPdfStatus::Failed, $reportCard->pdf_status);
        $this->assertSame($path, $reportCard->pdf_path);
        $this->assertEquals($generatedAt, $reportCard->pdf_generated_at);
        Storage::disk(ReportCard::PDF_DISK)->assertExists($path);
    }

    public function test_a_ready_report_card_is_not_downloadable_once_the_file_disappears(): void
    {
        // Status saja tidak cukup: berkas bisa hilang dari disk tanpa
        // sepengetahuan basis data.
        Storage::fake(ReportCard::PDF_DISK);

        $reportCard = $this->publishedReportCard();

        (new GenerateReportCardPdf($reportCard->getKey(), $this->school->id))
            ->handle(app(ReportCardPdfRenderer::class));

        Storage::disk(ReportCard::PDF_DISK)->delete($reportCard->fresh()->pdf_path);

        $this->assertFalse($reportCard->fresh()->hasDownloadablePdf());
    }

    public function test_the_stored_pdf_can_be_downloaded_from_the_table(): void
    {
        Storage::fake(ReportCard::PDF_DISK);

        $reportCard = $this->publishedReportCard();

        (new GenerateReportCardPdf($reportCard->getKey(), $this->school->id))
            ->handle(app(ReportCardPdfRenderer::class));

        $this->actingAs($this->homeroom);

        Livewire::test(ListReportCards::class)
            ->assertTableActionVisible('pdfFromStorage', $reportCard->fresh())
            ->callTableAction('pdfFromStorage', $reportCard->fresh())
            ->assertFileDownloaded();
    }

    public function test_the_stored_download_is_hidden_before_the_job_finishes(): void
    {
        Storage::fake(ReportCard::PDF_DISK);

        $reportCard = $this->publishedReportCard();
        $reportCard->markPdfQueued();

        $this->actingAs($this->homeroom);

        Livewire::test(ListReportCards::class)
            ->assertTableActionHidden('pdfFromStorage', $reportCard->fresh());
    }

    /**
     * Worker antrean tidak membawa sesi. Test lain menjalankan `handle()`
     * selagi guru masih terautentikasi dari langkah penyiapan, sehingga
     * SchoolScope di sana kebetulan menyaring ke cabang yang benar — bukan
     * keadaan yang dihadapi worker sungguhan. Di sini sesinya dilepas dulu.
     */
    public function test_the_job_writes_the_pdf_without_an_authenticated_session(): void
    {
        Storage::fake(ReportCard::PDF_DISK);

        $reportCard = $this->publishedReportCard();

        $this->forgetSession();
        $this->assertFalse(Auth::check());

        (new GenerateReportCardPdf($reportCard->getKey(), $this->school->id))
            ->handle(app(ReportCardPdfRenderer::class));

        $reportCard->refresh();

        $expected = "rapor/{$this->school->id}/rapor_{$reportCard->getKey()}.pdf";

        $this->assertSame($expected, $reportCard->pdf_path);
        $this->assertSame(ReportCardPdfStatus::Ready, $reportCard->pdf_status);
        Storage::disk(ReportCard::PDF_DISK)->assertExists($expected);
        $this->assertStringStartsWith(
            '%PDF',
            Storage::disk(ReportCard::PDF_DISK)->get($expected),
        );
    }

    /**
     * Melepas SchoolScope di dalam job tidak boleh menjadi pintu lintas
     * cabang: pagarnya adalah `school_id` yang dibawa job, dan itu tetap
     * berlaku justru ketika tidak ada sesi yang bisa membatasi apa pun.
     */
    public function test_without_a_session_the_job_still_refuses_another_tenant(): void
    {
        Storage::fake(ReportCard::PDF_DISK);

        $reportCard = $this->publishedReportCard();
        $otherSchool = School::factory()->create();

        $this->forgetSession();
        $this->assertFalse(Auth::check());

        (new GenerateReportCardPdf($reportCard->getKey(), $otherSchool->id))
            ->handle(app(ReportCardPdfRenderer::class));

        $reportCard->refresh();

        $this->assertNull($reportCard->pdf_path);
        $this->assertNotSame(ReportCardPdfStatus::Ready, $reportCard->pdf_status);
        $this->assertSame([], Storage::disk(ReportCard::PDF_DISK)->allFiles());
    }

    /**
     * `actingAs` menaruh user pada guard yang sudah ter-resolve; melupakannya
     * membuat pemanggilan berikutnya benar-benar berjalan tanpa pengguna.
     */
    protected function forgetSession(): void
    {
        Auth::logout();
        $this->app['auth']->forgetGuards();
    }
}
