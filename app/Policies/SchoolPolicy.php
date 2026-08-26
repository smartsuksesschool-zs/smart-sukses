<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\School;
use App\Models\User;

/**
 * PRD 1.1.2 — dua baris matriks yang berbeda bertemu di model yang sama:
 *
 *   "Manajemen Tenant/Cabang" → hanya SUPER_ADMIN (✅), peran lain ❌;
 *   "White-label Settings"    → SUPER_ADMIN ✅ dan SCHOOL_ADMIN ✅.
 *
 * Karena itu izin `tenant.*` mengatur CRUD cabang, sedangkan `white_label.*`
 * mengatur penyuntingan logo & warna. Admin Sekolah tidak dapat membuka daftar
 * cabang sama sekali, tetapi tetap dapat menyetel tampilan cabangnya sendiri.
 *
 * Super Admin sudah dilewatkan lebih dulu oleh Gate::before di
 * AppServiceProvider, jadi policy ini efektif mengatur peran School Level.
 */
class SchoolPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::TenantView->value);
    }

    public function view(User $user, School $school): bool
    {
        return $user->can(PermissionName::TenantView->value)
            && $this->isOwnSchool($user, $school);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::TenantManage->value);
    }

    public function update(User $user, School $school): bool
    {
        return $user->can(PermissionName::TenantManage->value)
            && $this->isOwnSchool($user, $school);
    }

    /**
     * Cabang tidak pernah dihapus — API 4.3 hanya mengenal
     * PATCH /admin/schools/{id}/toggle (aktifkan/nonaktifkan). Menghapus tenant
     * akan memutus seluruh data akademik, keuangan, dan rapor yang menggantung
     * padanya.
     */
    public function delete(User $user, School $school): bool
    {
        return false;
    }

    /**
     * Aktifkan / nonaktifkan cabang (API 4.3 — PATCH /admin/schools/{id}/toggle).
     */
    public function toggleActive(User $user, School $school): bool
    {
        return $this->update($user, $school);
    }

    /**
     * AUTH-03 poin 3 — "Perubahan white-label oleh Admin Sekolah langsung
     * berlaku tanpa deployment ulang." Izin ini terpisah dari `tenant.manage`
     * supaya Admin Sekolah dapat menyetel tampilan cabangnya tanpa ikut
     * memperoleh kewenangan mengelola cabang lain.
     */
    public function configureBranding(User $user, School $school): bool
    {
        return $user->can(PermissionName::WhiteLabelManage->value)
            && $this->isOwnSchool($user, $school);
    }

    /**
     * NOTIF-03 poin 2 — "Template teks notifikasi dapat diedit oleh **Admin
     * Sekolah**."
     *
     * Perannya diperiksa langsung, bukan lewat izin, dan alasannya sama dengan
     * butir 223. `notification.manage` juga dipegang Kepala Sekolah, sehingga
     * memakainya akan memberi Kepala kewenangan yang tidak disebut sumber mana
     * pun. `white_label.manage` kebetulan dipegang persis SUPER_ADMIN dan
     * SCHOOL_ADMIN, tetapi template WhatsApp bukan pengaturan white-label —
     * meminjam izinnya berarti dua hal berbeda menjadi tidak dapat dipisahkan
     * bila kelak salah satunya berubah (butir 249).
     */
    public function configureWaTemplates(User $user, School $school): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->hasRole(RoleName::SchoolAdmin->value)
            && $this->isOwnSchool($user, $school);
    }

    /**
     * Pengguna School Level hanya boleh menyentuh cabangnya sendiri.
     *
     * Super Admin tidak pernah sampai ke sini (Gate::before), sehingga NULL
     * `school_id` di sini selalu berarti pengguna tanpa cabang yang juga bukan
     * Super Admin — dan mereka tidak boleh menyentuh cabang mana pun.
     */
    protected function isOwnSchool(User $user, School $school): bool
    {
        return $user->school_id !== null
            && $user->school_id === $school->getKey();
    }
}
