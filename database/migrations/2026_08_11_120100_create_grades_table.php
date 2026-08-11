<?php

use App\Enums\AssessmentType;
use App\Enums\GradeType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERD 2.2 (Penilaian) — Tabel: grades.
 * Nilai siswa per komponen penilaian per mata pelajaran.
 *
 * Dua kolom berada DI LUAR ERD, atas keputusan Sprint 4:
 *  - `assessment_type` (butir 3: data harus membedakan formative & summative)
 *  - `grade_config_id` (butir 2: `weight` adalah snapshot, sumbernya harus terlacak)
 *
 * Lihat docs/implementation-notes.md butir 25 dan 26.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('class_subject_id')->constrained('class_subjects')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->enum('grade_type', GradeType::values())->index();
            // Nilai dalam skala 0.00 – 100.00 (NILAI-01 poin 1).
            $table->decimal('score', 5, 2);
            // Bobot komponen — SNAPSHOT dari grade_configs saat nilai dibuat.
            $table->decimal('weight', 4, 2)->nullable();
            $table->string('description', 200)->nullable();
            $table->foreignId('graded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('graded_at');

            // --- Di luar ERD (keputusan Sprint 4 butir 2 & 3) ---
            $table->enum('assessment_type', AssessmentType::values())
                ->default(AssessmentType::Summative->value)
                ->index();
            $table->foreignId('grade_config_id')->nullable()
                ->constrained('grade_configs')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grades');
    }
};
