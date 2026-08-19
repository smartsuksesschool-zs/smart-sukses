<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Enums\AuditAction;
use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Support\AuditLogger;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Peran yang dipegang pengguna sebelum form disimpan.
     *
     * @var array<int, string>
     */
    protected array $rolesBeforeSave = [];

    protected function beforeSave(): void
    {
        $this->rolesBeforeSave = $this->currentRoleNames();
    }

    /**
     * Arsitektur 3.4 — perubahan peran adalah aksi CUD yang mengubah kewenangan,
     * dan justru itu yang paling perlu terlacak.
     *
     * Field "Peran" adalah relationship `belongsToMany`; Filament menyimpannya
     * lewat `sync()`, yang **tidak memicu model event apa pun**. Bila hanya
     * perannya yang diubah, `save()` juga tidak menemukan atribut yang kotor,
     * sehingga event `updated` pun tidak menyala — tanpa pencatatan di sini,
     * perubahan kewenangan tidak meninggalkan jejak sama sekali (butir 47).
     *
     * `wasChanged()` menjaga agar tidak ada baris ganda: bila atribut pengguna
     * ikut berubah, listener otomatis sudah menulis satu baris UPDATED untuk
     * penyimpanan yang sama, dan satu aksi manusia cukup diwakili satu baris.
     */
    protected function afterSave(): void
    {
        $rolesChanged = $this->currentRoleNames() !== $this->rolesBeforeSave;

        if ($rolesChanged && ! $this->record->wasChanged()) {
            app(AuditLogger::class)->record($this->record, AuditAction::Updated);
        }
    }

    /**
     * @return array<int, string>
     */
    protected function currentRoleNames(): array
    {
        /** @var User $user */
        $user = $this->record;

        return $user->roles()->pluck('name')->sort()->values()->all();
    }
}
