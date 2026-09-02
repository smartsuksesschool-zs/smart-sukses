<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Isi tunggal halaman muka publik: judul hero, deskripsi, tautan, kontak.
 *
 * **Sengaja tanpa `school_id`.** Halaman muka adalah situs payung Smart Sukses
 * School, bukan situs salah satu cabang; menempelkannya ke tenant berarti
 * pengunjung yang belum login harus "menjadi" sebuah cabang lebih dulu sebelum
 * ada teks yang bisa ditampilkan. Kolomnya tidak ada sama sekali, bukan
 * dibiarkan NULL — kolom nullable adalah undangan untuk diisi nanti, dan
 * sekali terisi halaman publik berubah menjadi milik satu cabang tanpa ada yang
 * memutuskan begitu (butir 464).
 *
 * Satu tabel kunci-nilai, bukan satu tabel per field. Field halaman muka
 * berjumlah belasan dan seluruhnya tunggal; memberinya kolom sendiri berarti
 * migration baru setiap kali pemilik menambah satu baris teks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();

            // Kuncinya ditulis kode, bukan dikarang admin: form Filament yang
            // menentukan kunci mana yang ada, sehingga tidak ada kunci yatim
            // yang tersimpan tanpa satu pun tempat menampilkannya.
            $table->string('key', 100)->unique();

            // `text`, bukan `string`: paragraf "Tentang" jauh melewati 255, dan
            // memisahkan kolom pendek dan panjang hanya menambah cabang tanpa
            // menambah jaminan apa pun.
            $table->text('value')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
