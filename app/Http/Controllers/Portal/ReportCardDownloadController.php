<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Grading\ReportCardPdfRenderer;
use App\Services\Portal\ParentPortalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * NILAI-04 poin 3 — "Rapor dapat dicetak dalam format PDF", dari sisi orang
 * tua.
 *
 * Kewenangannya **tidak** digantung pada ReportCardPolicy. Policy itu memberi
 * ORANG_TUA `report_card.view` untuk seluruh cabang — memadai bagi peran panel
 * yang memang melihat semua siswa, tetapi jauh terlalu longgar di sini: orang
 * tua hanya boleh mengunduh rapor anaknya sendiri. Yang dipakai karena itu
 * pagar kepemilikan Batch 7.1, dan rapornya wajib milik anak itu serta sudah
 * diterbitkan (butir 162).
 *
 * Berkasnya dirender ulang lewat renderer yang sama dengan panel — tidak ada
 * pembuatan PDF kedua, dan kolom `pdf_*` tidak tersentuh sama sekali.
 */
class ReportCardDownloadController extends Controller
{
    /**
     * @throws AuthorizationException|ModelNotFoundException
     */
    public function __invoke(Request $request, int $studentId, int $reportCardId): StreamedResponse
    {
        $reportCard = app(ParentPortalService::class)
            ->publishedReportCardFor($request->user(), $studentId, $reportCardId);

        $renderer = app(ReportCardPdfRenderer::class);

        return response()->streamDownload(
            function () use ($reportCard, $renderer): void {
                echo $renderer->render($reportCard);
            },
            $renderer->filenameFor($reportCard),
        );
    }
}
