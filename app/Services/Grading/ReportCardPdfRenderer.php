<?php

namespace App\Services\Grading;

use App\Models\ReportCard;
use App\Models\Scopes\SchoolScope;
use App\Models\Subject;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Satu-satunya tempat rapor dirender menjadi PDF.
 *
 * Dipisahkan dari ReportCardResource karena kini ada dua pemakai dengan
 * kebutuhan berbeda — unduhan satuan yang sinkron dan job antrean untuk
 * generate sekelas — dan keduanya wajib menghasilkan berkas yang identik.
 * Menyalin logika render ke job akan membuat kedua jalur perlahan berbeda.
 */
class ReportCardPdfRenderer
{
    /**
     * Isi berkas PDF sebagai string biner.
     */
    public function render(ReportCard $reportCard): string
    {
        $reportCard->loadMissing(['student', 'schoolClass', 'academicYear', 'school']);

        return Pdf::loadView('pdf.report-card', [
            'reportCard' => $reportCard,
            'subjects' => $this->subjectNames($reportCard),
        ])->setPaper('a4')->output();
    }

    /**
     * Nama berkas untuk unduhan — mengidentifikasi siswa dan tahun ajaran.
     */
    public function filenameFor(ReportCard $reportCard): string
    {
        return sprintf(
            'rapor_%s_%s.pdf',
            str($reportCard->student?->nis ?? $reportCard->getKey())->slug('_'),
            str($reportCard->academicYear?->name ?? '')->slug('_'),
        );
    }

    /**
     * Peta kode mapel → nama, agar PDF menampilkan nama lengkap.
     *
     * Cabangnya disaring eksplisit, tidak mengandalkan SchoolScope: di dalam
     * worker antrean tidak ada pengguna terautentikasi, sehingga scope itu
     * tidak memasang batasan apa pun dan kode mapel yang kebetulan sama bisa
     * terambil dari cabang lain.
     *
     * @return array<string, string>
     */
    protected function subjectNames(ReportCard $reportCard): array
    {
        return Subject::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $reportCard->school_id)
            ->whereIn('code', array_keys($reportCard->final_scores ?? []))
            ->pluck('name', 'code')
            ->all();
    }
}
