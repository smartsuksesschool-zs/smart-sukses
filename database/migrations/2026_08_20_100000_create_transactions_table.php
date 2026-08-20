<?php

use App\Enums\TransactionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERD 2.2 (Keuangan) — Tabel: transactions.
 * "Buku kas sekolah. Mencatat semua pemasukan dan pengeluaran umum di luar
 * tagihan SPP."
 *
 * Tabel ini hanya memiliki `created_at`, persis seperti `payments`: perubahan
 * sesudahnya terlacak lewat `audit_logs`, bukan lewat kolom pada barisnya
 * sendiri.
 *
 * Tidak ada `deleted_at`. API 4.9 menyebut DELETE /transactions/{id} sebagai
 * "soft delete", tetapi ERD tidak memuat satu pun kolom yang dapat
 * menyimpannya — tidak `deleted_at`, tidak status, tidak flag aktif — dan tidak
 * ada bagian blueprint yang menjelaskan mekanismenya. Menambahkan kolom untuk
 * itu berarti mengarang skema, jadi penghapusan tidak diimplementasikan sama
 * sekali pada batch ini. Lihat docs/implementation-notes.md butir 74.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            // ERD menandai kolom ini "IX" — buku kas selalu dibaca per jenis.
            $table->enum('type', TransactionType::values())->index();
            $table->string('category', 100);
            $table->decimal('amount', 12, 2);
            $table->text('description')->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->string('proof_url', 500)->nullable();
            $table->date('transaction_date');
            // Pola sama dengan `payments.received_by` (butir 49): kolomnya NOT
            // NULL menurut ERD sehingga nullOnDelete bukan pilihan, dan
            // menghapus akun tidak boleh ikut menghapus buku kas.
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
