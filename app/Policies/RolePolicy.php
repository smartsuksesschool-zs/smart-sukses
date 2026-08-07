<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Definisi peran & izin bersifat platform-wide (PRD 1.1.1), sehingga hanya
 * Super Admin yang boleh mengubahnya. Peran School Level cukup diberi akses
 * baca agar dapat memilih peran saat mengelola user.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::UserView->value);
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can(PermissionName::UserView->value);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, Role $role): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->isSuperAdmin();
    }
}
