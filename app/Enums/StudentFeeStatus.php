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
            self::Unpaid => 'Belum Bayar',
            self::Partial => 'Bayar Sebagian',
            self::Paid => 'Lunas',
            self::Waived => 'Dibebaskan',
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
