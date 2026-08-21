<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\ReportCard;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\StudentVisibility;

/**
 * PRD 1.1.2 — Modul "Generate Rapor":
 * SUPER_ADMIN ✅, SCHOOL_ADMIN ✅, KEPALA ⭕, GURU/WALI ✅(Wali), BENDAHARA ❌, SISWA ⭕, ORTU ⭕.
 *
 * Sesuai keputusan D-8, GURU mata pelajaran (bukan wali kelas) tidak diberi
 * akses rapor: `RolePermissionSeeder` memang tidak memberi peran GURU izin
 * `report_card.*`, sejalan dengan deskripsi perannya di PRD 1.1.1.
 *
 * Pagar tambahan: generate & publish hanya untuk wali kelas dari kelas itu
 * sendiri (API 4.8 — "Wali Kelas only"; ERD: published_by = wali kelas).
 */
class ReportCardPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::ReportCardView->value);
    }

    public function view(User $user, ReportCard $reportCard): bool
    {
        return $user->can(PermissionName::ReportCardView->value)
            && $this->sharesTenant($user, $reportCard)
            // ORANG_TUA dan SISWA sama-sama memegang `report_card.view`
            // (matriks PRD 1.1.2: ORTU ⭕, SISWA ⭕), sehingga tanpa batasan
            // ini keduanya lolos untuk seluruh rapor cabangnya. Belum ada rute
            // yang membuka celah itu hari ini, tetapi rute pertama yang
            // melakukannya tidak boleh menemukan pagar yang bolong
            // (butir 170).
            && StudentVisibility::allows($user, $reportCard->student);
    }

    /**
     * Rapor lahir dari aksi generate per kelas, bukan dibuat satu per satu.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * NILAI-03 poin 2 — setelah publish, rapor terkunci.
     */
    public function update(User $user, ReportCard $reportCard): bool
    {
        return $user->can(PermissionName::ReportCardManage->value)
            && $this->sharesTenant($user, $reportCard)
            && ! $reportCard->is_published
            && $this->isHomeroomTeacherOfClass($user, (int) $reportCard->class_id);
    }

    public function delete(User $user, ReportCard $reportCard): bool
    {
        return false;
    }

    /**
     * API 4.8 — POST /report-cards/generate (Wali Kelas only).
     */
    public function generate(User $user, SchoolClass $class): bool
    {
        return $user->can(PermissionName::ReportCardManage->value)
            && $user->school_id === $class->school_id
            && $this->isHomeroomTeacher($user, $class);
    }

    /**
     * API 4.8 — POST /report-cards/{id}/publish.
     */
    public function publish(User $user, ReportCard $reportCard): bool
    {
        return $user->can(PermissionName::ReportCardManage->value)
            && $this->sharesTenant($user, $reportCard)
            && ! $reportCard->is_published
            && $this->isHomeroomTeacherOfClass($user, (int) $reportCard->class_id);
    }

    /**
     * NILAI-04 poin 3 — rapor dapat dicetak dalam format PDF.
     */
    public function downloadPdf(User $user, ReportCard $reportCard): bool
    {
        return $this->view($user, $reportCard);
    }

    protected function isHomeroomTeacherOfClass(User $user, int $classId): bool
    {
        if ($this->isSchoolAdministrator($user)) {
            return true;
        }

        return SchoolClass::query()
            ->whereKey($classId)
            ->where('homeroom_teacher_id', $user->getKey())
            ->exists();
    }

    protected function isHomeroomTeacher(User $user, SchoolClass $class): bool
    {
        return $this->isSchoolAdministrator($user)
            || (int) $class->homeroom_teacher_id === (int) $user->getKey();
    }

    protected function isSchoolAdministrator(User $user): bool
    {
        return $user->hasRole(RoleName::SchoolAdmin->value);
    }

    protected function sharesTenant(User $user, ReportCard $reportCard): bool
    {
        return $user->school_id !== null
            && $user->school_id === $reportCard->school_id;
    }
}
