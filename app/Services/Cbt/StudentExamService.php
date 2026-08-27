<?php

namespace App\Services\Cbt;

use App\Enums\ExamStatus;
use App\Enums\StudentExamState;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamOption;
use App\Models\ExamQuestion;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\User;
use App\Services\Portal\StudentPortalService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Sisi **baca** ujian online bagi siswa.
 *
 * Seluruh kelas ini hanya membaca, dan identitas siswanya **selalu** berasal
 * dari akun yang login lewat `StudentPortalService::student()` — tidak pernah
 * dari `student_id`, NIS, maupun apa pun yang dikirim pemanggil. Aturan yang
 * sama sudah berlaku untuk nilai, jadwal, dan rapor sejak Sprint 7 (butir 181),
 * dan CBT tidak boleh menjadi pengecualian pertamanya (butir 307).
 *
 * Kelas ini juga pemegang **satu-satunya** bentuk soal yang boleh sampai ke
 * peramban siswa. Lihat `questionsFor()`.
 */
class StudentExamService
{
    public function __construct(protected StudentPortalService $portal) {}

    /**
     * Daftar ujian yang boleh dilihat siswa ini, beserta keadaan masing-masing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function board(User $user, ?CarbonInterface $now = null): array
    {
        $student = $this->portal->student($user);
        $moment = CarbonImmutable::instance($now ?? now());

        $exams = $this->visibleQuery($student)
            ->with(['classSubject.subject', 'classSubject.schoolClass'])
            ->withCount('questions')
            ->orderByDesc('available_from')
            ->orderByDesc('id')
            ->get();

        // Seluruh pengerjaan milik siswa ini diambil sekali, lalu dibagikan ke
        // tiap baris. Menanyakannya per ujian akan menjadi satu query per baris
        // untuk hal yang bentuknya satu query saja (butir 312).
        $attempts = $this->attemptsOf($student, $exams->modelKeys());

        return $exams
            ->map(function (Exam $exam) use ($attempts, $moment): array {
                $attempt = $attempts->get($exam->getKey());
                $state = $this->stateOf($exam, $attempt, $moment);

                return [
                    'id' => $exam->getKey(),
                    'title' => $exam->title,
                    'subject_name' => $exam->classSubject?->subject?->name,
                    'class_name' => $exam->classSubject?->schoolClass?->name,
                    'available_from' => $exam->available_from,
                    'available_until' => $exam->available_until,
                    'duration_minutes' => (int) $exam->duration_minutes,
                    'question_count' => (int) $exam->questions_count,
                    'state' => $state,
                    // Nilai hanya ada setelah dikumpulkan; sebelum itu kolomnya
                    // memang NULL dan tidak diisi tebakan apa pun.
                    'score' => $state->hasResult() && $attempt?->score !== null
                        ? (float) $attempt->score
                        : null,
                    'submitted_at' => $attempt?->submitted_at,
                ];
            })
            ->all();
    }

    /**
     * Ujian yang boleh dibuka siswa ini, apa pun keadaannya.
     *
     * Yang tidak lolos di sini tidak menghasilkan 403 melainkan 404: ujian milik
     * cabang lain, kelas lain, atau yang masih draf **tidak ada** bagi siswa
     * ini, dan membedakan "tidak ada" dari "tidak boleh" sudah memberi tahu
     * bahwa ujiannya ada (butir 308).
     *
     * @throws ModelNotFoundException
     */
    public function examFor(User $user, int $examId): Exam
    {
        $student = $this->portal->student($user);

        return $this->visibleQuery($student)
            ->with(['classSubject.subject', 'classSubject.schoolClass'])
            ->findOrFail($examId);
    }

    /**
     * Soal ujian dalam bentuk yang **aman dikirim ke peramban siswa**.
     *
     * Yang keluar dari sini adalah array biasa berisi id, nomor, pertanyaan,
     * bobot, dan teks pilihan jawabannya. Tidak ada model Eloquent, tidak ada
     * relasi, dan yang terpenting: **tidak ada `is_correct`** — kolom itu tidak
     * pernah ikut terambil, bukan sekadar tidak dicetak Blade (butir 310).
     *
     * Teks pilihan yang benar tentu saja ikut terbawa: siswa harus dapat
     * memilihnya. Yang tidak boleh bocor adalah **mana** di antaranya yang
     * benar.
     *
     * @return array<int, array<string, mixed>>
     */
    public function questionsFor(Exam $exam): array
    {
        return ExamQuestion::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('exam_id', $exam->getKey())
            ->where('school_id', $exam->school_id)
            // Kolomnya disebut satu per satu. Mengambil seluruh kolom lalu
            // berharap tidak ada yang mencetaknya adalah jaminan yang bergantung
            // pada disiplin, bukan pada bentuk datanya.
            ->select(['id', 'exam_id', 'question_text', 'points', 'position'])
            ->with(['options' => fn ($query) => $query
                ->withoutGlobalScope(SchoolScope::class)
                ->select(['id', 'exam_question_id', 'option_text', 'position'])
                ->orderBy('position')
                ->orderBy('id')])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->values()
            ->map(fn (ExamQuestion $question, int $index) => [
                'id' => $question->getKey(),
                'number' => $index + 1,
                'question_text' => $question->question_text,
                'points' => (float) $question->points,
                'options' => $question->options
                    ->map(fn (ExamOption $option) => [
                        'id' => $option->getKey(),
                        'option_text' => $option->option_text,
                    ])
                    ->all(),
            ])
            ->all();
    }

    /**
     * Keadaan satu ujian bagi satu siswa, diturunkan — tidak disimpan
     * (butir 306).
     */
    public function stateOf(Exam $exam, ?ExamAttempt $attempt, CarbonInterface $now): StudentExamState
    {
        if ($attempt?->isFinal() === true) {
            return StudentExamState::Submitted;
        }

        // Pengerjaan yang batas waktunya sudah lewat bukan "sedang dikerjakan".
        // Ia menunggu difinalisasi, dan yang mengerjakannya adalah
        // ExamAttemptService (butir 313).
        if ($attempt !== null && ! $attempt->isExpiredAt($now)) {
            return StudentExamState::InProgress;
        }

        if ($exam->status !== ExamStatus::Published) {
            return StudentExamState::Missed;
        }

        if ($exam->available_from !== null && $exam->available_from->greaterThan($now)) {
            return StudentExamState::Upcoming;
        }

        if ($exam->available_until !== null && $exam->available_until->lessThan($now)) {
            return StudentExamState::Missed;
        }

        return StudentExamState::Available;
    }

    /**
     * Pengerjaan milik siswa ini pada satu ujian, atau NULL.
     */
    public function attemptOf(Student $student, Exam $exam): ?ExamAttempt
    {
        return ExamAttempt::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $student->school_id)
            ->where('exam_id', $exam->getKey())
            ->where('student_id', $student->getKey())
            ->first();
    }

    public function student(User $user): Student
    {
        return $this->portal->student($user);
    }

    /**
     * Ujian yang menjadi milik siswa ini.
     *
     * Empat syarat, seluruhnya wajib: cabang yang sama, tahun ajaran aktif,
     * kelas yang sedang ia tempati, dan status yang pernah terbit. Draf tidak
     * pernah termasuk. Ujian yang sudah **ditutup** tetap termasuk supaya hasil
     * yang sudah ada tidak ikut hilang ketika gurunya menutup ujian
     * (butir 308).
     */
    protected function visibleQuery(Student $student): Builder
    {
        $class = $this->portal->currentClass($student);
        $year = $this->portal->activeAcademicYear($student);

        $query = Exam::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $student->school_id)
            ->whereIn('status', [ExamStatus::Published->value, ExamStatus::Closed->value]);

        // Tanpa kelas aktif atau tanpa tahun ajaran aktif, tidak ada satu ujian
        // pun yang menjadi miliknya — gagal tertutup, bukan terbuka.
        if ($class === null || $year === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('academic_year_id', $year->getKey())
            ->whereHas('classSubject', fn (Builder $inner) => $inner
                ->withoutGlobalScope(SchoolScope::class)
                ->where('class_id', $class->getKey()));
    }

    /**
     * @param  array<int, int>  $examIds
     * @return Collection<int, ExamAttempt> dikunci exam_id
     */
    protected function attemptsOf(Student $student, array $examIds): Collection
    {
        if ($examIds === []) {
            return collect();
        }

        return ExamAttempt::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $student->school_id)
            ->where('student_id', $student->getKey())
            ->whereIn('exam_id', $examIds)
            ->get()
            ->keyBy('exam_id');
    }
}
