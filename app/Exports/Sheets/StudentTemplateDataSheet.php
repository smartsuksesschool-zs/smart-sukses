<?php

namespace App\Exports\Sheets;

use App\Imports\StudentsImport;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Lembar yang diisi tata usaha: judul kolom saja.
 *
 * **Tanpa baris contoh.** Baris contoh di lembar ini akan ikut terbaca sebagai
 * data begitu berkasnya diunggah kembali, dan yang lahir adalah seorang siswa
 * bernama contoh — lengkap dengan NIS karangan yang lalu dipakai nilai dan
 * tagihan. Contohnya diletakkan di lembar "Petunjuk", tempat importer tidak
 * pernah melihatnya (butir 498, meneruskan sikap yang sama di butir 38).
 */
class StudentTemplateDataSheet implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithTitle
{
    public function title(): string
    {
        return StudentsImport::SHEET;
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return array_keys(StudentsImport::COLUMNS);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        return [];
    }

    /**
     * NIS dan NISN diformat sebagai teks.
     *
     * Inilah sebab angka nol di depan NISN hilang pada berkas sekolah: Excel
     * menyimpan kolom identitas sebagai bilangan, lalu mencetaknya tanpa nol
     * pembuka. Formatnya disetel di berkas contoh supaya kehilangan itu tidak
     * terulang pada berkas berikutnya (butir 499).
     *
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        $formats = [];

        foreach (['nis', 'nisn'] as $column) {
            $index = array_search($column, $this->headings(), true);

            if ($index !== false) {
                $formats[Coordinate::stringFromColumnIndex($index + 1)] = NumberFormat::FORMAT_TEXT;
            }
        }

        return $formats;
    }
}
