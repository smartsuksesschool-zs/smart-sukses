<?php

namespace App\Services\Cbt;

use App\Enums\ExamAttemptStatus;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamOption;
use App\Models\ExamQuestion;
use App\Models\Scopes\SchoolScope;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Penilaian ujian online — seluruhnya di sisi server.
 *
 * Rumusnya dikunci sejak audit pra-implementasi:
 *
 *     nilai = round( Σ points_earned / Σ points_soal × 100 , 2 )
 *
 * Tidak ada satu pun angka di sini yang berasal dari peramban. Yang dikirim
 * siswa hanyalah **pilihan mana yang ia ambil**; benar-salahnya, poinnya, dan
 * nilainya dihitung di sini dari kunci jawaban yang tidak pernah meninggalkan
 * server. Mengubah HTML, JavaScript, atau payload Livewire karena itu tidak
 * dapat menggeser nilai sedikit pun (butir 311).
 *
 * Kelas ini juga satu-satunya jalan sebuah pengerjaan menjadi SUBMITTED —
 * dipakai pengumpulan biasa maupun finalisasi pengerjaan yang batas waktunya
 * lewat, sehingga keduanya mustahil menghasilkan angka yang berbeda
 * (butir 313).
 */
class ExamScoringService
{
    /**
     * Menilai dan mengunci satu pengerjaan.
     *
     * Tujuh langkahnya berada dalam satu transaksi: membaca soal dan kuncinya,
     * menghitung benar-salah, menyimpan snapshot per jawaban, menghitung nilai,
     * lalu menandai pengerjaannya selesai. Sebagian di antaranya akan
     * meninggalkan pengerjaan bernilai tanpa status selesai — keadaan yang
     * tidak pernah dapat diperbaiki sendiri oleh siapa pun.
     */
    public function finalize(ExamAttempt $attempt, CarbonInterface $submittedAt): ExamAttempt
    {
        return DB::transaction(function () use ($attempt, $submittedAt): ExamAttempt {
            $questions = $this->questionsOf($attempt);
            $answers = $this->answersOf($attempt);

            $totalPoints = 0.0;
            $earnedPoints = 0.0;

            foreach ($questions as $question) {
                $points = (float) $question->points;
                $totalPoints += $points;

                $correctOptionId = $question->options
                    ->first(fn (ExamOption $option) => (bool) $option->is_correct)
                    ?->getKey();

                $chosenOptionId = $answers->get($question->getKey())?->exam_option_id;

                $isCorrect = $chosenOptionId !== null
                    && $correctOptionId !== null
                    && (int) $chosenOptionId === (int) $correctOptionId;

                $gained = $isCorrect ? $points : 0.0;
                $earnedPoints += $gained;

                // Soal yang dilewati pun mendapat barisnya, bernilai nol. Tanpa
                // itu, "tidak dijawab" dan "belum dinilai" akan terlihat sama
                // persis di database (butir 314).
                ExamAnswer::query()
                    ->withoutGlobalScope(SchoolScope::class)
                    ->updateOrCreate(
                        [
                            'exam_attempt_id' => $attempt->getKey(),
                            'exam_question_id' => $question->getKey(),
                        ],
                        [
                            'school_id' => $attempt->school_id,
                            'is_correct' => $isCorrect,
                            'points_earned' => $gained,
                        ],
                    );
            }

            $attempt->forceFill([
                'score' => $this->scoreFrom($earnedPoints, $totalPoints),
                'status' => ExamAttemptStatus::Submitted->value,
                'submitted_at' => $submittedAt,
            ])->save();

            return $attempt->refresh();
        });
    }

    /**
     * Nilai 0.00–100.00, dibulatkan dua desimal.
     *
     * Total bobot nol seharusnya mustahil — menerbitkan ujian menuntutnya lebih
     * dari nol (butir 286). Tetap dijaga di sini, karena satu-satunya alternatif
     * pada pembagian dengan nol adalah kesalahan fatal tepat ketika seorang
     * siswa menekan Kumpulkan.
     */
    public function scoreFrom(float $earnedPoints, float $totalPoints): float
    {
        if ($totalPoints <= 0.0) {
            return 0.0;
        }

        return round($earnedPoints / $totalPoints * 100, 2);
    }

    /**
     * Soal beserta kunci jawabannya.
     *
     * Ini satu-satunya tempat di alur siswa yang memuat `is_correct`, dan ia
     * berada di sisi server tanpa satu pun jalan menuju peramban: yang keluar
     * dari kelas ini hanyalah angka.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ExamQuestion>
     */
    protected function questionsOf(ExamAttempt $attempt)
    {
        return ExamQuestion::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('exam_id', $attempt->exam_id)
            ->with(['options' => fn ($query) => $query->withoutGlobalScope(SchoolScope::class)])
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, ExamAnswer> dikunci exam_question_id
     */
    protected function answersOf(ExamAttempt $attempt)
    {
        return ExamAnswer::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('exam_attempt_id', $attempt->getKey())
            ->get()
            ->keyBy('exam_question_id');
    }
}
