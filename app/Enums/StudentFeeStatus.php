<?php

namespace App\Enums;

/**
 * ERD 2.2 — student_fees.status. Default: UNPAID.
 *
 * Perilaku transisinya (PARTIAL/PAID otomatis dari pembayaran, WAIVED lewat
 * PATCH /student-fees/{id}/waive) adalah lingkup SPP-03 dan belum
 * diimplementasikan pada Batch 5.1 — enum ini baru menyediakan nilainya.
 */
enum StudentFeeStatus: string
{
    /** Belum ada pembayaran sama sekali. */
    case Unpaid = 'UNPAID';

    /** Sudah dibayar sebagian (cicilan). */
    case Partial = 'PARTIAL';

    /** Lunas. */
    case Paid = 'PAID';

    /** Dibebaskan oleh Bendahara, dengan alasan. */
    case Waived = 'WAIVED';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => __('Belum Bayar'),
            self::Partial => __('Bayar Sebagian'),
            self::Paid => __('Lunas'),
            self::Waived => __('Dibebaskan'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'danger',
            self::Partial => 'warning',
            self::Paid => 'success',
            self::Waived => 'gray',
        };
    }

    /**
     * Hanya tagihan yang belum menerima satu pun pembayaran yang dapat
     * dibebaskan.
     *
     * Blueprint tidak mengatur pembebasan atas tagihan yang sudah dibayar
     * sebagian maupun lunas, dan tidak menyediakan refund/reversal untuk
     * mengembalikan uangnya — lihat docs/implementation-notes.md butir 68.
     * WAIVED sendiri bukan keadaan yang dapat dimasuki dua kali: alasan
     * pembebasan yang sudah tercatat tidak ditimpa diam-diam.
     */
    public function canBeWaived(): bool
    {
        return $this === self::Unpaid;
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
