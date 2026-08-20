<?php

namespace App\Filament\Resources\TransactionResource\Pages;

use App\Filament\Resources\TransactionResource;
use App\Models\User;
use App\Services\Finance\TransactionRecorder;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * API 4.9 — PUT /transactions/{id}.
 *
 * Tanpa DeleteAction: "soft delete" pada API 4.9 belum punya kolom maupun
 * penjelasan mekanisme di blueprint (docs/implementation-notes.md butir 74).
 *
 * `created_by` dan `school_id` tidak ikut berubah — TransactionRecorder::update()
 * memang tidak menulis keduanya. Siapa yang mengubah tercatat di `audit_logs`,
 * karena `transactions` tidak punya `updated_at`.
 */
class EditTransaction extends EditRecord
{
    protected static string $resource = TransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new \RuntimeException('Pengguna belum terautentikasi.');
        }

        return app(TransactionRecorder::class)->update($record->getKey(), $data, $user);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
