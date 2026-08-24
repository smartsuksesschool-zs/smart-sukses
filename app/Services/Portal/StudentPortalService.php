<?php

namespace App\Services\Portal;

use App\Enums\DayOfWeek;
use App\Enums\RoleName;
use App\Enums\StudentClassStatus;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\ReportCard;
use App\Models\Schedule;
use App\Models\SchoolClass;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Services\Grading\FinalScoreCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * PORTAL-03 / API 4.11 — Student Portal.
 *
 * Satu-satunya tempat "siswa yang sedang login" diputuskan, dipakai bersama
 * ketiga endpoint REST dan seluruh halaman portal. Identitasnya **selalu**
 * berasal dari akun yang login — tidak pernah dari `student_id`, NIS, maupun
 * apa pun yang dikirim pemanggil (butir 181).
 *
 * Seluruh kelas ini hanya membaca.
 */
class StudentPortalService
{
    /** API 4.11 — "5 nilai terbaru" pada dashboard siswa. */
    public const SUMMARY_SUBJECTS = 5;

    public function __construct(protected FinalScoreCalculator $finalScore) {}

    /**
     * Data siswa milik akun yang sedang login.
     *
     * Dua syarat, keduanya wajib: `students.user_id` menunjuk akun ini, dan
     * cabang siswa sama dengan cabang akunnya. Syarat kedua menutup akun yang
     * pernah dipindahkan cabang namun tautannya tertinggal.
     *
     * `students.user_id` boleh NULL menurut ERD ("NULL jika siswa belum punya
     * akun portal"), jadi ada akun berperan SISWA yang belum tertaut ke data
     * siswa mana pun. Keadaan itu bukan kesalahan sistem dan bukan alasan
     * mengambil siswa lain: yang terjadi `ModelNotFoundException`, dan halaman
     * portalnya menampilkan keterangan yang jelas (butir 182).
     *
     * @throws AuthorizationException|ModelNotFoundException
     */
    public function student(User $user): Student
    {
        $this->authorize($user);

        return once(fn () => Student::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('user_id', $user->getKey())
            ->where('school_id', $user->school_id)
            ->with(['school'])
            ->firstOrFail());
    }

    /**
     * Apakah akun ini sudah tertaut ke data siswa.
     *
     * Dipakai halaman portal supaya dapat menampilkan keterangan alih-alih
     * halaman kesalahan.
     */
    public function hasLinkedStudent(User $user): bool
    {
        try {
            $this->student($user);

            return true;
        } catch (ModelNotFoundException) {
            return false;
        }
    }

    /**
     * API 4.11 — "Dashboard siswa: jadwal hari ini, 5 nilai terbaru,
     * notifikasi".
     *
     * @return array<string, mixed>
     *
     * @throws AuthorizationException|ModelNotFoundException
     */
    public function dashboard(User $user): array
    {
        $student = $this->student($user);
        $year = $this->activeAcademicYear($student);
        $today = CarbonImmutable::now();
        $day = $this->dayOfWeek($today);

        return [
            'student' => $student,
            'academic_year' => $year,
            'current_class' => $this->currentClass($student),
            'today' => [
                'date' => $today->toDateString(),
                'day_of_week' => $day->value,
                'day_label' => $day->label(),
            ],
            'today_schedule' => $this->lessons($student, $day),
            'latest_grades' => array_slice($this->subjectGrades($student), 0, self::SUMMARY_SUBJECTS),
            'notifications' => $this->notifications(),
        ];
    }

    /**
     * API 4.11 — "Jadwal pelajaran siswa (tahun ajaran aktif)".
     *
     * @return array<string, mixed>
     *
     * @throws AuthorizationException|ModelNotFoundException
     */
    public function schedule(User $user): array
    {
        $student = $this->student($user);
        $today = $this->dayOfWeek(CarbonImmutable::now());

        return [
            'student' => $student,
            'academic_year' => $this->activeAcademicYear($student),
            'current_class' => $this->currentClass($student),
            'today' => $today->value,
            'lessons' => $this->lessons($student, null),
        ];
    }

    /**
     * API 4.11 — "Nilai siswa yang login (tahun ajaran aktif)".
     *
     * PORTAL-03 poin 2 meminta nilai per mata pelajaran, **per semester**, dan
     * per komponen. Semesternya nyata: `academic_years.semester` bernilai 1
     * atau 2 menurut ERD, dan tahun ajaran aktiflah yang membawanya — tidak ada
     * semester yang dikarang (butir 184).
     *
     * @return array<string, mixed>
     *
     * @throws AuthorizationException|ModelNotFoundException
     */
    public function grades(User $user): array
    {
        $student = $this->student($user);
        $year = $this->activeAcademicYear($student);

        return [
            'student' => $student,
            'academic_year' => $year,
            'current_class' => $this->currentClass($student),
            'subjects' => $this->subjectGrades($student),
            // NILAI-04 poin 2 — rapor final hanya setelah wali kelas
            // menerbitkannya; yang masih draf tidak pernah keluar (butir 162).
            'report_card' => $year === null
                ? null
                : $this->publishedReportCard($student, (int) $year->getKey()),
        ];
    }

    /**
     * Rincian nilai per mata pelajaran pada tahun ajaran aktif.
     *
     * Satu query untuk seluruh nilai siswa, lalu dikelompokkan di memori —
     * pola dan semantik yang sama dengan portal orang tua (butir 151), memakai
     * `FinalScoreCalculator` yang sama dengan panel dan rapor. Tidak ada rumus
     * nilai kedua di project ini.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function subjectGrades(Student $student): array
    {
        $year = $this->activeAcademicYear($student);

        if ($year === null) {
            return [];
        }

        $grades = Grade::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $student->school_id)
            ->where('student_id', $student->getKey())
            ->where('academic_year_id', $year->getKey())
            ->with('classSubject.subject')
            ->orderBy('graded_at')
            ->orderBy('id')
            ->get();

        return $grades
            ->filter(fn (Grade $grade) => $grade->classSubject?->subject !== null)
            ->groupBy(fn (Grade $grade) => $grade->classSubject->subject->getKey())
            ->map(function ($subjectGrades) {
                $subject = $subjectGrades->first()->classSubject->subject;
                $result = $this->finalScore->calculate($this->asEloquentCollection($subjectGrades));

                return [
                    'subject_id' => $subject->getKey(),
                    'subject_code' => $subject->code,
                    'subject_name' => $subject->name,
                    'final_score' => $result->score,
                    'is_complete' => $result->isComplete,
                    'components' => $subjectGrades
                        ->map(fn (Grade $grade) => [
                            'id' => $grade->getKey(),
                            'grade_type' => $grade->grade_type?->value,
                            'grade_type_label' => $grade->grade_type?->label(),
                            'assessment_type' => $grade->assessment_type?->value,
                            'assessment_type_label' => $grade->assessment_type?->label(),
                            'score' => $grade->score === null ? null : (float) $grade->score,
                            'description' => $grade->description,
                            'graded_at' => $grade->graded_at?->toIso8601String(),
                        ])
                        ->values()
                        ->all(),
                    'latest_graded_at' => $subjectGrades
                        ->max(fn (Grade $grade) => $grade->graded_at?->toIso8601String()),
                ];
            })
            // "5 nilai terbaru" berarti lima mapel dengan penilaian terakhir
            // paling baru — bukan lima baris penilaian, yang akan membuat lima
            // ulangan pada satu mapel memenuhi seluruh kartu (butir 151).
            ->sortByDesc('latest_graded_at')
            ->values()
            ->all();
    }

    /**
     * Jadwal kelas siswa ini, seluruh minggu atau satu hari saja.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function lessons(Student $student, ?DayOfWeek $day): array
    {
        $class = $this->currentClass($student);

        if ($class === null) {
            return [];
        }

        return Schedule::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $student->school_id)
            ->whereHas('classSubject', fn (Builder $query) => $query
                ->withoutGlobalScope(SchoolScope::class)
                ->where('class_id', $class->getKey()))
            ->when($day !== null, fn (Builder $query) => $query->where('day_of_week', $day->value))
            ->with(['classSubject.subject', 'classSubject.teacher'])
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->orderBy('end_time')
            ->orderBy('id')
            ->get()
            ->map(fn (Schedule $schedule) => [
                'id' => $schedule->getKey(),
                'day_of_week' => $schedule->day_of_week?->value,
                'day_label' => $schedule->day_of_week?->label(),
                'start_time' => $this->clock($schedule->start_time),
                'end_time' => $this->clock($schedule->end_time),
                'subject_name' => $schedule->classSubject?->subject?->name,
                'subject_code' => $schedule->classSubject?->subject?->code,
                // Nama guru saja — surel, telepon, dan id akunnya bukan urusan
                // siswa (butir 165).
                'teacher_name' => $schedule->classSubject?->teacher?->name,
                'room' => $schedule->room,
            ])
            ->all();
    }

    /**
     * Kelas siswa pada tahun ajaran aktif.
     *
     * Penempatan berstatus ACTIVE pada tahun ajaran aktif — bukan baris
     * `student_classes` terakhir, yang pada siswa yang pernah pindah kelas
     * belum tentu yang berlaku sekarang (butir 164).
     */
    public function currentClass(Student $student): ?SchoolClass
    {
        $year = $this->activeAcademicYear($student);

        if ($year === null) {
            return null;
        }

        return once(fn () => StudentClass::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $student->school_id)
            ->where('student_id', $student->getKey())
            ->where('academic_year_id', $year->getKey())
            ->where('status', StudentClassStatus::Active->value)
            ->with('schoolClass')
            ->latest('id')
            ->first()?->schoolClass);
    }

    /**
     * Rapor siswa ini yang sudah diterbitkan, pada tahun ajaran aktif.
     */
    protected function publishedReportCard(Student $student, int $academicYearId): ?ReportCard
    {
        return ReportCard::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $student->school_id)
            ->where('student_id', $student->getKey())
            ->where('academic_year_id', $academicYearId)
            ->published()
            ->with('schoolClass')
            ->first();
    }

    /**
     * Rapor yang boleh diunduh siswa ini.
     *
     * Identitasnya diresolusi lebih dulu, lalu rapornya wajib milik siswa itu
     * dan sudah terbit. Sama seperti pada portal orang tua, kepemilikannya
     * tidak digantung pada policy saja (butir 162, 185).
     *
     * @throws AuthorizationException|ModelNotFoundException
     */
    public function publishedReportCardFor(User $user, int $reportCardId): ReportCard
    {
        $student = $this->student($user);

        return ReportCard::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $student->school_id)
            ->where('student_id', $student->getKey())
            ->published()
            ->with(['student', 'schoolClass', 'academicYear', 'school'])
            ->findOrFail($reportCardId);
    }

    /**
     * PORTAL-03 meminta menu Notifikasi dan API 4.11 meminta notifikasi pada
     * dashboard; subsistemnya milik Sprint 8 dan belum ada.
     *
     * Bentuknya sama dengan kehadiran (butir 152) dan notifikasi guru
     * (butir 175): keadaan "belum tersedia" yang eksplisit, bukan angka nol
     * yang terbaca sebagai "tidak ada notifikasi".
     *
     * @return array{available: bool, reason: string, unread_count: null, items: array<int, mixed>}
     */
    protected function notifications(): array
    {
        return [
            'available' => false,
            'reason' => 'notification_module_not_available',
            'unread_count' => null,
            'items' => [],
        ];
    }

    public function activeAcademicYear(Student $student): ?AcademicYear
    {
        return once(fn () => AcademicYear::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $student->school_id)
            ->active()
            ->first());
    }

    protected function dayOfWeek(CarbonImmutable $moment): DayOfWeek
    {
        $day = (int) $moment->dayOfWeek;

        return DayOfWeek::from($day === 0 ? DayOfWeek::Sunday->value : $day);
    }

    protected function clock(mixed $time): ?string
    {
        return blank($time) ? null : substr((string) $time, 0, 5);
    }

    /**
     * @param  Collection<int, Grade>|EloquentCollection<int, Grade>  $grades
     * @return EloquentCollection<int, Grade>
     */
    protected function asEloquentCollection($grades): EloquentCollection
    {
        return $grades instanceof EloquentCollection
            ? $grades
            : new EloquentCollection($grades->all());
    }

    /**
     * Portal ini milik SISWA saja.
     *
     * "Auth Level: Auth" pada API map berarti wajib token, bukan boleh dipakai
     * setiap peran yang punya token — sama seperti pada portal orang tua dan
     * guru (butir 148, 171). Orang tua punya jalurnya sendiri lewat
     * `/parent/children`, dan admin punya panel.
     *
     * @throws AuthorizationException
     */
    protected function authorize(User $user): void
    {
        if (! $user->hasRole(RoleName::Siswa->value)) {
            throw new AuthorizationException('Portal ini hanya untuk akun siswa.');
        }

        if ($user->school_id === null) {
            throw new AuthorizationException('Akun Anda belum terhubung ke cabang mana pun.');
        }
    }
}
