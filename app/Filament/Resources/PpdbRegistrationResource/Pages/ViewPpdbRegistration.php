<?php

namespace App\Filament\Resources\PpdbRegistrationResource\Pages;

use App\Filament\Resources\PpdbRegistrationResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * API 4.7 — GET /admin/ppdb/{id}: detail pendaftar.
 */
class ViewPpdbRegistration extends ViewRecord
{
    protected static string $resource = PpdbRegistrationResource::class;
}
