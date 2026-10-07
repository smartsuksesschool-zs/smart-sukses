<?php

namespace Tests\Feature\Auth;

use App\Exports\AccountCredentialsExport;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * Berkas kredensial yang benar-benar diterima admin — dibaca kembali, bukan
 * diperiksa dari kodenya.
 *
 * Testnya membangkitkan `.xlsx` sungguhan, memuatnya ulang dengan
 * PhpSpreadsheet, lalu memeriksa tipe dan nilai setiap sel. Memeriksa keberadaan
 * method `safe()` atau mencocokkan potongan kode tidak akan menangkap apa pun
 * yang penting di sini: yang menentukan aman atau tidak adalah apa yang tersimpan
 * **di dalam berkasnya** (butir 595).
 *
 * Dua hal dijaga sekaligus, dan keduanya pernah gagal:
 *
 * 1. **Tidak pernah menjadi rumus.** Sandi yang diawali `=`, `+`, `-`, `@`, tab,
 *    atau carriage return tidak boleh dievaluasi Excel.
 * 2. **Sandinya tetap utuh.** Pagar formula tidak boleh mengubah sandinya —
 *    sandi yang tiba berbeda satu karakter pun adalah sandi yang tidak dapat
 *    dipakai masuk, dan itulah cacat yang ditemukan test ini pada percobaan
 *    pertama.
 */
class AccountCredentialsExportTest extends TestCase
{
    /**
     * Sandi karangan yang diawali setiap karakter berbahaya, plus satu yang
     * biasa sebagai pembanding.
     *
     * @var array<string, string>
     */
    protected const PASSWORDS = [
        'sama_dengan' => '=SUM(1+1)',
        'plus' => '+Sandi1234',
        'minus' => '-Sandi1234',
        'at' => '@Sandi1234',
        'tab' => "\tSandi1234",
        'carriage_return' => "\rSandi1234",
        'biasa' => 'Sandi1234',
    ];

    /**
     * Membangkitkan berkasnya, memuatnya kembali, dan mengembalikan lembarnya.
     */
    protected function renderedSheet(): Worksheet
    {
        $credentials = [];
        $row = 0;

        foreach (self::PASSWORDS as $label => $password) {
            $credentials[] = [
                'name' => 'Guru Karangan '.(++$row),
                'email' => $label.'@example.test',
                'role' => 'GURU',
                'password' => $password,
            ];
        }

        $path = tempnam(sys_get_temp_dir(), 'cred').'.xlsx';

        try {
            file_put_contents(
                $path,
                Excel::raw(new AccountCredentialsExport($credentials), ExcelFormat::XLSX),
            );

            // Dimuat penuh lalu lembarnya dilepas dari berkasnya, agar berkas
            // sementaranya dapat dihapus sebelum assertion berjalan.
            return IOFactory::load($path)->getActiveSheet();
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Baris data dimulai di baris 2; baris 1 judul kolom. Kolom D = kata sandi.
     *
     * @return array<string, array{type: string, value: string, quotePrefix: bool}>
     */
    protected function passwordCells(): array
    {
        $sheet = $this->renderedSheet();
        $cells = [];
        $row = 2;

        foreach (array_keys(self::PASSWORDS) as $label) {
            $coordinate = 'D'.$row;

            $cells[$label] = [
                'type' => $sheet->getCell($coordinate)->getDataType(),
                'value' => (string) $sheet->getCell($coordinate)->getValue(),
                'quotePrefix' => $sheet->getStyle($coordinate)->getQuotePrefix(),
            ];

            $row++;
        }

        return $cells;
    }

    public function test_tidak_ada_sandi_yang_tersimpan_sebagai_rumus(): void
    {
        foreach ($this->passwordCells() as $label => $cell) {
            $this->assertNotSame(
                DataType::TYPE_FORMULA,
                $cell['type'],
                "Sandi \"{$label}\" tersimpan sebagai rumus; Excel akan mengevaluasinya.",
            );

            $this->assertSame(
                DataType::TYPE_STRING,
                $cell['type'],
                "Sandi \"{$label}\" tidak tersimpan sebagai teks.",
            );
        }
    }

    /**
     * Pagar formula tidak boleh mengubah sandinya.
     *
     * Inilah cacat yang ditemukan test ini: penyisipan kutip tunggal di depan
     * nilai membuat apostrof itu tersimpan sebagai karakter biasa — tanpa atribut
     * `quotePrefix` — sehingga ia menjadi bagian dari sandi. Admin menerima
     * `'-Sandi1234` untuk akun yang sandinya `-Sandi1234`, dan login gagal.
     */
    public function test_sandi_tersimpan_utuh_tanpa_karakter_tambahan(): void
    {
        $cells = $this->passwordCells();

        foreach (self::PASSWORDS as $label => $expected) {
            $actual = $cells[$label]['value'];

            $this->assertStringStartsNotWith(
                "'",
                $actual,
                "Sandi \"{$label}\" tersimpan dengan apostrof di depan; apostrofnya akan ikut terbaca sebagai bagian sandi.",
            );

            /*
             * Carriage return dinormalkan menjadi line feed oleh format xlsx
             * sendiri, bukan oleh kode ini. Yang dijaga untuk kasus itu adalah
             * muatannya tetap utuh.
             */
            if ($label === 'carriage_return') {
                $this->assertSame("\nSandi1234", $actual);

                continue;
            }

            $this->assertSame(
                $expected,
                $actual,
                "Sandi \"{$label}\" berubah di dalam berkas; sandi yang berubah tidak dapat dipakai masuk.",
            );
        }
    }

    /**
     * Untuk nilai yang diawali `=`, Excel menuntut atribut `quotePrefix` agar
     * selnya ditampilkan sebagai teks. PhpSpreadsheet menyetelnya sendiri ketika
     * nilainya diikat sebagai string — dan itulah mekanisme yang benar, bukan
     * apostrof yang ditulis ke dalam nilainya.
     */
    public function test_nilai_yang_diawali_sama_dengan_memakai_quote_prefix_excel(): void
    {
        $cells = $this->passwordCells();

        $this->assertTrue(
            $cells['sama_dengan']['quotePrefix'],
            'Sel yang diawali "=" harus memakai atribut quotePrefix Excel.',
        );

        // Sandi biasa tidak perlu penanda apa pun.
        $this->assertFalse($cells['biasa']['quotePrefix']);
    }

    /**
     * Kolom lain ikut terlindungi: surel dan nama pun tidak boleh menjadi rumus
     * bila suatu saat memuat karakter berbahaya.
     */
    public function test_seluruh_kolom_ditulis_sebagai_teks(): void
    {
        $sheet = $this->renderedSheet();

        foreach (['A', 'B', 'C', 'D'] as $column) {
            for ($row = 2; $row <= 8; $row++) {
                $this->assertNotSame(
                    DataType::TYPE_FORMULA,
                    $sheet->getCell($column.$row)->getDataType(),
                    "Sel {$column}{$row} tersimpan sebagai rumus.",
                );
            }
        }
    }

    public function test_judul_kolom_dan_jumlah_baris_sesuai(): void
    {
        $sheet = $this->renderedSheet();

        $this->assertSame('Nama', (string) $sheet->getCell('A1')->getValue());
        $this->assertSame('Surel', (string) $sheet->getCell('B1')->getValue());
        $this->assertSame('Peran', (string) $sheet->getCell('C1')->getValue());
        $this->assertSame('Kata Sandi Sementara', (string) $sheet->getCell('D1')->getValue());

        // Tujuh baris data, satu per sandi uji.
        $this->assertSame(8, $sheet->getHighestRow());
    }
}
