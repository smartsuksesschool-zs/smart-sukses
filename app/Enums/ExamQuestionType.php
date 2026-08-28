<?php

namespace App\Enums;

/**
 * Jenis soal pada ujian online.
 *
 * Blueprint Phase 2 menyebut dua jenis: "pilihan ganda, essay"
 * (`01-prd/03-phase2-overview.md`). MVP yang dipercepat hanya mengerjakan
 * pilihan ganda; uraian tetap termasuk scope Phase 2 yang belum dikerjakan
 * (docs/cbt-mvp-scope.md).
 *
 * ESSAY tetap ada di enum ini sejak awal, dan karena itu juga ada di kolom
 * `exam_questions.question_type`. Alasannya teknis, bukan perluasan scope:
 * menambah nilai ke kolom ENUM MySQL yang sudah berisi data adalah perubahan
 * skema pada tabel hidup, sedangkan menuliskannya sekarang tidak berbiaya
 * apa pun. Yang menahannya tetap tertutup adalah `supportedCases()` — validasi
 * dan UI hanya boleh menawarkan jenis yang didukung (butir 266).
 */
enum ExamQuestionType: string
{
    case MultipleChoice = 'MULTIPLE_CHOICE';

    case Essay = 'ESSAY';

    public function label(): string
    {
        return match ($this) {
            self::MultipleChoice => __('Pilihan Ganda'),
            self::Essay => __('Uraian'),
        };
    }

    /**
     * Jenis yang dapat dinilai sistem tanpa guru. Uraian tidak, dan tidak ada
     * penilaian otomatis yang dikarang untuknya.
     */
    public function isAutoScored(): bool
    {
        return $this === self::MultipleChoice;
    }

    /**
     * Jenis yang boleh dibuat pada rilis ini. Yang tidak didukung tetap dapat
     * disimpan skema, tetapi tidak boleh ditawarkan maupun diterima aplikasi.
     */
    public function isSupported(): bool
    {
        return $this === self::MultipleChoice;
    }

    /**
     * @return array<int, self>
     */
    public static function supportedCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $case) => $case->isSupported()));
    }

    /**
     * @return array<int, string>
     */
    public static function supportedValues(): array
    {
        return array_map(fn (self $case) => $case->value, self::supportedCases());
    }

    /**
     * @return array<string, string>
     */
    public static function supportedOptions(): array
    {
        return array_reduce(
            self::supportedCases(),
            fn (array $carry, self $case) => $carry + [$case->value => $case->label()],
            [],
        );
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
