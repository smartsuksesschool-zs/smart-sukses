<?php

namespace App\Filament\Resources\AuditLogResource\Pages;

use App\Filament\Resources\AuditLogResource;
use Filament\Resources\Pages\ListRecords;

class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    /**
     * Tanpa aksi header: tidak ada baris audit yang dibuat manusia.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
