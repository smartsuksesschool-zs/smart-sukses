<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Transaction;
use App\Models\User;

/**
 * PRD 1.1.2 — modul "Akuntansi & Kas": SUPER_ADMIN ✅, SCHOOL_ADMIN ✅,
 * KEPALA ⭕, GURU/WALI ❌, BENDAHARA ✅, SISWA ❌, ORTU ❌.
 *
 * Izin `accounting.view` / `accounting.manage` sudah disediakan PermissionName
 * dan dibagikan RolePermissionSeeder persis mengikuti baris itu, sehingga tidak
 * ada izin baru yang perlu dibuat.
 *
 * API 4.9 memberi POST/PUT /transactions label Auth Level "Admin", tetapi
 * KAS-01 menyebut pelakunya secara eksplisit — "Sebagai **Bendahara**, saya
 * dapat mencatat pemasukan dan pengeluaran kas sekolah" — dan user story adalah
 * sumber yang lebih spesifik. Ini kebalikan dari pembebasan tagihan (butir 67),
 * yang tidak punya user story sehingga label "Admin"-nya tidak tertimpa apa
 * pun.
 */
class TransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::AccountingView->value);
    }

    public function view(User $user, Transaction $transaction): bool
    {
        return $user->can(PermissionName::AccountingView->value)
            && $this->sharesTenant($user, $transaction);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::AccountingManage->value);
    }

    /**
     * API 4.9 — PUT /transactions/{id}. Koreksi dilakukan di tempat; jejak
     * perubahannya ada di `audit_logs`, karena `transactions` tidak punya
     * `updated_at`.
     */
    public function update(User $user, Transaction $transaction): bool
    {
        return $user->can(PermissionName::AccountingManage->value)
            && $this->sharesTenant($user, $transaction);
    }

    /**
     * API 4.9.2 — GET /finance/export: "Export laporan keuangan ke Excel".
     *
     * Digantung pada `financial_report.manage`, sama seperti ekspor laporan
     * tagihan (butir 98), dan bukan pada `accounting.*` yang mengatur
     * pencatatan buku kasnya. Mengekspor adalah membaca laporan keuangan ke
     * luar sistem, sehingga baris matriks yang berlaku "Laporan Keuangan" —
     * dan PRD 1.1.1 memang menyebut "akuntansi & laporan keuangan" sebagai
     * tanggung jawab Bendahara.
     *
     * Kepala Sekolah karena itu dapat membaca buku kas di layar tetapi tidak
     * mengunduhnya: mereka hanya memegang `financial_report.view`. Lihat
     * docs/implementation-notes.md butir 105.
     */
    public function export(User $user): bool
    {
        return $user->can(PermissionName::FinancialReportManage->value);
    }

    /**
     * API 4.9.2 — DELETE /transactions/{id}, Auth Level **Admin**, dan
     * API 4.1: "Auth Level: Admin = Wajib token + role SCHOOL_ADMIN /
     * SUPER_ADMIN".
     *
     * Kewenangannya sengaja **tidak** digantung pada `accounting.manage`,
     * berbeda dari `create` dan `update` di atas. Izin itu juga dipegang
     * Bendahara, dan yang menimpa label "Admin" pada pencatatan adalah user
     * story KAS-01 yang menyebut Bendahara secara eksplisit — story itu bicara
     * tentang *mencatat* pemasukan dan pengeluaran, tidak tentang menghapusnya.
     * Tidak ada satu pun user story penghapusan di PRD, sehingga label "Admin"
     * di API tidak tertimpa apa pun. Polanya sama dengan pembebasan tagihan
     * (butir 67).
     *
     * PRD 1.1.2 memberi Bendahara ✅ pada modul "Akuntansi & Kas", dan ✅ di
     * sana didefinisikan sebagai akses penuh termasuk delete. Baris matriks itu
     * umum untuk satu modul, sedangkan Auth Level pada satu endpoint bersifat
     * spesifik — dan yang spesifik menang. Menghapus baris buku kas juga
     * destruktif satu arah: tidak ada UI restore pada Phase 1. Ini keputusan
     * implementasi, bukan kutipan blueprint; lihat butir 129.
     */
    public function delete(User $user, Transaction $transaction): bool
    {
        return $this->isSchoolAdministrator($user)
            && $this->sharesTenant($user, $transaction);
    }

    /**
     * Mengembalikan transaksi yang sudah dihapus tidak ada pada Phase 1 —
     * tidak lewat panel, tidak lewat API. Ability-nya tetap ditulis di sini,
     * dan tetap `false`, supaya penambahan halaman "trashed" bawaan Filament
     * di kemudian hari tidak diam-diam mendapat izin (butir 131).
     */
    public function restore(User $user, Transaction $transaction): bool
    {
        return false;
    }

    /**
     * Penghapusan permanen tidak pernah menjadi bagian dari kontrak apa pun:
     * yang diminta API adalah soft delete, dan buku kas yang benar-benar
     * lenyap adalah kebalikan dari jejak audit yang diminta Security 3.4.
     */
    public function forceDelete(User $user, Transaction $transaction): bool
    {
        return false;
    }

    /**
     * SUPER_ADMIN sudah lolos lebih dulu lewat Gate::before.
     */
    protected function isSchoolAdministrator(User $user): bool
    {
        return $user->hasRole(RoleName::SchoolAdmin->value);
    }

    /**
     * Bukti transaksi disimpan di disk privat; yang boleh mengunduhnya persis
     * yang boleh melihat transaksinya.
     */
    public function downloadProof(User $user, Transaction $transaction): bool
    {
        return $this->view($user, $transaction);
    }

    protected function sharesTenant(User $user, Transaction $transaction): bool
    {
        return $user->school_id !== null
            && $user->school_id === $transaction->school_id;
    }
}
