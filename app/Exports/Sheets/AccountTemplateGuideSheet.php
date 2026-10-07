<?php

namespace App\Exports\Sheets;

use App\Imports\UserAccountsImport;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Lembar penjelasan untuk kedua lembar akun.
 *
 * Daftar kolomnya dibangkitkan dari kontrak importer, jadi kolom baru tidak dapat
 * masuk ke importer tanpa muncul di sini (butir 497). Contoh pengisian diletakkan
 * di lembar ini — importer tidak pernah membacanya, sehingga contohnya tidak
 * mungkin ikut terimpor sebagai akun (butir 498).
 *
 * Tidak ada kolom kata sandi, dan itu bukan kelalaian: sandi sementara dibuat
 * sistem, bukan diketik tata usaha ke dalam berkas yang lalu beredar lewat surel
 * dan grup percakapan.
 */
class AccountTemplateGuideSheet implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * Contoh karangan. Surelnya memakai domain `.test` yang dicadangkan untuk
     * contoh (RFC 2606), dan NIS-nya berpola nol.
     *
     * @var array<string, string>
     */
    protected const TEACHER_EXAMPLE = [
        'nama' => 'Guru Contoh Pertama',
        'email' => 'guru.contoh@example.test',
        'peran' => 'GURU',
        'hp' => '081200000000',
        'bahasa' => 'id',
    ];

    /**
     * @var array<string, string>
     */
    protected const STUDENT_EXAMPLE = [
        'nis' => 'T000000001',
        'email' => 'siswa.contoh@example.test',
        'hp' => '081200000001',
        'bahasa' => 'id',
    ];

    public function title(): string
    {
        return 'Petunjuk';
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [__('Kolom'), __('Wajib'), __('Keterangan')];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        $rows = [];

        $rows[] = [__('Lembar ":sheet"', ['sheet' => UserAccountsImport::TEACHER_SHEET])];

        foreach (array_keys(UserAccountsImport::TEACHER_COLUMNS) as $column) {
            $rows[] = [
                $column,
                in_array($column, UserAccountsImport::TEACHER_REQUIRED, true) ? __('Wajib') : __('Opsional'),
                $this->guidance($column),
            ];
        }

        $rows[] = [];
        $rows[] = [__('Lembar ":sheet"', ['sheet' => UserAccountsImport::STUDENT_SHEET])];

        foreach (array_keys(UserAccountsImport::STUDENT_COLUMNS) as $column) {
            $rows[] = [
                $column,
                in_array($column, UserAccountsImport::STUDENT_REQUIRED, true) ? __('Wajib') : __('Opsional'),
                $this->guidance($column),
            ];
        }

        $rows[] = [];
        $rows[] = [__('Aturan umum')];

        foreach ($this->rules() as $rule) {
            $rows[] = ['', '', $rule];
        }

        $rows[] = [];
        $rows[] = [__('Contoh pengisian — data karangan, jangan diunggah apa adanya')];
        $rows[] = [UserAccountsImport::TEACHER_SHEET];
        $rows[] = array_keys(UserAccountsImport::TEACHER_COLUMNS);
        $rows[] = array_values(self::TEACHER_EXAMPLE);
        $rows[] = [];
        $rows[] = [UserAccountsImport::STUDENT_SHEET];
        $rows[] = array_keys(UserAccountsImport::STUDENT_COLUMNS);
        $rows[] = array_values(self::STUDENT_EXAMPLE);

        return $rows;
    }

    protected function guidance(string $column): string
    {
        return match ($column) {
            'nama' => __('Nama lengkap sesuai dokumen resmi. Hanya pada lembar guru — nama akun siswa diambil dari data induknya.'),
            'email' => __('Alamat surel yang sah dan dapat dibaca pemiliknya. Dipakai sebagai nama pengguna, dan harus unik di seluruh sistem.'),
            'peran' => __('Diisi :values.', ['values' => implode(' / ', UserAccountsImport::TEACHER_ROLES)]),
            'nis' => __('NIS siswa yang **sudah ada** di cabang ini. Setel selnya sebagai teks agar angka nol di depan tidak hilang.'),
            'hp' => __('Nomor HP, boleh dikosongkan. Dipakai untuk tautan WhatsApp.'),
            'bahasa' => __('Diisi :values. Kosongkan untuk :default.', [
                'values' => implode(' / ', UserAccountsImport::LOCALES),
                'default' => 'id',
            ]),
            default => __('Boleh dikosongkan.'),
        };
    }

    /**
     * @return array<int, string>
     */
    protected function rules(): array
    {
        return [
            __('Jangan mengubah nama lembar dan nama kolom di baris pertama.'),
            __('Jangan menggabungkan sel (merged cells).'),
            __('Satu baris untuk satu akun; jangan menyisipkan baris judul atau baris jumlah.'),
            __('Tidak ada kolom kata sandi. Sandi sementara dibuat sistem dan diunduh sekali setelah impor berhasil.'),
            __('Tidak ada kolom cabang. Cabang ditentukan dari akun Anda, atau dipilih Super Admin sebelum mengunggah.'),
            __('Surel yang sudah dipakai akun lain menolak barisnya — tidak ditimpa dan tidak dipakai ulang.'),
            __('Lembar siswa hanya menautkan akun ke siswa yang sudah ada. Data siswa baru dibuat lewat import siswa, bukan berkas ini.'),
            __('Siswa yang sudah memiliki akun portal, dan siswa yang diarsipkan, menolak barisnya.'),
            __('Satu kesalahan membatalkan seluruh impor: tidak ada akun yang dibuat sampai seluruh berkas bersih.'),
            __('Akun orang tua tidak dibuat oleh berkas ini.'),
        ];
    }
}
