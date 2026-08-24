<?php

namespace App\Livewire\Teacher;

use App\Services\Portal\TeacherPortalService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * KELAS-04 — "jadwal mengajar saya untuk minggu berjalan".
 *
 * Halaman ini memakai service yang sama dengan jadwal hari ini di dasbor;
 * tidak ada logika jadwal kedua, dan tidak ada endpoint API baru di luar
 * API 4.11 (butir 174).
 */
class TeacherSchedule extends Component
{
    public function render(): View
    {
        $lessons = collect(app(TeacherPortalService::class)->schedule(Auth::user()));

        return view('livewire.teacher.schedule', [
            'week' => $lessons->groupBy('day_of_week'),
        ])->layout('layouts.portal', ['title' => 'Jadwal Mengajar']);
    }
}
