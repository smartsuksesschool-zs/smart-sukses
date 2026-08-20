<?php

namespace App\Filament\Resources\TransactionResource\Pages;

use App\Filament\Resources\TransactionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

/**
 * API 4.9 — GET /transactions.
 */
class ListTransactions extends ListRecords
{
    protected static string $resource = TransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Catat Transaksi'),
        ];
    }
}
