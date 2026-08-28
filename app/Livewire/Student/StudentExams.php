<?php

namespace App\Livewire\Student;

use App\Livewire\Student\Concerns\ResolvesStudent;
use App\Services\Cbt\ExamAttemptService;
use App\Services\Cbt\StudentExamService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Daftar ujian online milik siswa.
 *
 * Membuka halaman ini juga menutup pengerjaan yang batas waktunya sudah lewat —
 * tanpa penjadwal, dan hanya milik siswa yang sedang login (butir 313).
 */
class StudentExams extends Component
{
    use ResolvesStudent;

    public function render(): View
    {
        return view('livewire.student.exams', [
            'rows' => $this->hasStudent() ? $this->rows() : null,
        ])->layout('layouts.portal', ['title' => __('Ujian')]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function rows(): array
    {
        $user = Auth::user();
        $exams = app(StudentExamService::class);

        app(ExamAttemptService::class)->finalizeExpiredFor($exams->student($user));

        return $exams->board($user);
    }
}
