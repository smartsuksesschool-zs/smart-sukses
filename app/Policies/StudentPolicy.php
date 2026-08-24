<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Student;
use App\Models\User;
use App\Support\StudentVisibility;

/**
 * PRD 1.1.2 — Modul "Data Siswa (SIS)":
 * SUPER_ADMIN ✅, SCHOOL_ADMIN ✅, KEPALA ⭕, GURU/WALI ⭕, BENDAHARA ⭕, SISWA ⭕, ORTU ⭕.
 *
 * Isolasi tenant ditangani global scope (App\Models\Scopes\SchoolScope); policy
 * ini menambahkan pagar kedua agar akses per-record juga diverifikasi.
 */
class StudentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::StudentView->value);
    }

    /**
     * Izin modul dan cabang saja tidak cukup untuk dua peran.
     *
     * SISWA dan ORANG_TUA sama-sama memegang `student.view` (matriks PRD 1.1.2
     * — Data Siswa: SISWA ⭕, ORTU ⭕), sehingga pemeriksaan izin + cabang
     * meloloskan keduanya ke **seluruh** siswa cabang itu. Batasan barisnya
     * karena itu ikut diperiksa di sini, memakai aturan yang sama dengan
     * tagihan dan rapor (butir 170, 188).
     */
    public function view(User $user, Student $student): bool
    {
        return $user->can(PermissionName::StudentView->value)
            && $this->sharesTenant($user, $student)
            && StudentVisibility::allows($user, $student);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::StudentManage->value);
    }

    public function update(User $user, Student $student): bool
    {
        return $user->can(PermissionName::StudentManage->value)
            && $this->sharesTenant($user, $student);
    }

    /**
     * SIS-02 poin 2: siswa dinonaktifkan (status), tidak dihapus dari database.
     */
    public function delete(User $user, Student $student): bool
    {
        return false;
    }

    /**
     * API 4.5 — PATCH /students/{id}/status.
     */
    public function changeStatus(User $user, Student $student): bool
    {
        return $this->update($user, $student);
    }

    /**
     * SIS-05 / API 4.5 — GET /students/export.
     */
    public function export(User $user): bool
    {
        return $user->can(PermissionName::StudentView->value);
    }

    /**
     * API 4.5 — POST /students/import (Auth Level: Admin).
     */
    public function import(User $user): bool
    {
        return $user->can(PermissionName::StudentManage->value);
    }

    protected function sharesTenant(User $user, Student $student): bool
    {
        return $user->school_id !== null
            && $user->school_id === $student->school_id;
    }
}
