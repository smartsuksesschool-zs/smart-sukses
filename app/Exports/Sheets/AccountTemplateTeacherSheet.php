<?php

namespace App\Exports\Sheets;

use App\Imports\UserAccountsImport;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Lembar akun guru: judul kolom saja.
 *
 * Tanpa baris contoh — baris contoh akan ikut terbaca sebagai data ketika
 * berkasnya diunggah kembali, dan yang lahir adalah akun bernama contoh dengan
 * surel karangan yang lalu memakan satu surel sungguhan (butir 498).
 */
class AccountTemplateTeacherSheet implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    public function title(): string
    {
        return UserAccountsImport::TEACHER_SHEET;
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return array_keys(UserAccountsImport::TEACHER_COLUMNS);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        return [];
    }
}
