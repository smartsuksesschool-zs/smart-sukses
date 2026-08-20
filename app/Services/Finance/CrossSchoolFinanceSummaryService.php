<?php

namespace App\Services\Finance;

use App\Enums\StudentFeeStatus;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\StudentFee;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * KAS-03 — "Sebagai Super Admin, saya dapat melihat ringkasan keuangan semua
 * cabang dalam satu dashboard", dengan "tampil per cabang: total tagihan, total
 * terkumpul, persentase lunas" dan "filter per tahun ajaran / bulan".
 *
 * Terpisah dari FinanceSummaryService (KAS-02) karena yang diukur berbeda.
 * KAS-02 mengukur **kas satu cabang pada satu bulan** — uang masuk dan keluar
 * menurut tanggal transaksinya. KAS-03 mengukur **keterkumpulan tagihan lintas
 * cabang** — seberapa banyak dari yang sudah diterbitkan sudah tertagih.
 * Menggabungkan keduanya ke satu class akan membuat satu objek dengan dua
 * definisi "terkumpul" yang berbeda.
 *
 * Ini satu-satunya jalur yang membaca lintas cabang, dan hanya Super Admin yang
 * boleh melewatinya.
 */
class CrossSchoolFinanceSummaryService
{
    /**
     * Status yang masih merupakan kewajiban pembayaran.
     *
     * WAIVED tidak termasuk: tagihan yang dibebaskan bukan tagihan yang perlu
     * dilunasi, dan memasukkannya ke penyebut membuat cabang yang banyak
     * memberi keringanan terlihat buruk justru karena kebijakannya. Lihat
     * docs/implementation-notes.md butir 91.
     *
     * @var array<int, string>
     */
    protected const BILLABLE_STATUSES = [
        StudentFeeStatus::Unpaid->value,
        StudentFeeStatus::Partial->value,
        StudentFeeStatus::Paid->value,
    ];

    protected const SCALE = 2;

    /**
     * Ringkasan per cabang.
     *
     * @param  string|null  $academicYear  Nama tahun ajaran (mis. "2026/2027 Ganjil"),
     *                                     bukan id — lihat butir 92.
     * @param  string|null  $period  Periode `YYYY-MM`, atau NULL untuk seluruh bulan.
     * @return array<int, array{
     *     school_id: int,
     *     school_code: string,
     *     school_name: string,
     *     is_active: bool,
     *     total_billed: string,
     *     total_collected: string,
     *     paid_count: int,
     *     billable_count: int,
     *     paid_percentage: string
     * }>
     *
     * @throws AuthorizationException|ValidationException
     */
    public function summarize(User $actor, ?string $academicYear = null, ?string $period = null): array
    {
        $this->authorize($actor);

        $period = $this->normalizePeriod($period);
        $academicYear = $this->normalizeAcademicYear($academicYear);

        $aggregates = $this->aggregateBySchool($academicYear, $period);

        return $this->schools()
            // Cabang nonaktif tetap tampil selama masih punya data pada filter
            // ini: laporan historis tidak boleh kehilangan cabang hanya karena
            // cabangnya kemudian ditutup. Yang tidak ditampilkan hanyalah
            // cabang nonaktif yang memang tidak punya tagihan sama sekali di
            // sini — barisnya akan berisi nol seluruhnya (butir 93).
            ->filter(fn (School $school) => $school->is_active
                || isset($aggregates[(int) $school->getKey()]))
            ->map(fn (School $school) => $this->row($school, $aggregates[(int) $school->getKey()] ?? []))
            ->values()
            ->all();
    }

    /**
     * Pilihan tahun ajaran untuk filter, dikumpulkan dari seluruh cabang.
     *
     * @return array<string, string>
     */
    public function academicYearOptions(User $actor): array
    {
        $this->authorize($actor);

        return AcademicYear::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->select('name')
            ->distinct()
            ->orderByDesc('name')
            ->pluck('name', 'name')
            ->all();
    }

    /**
     * KAS-03 adalah satu-satunya dashboard lintas cabang, dan PRD menyebut
     * pelakunya tunggal: Super Admin.
     *
     * Diperiksa lewat peran, bukan izin: `Gate::before` meloloskan Super Admin
     * untuk setiap ability, sehingga pemeriksaan berbasis izin justru tidak
     * dapat membedakan mereka dari peran lain yang kebetulan memegang izin
     * keuangan yang sama. Pola sama dengan RolePolicy.
     *
     * @throws AuthorizationException
     */
    protected function authorize(User $actor): void
    {
        if (! $actor->isSuperAdmin()) {
            throw new AuthorizationException('Hanya Super Admin yang dapat melihat ringkasan seluruh cabang.');
        }
    }

    /**
     * Satu query agregat untuk seluruh cabang sekaligus.
     *
     * Dikelompokkan per `school_id` **dan** `status`, sehingga yang kembali
     * paling banyak empat baris per cabang — bukan satu query per cabang, dan
     * bukan seluruh `student_fees` dimuat ke PHP. Jumlah query karena itu tetap
     * dua berapa pun banyaknya cabang maupun tagihan.
     *
     * `SUM`, `COUNT`, dan `GROUP BY` ditulis apa adanya karena ketiganya
     * berlaku sama di MySQL dan SQLite; tidak ada ekspresi spesifik driver.
     *
     * @return array<int, array<string, array{amount: string, amount_paid: string, count: int}>>
     */
    protected function aggregateBySchool(?string $academicYear, ?string $period): array
    {
        $rows = StudentFee::query()
            // Satu-satunya pelepasan SchoolScope pada jalur baca dashboard, dan
            // hanya setelah authorize() memastikan pelakunya Super Admin.
            ->withoutGlobalScope(SchoolScope::class)
            ->when($period !== null, fn (Builder $query) => $query->where('period', $period))
            ->when(
                $academicYear !== null,
                // Tahun ajaran adalah baris milik masing-masing cabang, jadi
                // yang dicocokkan adalah namanya (butir 92).
                fn (Builder $query) => $query->whereHas(
                    'academicYear',
                    fn (Builder $inner) => $inner
                        ->withoutGlobalScope(SchoolScope::class)
                        ->where('name', $academicYear),
                ),
            )
            ->groupBy('school_id', 'status')
            ->selectRaw('school_id, status, SUM(amount) as total_amount, SUM(amount_paid) as total_paid, COUNT(*) as fee_count')
            ->get();

        $aggregates = [];

        foreach ($rows as $row) {
            $status = $row->status instanceof StudentFeeStatus
                ? $row->status->value
                : (string) $row->status;

            $aggregates[(int) $row->school_id][$status] = [
                'amount' => $this->decimal($row->getAttribute('total_amount')),
                'amount_paid' => $this->decimal($row->getAttribute('total_paid')),
                'count' => (int) $row->getAttribute('fee_count'),
            ];
        }

        return $aggregates;
    }

    /**
     * @return Collection<int, School>
     */
    protected function schools()
    {
        return School::query()
            ->select(['id', 'code', 'name', 'is_active'])
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<string, array{amount: string, amount_paid: string, count: int}>  $byStatus
     * @return array<string, mixed>
     */
    protected function row(School $school, array $byStatus): array
    {
        $totalBilled = '0.00';
        $totalCollected = '0.00';
        $billableCount = 0;

        foreach ($byStatus as $status => $totals) {
            // "Total tagihan" adalah nominal yang pernah diterbitkan, termasuk
            // yang kemudian dibebaskan: recordnya nyata dan nominalnya pernah
            // ditagihkan (butir 90).
            $totalBilled = bcadd($totalBilled, $totals['amount'], self::SCALE);
            $totalCollected = bcadd($totalCollected, $totals['amount_paid'], self::SCALE);

            if (in_array($status, self::BILLABLE_STATUSES, true)) {
                $billableCount += $totals['count'];
            }
        }

        $paidCount = $byStatus[StudentFeeStatus::Paid->value]['count'] ?? 0;

        return [
            'school_id' => (int) $school->getKey(),
            'school_code' => (string) $school->code,
            'school_name' => (string) $school->name,
            'is_active' => (bool) $school->is_active,
            'total_billed' => $totalBilled,
            'total_collected' => $totalCollected,
            'paid_count' => $paidCount,
            'billable_count' => $billableCount,
            'paid_percentage' => $this->percentage($paidCount, $billableCount),
        ];
    }

    /**
     * "Persentase lunas" — proporsi tagihan berstatus PAID terhadap tagihan
     * yang masih merupakan kewajiban pembayaran.
     *
     * Blueprint tidak memberi rumusnya; ini keputusan implementasi yang
     * didokumentasikan (butir 91). Yang **tidak** dipakai adalah
     * `amount_paid / amount` — itu tingkat penagihan (collection rate), bukan
     * proporsi tagihan yang lunas, dan keduanya dapat jauh berbeda ketika
     * banyak tagihan dicicil.
     *
     * Penyebut nol berarti tidak ada tagihan yang perlu dilunasi, bukan
     * kegagalan menagih.
     */
    protected function percentage(int $paidCount, int $billableCount): string
    {
        if ($billableCount <= 0) {
            return '0.00';
        }

        return number_format($paidCount / $billableCount * 100, self::SCALE, '.', '');
    }

    /**
     * @throws ValidationException
     */
    protected function normalizePeriod(?string $period): ?string
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

    protected function normalizeAcademicYear(?string $academicYear): ?string
    {
        $academicYear = is_string($academicYear) ? trim($academicYear) : '';

        return $academicYear === '' ? null : $academicYear;
    }

    protected function decimal(mixed $value): string
    {
        return number_format((float) $value, self::SCALE, '.', '');
    }
}
