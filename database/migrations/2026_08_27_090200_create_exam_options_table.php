<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DI LUAR ERD 2.2 — pilihan jawaban satu soal pilihan ganda.
 *
 * `is_correct` adalah kunci jawaban. Ia **tidak boleh** pernah ikut terkirim ke
 * peramban siswa sebelum pengerjaannya final: satu kolom boolean di dalam
 * payload halaman sudah cukup untuk membocorkan seluruh kunci. Penegakannya
 * ada di lapisan baca (batch berikutnya), tetapi catatannya berada di sini
 * karena kolom inilah yang menjadi taruhannya (butir 270).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('exam_question_id')->constrained('exam_questions')->cascadeOnDelete();

            $table->text('option_text');
            $table->boolean('is_correct')->default(false);
            $table->unsignedSmallInteger('position')->default(1);

            $table->timestamps();

            $table->index(['exam_question_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_options');
    }
};
