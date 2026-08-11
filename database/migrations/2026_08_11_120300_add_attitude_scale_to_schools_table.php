<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Di luar ERD 2.2 — keputusan Sprint 4 butir 3:
 * "Range attitude JANGAN hard-code. Simpan sebagai configuration agar dapat
 * diubah Admin."
 *
 * Ditempatkan di `schools` karena ERD mendeskripsikan tabel itu sebagai
 * "Data dan konfigurasi setiap cabang sekolah (tenant)". NULL berarti cabang
 * memakai rentang default App\Enums\AttitudePredicate::defaultScale().
 *
 * Lihat docs/implementation-notes.md butir 27.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            // Batas bawah per predikat: {"A": 86, "B": 76, "C": 66, "D": 0}
            $table->json('attitude_scale')->nullable()->after('wa_template_rapor');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('attitude_scale');
        });
    }
};
