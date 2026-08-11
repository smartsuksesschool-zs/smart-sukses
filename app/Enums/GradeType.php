<?php

namespace App\Enums;

/**
 * ERD 2.2 — grades.grade_type: ENUM(DAILY,MIDTERM,FINAL,ASSIGNMENT,SKILL,ATTITUDE).
 *
 * Keputusan Sprint 4 (butir 3) mengelompokkan keenam tipe ini:
 *  - Akademik & berbobot : DAILY, ASSIGNMENT, MIDTERM, FINAL, SKILL
 *  - Di luar nilai akademik: ATTITUDE — dilaporkan terpisah sebagai predikat
 *    (lihat App\Enums\AttitudePredicate).
 */
enum GradeType: string
{
    case Daily = 'DAILY';
    case Midterm = 'MIDTERM';
    case Final = 'FINAL';
    case Assignment = 'ASSIGNMENT';
    case Skill = 'SKILL';
    case Attitude = 'ATTITUDE';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Harian',
            self::Midterm => 'UTS',
            self::Final => 'UAS',
            self::Assignment => 'Tugas',
            self::Skill => 'Keterampilan',
            self::Attitude => 'Sikap',
        };
    }

    /**
     * Komponen yang boleh masuk perhitungan nilai akhir dan boleh muncul di
     * `grade_configs.components`. ATTITUDE sengaja dikecualikan.
     */
    public function isAcademic(): bool
    {
        return $this !== self::Attitude;
    }

    /**
     * Kelompok "Exam / Summative" pada keputusan Sprint 4 butir 3 —
     * UTS dan UAS sesuai penamaan NILAI-01.
     */
    public function isExam(): bool
    {
        return $this === self::Midterm || $this === self::Final;
    }

    /**
     * Tipe yang sah sebagai komponen konfigurasi bobot.
     *
     * @return array<int, self>
     */
    public static function academicCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $case) => $case->isAcademic()));
    }

    /**
     * @return array<int, string>
     */
    public static function academicValues(): array
    {
        return array_map(fn (self $case) => $case->value, self::academicCases());
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
     * @return array<string, string>
     */
    public static function academicOptions(): array
    {
        return array_reduce(
            self::academicCases(),
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
