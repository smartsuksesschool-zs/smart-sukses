<?php

namespace App\Services\Cbt;

use App\Enums\AssessmentType;
use App\Enums\ExamAttemptStatus;
use App\Enums\GradeType;
use App\Models\ClassSubject;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Grade;
use App\Models\ReportCard;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Jembatan dari hasil CBT ke nilai akademik.
 *
 * Kelas ini berdiri sendiri, dan itu disengaja. C3 menutup ketiga layanan CBT —
 * StudentExamService, ExamAttemptService, ExamScoringService — dengan test yang
 * membaca kodenya dan membuktikan tidak satu pun menyebut `Grade`,
 * `GradeConfig`, atau `ReportCard`. Menaruh jembatan ini di dalam salah satunya
 * akan melumpuhkan penjagaan itu tepat ketika ia paling dibutuhkan. Yang
 * mengetahui nilai akademik hanya berkas ini (butir 326).
 *
 * Keputusan pemilik (R-1): pengumpulan siswa tidak pernah menghasilkan nilai
 * akademik. Angka berpindah hanya ketika seorang guru menekan "Masukkan ke
 * Nilai", memilih jenis nilainya, dan memilih apakah nilai itu dihitung.
 *
 * Yang **tidak** dikerjakan kelas ini, dengan sengaja: ia tidak menghitung
 * bobot, tidak menyentuh GradeConfig, dan tidak membuat ulang rapor. Ia
 * menempatkan satu baris `grades` lewat jalur yang sama persis dengan input
 * nilai biasa, lalu berhenti — sehingga seluruh semantik penilaian yang sudah
 * ada tetap yang berlaku, bukan salinannya (butir 327).
 */
class ExamGradeBridge
{
    /**
     * Jenis nilai yang ditawarkan untuk hasil CBT.
     *
     * ATTITUDE dikecualikan karena bukan nilai akademik — ia dilaporkan sebagai
     * predikat terpisah (keputusan Sprint 4 butir 3).
     *
     * SKILL juga tidak ditawarkan, dan itu keputusan yang perlu dijelaskan.
     * Seluruh `smartsukses-docs/` menyebut SKILL tepat satu kali, yaitu sebagai
     * nilai enum di ERD; tidak ada satu pun sumber yang menyatakan tes objektif
     * termasuk penilaian keterampilan. Menawarkannya berarti menebak makna
     * sebuah komponen rapor, dan tebakan itu akan tercetak di rapor siswa.
     * Ujian pilihan ganda dinilai sebagai pengetahuan; bila sekolah memang
     * memakainya untuk keterampilan, itu keputusan pemilik yang belum diambil
     * (butir 328).
     *
     * @return array<int, GradeType>
     */
    public static function allowedGradeTypes(): array
    {
        return [
            GradeType::Daily,
            GradeType::Assignment,
            GradeType::Midterm,
            GradeType::Final,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function gradeTypeOptions(): array
    {
        return array_reduce(
            static::allowedGradeTypes(),
            fn (array $carry, GradeType $type) => $carry + [$type->value => $type->label()],
            [],
        );
    }

    public static function allows(GradeType $type): bool
    {
        return in_array($type, static::allowedGradeTypes(), true);
    }

    /**
     * Memindahkan satu hasil CBT menjadi satu baris nilai akademik.
     *
     * Seluruhnya dalam satu transaksi, dan barisnya dikunci lebih dulu: klik
     * ganda maupun dua request bersamaan menghasilkan **tepat satu** nilai
     * (butir 329).
     *
     * @throws ValidationException
     */
    public function bridge(
        ExamAttempt $attempt,
        User $actor,
        GradeType $gradeType,
        AssessmentType $assessmentType,
        ?string $description = null,
    ): Grade {
        return DB::transaction(function () use ($attempt, $actor, $gradeType, $assessmentType, $description): Grade {
            $locked = ExamAttempt::query()
                ->withoutGlobalScope(SchoolScope::class)
                ->whereKey($attempt->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                $this->refuse('Pengerjaan ujian tidak ditemukan.');
            }

            // Seluruh syarat diperiksa **setelah** penguncian. Memeriksanya
            // sebelum mengunci berarti memeriksa keadaan yang masih dapat
            // berubah di antara pemeriksaan dan penulisan.
            $reason = $this->reasonToRefuse($locked, $actor, $gradeType, $assessmentType);

            if ($reason !== null) {
                $this->refuse($reason);
            }

            $exam = $this->examOf($locked);

            // Jalur pembuatan yang sama persis dengan input nilai biasa:
            // `Grade::booted()` yang mengambil snapshot bobot dan grade_config_id
            // (keputusan Sprint 4 butir 2). Tidak ada insert massal, tidak ada
            // query mentah — keduanya akan melewati snapshot itu (butir 330).
            $grade = Grade::query()->create([
                'school_id' => $exam->school_id,
                'student_id' => $locked->student_id,
                'class_subject_id' => $exam->class_subject_id,
                'academic_year_id' => $exam->academic_year_id,
                'grade_type' => $gradeType->value,
                'assessment_type' => $assessmentType->value,
                'score' => $locked->score,
                'description' => $this->descriptionFor($exam, $description),
                'graded_by' => $actor->getKey(),
                'graded_at' => now(),
            ]);

            $locked->forceFill(['grade_id' => $grade->getKey()])->save();

            return $grade;
        });
    }

    /**
     * Alasan menolak, atau NULL bila hasil ini boleh menjadi nilai.
     *
     * Dipakai dua kali: oleh UI untuk menyembunyikan aksinya, dan oleh
     * `bridge()` di dalam transaksi. Yang kedua yang menegakkan; yang pertama
     * hanya sopan santun (butir 331).
     */
    public function reasonToRefuse(
        ExamAttempt $attempt,
        User $actor,
        ?GradeType $gradeType = null,
        ?AssessmentType $assessmentType = null,
    ): ?string {
        $exam = $this->examOf($attempt);

        if ($exam === null) {
            return 'Ujian tidak ditemukan.';
        }

        if ((int) $exam->school_id !== (int) $attempt->school_id) {
            return 'Pengerjaan dan ujiannya berasal dari cabang berbeda.';
        }

        // Cabang, tahun ajaran, dan pembuat ujiannya — dijawab ExamIntegrity,
        // tidak ditulis ulang di sini.
        if ($reason = app(ExamIntegrity::class)->reasonToRejectExam($exam)) {
            return $reason;
        }

        if ($attempt->status !== ExamAttemptStatus::Submitted) {
            return 'Hanya pengerjaan yang sudah dikumpulkan yang dapat dimasukkan ke nilai.';
        }

        if ($attempt->score === null) {
            return 'Pengerjaan ini belum memiliki nilai.';
        }

        $score = (float) $attempt->score;

        if ($score < Grade::MIN_SCORE || $score > Grade::MAX_SCORE) {
            return 'Nilai ujian berada di luar skala 0–100.';
        }

        if ($attempt->grade_id !== null) {
            return 'Hasil ini sudah dimasukkan ke nilai.';
        }

        if ($gradeType !== null && ! static::allows($gradeType)) {
            return 'Jenis nilai ini tidak dapat dipakai untuk hasil ujian online.';
        }

        if ($gradeType === null && $assessmentType !== null) {
            return 'Jenis nilai belum dipilih.';
        }

        $student = $this->studentOf($attempt);

        if ($student === null) {
            return 'Siswa tidak ditemukan.';
        }

        if ((int) $student->school_id !== (int) $attempt->school_id) {
            return 'Siswa ini terdaftar di cabang lain.';
        }

        if ($reason = $this->reasonActorMayNot($actor, $exam)) {
            return $reason;
        }

        return $this->reasonReportCardForbids($attempt, $exam);
    }

    /**
     * Kewenangan aktor, dari **dua** sisi.
     *
     * Sisi ujian dijawab ExamPolicy; sisi penilaian akademik dijawab
     * GradePolicy — kewenangan yang sama yang menjaga input nilai biasa.
     * Keduanya dipanggil, tidak ada yang ditiru: sebuah jembatan yang
     * memutuskan sendiri siapa yang boleh menilai adalah pintu belakang menuju
     * penilaian akademik (butir 325).
     */
    protected function reasonActorMayNot(User $actor, Exam $exam): ?string
    {
        if (Gate::forUser($actor)->denies('bridgeToGrade', $exam)) {
            return 'Anda tidak berwenang memasukkan hasil ujian ini ke nilai.';
        }

        $classSubject = ClassSubject::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->find($exam->class_subject_id);

        if ($classSubject === null) {
            return 'Kelas–mata pelajaran tidak ditemukan.';
        }

        if (Gate::forUser($actor)->denies('gradeClassSubject', [Grade::class, $classSubject])) {
            return 'Anda tidak berwenang menilai kelas–mata pelajaran ini.';
        }

        return null;
    }

    /**
     * Pagar rapor terbit.
     *
     * Audit pra-implementasi menemukan celah yang nyata: `Grade::isLocked()`
     * hanya menjaga **pengubahan** dan **penghapusan** nilai
     * (`GradePolicy::update`), sedangkan `GradePolicy::create()` tidak pernah
     * menanyakannya. Nilai **baru** karena itu masih dapat lahir setelah rapor
     * terbit.
     *
     * Pada input nilai manual keadaan itu tidak berbahaya: rapor yang sudah
     * terbit dilewati saat generate ulang, dan `ConfigurationGapWarner`
     * memberi tahu gurunya. Pada jembatan ini taruhannya lain — satu tindakan
     * dapat memindahkan sekelas nilai sekaligus, dan tidak seorang pun akan
     * menyadari bahwa riwayat nilai kini bercerita lain daripada rapor yang
     * sudah dipegang orang tua.
     *
     * Karena itu pagarnya dipasang **di sini saja**, lokal pada jembatan.
     * `Grade::isLocked()`, `GradePolicy`, dan `ReportCardGenerator` tidak
     * disentuh sama sekali; celahnya sendiri adalah temuan terbuka yang
     * keputusannya milik pemilik (butir 332).
     */
    protected function reasonReportCardForbids(ExamAttempt $attempt, Exam $exam): ?string
    {
        // Bentuk query yang sama dengan `Grade::isLocked()` — sengaja, dan ada
        // test yang menjaga keduanya tetap sependapat.
        $published = ReportCard::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('student_id', $attempt->student_id)
            ->where('academic_year_id', $exam->academic_year_id)
            ->published()
            ->exists();

        return $published
            ? 'Rapor siswa ini pada tahun ajaran tersebut sudah diterbitkan, sehingga nilai baru tidak dapat ditambahkan.'
            : null;
    }

    /**
     * Keterangan yang membuat asal-usul nilainya terbaca.
     *
     * Memakai kolom `description` yang memang sudah ada; tidak ada migrasi yang
     * ditambahkan hanya untuk mencatat sumber (butir 333).
     */
    protected function descriptionFor(Exam $exam, ?string $override): string
    {
        $text = filled($override) ? $override : 'CBT: '.$exam->title;

        return Str::limit($text, 200, '');
    }

    protected function examOf(ExamAttempt $attempt): ?Exam
    {
        return Exam::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->find($attempt->exam_id);
    }

    protected function studentOf(ExamAttempt $attempt): ?Student
    {
        return Student::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->find($attempt->student_id);
    }

    /**
     * @throws ValidationException
     */
    protected function refuse(string $message): never
    {
        throw ValidationException::withMessages(['grade' => $message]);
    }
}
