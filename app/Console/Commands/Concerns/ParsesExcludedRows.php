<?php

namespace App\Console\Commands\Concerns;

/**
 * Membaca `--kecualikan-baris` menjadi daftar penunjuk baris.
 *
 * Satu penguraian untuk ketiga perintah migrasi. Ditulis tiga kali, ia akan
 * cepat atau lambat berbeda pada satu perintah — dan perbedaan yang paling
 * mungkin adalah perintah produksi menerima nilai yang ditolak analisis kering,
 * yaitu keadaan yang justru harus mustahil: yang ditinjau dan yang diterapkan
 * harus mengurai masukan yang sama dengan cara yang sama (butir 560).
 *
 * Nilainya diteruskan sebagai **string apa adanya**, karena bentuknya dua:
 * `12` dan `Kelas 11:12`. Penerjemahannya milik StudentImportPlan, satu tempat,
 * bersama aturan kapan sebuah penunjuk berlaku (butir 566).
 */
trait ParsesExcludedRows
{
    /**
     * @return array<int, string>
     */
    protected function excludedRows(): array
    {
        $raw = (string) ($this->option('kecualikan-baris') ?? '');

        if (trim($raw) === '') {
            return [];
        }

        $rows = [];

        foreach (explode(',', $raw) as $piece) {
            $piece = trim($piece);

            if ($piece !== '') {
                $rows[] = $piece;
            }
        }

        return array_values(array_unique($rows));
    }
}
