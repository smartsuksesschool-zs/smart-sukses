<?php

namespace App\Enums;

/**
 * Keadaan satu ujian **dari sudut pandang seorang siswa**.
 *
 * Bukan kolom database dan tidak pernah disimpan. Ia diturunkan dari tiga hal
 * yang sudah tersimpan — status ujian, jadwalnya, dan ada-tidaknya pengerjaan
 * milik siswa itu — sehingga tidak ada keadaan kedua yang harus dijaga tetap
 * sinkron dengan yang sebenarnya. Menyimpannya berarti menyimpan kesimpulan,
 * dan kesimpulan yang tersimpan akan basi tepat pada detik jadwalnya lewat
 * (butir 306).
 */
enum StudentExamState: string
{
    /** Terbit, tetapi waktunya belum tiba. */
    case Upcoming = 'UPCOMING';

    /** Sedang dalam rentang waktunya dan belum dikerjakan. */
    case Available = 'AVAILABLE';

    /** Sudah dimulai, belum dikumpulkan, dan belum lewat batas waktunya. */
    case InProgress = 'IN_PROGRESS';

    /** Sudah dikumpulkan dan sudah bernilai. */
    case Submitted = 'SUBMITTED';

    /** Waktunya sudah lewat tanpa pernah dikerjakan. */
    case Missed = 'MISSED';

    public function label(): string
    {
        return match ($this) {
            self::Upcoming => __('Belum dibuka'),
            self::Available => __('Dapat dikerjakan'),
            self::InProgress => __('Sedang dikerjakan'),
            self::Submitted => __('Sudah dikumpulkan'),
            self::Missed => __('Terlewat'),
        };
    }

    /**
     * Kelas badge pada tata letak portal — nama yang sama dengan yang sudah
     * dipakai halaman portal lain.
     */
    public function badge(): string
    {
        return match ($this) {
            self::Upcoming => 'portal-badge--muted',
            self::Available => 'portal-badge--success',
            self::InProgress => 'portal-badge--warning',
            self::Submitted => 'portal-badge--success',
            self::Missed => 'portal-badge--danger',
        };
    }

    /**
     * Apakah siswa dapat membuka halaman pengerjaan — memulai maupun
     * melanjutkan. Keduanya satu pintu; lihat butir 309.
     */
    public function isWorkable(): bool
    {
        return $this === self::Available || $this === self::InProgress;
    }

    public function hasResult(): bool
    {
        return $this === self::Submitted;
    }

    /**
     * Ajakan yang dibaca siswa di daftar ujian.
     */
    public function action(): ?string
    {
        return match ($this) {
            self::Available => 'Mulai Ujian',
            self::InProgress => 'Lanjutkan',
            self::Submitted => 'Lihat Hasil',
            default => null,
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

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
