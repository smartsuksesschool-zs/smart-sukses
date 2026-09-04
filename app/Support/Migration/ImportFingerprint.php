<?php

namespace App\Support\Migration;

use App\Models\AcademicYear;
use App\Models\School;
use RuntimeException;

/**
 * M5 — sidik jari deterministik satu rencana impor.
 *
 * Menutup satu kekeliruan yang sangat mungkin terjadi dan hampir mustahil
 * terlihat: analisis kering dijalankan atas berkas A, lalu mode terap dijalankan
 * atas berkas B. Keduanya mencetak angka yang meyakinkan, dan yang masuk ke
 * basis data bukan yang ditinjau siapa pun (butir 519).
 *
 * Sidik jarinya menutup **empat** hal sekaligus, bukan hanya berkasnya:
 *
 *   1. isi berkas (sha256 penuh atas byte-nya);
 *   2. cabang tujuan;
 *   3. tahun ajaran tujuan;
 *   4. hasil rekonsiliasi yang sudah dinormalkan.
 *
 * Angka rekonsiliasi ikut dihitung karena berkas yang sama dapat menghasilkan
 * rencana yang berbeda bila basis datanya berubah di antara kedua perintah —
 * rombel dihapus, siswa ditambahkan tangan, tahun ajaran diganti. Sidik jari
 * yang hanya membaca berkas akan menyatakan keduanya sama padahal yang akan
 * ditulis sudah berbeda.
 *
 * **Tidak memuat data pribadi.** Yang masuk hitungan hanya hash berkas dan
 * angka; tidak ada NIS, NISN, nama, maupun isi baris yang ikut, dan sidik
 * jarinya sendiri tidak dapat dikembalikan menjadi isi berkas.
 */
final class ImportFingerprint
{
    /**
     * Panjang sidik jari yang ditampilkan. Cukup panjang untuk tidak bertabrakan
     * secara kebetulan, cukup pendek untuk disalin operator dengan tangan.
     */
    public const LENGTH = 16;

    private function __construct(
        public readonly string $value,
        public readonly string $fileHash,
        public readonly string $fileName,
    ) {}

    /**
     * @param  array<string, mixed>  $plan
     */
    public static function of(string $path, School $school, ?AcademicYear $year, array $plan): self
    {
        if (! is_file($path)) {
            // Jalur berkas tidak pernah ikut pesan: berkas ini privat dan
            // letaknya di luar repositori.
            throw new RuntimeException('Berkas sumber tidak ditemukan saat menghitung sidik jari.');
        }

        $fileHash = hash_file('sha256', $path);

        if ($fileHash === false) {
            throw new RuntimeException('Berkas sumber tidak terbaca saat menghitung sidik jari.');
        }

        $material = implode('|', [
            'v1',
            $fileHash,
            $school->code,
            $year?->name ?? '-',
            self::totals($plan),
        ]);

        return new self(
            substr(hash('sha256', $material), 0, self::LENGTH),
            $fileHash,
            basename($path),
        );
    }

    public function matches(string $candidate): bool
    {
        // hash_equals: perbandingannya bukan rahasia, tetapi membandingkan hash
        // dengan panjang tetap memang pekerjaannya.
        return hash_equals($this->value, mb_strtolower(trim($candidate)));
    }

    /**
     * Angka rekonsiliasi yang sudah dinormalkan, sebagai teks yang urutannya
     * pasti — `ksort` supaya urutan kunci PHP tidak pernah mengubah sidik jari.
     *
     * @param  array<string, mixed>  $plan
     */
    protected static function totals(array $plan): string
    {
        $parts = [];

        foreach (['reconciliation', 'outcomes', 'nisn', 'placements'] as $section) {
            $values = (array) ($plan[$section] ?? []);
            ksort($values);

            foreach ($values as $key => $value) {
                if (is_bool($value)) {
                    $value = $value ? '1' : '0';
                }

                if (! is_scalar($value)) {
                    continue;
                }

                $parts[] = "{$section}.{$key}={$value}";
            }
        }

        return implode(';', $parts);
    }
}
