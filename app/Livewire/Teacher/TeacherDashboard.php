<?php

namespace App\Livewire\Teacher;

use App\Services\Portal\TeacherPortalService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * PORTAL-02 — "Sebagai Guru, saya dapat mengakses dasbor kerja yang menampilkan
 * jadwal hari ini dan kelas yang aktif."
 */
class TeacherDashboard extends Component
{
    public function render(): View
    {
        return view('livewire.teacher.dashboard', [
            'data' => app(TeacherPortalService::class)->dashboard(Auth::user()),
        ])->layout('layouts.portal', ['title' => __('Dasbor Guru')]);
    }
}
