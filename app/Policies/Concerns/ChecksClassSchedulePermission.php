<?php

namespace App\Policies\Concerns;

use App\Enums\PermissionName;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * PRD 1.1.2 — Modul "Kelas & Jadwal":
 * SUPER_ADMIN ✅, SCHOOL_ADMIN ✅, KEPALA ⭕, GURU/WALI ⭕, BENDAHARA ❌, SISWA ⭕, ORTU ❌.
 *
 * Matriks izin memperlakukan Tahun Ajaran, Kelas, Mata Pelajaran, penugasan
 * guru, dan Jadwal sebagai satu modul, sehingga seluruh entitas tersebut
 * memakai pasangan izin `class_schedule.view` / `class_schedule.manage`.
 * Tidak ada izin baru yang ditambahkan di luar matriks.
 */
trait ChecksClassSchedulePermission
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::ClassScheduleView->value);
    }

    public function view(User $user, Model $record): bool
    {
        return $user->can(PermissionName::ClassScheduleView->value)
            && $this->sharesTenant($user, $record);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::ClassScheduleManage->value);
    }

    public function update(User $user, Model $record): bool
    {
        return $user->can(PermissionName::ClassScheduleManage->value)
            && $this->sharesTenant($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->update($user, $record);
    }

    protected function sharesTenant(User $user, Model $record): bool
    {
        return $user->school_id !== null
            && $user->school_id === $record->school_id;
    }
}
