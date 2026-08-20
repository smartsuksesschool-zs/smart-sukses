<?php

use App\Enums\FeeFrequency;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERD 2.2 (Keuangan) — Tabel: fee_types.
 * "Jenis tagihan yang dapat dibuat oleh Bendahara."
 *
 * Kolomnya persis daftar ERD. Tidak ada `updated_at`: ERD hanya mencantumkan
 * `created_at`, sama seperti `subjects` (butir 8) dan `audit_logs`. Tidak ada
 * `deleted_at` pula — SPP-01 poin 2 justru menuntut jenis tagihan
 * dinonaktifkan lewat `is_active`, bukan dihapus, agar histori tetap utuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name', 100);
            $table->decimal('amount', 12, 2);
            $table->enum('frequency', FeeFrequency::values());
            // "NULL untuk tagihan berulang". nullOnDelete mengikuti pola kolom
            // academic_year_id nullable yang sudah ada di ppdb_registrations.
            $table->foreignId('academic_year_id')->nullable()
                ->constrained('academic_years')->nullOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_types');
    }
};
