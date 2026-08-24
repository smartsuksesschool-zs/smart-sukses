<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERD 2.2 (Komunikasi) — Tabel: notification_reads.
 * "Tabel pivot yang merecord siapa saja yang sudah membaca notifikasi
 * tertentu."
 *
 * Empat kolom, persis daftar ERD. Yang sengaja **tidak** ada:
 *
 *  - `school_id` — pivot ini tidak membawa cabangnya sendiri, dan karena itu
 *    tidak boleh dipakai sebagai pagar tenant. Setiap pembacaannya wajib
 *    disandarkan pada `notifications` yang sudah terotorisasi (butir 193).
 *  - `created_at`/`updated_at` — `read_at` adalah satu-satunya waktu yang
 *    berarti di sini: "waktu pertama kali dibaca".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at');

            // ERD tidak menyatakan keunikannya, tetapi maknanya menyatakannya:
            // "waktu **pertama kali** dibaca" hanya masuk akal bila satu
            // pengguna punya satu baris per notifikasi. Tanpa pagar ini, dua
            // permintaan tandai-baca yang tiba bersamaan akan menghasilkan dua
            // baris dan waktu baca pertama menjadi ambigu. Idempotensi tetap
            // ditegakkan aplikasi; indeks ini pagar terakhirnya, dan dicatat
            // sebagai pengerasan implementasi — bukan klaim ERD (butir 192).
            $table->unique(['notification_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_reads');
    }
};
