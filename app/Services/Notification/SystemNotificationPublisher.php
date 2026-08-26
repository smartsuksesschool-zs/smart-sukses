<?php

namespace App\Services\Notification;

use App\Enums\NotificationTargetType;
use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;

/**
 * NOTIF-03 — "Sebagai Sistem, notifikasi trigger otomatis tersedia untuk event
 * tertentu."
 *
 * Satu-satunya jalur tulis untuk notifikasi yang **tidak** punya penulis
 * manusia. AnnouncementPublisher tidak dipakai untuk ini dan sebaliknya:
 * pengumuman manual selalu punya pengirim dan wajib melewati pemeriksaan
 * kewenangan pembuatnya, sedangkan notifikasi otomatis tidak punya siapa pun
 * untuk diperiksa. Memaksa keduanya lewat satu kelas berarti salah satu
 * pemeriksaan harus dilonggarkan (butir 235).
 *
 * `sender_id` NULL adalah penanda asal-sistem, persis seperti yang ERD
 * sebutkan — "NULL jika notifikasi sistem otomatis". Tidak ada akun palsu yang
 * dibuat untuk menjadi pengirim.
 *
 * Kelas ini **tidak** membuka transaksinya sendiri. Batas transaksi milik
 * kejadian bisnisnya: notifikasi harus hidup dan mati bersama tagihan atau
 * rapor yang memicunya, bukan bersama dirinya sendiri (butir 244).
 */
class SystemNotificationPublisher
{
    /** Batas kolom `notifications.title`. */
    protected const TITLE_LIMIT = 200;

    /**
     * Menerbitkan satu notifikasi sistem kepada seorang pengguna.
     *
     * Mengembalikan NULL — dan **tidak** menulis apa pun — bila tidak ada
     * penerima yang sah. Itu keadaan yang wajar, bukan kegagalan: sebagian
     * kejadian otomatis memang menyangkut orang yang tidak punya akun portal
     * sama sekali (butir 240). Yang tidak boleh terjadi adalah menambal
     * ketiadaan itu dengan target karangan.
     */
    public function toUser(
        ?User $recipient,
        int $schoolId,
        NotificationType $type,
        string $title,
        string $message,
        string $waMessage,
    ): ?Notification {
        if (! $this->isLegitimate($recipient, $schoolId)) {
            return null;
        }

        $title = trim($title);
        $message = trim($message);

        // Judul dan isi tidak pernah kosong; pemanggilnya memakai teks tetap,
        // jadi keadaan ini hanya mungkin muncul karena salah rakit di kode.
        if ($title === '' || $message === '') {
            return null;
        }

        $waMessage = trim($waMessage);

        return Notification::query()->create([
            'school_id' => $schoolId,
            // ERD 2.2 — "NULL jika notifikasi sistem otomatis".
            'sender_id' => null,
            'title' => mb_substr($title, 0, self::TITLE_LIMIT),
            'message' => $message,
            'type' => $type->value,
            // Penerimanya satu akun nyata, dan `target_id` tetap berarti
            // `users.id` seperti yang dijanjikan skema. Tidak pernah id
            // pendaftaran PPDB, tidak pernah id siswa (butir 240).
            'target_type' => NotificationTargetType::Individual->value,
            'target_id' => $recipient->getKey(),
            // Snapshot teks WA pada saat kejadian. Template cabang yang diedit
            // sesudah ini tidak mengubah pesan yang sudah terbit (butir 241).
            'wa_template' => $waMessage !== '' ? $waMessage : null,
            'is_draft' => false,
            'sent_at' => now(),
        ]);
    }

    /**
     * Penerima yang sah: akun nyata, aktif, dan **di cabang kejadiannya**.
     *
     * Cabang diperiksa di sini dan bukan dipercayakan kepada pemanggil, karena
     * inilah satu-satunya tempat seluruh jalur otomatis bertemu. `parent_user_id`
     * lintas cabang adalah data rusak, dan notifikasi tidak boleh menjadi cara
     * data rusak itu terbaca orang lain (butir 243).
     */
    protected function isLegitimate(?User $recipient, int $schoolId): bool
    {
        return $recipient !== null
            && $recipient->exists
            && $recipient->school_id !== null
            && (int) $recipient->school_id === $schoolId
            && (bool) $recipient->is_active;
    }
}
