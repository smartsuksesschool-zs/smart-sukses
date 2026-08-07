<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERD 2.2 (Akademik) — Tabel: classes (rombel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->string('name', 50);
            $table->tinyInteger('grade_level');
            $table->foreignId('homeroom_teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('room', 50)->nullable();
            $table->smallInteger('capacity')->default(35);
            $table->timestamps();

            // KELAS-01 poin 3: satu guru hanya boleh menjadi wali kelas satu kelas
            // per tahun ajaran. MySQL mengizinkan banyak NULL pada unique index,
            // sehingga kelas tanpa wali kelas tetap bisa lebih dari satu.
            $table->unique(['academic_year_id', 'homeroom_teacher_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classes');
    }
};
