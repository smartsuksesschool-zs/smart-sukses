<?php

namespace App\Filament\Resources\StudentResource\Pages;

use App\Filament\Resources\StudentResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * SIS-01 — detail satu siswa, hanya baca.
 *
 * Record-nya diambil lewat `StudentResource::getEloquentQuery()`, sehingga
 * SchoolScope dan pagar kelas ajar guru berlaku persis seperti di daftarnya:
 * siswa cabang lain tidak ditemukan, bukan ditolak sesudah ditemukan.
 */
class ViewStudent extends ViewRecord
{
    protected static string $resource = StudentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->label(__('Ubah')),
        ];
    }
}
