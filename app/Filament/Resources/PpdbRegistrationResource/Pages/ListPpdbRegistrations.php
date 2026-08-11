<?php

namespace App\Filament\Resources\PpdbRegistrationResource\Pages;

use App\Enums\PpdbStatus;
use App\Filament\Resources\PpdbRegistrationResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * PPDB-03 / API 4.7 — GET /admin/ppdb: daftar semua pendaftar cabang.
 */
class ListPpdbRegistrations extends ListRecords
{
    protected static string $resource = PpdbRegistrationResource::class;

    protected function getHeaderActions(): array
    {
        // Pendaftaran hanya lahir dari formulir publik (PPDB-01).
        return [];
    }

    /**
     * PPDB-03 poin 2 — "Filter per status tersedia".
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make('Semua')];

        foreach (PpdbStatus::cases() as $status) {
            $tabs[$status->value] = Tab::make($status->label())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status->value));
        }

        return $tabs;
    }
}
