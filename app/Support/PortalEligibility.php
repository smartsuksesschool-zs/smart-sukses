<?php

namespace App\Support;

use App\Enums\RoleName;
use App\Models\User;

/**
 * Syarat masuk portal, ditulis sekali untuk ketiga portal.
 *
 * Orang tua, guru, dan siswa punya pintu masuk yang berbeda, tetapi syarat
 * kelayakannya sama persis: peran yang tepat, akun aktif, terhubung ke sebuah
 * cabang, dan kata sandi sementara sudah diganti. Menuliskannya tiga kali
 * berarti tiga tempat yang harus ikut berubah setiap kali salah satunya
 * bergeser — dan yang tertinggal adalah lubang keamanan yang tidak terlihat
 * (butir 180).
 *
 * Yang paling mudah terlewat adalah syarat terakhir. Ketiga portal berada di
 * luar panel, sehingga `EnsurePasswordIsChanged` — middleware panel — tidak
 * ikut berlaku. Tanpa pemeriksaan ini, portal mana pun menjadi jalan memutar
 * bagi kata sandi sementara hasil reset admin (butir 158).
 */
class PortalEligibility
{
    /**
     * Pesan penolakan yang seragam.
     *
     * Nonaktif, peran keliru, dan tanpa cabang sengaja memakai kalimat yang
     * sama: membedakannya akan memberi tahu bahwa surel itu terdaftar dan
     * seperti apa akunnya (butir 115, 157).
     */
    public const REFUSED = 'Akun ini tidak memiliki akses ke portal ini.';

    public const PASSWORD_CHANGE_REQUIRED = 'Kata sandi sementara wajib diganti sebelum masuk. '
        .'Gunakan tautan lupa kata sandi untuk menyetel kata sandi baru.';

    /**
     * Alasan menolak akun ini, atau NULL bila ia memang berhak masuk.
     *
     * @param  array<int, RoleName>  $allowedRoles
     */
    public static function refusalReasonFor(?User $user, array $allowedRoles): ?string
    {
        if ($user === null || ! $user->is_active) {
            return self::REFUSED;
        }

        if (! self::hasAnyRole($user, $allowedRoles)) {
            return self::REFUSED;
        }

        // Akun School Level tanpa cabang tidak punya satu pun baris yang
        // menjadi miliknya (butir 127).
        if ($user->school_id === null) {
            return self::REFUSED;
        }

        if ($user->must_change_password) {
            return self::PASSWORD_CHANGE_REQUIRED;
        }

        return null;
    }

    public static function allows(?User $user, array $allowedRoles): bool
    {
        return self::refusalReasonFor($user, $allowedRoles) === null;
    }

    /**
     * @param  array<int, RoleName>  $roles
     */
    public static function hasAnyRole(User $user, array $roles): bool
    {
        foreach ($roles as $role) {
            if ($user->hasRole($role->value)) {
                return true;
            }
        }

        return false;
    }
}
