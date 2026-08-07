<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERD 2.2 (Akademik) — Tabel: class_subjects.
 * Mata pelajaran yang diajar guru tertentu di kelas tertentu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            // Satu mata pelajaran hanya diajarkan sekali di satu kelas pada satu
            // tahun ajaran (dasar untuk input nilai — ERD: "Dasar untuk input nilai").
            $table->unique(['class_id', 'subject_id', 'academic_year_id'], 'class_subjects_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_subjects');
    }
};
