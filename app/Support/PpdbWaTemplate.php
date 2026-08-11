<?php

namespace App\Support;

use App\Models\PpdbRegistration;

/**
 * PPDB-04 poin 1 — template teks notifikasi WA per perubahan status.
 *
 * Sumber template, berurutan:
 *  1. schools.wa_template_ppdb  (ERD 2.2 — "Template teks WA untuk notifikasi PPDB",
 *     dapat diedit Admin Sekolah sesuai NOTIF-03 poin 2)
 *  2. App\Enums\PpdbStatus::waTemplate() sebagai bawaan per status
 */
class PpdbWaTemplate
{
    /**
     * Placeholder yang dikenali di dalam template.
     *
     * @return array<int, string>
     */
    public static function placeholders(): array
    {
        return ['[nama]', '[nomor_pendaftaran]', '[status]', '[sekolah]', '[ortu]', '[catatan]'];
    }

    public static function render(PpdbRegistration $registration): string
    {
        return static::fill(static::templateFor($registration), $registration);
    }

    public static function templateFor(PpdbRegistration $registration): string
    {
        $schoolTemplate = $registration->school?->wa_template_ppdb;

        return filled($schoolTemplate)
            ? $schoolTemplate
            : $registration->status->waTemplate();
    }

    public static function fill(string $template, PpdbRegistration $registration): string
    {
        return str_replace(
            static::placeholders(),
            [
                $registration->full_name,
                $registration->reg_number,
                $registration->status->label(),
                (string) $registration->school?->name,
                (string) ($registration->parent_name ?: 'Wali'),
                (string) $registration->status_notes,
            ],
            $template,
        );
    }
}
