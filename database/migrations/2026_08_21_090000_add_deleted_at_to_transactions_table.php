<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API 4.9.2 — DELETE /transactions/{id}: "Hapus transaksi (soft delete)".
 *
 * ERD 2.2 `transactions` tidak memuat `deleted_at`, status, maupun flag aktif,
 * dan tidak ada bagian blueprint yang menjelaskan mekanisme penghapusannya.
 * Konflik ini sebelumnya dibiarkan terbuka dan penghapusan tidak dibuat sama
 * sekali (butir 74). Yang menutupnya sekarang adalah **keputusan implementasi
 * Phase 1**, bukan isi ERD: satu kolom `deleted_at` ditambahkan secara aditif
 * supaya kata "soft delete" pada API dapat dipenuhi apa adanya — barisnya
 * tetap tersimpan, hanya tidak lagi ikut terbaca.
 *
 * Kolomnya nullable dan tanpa nilai bawaan, sehingga seluruh baris yang sudah
 * ada langsung berarti "belum dihapus" tanpa satu pun perlu disentuh.
 *
 * Lihat docs/implementation-notes.md butir 128.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // `transactions` tidak punya `updated_at` (ERD), jadi kolomnya
            // ditaruh setelah `created_at` — bukan lewat `->after()` pada
            // pasangan timestamp yang memang tidak ada di sini.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
