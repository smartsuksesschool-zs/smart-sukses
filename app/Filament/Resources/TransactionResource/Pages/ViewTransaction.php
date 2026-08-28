<?php

namespace App\Filament\Resources\TransactionResource\Pages;

use App\Filament\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\Finance\TransactionRecorder;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Detail satu transaksi kas, beserta unduhan buktinya yang terotorisasi.
 *
 * Tanpa DeleteAction: mekanisme "soft delete" pada API 4.9 belum punya kolom
 * maupun penjelasan di blueprint (butir 74).
 */
class ViewTransaction extends ViewRecord
{
    protected static string $resource = TransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('downloadProof')
                ->label(__('Unduh Bukti'))
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (Transaction $record): bool => $record->hasDownloadableProof()
                    && (Auth::user()?->can('downloadProof', $record) ?? false))
                ->action(fn (Transaction $record): StreamedResponse => Storage::disk(TransactionRecorder::PROOF_DISK)
                    ->download($record->proof_url, TransactionResource::proofFilenameFor($record))),

            Actions\EditAction::make(),
        ];
    }
}
