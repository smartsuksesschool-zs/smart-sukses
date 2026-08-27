<?php

namespace App\Services\Cbt;

use App\Enums\ExamAttemptStatus;
use App\Enums\ExamStatus;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamOption;
use App\Models\ExamQuestion;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sisi **tulis** ujian online bagi siswa: memulai, menjawab, mengumpulkan.
 *
 * Seluruh waktu di sini milik server. `started_at`, `expires_at`, dan
 * `submitted_at` ditetapkan di sini dan tidak pernah dibaca dari request; jam
 * pada perangkat siswa hanya dipakai menggambar hitung mundur. Peramban yang
 * jamnya dimundurkan, hitung mundur yang dihentikan lewat konsol, atau nilai
 * tersembunyi yang diubah tidak menggeser apa pun (butir 311).
 *
 * Identitas siswanya selalu dari akun yang login (butir 307). Yang datang dari
 * pemanggil hanyalah id ujian, id soal, dan id pilihan — dan ketiganya
 * diperiksa ulang di sini terhadap pengerjaan yang sedang berlangsung.
 */
class ExamAttemptService
{
    public function __construct(
        protected StudentExamService $exams,
        protected ExamScoringService $scoring,
    ) {}

    /**
     * Memulai pengerjaan, atau melanjutkan yang sudah ada.
     *
     * Tidak pernah ada pengerjaan kedua. Bila sudah ada yang berjalan, yang
     * dikembalikan adalah pengerjaan itu juga — dan bila sudah dikumpulkan,
     * permintaan ditolak alih-alih membuat yang baru.
     *
     * @throws ValidationException
     */
    public function startOrResume(User $user, int $examId, ?CarbonInterface $now = null): ExamAttempt
    {
        $student = $this->exams->student($user);
        $exam = $this->exams->examFor($user, $examId);
        $moment = CarbonImmutable::instance($now ?? now());

        $existing = $this->exams->attemptOf($student, $exam);

        if ($existing !== null) {
            $existing = $this->finalizeIfExpired($existing, $moment);

            if ($existing->isFinal()) {
                $this->refuse('Ujian ini sudah Anda kumpulkan.');
            }

            return $existing;
        }

        $this->assertOpen($exam, $moment);

        return $this->create($student, $exam, $moment);
    }

    /**
     * Menyimpan satu jawaban.
     *
     * Yang diterima hanya id soal dan id pilihan. `is_correct`, `points_earned`,
     * dan `score` **tidak** termasuk parameter kelas ini sama sekali —
     * ketiganya hanya ditulis ExamScoringService, sehingga tidak ada bentuk
     * request apa pun yang dapat menyentuhnya (butir 311).
     *
     * @throws ValidationException
     */
    public function saveAnswer(
        User $user,
        int $examId,
        int $questionId,
        int $optionId,
        ?CarbonInterface $now = null,
    ): ExamAttempt {
        $moment = CarbonImmutable::instance($now ?? now());
        $attempt = $this->activeAttempt($user, $examId, $moment);

        $question = $this->questionIn($attempt, $questionId);
        $option = $this->optionIn($question, $optionId);

        // UNIQUE (exam_attempt_id, exam_question_id) membuat ini satu upsert:
        // mengganti pilihan sebelum mengumpulkan memperbarui baris yang sama,
        // bukan menumpuk jawaban (butir 273).
        ExamAnswer::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->updateOrCreate(
                [
                    'exam_attempt_id' => $attempt->getKey(),
                    'exam_question_id' => $question->getKey(),
                ],
                [
                    'school_id' => $attempt->school_id,
                    'exam_option_id' => $option->getKey(),
                ],
            );

        return $attempt;
    }

    /**
     * Mengumpulkan dan menilai.
     *
     * Idempoten: pengerjaan yang sudah dikumpulkan dikembalikan apa adanya
     * tanpa dinilai ulang, sehingga klik ganda tidak menghasilkan nilai kedua.
     * Barisnya dikunci di dalam transaksi supaya dua request yang datang
     * bersamaan tidak sama-sama lolos pemeriksaan status (butir 315).
     *
     * @throws ValidationException
     */
    public function submit(User $user, int $examId, ?CarbonInterface $now = null): ExamAttempt
    {
        $student = $this->exams->student($user);
        $exam = $this->exams->examFor($user, $examId);
        $moment = CarbonImmutable::instance($now ?? now());

        $attempt = $this->exams->attemptOf($student, $exam);

        if ($attempt === null) {
            $this->refuse('Ujian ini belum Anda mulai.');
        }

        return DB::transaction(function () use ($attempt, $moment): ExamAttempt {
            $locked = ExamAttempt::query()
                ->withoutGlobalScope(SchoolScope::class)
                ->whereKey($attempt->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->isFinal()) {
                return $locked ?? $attempt;
            }

            // Batas waktu yang sudah lewat tetap dikumpulkan — dengan jawaban
            // yang sudah tersimpan, dan dengan waktu berakhirnya yang
            // sebenarnya.
            $submittedAt = $locked->isExpiredAt($moment)
                ? $locked->expires_at
                : $moment;

            return $this->scoring->finalize($locked, $submittedAt);
        });
    }

    /**
     * Menutup pengerjaan yang batas waktunya sudah lewat.
     *
     * Tidak ada penjadwal. Finalisasinya dipicu oleh akses siswa itu sendiri —
     * membuka daftar ujian, membuka halaman pengerjaan, atau menekan
     * Kumpulkan — dan memakai mesin penilaian yang sama persis dengan
     * pengumpulan biasa, sehingga tidak ada jalur kedua yang dapat memberi
     * angka berbeda (butir 313).
     *
     * `submitted_at` diisi `expires_at`, bukan saat penemuannya: itulah detik
     * pengerjaannya benar-benar berakhir. Memakai waktu penemuan akan membuat
     * waktu pengumpulan bergantung pada kapan siswanya kebetulan membuka
     * halaman lagi — bisa berhari-hari kemudian (butir 316).
     */
    public function finalizeIfExpired(ExamAttempt $attempt, ?CarbonInterface $now = null): ExamAttempt
    {
        $moment = CarbonImmutable::instance($now ?? now());

        if ($attempt->isFinal() || ! $attempt->isExpiredAt($moment)) {
            return $attempt;
        }

        return DB::transaction(function () use ($attempt): ExamAttempt {
            $locked = ExamAttempt::query()
                ->withoutGlobalScope(SchoolScope::class)
                ->whereKey($attempt->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->isFinal()) {
                return $locked ?? $attempt;
            }

            return $this->scoring->finalize($locked, $locked->expires_at);
        });
    }

    /**
     * Menutup seluruh pengerjaan milik siswa ini yang batas waktunya lewat.
     *
     * Dipanggil saat daftar ujian dibuka. Cakupannya sengaja sempit — hanya
     * milik siswa yang sedang login — supaya tidak menjelma menjadi pekerjaan
     * latar yang menyamar sebagai permintaan halaman.
     *
     * @return int jumlah yang ditutup
     */
    public function finalizeExpiredFor(Student $student, ?CarbonInterface $now = null): int
    {
        $moment = CarbonImmutable::instance($now ?? now());

        $stale = ExamAttempt::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $student->school_id)
            ->where('student_id', $student->getKey())
            ->inProgress()
            ->where('expires_at', '<', $moment)
            ->get();

        foreach ($stale as $attempt) {
            $this->finalizeIfExpired($attempt, $moment);
        }

        return $stale->count();
    }

    /**
     * Pengerjaan yang sedang berjalan dan masih boleh menerima jawaban.
     *
     * @throws ValidationException
     */
    public function activeAttempt(User $user, int $examId, CarbonInterface $now): ExamAttempt
    {
        $student = $this->exams->student($user);
        $exam = $this->exams->examFor($user, $examId);

        $attempt = $this->exams->attemptOf($student, $exam);

        if ($attempt === null) {
            $this->refuse('Ujian ini belum Anda mulai.');
        }

        $attempt = $this->finalizeIfExpired($attempt, $now);

        if ($attempt->isFinal()) {
            $this->refuse('Ujian ini sudah dikumpulkan, jawabannya tidak dapat diubah lagi.');
        }

        return $attempt;
    }

    /**
     * Sisa waktu dalam detik, menurut jam server.
     */
    public function remainingSeconds(ExamAttempt $attempt, ?CarbonInterface $now = null): int
    {
        $moment = CarbonImmutable::instance($now ?? now());

        if ($attempt->expires_at === null) {
            return 0;
        }

        return max(0, $moment->diffInSeconds($attempt->expires_at, false));
    }

    /**
     * Jawaban yang sudah tersimpan, sebagai peta soal → pilihan.
     *
     * @return array<int, int>
     */
    public function savedAnswers(ExamAttempt $attempt): array
    {
        return ExamAnswer::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('exam_attempt_id', $attempt->getKey())
            ->whereNotNull('exam_option_id')
            ->pluck('exam_option_id', 'exam_question_id')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    /**
     * Membuat pengerjaan baru.
     *
     * `expires_at` adalah yang **lebih awal** antara akhir durasi dan penutupan
     * ujian. Siswa yang memulai 15 menit sebelum ujian ditutup mendapat 15
     * menit, bukan durasi penuh (butir 317).
     */
    protected function create(Student $student, Exam $exam, CarbonImmutable $startedAt): ExamAttempt
    {
        $durationEnd = $startedAt->addMinutes((int) $exam->duration_minutes);
        $windowEnd = CarbonImmutable::instance($exam->available_until);

        $expiresAt = $durationEnd->lessThan($windowEnd) ? $durationEnd : $windowEnd;

        try {
            return ExamAttempt::query()->create([
                'school_id' => $exam->school_id,
                'exam_id' => $exam->getKey(),
                'student_id' => $student->getKey(),
                'status' => ExamAttemptStatus::InProgress->value,
                'started_at' => $startedAt,
                'expires_at' => $expiresAt,
            ]);
        } catch (QueryException $exception) {
            // UNIQUE (exam_id, student_id) menang atas balapan: dua request yang
            // datang bersamaan, dan yang kalah melanjutkan pengerjaan yang
            // dibuat pemenangnya — bukan gagal, dan bukan pengerjaan kedua
            // (butir 271).
            $winner = $this->exams->attemptOf($student, $exam);

            if ($winner === null) {
                throw $exception;
            }

            return $winner;
        }
    }

    /**
     * @throws ValidationException
     */
    protected function assertOpen(Exam $exam, CarbonImmutable $now): void
    {
        if ($exam->status !== ExamStatus::Published) {
            $this->refuse('Ujian ini sudah ditutup.');
        }

        if ($exam->available_from !== null && $exam->available_from->greaterThan($now)) {
            $this->refuse('Ujian ini belum dibuka.');
        }

        if ($exam->available_until !== null && $exam->available_until->lessThan($now)) {
            $this->refuse('Waktu ujian ini sudah berakhir.');
        }
    }

    /**
     * Soal harus benar-benar bagian dari ujian yang sedang dikerjakan.
     *
     * @throws ValidationException
     */
    protected function questionIn(ExamAttempt $attempt, int $questionId): ExamQuestion
    {
        $question = ExamQuestion::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $attempt->school_id)
            ->where('exam_id', $attempt->exam_id)
            ->find($questionId);

        if ($question === null) {
            $this->refuse('Soal ini bukan bagian dari ujian yang sedang Anda kerjakan.');
        }

        if ($question->question_type?->isSupported() !== true) {
            $this->refuse('Jenis soal ini belum dapat dijawab.');
        }

        return $question;
    }

    /**
     * Pilihan harus benar-benar milik soal yang dijawab.
     *
     * @throws ValidationException
     */
    protected function optionIn(ExamQuestion $question, int $optionId): ExamOption
    {
        $option = ExamOption::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $question->school_id)
            ->where('exam_question_id', $question->getKey())
            ->find($optionId);

        if ($option === null) {
            $this->refuse('Pilihan jawaban ini bukan milik soal tersebut.');
        }

        return $option;
    }

    /**
     * @throws ValidationException
     */
    protected function refuse(string $message): never
    {
        throw ValidationException::withMessages(['exam' => $message]);
    }
}
