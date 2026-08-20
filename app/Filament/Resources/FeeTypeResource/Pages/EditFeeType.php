<?php

namespace App\Filament\Resources\FeeTypeResource\Pages;

use App\Filament\Resources\FeeTypeResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Tanpa DeleteAction — SPP-01 poin 2 menuntut penonaktifan, bukan penghapusan.
 */
class EditFeeType extends EditRecord
{
    protected static string $resource = FeeTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Cabang sebuah jenis tagihan tidak dapat dipindahkan: tagihan siswa yang
     * sudah terbit menunjuk ke record ini. Field-nya memang `disabledOn('edit')`
     * sehingga tidak ter-dehydrate, tetapi state Livewire tetap dapat dikirim
     * apa adanya — nilainya dibuang di sini.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['school_id']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
