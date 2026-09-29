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

    /**
     * Kalimat ini menunjuk **admin sekolah**, bukan tautan lupa kata sandi.
     *
     * Sebelumnya ia menyuruh siswa dan orang tua memakai tautan lupa kata sandi,
     * dan tautan itu memang tidak ada: halaman masuk sengaja tidak
     * mengiklankannya (`UnifiedLoginTest::test_no_password_recovery_is_advertised`).
     * Petunjuk yang menyuruh seseorang menekan sesuatu yang tidak ada di layar
     * lebih buruk daripada penolakan tanpa petunjuk — ia membuat orang mengira
     * dirinya yang tidak teliti.
     *
     * Admin menyetel kata sandi baru lewat Ubah Pengguna, dan penanda "wajib
     * ganti" terlepas sendiri pada penyimpanan yang sama (`User::booted`),
     * sehingga akunnya langsung dapat dipakai (butir 591).
     */
    public const PASSWORD_CHANGE_REQUIRED = 'Kata sandi sementara wajib diganti sebelum masuk. '
        .'Hubungi admin sekolah untuk memperoleh kata sandi baru.';

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
