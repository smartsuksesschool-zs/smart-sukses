<?php

namespace App\Http\Resources\Api;

use App\Models\FeeType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API 4.9.1 — /fee-types. ERD 2.2 `fee_types`.
 *
 * @mixin FeeType
 */
class FeeTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Nominal dikirim sebagai string desimal, bukan float: presisi uang
            // tidak boleh bergantung pada representasi biner JSON di sisi klien
            // (butir 58).
            'amount' => (string) $this->amount,
            'frequency' => $this->frequency->value,
            'frequency_label' => $this->frequency->label(),
            'academic_year_id' => $this->academic_year_id,
            'description' => $this->description,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
