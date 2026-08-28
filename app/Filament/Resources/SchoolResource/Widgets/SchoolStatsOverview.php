<?php

namespace App\Filament\Resources\SchoolResource\Widgets;

use App\Models\School;
use App\Services\Admin\SchoolStatisticsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * API 4.3 — GET /admin/schools/{id}/stats, ditampilkan di tempat yang paling
 * masuk akal: halaman detail cabang yang sudah ada.
 *
 * Angkanya dari `SchoolStatisticsService`, service yang sama dengan
 * endpointnya. Tidak ada rumus di Blade maupun di widget ini.
 */
class SchoolStatsOverview extends StatsOverviewWidget
{
    public ?School $record = null;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        if ($this->record === null) {
            return [];
        }

        $stats = app(SchoolStatisticsService::class)
            ->forSchool($this->record->getKey(), Auth::user());

        return [
            Stat::make('Jumlah Siswa', number_format($stats['student_count'], 0, ',', '.'))
                ->description(__('Berstatus aktif'))
                ->icon('heroicon-m-academic-cap'),

            Stat::make('Jumlah Guru', number_format($stats['teacher_count'], 0, ',', '.'))
                ->description(__('Guru dan wali kelas aktif'))
                ->icon('heroicon-m-user-group'),

            Stat::make('Terkumpul Bulan Ini', 'Rp '.number_format(
                (float) $stats['collected_this_month'], 0, ',', '.'
            ))
                ->description('Periode '.$stats['period'])
                ->icon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('Tunggakan', 'Rp '.number_format(
                (float) $stats['arrears'], 0, ',', '.'
            ))
                ->description(__('Seluruh periode, di luar tagihan yang dibebaskan'))
                ->icon('heroicon-m-exclamation-triangle')
                ->color('danger'),
        ];
    }
}
