<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\FeeType;
use App\Models\User;

/**
 * PRD 1.1.2 — modul "Tagihan SPP":
 * SUPER_ADMIN ✅, SCHOOL_ADMIN ✅, KEPALA ⭕, GURU/WALI ❌, BENDAHARA ✅,
 * SISWA ❌, ORTU ⭕.
 *
 * Izin `fee.view` / `fee.manage` sudah disediakan PermissionName dan
 * disebarkan RolePermissionSeeder persis mengikuti matriks itu, sehingga
 * policy ini cukup membacanya — tidak ada izin baru yang ditambahkan.
 * SUPER_ADMIN lolos lebih dulu lewat Gate::before (Arsitektur 3.2.2).
 */
class FeeTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::FeeView->value);
    }

    public function view(User $user, FeeType $feeType): bool
    {
        return $user->can(PermissionName::FeeView->value)
            && $this->sharesTenant($user, $feeType);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::FeeManage->value);
    }

    public function update(User $user, FeeType $feeType): bool
    {
        return $user->can(PermissionName::FeeManage->value)
            && $this->sharesTenant($user, $feeType);
    }

    /**
     * SPP-01 poin 2: "Jenis tagihan dapat dinonaktifkan tanpa menghapus
     * histori." Tagihan siswa yang sudah terbit menunjuk ke record ini, jadi
     * tidak ada jalur hapus sama sekali — penonaktifan lewat `is_active`.
     */
    public function delete(User $user, FeeType $feeType): bool
    {
        return false;
    }

    protected function sharesTenant(User $user, FeeType $feeType): bool
    {
        return $user->school_id !== null
            && $user->school_id === $feeType->school_id;
    }
}
