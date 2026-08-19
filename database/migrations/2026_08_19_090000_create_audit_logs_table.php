<?php

use App\Enums\AuditAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel di luar 21 entitas ERD, diwajibkan dua dokumen sekaligus:
 *
 *   `01-prd/04-non-functional-requirements.md` — Audit: *"Semua aksi CRUD
 *   dicatat di tabel audit_logs dengan user & timestamp"*;
 *   `03-architecture/04-security.md` — Audit Log: *"Semua aksi CUD (Create,
 *   Update, Delete) dicatat: user, action, table, id, timestamp, IP"*.
 *
 * Kolomnya persis daftar Security 3.4 ditambah `school_id` untuk isolasi tenant.
 * Tidak ada kolom `changes`: kedua dokumen tidak memintanya, dan menambahkannya
 * berarti menyimpan salinan setiap perubahan data — termasuk data pribadi siswa
 * — tanpa dasar requirement. Lihat docs/implementation-notes.md butir 45.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // NULL sah: aksi platform oleh Super Admin (mis. membuat cabang)
            // tidak berada di dalam cabang mana pun.
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();

            // NULL sah: job antrean dan perintah CLI berjalan tanpa pengguna.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('action', AuditAction::values());

            // "table, id" pada Security 3.4. Disimpan sebagai morph supaya baris
            // audit dapat dirunut kembali ke recordnya, dan nama tabel tetap
            // dapat diturunkan dari kelasnya.
            $table->string('auditable_type', 255);
            $table->unsignedBigInteger('auditable_id');

            // NULL untuk CLI/queue yang tidak punya request.
            $table->string('ip_address', 45)->nullable();

            // Sengaja tanpa `updated_at`: baris audit tidak pernah berubah.
            // Preseden tabel tanpa updated_at ada di Sprint 2 (butir 8).
            $table->timestamp('created_at')->nullable();

            $table->index(['school_id', 'created_at']);
            $table->index(['auditable_type', 'auditable_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
