<?php

namespace App\Filament\Resources\GradeConfigResource\Pages;

use App\Filament\Resources\GradeConfigResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Hanya konfigurasi berstatus DRAFT yang dapat dibuka di sini —
 * GradeConfigPolicy::update() menolak ACTIVE dan LOCKED.
 */
class EditGradeConfig extends EditRecord
{
    protected static string $resource = GradeConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['components'] = array_values($data['components'] ?? []);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
