<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\PpdbRegistration;
use App\Models\User;

/**
 * PRD 1.1.2 — Modul "PPDB Online":
 * SUPER_ADMIN ✅, SCHOOL_ADMIN ✅, KEPALA ⭕, GURU/WALI ❌, BENDAHARA ❌, SISWA ❌, ORTU ❌.
 *
 * Isolasi tenant ditangani global scope (App\Models\Scopes\SchoolScope); policy
 * ini menambahkan pagar kedua agar akses per-record juga diverifikasi.
 */
class PpdbRegistrationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::PpdbView->value);
    }

    public function view(User $user, PpdbRegistration $registration): bool
    {
        return $user->can(PermissionName::PpdbView->value)
            && $this->sharesTenant($user, $registration);
    }

    /**
     * Pendaftaran dibuat lewat formulir publik (PPDB-01), bukan dari panel.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PpdbRegistration $registration): bool
    {
        return $user->can(PermissionName::PpdbManage->value)
            && $this->sharesTenant($user, $registration);
    }

    /**
     * API 4.7 tidak menyediakan endpoint hapus; jejak pendaftar dipertahankan.
     */
    public function delete(User $user, PpdbRegistration $registration): bool
    {
        return false;
    }

    /**
     * PPDB-03 poin 3 / API 4.7 — PATCH /admin/ppdb/{id}/status.
     */
    public function changeStatus(User $user, PpdbRegistration $registration): bool
    {
        return $this->update($user, $registration);
    }

    /**
     * PPDB-04 / API 4.7 — GET /admin/ppdb/{id}/wa-link (Auth Level: Admin).
     */
    public function generateWaLink(User $user, PpdbRegistration $registration): bool
    {
        return $this->update($user, $registration);
    }

    /**
     * PPDB-05 / API 4.7 — POST /admin/ppdb/{id}/enroll.
     *
     * Aksi ini membuat record `students`, sehingga selain izin PPDB juga
     * menuntut izin kelola Data Siswa (SIS) pada matriks 1.1.2.
     */
    public function enroll(User $user, PpdbRegistration $registration): bool
    {
        return $this->update($user, $registration)
            && $user->can(PermissionName::StudentManage->value)
            && $registration->isEnrollable();
    }

    protected function sharesTenant(User $user, PpdbRegistration $registration): bool
    {
        return $user->school_id !== null
            && $user->school_id === $registration->school_id;
    }
}
