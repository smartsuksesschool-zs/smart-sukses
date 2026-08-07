<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERD 2.2 (Akademik) — Tabel: academic_years.
 * Tahun ajaran dan semester aktif per cabang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->tinyInteger('semester');
            // "Hanya satu tahun ajaran per sekolah boleh aktif" — ditegakkan di
            // level aplikasi (App\Models\AcademicYear::activate), bukan constraint
            // DB, karena MySQL tidak mendukung partial unique index.
            $table->boolean('is_active')->default(false)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_years');
    }
};
