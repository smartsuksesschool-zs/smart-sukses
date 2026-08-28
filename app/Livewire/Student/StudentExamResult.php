<?php

namespace App\Livewire\Student;

use App\Livewire\Student\Concerns\ResolvesStudent;
use App\Services\Cbt\ExamAttemptService;
use App\Services\Cbt\StudentExamService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hasil satu ujian, milik siswa yang sedang login.
 *
 * Yang ditampilkan hanya **nilainya**, bukan kunci jawaban per soal. Selama
 * jendela ujian masih terbuka, siswa yang mengumpulkan lebih awal akan menjadi
 * sumber kunci bagi teman-temannya — dan halaman ulasan per soal adalah cara
 * paling langsung membuat itu terjadi. Ulasan per soal disiapkan sebagai
 * pekerjaan berikutnya, bukan bagian rilis ini (butir 318).
 */
class StudentExamResult extends Component
{
    use ResolvesStudent;

    public int $examId;

    public function mount(int $examId): void
    {
        $this->examId = $examId;
    }

    public function render(): View|Response
    {
        if (! $this->hasStudent()) {
            return view('livewire.student.exam-result', ['result' => null])
                ->layout('layouts.portal', ['title' => __('Hasil Ujian')]);
        }

        $user = Auth::user();
        $exams = app(StudentExamService::class);
        $attempts = app(ExamAttemptService::class);

        // Ujian yang bukan milik kelas siswa ini tidak ada baginya — 404, bukan
        // 403 (butir 308).
        $exam = $exams->examFor($user, $this->examId);
        $attempt = $exams->attemptOf($exams->student($user), $exam);

        // Belum pernah dikerjakan berarti belum ada hasil untuk dilihat.
        if ($attempt === null) {
            return redirect()->route('student.exams');
        }

        $attempt = $attempts->finalizeIfExpired($attempt);

        if (! $attempt->isFinal()) {
            return redirect()->route('student.exam', ['examId' => $this->examId]);
        }

        return view('livewire.student.exam-result', [
            'result' => [
                'title' => $exam->title,
                'subject_name' => $exam->classSubject?->subject?->name,
                'class_name' => $exam->classSubject?->schoolClass?->name,
                'score' => $attempt->score === null ? null : (float) $attempt->score,
                'submitted_at' => $attempt->submitted_at,
                'started_at' => $attempt->started_at,
                'duration_minutes' => (int) $exam->duration_minutes,
            ],
        ])->layout('layouts.portal', ['title' => __('Hasil Ujian')]);
    }
}
