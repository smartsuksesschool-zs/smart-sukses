<?php

namespace App\Enums;

/**
 * Siklus hidup satu ujian online (CBT).
 *
 * Di luar blueprint Phase 1. CBT adalah fitur Phase 2
 * (`01-prd/03-phase2-overview.md`) yang dipercepat atas permintaan langsung
 * pemilik — lihat docs/owner-scope-changes.md. Bentuk siklusnya meniru pola
 * yang sudah ada di project ini (GradeConfigStatus: DRAFT → ACTIVE → LOCKED),
 * bukan pola baru: keadaan yang boleh diedit dipisahkan dari keadaan yang
 * sudah dilihat orang lain, dan keadaan terakhir bersifat final (butir 265).
 */
enum ExamStatus: string
{
    /** Belum terlihat siswa; soal dan pilihan bebas diubah. */
    case Draft = 'DRAFT';

    /** Terbit untuk kelas yang mengikutinya, dalam rentang waktunya. */
    case Published = 'PUBLISHED';

    /** Sudah ditutup; hanya dibaca. */
    case Closed = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Published => 'Terbit',
            self::Closed => 'Ditutup',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Published => 'success',
            self::Closed => 'danger',
        };
    }

    /**
     * Hanya draf yang boleh diubah bebas. Ujian yang sudah terbit masih dapat
     * ditarik kembali selama belum ada yang mengerjakannya — aturan itu
     * menyangkut jumlah percobaan, bukan status, jadi tempatnya bukan di sini.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Status yang membuat ujian dapat muncul di portal siswa. Rentang waktunya
     * diperiksa terpisah: status dan jadwal adalah dua syarat, bukan satu.
     */
    public function isVisibleToStudents(): bool
    {
        return $this === self::Published;
    }

    public function canBePublished(): bool
    {
        return $this === self::Draft;
    }

    public function canBeClosed(): bool
    {
        return $this === self::Published;
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
