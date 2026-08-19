<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Berkas contoh untuk import nilai — API 4.8 `POST /grades/import` menyebutnya
 * "Import nilai dari Excel template", tetapi tidak menentukan isinya. Yang
 * dipakai di sini karena itu bukan format baru: persis kolom yang dibaca
 * App\Imports\GradesImport, tidak lebih (butir 38).
 *
 * Sengaja tanpa baris contoh dan tanpa daftar siswa. Komponen, jenis penilaian,
 * dan kelas-mapel adalah konteks yang dipilih di form import, bukan isi berkas,
 * sehingga tidak ada kolom lain yang perlu dibawa. Baris contoh pun akan ikut
 * terbaca sebagai data begitu berkasnya diunggah kembali.
 */
class GradeTemplateExport implements FromArray, ShouldAutoSize, WithHeadings
{
    /**
     * Huruf kecil, sama seperti yang dibaca importer dan yang tertulis di
     * modal import.
     *
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['nis', 'nilai'];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        return [];
    }
}
