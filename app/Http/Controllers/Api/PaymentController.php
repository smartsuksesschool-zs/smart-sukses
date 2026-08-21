<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\PaymentResource;
use App\Models\Payment;
use App\Services\Finance\PaymentProofAttacher;
use App\Services\Finance\PaymentRecorder;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * API 4.9.1 — /payments (SPP-03).
 *
 * Seluruh aturan pencatatan tetap di PaymentRecorder: cabang, siswa, dan
 * pencatat diturunkan server, cicilan diakumulasi di bawah row lock, kelebihan
 * bayar ditolak, tagihan WAIVED menolak pembayaran, dan PAYMENT_GATEWAY tidak
 * dapat dicatat manual. Controller tidak mengulangi satu pun dari itu.
 */
class PaymentController extends Controller
{
    /**
     * GET /payments — "riwayat semua pembayaran. Filter: student_id, period,
     * method".
     *
     * `period` disaring lewat `student_fees.period`: `payments` tidak punya
     * kolom periode, dan menambahkannya berarti menduplikasi data yang sudah
     * dimiliki tagihannya (butir 122).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $filters = $request->validate([
            'student_id' => ['nullable', 'integer'],
            'period' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Payment::query()
            ->with('receivedBy')
            ->when($filters['student_id'] ?? null, fn ($q, $v) => $q->where('student_id', (int) $v))
            ->when($filters['method'] ?? null, fn ($q, $v) => $q->where('payment_method', $v))
            ->when(
                $filters['period'] ?? null,
                fn (Builder $q, $v) => $q->whereHas('studentFee', fn (Builder $inner) => $inner->forPeriod($v)),
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return ApiResponse::paginated(
            $query->paginate($filters['per_page'] ?? 25),
            PaymentResource::class,
        );
    }

    /**
     * POST /payments — "catat pembayaran baru (body: student_fee_id, amount,
     * method, date, reference)".
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_fee_id' => ['required', 'integer'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'amount' => ['required', 'numeric'],
            'payment_date' => ['required', 'date'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);

        $payment = app(PaymentRecorder::class)->record(
            (int) $data['student_fee_id'],
            $data,
            $request->user(),
        );

        $payment->loadMissing('receivedBy');

        return ApiResponse::success(
            (new PaymentResource($payment))->resolve(),
            'Pembayaran tercatat.',
            201,
        );
    }

    /**
     * POST /payments/{id}/proof — "upload bukti pembayaran (multipart)".
     *
     * Melengkapi pembayaran yang buktinya belum ada. Bukti yang sudah tercatat
     * tidak dapat diganti lewat jalur ini, dan tidak ada field pembayaran lain
     * yang ikut berubah (butir 119).
     */
    public function attachProof(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'proof' => ['required', 'file'],
        ]);

        $payment = app(PaymentProofAttacher::class)->attach(
            $id,
            $request->file('proof'),
            $request->user(),
        );

        $payment->loadMissing('receivedBy');

        return ApiResponse::success(
            (new PaymentResource($payment))->resolve(),
            'Bukti pembayaran dilampirkan.',
        );
    }
}
