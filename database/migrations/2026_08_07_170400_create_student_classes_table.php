<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERD 2.2 (Akademik) — Tabel: student_classes.
 * Pivot penempatan siswa ke kelas per tahun ajaran; hanya created_at sesuai ERD.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            // KELAS-02 poin 2 ditegakkan di level aplikasi (hanya satu baris ACTIVE
            // per siswa per tahun ajaran); baris berstatus MOVED sengaja disimpan
            // sebagai histori perpindahan kelas sehingga unique index tidak dipakai.
            $table->enum('status', ['ACTIVE', 'MOVED'])->default('ACTIVE');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_classes');
    }
};
