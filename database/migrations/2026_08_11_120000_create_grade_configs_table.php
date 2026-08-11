<?php

use App\Enums\GradeConfigStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERD 2.2 (Penilaian) — Tabel: grade_configs.
 * Konfigurasi bobot komponen penilaian per mata pelajaran per tahun ajaran.
 *
 * Kolom `version`, `status`, `activated_at`, `locked_at`, dan `updated_at`
 * berada DI LUAR ERD; ditambahkan atas keputusan Sprint 4 butir 4
 * (DRAFT → ACTIVE → LOCKED + versioning). Lihat docs/implementation-notes.md
 * butir 23 dan 24.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            // Definisi komponen & bobot: [{"type":"DAILY","weight":0.40}, ...]
            $table->json('components');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            // --- Di luar ERD: versioning (keputusan Sprint 4 butir 4) ---
            $table->unsignedSmallInteger('version')->default(1);
            $table->enum('status', GradeConfigStatus::values())
                ->default(GradeConfigStatus::Draft->value)
                ->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('locked_at')->nullable();

            $table->timestamp('created_at')->nullable();
            // ERD hanya mencantumkan created_at; updated_at diperlukan agar
            // pengeditan konfigurasi berstatus DRAFT tetap punya jejak waktu.
            $table->timestamp('updated_at')->nullable();

            // "Perubahan kebijakan bobot harus membuat versi baru, bukan overwrite."
            // Nama index ditulis eksplisit: nama bawaan Laravel melewati batas
            // 64 karakter identifier MySQL.
            $table->unique(
                ['school_id', 'subject_id', 'academic_year_id', 'version'],
                'grade_configs_scope_version_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_configs');
    }
};
