<?php

namespace App\Livewire\Student;

use App\Livewire\Student\Concerns\ResolvesStudent;
use App\Services\Portal\StudentPortalService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * PORTAL-03 poin 1 — tab Jadwal.
 */
class StudentSchedule extends Component
{
    use ResolvesStudent;

    public function render(): View
    {
        $data = $this->hasStudent()
            ? app(StudentPortalService::class)->schedule(Auth::user())
            : null;

        $lessons = collect($data['lessons'] ?? []);

        return view('livewire.student.schedule', [
            'data' => $data,
            'today' => $lessons->where('day_of_week', $data['today'] ?? null)->values(),
            'week' => $lessons->groupBy('day_of_week'),
        ])->layout('layouts.portal', ['title' => 'Jadwal']);
    }
}
