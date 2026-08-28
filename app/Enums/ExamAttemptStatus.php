<?php

namespace App\Enums;

/**
 * Keadaan satu pengerjaan ujian oleh satu siswa.
 *
 * Hanya dua keadaan. MVP yang dipercepat tidak mengenal uraian, sehingga tidak
 * ada keadaan "menunggu dinilai guru": begitu siswa mengumpulkan, seluruh
 * soalnya sudah dapat dinilai sistem dan nilainya final saat itu juga
 * (butir 267).
 */
enum ExamAttemptStatus: string
{
    /** Sedang dikerjakan; jawaban masih boleh berubah sampai batas waktunya. */
    case InProgress = 'IN_PROGRESS';

    /** Sudah dikumpulkan; terkunci dan sudah bernilai. */
    case Submitted = 'SUBMITTED';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => __('Sedang dikerjakan'),
            self::Submitted => __('Sudah dikumpulkan'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::InProgress => 'warning',
            self::Submitted => 'success',
        };
    }

    /**
     * Keadaan akhir: tidak ada jawaban yang boleh ditulis lagi.
     */
    public function isFinal(): bool
    {
        return $this === self::Submitted;
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
