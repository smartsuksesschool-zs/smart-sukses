<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            SchoolSeeder::class,
            UserSeeder::class,
        ]);

        /*
         * PublicSiteSeeder sengaja **tidak** didaftarkan di sini.
         *
         * Ia aman — hanya teks dan struktur, tanpa akun dan tanpa data pribadi
         * — tetapi ia menerbitkan isi ke halaman yang dilihat publik, termasuk
         * enam baris galeri yang belum berfoto. `php artisan db:seed` di
         * produksi tidak boleh menerbitkan apa pun ke situs sekolah tanpa ada
         * yang memutuskan begitu (butir 481).
         *
         * Halaman muka tetap utuh tanpanya: teksnya dari PublicSite::DEFAULTS,
         * dan bagian yang memang belum berisi tidak dirender sama sekali.
         *
         * Isi awal ditambahkan dengan sengaja, lewat panel admin atau:
         *
         *     php artisan db:seed --class=PublicSiteSeeder
         */
    }
}
