<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\User;

/**
 * Matriks PRD 1.1.2 tidak memiliki baris untuk Audit Log, dan tidak ada izin
 * `audit.*` di `App\Enums\PermissionName`. Membuat izin baru akan melanggar pola
 * yang dipegang sejak Sprint 2 (butir 7 & 18: "tidak ada izin baru yang
 * dibuat"), jadi kewenangannya menumpang izin **paling ketat** yang sudah ada:
 * `tenant.view`, yang pada matriks hanya dimiliki SUPER_ADMIN.
 *
 * Jejak audit lintas cabang memang hanya layak dibaca peran Platform Level.
 * Bila client kelak menghendaki Admin Sekolah membaca jejak cabangnya sendiri,
 * yang perlu ditambah adalah baris matriks — bukan kodenya. Lihat butir 45.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::TenantView->value);
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $user->can(PermissionName::TenantView->value);
    }

    /**
     * Baris audit hanya lahir dari aksi yang diaudit, tidak pernah dari tangan
     * manusia. Ketiganya ditutup permanen — termasuk bagi Super Admin, yang
     * untuk itu Gate::before-nya sengaja tidak diandalkan: aksinya juga tidak
     * pernah dirender di UI (lihat AuditLogResource).
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditLog $auditLog): bool
    {
        return false;
    }

    public function delete(User $user, AuditLog $auditLog): bool
    {
        return false;
    }
}
