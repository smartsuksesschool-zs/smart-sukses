<?php

namespace App\Filament\Resources\StudentFeeResource\Pages;

use App\Filament\Resources\StudentFeeResource;
use Filament\Resources\Pages\ListRecords;

/**
 * API 4.9 — GET /student-fees.
 *
 * Tanpa header action apa pun: tagihan diterbitkan di halaman Generate
 * Tagihan (SPP-02), bukan dari daftar ini.
 */
class ListStudentFees extends ListRecords
{
    protected static string $resource = StudentFeeResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
