<?php

namespace App\Http\Resources\Api;

use App\Models\StudentFee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API 4.9.1 — /student-fees dan /student-fees/{id} ("detail satu tagihan +
 * riwayat pembayaran").
 *
 * `remaining` memakai helper domain yang sama dengan layar dan berkas ekspor
 * (butir 71), sehingga tidak ada rumus sisa tagihan kedua yang dapat
 * menyimpang.
 *
 * @mixin StudentFee
 */
class StudentFeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student?->id,
                'nis' => $this->student?->nis,
                'full_name' => $this->student?->full_name,
            ]),
            'fee_type' => $this->whenLoaded('feeType', fn () => [
                'id' => $this->feeType?->id,
                'name' => $this->feeType?->name,
            ]),
            'academic_year_id' => $this->academic_year_id,
            'period' => $this->period,
            'amount' => (string) $this->amount,
            'amount_paid' => (string) $this->amount_paid,
            'remaining' => $this->remaining(),
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'waive_reason' => $this->waive_reason,
            // Riwayat pembayaran hanya menyertai endpoint detail; daftar
            // tagihan tidak memuatnya supaya tidak menarik seluruh cicilan
            // setiap siswa untuk ringkasan yang tidak menampilkannya.
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
