<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Persetujuan yang tidak dapat dilanjutkan.
 *
 * Pesannya ditujukan kepada **admin**, dan karena itu boleh spesifik: yang
 * membacanya sudah berwenang melihat baris yang bersangkutan, dan tanpa
 * keterangan yang tepat ia tidak dapat memperbaiki apa pun. Pemohon tidak
 * pernah melihat satu pun kalimat dari sini (butir 533).
 */
class AccountClaimException extends RuntimeException
{
    public static function notReviewable(): self
    {
        return new self(__('Permintaan ini sudah pernah ditinjau.'));
    }

    public static function studentAlreadyLinked(): self
    {
        return new self(__('Siswa ini sudah tertaut ke akun lain untuk peran tersebut.'));
    }

    public static function identityAlreadyUsed(): self
    {
        return new self(__('Akun Google ini sudah dipakai pengguna lain.'));
    }

    public static function emailAlreadyUsed(): self
    {
        return new self(__('Alamat surel ini sudah dipakai pengguna lain.'));
    }

    public static function crossSchool(): self
    {
        return new self(__('Akun ini terdaftar di cabang lain; penautan lintas cabang belum didukung.'));
    }

    public static function typeNotSelfService(): self
    {
        return new self(__('Jenis permintaan ini tidak dapat diproses lewat alur pendaftaran mandiri.'));
    }

    /**
     * Permintaan staf yang disetujui tanpa peran.
     */
    public static function roleRequired(): self
    {
        return new self(__('Pilih peran yang akan diberikan sebelum menyetujui permintaan staf.'));
    }

    /**
     * Peran di luar daftar putih peninjau.
     *
     * Terutama SCHOOL_ADMIN dan SUPER_ADMIN: keduanya dapat membuat pengguna
     * lain, sehingga jalur publik yang berakhir di salah satunya berarti
     * pendaftaran mandiri yang menyerahkan seluruh cabang — atau seluruh
     * platform (butir 547).
     */
    public static function roleNotAssignable(): self
    {
        return new self(__('Peran tersebut tidak dapat diberikan lewat permintaan akun.'));
    }

    /**
     * Permintaan yang seharusnya menunjuk siswa tetapi tidak menyebut satu pun.
     */
    public static function studentMissing(): self
    {
        return new self(__('Permintaan ini tidak menunjuk data siswa mana pun.'));
    }
}
