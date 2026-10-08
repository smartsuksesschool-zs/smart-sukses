<?php

namespace App\Exports;

use App\Exports\Sheets\AccountTemplateGuideSheet;
use App\Exports\Sheets\AccountTemplateStudentSheet;
use App\Exports\Sheets\AccountTemplateTeacherSheet;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Berkas contoh untuk import akun guru dan siswa (PORTAL-04 AC-1).
 *
 * Tiga lembar: dua yang diisi, dan satu yang menjelaskan. Judul kolomnya
 * dibangkitkan dari kontrak `UserAccountsImport`, bukan disalin ke sini — berkas
 * contoh yang disalin tangan akan menyimpang begitu importer berubah, dan berkas
 * contoh yang menyimpang lebih buruk daripada tidak ada berkas contoh sama sekali
 * (butir 497).
 */
class AccountTemplateExport implements WithMultipleSheets
{
    /**
     * @return array<int, object>
     */
    public function sheets(): array
    {
        return [
            new AccountTemplateTeacherSheet,
            new AccountTemplateStudentSheet,
            new AccountTemplateGuideSheet,
        ];
    }

    public static function filename(): string
    {
        return 'template_import_akun.xlsx';
    }
}
