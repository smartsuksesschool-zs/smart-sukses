<?php

namespace App\Enums;

/**
 * ERD 2.2 — notifications.target_type: ENUM(ALL, CLASS, INDIVIDUAL).
 *
 * `target_id` menyimpan "class_id atau user_id jika bukan ALL" dan sengaja
 * tidak punya foreign key — satu kolom yang menunjuk dua tabel berbeda tidak
 * dapat dijaga FK. Karena itu maknanya divalidasi aplikasi (butir 194).
 */
enum NotificationTargetType: string
{
    case All = 'ALL';
    case SchoolClass = 'CLASS';
    case Individual = 'INDIVIDUAL';

    public function label(): string
    {
        return match ($this) {
            self::All => __('Semua pengguna cabang'),
            self::SchoolClass => __('Orang tua satu kelas'),
            self::Individual => __('Satu pengguna'),
        };
    }

    /** Apakah target ini membutuhkan `target_id`. */
    public function needsTargetId(): bool
    {
        return $this !== self::All;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
