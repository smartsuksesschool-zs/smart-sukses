<?php

namespace App\Enums;

/**
 * ERD 2.2 — schedules.day_of_week: 1=Senin, 2=Selasa, …, 7=Minggu.
 */
enum DayOfWeek: int
{
    case Monday = 1;
    case Tuesday = 2;
    case Wednesday = 3;
    case Thursday = 4;
    case Friday = 5;
    case Saturday = 6;
    case Sunday = 7;

    public function label(): string
    {
        return match ($this) {
            self::Monday => __('Senin'),
            self::Tuesday => __('Selasa'),
            self::Wednesday => __('Rabu'),
            self::Thursday => __('Kamis'),
            self::Friday => __('Jumat'),
            self::Saturday => __('Sabtu'),
            self::Sunday => __('Minggu'),
        };
    }

    /**
     * @return array<int, string>
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
