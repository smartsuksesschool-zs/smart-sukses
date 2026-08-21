<?php

namespace App\Services\Finance;

use App\Enums\StudentFeeStatus;
use App\Models\Scopes\SchoolScope;
use App\Models\StudentFee;
use Illuminate\Validation\ValidationException;

/**
 * API 4.9.2 — GET /finance/spp-report: "Laporan SPP: total tagihan, terkumpul,
 * tunggakan per periode".
 *
 * Domainnya `student_fees`, bukan `transactions`: yang diminta adalah posisi
 * tagihan, dan buku kas tidak tahu apa-apa tentang siapa menunggak. Karena itu
 * tidak ada satu pun baris `transactions` yang menyentuh angka di sini
 * (butir 75).
 *
 * Kalimat blueprint-nya berhenti pada tiga angka dan kata "per periode". Tidak
 * ada daftar filter, tidak ada bentuk respons, dan tidak ada definisi
 * tunggakan di seluruh dokumen — satu-satunya kemunculan kata "tunggakan" yang
 * lain ada pada endpoint statistik Super Admin, juga tanpa definisi. Yang di
 * bawah karena itu keputusan implementasi Phase 1 (butir 135, 136).
 */
class SppReportService
{
    /** Mengikuti DECIMAL(12,2) pada ERD. */
    protected const SCALE = 2;

    /**
     * Banyak periode terbanyak yang dikembalikan bila pemanggil tidak memilih
     * satu periode.
     *
     * Laporan tanpa batas akan tumbuh diam-diam seiring umur cabang, dan
     * blueprint tidak menetapkan paginasi untuk endpoint ini. Lima tahun
     * periode bulanan cukup jauh melampaui satu tahun ajaran, dan batasnya
     * disebutkan pada respons supaya pemanggil tahu ia sedang melihat potongan
     * (butir 136).
     */
    public const MAX_PERIODS = 60;

    /**
     * Laporan SPP satu cabang.
     *
     * `$period` opsional: bila diisi, hanya periode itu yang dilaporkan; bila
     * tidak, seluruh periode yang benar-benar punya tagihan dikembalikan
     * berurutan dari yang terbaru.
     *
     * @return array{
     *     school_id: int,
     *     period: string|null,
     *     truncated: bool,
     *     periods: array<int, array{period: string, total_billed: string, total_collected: string, arrears: string}>
     * }
     *
     * @throws ValidationException
     */
    public function report(int $schoolId, ?string $period = null): array
    {
        $period = $this->resolvePeriod($period);

        $rows = $this->aggregate($schoolId, $period);

        return [
            'school_id' => $schoolId,
            'period' => $period,
            'truncated' => $period === null && count($rows) > self::MAX_PERIODS,
            'periods' => array_slice($rows, 0, self::MAX_PERIODS),
        ];
    }

    /**
     * Seluruh tunggakan satu cabang, lintas seluruh periode.
     *
     * Dipakai statistik per cabang milik Super Admin, yang menyebut "tunggakan"
     * tanpa keterangan periode sama sekali. Angkanya sengaja **tidak** diambil
     * dengan menjumlahkan hasil `report()`: daftar itu dipotong pada
     * MAX_PERIODS, sehingga cabang yang sudah berjalan lebih dari lima tahun
     * akan diam-diam melaporkan tunggakan yang terlalu kecil.
     *
     * Aturannya tetap satu dan sama — UNPAID dan PARTIAL saja, WAIVED tidak
     * pernah ikut (butir 135). Yang berbeda hanya pengelompokannya: di sini
     * tidak ada `GROUP BY`, jadi hasilnya satu angka dari satu query.
     */
    public function totalArrearsForSchool(int $schoolId): string
    {
        $sum = StudentFee::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)
            ->whereIn('status', $this->arrearsStatuses())
            ->selectRaw('SUM(amount - amount_paid) as arrears')
            ->value('arrears');

        return $this->decimal($sum);
    }

    /**
     * Status yang benar-benar merupakan tunggakan.
     *
     * PAID sisanya nol dengan sendirinya; WAIVED sengaja tidak pernah masuk —
     * tagihan yang dibebaskan adalah uang yang secara sadar direlakan, bukan
     * piutang yang masih ditagih (butir 135).
     *
     * @return array<int, string>
     */
    protected function arrearsStatuses(): array
    {
        return [
            StudentFeeStatus::Unpaid->value,
            StudentFeeStatus::Partial->value,
        ];
    }

    /**
     * Satu query agregat untuk seluruh periode sekaligus.
     *
     * Tidak ada satu pun baris `student_fees` yang ditarik ke PHP: jumlahnya
     * dihitung database dan yang kembali hanya satu baris per periode. Jumlah
     * query karena itu tetap satu, berapa pun banyaknya tagihan cabang ini.
     *
     * `SUM`, `CASE WHEN`, dan `GROUP BY` ditulis apa adanya — ketiganya
     * berlaku sama di MySQL maupun SQLite, sehingga tidak ada percabangan
     * driver yang perlu dipasang (pola yang sama dengan FinanceSummaryService).
     *
     * @return array<int, array{period: string, total_billed: string, total_collected: string, arrears: string}>
     */
    protected function aggregate(int $schoolId, ?string $period): array
    {
        $arrearsStatuses = "'".implode("','", $this->arrearsStatuses())."'";

        return StudentFee::query()
            // Scope dilepas dan `school_id` disaring eksplisit: laporan ini
            // terkunci pada satu cabang yang sudah diputuskan pemanggilnya,
            // dan Super Admin tidak boleh diam-diam mendapat gabungan semua
            // cabang (KAS-03 adalah domain lintas cabang yang berbeda).
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)
            ->when($period !== null, fn ($query) => $query->where('period', $period))
            ->selectRaw('period')
            ->selectRaw('SUM(amount) as total_billed')
            ->selectRaw('SUM(amount_paid) as total_collected')
            ->selectRaw(
                "SUM(CASE WHEN status IN ({$arrearsStatuses}) THEN amount - amount_paid ELSE 0 END) as arrears"
            )
            ->groupBy('period')
            ->orderByDesc('period')
            ->get()
            ->map(fn ($row): array => [
                'period' => (string) $row->period,
                'total_billed' => $this->decimal($row->total_billed),
                'total_collected' => $this->decimal($row->total_collected),
                'arrears' => $this->decimal($row->arrears),
            ])
            ->all();
    }

    /**
     * @throws ValidationException
     */
    protected function resolvePeriod(?string $period): ?string
    {
        if (blank($period)) {
            return null;
        }

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw ValidationException::withMessages([
                'period' => 'Periode harus berformat YYYY-MM, misalnya 2026-08.',
            ]);
        }

        return $period;
    }

    /**
     * Hasil `SUM()` kembali sebagai string atau float tergantung driver;
     * dinormalkan ke dua desimal supaya seluruh perbandingan berikutnya
     * berjalan di ranah string, bukan floating point (butir 58).
     */
    protected function decimal(mixed $value): string
    {
        return number_format((float) $value, self::SCALE, '.', '');
    }
}
