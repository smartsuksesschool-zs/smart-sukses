<?php

namespace App\Http\Controllers\Api;

use App\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceSummaryService;
use App\Services\Finance\SppReportService;
use App\Support\Api\ApiResponse;
use App\Support\Finance\SchoolContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API 4.9.2 — GET /finance/summary dan GET /finance/spp-report.
 *
 * Keduanya berlabel Auth Level "Auth" pada API map, tetapi "wajib token" bukan
 * berarti "boleh dibaca semua peran yang punya token". PRD 1.1.2 baris
 * **Laporan Keuangan** yang menentukan siapa: SUPER_ADMIN ✅, SCHOOL_ADMIN ✅,
 * KEPALA ⭕, BENDAHARA ✅, GURU/WALI ❌, SISWA ❌, ORTU ❌. Guru tidak
 * mendapat laporan keuangan hanya karena ia berhasil login (butir 137).
 *
 * Keduanya juga laporan **satu cabang**. Super Admin karena itu wajib memilih
 * cabang persis seperti pada ekspor, dan tidak pernah diam-diam menerima
 * gabungan seluruh cabang — yang lintas cabang adalah KAS-03, domain yang
 * berbeda.
 */
class FinanceReportController extends Controller
{
    /**
     * GET /finance/summary — "Ringkasan keuangan: total income, expense, saldo
     * per bulan. Filter: year, month".
     *
     * `year` dan `month` adalah kontrak publiknya dan dipakai apa adanya.
     * Service-nya bekerja dengan `period` YYYY-MM di dalam, tetapi nama
     * internal tidak pernah menjadi kontrak publik (butir 123) — pemetaannya
     * terjadi di service, bukan dengan meminta pemanggil mengirim `period`.
     *
     * @throws AuthorizationException
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorizeReport($request);

        $filters = $request->validate([
            'school_id' => ['nullable', 'integer'],
            // Batas tahunnya sengaja lebar tetapi tidak tak terhingga: di luar
            // rentang ini nilainya bukan periode yang keliru melainkan input
            // yang salah bentuk.
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $summary = app(FinanceSummaryService::class)->monthlySummary(
            SchoolContext::resolve($filters['school_id'] ?? null, $request->user()),
            (int) $filters['year'],
            (int) $filters['month'],
        );

        return ApiResponse::success($summary);
    }

    /**
     * GET /finance/spp-report — "Laporan SPP: total tagihan, terkumpul,
     * tunggakan per periode".
     *
     * API map tidak menyebutkan satu pun filter untuk endpoint ini. Yang
     * dipakai adalah `period` YYYY-MM opsional — nama dan bentuk yang sudah
     * menjadi kontrak `student_fees` pada endpoint tetangganya, bukan istilah
     * baru. Tanpa `period`, seluruh periode dikembalikan berurutan dari yang
     * terbaru dengan batas yang disebutkan pada respons (butir 136).
     *
     * @throws AuthorizationException
     */
    public function sppReport(Request $request): JsonResponse
    {
        $this->authorizeReport($request);

        $filters = $request->validate([
            'school_id' => ['nullable', 'integer'],
            'period' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        $report = app(SppReportService::class)->report(
            SchoolContext::resolve($filters['school_id'] ?? null, $request->user()),
            $filters['period'] ?? null,
        );

        return ApiResponse::success($report);
    }

    /**
     * Laporan keuangan dibaca oleh peran yang memegang `financial_report.view`
     * — ability yang sama dengan halaman Laporan Keuangan di panel, sehingga
     * API dan panel tidak dapat berbeda pendapat tentang siapa yang berhak.
     *
     * @throws AuthorizationException
     */
    protected function authorizeReport(Request $request): void
    {
        if ($request->user()?->can(PermissionName::FinancialReportView->value) !== true) {
            throw new AuthorizationException('Anda tidak berwenang membaca laporan keuangan.');
        }
    }
}
