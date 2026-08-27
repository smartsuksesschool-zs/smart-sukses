<?php

namespace App\Services\Cbt;

use App\Models\ClassSubject;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamOption;
use App\Models\ExamQuestion;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Pemeriksaan keterkaitan antar baris CBT.
 *
 * Foreign key hanya menjamin bahwa baris tujuannya **ada** — tidak bahwa ia
 * milik cabang yang sama, tahun ajaran yang sama, atau ujian yang sama. Seluruh
 * jaminan itu harus ditegakkan aplikasi, dan project ini sudah punya satu
 * pelajaran mahal tentangnya: `notifications.target_id` menunjuk dua tabel
 * berbeda tanpa FK, dan maknanya divalidasi aplikasi (butir 194).
 *
 * Kelas ini menuliskan pemeriksaan itu **sekali**, sehingga UI guru, alur
 * pengerjaan siswa, dan penilaian di sisi server tidak dapat mulai berbeda
 * pendapat tentang apa yang sah. Bentuknya meniru `PortalEligibility`: satu
 * alasan penolakan sebagai string, atau NULL bila memang sah (butir 274).
 *
 * Seluruh pembacaannya melepas SchoolScope dengan sengaja. Baris dari cabang
 * lain harus dapat **ditemukan** supaya dapat **ditolak dengan alasan yang
 * benar**; bila scope menyembunyikannya, yang dilaporkan hanya "tidak
 * ditemukan" dan penyebab sesungguhnya hilang. Kelas ini hanya membaca dan
 * tidak pernah mengembalikan isi baris cabang lain (butir 275).
 */
class ExamIntegrity
{
    public function reasonToRejectExam(Exam $exam): ?string
    {
        $classSubject = $this->find(ClassSubject::class, $exam->class_subject_id);

        if ($classSubject === null) {
            return 'Kelas–mata pelajaran tidak ditemukan.';
        }

        if ((int) $classSubject->school_id !== (int) $exam->school_id) {
            return 'Kelas–mata pelajaran berasal dari cabang lain.';
        }

        if ((int) $classSubject->academic_year_id !== (int) $exam->academic_year_id) {
            return 'Tahun ajaran ujian berbeda dari tahun ajaran kelas–mata pelajarannya.';
        }

        return $this->reasonToRejectCreator($exam);
    }

    public function reasonToRejectQuestion(ExamQuestion $question): ?string
    {
        $exam = $this->find(Exam::class, $question->exam_id);

        if ($exam === null) {
            return 'Ujian tidak ditemukan.';
        }

        if ((int) $exam->school_id !== (int) $question->school_id) {
            return 'Soal dan ujiannya berasal dari cabang berbeda.';
        }

        return null;
    }

    public function reasonToRejectOption(ExamOption $option): ?string
    {
        $question = $this->find(ExamQuestion::class, $option->exam_question_id);

        if ($question === null) {
            return 'Soal tidak ditemukan.';
        }

        if ((int) $question->school_id !== (int) $option->school_id) {
            return 'Pilihan jawaban dan soalnya berasal dari cabang berbeda.';
        }

        return null;
    }

    public function reasonToRejectAttempt(ExamAttempt $attempt): ?string
    {
        $exam = $this->find(Exam::class, $attempt->exam_id);

        if ($exam === null) {
            return 'Ujian tidak ditemukan.';
        }

        if ((int) $exam->school_id !== (int) $attempt->school_id) {
            return 'Ujian ini milik cabang lain.';
        }

        $student = $this->find(Student::class, $attempt->student_id);

        if ($student === null) {
            return 'Siswa tidak ditemukan.';
        }

        if ((int) $student->school_id !== (int) $attempt->school_id) {
            return 'Siswa ini terdaftar di cabang lain.';
        }

        return null;
    }

    public function reasonToRejectAnswer(ExamAnswer $answer): ?string
    {
        $attempt = $this->find(ExamAttempt::class, $answer->exam_attempt_id);

        if ($attempt === null) {
            return 'Pengerjaan ujian tidak ditemukan.';
        }

        if ((int) $attempt->school_id !== (int) $answer->school_id) {
            return 'Jawaban dan pengerjaannya berasal dari cabang berbeda.';
        }

        $question = $this->find(ExamQuestion::class, $answer->exam_question_id);

        if ($question === null) {
            return 'Soal tidak ditemukan.';
        }

        if ((int) $question->school_id !== (int) $answer->school_id) {
            return 'Jawaban dan soalnya berasal dari cabang berbeda.';
        }

        // Inti pemeriksaan ini: soal yang dijawab harus benar-benar bagian dari
        // ujian yang sedang dikerjakan. Tanpa ini, id soal dari ujian lain —
        // bahkan ujian mata pelajaran lain — akan diterima FK tanpa keberatan.
        if ((int) $question->exam_id !== (int) $attempt->exam_id) {
            return 'Soal ini bukan bagian dari ujian yang sedang dikerjakan.';
        }

        if ($answer->exam_option_id === null) {
            return null;
        }

        $option = $this->find(ExamOption::class, $answer->exam_option_id);

        if ($option === null) {
            return 'Pilihan jawaban tidak ditemukan.';
        }

        if ((int) $option->exam_question_id !== (int) $answer->exam_question_id) {
            return 'Pilihan jawaban ini milik soal lain.';
        }

        return null;
    }

    /**
     * Apakah baris ini boleh disimpan sebagaimana adanya.
     */
    public function accepts(Model $model): bool
    {
        return $this->reasonToReject($model) === null;
    }

    /**
     * Alasan menolak baris CBT mana pun, atau NULL bila sah.
     */
    public function reasonToReject(Model $model): ?string
    {
        return match (true) {
            $model instanceof Exam => $this->reasonToRejectExam($model),
            $model instanceof ExamQuestion => $this->reasonToRejectQuestion($model),
            $model instanceof ExamOption => $this->reasonToRejectOption($model),
            $model instanceof ExamAttempt => $this->reasonToRejectAttempt($model),
            $model instanceof ExamAnswer => $this->reasonToRejectAnswer($model),
            default => 'Jenis data ini bukan bagian dari ujian online.',
        };
    }

    /**
     * Pembuat ujian harus berada dalam konteks cabang yang sah.
     *
     * Akun Platform Level punya `school_id` NULL menurut Arsitektur 3.2.2, dan
     * itu **bukan** ketidaksesuaian: Super Admin memang lintas cabang. Yang
     * ditolak hanya akun School Level dari cabang yang berbeda (butir 276).
     */
    protected function reasonToRejectCreator(Exam $exam): ?string
    {
        if ($exam->created_by === null) {
            return null;
        }

        $creator = $this->find(User::class, $exam->created_by);

        if ($creator === null) {
            return 'Akun pembuat ujian tidak ditemukan.';
        }

        if ($creator->school_id === null) {
            return null;
        }

        if ((int) $creator->school_id !== (int) $exam->school_id) {
            return 'Akun pembuat ujian berasal dari cabang lain.';
        }

        return null;
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel|null
     */
    protected function find(string $model, mixed $key): ?Model
    {
        if ($key === null) {
            return null;
        }

        return $model::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->find($key);
    }
}
