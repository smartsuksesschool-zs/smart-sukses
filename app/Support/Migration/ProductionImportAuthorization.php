<?php

namespace App\Support\Migration;

use App\Models\AcademicYear;
use App\Models\School;

/**
 * M5 — bukti bahwa seluruh pagar impor produksi sudah terbuka.
 *
 * Bukan sebuah bendera boolean. `StudentImportApply` menerima **objek ini**,
 * dan objek ini tidak dapat dibuat tanpa melewati `grant()` — sehingga tidak ada
 * jalan memanggil impor produksi dengan `true` yang tersalin dari contoh kode
 * atau tertinggal dari eksperimen (butir 520).
 *
 * Ia juga membawa rencana yang disahkannya. `StudentImportApply` memeriksa
 * bahwa rencana yang hendak ditulis benar-benar rencana yang sama dengan yang
 * diotorisasi, sehingga otorisasi tidak dapat dipakai ulang untuk berkas lain.
 *
 * Kelas ini **tidak melemahkan** MigrationWriteGuard. Pagar itu tetap utuh dan
 * tetap menolak `migrasi:terapkan-uji` di luar basis data uji; yang ada di sini
 * jalur kedua yang terpisah, jauh lebih sempit, dan hanya terbuka di
 * `production`.
 */
final class ProductionImportAuthorization
{
    /**
     * Hasil rencana yang membuat impor produksi ditolak.
     *
     * Bukan daftar "yang mencurigakan" melainkan daftar "yang belum selesai":
     * setiap satu di antaranya berarti ada baris yang tidak akan ikut, dan
     * produksi bukan tempat mengetahui hal itu setelah menulis.
     *
     * PENDING_MISSING_NIS sengaja **tidak** ada di sini — satu siswa yang
     * menunggu NIS resmi adalah keadaan yang sudah diputuskan dan diterima,
     * dan ia tidak boleh menahan 39 yang lain (butir 487).
     *
     * @var array<int, string>
     */
    public const BLOCKING_OUTCOMES = [
        StudentImportPlan::REJECTED_MASTER_INCOMPLETE,
        StudentImportPlan::PENDING_DUPLICATE_NIS,
        StudentImportPlan::PENDING_DUPLICATE_NISN,
        StudentImportPlan::PENDING_INVALID_NISN,
        StudentImportPlan::PENDING_PPDB_RECONCILIATION,
    ];

    /**
     * Keadaan penempatan yang membuat impor produksi ditolak.
     *
     * @var array<int, string>
     */
    public const BLOCKING_PLACEMENTS = [
        StudentImportPlan::CLASS_NOT_FOUND,
        StudentImportPlan::CLASS_AMBIGUOUS,
        StudentImportPlan::CLASS_LABEL_MISSING,
        StudentImportPlan::ACADEMIC_YEAR_MISSING,
    ];

    private function __construct(
        public readonly School $school,
        public readonly AcademicYear $year,
        public readonly ImportFingerprint $fingerprint,
        public readonly int $ready,
    ) {}

    /**
     * Seluruh alasan penolakan, kosong bila boleh menulis.
     *
     * Dikembalikan sebagai daftar, bukan alasan pertama: operator berhak tahu
     * semua yang harus dibereskan sekali jalan, bukan menemukannya satu per satu
     * lewat percobaan berulang di produksi.
     *
     * @param  array<string, mixed>  $plan
     * @return array<int, string>
     */
    public static function refusals(array $plan, ?AcademicYear $year): array
    {
        $out = [];

        if (! app()->environment('production')) {
            $out[] = 'Lingkungan bukan `production` (sekarang: `'.app()->environment().'`). '
                .'Perintah ini hanya untuk impor produksi yang sudah diotorisasi; '
                .'untuk basis data uji pakai `migrasi:terapkan-uji`.';
        }

        if ($year === null) {
            $out[] = 'Tahun ajaran tujuan belum ada.';
        }

        foreach (self::BLOCKING_OUTCOMES as $outcome) {
            $count = $plan['outcomes'][$outcome] ?? 0;

            if ($count > 0) {
                $out[] = "{$count} baris berstatus {$outcome} — bereskan di sumbernya lebih dulu.";
            }
        }

        foreach (self::BLOCKING_PLACEMENTS as $placement) {
            $count = $plan['placements'][$placement] ?? 0;

            if ($count > 0) {
                $out[] = "{$count} baris siap berstatus {$placement} — seluruh baris yang siap "
                    .'harus PLACEMENT_READY sebelum impor produksi.';
            }
        }

        if (($plan['reconciliation']['ready'] ?? 0) < 1) {
            $out[] = 'Tidak ada satu baris pun yang siap diimpor.';
        }

        if (($plan['reconciliation']['balanced'] ?? false) !== true) {
            $out[] = 'Rekonsiliasi tidak seimbang: sumber ≠ siap + tertunda + ditolak.';
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public static function allowed(array $plan, ?AcademicYear $year): bool
    {
        return self::refusals($plan, $year) === [];
    }

    /**
     * Menerbitkan otorisasi, atau null bila ada satu saja pagar yang tertutup.
     *
     * Memeriksa ulang seluruh syarat di sini, bukan memercayai pemanggilnya:
     * objek ini adalah bukti, dan bukti yang menerima kata pemanggilnya bukan
     * bukti.
     *
     * @param  array<string, mixed>  $plan
     */
    public static function grant(
        array $plan,
        School $school,
        ?AcademicYear $year,
        ImportFingerprint $fingerprint,
    ): ?self {
        if ($year === null || self::refusals($plan, $year) !== []) {
            return null;
        }

        return new self($school, $year, $fingerprint, (int) $plan['reconciliation']['ready']);
    }

    /**
     * Apakah otorisasi ini memang menyahkan rencana yang sedang hendak ditulis.
     *
     * @param  array<string, mixed>  $plan
     */
    public function covers(array $plan, ImportFingerprint $fingerprint): bool
    {
        return $this->fingerprint->matches($fingerprint->value)
            && $this->ready === (int) ($plan['reconciliation']['ready'] ?? -1);
    }

    /**
     * Kalimat konfirmasi yang harus diketik operator.
     *
     * Diturunkan dari rencana yang sebenarnya, bukan ditulis tetap di kode:
     * kalimat yang selalu sama akan dihafal dan diketik tanpa dibaca, sementara
     * kalimat yang memuat jumlah, cabang, dan tahun ajaran memaksa operator
     * membaca apa yang sedang disetujuinya (butir 521).
     */
    public function confirmationPhrase(): string
    {
        return sprintf('IMPOR %d SISWA %s %s', $this->ready, $this->school->code, $this->year->name);
    }

    public function phraseMatches(string $typed): bool
    {
        return self::normalisePhrase($typed) === self::normalisePhrase($this->confirmationPhrase());
    }

    /**
     * Spasi berlebih dan besar-kecil huruf dimaafkan; isinya tidak.
     */
    protected static function normalisePhrase(string $phrase): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $phrase) ?? ''));
    }
}
