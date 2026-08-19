<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Enums\AuditAction;
use App\Filament\Resources\RoleResource;
use App\Support\AuditLogger;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\Models\Role;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * `Role` dikecualikan dari listener otomatis agar seeder tidak melahirkan
     * puluhan baris audit (butir 45), sehingga peran yang dibuat lewat panel
     * dicatat di sini — satu-satunya jalur manusia yang membuatnya.
     */
    protected function afterCreate(): void
    {
        app(AuditLogger::class)->recordFor(
            Role::class,
            $this->record->getKey(),
            AuditAction::Created,
        );
    }
}
