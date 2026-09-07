<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * PENYIMPANGAN DARI ERD — disengaja.
 *
 * ERD 2.2 (smartsukses-docs/02-erd/02-tables-core.md) memodelkan `users` sebagai
 * akun berkata sandi: tidak ada satu pun atribut identitas penyedia luar.
 * Keputusan pemilik menambahkan pendaftaran mandiri lewat Google bagi siswa dan
 * orang tua, sehingga sebuah akun kini dapat lahir tanpa kata sandi sama sekali.
 *
 * Dua perubahan, keduanya aditif:
 *
 *  1. `auth_provider` + `provider_subject` — identitas stabil dari penyedia.
 *     Yang dipakai sebagai kunci **bukan** surelnya: surel Google dapat
 *     berganti pemilik ketika sebuah akun dihapus dan namanya didaftarkan orang
 *     lain, sedangkan `sub` tidak pernah dipakai ulang. Surel tetap disimpan,
 *     tetapi sebagai keterangan, bukan sebagai identitas.
 *
 *     Dua kolom, bukan satu `google_id`, karena keduanya bersama-sama membentuk
 *     kunci unik yang tidak akan bertabrakan bila kelak ada penyedia kedua.
 *     Itu keseluruhan "kerangka multi-penyedia"-nya: dua kolom dan satu indeks,
 *     bukan tabel identitas tersendiri yang hari ini hanya akan berisi satu
 *     baris per pengguna.
 *
 *  2. `password` menjadi nullable. Akun Google tidak punya kata sandi, dan
 *     satu-satunya alternatifnya adalah menyimpan hash acak yang tidak dapat
 *     dipakai siapa pun — nilai yang berbohong tentang keadaan akun. NULL
 *     mengatakan yang sebenarnya, dan Laravel sudah menolaknya dengan benar:
 *     `AbstractHasher::check()` mengembalikan false untuk hash NULL, jadi
 *     `Auth::attempt()` atas akun Google selalu gagal tanpa aturan tambahan.
 *
 * Akun berkata sandi yang sudah ada tidak tersentuh: kolomnya bertambah dengan
 * nilai NULL, dan tidak ada satu baris pun yang diubah.
 *
 * Catatan selengkapnya: docs/auth/google-account-claims.md, butir 529.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('auth_provider', 20)->nullable()->after('email_verified_at');
            $table->string('provider_subject', 191)->nullable()->after('auth_provider');

            /*
             * Satu identitas penyedia hanya boleh dimiliki satu akun.
             *
             * Keduanya nullable, dan pada MySQL maupun SQLite dua baris yang
             * sama-sama NULL dianggap berbeda oleh indeks unik. Karena itu
             * seluruh akun berkata sandi — yang tidak punya penyedia — tetap
             * dapat hidup berdampingan tanpa pengecualian apa pun.
             */
            $table->unique(['auth_provider', 'provider_subject']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['auth_provider', 'provider_subject']);
            $table->dropColumn(['auth_provider', 'provider_subject']);
        });

        /*
         * Membalikkan nullable hanya aman bila tidak ada akun tanpa kata sandi
         * yang tertinggal. Akun Google memang tidak punya kata sandi, jadi
         * mengembalikan NOT NULL akan menggagalkan migrasi turun dengan galat
         * basis data yang tidak menerangkan apa pun. Diisi lebih dulu dengan
         * hash acak yang tidak dapat dipakai siapa pun — akunnya toh sudah
         * kehilangan satu-satunya cara masuknya pada baris di atas.
         */
        DB::table('users')
            ->whereNull('password')
            ->update(['password' => bcrypt(Str::random(64))]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
