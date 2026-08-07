<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERD 2.2 (Akademik) — Tabel: students.
 * Data induk siswa; user_id & parent_user_id opsional (akun portal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nis', 20)->index();
            $table->string('nisn', 10)->nullable()->index();
            $table->string('full_name', 150);
            $table->enum('gender', ['L', 'P']);
            $table->string('birth_place', 100)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('religion', 30)->nullable();
            $table->text('address')->nullable();
            $table->string('photo_url', 500)->nullable();
            $table->string('parent_name', 150)->nullable();
            $table->string('parent_phone', 20)->nullable()->index();
            $table->string('parent_email', 150)->nullable();
            $table->foreignId('parent_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->year('entry_year')->nullable();
            $table->enum('status', ['ACTIVE', 'GRADUATED', 'DROPPED_OUT', 'TRANSFERRED'])
                ->default('ACTIVE')
                ->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            // SIS-01 poin 3: "NIS unik dalam satu sekolah" (ERD: "lokal, unik per sekolah").
            $table->unique(['school_id', 'nis']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
