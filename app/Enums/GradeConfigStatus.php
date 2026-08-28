<?php

namespace App\Enums;

/**
 * Keputusan Sprint 4 butir 4 — siklus hidup konfigurasi bobot:
 * DRAFT → ACTIVE → LOCKED.
 *
 * Di luar ERD 2.2 — lihat docs/implementation-notes.md butir 23.
 */
enum GradeConfigStatus: string
{
    /** Bebas diedit; belum dipakai menilai. */
    case Draft = 'DRAFT';

    /** Sudah dipakai untuk penilaian — tidak boleh diubah sembarangan. */
    case Active = 'ACTIVE';

    /** Semester difinalisasi; konfigurasi permanen. */
    case Locked = 'LOCKED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Active => __('Aktif'),
            self::Locked => __('Terkunci'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Active => 'success',
            self::Locked => 'danger',
        };
    }

    /**
     * "DRAFT: bebas diedit." Selain itu, perubahan kebijakan bobot wajib
     * membuat versi baru — bukan menimpa konfigurasi lama.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function canBeActivated(): bool
    {
        return $this === self::Draft;
    }

    public function canBeLocked(): bool
    {
        return $this === self::Active;
    }

    /**
     * Hanya konfigurasi ACTIVE yang menjadi sumber snapshot bobot nilai baru.
     */
    public function isUsableForGrading(): bool
    {
        return $this === self::Active;
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
