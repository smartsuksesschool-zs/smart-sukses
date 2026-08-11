<?php

use App\Enums\AttitudePredicate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERD 2.2 (Penilaian) — Tabel: report_cards.
 * Rapor final per siswa per semester. Setelah published, data terkunci.
 *
 * Tabel ini mengikuti ERD TANPA kolom tambahan: `attitude_score` ENUM(A,B,C,D)
 * sudah sesuai keputusan Sprint 4 butir 3 (sikap sebagai predikat terpisah),
 * dan ketertelusuran konfigurasi diperoleh lewat `grades.grade_config_id`.
 *
 * Unique index (student_id, academic_year_id) tidak tercantum ERD; ditambahkan
 * mengikuti deskripsi "Rapor final per siswa per semester" dan preseden
 * Sprint 2 butir 6. Lihat docs/implementation-notes.md butir 29.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            // Nilai akhir per mapel: {"MTK": 87.5, "BIN": 90, ...} — kunci = subjects.code.
            $table->json('final_scores');
            $table->enum('attitude_score', AttitudePredicate::values())->nullable();
            $table->smallInteger('attend_present')->nullable();
            $table->smallInteger('attend_sick')->nullable();
            $table->smallInteger('attend_permission')->nullable();
            $table->smallInteger('attend_absent')->nullable();
            $table->smallInteger('rank_in_class')->nullable();
            $table->text('homeroom_notes')->nullable();
            $table->boolean('is_published')->default(false)->index();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['student_id', 'academic_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_cards');
    }
};
