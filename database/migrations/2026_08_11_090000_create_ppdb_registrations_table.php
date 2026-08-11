<?php

use App\Enums\PpdbStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERD 2.2 (PPDB) — Tabel: ppdb_registrations.
 * Data pendaftar PPDB; dapat diisi tanpa login (public form).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppdb_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            // Nomor pendaftaran unik: [KODE_CABANG]-[TAHUN]-[SEQ].
            $table->string('reg_number', 20)->unique();
            $table->string('full_name', 150);
            $table->enum('gender', ['L', 'P']);
            $table->date('birth_date')->nullable();
            $table->string('origin_school', 150)->nullable();
            $table->string('parent_name', 150)->nullable();
            $table->string('parent_phone', 20)->nullable();
            $table->string('parent_email', 150)->nullable();
            // Path file dokumen yang diupload (array URL).
            $table->json('documents')->nullable();
            $table->enum('status', PpdbStatus::values())
                ->default(PpdbStatus::Registered->value)
                ->index();
            $table->text('status_notes')->nullable();
            $table->foreignId('converted_student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->timestamp('registered_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppdb_registrations');
    }
};
