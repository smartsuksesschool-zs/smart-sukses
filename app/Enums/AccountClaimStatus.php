<?php

namespace App\Enums;

/**
 * Keadaan sebuah permintaan akun — `account_claims.status`.
 *
 * PENDING dan APPROVED adalah keadaan **berlaku**: keduanya memakai tempat pada
 * indeks unik tabelnya. REJECTED dan CANCELLED adalah keadaan yang sudah lewat,
 * dan sengaja melepaskan tempat itu — seorang siswa yang permintaannya ditolak
 * karena salah ketik harus dapat mencoba lagi, dan siswa yang sama tidak boleh
 * terkunci selamanya oleh permintaan orang lain yang pernah ditolak.
 */
enum AccountClaimStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Menunggu'),
            self::Approved => __('Disetujui'),
            self::Rejected => __('Ditolak'),
            self::Cancelled => __('Dibatalkan'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Cancelled => 'gray',
        };
    }

    /**
     * Keadaan yang masih memakan tempat pada indeks unik tabelnya.
     */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    public function isReviewable(): bool
    {
        return $this === self::Pending;
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
}
