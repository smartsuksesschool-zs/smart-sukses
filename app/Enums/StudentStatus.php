<?php

namespace App\Enums;

/**
 * ERD 2.2 — students.status. Lihat juga API 4.5 PATCH /students/{id}/status.
 */
enum StudentStatus: string
{
    case Active = 'ACTIVE';
    case Graduated = 'GRADUATED';
    case DroppedOut = 'DROPPED_OUT';
    case Transferred = 'TRANSFERRED';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Aktif'),
            self::Graduated => __('Lulus'),
            self::DroppedOut => __('Keluar'),
            self::Transferred => __('Pindah'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Graduated => 'info',
            self::DroppedOut => 'danger',
            self::Transferred => 'warning',
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
}
