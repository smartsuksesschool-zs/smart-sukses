<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\GradeConfig;
use App\Models\User;

/**
 * Kewenangan konfigurasi bobot — hasil verifikasi requirement K-2, yang
 * dijawab eksplisit oleh empat tempat di blueprint:
 *
 *  - NILAI-05: "Sebagai **Admin Sekolah**, saya dapat mengkonfigurasi ..."
 *  - NILAI-02: "... bobot komponen yang dikonfigurasi **Admin**"
 *  - API 4.8 : POST /grade-configs → Auth Level **Admin**
 *  - API 4.1 : "Auth Level: Admin = Wajib token + role SCHOOL_ADMIN / SUPER_ADMIN"
 *
 * Karena itu penulisan konfigurasi TIDAK digantung pada izin `grade.manage`:
 * izin itu juga dipegang GURU, sedangkan deskripsi peran GURU (PRD 1.1.1)
 * tidak memuat konfigurasi bobot. Membaca tetap terbuka bagi seluruh pengguna
 * terautentikasi, sesuai GET /grade-configs yang ber-Auth Level "Auth".
 */
class GradeConfigPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::GradeView->value);
    }

    public function view(User $user, GradeConfig $config): bool
    {
        return $user->can(PermissionName::GradeView->value)
            && $this->sharesTenant($user, $config);
    }

    public function create(User $user): bool
    {
        return $this->isSchoolAdministrator($user);
    }

    /**
     * Keputusan Sprint 4 butir 4 — hanya DRAFT yang bebas diedit; perubahan
     * kebijakan pada konfigurasi ACTIVE/LOCKED wajib lewat versi baru.
     */
    public function update(User $user, GradeConfig $config): bool
    {
        return $this->isSchoolAdministrator($user)
            && $this->sharesTenant($user, $config)
            && $config->isEditable();
    }

    /**
     * Konfigurasi tidak pernah dihapus — versinya menjadi jejak kebijakan.
     */
    public function delete(User $user, GradeConfig $config): bool
    {
        return false;
    }

    public function activate(User $user, GradeConfig $config): bool
    {
        return $this->isSchoolAdministrator($user)
            && $this->sharesTenant($user, $config)
            && $config->status->canBeActivated();
    }

    public function lock(User $user, GradeConfig $config): bool
    {
        return $this->isSchoolAdministrator($user)
            && $this->sharesTenant($user, $config)
            && $config->status->canBeLocked();
    }

    public function createVersion(User $user, GradeConfig $config): bool
    {
        return $this->isSchoolAdministrator($user)
            && $this->sharesTenant($user, $config)
            && ! $config->isEditable();
    }

    /**
     * Pengaturan skala predikat sikap (schools.attitude_scale) mengikuti
     * kewenangan yang sama — sama-sama kebijakan penilaian tingkat cabang.
     */
    public function configureAttitudeScale(User $user): bool
    {
        return $this->isSchoolAdministrator($user);
    }

    /**
     * "Auth Level: Admin" pada API 4.1 = SCHOOL_ADMIN / SUPER_ADMIN.
     * SUPER_ADMIN sudah lolos lebih dulu lewat Gate::before.
     */
    protected function isSchoolAdministrator(User $user): bool
    {
        return $user->hasRole(RoleName::SchoolAdmin->value);
    }

    protected function sharesTenant(User $user, GradeConfig $config): bool
    {
        return $user->school_id !== null
            && $user->school_id === $config->school_id;
    }
}
