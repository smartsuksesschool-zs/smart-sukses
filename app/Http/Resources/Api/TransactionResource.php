<?php

namespace App\Http\Resources\Api;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API 4.9.2 — /transactions. ERD 2.2 `transactions`.
 *
 * Nominal selalu positif; arah kasnya ada di `type` (butir 78). `proof_url`
 * tidak dikirim, dengan alasan yang sama seperti pada pembayaran (butir 118).
 *
 * @mixin Transaction
 */
class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'category' => $this->category,
            'amount' => (string) $this->amount,
            'description' => $this->description,
            'reference_number' => $this->reference_number,
            'transaction_date' => $this->transaction_date?->toDateString(),
            'has_proof' => filled($this->proof_url),
            'created_by' => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy?->id,
                'name' => $this->createdBy?->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
