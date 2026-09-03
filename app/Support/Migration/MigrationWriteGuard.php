<?php

namespace App\Support\Migration;

use Illuminate\Support\Facades\DB;

/**
 * M3 — pagar tulis impor uji.
 *
 * Impor migrasi menulis data induk siswa. Sebuah perintah yang bisa diarahkan
 * ke basis data mana pun cepat atau lambat akan diarahkan ke yang salah —
 * biasanya larut malam, biasanya dengan `--force` yang disalin dari catatan
 * lama. Karena itu perintah terapnya tidak dibuat serba-guna: ia menolak
 * berjalan kecuali ketiga pagar di bawah terbuka bersamaan (butir 491).
 *
 * 1. Lingkungan bukan `production`.
 * 2. Nama basis data berpola basis data uji.
 * 3. Konfirmasi eksplisit di baris perintah (dipagari perintahnya sendiri).
 *
 * Pagar kedua yang paling menentukan. `APP_ENV=local` di mesin pengembang tetap
 * menunjuk `smartsukses` — basis data kerja yang berisi data pemilik sungguhan.
 * Selama fase ini, satu-satunya sasaran yang sah adalah basis data yang
 * namanya sendiri menyatakan ia basis data uji.
 */
class MigrationWriteGuard
{
    /**
     * Akhiran/nama yang menandai basis data uji.
     *
     * @var array<int, string>
     */
    protected const TEST_NAMES = [':memory:', 'testing'];

    protected const TEST_SUFFIX = '_test';

    /**
     * Alasan penolakan, atau null bila menulis diperbolehkan.
     */
    public static function refusal(): ?string
    {
        if (app()->environment('production')) {
            return 'Lingkungan `production`. Impor uji tidak pernah menyentuh produksi.';
        }

        $database = self::database();

        if ($database === null) {
            return 'Nama basis data tidak terbaca dari koneksi yang aktif.';
        }

        if (! self::looksLikeTestDatabase($database)) {
            return "Basis data \"{$database}\" bukan basis data uji. "
                .'Sasaran yang sah berakhiran `_test`, bernama `testing`, atau `:memory:`.';
        }

        return null;
    }

    public static function allowed(): bool
    {
        return self::refusal() === null;
    }

    public static function looksLikeTestDatabase(string $database): bool
    {
        $name = mb_strtolower(basename(str_replace('\\', '/', $database)));

        if (in_array($name, self::TEST_NAMES, true)) {
            return true;
        }

        // `smartsukses_test.sqlite` sama sahnya dengan `smartsukses_test`.
        $name = preg_replace('/\.(sqlite|sqlite3|db)$/', '', $name) ?? $name;

        return str_ends_with($name, self::TEST_SUFFIX);
    }

    public static function database(): ?string
    {
        $name = DB::connection()->getDatabaseName();

        return ($name === '' || $name === null) ? null : $name;
    }
}
