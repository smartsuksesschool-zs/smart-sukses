<?php

namespace App\Http\Resources\Api;

use App\Models\Payment;
use App\Models\StudentFee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * SPP-04 / API 4.11 — "Semua tagihan anak + status + riwayat bayar".
 *
 * Daftar-izin, dan yang paling penting adalah apa yang **tidak** ikut:
 * `proof_url` (berkas di disk privat), `received_by` (id akun bendahara),
 * `school_id`, dan catatan internal petugas. Orang tua membutuhkan tanggal,
 * jumlah, dan cara bayarnya — bukan jejak administrasi di baliknya
 * (butir 166).
 *
 * @mixin StudentFee
 */
class ChildFeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fee_type_name' => $this->feeType?->name,
            'period' => $this->period,
            'amount' => (string) $this->amount,
            'amount_paid' => (string) $this->amount_paid,
            // Sisa tagihan dari helper domain yang sudah ada, bukan
            // pengurangan yang ditulis ulang di sini (butir 166).
            'remaining' => $this->remaining(),
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            // Alasan pembebasan memang ditujukan untuk diketahui orang tua —
            // itu keringanan atas tagihan anaknya sendiri. Hanya muncul bila
            // tagihannya memang dibebaskan.
            'waive_reason' => $this->when($this->isWaived(), $this->waive_reason),
            'payments' => $this->payments
                ->sortByDesc('payment_date')
                ->values()
                ->map(fn (Payment $payment) => [
                    'id' => $payment->getKey(),
                    'payment_date' => $payment->payment_date?->toDateString(),
                    'amount_paid' => (string) $payment->amount_paid,
                    'method' => $payment->payment_method?->value,
                    'method_label' => $payment->payment_method?->label(),
                    'reference_number' => $payment->reference_number,
                ])
                ->all(),
        ];
    }
}
