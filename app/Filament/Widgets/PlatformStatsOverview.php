<?php

namespace App\Filament\Widgets;

use App\Services\Admin\SuperAdminDashboardService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * API 4.3 / alur Super Admin — "Dashboard ringkasan semua cabang: total siswa,
 * total SPP terkumpul, PPDB aktif".
 *
 * Tiga kartu itu saja, dan angkanya diambil dari service yang sama dengan
 * `GET /admin/dashboard`. Tidak ada satu pun rumus yang ditulis ulang di sini:
 * kalau widget ini menghitung sendiri, layar dan API akan mulai berbeda tanpa
 * ada yang menyadarinya.
 *
 * Tabel dan grafik lintas cabang tidak ikut ke sini — itu KAS-03, yang tetap
 * berdiri sebagai halamannya sendiri.
 */
class PlatformStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    protected int|string|array $columnSpan = 'full';

    /**
     * Hanya Super Admin. Peran lain tidak melihat kartunya sama sekali — dan
     * seandainya widget ini dipaksa dirender, service-nya tetap menolak.
     */
    public static function canView(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $summary = app(SuperAdminDashboardService::class)->summarize(Auth::user());

        return [
            Stat::make('Total Siswa', number_format($summary['total_students'], 0, ',', '.'))
                ->description(__('Siswa aktif di seluruh cabang'))
                ->icon('heroicon-m-academic-cap')
                ->color('primary'),

            Stat::make('Total SPP Terkumpul', 'Rp '.number_format(
                (float) $summary['total_spp_collected'], 0, ',', '.'
            ))
                ->description(__('Seluruh pembayaran yang tercatat'))
                ->icon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('PPDB Aktif', number_format($summary['active_ppdb'], 0, ',', '.'))
                ->description(__('Pendaftaran yang masih berjalan'))
                ->icon('heroicon-m-clipboard-document-list')
                ->color('warning'),
        ];
    }
}
