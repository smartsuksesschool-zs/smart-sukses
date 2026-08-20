<?php

namespace App\Filament\Resources\TransactionResource\Pages;

use App\Filament\Resources\TransactionResource;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\TransactionRecorder;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Penulisannya diserahkan ke TransactionRecorder, bukan ke `CreateRecord`
 * bawaan: di sanalah `created_by` diambil dari sesi, cabang diturunkan, dan
 * jalur berkas bukti diperiksa. Form hanya mengumpulkan isian.
 */
class CreateTransaction extends CreateRecord
{
    protected static string $resource = TransactionResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new \RuntimeException('Pengguna belum terautentikasi.');
        }

        return app(TransactionRecorder::class)->record($data, $user);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        $record = $this->getRecord();

        return $record instanceof Transaction
            ? $record->type->label().' tercatat di buku kas'
            : 'Transaksi tercatat';
    }
}
