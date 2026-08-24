<?php

use App\Enums\NotificationTargetType;
use App\Enums\NotificationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERD 2.2 (Komunikasi) — Tabel: notifications.
 * "Pengumuman dan notifikasi yang dibuat oleh Admin atau dipicu otomatis oleh
 * sistem."
 *
 * Kolomnya persis daftar ERD, tanpa tambahan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            // "NULL jika notifikasi sistem otomatis" (NOTIF-03). Akun pengirim
            // yang dihapus tidak boleh ikut menghapus pengumumannya —
            // riwayatnya tetap milik cabang — jadi nullOnDelete, pola yang
            // sama dengan kolom pengguna opsional lain di project ini.
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 200);
            $table->text('message');
            // ERD menandai kolom ini "IX": notifikasi selalu dibaca per
            // kategori pada daftar maupun filter API 4.10.
            $table->enum('type', NotificationType::values())->index();
            $table->enum('target_type', NotificationTargetType::values());
            // "class_id atau user_id jika bukan ALL". Sengaja **tanpa** foreign
            // key: satu kolom yang menunjuk dua tabel berbeda tidak dapat
            // dijaga FK, dan ERD pun tidak menandainya sebagai FK. Maknanya
            // divalidasi aplikasi (butir 194).
            $table->unsignedBigInteger('target_id')->nullable();
            $table->text('wa_template')->nullable();
            $table->boolean('is_draft')->default(true);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // Umpan penerima selalu menyaring "cabang ini, sudah terkirim,
            // terbaru dulu"; indeksnya mengikuti bentuk query itu.
            $table->index(['school_id', 'is_draft', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
