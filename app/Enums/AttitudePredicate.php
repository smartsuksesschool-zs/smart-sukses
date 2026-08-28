<?php

namespace App\Enums;

/**
 * ERD 2.2 — report_cards.attitude_score: ENUM(A,B,C,D).
 *
 * Keputusan Sprint 4 butir 3: nilai sikap TIDAK masuk nilai akademik dan
 * dilaporkan terpisah sebagai predikat. Rentang di bawah hanyalah **default
 * aplikasi**; sumber kebenarannya adalah `schools.attitude_scale` yang dapat
 * diubah Admin (keputusan: "Range attitude JANGAN hard-code").
 *
 * Resolusi skor → predikat dilakukan App\Services\Grading\AttitudePredicateResolver.
 */
enum AttitudePredicate: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';

    public function label(): string
    {
        return match ($this) {
            self::A => __('A — Sangat Baik'),
            self::B => __('B — Baik'),
            self::C => __('C — Cukup'),
            self::D => __('D — Perlu Bimbingan'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::A => 'success',
            self::B => 'info',
            self::C => 'warning',
            self::D => 'danger',
        };
    }

    /**
     * Batas bawah default sesuai keputusan Sprint 4 butir 3:
     * 86-100 = A, 76-85 = B, 66-75 = C, 0-65 = D.
     *
     * Dipakai hanya bila `schools.attitude_scale` belum diisi.
     *
     * @return array<string, float>
     */
    public static function defaultScale(): array
    {
        return [
            self::A->value => 86.0,
            self::B->value => 76.0,
            self::C->value => 66.0,
            self::D->value => 0.0,
        ];
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
