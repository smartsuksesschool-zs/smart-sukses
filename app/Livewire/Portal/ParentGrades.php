<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\SelectsChild;
use App\Services\Portal\ParentPortalService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * NILAI-04 — "Sebagai Siswa / Orang Tua, saya dapat melihat nilai real-time
 * (sebelum rapor diterbitkan) dan rapor final."
 *
 * Halaman ini hanya membaca. Angkanya dari ParentPortalService, yang memakai
 * kalkulator nilai yang sama dengan panel dan rapor (butir 160).
 */
class ParentGrades extends Component
{
    use SelectsChild;

    /**
     * @return array<string, mixed>|null
     */
    public function grades(): ?array
    {
        if ($this->selectedChildId === null) {
            return null;
        }

        return app(ParentPortalService::class)->grades(Auth::user(), $this->selectedChildId);
    }

    public function render(): View
    {
        return view('livewire.portal.parent-grades', [
            'children' => $this->children(),
            'data' => $this->grades(),
        ])->layout('layouts.portal', ['title' => __('Nilai')]);
    }
}
