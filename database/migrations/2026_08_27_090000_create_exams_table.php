<?php

use App\Enums\ExamStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DI LUAR ERD 2.2 — tabel ini tidak ada di blueprint Phase 1.
 *
 * CBT (ujian online) adalah fitur Phase 2 yang dipercepat atas permintaan
 * langsung pemilik; lihat docs/owner-scope-changes.md dan docs/cbt-mvp-scope.md.
 * Bentuknya mengikuti konvensi tabel akademik yang sudah ada — `school_id`
 * pertama, FK ke `class_subjects` dan `academic_years`, ENUM status, timestamps
 * penuh — supaya tidak ada pola kedua yang harus dipelihara (butir 263).
 *
 * Perilaku hapus (butir 269):
 *  - `class_subject_id` dan `academic_year_id` cascade, persis seperti
 *    `grades` dan `report_cards`: menghapus kelas-mapel di project ini memang
 *    sudah berarti menghapus penilaiannya, dan CBT tidak boleh punya aturan
 *    sendiri untuk hal yang sama;
 *  - `created_by` **nullOnDelete dan nullable**, mengikuti
 *    `report_cards.published_by` — bukan `grades.graded_by`. Satu baris nilai
 *    adalah satu angka milik gurunya; satu ujian adalah wadah berisi pekerjaan
 *    banyak siswa. Menghapus akun guru yang berhenti tidak boleh ikut
 *    menghapus hasil ujian mereka.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('class_subject_id')->constrained('class_subjects')->cascadeOnDelete();
            // Didenormalisasi seperti pada `grades` dan `report_cards`: tahun
            // ajaran dapat disimpulkan dari class_subject, tetapi seluruh tabel
            // akademik lain menyimpannya sendiri dan penyaringan "tahun ajaran
            // aktif" adalah bentuk query yang paling sering dipakai.
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();

            $table->string('title', 200);
            $table->text('description')->nullable();
            // Durasi pengerjaan dalam menit, ditentukan server. Batas smallint
            // (65.535) jauh di atas ujian mana pun.
            $table->unsignedSmallInteger('duration_minutes');
            $table->timestamp('available_from');
            $table->timestamp('available_until');

            $table->enum('status', ExamStatus::values())
                ->default(ExamStatus::Draft->value)
                ->index();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Bentuk query guru: ujian satu kelas-mapel menurut statusnya.
            $table->index(['school_id', 'class_subject_id', 'status']);
            // Bentuk query siswa: ujian cabang ini yang terbit, menurut jadwal.
            $table->index(['school_id', 'status', 'available_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exams');
    }
};
