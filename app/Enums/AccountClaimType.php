<?php

namespace App\Enums;

/**
 * Apa yang diminta pemohon dari jalur publik — `account_claims.requested_type`.
 *
 * **Ini bukan peran, dan sengaja bukan peran.** Dua di antaranya kebetulan
 * bernama sama dengan sebuah `RoleName`; yang ketiga tidak punya pasangan sama
 * sekali, dan itulah intinya. `STAF_SEKOLAH` tidak ada pada matriks izin PRD
 * 1.1.2, tidak dapat diberikan kepada siapa pun, dan tidak membawa satu izin
 * pun — ia hanya kalimat *"saya bekerja di sekolah ini"* yang menunggu dijawab
 * manusia (butir 544).
 *
 * Menambahkannya sebagai `RoleName` akan membuat sebuah peran tanpa izin hidup
 * di dalam enum yang seluruh isinya adalah peran nyata: ia akan muncul di
 * pemilih peran `UserResource`, dituntut punya akun penguji oleh test kesiapan
 * UAT (butir 526), dan menuntut satu baris pada matriks izin yang tidak pernah
 * diminta dokumen mana pun.
 */
enum AccountClaimType: string
{
    case Siswa = 'SISWA';
    case OrangTua = 'ORANG_TUA';
    case StafSekolah = 'STAF_SEKOLAH';

    public function label(): string
    {
        return match ($this) {
            self::Siswa => __('Siswa'),
            self::OrangTua => __('Orang Tua / Wali Murid'),
            self::StafSekolah => __('Staf Sekolah'),
        };
    }

    /**
     * Kalimat yang dipilih pemohon di halaman publik.
     */
    public function publicLabel(): string
    {
        return match ($this) {
            self::Siswa => __('Saya Siswa'),
            self::OrangTua => __('Saya Orang Tua/Wali'),
            self::StafSekolah => __('Saya Staf Sekolah'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Siswa => 'info',
            self::OrangTua => 'primary',
            self::StafSekolah => 'warning',
        };
    }

    /**
     * Permintaan ini menunjuk satu baris siswa, dan karena itu dicocokkan
     * dengan NIS + NISN.
     *
     * Staf tidak punya pengenal induk yang setara: tidak ada tabel guru, dan
     * NIP bukan data yang dipegang aplikasi ini. Karena itu tidak ada yang
     * dicocokkan untuknya — dan tidak ada pula yang dikarang sebagai gantinya
     * (butir 545).
     */
    public function matchesStudent(): bool
    {
        return $this !== self::StafSekolah;
    }

    /**
     * Peran yang otomatis mengikuti jenis ini, atau NULL bila admin yang
     * memilihnya.
     */
    public function impliedRole(): ?RoleName
    {
        return match ($this) {
            self::Siswa => RoleName::Siswa,
            self::OrangTua => RoleName::OrangTua,
            self::StafSekolah => null,
        };
    }

    /**
     * Jenis ini menuntut admin memilih peran hasilnya.
     */
    public function requiresRoleChoice(): bool
    {
        return $this->impliedRole() === null;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $case) => $carry + [$case->value => $case->label()],
            [],
        );
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
