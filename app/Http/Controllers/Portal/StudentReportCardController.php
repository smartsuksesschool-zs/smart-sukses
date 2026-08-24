<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Grading\ReportCardPdfRenderer;
use App\Services\Portal\StudentPortalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * NILAI-04 poin 3 — "rapor dapat dicetak dalam format PDF", dari sisi siswa.
 *
 * Kepemilikannya diputuskan StudentPortalService: identitas siswanya
 * diresolusi dari akun yang login, lalu rapornya wajib milik siswa itu dan
 * sudah diterbitkan. Rapor draf dan rapor siswa lain sama-sama 404, tanpa
 * membocorkan keberadaannya (butir 185).
 *
 * Berkasnya dirender ulang lewat renderer yang sama dengan panel dan portal
 * orang tua — tidak ada pembuatan PDF ketiga, dan kolom `pdf_*` tidak
 * tersentuh.
 */
class StudentReportCardController extends Controller
{
    /**
     * @throws AuthorizationException|ModelNotFoundException
     */
    public function __invoke(Request $request, int $reportCardId): StreamedResponse
    {
        $reportCard = app(StudentPortalService::class)
            ->publishedReportCardFor($request->user(), $reportCardId);

        $renderer = app(ReportCardPdfRenderer::class);

        return response()->streamDownload(
            function () use ($reportCard, $renderer): void {
                echo $renderer->render($reportCard);
            },
            $renderer->filenameFor($reportCard),
        );
    }
}
