<?php

namespace App\Livewire\Student;

use App\Livewire\Student\Concerns\ResolvesStudent;
use App\Services\Portal\StudentPortalService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * PORTAL-03 — beranda portal siswa.
 */
class StudentDashboard extends Component
{
    use ResolvesStudent;

    public function render(): View
    {
        return view('livewire.student.dashboard', [
            'data' => $this->hasStudent()
                ? app(StudentPortalService::class)->dashboard(Auth::user())
                : null,
        ])->layout('layouts.portal', ['title' => __('Portal Siswa')]);
    }
}
