<?php

namespace App\Filament\Resources\SiteBlockResource\Pages;

use App\Filament\Resources\SiteBlockResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageSiteBlocks extends ManageRecords
{
    protected static string $resource = SiteBlockResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label(__('Tambah Blok Isi')),
        ];
    }
}
