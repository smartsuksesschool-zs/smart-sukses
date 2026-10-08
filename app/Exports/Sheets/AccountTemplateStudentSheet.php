<?php

namespace App\Exports\Sheets;

use App\Imports\UserAccountsImport;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Lembar akun siswa: judul kolom saja.
 *
 * NIS diformat sebagai teks. Tanpa itu Excel menyimpannya sebagai bilangan dan
 * mencetaknya tanpa nol pembuka — kehilangan yang sudah pernah terjadi pada
 * berkas sekolah (butir 499).
 */
class AccountTemplateStudentSheet implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithTitle
{
    public function title(): string
    {
        return UserAccountsImport::STUDENT_SHEET;
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return array_keys(UserAccountsImport::STUDENT_COLUMNS);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        $index = array_search('nis', $this->headings(), true);

        if ($index === false) {
            return [];
        }

        return [Coordinate::stringFromColumnIndex($index + 1) => NumberFormat::FORMAT_TEXT];
    }
}
