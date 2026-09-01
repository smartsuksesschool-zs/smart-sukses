<?php

namespace App\Support\Migration;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;

/**
 * M1 — pembaca berkas Excel sekolah *apa adanya*.
 *
 * Berbeda dari App\Imports\StudentsImport yang menuntut satu baris heading rapi
 * di baris pertama, berkas sungguhan sekolah tersusun per angkatan: judul,
 * baris heading yang **berulang** di setiap seksi, lalu baris rekap
 * "Jumlah Siswa Kelas N". Menuntut sekolah merapikan berkasnya lebih dulu hanya
 * memindahkan pekerjaan — dan setiap penyalinan manual adalah kesempatan salah
 * ketik pada data identitas (butir 449).
 *
 * Kelas ini **hanya membaca**. Ia tidak memvalidasi, tidak memutuskan, dan tidak
 * pernah menyentuh basis data.
 */
class LegacyWorkbook
{
    /**
     * Sinonim heading -> nama kanonis. Kunci sudah dinormalisasi
     * (huruf kecil, tanpa tanda baca, spasi tunggal).
     *
     * Sekolah boleh menambah kolom kapan pun: kolom yang dikenali langsung
     * terbaca tanpa perubahan kode, kolom asing diabaikan dengan aman.
     *
     * @var array<string, string>
     */
    protected const STUDENT_HEADINGS = [
        'no' => 'seq',
        'nama' => 'full_name',
        'nama lengkap' => 'full_name',
        'nama siswa' => 'full_name',
        'kelas' => 'class_label',
        'alamat' => 'address',
        'nis' => 'nis',
        'nisn' => 'nisn',
        'jenis kelamin' => 'gender',
        'l p' => 'gender',
        'gender' => 'gender',
        'tempat lahir' => 'birth_place',
        'tanggal lahir' => 'birth_date',
        'agama' => 'religion',
        'nama orang tua' => 'parent_name',
        'nama ortu' => 'parent_name',
        'hp orang tua' => 'parent_phone',
        'no hp orang tua' => 'parent_phone',
        'email orang tua' => 'parent_email',
        'tahun masuk' => 'entry_year',
        'email akun siswa' => 'account_email',
        'email siswa' => 'account_email',
    ];

    /**
     * @var array<string, string>
     */
    protected const TEACHER_HEADINGS = [
        'no' => 'seq',
        'nama' => 'full_name',
        'nama guru' => 'full_name',
        'pelajaran' => 'assignment',
        'mata pelajaran' => 'assignment',
        'mapel' => 'assignment',
        'email' => 'account_email',
        'email guru' => 'account_email',
        'no hp' => 'phone',
    ];

    protected ?Spreadsheet $loaded = null;

    public function __construct(protected string $path)
    {
        if (! is_file($this->path)) {
            // Jalur berkas sengaja tidak ikut pesan: berkas ini privat dan
            // letaknya di luar repositori (butir 450).
            throw new RuntimeException('Berkas sumber tidak ditemukan.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function students(string $sheet = 'Data Siswa'): array
    {
        return $this->readSectioned($sheet, self::STUDENT_HEADINGS);
    }

    /**
     * @return array<string, mixed>
     */
    public function teachers(string $sheet = 'Data Guru'): array
    {
        return $this->readSectioned($sheet, self::TEACHER_HEADINGS);
    }

    /**
     * @return array<int, string>
     */
    public function sheetNames(): array
    {
        return $this->spreadsheet()->getSheetNames();
    }

    /**
     * Satu lintasan untuk kedua lembar: keduanya berbentuk sama — judul, heading
     * yang boleh berulang, baris data bernomor, dan baris rekap.
     *
     * @param  array<string, string>  $headings
     * @return array<string, mixed>
     */
    protected function readSectioned(string $sheet, array $headings): array
    {
        $worksheet = $this->spreadsheet()->getSheetByName($sheet);

        if ($worksheet === null) {
            throw new RuntimeException("Lembar \"{$sheet}\" tidak ada di berkas sumber.");
        }

        $grid = $worksheet->rangeToArray(
            'A1:'.$worksheet->getHighestDataColumn().$worksheet->getHighestDataRow(),
            null,
            false,
            false,
        );

        $map = [];
        $unknown = [];
        $rows = [];
        $declared = [];
        $ignored = 0;
        $headingRows = 0;

        foreach ($grid as $offset => $cells) {
            $line = $offset + 1;

            if ($this->isBlank($cells)) {
                continue;
            }

            if ($this->looksLikeHeading($cells, $headings)) {
                $headingRows++;
                [$map, $found] = $this->mapColumns($cells, $headings);
                $unknown = array_values(array_unique(array_merge($unknown, $found)));

                continue;
            }

            $first = $this->text($cells[0] ?? null);

            // "Jumlah Siswa Kelas 10: 13 siswa" — angka yang ditulis sekolah
            // sendiri, dipakai sebagai pemeriksaan silang terhadap hasil parsing
            // (butir 451). Bukan sumber data.
            if (preg_match('/^jumlah\b/i', $first) === 1) {
                if (preg_match('/(\d+)\s*[a-z]*\s*$/i', $first, $m) === 1) {
                    $declared[] = ['label' => $first, 'count' => (int) $m[1]];
                }

                continue;
            }

            $row = $this->extract($cells, $map, $line);

            if ($row === null) {
                $ignored++;

                continue;
            }

            $rows[] = $row;
        }

        return [
            'rows' => $rows,
            'declared_totals' => $declared,
            'ignored_rows' => $ignored,
            'unknown_headings' => $unknown,
            'heading_rows' => $headingRows,
        ];
    }

    /**
     * Baris data adalah baris yang nomor urutnya angka **dan** namanya terisi.
     * Keduanya bersama-sama, karena masing-masing sendirian juga cocok dengan
     * baris judul maupun baris rekap.
     *
     * @param  array<int, mixed>  $cells
     * @param  array<int, string>  $map
     * @return array<string, mixed>|null
     */
    protected function extract(array $cells, array $map, int $line): ?array
    {
        if ($map === []) {
            return null;
        }

        $row = ['source_line' => $line];

        foreach ($map as $index => $field) {
            $row[$field] = $this->cell($cells[$index] ?? null, $field);
        }

        if (! is_numeric($row['seq'] ?? null) || ($row['full_name'] ?? '') === '') {
            return null;
        }

        return $row;
    }

    /**
     * @param  array<int, mixed>  $cells
     * @param  array<string, string>  $headings
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    protected function mapColumns(array $cells, array $headings): array
    {
        $map = [];
        $unknown = [];

        foreach ($cells as $index => $value) {
            $key = $this->headingKey($value);

            if ($key === '') {
                continue;
            }

            if (isset($headings[$key])) {
                $map[$index] = $headings[$key];

                continue;
            }

            $unknown[] = $this->text($value);
        }

        return [$map, $unknown];
    }

    /**
     * Sebuah baris dianggap heading bila memuat minimal dua judul kolom yang
     * dikenali. Satu saja terlalu longgar: baris judul dokumen
     * ("Data Siswa SMA ...") kebetulan diawali kata yang mirip.
     *
     * @param  array<int, mixed>  $cells
     * @param  array<string, string>  $headings
     */
    protected function looksLikeHeading(array $cells, array $headings): bool
    {
        $hits = 0;

        foreach ($cells as $value) {
            if (isset($headings[$this->headingKey($value)])) {
                $hits++;
            }
        }

        return $hits >= 2;
    }

    protected function headingKey(mixed $value): string
    {
        $text = mb_strtolower($this->text($value));
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    /**
     * @param  array<int, mixed>  $cells
     */
    protected function isBlank(array $cells): bool
    {
        foreach ($cells as $value) {
            if ($this->text($value) !== '') {
                return false;
            }
        }

        return true;
    }

    protected function cell(mixed $value, string $field): mixed
    {
        if ($field !== 'birth_date') {
            return $this->text($value);
        }

        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value)
            ? ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d')
            : $this->text($value);
    }

    protected function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    }

    protected function spreadsheet(): Spreadsheet
    {
        if ($this->loaded === null) {
            $reader = IOFactory::createReaderForFile($this->path);
            $reader->setReadDataOnly(true);
            $this->loaded = $reader->load($this->path);
        }

        return $this->loaded;
    }
}
