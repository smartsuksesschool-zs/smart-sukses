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
     * NOTIF-02 / API 4.10 — GET /notifications/{id}/wa-links.
     *
     * Pagarnya sama dengan sisi pengelolaan lainnya, dan itu keputusan yang
     * perlu disebut alasannya. NOTIF-02 berbunyi "Sebagai **Admin**" — lebih
     * umum, bukan lebih sempit, daripada NOTIF-01 yang menyebut "Admin
     * Sekolah" — dan API 4.10 memberi endpoint ini label Auth Level yang
     * **persis sama** dengan POST /notifications. Butir 201 sudah memutuskan
     * label generik itu tidak dipakai sebagai pagar, karena akan menutup Kepala
     * Sekolah yang justru diberi kewenangan penuh atas modul Notifikasi oleh
     * matriks 1.1.2. Memakai aturan berbeda di sini akan berarti Kepala boleh
     * menerbitkan pengumuman tetapi tidak boleh melihat daftar kirimnya
     * (butir 223).
     *
     * Yang tetap dijaga adalah cabangnya: daftar ini memuat nomor telepon, dan
     * `view()` sudah menuntut notifikasi itu milik cabang pelakunya.
     */
    public function waLinks(User $user, Notification $notification): bool
    {
        return $this->view($user, $notification);
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
