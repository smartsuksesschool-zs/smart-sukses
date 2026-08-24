<?php

namespace App\Livewire\Student;

use App\Livewire\Student\Concerns\ResolvesStudent;
use App\Services\Portal\StudentPortalService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * PORTAL-03 poin 2 — nilai per mata pelajaran, per semester, per komponen.
 */
class StudentGrades extends Component
{
    use ResolvesStudent;

    public function render(): View
    {
        return view('livewire.student.grades', [
            'data' => $this->hasStudent()
                ? app(StudentPortalService::class)->grades(Auth::user())
                : null,
        ])->layout('layouts.portal', ['title' => 'Nilai']);
    }
}
