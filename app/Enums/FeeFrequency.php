<?php

namespace App\Enums;

/**
 * ERD 2.2 — fee_types.frequency: "Frekuensi penerbitan".
 *
 * SPP-01 poin 1: "frekuensi (MONTHLY/YEARLY/ONCE)".
 */
enum FeeFrequency: string
{
    /** Terbit tiap bulan (SPP). */
    case Monthly = 'MONTHLY';

    /** Terbit sekali per tahun ajaran. */
    case Yearly = 'YEARLY';

    /** Terbit satu kali saja (mis. uang gedung). */
    case Once = 'ONCE';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => __('Bulanan'),
            self::Yearly => __('Tahunan'),
            self::Once => __('Sekali'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Monthly => 'success',
            self::Yearly => 'info',
            self::Once => 'gray',
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
