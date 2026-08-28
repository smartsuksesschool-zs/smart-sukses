<?php

namespace App\Filament\Resources\ReportCardResource\Pages;

use App\Filament\Resources\ReportCardResource;
use App\Models\ReportCard;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * API 4.8 — GET /report-cards/{id}: detail rapor + nilai final per mapel.
 */
class ViewReportCard extends ViewRecord
{
    protected static string $resource = ReportCardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('pdf')
                ->label(__('Unduh PDF'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->authorize('downloadPdf')
                ->action(fn (ReportCard $record): StreamedResponse => ReportCardResource::streamPdf($record)),
        ];
    }
}
