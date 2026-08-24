<?php

namespace App\Services\Notification;

use App\Models\Notification;
use Illuminate\Support\Carbon;

/**
 * Bentuk aman sebuah notifikasi bagi penerimanya, di luar API.
 *
 * Dipakai dua tempat yang tidak boleh berbeda: ringkasan notifikasi pada dasbor
 * guru dan siswa (API 4.11), dan halaman Notifikasi ketiga portal. Tanpa satu
 * formatter, "notifikasi" akan punya dua bentuk yang perlahan menyimpang, dan
 * kolom yang tidak boleh keluar hanya perlu lolos sekali untuk bocor
 * (butir 207).
 *
 * Daftar-izinnya sama dengan NotificationResource — yang sengaja tidak ikut:
 * `school_id`, `target_type`/`target_id`, `wa_template`, `is_draft`, dan seluruh
 * isi pivot selain keadaan terbaca pengguna ini sendiri (butir 203).
 *
 * Blade karena itu tidak pernah menerima model Notification: ia menerima array
 * ini, sehingga tidak ada jalan bagi kolom yang tidak disebut di sini untuk
 * sampai ke halaman.
 */
class NotificationPresenter
{
    /**
     * Bentuk ringkas untuk dasbor: tanpa isi pesan.
     *
     * @return array{id: int, title: string, type: string|null, type_label: string|null, sent_at: string|null, is_read: bool}
     */
    public function summary(Notification $notification): array
    {
        return [
            'id' => (int) $notification->getKey(),
            'title' => (string) $notification->title,
            'type' => $notification->type?->value,
            'type_label' => $notification->type?->label(),
            'sent_at' => $notification->sent_at?->toIso8601String(),
            'is_read' => $notification->read_at !== null,
        ];
    }

    /**
     * Bentuk lengkap untuk halaman Notifikasi portal.
     *
     * `message` ikut karena halaman itulah tempat pesannya dibaca; ia tetap
     * dirender ter-escape oleh Blade, dan baris barunya diatur CSS — bukan
     * dengan menyisipkan markup (butir 210).
     *
     * @return array<string, mixed>
     */
    public function detail(Notification $notification): array
    {
        return $this->summary($notification) + [
            'message' => (string) $notification->message,
            // NULL berarti notifikasi sistem otomatis (NOTIF-03).
            'sender_name' => $notification->sender?->name,
            'sent_at_label' => $notification->sent_at?->translatedFormat('d M Y, H:i'),
            'read_at_label' => $notification->read_at === null
                ? null
                : Carbon::parse($notification->read_at)->translatedFormat('d M Y, H:i'),
        ];
    }

    /**
     * @param  iterable<int, Notification>  $notifications
     * @return array<int, array<string, mixed>>
     */
    public function summaries(iterable $notifications): array
    {
        $items = [];

        foreach ($notifications as $notification) {
            $items[] = $this->summary($notification);
        }

        return $items;
    }

    /**
     * @param  iterable<int, Notification>  $notifications
     * @return array<int, array<string, mixed>>
     */
    public function details(iterable $notifications): array
    {
        $items = [];

        foreach ($notifications as $notification) {
            $items[] = $this->detail($notification);
        }

        return $items;
    }
}
