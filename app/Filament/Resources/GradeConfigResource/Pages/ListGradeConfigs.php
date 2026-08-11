<?php

namespace App\Filament\Resources\GradeConfigResource\Pages;

use App\Filament\Resources\GradeConfigResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGradeConfigs extends ListRecords
{
    protected static string $resource = GradeConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Konfigurasi Baru')];
    }
}
