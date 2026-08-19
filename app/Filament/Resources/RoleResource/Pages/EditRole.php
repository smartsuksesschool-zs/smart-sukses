<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Enums\AuditAction;
use App\Filament\Resources\RoleResource;
use App\Support\AuditLogger;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\Models\Role;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * Izin yang dipegang peran ini sebelum form disimpan.
     *
     * @var array<int, string>
     */
    protected array $permissionsBeforeSave = [];

    protected function beforeSave(): void
    {
        $this->permissionsBeforeSave = $this->currentPermissionNames();
    }

    /**
     * Mengubah izin sebuah peran mengubah kewenangan **setiap** pengguna yang
     * memegangnya sekaligus — aksi paling berdampak di seluruh panel.
     *
     * `Role` dikecualikan dari listener otomatis supaya seeder tidak melahirkan
     * puluhan baris audit (butir 45), sehingga jalur UI ini diinstrumentasi
     * sendiri. Izin disimpan lewat relationship `belongsToMany` yang tidak
     * memicu model event, dan mengubah izin saja tidak mengotori atribut peran —
     * tanpa pencatatan di sini tidak ada jejak sama sekali (butir 47).
     *
     * `school_id` sengaja NULL: definisi peran bersifat platform-wide (PRD
     * 1.1.1), tidak berada di dalam cabang mana pun.
     */
    protected function afterSave(): void
    {
        $permissionsChanged = $this->currentPermissionNames() !== $this->permissionsBeforeSave;

        if ($permissionsChanged || $this->record->wasChanged()) {
            app(AuditLogger::class)->recordFor(
                Role::class,
                $this->record->getKey(),
                AuditAction::Updated,
            );
        }
    }

    /**
     * @return array<int, string>
     */
    protected function currentPermissionNames(): array
    {
        /** @var Role $role */
        $role = $this->record;

        return $role->permissions()->pluck('name')->sort()->values()->all();
    }
}
