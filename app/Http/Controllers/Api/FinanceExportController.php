<?php

namespace App\Http\Controllers\Api;

use App\Enums\StudentFeeStatus;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Services\Finance\CashLedgerExporter;
use App\Services\Finance\StudentFeeReportExporter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * API 4.9 — GET /student-fees/export (SPP-05) dan GET /finance/export.
 *
 * Keduanya memanggil exporter yang sama dengan panel, sehingga berkas yang
 * diunduh lewat API identik dengan yang diunduh operator — termasuk pagar
 * kewenangan, penguncian satu cabang, dan penamaan berkasnya.
 */
class FinanceExportController extends Controller
{
    /**
     * GET /student-fees/export — Admin.
     * "Export laporan tagihan ke Excel. Filter: period, class_id, status."
     */
    public function studentFees(Request $request): BinaryFileResponse
    {
        $filters = $request->validate([
            'school_id' => ['nullable', 'integer'],
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'class_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(StudentFeeStatus::class)],
        ]);

        return app(StudentFeeReportExporter::class)->download($request->user(), $filters);
    }

    /**
     * GET /finance/export — Admin: "export laporan keuangan ke Excel".
     *
     * API map tidak merinci filternya. Yang dipakai adalah kontrak filter
     * `GET /transactions` — `type`, `category`, `date_from`, `date_to` — karena
     * itulah yang terdekat dengan dokumen untuk domain yang sama. Rentang
     * tanggalnya wajib; ini kontrak implementasi, bukan klaim blueprint
     * (butir 109, 124).
     *
     * `date_to` publik dipetakan ke `date_until` internal exporter — nama
     * internal tidak pernah menjadi kontrak publik (butir 123).
     */
    public function cashLedger(Request $request): BinaryFileResponse
    {
        $filters = $request->validate([
            'school_id' => ['nullable', 'integer'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'type' => ['nullable', Rule::enum(TransactionType::class)],
            'category' => ['nullable', 'string', 'max:100'],
        ]);

        return app(CashLedgerExporter::class)->download($request->user(), [
            'school_id' => $filters['school_id'] ?? null,
            'date_from' => $filters['date_from'],
            'date_until' => $filters['date_to'],
            'type' => $filters['type'] ?? null,
            'category' => $filters['category'] ?? null,
        ]);
    }
}
