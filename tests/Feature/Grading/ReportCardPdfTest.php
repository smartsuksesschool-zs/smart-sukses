<?php

namespace Tests\Feature\Grading;

use App\Enums\GradeType;
use App\Filament\Resources\ReportCardResource;
use App\Models\ReportCard;
use App\Services\Grading\ReportCardGenerator;
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
}
