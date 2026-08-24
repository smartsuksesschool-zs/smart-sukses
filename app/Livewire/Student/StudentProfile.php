<?php

namespace App\Livewire\Student;

use App\Livewire\Student\Concerns\ResolvesStudent;
use App\Services\Portal\StudentPortalService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * PORTAL-03 poin 1 — tab Profil.
 *
 * Tidak ada id siswa pada alamatnya: identitasnya berasal dari akun yang login,
 * sehingga tidak ada yang dapat diganti untuk melihat profil siswa lain
 * (butir 186).
 *
 * Hanya membaca. Mengubah profil dan kata sandi belum punya jalurnya di repo
 * ini — `PATCH /auth/me` memang belum dibuat — dan membuatnya sekarang berarti
 * mengerjakan backlog endpoint lain (butir 187).
 */
class StudentProfile extends Component
{
    use ResolvesStudent;

    public function render(): View
    {
        $service = app(StudentPortalService::class);
        $student = $this->hasStudent() ? $service->student(Auth::user()) : null;

        return view('livewire.student.profile', [
            'student' => $student,
            'currentClass' => $student === null ? null : $service->currentClass($student),
            'academicYear' => $student === null ? null : $service->activeAcademicYear($student),
        ])->layout('layouts.portal', ['title' => 'Profil']);
    }
}
