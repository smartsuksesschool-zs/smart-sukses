<?php

namespace App\Filament\Resources\SchoolResource\Pages;

use App\Filament\Resources\SchoolResource;
use App\Filament\Resources\SchoolResource\Widgets\SchoolStatsOverview;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * API 4.3 — GET /admin/schools/{id}: detail lengkap satu cabang termasuk
 * konfigurasi white-label.
 */
class ViewSchool extends ViewRecord
{
    protected static string $resource = SchoolResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    /**
     * API 4.3 — GET /admin/schools/{id}/stats, ditampilkan di halaman yang
     * sudah ada alih-alih sebagai dashboard baru.
     *
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            SchoolStatsOverview::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return ['record' => $this->record];
    }
}
