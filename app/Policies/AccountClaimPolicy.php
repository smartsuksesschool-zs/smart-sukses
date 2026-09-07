<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\AccountClaim;
use App\Models\User;

/**
 * Siapa yang boleh memutuskan permintaan akun.
 *
 * **Tidak ada modul izin baru.** Permintaan akun adalah permintaan untuk
 * membuat sebuah `users`, jadi ia memakai izin modul "User Management" pada
 * matriks PRD 1.1.2 apa adanya. Menambahkan modul ke-17 berarti menambahkan
 * baris yang tidak ada di dokumen sumber mana pun, dan setiap peran baru
 * kelak harus dijawab dua kali: sekali untuk pengguna, sekali untuk permintaan
 * pengguna (butir 542).
 *
 * Akibat langsungnya sesuai keputusan produk: Super Admin dan Admin Sekolah
 * dapat menyetujui, sedangkan Kepala Sekolah, Guru, Wali Kelas, dan Bendahara
 * — yang tidak punya modul `user` sama sekali — bahkan tidak melihat menunya.
 *
 * Isolasi cabang ditangani global scope; policy ini pagar keduanya, per record.
 */
class AccountClaimPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::UserView->value);
    }

    public function view(User $user, AccountClaim $claim): bool
    {
        return $user->can(PermissionName::UserView->value)
            && $this->sharesTenant($user, $claim);
    }

    /**
     * Permintaan lahir dari alur Google publik, tidak pernah dari panel.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Tidak ada penyuntingan bebas: satu-satunya perubahan yang sah adalah
     * Setujui dan Tolak, dan keduanya punya jalurnya sendiri.
     */
    public function update(User $user, AccountClaim $claim): bool
    {
        return false;
    }

    /**
     * Permintaan tidak dihapus. Yang ditolak tetap tersimpan sebagai keterangan
     * kalau kelak ada pertanyaan mengapa sebuah akun tidak pernah aktif.
     */
    public function delete(User $user, AccountClaim $claim): bool
    {
        return false;
    }

    public function approve(User $user, AccountClaim $claim): bool
    {
        return $user->can(PermissionName::UserManage->value)
            && $this->sharesTenant($user, $claim)
            && $claim->isReviewable();
    }

    public function reject(User $user, AccountClaim $claim): bool
    {
        return $this->approve($user, $claim);
    }

    protected function sharesTenant(User $user, AccountClaim $claim): bool
    {
        return $user->school_id !== null
            && $user->school_id === $claim->school_id;
    }
}
