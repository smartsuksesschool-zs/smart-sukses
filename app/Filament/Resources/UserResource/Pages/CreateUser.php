<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Pengguna School Level hanya boleh membuat akun di cabangnya sendiri;
     * field school_id tidak tampil untuk mereka sehingga diisi di sini.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! Auth::user()?->isSuperAdmin()) {
            $data['school_id'] = Auth::user()?->school_id;
        }

        return $data;
    }
}
