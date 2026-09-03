<?php

namespace App\Exports;

use App\Exports\Sheets\StudentTemplateDataSheet;
use App\Exports\Sheets\StudentTemplateGuideSheet;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Berkas contoh untuk import siswa.
 *
 * Umpan balik pemilik: modal import menuntut admin menebak format berkasnya.
 * Menebak format adalah cara paling mahal untuk mengetahui bahwa sebuah kolom
 * salah nama — kesalahannya baru terlihat setelah unggahan menghasilkan
 * "0 siswa diimport" tanpa keterangan. Berkas ini menghapus tebakan itu
 * (butir 496).
 *
 * Judul kolomnya **dibangkitkan dari kontrak importer**, bukan disalin ke sini.
 * Berkas contoh yang disalin tangan akan menyimpang begitu importer berubah,
 * dan berkas contoh yang menyimpang lebih buruk daripada tidak ada berkas
 * contoh sama sekali (butir 497).
 *
 * Dua lembar: "Data Siswa" yang diisi, dan "Petunjuk" yang menjelaskan.
 */
class StudentTemplateExport implements WithMultipleSheets
{
    /**
     * @return array<int, object>
     */
    public function sheets(): array
    {
        return [
            new StudentTemplateDataSheet,
            new StudentTemplateGuideSheet,
        ];
    }

    public static function filename(): string
    {
        return 'template_import_siswa.xlsx';
    }
}
