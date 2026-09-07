<?php

namespace App\Filament\Resources\AccountClaimResource\Pages;

use App\Filament\Resources\AccountClaimResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAccountClaim extends ViewRecord
{
    protected static string $resource = AccountClaimResource::class;

    /**
     * Kedua keputusannya tersedia di sini juga, supaya admin tidak perlu
     * kembali ke daftar setelah membaca rinciannya — yang justru merupakan
     * satu-satunya tempat seluruh bahan keputusan tersedia.
     */
    protected function getHeaderActions(): array
    {
        return [
            AccountClaimResource::headerApproveAction(),
            AccountClaimResource::headerRejectAction(),
        ];
    }
}
