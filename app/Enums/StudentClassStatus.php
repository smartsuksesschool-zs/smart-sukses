<?php

namespace App\Enums;

/**
 * ERD 2.2 — student_classes.status: ENUM(ACTIVE, MOVED).
 */
enum StudentClassStatus: string
{
    case Active = 'ACTIVE';
    case Moved = 'MOVED';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::Moved => 'Pindah Kelas',
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
