<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\SelectsChild;
use App\Services\Portal\ParentPortalService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * API 4.11 — "Jadwal pelajaran kelas anak hari ini & minggu ini".
 */
class ParentSchedule extends Component
{
    use SelectsChild;

    /**
     * @return array<string, mixed>|null
     */
    public function schedule(): ?array
    {
        if ($this->selectedChildId === null) {
            return null;
        }

        return app(ParentPortalService::class)->schedule(Auth::user(), $this->selectedChildId);
    }

    public function render(): View
    {
        $data = $this->schedule();

        $lessons = collect($data['lessons'] ?? []);

        return view('livewire.portal.parent-schedule', [
            'children' => $this->children(),
            'data' => $data,
            'today' => $lessons->where('day_of_week', $data['today'] ?? null)->values(),
            'week' => $lessons->groupBy('day_of_week'),
        ])->layout('layouts.portal', ['title' => __('Jadwal')]);
    }
}
