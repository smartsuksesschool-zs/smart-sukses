<?php

namespace App\Http\Controllers\Api;

use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\TransactionResource;
use App\Models\Transaction;
use App\Services\Finance\TransactionRecorder;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * API 4.9.2 — /transactions (KAS-01).
 *
 * `DELETE /transactions/{id}` sengaja tidak dibuat: ERD tidak memuat kolom
 * untuk "soft delete" yang disebut API map, dan mekanismenya belum dijelaskan
 * di mana pun (butir 74). Rutenya pun tidak didaftarkan sebagai placeholder.
 */
class TransactionController extends Controller
{
    /**
     * GET /transactions — "daftar transaksi kas. Filter: type, category,
     * date_from, date_to".
     *
     * Nama filternya `date_to` persis seperti blueprint. Internal boleh memakai
     * nama lain, tetapi kontrak publiknya `date_to` (butir 123).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Transaction::class);

        $filters = $request->validate([
            'type' => ['nullable', Rule::enum(TransactionType::class)],
            'category' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Transaction::query()
            ->with('createdBy')
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['category'] ?? null, fn ($q, $v) => $q->where('category', $v))
            ->betweenDates($filters['date_from'] ?? null, $filters['date_to'] ?? null)
            ->orderByDesc('transaction_date')
            ->orderByDesc('id');

        return ApiResponse::paginated(
            $query->paginate($filters['per_page'] ?? 25),
            TransactionResource::class,
        );
    }

    /**
     * POST /transactions — "catat transaksi baru (income atau expense)".
     */
    public function store(Request $request): JsonResponse
    {
        $transaction = app(TransactionRecorder::class)
            ->record($this->payload($request), $request->user());

        $transaction->loadMissing('createdBy');

        return ApiResponse::success(
            (new TransactionResource($transaction))->resolve(),
            'Transaksi kas tercatat.',
            201,
        );
    }

    /**
     * PUT /transactions/{id} — "edit transaksi".
     *
     * `created_by` dan `school_id` tidak ikut berubah; siapa yang mengubah
     * tercatat di `audit_logs` karena `transactions` tidak punya `updated_at`
     * (butir 79).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $transaction = app(TransactionRecorder::class)
            ->update($id, $this->payload($request), $request->user());

        $transaction->loadMissing('createdBy');

        return ApiResponse::success(
            (new TransactionResource($transaction))->resolve(),
            'Transaksi kas diperbarui.',
        );
    }

    /**
     * Validasi bentuk; invarian nominal, cabang, dan pencatatnya tetap
     * ditegakkan ulang TransactionRecorder.
     *
     * @return array<string, mixed>
     */
    protected function payload(Request $request): array
    {
        return $request->validate([
            'school_id' => ['nullable', 'integer'],
            'type' => ['required', Rule::enum(TransactionType::class)],
            'category' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric'],
            'transaction_date' => ['required', 'date'],
            // Aturan validasi KAS-01: keduanya wajib walaupun kolomnya NULL di
            // ERD (butir 81).
            'description' => ['required', 'string'],
            'reference_number' => ['required', 'string', 'max:100'],
        ]);
    }
}
