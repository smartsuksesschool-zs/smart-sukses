<?php

use App\Enums\ExamAttemptStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DI LUAR ERD 2.2 — satu pengerjaan ujian oleh satu siswa.
 *
 * Tiga hal yang sengaja dijamin di lapisan database, bukan aplikasi:
 *
 *  1. UNIQUE (exam_id, student_id) — "satu percobaan per ujian per siswa".
 *     Aturan ini ditegakkan database supaya dua request yang datang bersamaan
 *     tidak dapat membuat dua percobaan; pemeriksaan di PHP saja akan lolos
 *     pada balapan itu (butir 271).
 *
 *  2. `expires_at` disimpan, bukan dihitung ulang saat halaman dibuka. Batas
 *     waktunya ditetapkan server sekali di awal, sehingga tidak ada nilai yang
 *     berasal dari jam peramban siswa.
 *
 *  3. `score` disimpan di sini, bukan dihitung ulang dari jawaban setiap kali
 *     dibaca. Nilai yang sudah final adalah fakta yang sudah terjadi; menghitung
 *     ulangnya berarti mengubah nilai lama setiap kali rumusnya bergeser.
 *
 * `grade_id` menyiapkan jembatan "Masukkan ke Nilai" yang **tidak** dikerjakan
 * pada batch ini: nilai CBT tidak pernah otomatis menjadi Grade. Kolomnya ada
 * supaya jembatan itu nanti tahu percobaan mana yang sudah pernah dimasukkan,
 * dan `nullOnDelete` supaya menghapus baris nilai tidak ikut menghapus hasil
 * ujiannya (butir 272).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();

            $table->enum('status', ExamAttemptStatus::values())
                ->default(ExamAttemptStatus::InProgress->value);

            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('submitted_at')->nullable();

            // Skala 0.00–100.00, presisi yang sama dengan `grades.score`
            // sehingga tidak ada pembulatan kedua saat nilainya nanti
            // dimasukkan sebagai nilai akademik.
            $table->decimal('score', 5, 2)->nullable();

            $table->foreignId('grade_id')->nullable()->constrained('grades')->nullOnDelete();

            $table->timestamps();

            $table->unique(['exam_id', 'student_id']);
            $table->index(['school_id', 'exam_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_attempts');
    }
};
