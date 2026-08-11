<?php

namespace App\Enums;

/**
 * ERD 2.2 (PPDB) — ppdb_registrations.status.
 * Alur PPDB sesuai PPDB-02: REGISTERED → DOCUMENT_REVIEW → PASSED/FAILED → ENROLLED.
 */
enum PpdbStatus: string
{
    case Registered = 'REGISTERED';
    case DocumentReview = 'DOCUMENT_REVIEW';
    case Passed = 'PASSED';
    case Failed = 'FAILED';
    case Enrolled = 'ENROLLED';

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Terdaftar',
            self::DocumentReview => 'Verifikasi Berkas',
            self::Passed => 'Lulus Seleksi',
            self::Failed => 'Tidak Lulus',
            self::Enrolled => 'Sudah Menjadi Siswa',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Registered => 'gray',
            self::DocumentReview => 'warning',
            self::Passed => 'success',
            self::Failed => 'danger',
            self::Enrolled => 'info',
        };
    }

    /**
     * PPDB-04 poin 1 — "Sistem menyediakan template teks per perubahan status
     * (misal: 'Selamat, Ananda [nama] dinyatakan LULUS seleksi...')".
     *
     * Dipakai bila schools.wa_template_ppdb belum diisi Admin Sekolah.
     * Placeholder yang dikenali: lihat App\Support\PpdbWaTemplate.
     */
    public function waTemplate(): string
    {
        return match ($this) {
            self::Registered => 'Assalamu\'alaikum Bapak/Ibu [ortu]. Pendaftaran PPDB ananda [nama] di [sekolah] telah kami terima dengan nomor pendaftaran [nomor_pendaftaran]. Mohon menunggu proses verifikasi berkas.',
            self::DocumentReview => 'Assalamu\'alaikum Bapak/Ibu [ortu]. Berkas pendaftaran ananda [nama] (No. [nomor_pendaftaran]) di [sekolah] sedang dalam proses verifikasi. Terima kasih.',
            self::Passed => 'Selamat, Ananda [nama] dinyatakan LULUS seleksi PPDB [sekolah] dengan nomor pendaftaran [nomor_pendaftaran]. Silakan menghubungi pihak sekolah untuk proses daftar ulang.',
            self::Failed => 'Assalamu\'alaikum Bapak/Ibu [ortu]. Mohon maaf, ananda [nama] (No. [nomor_pendaftaran]) belum dapat kami terima pada PPDB [sekolah] tahun ini. Terima kasih atas kepercayaannya.',
            self::Enrolled => 'Selamat, Ananda [nama] (No. [nomor_pendaftaran]) resmi terdaftar sebagai siswa [sekolah]. Informasi selanjutnya akan kami sampaikan melalui nomor ini.',
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
