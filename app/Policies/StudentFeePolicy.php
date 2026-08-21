<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\StudentFee;
use App\Models\User;
use App\Support\StudentVisibility;

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

    /**
     * Izin modul dan cabang saja tidak cukup di sini.
     *
     * ORANG_TUA memegang `fee.view` menurut matriks PRD 1.1.2 (Tagihan SPP:
     * ORTU ⭕), sehingga pemeriksaan izin + cabang meloloskan mereka ke
     * **seluruh** tagihan cabang itu — termasuk tagihan anak orang lain.
     * Batasan barisnya karena itu ikut diperiksa di sini (butir 170).
     */
    public function view(User $user, StudentFee $studentFee): bool
    {
        return $user->can(PermissionName::FeeView->value)
            && $this->sharesTenant($user, $studentFee)
            && StudentVisibility::allows($user, $studentFee->student);
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
     * dibebaskan (WAIVED), bukan dihapus.
     */
    public function delete(User $user, StudentFee $studentFee): bool
    {
        return false;
    }

    /**
     * SPP-05 / API 4.9 — GET /student-fees/export.
     *
     * Digantung pada `financial_report.manage`, bukan `fee.view` maupun
     * `fee.manage`. Ekspor adalah pengambilan laporan keuangan ke luar sistem,
     * sehingga modul yang berlaku adalah "Laporan Keuangan" pada matriks 1.1.2
     * — SUPER_ADMIN ✅, SCHOOL_ADMIN ✅, KEPALA ⭕, BENDAHARA ✅.
     *
     * Konsekuensinya Kepala Sekolah dapat melihat daftar tagihan tetapi tidak
     * mengunduhnya: mereka hanya memegang `financial_report.view`. Itu memang
     * arti ⭕ pada baris tersebut, dan berkas yang keluar dari sistem adalah
     * hal yang layak dibatasi lebih ketat daripada tampilan di layar. Lihat
     * docs/implementation-notes.md butir 98.
     */
    public function export(User $user): bool
    {
        return $user->can(PermissionName::FinancialReportManage->value);
    }

    /**
     * API 4.9 — PATCH /student-fees/{id}/waive, Auth Level **Admin**, dan
     * API 4.1: "Auth Level: Admin = Wajib token + role SCHOOL_ADMIN /
     * SUPER_ADMIN".
     *
     * Kewenangannya karena itu **tidak** digantung pada `fee.manage`. Izin itu
     * juga dipegang BENDAHARA, dan tidak ada satu pun dokumen yang memberi
     * Bendahara wewenang membebaskan tagihan: PRD 1.2.6 tidak memuat user story
     * pembebasan sama sekali, sehingga tidak ada yang menimpa label "Admin" di
     * API — berbeda dengan SPP-01/02/03 yang masing-masing menyebut Bendahara
     * secara eksplisit. Polanya sama dengan GradeConfigPolicy, yang menolak
     * menggantungkan konfigurasi bobot pada `grade.manage` karena izin itu juga
     * dipegang Guru.
     *
     * Membebaskan tagihan adalah keputusan menghapus penerimaan, bukan mencatat
     * penerimaan; membatasinya ke Admin Sekolah juga pilihan yang lebih aman.
     * Lihat docs/implementation-notes.md butir 67.
     *
     * Keadaan tagihannya sendiri (sudah WAIVED, sudah ada pembayaran) bukan
     * urusan policy melainkan StudentFeeWaiver — supaya "tidak berwenang" dan
     * "keadaan tidak memungkinkan" tidak tertukar menjadi satu pesan.
     */
    public function waive(User $user, StudentFee $studentFee): bool
    {
        return $this->isSchoolAdministrator($user)
            && $this->sharesTenant($user, $studentFee);
    }

    /**
     * SUPER_ADMIN sudah lolos lebih dulu lewat Gate::before.
     */
    protected function isSchoolAdministrator(User $user): bool
    {
        return $user->hasRole(RoleName::SchoolAdmin->value);
    }

    protected function sharesTenant(User $user, StudentFee $studentFee): bool
    {
        return $user->school_id !== null
            && $user->school_id === $studentFee->school_id;
    }
}
