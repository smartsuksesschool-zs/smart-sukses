<?php

namespace App\Http\Controllers\Api;

use App\Enums\StudentFeeStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\StudentFeeResource;
use App\Jobs\GenerateStudentFees;
use App\Models\FeeType;
use App\Models\StudentFee;
use App\Services\Finance\StudentFeeGenerator;
use App\Services\Finance\StudentFeeWaiver;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * API 4.9.1 — /student-fees (SPP-02, SPP-03, WAIVED).
 *
 * Controller tipis: seluruh aturan bisnisnya ada di StudentFeeGenerator dan
 * StudentFeeWaiver yang sama dengan yang dipakai panel.
 */
class StudentFeeController extends Controller
{
    /**
     * GET /student-fees — Auth.
     * "Filter: student_id, status, period, fee_type_id."
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StudentFee::class);

        $filters = $request->validate([
            'student_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(StudentFeeStatus::class)],
            'period' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'fee_type_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = StudentFee::query()
            ->with(['student', 'feeType'])
            ->when($filters['student_id'] ?? null, fn ($q, $v) => $q->forStudent((int) $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['period'] ?? null, fn ($q, $v) => $q->forPeriod($v))
            ->when($filters['fee_type_id'] ?? null, fn ($q, $v) => $q->where('fee_type_id', (int) $v))
            ->orderByDesc('due_date')
            ->orderByDesc('id');

        return ApiResponse::paginated(
            $query->paginate($filters['per_page'] ?? 25),
            StudentFeeResource::class,
        );
    }

    /**
     * GET /student-fees/{id} — Auth: "detail satu tagihan + riwayat pembayaran".
     *
     * Tagihan cabang lain tersaring SchoolScope sehingga menjadi 404, bukan
     * 403 (butir 116).
     */
    public function show(int $id): JsonResponse
    {
        $studentFee = StudentFee::query()
            ->withBillingDetail()
            ->findOrFail($id);

        $this->authorize('view', $studentFee);

        return ApiResponse::success((new StudentFeeResource($studentFee))->resolve());
    }

    /**
     * POST /student-fees/generate-bulk — Admin.
     * "Body: fee_type_id, period, due_date."
     *
     * SPP-02 poin 3 mewajibkan pratinjau sebelum konfirmasi, sedangkan API map
     * tidak memuat endpoint pratinjau. Konfliknya diselesaikan tanpa mengarang
     * endpoint dan tanpa menghapus jaminannya: pratinjau dihitung di sisi
     * server dari sumber logika yang sama, lalu dikembalikan bersama respons —
     * pemanggil selalu menerima daftar siswa yang akan ditagih. Lihat
     * butir 114.
     */
    public function generateBulk(Request $request): JsonResponse
    {
        $this->authorize('create', StudentFee::class);

        $data = $request->validate([
            'fee_type_id' => ['required', 'integer'],
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'due_date' => ['required', 'date'],
        ]);

        $feeType = FeeType::query()->find($data['fee_type_id']);

        if ($feeType === null || ! $feeType->is_active) {
            throw ValidationException::withMessages([
                'fee_type_id' => 'Jenis tagihan tidak ditemukan atau sudah tidak aktif.',
            ]);
        }

        $preview = app(StudentFeeGenerator::class)->preview($feeType, $data['period']);

        GenerateStudentFees::dispatch(
            (int) $feeType->school_id,
            (int) $feeType->getKey(),
            $data['period'],
            $data['due_date'],
        );

        return ApiResponse::success([
            'queued' => true,
            'fee_type_id' => (int) $feeType->getKey(),
            'period' => $data['period'],
            'due_date' => $data['due_date'],
            'preview' => [
                'will_be_billed' => $preview['targets']->count(),
                'already_billed' => $preview['skipped']->count(),
                'students' => $preview['targets']
                    ->map(fn ($student) => [
                        'id' => $student->getKey(),
                        'nis' => $student->nis,
                        'full_name' => $student->full_name,
                    ])
                    ->all(),
            ],
        ], 'Penerbitan tagihan diantrekan.', 202);
    }

    /**
     * PATCH /student-fees/{id}/waive — Admin.
     * "Bebaskan tagihan dengan alasan (status → WAIVED)."
     */
    public function waive(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'waive_reason' => ['required', 'string', 'max:'.StudentFeeWaiver::REASON_MAX_LENGTH],
        ]);

        $studentFee = app(StudentFeeWaiver::class)
            ->waive($id, $data['waive_reason'], $request->user());

        $studentFee->loadMissing(['student', 'feeType']);

        return ApiResponse::success(
            (new StudentFeeResource($studentFee))->resolve(),
            'Tagihan dibebaskan.',
        );
    }
}
