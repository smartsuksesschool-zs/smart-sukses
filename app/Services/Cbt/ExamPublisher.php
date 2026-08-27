<?php

namespace App\Services\Cbt;

use App\Enums\ExamStatus;
use App\Models\Exam;
use App\Models\ExamOption;
use App\Models\ExamQuestion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya tempat siklus hidup ujian berpindah keadaan.
 *
 * DRAFT → PUBLISHED → CLOSED, dan PUBLISHED → DRAFT selama belum ada yang
 * mengerjakan. Aturannya ditulis **sekali** di sini; Resource, halaman, dan
 * test memanggil kelas ini, tidak menyalin syaratnya. Menyalinnya berarti tiga
 * tempat yang harus ikut berubah setiap kali satu syarat bergeser, dan yang
 * tertinggal adalah ujian yang terbit dalam keadaan yang seharusnya ditolak
 * (butir 285).
 *
 * Pembagian tanggung jawabnya sengaja tegas:
 *
 *  - **ExamPolicy** menjawab "bolehkah orang ini" — izin, cabang, kelas yang
 *    diampu, dan status yang memungkinkan;
 *  - **ExamIntegrity** menjawab "apakah keterkaitannya sah" — cabang, tahun
 *    ajaran, pembuatnya;
 *  - **kelas ini** menjawab "apakah isinya siap terbit" — soal, pilihan,
 *    bobot, jadwal.
 *
 * Ketiganya dipanggil, tidak ada yang ditiru.
 */
class ExamPublisher
{
    public function __construct(protected ExamIntegrity $integrity) {}

    /**
     * DRAFT → PUBLISHED.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function publish(Exam $exam, User $actor): Exam
    {
        Gate::forUser($actor)->authorize('publish', $exam);

        $this->validateForPublishing($exam);

        // Perpindahan status satu perintah, tetapi tetap di dalam transaksi:
        // batch berikutnya menambahkan pekerjaan pada peralihan yang sama
        // (notifikasi, penjadwalan), dan batas transaksinya sudah ada di sini
        // sejak awal.
        DB::transaction(function () use ($exam): void {
            $exam->forceFill(['status' => ExamStatus::Published->value])->save();
        });

        return $exam->refresh();
    }

    /**
     * PUBLISHED → DRAFT, hanya selama belum ada yang mengerjakan.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function unpublish(Exam $exam, User $actor): Exam
    {
        Gate::forUser($actor)->authorize('unpublish', $exam);

        if ($exam->status !== ExamStatus::Published) {
            $this->refuse('Hanya ujian yang sedang terbit yang dapat ditarik kembali.');
        }

        // Pagar yang sesungguhnya. Menarik kembali ujian yang sudah dikerjakan
        // berarti membuka soal dan kuncinya untuk diubah, sementara ada siswa
        // yang sudah menjawabnya — jawaban mereka akan dinilai dengan kunci
        // yang bukan kunci saat mereka mengerjakan (butir 287).
        if ($exam->hasAttempts()) {
            $this->refuse('Ujian ini sudah dikerjakan siswa, sehingga tidak dapat ditarik kembali.');
        }

        $exam->forceFill(['status' => ExamStatus::Draft->value])->save();

        return $exam->refresh();
    }

    /**
     * PUBLISHED → CLOSED. Tidak menghapus apa pun, dan tidak dapat dibuka lagi.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function close(Exam $exam, User $actor): Exam
    {
        Gate::forUser($actor)->authorize('close', $exam);

        if ($exam->status !== ExamStatus::Published) {
            $this->refuse('Hanya ujian yang sedang terbit yang dapat ditutup.');
        }

        $exam->forceFill(['status' => ExamStatus::Closed->value])->save();

        return $exam->refresh();
    }

    /**
     * @throws ValidationException
     */
    public function validateForPublishing(Exam $exam): void
    {
        $reason = $this->reasonToRefusePublishing($exam);

        if ($reason !== null) {
            $this->refuse($reason);
        }
    }

    /**
     * Alasan ujian ini belum boleh terbit, atau NULL bila sudah siap.
     *
     * Berhenti pada alasan pertama. Menumpuk seluruh keluhan sekaligus terdengar
     * lebih membantu, tetapi yang sampai ke guru adalah daftar panjang yang
     * sebagian besarnya akibat dari yang pertama.
     */
    public function reasonToRefusePublishing(Exam $exam): ?string
    {
        if ($exam->status !== ExamStatus::Draft) {
            return 'Hanya ujian berstatus Draf yang dapat diterbitkan.';
        }

        // Cabang, tahun ajaran, dan pembuatnya — dijawab ExamIntegrity, tidak
        // ditulis ulang di sini.
        if ($reason = $this->integrity->reasonToRejectExam($exam)) {
            return $reason;
        }

        if ((int) $exam->duration_minutes <= 0) {
            return 'Durasi pengerjaan harus lebih dari 0 menit.';
        }

        if ($exam->available_from === null || $exam->available_until === null) {
            return 'Waktu buka dan waktu tutup harus diisi.';
        }

        if ($exam->available_until->lessThanOrEqualTo($exam->available_from)) {
            return 'Waktu tutup harus setelah waktu buka.';
        }

        $questions = $exam->questions()->with('options')->get();

        if ($questions->isEmpty()) {
            return 'Ujian belum memiliki satu soal pun.';
        }

        foreach ($questions as $question) {
            if ($reason = $this->reasonToRefuseQuestion($question)) {
                return $reason;
            }
        }

        // Sudah dijamin oleh bobot tiap soal di atas, tetapi ditulis eksplisit:
        // total nol adalah penyebut nol pada rumus nilai, dan satu-satunya
        // tempat yang dapat mencegahnya adalah di sini — sebelum ada yang
        // mengerjakan (butir 286).
        if ($questions->sum(fn (ExamQuestion $question) => (float) $question->points) <= 0) {
            return 'Total bobot seluruh soal harus lebih dari 0.';
        }

        return null;
    }

    /**
     * Alasan satu soal belum siap terbit, sudah diberi nomornya.
     */
    protected function reasonToRefuseQuestion(ExamQuestion $question): ?string
    {
        $number = 'Soal nomor '.($question->position ?? '?');

        if ($question->question_type?->isSupported() !== true) {
            return sprintf(
                '%s bertipe %s, yang belum didukung pada rilis ini.',
                $number,
                $question->question_type?->label() ?? 'tidak dikenal',
            );
        }

        $reason = $this->reasonToRejectQuestionShape(
            $question->points === null ? null : (float) $question->points,
            $question->options
                ->map(fn (ExamOption $option) => [
                    'option_text' => $option->option_text,
                    'is_correct' => (bool) $option->is_correct,
                ])
                ->all(),
        );

        return $reason === null ? null : "{$number}: {$reason}";
    }

    /**
     * Bentuk satu soal pilihan ganda yang sah.
     *
     * Menerima array biasa, bukan model, supaya aturan yang sama dapat dipanggil
     * dari dua tempat yang bentuk datanya berbeda: validasi form penulisan soal
     * (state repeater, belum tersimpan) dan pemeriksaan saat terbit (baris yang
     * sudah tersimpan). Satu aturan, dua pemanggil — bukan dua aturan yang
     * kebetulan mirip (butir 288).
     *
     * @param  array<int, array{option_text?: string|null, is_correct?: mixed}>  $options
     */
    public function reasonToRejectQuestionShape(?float $points, array $options): ?string
    {
        if ($points === null || $points <= 0) {
            return 'bobot soal harus lebih dari 0.';
        }

        return $this->reasonToRejectOptions($options);
    }

    /**
     * Bentuk pilihan jawaban yang sah, terlepas dari bobot soalnya.
     *
     * Dipisahkan karena form penulisan soal memvalidasi pilihan jawabannya
     * sebagai satu kesatuan, sementara bobot punya aturannya sendiri di field
     * itu. Memaksa keduanya lewat satu pintu akan menuntut form membaca nilai
     * field tetangga di tengah validasi — sambungan yang mudah putus tanpa
     * menambah jaminan apa pun.
     *
     * @param  array<int, array{option_text?: string|null, is_correct?: mixed}>  $options
     */
    public function reasonToRejectOptions(array $options): ?string
    {
        $options = array_values($options);

        if (count($options) < 2) {
            return 'soal pilihan ganda membutuhkan minimal 2 pilihan jawaban.';
        }

        foreach ($options as $option) {
            if (blank($option['option_text'] ?? null)) {
                return 'setiap pilihan jawaban harus diisi.';
            }
        }

        $correct = count(array_filter(
            $options,
            fn (array $option) => (bool) ($option['is_correct'] ?? false),
        ));

        if ($correct === 0) {
            return 'belum ada pilihan yang ditandai sebagai kunci jawaban.';
        }

        if ($correct > 1) {
            return 'hanya boleh ada satu kunci jawaban.';
        }

        return null;
    }

    /**
     * @throws ValidationException
     */
    protected function refuse(string $message): never
    {
        throw ValidationException::withMessages(['exam' => $message]);
    }
}
