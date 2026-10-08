<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

/**
 * Sandi sementara hasil impor massal — sekali unduh, tidak pernah disimpan.
 *
 * Berkas ini **di-stream** ke peramban admin yang baru menjalankan impornya dan
 * tidak pernah ditulis ke disk oleh aplikasi. Tanpa berkas ini impor massal tidak
 * ada gunanya: dua ratus sandi tidak dapat dibaca dari satu notifikasi, dan
 * `MAIL_MAILER=log` berarti tidak ada satu pun yang terkirim otomatis.
 *
 * Yang menjaga agar sandi di dalamnya tidak berumur panjang bukan berkasnya,
 * melainkan `must_change_password` pada akun stafnya dan prosedur penyerahan di
 * luar sistem (butir 594).
 *
 * **Setiap sel ditulis sebagai teks**, lewat `StringValueBinder`. Itu yang
 * mencegah formula injection: `Str::password()` dapat menghasilkan sandi yang
 * diawali `-`, `+`, `=`, atau `@`, dan sel semacam itu akan dievaluasi Excel
 * sebagai rumus — sandinya berubah menjadi `#NAME?` di hadapan admin, atau, pada
 * berkas yang berpindah tangan, menjadi jalan menjalankan sesuatu.
 *
 * Mula-mula ini dikerjakan dengan menyisipkan kutip tunggal di depan nilainya.
 * Itu **salah**, dan regression test inilah yang membuktikannya: PhpSpreadsheet
 * menyimpan kutipnya sebagai karakter biasa tanpa menyetel `quotePrefix`,
 * sehingga apostrof itu menjadi **bagian dari sandi**. Sandi `-ketiga12` tiba di
 * tangan admin sebagai `'-ketiga12` dan gagal dipakai masuk, sebab yang ter-hash
 * di basis data tidak memuat apostrof.
 *
 * `StringValueBinder` menyelesaikan keduanya sekaligus: nilainya tersimpan bersih
 * apa adanya, tipenya selalu string sehingga tidak pernah dievaluasi, dan untuk
 * nilai yang diawali `=` PhpSpreadsheet menyetel sendiri atribut `quotePrefix` —
 * mekanisme Excel yang memang dimaksudkan untuk itu (butir 595).
 */
class AccountCredentialsExport extends StringValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithHeadings, WithStrictNullComparison, WithTitle
{
    /**
     * @param  array<int, array{name: string, email: string, role: string, password: string}>  $credentials
     */
    public function __construct(protected array $credentials) {}

    public function title(): string
    {
        return 'Kredensial';
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [__('Nama'), __('Surel'), __('Peran'), __('Kata Sandi Sementara')];
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function array(): array
    {
        return array_map(fn (array $row): array => [
            $row['name'],
            $row['email'],
            $row['role'],
            $row['password'],
        ], $this->credentials);
    }

    public static function filename(): string
    {
        return 'kredensial_akun_'.now()->format('Y-m-d_His').'.xlsx';
    }
}
