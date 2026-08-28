<?php

namespace App\Enums;

/**
 * ERD 2.2 — students.gender: ENUM(L, P).
 */
enum Gender: string
{
    case Male = 'L';
    case Female = 'P';

    public function label(): string
    {
        return match ($this) {
            self::Male => __('Laki-laki'),
            self::Female => __('Perempuan'),
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
