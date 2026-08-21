<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\SelectsChild;
use App\Services\Portal\ParentPortalService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * PORTAL-01 — "Sebagai Orang Tua, setelah login saya dapat melihat dashboard
 * anak yang menampilkan informasi penting secara ringkas."
 *
 * Halaman penuh Livewire, mengikuti pola yang sudah dipakai halaman PPDB:
 * rute web biasa, guard `web` yang sama dengan panel, dan tata letak sendiri
 * yang mengambil warna cabang. Bukan panel Filament kedua (butir 147).
 *
 * Seluruh datanya dari ParentPortalService — service yang sama dengan yang
 * dipakai endpoint API, sehingga layar dan API tidak dapat berbeda tentang
 * anak siapa yang boleh dilihat maupun berapa tagihan yang tertunggak.
 */
class ParentDashboard extends Component
{
    use SelectsChild;

    /**
     * Ringkasan anak terpilih, atau NULL bila orang tua ini belum punya anak
     * yang tertaut sama sekali.
     *
     * @return array<string, mixed>|null
     */
    public function summary(): ?array
    {
        if ($this->selectedChildId === null) {
            return null;
        }

        return app(ParentPortalService::class)
            ->summary(Auth::user(), $this->selectedChildId);
    }

    public function render(): View
    {
        $summary = $this->summary();

        return view('livewire.portal.parent-dashboard', [
            'children' => $this->children(),
            'summary' => $summary,
            // PORTAL-01 poin 1 menyebut "3 nilai terbaru"; API menyediakan
            // lima. Yang dipotong tampilannya, bukan datanya (butir 150).
            'grades' => array_slice(
                $summary['latest_grades'] ?? [],
                0,
                ParentPortalService::DASHBOARD_SUBJECTS,
            ),
        ])->layout('layouts.portal', [
            'title' => 'Dashboard Orang Tua',
        ]);
    }
}
