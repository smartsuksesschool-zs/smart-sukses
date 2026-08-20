<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Payment;
use App\Models\User;

/**
 * PRD 1.1.2 memisahkan dua modul yang mudah tertukar: "Tagihan SPP"
 * (SUPER_ADMIN ✅, SCHOOL_ADMIN ✅, KEPALA ⭕, GURU/WALI ❌, BENDAHARA ✅) dan
 * "Catat Pembayaran" (SUPER_ADMIN ✅, SCHOOL_ADMIN ✅, KEPALA **❌**,
 * GURU/WALI ❌, BENDAHARA ✅).
 *
 * Kepala Sekolah karena itu boleh melihat daftar tagihan tetapi tidak boleh
 * menyentuh pembayarannya sama sekali — bukan sekadar tidak boleh mencatat.
 * Izin `payment.view` / `payment.manage` sudah disediakan PermissionName dan
 * dibagikan RolePermissionSeeder persis mengikuti baris matriks itu, jadi
 * policy ini memakainya, bukan `fee.*` yang memetakan baris modul lain.
 */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::PaymentView->value);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->can(PermissionName::PaymentView->value)
            && $this->sharesTenant($user, $payment);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::PaymentManage->value);
    }

    /**
     * `payments` adalah riwayat, bukan data yang dikoreksi di tempat: ERD
     * memberinya `created_at` saja, dan API 4.9 tidak menyediakan
     * PUT/DELETE /payments. Nominal yang salah catat diselesaikan lewat baris
     * baru, bukan dengan mengubah baris lama.
     */
    public function update(User $user, Payment $payment): bool
    {
        return false;
    }

    public function delete(User $user, Payment $payment): bool
    {
        return false;
    }

    /**
     * Bukti pembayaran disimpan di disk privat; yang boleh mengunduhnya persis
     * yang boleh melihat pembayarannya.
     */
    public function downloadProof(User $user, Payment $payment): bool
    {
        return $this->view($user, $payment);
    }

    protected function sharesTenant(User $user, Payment $payment): bool
    {
        return $user->school_id !== null
            && $user->school_id === $payment->school_id;
    }
}
