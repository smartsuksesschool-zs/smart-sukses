<?php

namespace App\Policies;

use App\Enums\PermissionName;
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
     * Penghapusan tidak diimplementasikan sama sekali.
     *
     * API 4.9 menyebut DELETE /transactions/{id} sebagai "soft delete", tetapi
     * ERD `transactions` tidak memuat `deleted_at`, status, maupun flag aktif,
     * dan tidak ada bagian blueprint yang menjelaskan mekanismenya. Menghapus
     * baris buku kas secara permanen bukan yang diminta dokumen, dan menambah
     * kolom untuk menandainya berarti mengarang skema — jadi keduanya tidak
     * dilakukan. Lihat docs/implementation-notes.md butir 74.
     */
    public function delete(User $user, Transaction $transaction): bool
    {
        return false;
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
