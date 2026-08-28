<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\SelectsChild;
use App\Services\Portal\ParentPortalService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * SPP-04 — "Sebagai Orang Tua, saya dapat melihat daftar tagihan anak, status
 * pembayaran, dan riwayat pembayaran."
 */
class ParentFees extends Component
{
    use SelectsChild;

    /**
     * @return array<string, mixed>|null
     */
    public function fees(): ?array
    {
        if ($this->selectedChildId === null) {
            return null;
        }

        return app(ParentPortalService::class)->fees(Auth::user(), $this->selectedChildId);
    }

    public function render(): View
    {
        return view('livewire.portal.parent-fees', [
            'children' => $this->children(),
            'data' => $this->fees(),
        ])->layout('layouts.portal', ['title' => __('Tagihan')]);
    }
}
