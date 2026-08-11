<?php

namespace App\Filament\Resources\GradeResource\Pages;

use App\Filament\Resources\GradeResource;
use Filament\Resources\Pages\EditRecord;

/**
 * NILAI-01 poin 3 — penyuntingan ditolak GradePolicy::update() begitu rapor
 * siswa yang bersangkutan diterbitkan.
 */
class EditGrade extends EditRecord
{
    protected static string $resource = GradeResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
