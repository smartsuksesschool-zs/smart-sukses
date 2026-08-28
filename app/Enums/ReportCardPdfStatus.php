<?php

namespace App\Enums;

/**
 * Status berkas PDF rapor yang dihasilkan lewat antrean.
 *
 * NULL pada `report_cards.pdf_status` berarti PDF belum pernah diminta —
 * berbeda dari FAILED, yang berarti pernah diminta dan gagal. Unduhan satuan
 * lewat ReportCardResource::streamPdf() tidak menyentuh status ini sama sekali
 * karena berkasnya dirender langsung tanpa disimpan.
 *
 * Tech stack 3.1 menempatkan "PDF rapor" sebagai background job; yang
 * diantrekan adalah generate sekelas, bukan unduhan satu siswa.
 */
enum ReportCardPdfStatus: string
{
    case Queued = 'QUEUED';
    case Ready = 'READY';
    case Failed = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::Queued => __('Dalam antrean'),
            self::Ready => __('Siap diunduh'),
            self::Failed => __('Gagal dibuat'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued => 'warning',
            self::Ready => 'success',
            self::Failed => 'danger',
        };
    }

    /**
     * Hanya status READY yang menjamin berkasnya benar-benar ada — status itu
     * ditulis setelah berkas tersimpan, tidak pernah sebelumnya.
     */
    public function isDownloadable(): bool
    {
        return $this === self::Ready;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $case) => $carry + [$case->value => $case->label()],
            [],
        );
    }
}
