<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Yang dipanggil otomatis hanya tiga, dan ketiganya adalah **prasyarat
     * struktural**: tanpa mereka aplikasi tidak dapat dipakai siapa pun.
     *
     *   RolePermissionSeeder — peran dan izin; tanpa ini setiap policy menolak
     *                          semua orang, termasuk Super Admin.
     *   SchoolSeeder         — cabang PUSAT; seluruh data lain menggantung
     *                          padanya lewat school_id.
     *   UserSeeder           — akun awal; tanpa satu akun pun, panel tidak
     *                          dapat dimasuki untuk membuat akun berikutnya.
     *
     * Tidak ada data contoh di antaranya. `php artisan db:seed` karena itu aman
     * dijalankan di lingkungan mana pun yang memang sedang disiapkan — termasuk
     * staging — tanpa menerbitkan isi halaman muka maupun membuat akun demo
     * (butir 513).
     *
     * Seeder demo tetap harus dipanggil dengan sengaja, satu per satu; lihat
     * docs/deployment/staging-uat.md.
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
         *
         * SimulationSeeder dan Sprint4DemoSeeder juga sengaja tidak di sini.
         * Keduanya membuat akun yang dapat login, dan akun demo yang lahir
         * tanpa diminta adalah pintu masuk — bukan data contoh (butir 459).
         * Adanya staging tidak mengubah itu: staging pun harus memintanya
         * dengan sengaja.
         */
    }
}
