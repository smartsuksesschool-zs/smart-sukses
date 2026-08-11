<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\ClassSubject;
use App\Models\Grade;
use App\Models\User;

/**
 * PRD 1.1.2 — Modul "Input Nilai":
 * SUPER_ADMIN ✅, SCHOOL_ADMIN ✅, KEPALA ⭕, GURU/WALI ✅, BENDAHARA ❌, SISWA ❌, ORTU ❌.
 *
 * Dua pagar tambahan di luar izin:
 *  - API 4.8: "Guru hanya bisa input nilai untuk kelas yang dia ampu"
 *    (`class_subjects.teacher_id`);
 *  - NILAI-01 poin 3: nilai hanya dapat diedit selama rapor belum diterbitkan.
 */
class GradePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::GradeView->value);
    }

    public function view(User $user, Grade $grade): bool
    {
        return $user->can(PermissionName::GradeView->value)
            && $this->sharesTenant($user, $grade);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::GradeManage->value);
    }

    public function update(User $user, Grade $grade): bool
    {
        return $user->can(PermissionName::GradeManage->value)
            && $this->sharesTenant($user, $grade)
            && $this->teachesClassSubject($user, (int) $grade->class_subject_id)
            && ! $grade->isLocked();
    }

    public function delete(User $user, Grade $grade): bool
    {
        return $this->update($user, $grade);
    }

    /**
     * API 4.8 — POST /grades/bulk dan POST /grades/import.
     */
    public function import(User $user): bool
    {
        return $user->can(PermissionName::GradeManage->value);
    }

    /**
     * Guru mata pelajaran dibatasi pada kelas yang dia ampu; administrator
     * cabang (matriks ✅ penuh) tidak dibatasi.
     */
    public function gradeClassSubject(User $user, ClassSubject $classSubject): bool
    {
        return $user->can(PermissionName::GradeManage->value)
            && $user->school_id === $classSubject->school_id
            && $this->teachesClassSubject($user, (int) $classSubject->getKey());
    }

    protected function teachesClassSubject(User $user, int $classSubjectId): bool
    {
        if ($this->isSchoolAdministrator($user)) {
            return true;
        }

        return ClassSubject::query()
            ->whereKey($classSubjectId)
            ->where('teacher_id', $user->getKey())
            ->exists();
    }

    protected function isSchoolAdministrator(User $user): bool
    {
        return $user->hasRole(RoleName::SchoolAdmin->value);
    }

    protected function sharesTenant(User $user, Grade $grade): bool
    {
        return $user->school_id !== null
            && $user->school_id === $grade->school_id;
    }
}
