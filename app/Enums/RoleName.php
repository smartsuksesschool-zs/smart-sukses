<?php

namespace App\Enums;

/**
 * PRD 1.1.1 — Daftar Peran.
 *
 * Setiap pengguna memiliki tepat satu peran utama. Terdapat dua level:
 * Platform Level (lintas semua cabang) dan School Level (terikat school_id).
 */
enum RoleName: string
{
    case SuperAdmin = 'SUPER_ADMIN';
    case SchoolAdmin = 'SCHOOL_ADMIN';
    case KepalaSekolah = 'KEPALA_SEKOLAH';
    case Guru = 'GURU';
    case WaliKelas = 'WALI_KELAS';
    case Siswa = 'SISWA';
    case OrangTua = 'ORANG_TUA';
    case Bendahara = 'BENDAHARA';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Administrator',
            self::SchoolAdmin => 'Admin Sekolah',
            self::KepalaSekolah => 'Kepala Sekolah',
            self::Guru => 'Guru Mata Pelajaran',
            self::WaliKelas => 'Wali Kelas',
            self::Siswa => 'Siswa',
            self::OrangTua => 'Orang Tua / Wali Murid',
            self::Bendahara => 'Bendahara',
        };
    }

    /**
     * Peran Platform Level tidak terikat pada satu cabang (school_id = NULL).
     */
    public function isPlatformLevel(): bool
    {
        return $this === self::SuperAdmin;
    }

    /**
     * Siswa & Orang Tua dilayani lewat portal terpisah, bukan admin panel.
     */
    public function canAccessAdminPanel(): bool
    {
        return ! in_array($this, [self::Siswa, self::OrangTua], true);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $role) => $carry + [$role->value => $role->label()],
            [],
        );
    }
}
