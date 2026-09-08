<?php

namespace App\Filament\Resources\StudentResource\Widgets;

use App\Services\Admin\StudentRosterSummary;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * Ringkasan jumlah siswa di atas daftar Data Siswa.
 *
 * Muncul karena satu pertanyaan yang berulang setiap kali berkas diimpor:
 * *"jadi sekarang totalnya berapa, dan sudah masuk kelas semua belum?"*.
 * Menjawabnya dulu berarti membuka tabel, menyaring per kelas, dan menghitung
 * sendiri — empat kali, satu per tingkat (butir 578).
 *
 * Angkanya seluruhnya dari `StudentRosterSummary`; tidak ada satu pun rumus di
 * kelas ini. Tidak ada pula angka yang ditulis mati: 12/13/14 adalah kontrak
 * roster hari ini, dan menuliskannya di sini akan membuat kartu ini berbohong
 * pada hari pertama seorang siswa pindah.
 */
class StudentRosterOverview extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    /**
     * Tiga kolom di layar lebar, satu di ponsel — mengikuti bawaan Filament,
     * yang memang sudah responsif.
     */
    protected function getColumns(): int
    {
        return 3;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $user = Auth::user();
        $summary = app(StudentRosterSummary::class)->for($user);

        $grade = $summary['by_grade'];

        /*
         * Super Admin melihat seluruh cabang pada tabel di bawah, jadi
         * ringkasannya pun lintas cabang — dan dikatakan begitu. Angka lintas
         * cabang yang tampil tanpa keterangan akan dibaca sebagai angka satu
         * sekolah (butir 579).
         */
        $scopeNote = $summary['scope'] === 'all'
            ? __('Semua cabang')
            : __('Cabang Anda');

        $stats = [
            Stat::make(__('Total Siswa'), (string) $summary['total'])
                ->description($scopeNote.' · '.__('berstatus aktif'))
                ->icon('heroicon-m-academic-cap'),

            Stat::make(__('Kelas X'), (string) ($grade[10] ?? 0))
                ->description(__('Tahun ajaran aktif'))
                ->icon('heroicon-m-user-group'),

            Stat::make(__('Kelas XI'), (string) ($grade[11] ?? 0))
                ->description(__('Tahun ajaran aktif'))
                ->icon('heroicon-m-user-group'),

            Stat::make(__('Kelas XII'), (string) ($grade[12] ?? 0))
                ->description(__('Tahun ajaran aktif'))
                ->icon('heroicon-m-user-group'),
        ];

        /*
         * "Belum Ada Kelas" diberi warna hanya ketika jumlahnya bukan nol.
         * Kartu merah yang selalu merah berhenti dibaca; yang menyala hanya
         * saat ada isinya justru menarik mata ke pekerjaan yang tersisa.
         */
        $stats[] = Stat::make(__('Belum Ada Kelas'), (string) $summary['unplaced'])
            ->description(__('Siswa aktif tanpa penempatan tahun ini'))
            ->icon('heroicon-m-exclamation-triangle')
            ->color($summary['unplaced'] > 0 ? 'warning' : 'gray');

        // Rincian per rombel hanya ditampilkan bila memang ada isinya, dan
        // hanya sebagai keterangan — bukan dasar penghitungan tingkat di atas.
        if ($summary['by_class'] !== []) {
            /*
             * Dipisah koma, bukan titik tengah: pada cakupan lintas cabang
             * labelnya sendiri sudah memakai titik tengah ("PUSAT · X Terbuka
             * - 2"), sehingga pemisah yang sama akan membuat batas antar-rombel
             * tidak terbaca lagi.
             */
            $breakdown = collect($summary['by_class'])
                ->map(fn (int $n, string $name) => "{$name}: {$n}")
                ->implode(', ');

            $stats[] = Stat::make(__('Rincian Rombel'), (string) count($summary['by_class']))
                ->description($breakdown)
                ->icon('heroicon-m-rectangle-group');
        }

        return $stats;
    }
}
