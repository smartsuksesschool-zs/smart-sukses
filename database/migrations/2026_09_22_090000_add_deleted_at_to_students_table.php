<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arsip siswa — satu kolom `deleted_at`, aditif, tanpa menyentuh data lama.
 *
 * SIS-02 poin 2 menetapkan siswa **tidak pernah dihapus dari basis data**, dan
 * itu tetap berlaku sepenuhnya: kolom ini justru yang membuat tombol "Arsipkan"
 * di panel dapat ada tanpa satu baris pun benar-benar hilang. Tidak ada DELETE,
 * sehingga tujuh FK `cascadeOnDelete` ke `students` — nilai, rapor, tagihan,
 * pembayaran, kelas, percobaan ujian, permintaan akun — tidak pernah berjalan.
 *
 * Arsip **bukan** status akademik. `students.status`
 * (ACTIVE/GRADUATED/DROPPED_OUT/TRANSFERRED, ERD 2.2) menjawab "apa yang
 * terjadi pada siswa ini", sedangkan `deleted_at` menjawab "barisnya masih
 * dipakai atau tidak". Menggabungkan keduanya akan membuat satu kolom memikul
 * dua pertanyaan yang jawabannya dapat berbeda — siswa yang lulus tetap boleh
 * tampil, dan siswa salah input boleh disembunyikan tanpa berpura-pura lulus.
 *
 * Nilai `INACTIVE` yang disebut kalimat SIS-02 sengaja **tidak** ditambahkan ke
 * enum: ERD 2.2 `students.status` menyebut empat nilai di atas dan tidak memuat
 * INACTIVE, dan aturan yang lebih spesifik terhadap kolomnya yang dipakai
 * (butir 589).
 *
 * Kolomnya nullable tanpa bawaan, sehingga seluruh baris yang sudah ada
 * langsung berarti "belum diarsipkan" tanpa perlu disentuh. Polanya mengikuti
 * `2026_08_21_090000_add_deleted_at_to_transactions_table.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
