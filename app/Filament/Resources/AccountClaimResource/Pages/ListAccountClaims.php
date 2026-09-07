<?php

namespace App\Filament\Resources\AccountClaimResource\Pages;

use App\Filament\Resources\AccountClaimResource;
use Filament\Resources\Pages\ListRecords;

class ListAccountClaims extends ListRecords
{
    protected static string $resource = AccountClaimResource::class;

    /**
     * Tanpa aksi header: permintaan lahir dari alur Google publik, bukan dari
     * tangan admin.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
