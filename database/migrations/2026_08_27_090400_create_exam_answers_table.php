<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DI LUAR ERD 2.2 — jawaban satu siswa atas satu soal.
 *
 * UNIQUE (exam_attempt_id, exam_question_id) melayani dua hal sekaligus:
 * ia menyatakan aturan bisnisnya ("satu jawaban per soal"), dan ia membuat
 * penyimpanan otomatis saat siswa berpindah soal menjadi satu upsert alih-alih
 * baca-lalu-tulis yang dapat berlomba dengan dirinya sendiri (butir 273).
 *
 * `is_correct` dan `points_earned` adalah **snapshot penilaian**, bukan
 * pertanyaan yang dijawab ulang setiap kali hasilnya dibuka — pola yang sama
 * dengan `grades.weight` (keputusan Sprint 4 butir 2). Mengubah kunci jawaban
 * setelah ada yang mengerjakan karena itu tidak menggeser nilai yang sudah
 * terjadi.
 *
 * `answer_text` disiapkan untuk soal uraian yang **tidak** dikerjakan rilis
 * ini. Kolom nullable pada tabel kosong tidak berbiaya, sedangkan menambah
 * kolom ke tabel jawaban yang sudah hidup adalah perubahan skema yang menyentuh
 * data siswa (butir 266).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('exam_attempt_id')->constrained('exam_attempts')->cascadeOnDelete();
            $table->foreignId('exam_question_id')->constrained('exam_questions')->cascadeOnDelete();

            // Pilihan yang diambil siswa. NULL berarti soal ini dilewati —
            // keadaan yang wajar dan bernilai nol, bukan kesalahan.
            $table->foreignId('exam_option_id')->nullable()->constrained('exam_options')->nullOnDelete();
            $table->text('answer_text')->nullable();

            $table->boolean('is_correct')->nullable();
            $table->decimal('points_earned', 5, 2)->nullable();

            $table->timestamps();

            $table->unique(['exam_attempt_id', 'exam_question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_answers');
    }
};
