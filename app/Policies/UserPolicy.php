<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\User;

/**
 * PRD 1.1.2 — Modul "User Management": SUPER_ADMIN & SCHOOL_ADMIN akses penuh.
 *
 * Super Admin sudah dilewatkan lebih dulu oleh Gate::before di AppServiceProvider,
 * jadi policy ini efektif mengatur peran School Level.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::UserView->value);
    }

    public function view(User $user, User $model): bool
    {
        return $user->can(PermissionName::UserView->value)
            && $this->sharesTenant($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::UserManage->value);
    }

    public function update(User $user, User $model): bool
    {
        return $user->can(PermissionName::UserManage->value)
            && $this->sharesTenant($user, $model)
            && ! $model->isSuperAdmin();
    }

    /**
     * Penonaktifan user memakai soft deactivate (is_active = 0), bukan hard
     * delete — lihat API 4.4 DELETE /users/{id}.
     */
    public function delete(User $user, User $model): bool
    {
        return $user->id !== $model->id
            && $this->update($user, $model);
    }

    /**
     * Reset password user lain (API 4.4 POST /users/{id}/reset-password).
     */
    public function resetPassword(User $user, User $model): bool
    {
        return $this->update($user, $model);
    }

    /**
     * Pengguna School Level hanya boleh menyentuh user di cabangnya sendiri.
     */
    protected function sharesTenant(User $user, User $model): bool
    {
        return $user->school_id !== null
            && $user->school_id === $model->school_id;
    }
}
