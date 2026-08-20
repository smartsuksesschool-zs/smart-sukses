<?php

namespace App\Filament\Resources\StudentFeeResource\Pages;

use App\Filament\Resources\StudentFeeResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * API 4.9 — GET /student-fees/{id}: "detail satu tagihan + riwayat
 * pembayaran". Riwayatnya dirender PaymentsRelationManager.
 */
class ViewStudentFee extends ViewRecord
{
    protected static string $resource = StudentFeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            StudentFeeResource::recordPaymentPageAction()
                // Relation manager adalah komponen Livewire tersendiri: tanpa
                // sinyal ini tabel riwayatnya tetap menampilkan keadaan sebelum
                // pembayaran barusan sampai halaman dimuat ulang.
                ->after(fn () => $this->dispatch(StudentFeeResource::PAYMENT_RECORDED_EVENT)),

            StudentFeeResource::waivePageAction(),
        ];
    }
}
