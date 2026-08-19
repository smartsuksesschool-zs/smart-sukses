<?php

namespace App\Filament\Resources\SchoolResource\Pages;

use App\Filament\Resources\SchoolResource;
use Filament\Resources\Pages\EditRecord;

class EditSchool extends EditRecord
{
    protected static string $resource = SchoolResource::class;

    /**
     * Cabang tidak dapat dihapus (SchoolPolicy::delete) — seluruh data akademik,
     * keuangan, dan rapor menggantung padanya. Penonaktifan tersedia sebagai
     * aksi tersendiri di daftar cabang.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
