<?php

namespace App\Http\Resources\Api;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API 4.9.1 — /payments. ERD 2.2 `payments`.
 *
 * `proof_url` **tidak pernah** dikirim. Berkasnya ada di disk privat justru
 * supaya jalurnya tidak beredar (butir 63); yang dibutuhkan klien hanyalah
 * apakah buktinya ada, dan itu cukup diwakili boolean (butir 118).
 *
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_fee_id' => $this->student_fee_id,
            'student_id' => $this->student_id,
            'payment_method' => $this->payment_method->value,
            'payment_method_label' => $this->payment_method->label(),
            'amount_paid' => (string) $this->amount_paid,
            'reference_number' => $this->reference_number,
            'payment_date' => $this->payment_date?->toDateString(),
            'has_proof' => filled($this->proof_url),
            'notes' => $this->notes,
            'received_by' => $this->whenLoaded('receivedBy', fn () => [
                'id' => $this->receivedBy?->id,
                'name' => $this->receivedBy?->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
