<?php

namespace App\Enums;

/**
 * Keputusan Sprint 4 butir 3: "Data harus membedakan formative dan summative.
 * Tidak semua Daily/formative otomatis menjadi komponen nilai rapor."
 *
 * Di luar ERD 2.2 — lihat docs/implementation-notes.md butir 25.
 */
enum AssessmentType: string
{
    /** Penilaian latihan/proses — tidak ikut perhitungan nilai rapor. */
    case Formative = 'FORMATIVE';

    /** Penilaian yang diperhitungkan sebagai komponen nilai rapor. */
    case Summative = 'SUMMATIVE';

    public function label(): string
    {
        return match ($this) {
            self::Formative => __('Formatif (tidak dihitung)'),
            self::Summative => __('Sumatif (dihitung)'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Formative => 'gray',
            self::Summative => 'success',
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
