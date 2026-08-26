<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Enums\RoleName;
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
     * Aturannya **sengaja tidak** meneruskan ke `view()`, dan itu inti butir
     * 223. Membuat pengumuman dan membuka daftar nomor penerimanya adalah dua
     * kewenangan berbeda, jadi keduanya dipagari terpisah:
     *
     *  - NOTIF-01 memetakan pembuatan kepada Admin Sekolah **dan** Kepala
     *    Sekolah, dan butir 201 memang meluluskan Kepala di sana.
     *  - NOTIF-02 memetakan daftar wa.me hanya kepada **Admin Sekolah**, dan
     *    API 4.1 mendefinisikan Auth Level "Admin" sebagai SCHOOL_ADMIN /
     *    SUPER_ADMIN.
     *
     * Pengecualian butir 201 karena itu berhenti di NOTIF-01. Meneruskannya ke
     * sini akan melebarkan kewenangan lewat kemiripan label, bukan lewat
     * sumber — dan yang dilebarkan adalah akses ke nomor telepon **seluruh**
     * penerima, termasuk orang yang tidak pernah memakai aplikasi ini. Kalau
     * dua pembacaan sama-sama mungkin, yang lebih sempit yang dipilih.
     *
     * `notification.manage` tidak dipakai di sini justru karena izin itu
     * dipegang Kepala Sekolah juga. Yang menentukan perannya, bukan izin
     * pembuatannya.
     */
    public function waLinks(User $user, Notification $notification): bool
    {
        // Ditulis eksplisit meski `Gate::before` sudah meluluskan Super Admin:
        // pagar ini harus terbaca utuh dari satu tempat. Cabangnya tetap aman
        // karena yang diselesaikan lebih dulu adalah **satu** notifikasi, dan
        // penerimanya hanya penerima notifikasi itu (butir 227).
        if ($user->isSuperAdmin()) {
            return true;
        }

        if (! $user->hasRole(RoleName::SchoolAdmin->value)) {
            return false;
        }

        return $this->sharesTenant($user, $notification);
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
