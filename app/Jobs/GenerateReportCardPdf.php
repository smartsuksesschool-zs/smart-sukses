<?php

namespace App\Jobs;

use App\Models\ReportCard;
use App\Models\Scopes\SchoolScope;
use App\Services\Grading\ReportCardPdfRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Tech stack 3.1 — "Background jobs (generate tagihan massal, PDF rapor)".
 *
 * Yang diantrekan hanya generate sekelas. Unduhan satu rapor tetap sinkron:
 * memindahkannya ke antrean berarti pengguna menekan tombol dan tidak menerima
 * apa pun, padahal rendering satu berkas berlangsung cepat.
 *
 * Job ini identifikasinya sengaja berupa ID, bukan model ter-serialize. Dua
 * alasannya: rapor yang keburu dihapus membuat job berhenti tenang alih-alih
 * melempar ModelNotFoundException berulang kali, dan `school_id` yang dibawa
 * eksplisit menjadi pagar cabang yang tidak bergantung pada SchoolScope —
 * scope itu tidak memasang batasan apa pun di dalam worker karena tidak ada
 * pengguna yang terautentikasi.
 */
class GenerateReportCardPdf implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public readonly int $reportCardId,
        public readonly int $schoolId,
    ) {}

    /**
     * Job ini idempoten: nama berkasnya deterministik
     * (ReportCard::pdfStoragePath()), sehingga menjalankannya ulang menimpa
     * berkas yang sama alih-alih menumpuk salinan, dan status akhirnya sama
     * berapa kali pun ia diulang.
     */
    public function handle(ReportCardPdfRenderer $renderer): void
    {
        $reportCard = $this->reportCard();

        // Rapor sudah dihapus, atau bukan milik cabang yang mengantrekannya.
        // Keduanya bukan kegagalan yang perlu diulang.
        if ($reportCard === null) {
            return;
        }

        $path = $reportCard->pdfStoragePath();

        // Berkas ditulis lebih dulu, status menyusul. Urutan ini yang menjaga
        // agar READY tidak pernah menunjuk berkas yang tidak ada — bila render
        // atau penyimpanan gagal, statusnya tetap QUEUED lalu menjadi FAILED.
        Storage::disk(ReportCard::PDF_DISK)->put($path, $renderer->render($reportCard));

        $reportCard->markPdfReady($path);
    }

    /**
     * Dipanggil setelah seluruh percobaan habis. `pdf_path` dan
     * `pdf_generated_at` dibiarkan apa adanya — keduanya masih menggambarkan
     * berkas terakhir yang berhasil dibuat, dan mengosongkannya akan membuang
     * PDF yang sebenarnya masih sah.
     */
    public function failed(?Throwable $exception): void
    {
        $this->reportCard()?->markPdfFailed();
    }

    /**
     * Rapor yang menjadi sasaran job, atau NULL bila sudah tidak ada maupun
     * bukan milik cabang yang mengantrekannya.
     */
    protected function reportCard(): ?ReportCard
    {
        return ReportCard::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $this->schoolId)
            ->find($this->reportCardId);
    }
}
