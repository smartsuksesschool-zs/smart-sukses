<?php

namespace App\Enums;

/**
 * Aksi yang dicatat audit log.
 *
 * `03-architecture/04-security.md` — Audit Log: *"Semua aksi CUD (Create,
 * Update, Delete) dicatat"*. NFR 1.4 menyebut "CRUD", tetapi Security 3.4 lebih
 * spesifik: ia menyebut CUD **dan** merinci field yang harus disimpan. Aksi baca
 * karena itu tidak dicatat — lihat docs/implementation-notes.md butir 45.
 */
enum AuditAction: string
{
    case Created = 'CREATED';

    case Updated = 'UPDATED';

    case Deleted = 'DELETED';

    public function label(): string
    {
        return match ($this) {
            self::Created => __('Dibuat'),
            self::Updated => __('Diubah'),
            self::Deleted => __('Dihapus'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Created => 'success',
            self::Updated => 'warning',
            self::Deleted => 'danger',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
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
