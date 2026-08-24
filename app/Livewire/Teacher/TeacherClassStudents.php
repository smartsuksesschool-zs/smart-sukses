<?php

namespace App\Livewire\Teacher;

use App\Services\Portal\TeacherPortalService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * SIS-04 — "daftar siswa di kelas yang saya ampu", hanya siswa aktif.
 *
 * Kelas yang tidak diampunya menjadi 404 lewat service, termasuk kelas lain di
 * cabang yang sama (butir 173).
 */
class TeacherClassStudents extends Component
{
    public int $classId;

    public function mount(int $classId): void
    {
        $this->classId = $classId;

        // Diresolusi saat mount supaya kelas yang bukan miliknya berhenti di
        // sini, bukan setelah kerangka halaman terlanjur dirender.
        app(TeacherPortalService::class)->teachingClass(Auth::user(), $classId);
    }

    public function render(): View
    {
        $service = app(TeacherPortalService::class);

        return view('livewire.teacher.class-students', [
            'schoolClass' => $service->teachingClass(Auth::user(), $this->classId),
            'students' => $service->classStudents(Auth::user(), $this->classId),
        ])->layout('layouts.portal', ['title' => 'Daftar Siswa']);
    }
}
