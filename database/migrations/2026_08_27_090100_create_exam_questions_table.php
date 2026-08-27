<?php

use App\Enums\ExamQuestionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DI LUAR ERD 2.2 — soal satu ujian online. Lihat docs/cbt-mvp-scope.md.
 *
 * `question_type` menyimpan seluruh nilai ExamQuestionType, termasuk ESSAY yang
 * **tidak** didukung rilis ini. Itu keputusan skema, bukan scope: menambah
 * nilai ke kolom ENUM MySQL yang sudah berisi data adalah perubahan skema pada
 * tabel hidup, sementara menuliskannya sekarang tidak berbiaya. Yang menahan
 * ESSAY tetap tertutup adalah validasi aplikasi, bukan kolom ini (butir 266).
 *
 * `points` adalah bobot soal di dalam ujiannya sendiri — bukan `grades.weight`,
 * yang adalah bobot komponen rapor. Keduanya tidak pernah bertemu: nilai ujian
 * dinormalisasi ke 0–100 sebelum ada kaitan apa pun dengan nilai akademik
 * (butir 268).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();

            $table->enum('question_type', ExamQuestionType::values());
            $table->text('question_text');
            // Skala yang sama dengan `grades.score` dan `exam_attempts.score`,
            // sehingga tidak ada tiga presisi berbeda dalam satu alur hitung.
            $table->decimal('points', 5, 2)->default(1.00);
            $table->unsignedSmallInteger('position')->default(1);

            $table->timestamps();

            // Soal selalu dibaca berurutan, tidak pernah satu-satu.
            $table->index(['exam_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_questions');
    }
};
