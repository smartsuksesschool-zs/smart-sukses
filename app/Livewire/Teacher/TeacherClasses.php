<?php

namespace App\Livewire\Teacher;

use App\Services\Portal\TeacherPortalService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * PORTAL-02 poin 2 — pintasan "Daftar Siswa Kelas" bermula di sini.
 */
class TeacherClasses extends Component
{
    public function render(): View
    {
        return view('livewire.teacher.classes', [
            'classes' => app(TeacherPortalService::class)->classes(Auth::user()),
        ])->layout('layouts.portal', ['title' => __('Kelas Ajar')]);
    }
}
