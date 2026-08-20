<?php

use App\Enums\PaymentMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERD 2.2 (Keuangan) — Tabel: payments.
 * "Riwayat setiap transaksi pembayaran. Satu tagihan dapat memiliki beberapa
 * pembayaran (cicilan)."
 *
 * Batch 5.1 hanya membangun skemanya; pencatatan pembayaran (SPP-03) belum
 * diimplementasikan. Tidak ada kolom status maupun kaitan ke `transactions`:
 * ERD tidak memuat keduanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('student_fee_id')->constrained('student_fees')->cascadeOnDelete();
            // "denormalized untuk query cepat".
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->enum('payment_method', PaymentMethod::values());
            $table->decimal('amount_paid', 12, 2);
            $table->string('reference_number', 100)->nullable();
            $table->string('proof_url', 500)->nullable();
            $table->date('payment_date');
            // "bendahara yang mencatat" — NOT NULL menurut ERD, sehingga
            // nullOnDelete bukan pilihan. restrictOnDelete dipakai supaya
            // menghapus akun bendahara tidak ikut menghapus riwayat pembayaran
            // (akun memang dapat dihapus lewat UserResource). Lihat butir 49.
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
