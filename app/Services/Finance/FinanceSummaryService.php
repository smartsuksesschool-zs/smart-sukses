<?php

namespace App\Services\Finance;

use App\Enums\TransactionType;
use App\Models\Payment;
use App\Models\Scopes\SchoolScope;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * KAS-02 — "ringkasan keuangan bulanan"; dashboardnya menampilkan "saldo kas,
 * total penerimaan SPP bulan ini, dan total pengeluaran bulan ini", ditambah
 * "grafik tren 6 bulan terakhir".
 *
 * Satu-satunya tempat keempat angka itu dihitung. Halaman Filament hanya
 * memilih cabang dan periode; rumusnya tidak disalin ke mana pun, sehingga
 * endpoint `GET /finance/summary` nanti (butir 84) memakai sumber yang sama
 * dan tidak dapat menghasilkan angka berbeda.
 *
 * `payments` dan `transactions` tetap dua jalur terpisah (butir 75).
 * Penerimaan SPP dibaca dari `payments`, saldo dan pengeluaran dari
 * `transactions`, dan tidak ada satu pun baris yang dihitung dua kali.
 */
class FinanceSummaryService
{
    /** "Grafik tren 6 bulan terakhir" — bulan terpilih dan lima sebelumnya. */
    public const TREND_MONTHS = 6;

    /** Mengikuti DECIMAL(12,2) pada ERD. */
    protected const SCALE = 2;

    /**
     * Ringkasan keuangan satu cabang untuk satu periode `YYYY-MM`.
     *
     * @return array{
     *     period: string,
     *     period_start: string,
     *     period_end: string,
     *     cash_balance: string,
     *     spp_received: string,
     *     expenses: string,
     *     trend: array<int, array{period: string, label: string, income: string, expense: string}>
     * }
     *
     * @throws ValidationException
     */
    public function summarize(int $schoolId, string $period): array
    {
        $start = $this->periodStart($period);
        $end = $start->endOfMonth();

        return [
            'period' => $start->format('Y-m'),
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'cash_balance' => $this->cashBalance($schoolId, $end),
            'spp_received' => $this->sppReceived($schoolId, $start, $end),
            'expenses' => $this->expenses($schoolId, $start, $end),
            'trend' => $this->trend($schoolId, $start),
        ];
    }

    /**
     * Saldo kas: posisi sampai akhir periode terpilih, bukan pergerakan kas
     * pada bulan itu saja.
     *
     * Dasarnya ada pada kalimat AC-nya sendiri: keterangan "bulan ini" melekat
     * pada *penerimaan SPP* dan *pengeluaran*, tetapi **tidak** pada "saldo
     * kas". Saldo memang bukan angka bulanan — kas yang tersisa dari bulan
     * sebelumnya tetap ada di brankas. Tanggal potongnya sendiri tidak
     * ditetapkan dokumen; yang dipakai adalah akhir periode terpilih, sehingga
     * memilih bulan lampau menampilkan saldo sebagaimana adanya saat itu.
     * Lihat docs/implementation-notes.md butir 82.
     *
     * Hanya `transactions` yang dihitung. Penerimaan SPP tidak ditambahkan ke
     * sini: blueprint tidak menjelaskan kapan uang SPP masuk buku kas, dan
     * menjumlahkannya berarti mengarang aturan rekonsiliasi (butir 75).
     */
    protected function cashBalance(int $schoolId, CarbonImmutable $until): string
    {
        $totals = $this->transactionTotals(
            $schoolId,
            fn ($query) => $query->whereDate('transaction_date', '<=', $until->toDateString()),
        );

        return bcsub(
            $totals[TransactionType::Income->value],
            $totals[TransactionType::Expense->value],
            self::SCALE,
        );
    }

    /**
     * "Total penerimaan SPP bulan ini" — uang yang benar-benar tercatat di
     * `payments`, disaring berdasarkan `payment_date`.
     *
     * `student_fees.amount_paid` sengaja tidak dipakai walaupun angkanya sama:
     * ia kolom ringkasan posisi tagihan, bukan riwayat kapan uangnya diterima,
     * sehingga tidak dapat disaring per bulan. Tagihan berstatus WAIVED tidak
     * punya baris `payments` sama sekali dan karena itu tidak pernah muncul di
     * sini; tagihan PARTIAL menyumbang persis cicilan yang sudah masuk.
     */
    protected function sppReceived(int $schoolId, CarbonImmutable $start, CarbonImmutable $end): string
    {
        $sum = Payment::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)
            ->whereDate('payment_date', '>=', $start->toDateString())
            ->whereDate('payment_date', '<=', $end->toDateString())
            ->sum('amount_paid');

        return $this->decimal($sum);
    }

    /**
     * "Total pengeluaran bulan ini" — `transactions` bertipe EXPENSE dengan
     * `transaction_date` di dalam periode.
     */
    protected function expenses(int $schoolId, CarbonImmutable $start, CarbonImmutable $end): string
    {
        $totals = $this->transactionTotals(
            $schoolId,
            fn ($query) => $this->withinDates($query, 'transaction_date', $start, $end),
        );

        return $totals[TransactionType::Expense->value];
    }

    /**
     * "Grafik tren 6 bulan terakhir": bulan terpilih dan lima bulan
     * sebelumnya, berurutan lama → baru.
     *
     * Serinya sengaja hanya dua, dan keduanya benar-benar ada datanya:
     * pemasukan dan pengeluaran `transactions` per bulan. Dokumen tidak
     * menetapkan seri apa pun, jadi tidak ada proyeksi, pertumbuhan persen,
     * maupun garis saldo yang dikarang (butir 83). Bulan tanpa transaksi
     * bernilai 0, bukan hilang dari sumbu.
     *
     * @return array<int, array{period: string, label: string, income: string, expense: string}>
     */
    protected function trend(int $schoolId, CarbonImmutable $selected): array
    {
        $months = [];

        for ($offset = self::TREND_MONTHS - 1; $offset >= 0; $offset--) {
            $month = $selected->subMonths($offset);
            $totals = $this->transactionTotals(
                $schoolId,
                fn ($query) => $this->withinDates(
                    $query,
                    'transaction_date',
                    $month->startOfMonth(),
                    $month->endOfMonth(),
                ),
            );

            $months[] = [
                'period' => $month->format('Y-m'),
                'label' => $month->translatedFormat('M Y'),
                'income' => $totals[TransactionType::Income->value],
                'expense' => $totals[TransactionType::Expense->value],
            ];
        }

        return $months;
    }

    /**
     * Menyaring satu kolom tanggal ke sebuah rentang, inklusif di kedua ujung.
     *
     * `whereDate()`, bukan `whereBetween()`. Kolom `transaction_date` dan
     * `payment_date` bertipe DATE di ERD, tetapi cast `date` Eloquent
     * menyimpannya sebagai `Y-m-d H:i:s` — sehingga baris tanggal 31 tersimpan
     * sebagai `2026-08-31 00:00:00` dan perbandingan mentah terhadap
     * `'2026-08-31'` menganggapnya **lebih besar**. Akibatnya seluruh transaksi
     * pada hari terakhir bulan diam-diam hilang dari total bulan itu.
     * `whereDate()` membungkus kolomnya sehingga yang dibandingkan benar-benar
     * tanggal, dan itu pula yang sudah dipakai scope `betweenDates` pada model
     * (butir 139).
     */
    protected function withinDates(Builder $query, string $column, CarbonImmutable $from, CarbonImmutable $until): Builder
    {
        return $query
            ->whereDate($column, '>=', $from->toDateString())
            ->whereDate($column, '<=', $until->toDateString());
    }

    /**
     * Satu query agregat per rentang: `SUM(amount)` dikelompokkan per `type`,
     * sehingga yang kembali paling banyak dua baris — bukan seluruh transaksi
     * cabang itu. Jumlah query karena itu tetap sama berapa pun banyaknya
     * record.
     *
     * `SUM()` dan `GROUP BY` ditulis apa adanya karena keduanya berlaku sama di
     * MySQL maupun SQLite. Ekstraksi bulan yang berbeda antar driver
     * (`DATE_FORMAT` vs `strftime`) sengaja dihindari: satu query agregat per
     * bulan tren lebih sederhana daripada memasang percabangan driver yang
     * belum pernah dipakai project ini.
     *
     * @param  \Closure(Builder): Builder  $constrain
     * @return array<string, string>
     */
    protected function transactionTotals(int $schoolId, \Closure $constrain): array
    {
        $query = Transaction::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId);

        $rows = $constrain($query)
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return [
            TransactionType::Income->value => $this->decimal($rows[TransactionType::Income->value] ?? 0),
            TransactionType::Expense->value => $this->decimal($rows[TransactionType::Expense->value] ?? 0),
        ];
    }

    /**
     * Awal bulan dari periode `YYYY-MM`.
     *
     * @throws ValidationException
     */
    public function periodStart(string $period): CarbonImmutable
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw ValidationException::withMessages([
                'period' => 'Periode harus berformat YYYY-MM, misalnya 2026-08.',
            ]);
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $period.'-01')->startOfDay();
    }

    /**
     * Hasil `SUM()` kembali sebagai string atau float tergantung driver;
     * dinormalkan ke dua desimal supaya seluruh perbandingan dan pengurangan
     * berikutnya berjalan di ranah string bcmath, bukan floating point
     * (butir 58).
     */
    protected function decimal(mixed $value): string
    {
        return number_format((float) $value, self::SCALE, '.', '');
    }
}
