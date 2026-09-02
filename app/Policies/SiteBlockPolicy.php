<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\SiteBlock;
use App\Models\User;

/**
 * Isi situs publik — modul `public_content`.
 *
 * **Tanpa pemeriksaan tenant, dan itu disengaja.** Policy lain di project ini
 * menutup dengan `sharesTenant()` karena recordnya milik satu cabang; blok isi
 * halaman muka tidak punya `school_id` sama sekali, sehingga pemeriksaan yang
 * sama di sini akan membandingkan `school_id` pengguna dengan kolom yang tidak
 * ada — dan menolak semua orang, termasuk yang berhak.
 *
 * Penjagaannya berpindah tempat, bukan hilang: izin `public_content.*` hanya
 * dimiliki Super Admin, sehingga Admin Sekolah mana pun tetap tertutup di
 * `viewAny()` (butir 469).
 */
class SiteBlockPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionName::PublicContentView->value);
    }

    public function view(User $user, SiteBlock $siteBlock): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionName::PublicContentManage->value);
    }

    public function update(User $user, SiteBlock $siteBlock): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, SiteBlock $siteBlock): bool
    {
        return $this->create($user);
    }
}
