<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\StudentFee;
use App\Models\User;

/**
 * PRD 1.1.2 — modul "Tagihan SPP", izin yang sama dengan FeeTypePolicy:
 * SUPER_ADMIN ✅, SCHOOL_ADMIN ✅, KEPALA ⭕, GURU/WALI ❌, BENDAHARA ✅,
 * SISWA ❌, ORTU ⭕.
 *
 * `create` adalah kewenangan menerbitkan tagihan massal (SPP-02); tidak ada
 * jalur pembuatan satu-per-satu, karena user story-nya memang penerbitan
 * untuk seluruh siswa aktif sekaligus.
 */
class StudentFeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::FeeView->value);
    }

    public function view(User $user, StudentFee $studentFee): bool
    {
        return $user->can(PermissionName::FeeView->value)
            && $this->sharesTenant($user, $studentFee);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::FeeManage->value);
    }

    public function update(User $user, StudentFee $studentFee): bool
    {
        return $user->can(PermissionName::FeeManage->value)
            && $this->sharesTenant($user, $studentFee);
    }

    /**
     * API 4.9 tidak menyediakan DELETE /student-fees: tagihan yang salah terbit
     * dibebaskan (WAIVED), bukan dihapus. Aksi waive sendiri belum masuk batch
     * ini.
     */
    public function delete(User $user, StudentFee $studentFee): bool
    {
        return false;
    }

    protected function sharesTenant(User $user, StudentFee $studentFee): bool
    {
        return $user->school_id !== null
            && $user->school_id === $studentFee->school_id;
    }
}
