<?php

use App\Enums\StudentFeeStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERD 2.2 (Keuangan) — Tabel: student_fees.
 * "Tagihan per siswa per periode. Di-generate secara massal oleh Bendahara."
 *
 * Batch 5.1 hanya membangun skemanya; alur generate massal (SPP-02) dan
 * perubahan status dari pembayaran (SPP-03) belum diimplementasikan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('fee_type_id')->constrained('fee_types')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()
                ->constrained('academic_years')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->date('due_date');
            // "Periode: format YYYY-MM (misal: 2025-08 untuk Agustus 2025)".
            $table->string('period', 7)->index();
            $table->enum('status', StudentFeeStatus::values())
                ->default(StudentFeeStatus::Unpaid->value)
                ->index();
            $table->string('waive_reason', 200)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_fees');
    }
};
