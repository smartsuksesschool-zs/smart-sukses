<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Notification;
use App\Models\User;

/**
 * PRD 1.1.2 — modul "Notifikasi (buat)": SUPER_ADMIN ✅, SCHOOL_ADMIN ✅,
 * KEPALA ✅, GURU/WALI ❌, BENDAHARA ❌, SISWA ❌, ORTU ❌.
 *
 * Izin `notification.view` / `notification.manage` sudah disediakan
 * PermissionName dan dibagikan RolePermissionSeeder persis mengikuti baris itu,
 * sehingga tidak ada izin baru yang perlu dibuat untuk Sprint 8.
 *
 * API 4.10 memberi POST /notifications label Auth Level "Admin", yang menurut
 * API 4.1 berarti SCHOOL_ADMIN/SUPER_ADMIN saja. Label itu **tidak** dipakai
 * sebagai pagar, karena akan menutup Kepala Sekolah yang justru diberi
 * kewenangan oleh NOTIF-01 dan matriks — kewenangan fungsional yang spesifik
 * menang atas label generik (butir 201).
 *
 * Policy ini mengatur sisi **pengelolaan**. Sisi penerima tidak diatur izin
 * sama sekali: yang menentukan seseorang boleh membaca sebuah notifikasi
 * adalah apakah notifikasi itu memang ditujukan kepadanya (butir 203).
 */
class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::NotificationManage->value);
    }

    public function view(User $user, Notification $notification): bool
    {
        return $user->can(PermissionName::NotificationManage->value)
            && $this->sharesTenant($user, $notification);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::NotificationManage->value);
    }

    /**
     * Hanya draf yang dapat diubah.
     *
     * Blueprint tidak menyebutkan pengeditan pengumuman sama sekali. Yang
     * dipilih adalah yang menjaga jejak: begitu terkirim, penerimanya sudah
     * membacanya, dan mengubah isinya sesudah itu membuat riwayat tidak lagi
     * menggambarkan apa yang benar-benar dikirim (butir 195).
     */
    public function update(User $user, Notification $notification): bool
    {
        return $user->can(PermissionName::NotificationManage->value)
            && $this->sharesTenant($user, $notification)
            && ! $notification->isSent();
    }

    /**
     * Tidak ada penghapusan.
     *
     * API 4.10 tidak menyediakan DELETE, dan riwayat pengumuman justru yang
     * diminta disimpan (NOTIF-04 poin 3). Menambahkannya berarti mengarang
     * kemampuan yang tidak diminta siapa pun.
     */
    public function delete(User $user, Notification $notification): bool
    {
        return false;
    }

    protected function sharesTenant(User $user, Notification $notification): bool
    {
        return $user->school_id !== null
            && (int) $user->school_id === (int) $notification->school_id;
    }
}
