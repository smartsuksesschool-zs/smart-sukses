<?php

namespace App\Exports\Sheets;

use App\Enums\Gender;
use App\Enums\StudentStatus;
use App\Imports\StudentsImport;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Lembar penjelasan: kolom mana wajib, nilai apa yang diterima, dan apa yang
 * tidak boleh diubah.
 *
 * Daftar kolomnya dibangkitkan dari StudentsImport::COLUMNS, jadi kolom baru
 * tidak dapat masuk ke importer tanpa muncul di sini (butir 497).
 *
 * Contoh pengisian sengaja diletakkan di lembar ini, bukan di lembar "Data
 * Siswa": importer tidak pernah membaca lembar ini, sehingga contohnya tidak
 * mungkin ikut terimpor sebagai siswa (butir 498). Isinya karangan seluruhnya —
 * tidak ada satu pun identitas siswa sungguhan di berkas yang dibangkitkan
 * aplikasi.
 */
class StudentTemplateGuideSheet implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * Contoh pengisian. Seluruhnya karangan: NIS berpola nol, NISN bukan milik
     * siapa pun, dan surelnya memakai domain `.test` yang memang dicadangkan
     * untuk contoh (RFC 2606).
     *
     * @var array<string, string>
     */
    protected const EXAMPLE = [
        'nis' => 'T000000001',
        'nisn' => '0012345678',
        'nama_lengkap' => 'Budi Contoh Pratama',
        'jenis_kelamin' => 'L',
        'tempat_lahir' => 'Depok',
        'tanggal_lahir' => '2010-05-17',
        'agama' => 'Islam',
        'alamat' => 'Jl. Contoh No. 1',
        'nama_orang_tua' => 'Orang Tua Contoh',
        'hp_orang_tua' => '081200000000',
        'email_orang_tua' => 'orangtua.contoh@example.test',
        'tahun_masuk' => '2026',
        'status' => 'ACTIVE',
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

        foreach (array_keys(StudentsImport::COLUMNS) as $column) {
            $required = in_array($column, StudentsImport::REQUIRED_COLUMNS, true);

            $rows[] = [
                $column,
                $required ? __('Wajib') : __('Opsional'),
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
        $rows[] = array_keys(StudentsImport::COLUMNS);
        $rows[] = array_values(self::EXAMPLE);

        return $rows;
    }

    protected function guidance(string $column): string
    {
        return match ($column) {
            'nis' => __('NIS resmi dari sekolah. Jangan mengarang nomor sementara.'),
            'nisn' => __('10 digit. Setel selnya sebagai teks agar angka nol di depan tidak hilang.'),
            'nama_lengkap' => __('Nama lengkap sesuai dokumen resmi.'),
            'jenis_kelamin' => __('Diisi :values.', ['values' => implode(' / ', array_column(Gender::cases(), 'value'))]),
            'tanggal_lahir' => __('Format YYYY-MM-DD, contoh 2010-05-17.'),
            'email_orang_tua' => __('Alamat surel yang sah, atau dikosongkan.'),
            'tahun_masuk' => __('Empat digit tahun, contoh 2026.'),
            'status' => __('Diisi :values. Bila dikosongkan dianggap ACTIVE.', [
                'values' => implode(', ', array_column(StudentStatus::cases(), 'value')),
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
            __('Jangan mengubah nama kolom di baris pertama lembar "Data Siswa".'),
            __('Jangan menggabungkan sel (merged cells).'),
            __('Satu baris untuk satu siswa; jangan menyisipkan baris judul atau baris jumlah.'),
            __('NIS harus unik di dalam satu cabang. NIS yang sudah ada akan ditolak, bukan ditimpa.'),
            __('Penempatan kelas tidak diatur lewat berkas ini. Lakukan lewat menu Kelas setelah siswa masuk.'),
            __('Akun portal siswa tidak dibuat oleh import ini.'),
        ];
    }
}
