<?php

namespace App\Livewire\Student;

use App\Livewire\Student\Concerns\ResolvesStudent;
use App\Services\Cbt\ExamAttemptService;
use App\Services\Cbt\StudentExamService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;

/**
 * Halaman pengerjaan ujian.
 *
 * Tiga hal yang menentukan bentuk komponen ini:
 *
 * 1. **Tidak ada satu pun model di properti publik.** Livewire menyimpan
 *    properti publik ke dalam snapshot yang bolak-balik ke peramban; menaruh
 *    model soal di sana berarti menaruh seluruh kolomnya — termasuk kunci
 *    jawaban — di dalam jangkauan siapa pun yang membuka panel jaringan. Yang
 *    publik di sini hanyalah angka: id ujian, nomor soal yang sedang dibuka,
 *    dan peta jawaban (butir 310).
 *
 * 2. **Membuka halaman ini berarti memulai.** Tidak ada tombol "Mulai" yang
 *    terpisah, karena satu-satunya jalan menuju halaman ini adalah tautan dari
 *    daftar ujian yang sudah menyebutnya "Mulai Ujian" atau "Lanjutkan".
 *    Keduanya memanggil layanan yang sama, dan layanan itu tidak pernah membuat
 *    pengerjaan kedua (butir 309).
 *
 * 3. **Waktu milik server.** Hitung mundur di layar hanya gambar; setiap aksi
 *    memeriksa ulang `expires_at` terhadap jam server, dan pengerjaan yang
 *    lewat batas ditutup memakai jawaban yang sudah tersimpan (butir 311).
 */
class StudentExam extends Component
{
    use ResolvesStudent;

    public int $examId;

    /** Nomor soal yang sedang dibuka, dihitung dari 0. */
    public int $index = 0;

    /**
     * Jawaban tersimpan: id soal → id pilihan.
     *
     * @var array<int, int>
     */
    public array $answers = [];

    /** Pesan penolakan terakhir dari server, bila ada. */
    public ?string $notice = null;

    public function mount(int $examId): void
    {
        $this->examId = $examId;
    }

    public function render(): View|Response
    {
        if (! $this->hasStudent()) {
            return $this->page(null);
        }

        try {
            $attempt = app(ExamAttemptService::class)->startOrResume(Auth::user(), $this->examId);
        } catch (ValidationException $exception) {
            // Sudah dikumpulkan → hasilnya. Belum dibuka atau sudah berakhir →
            // kembali ke daftar, tempat keadaannya dijelaskan.
            return $this->refusalRedirect($exception);
        }

        $attempts = app(ExamAttemptService::class);
        $exams = app(StudentExamService::class);

        $this->answers = $attempts->savedAnswers($attempt);

        $exam = $exams->examFor(Auth::user(), $this->examId);
        $questions = $exams->questionsFor($exam);

        $this->index = max(0, min($this->index, max(0, count($questions) - 1)));

        return $this->page([
            'exam' => [
                'title' => $exam->title,
                'subject_name' => $exam->classSubject?->subject?->name,
            ],
            'questions' => $questions,
            'question' => $questions[$this->index] ?? null,
            'total' => count($questions),
            'answered' => count(array_intersect_key(
                $this->answers,
                array_flip(array_column($questions, 'id')),
            )),
            'remaining_seconds' => $attempts->remainingSeconds($attempt),
        ]);
    }

    /**
     * Menyimpan satu jawaban. Idempoten dan dapat diubah sampai dikumpulkan.
     */
    public function choose(int $questionId, int $optionId): void
    {
        $this->notice = null;

        try {
            app(ExamAttemptService::class)->saveAnswer(
                Auth::user(),
                $this->examId,
                $questionId,
                $optionId,
            );
        } catch (ValidationException $exception) {
            $this->notice = $this->messageOf($exception);

            return;
        }

        $this->answers[$questionId] = $optionId;
    }

    public function goTo(int $index): void
    {
        $this->index = max(0, $index);
    }

    public function previous(): void
    {
        $this->index = max(0, $this->index - 1);
    }

    public function next(): void
    {
        $this->index++;
    }

    /**
     * Mengumpulkan. Aman diklik dua kali — pengumpulan kedua tidak menilai
     * ulang (butir 315).
     */
    public function submit(): mixed
    {
        try {
            app(ExamAttemptService::class)->submit(Auth::user(), $this->examId);
        } catch (ValidationException $exception) {
            $this->notice = $this->messageOf($exception);

            return null;
        }

        return $this->redirect(
            route('student.exam-result', ['examId' => $this->examId]),
            navigate: false,
        );
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    protected function page(?array $data): View
    {
        return view('livewire.student.exam', ['data' => $data])
            ->layout('layouts.portal', ['title' => 'Ujian']);
    }

    protected function refusalRedirect(ValidationException $exception): Response
    {
        $attempt = app(StudentExamService::class);
        $student = $attempt->student(Auth::user());
        $exam = $attempt->examFor(Auth::user(), $this->examId);

        if ($attempt->attemptOf($student, $exam)?->isFinal() === true) {
            return redirect()->route('student.exam-result', ['examId' => $this->examId]);
        }

        return redirect()->route('student.exams')->with('exam-notice', $this->messageOf($exception));
    }

    protected function messageOf(ValidationException $exception): string
    {
        return (string) collect($exception->errors())->flatten()->first();
    }
}
