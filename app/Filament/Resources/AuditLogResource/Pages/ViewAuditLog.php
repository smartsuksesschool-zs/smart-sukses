<?php

namespace App\Filament\Resources\AuditLogResource\Pages;

use App\Filament\Resources\AuditLogResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAuditLog extends ViewRecord
{
    protected static string $resource = AuditLogResource::class;

    /**
     * Tanpa aksi sunting maupun hapus — baris audit imutabel.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
