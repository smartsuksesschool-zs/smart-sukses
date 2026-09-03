<?php

namespace App\Support;

use RuntimeException;

/**
 * Satu sumber kata sandi akun hasil seeding, beserta pagarnya.
 *
 * Nilai cadangannya ada di dalam repositori dan karena itu diketahui siapa pun
 * yang dapat membaca kode ini. Di mesin pengembang itu kenyamanan yang
 * disengaja; di lingkungan yang punya nama host sungguhan ia berarti akun yang
 * dapat login lahir dengan kata sandi yang tercetak di repositori.
 *
 * Semula pagarnya hanya ada di UserSeeder dan hanya menyebut `production`. Dua
 * celah muncul begitu staging.smartsukses.sch.id direncanakan (butir 509):
 *
 *   1. `APP_ENV=staging` melewati pagar itu begitu saja;
 *   2. SimulationSeeder dan Sprint4DemoSeeder membaca env yang sama tanpa
 *      pagar apa pun, dan keduanya dapat dijalankan sendiri tanpa UserSeeder.
 *
 * Keduanya ditutup di sini sekaligus. Yang dipagari adalah "bukan lokal",
 * bukan daftar nama lingkungan — supaya lingkungan berikutnya (uat, demo,
 * sandbox) ikut terlindungi tanpa perlu diingat menambahkannya.
 *
 * Nilainya dibaca lewat **config**, tidak pernah lewat `env()` langsung.
 * `env()` di luar berkas config mengembalikan NULL begitu `config:cache`
 * dijalankan, sehingga pagar ini akan menolak seeding di staging yang justru
 * sudah benar konfigurasinya (butir 357, butir 511).
 *
 * Kata sandinya sendiri tidak pernah ikut dicetak, tidak di pesan galat dan
 * tidak di log.
 */
final class SeedPassword
{
    public const CONFIG_KEY = 'seeding.admin_password';

    public const ENVIRONMENTS_KEY = 'seeding.password_optional_environments';

    public const ENV_KEY = 'SEED_ADMIN_PASSWORD';

    /**
     * Kata sandi bawaan. Sengaja terlihat sebagai konstanta, bukan tersembunyi
     * sebagai literal di beberapa tempat: yang berbahaya bukan nilainya
     * melainkan dipakainya nilai itu di luar mesin pengembang.
     */
    public const FALLBACK = 'Password123';

    public static function resolve(): string
    {
        $password = trim((string) config(self::CONFIG_KEY, ''));

        if ($password !== '') {
            return $password;
        }

        if (! self::fallbackAllowed()) {
            throw new RuntimeException(
                self::ENV_KEY.' wajib disetel sebelum seeding di lingkungan "'
                .app()->environment().'". Tanpa itu akun hasil seeding memakai '
                .'kata sandi bawaan yang ada di dalam repositori.'
            );
        }

        return self::FALLBACK;
    }

    /**
     * Apakah kata sandi sudah disetel operator — dipakai `app:production-check`
     * tanpa pernah menyentuh nilainya.
     */
    public static function isConfigured(): bool
    {
        return trim((string) config(self::CONFIG_KEY, '')) !== '';
    }

    public static function fallbackAllowed(): bool
    {
        return app()->environment(self::optionalEnvironments());
    }

    /**
     * @return array<int, string>
     */
    public static function optionalEnvironments(): array
    {
        return (array) config(self::ENVIRONMENTS_KEY, ['local', 'testing']);
    }
}
