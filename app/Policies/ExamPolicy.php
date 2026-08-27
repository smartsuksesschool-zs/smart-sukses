<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\ClassSubject;
use App\Models\Exam;
use App\Models\User;

/**
 * Kewenangan atas ujian online (CBT).
 *
 * CBT tidak punya barisnya sendiri di matriks izin PRD 1.1.2 — fitur ini
 * dipercepat dari Phase 2 dan matriksnya ditulis untuk Phase 1. Yang dipakai
 * karena itu adalah izin modul terdekat yang **sudah ada dan sudah teruji**,
 * yaitu "Input Nilai" (`grade.view` / `grade.manage`): CBT adalah alat menilai,
 * dan pemetaannya jatuh persis pada peran yang sama —
 * SUPER_ADMIN ✅, SCHOOL_ADMIN ✅, KEPALA ⭕, GURU/WALI ✅, BENDAHARA ❌,
 * SISWA ❌, ORTU ❌ (butir 277).
 *
 * Yang dipakai bersama hanyalah **izinnya**. Policy ini tidak memanggil
 * GradePolicy dan tidak mewarisinya. Pelajaran Batch 8.4 masih berlaku: policy
 * yang meminjam kewenangan modul lain ikut hanyut setiap kali modul itu
 * bergeser, dan pergeserannya tidak terlihat dari sini (butir 223).
 *
 * Dua pagar di luar izin, keduanya sama seperti pada input nilai:
 *  - cabang — akun School Level hanya menyentuh cabangnya sendiri;
 *  - kelas yang diampu — `class_subjects.teacher_id`, bukan
 *    `classes.homeroom_teacher_id`. Menjadi wali kelas saja tidak memberi
 *    kewenangan membuat ujian; yang memberi adalah penugasan mengajar
 *    (butir 278).
 *
 * SUPER_ADMIN tidak disebut sama sekali di sini: `Gate::before` sudah
 * meloloskannya untuk seluruh ability (Arsitektur 3.2.2), dan menuliskannya
 * ulang hanya akan membuat dua sumber kebenaran.
 *
 * Kewenangan **siswa mengerjakan** ujian bukan urusan policy ini. Siswa tidak
 * punya izin `grade.*` mana pun dan ditolak seluruh method di bawah; jalur
 * pengerjaannya berjalan lewat identitas portal (StudentPortalService) dan
 * dibangun pada batch tersendiri.
 */
class ExamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::GradeView->value);
    }

    public function view(User $user, Exam $exam): bool
    {
        return $user->can(PermissionName::GradeView->value)
            && $this->sharesTenant($user, $exam);
    }

    /**
     * Kewenangan membuat, tanpa kelas tertentu — sama seperti
     * `GradePolicy::create()`. Pagar per kelas-mapel ada di `author()`, dan
     * jalur pembuatan wajib melewatinya: tanpa itu Guru dapat membuat ujian
     * untuk kelas yang tidak ia ampu.
     */
    public function create(User $user): bool
    {
        return $user->can(PermissionName::GradeManage->value);
    }

    public function update(User $user, Exam $exam): bool
    {
        return $user->can(PermissionName::GradeManage->value)
            && $this->sharesTenant($user, $exam)
            && $this->teachesClassSubject($user, (int) $exam->class_subject_id);
    }

    public function delete(User $user, Exam $exam): bool
    {
        return $this->update($user, $exam);
    }

    /**
     * Boleh membuat atau memindahkan ujian ke kelas-mata pelajaran ini.
     *
     * Cabangnya dibandingkan langsung di sini, tidak digantung pada global
     * scope: objek ClassSubject dapat saja sudah dimuat lewat jalur yang
     * melepas scope, dan pemeriksaan kewenangan tidak boleh bergantung pada
     * cara pemanggilnya mengambil baris itu.
     */
    public function author(User $user, ClassSubject $classSubject): bool
    {
        return $user->can(PermissionName::GradeManage->value)
            && $user->school_id !== null
            && (int) $user->school_id === (int) $classSubject->school_id
            && $this->teachesClassSubject($user, (int) $classSubject->getKey());
    }

    /**
     * Guru mata pelajaran dibatasi pada kelas yang ia ampu. Administrator
     * cabang tidak dibatasi — matriks 1.1.2 memberinya ✅ penuh pada Input
     * Nilai, dan CBT memakai kewenangan yang sama.
     */
    protected function teachesClassSubject(User $user, int $classSubjectId): bool
    {
        if ($user->hasRole(RoleName::SchoolAdmin->value)) {
            return true;
        }

        return ClassSubject::query()
            ->whereKey($classSubjectId)
            ->where('teacher_id', $user->getKey())
            ->exists();
    }

    /**
     * Akun tanpa cabang tidak berbagi cabang dengan siapa pun — gagal tertutup,
     * pola yang sama dengan seluruh policy lain di project ini (butir 127).
     */
    protected function sharesTenant(User $user, Exam $exam): bool
    {
        return $user->school_id !== null
            && (int) $user->school_id === (int) $exam->school_id;
    }
}
