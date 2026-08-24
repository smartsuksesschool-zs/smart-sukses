<?php

namespace App\Enums;

/**
 * ERD 2.2 — notifications.type:
 * ENUM(ANNOUNCEMENT, BILLING, ACADEMIC, EMERGENCY, SYSTEM).
 *
 * NOTIF-01 menyebut kategorinya "ACADEMIC/BILLING/EMERGENCY/**GENERAL**",
 * sedangkan ERD tidak mengenal GENERAL dan justru punya ANNOUNCEMENT serta
 * SYSTEM. Yang dipakai sebagai nilai kanonik adalah enum ERD — skema yang
 * benar-benar menyimpan datanya — dan `GENERAL` diterima sebagai alias masukan
 * yang dinormalkan ke ANNOUNCEMENT (butir 191).
 */
enum NotificationType: string
{
    /** Kategori umum untuk pengumuman manual — padanan "GENERAL" NOTIF-01. */
    case Announcement = 'ANNOUNCEMENT';

    case Billing = 'BILLING';

    case Academic = 'ACADEMIC';

    case Emergency = 'EMERGENCY';

    /** Disediakan untuk notifikasi otomatis (NOTIF-03), belum dipakai. */
    case System = 'SYSTEM';

    /**
     * Alias masukan yang diterima selain nilai kanonik.
     *
     * Hanya satu, dan sengaja hanya satu: "GENERAL" muncul di NOTIF-01 tetapi
     * tidak pernah ada di skema, jadi ia dipetakan alih-alih ditolak — dan
     * alih-alih menambah nilai enum yang tidak ada di ERD.
     */
    public static function fromInput(?string $value): ?self
    {
        if (blank($value)) {
            return null;
        }

        $value = mb_strtoupper(trim($value));

        if ($value === 'GENERAL') {
            return self::Announcement;
        }

        return self::tryFrom($value);
    }

    public function label(): string
    {
        return match ($this) {
            self::Announcement => 'Pengumuman',
            self::Billing => 'Tagihan',
            self::Academic => 'Akademik',
            self::Emergency => 'Darurat',
            self::System => 'Sistem',
        };
    }

    /**
     * Kategori yang boleh dipilih pada pengumuman manual.
     *
     * SYSTEM tidak termasuk: ERD menyebutnya untuk notifikasi yang dipicu
     * sistem, dan memilihnya secara manual akan membuat notifikasi buatan
     * manusia menyamar sebagai notifikasi otomatis.
     *
     * @return array<int, self>
     */
    public static function manualCases(): array
    {
        return [self::Announcement, self::Academic, self::Billing, self::Emergency];
    }

    /**
     * @return array<string, string>
     */
    public static function manualOptions(): array
    {
        $options = [];

        foreach (self::manualCases() as $case) {
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
