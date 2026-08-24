<?php

namespace App\Services\Portal;

use App\Enums\DayOfWeek;
use App\Enums\RoleName;
use App\Enums\StudentClassStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Schedule;
use App\Models\SchoolClass;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * PORTAL-02 / API 4.11 — Teacher Portal.
 *
 * Satu-satunya tempat "kelas yang diampu" didefinisikan, dipakai bersama
 * endpoint REST, halaman portal, dan pagar baris di panel. Kalau ketiganya
 * menghitung sendiri, "kelas ajar" akan punya tiga arti yang perlahan berbeda.
 *
 * Seluruh kelas ini hanya membaca.
 */
class TeacherPortalService
{
    /**
     * Kelas yang benar-benar diampu: penugasan mengajar pada tahun ajaran
     * aktif.
     *
     * Bukan seluruh kelas cabang, bukan `homeroom_teacher_id` saja, bukan dari
     * jadwal, dan bukan penugasan tahun lalu. Definisi kanoniknya
     * `class_subjects.teacher_id` + tahun ajaran aktif + cabang yang sama
     * (butir 172).
     *
     * @return array<int, int>
     */
    public function teachingClassIds(User $teacher): array
    {
        $year = $this->activeAcademicYear($teacher);

        if ($year === null) {
            return [];
        }

        return ClassSubject::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $teacher->school_id)
            ->where('teacher_id', $teacher->getKey())
            ->where('academic_year_id', $year->getKey())
            ->distinct()
            ->pluck('class_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * API 4.11 — "Kelas yang diampu guru yang login (tahun ajaran aktif)".
     *
     * Satu kelas muncul **sekali** walaupun guru itu mengajar beberapa mata
     * pelajaran di sana; mata pelajarannya dikumpulkan di dalam barisnya.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws AuthorizationException
     */
    public function classes(User $teacher): array
    {
        $this->authorize($teacher);

        $year = $this->activeAcademicYear($teacher);

        if ($year === null) {
            return [];
        }

        // Satu query untuk seluruh penugasan, satu lagi untuk jumlah siswanya.
        // Tidak ada query per kelas maupun per mata pelajaran.
        $assignments = ClassSubject::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $teacher->school_id)
            ->where('teacher_id', $teacher->getKey())
            ->where('academic_year_id', $year->getKey())
            ->with(['schoolClass', 'subject'])
            ->get()
            ->filter(fn (ClassSubject $assignment) => $assignment->schoolClass !== null);

        $studentCounts = $this->studentCounts(
            $teacher,
            $assignments->pluck('class_id')->unique()->all(),
            (int) $year->getKey(),
        );

        return $assignments
            ->groupBy('class_id')
            ->map(function ($group) use ($studentCounts): array {
                $class = $group->first()->schoolClass;

                return [
                    'class' => [
                        'id' => $class->getKey(),
                        'name' => $class->name,
                        'grade_level' => $class->grade_level,
                        'room' => $class->room,
                    ],
                    'subjects' => $group
                        ->filter(fn (ClassSubject $assignment) => $assignment->subject !== null)
                        ->map(fn (ClassSubject $assignment) => [
                            'id' => $assignment->subject->getKey(),
                            'code' => $assignment->subject->code,
                            'name' => $assignment->subject->name,
                        ])
                        ->sortBy('name')
                        ->values()
                        ->all(),
                    'student_count' => $studentCounts[$class->getKey()] ?? 0,
                ];
            })
            // Urutan tetap: tingkat, lalu nama, lalu id sebagai pemutus.
            // Kunci gabungan dipakai supaya perbandingannya satu nilai — dan
            // tingkat yang belum diisi tidak membuat urutannya berpindah-pindah
            // antar permintaan.
            ->sortBy(fn (array $row) => sprintf(
                '%03d|%s|%010d',
                (int) ($row['class']['grade_level'] ?? 0),
                (string) $row['class']['name'],
                $row['class']['id'],
            ))
            ->values()
            ->all();
    }

    /**
     * Jumlah siswa aktif per kelas, satu query untuk seluruh kelas sekaligus.
     *
     * @param  array<int, int>  $classIds
     * @return array<int, int>
     */
    protected function studentCounts(User $teacher, array $classIds, int $academicYearId): array
    {
        if ($classIds === []) {
            return [];
        }

        return Student::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('students.school_id', $teacher->school_id)
            // Kolomnya ditulis lengkap, bukan lewat scope `active()`: query ini
            // menggabungkan dua tabel yang sama-sama punya kolom `status`.
            ->where('students.status', StudentStatus::Active->value)
            ->join('student_classes', 'student_classes.student_id', '=', 'students.id')
            ->whereIn('student_classes.class_id', $classIds)
            ->where('student_classes.academic_year_id', $academicYearId)
            ->where('student_classes.status', StudentClassStatus::Active->value)
            ->selectRaw('student_classes.class_id as class_id, COUNT(DISTINCT students.id) as total')
            ->groupBy('student_classes.class_id')
            ->pluck('total', 'class_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * API 4.11 — "Dashboard guru: jadwal hari ini, kelas aktif, notifikasi
     * masuk".
     *
     * @return array{
     *     teacher: User,
     *     academic_year: AcademicYear|null,
     *     today: array{date: string, day_of_week: int, day_label: string},
     *     today_schedule: array<int, array<string, mixed>>,
     *     active_classes: array<int, array<string, mixed>>,
     *     homeroom_class: SchoolClass|null,
     *     notifications: array{available: bool, reason: string, unread_count: null, items: array<int, mixed>}
     * }
     *
     * @throws AuthorizationException
     */
    public function dashboard(User $teacher): array
    {
        $this->authorize($teacher);

        $today = CarbonImmutable::now();
        $day = $this->dayOfWeek($today);

        return [
            'teacher' => $teacher,
            'academic_year' => $this->activeAcademicYear($teacher),
            'today' => [
                'date' => $today->toDateString(),
                'day_of_week' => $day->value,
                'day_label' => $day->label(),
            ],
            'today_schedule' => $this->scheduleFor($teacher, $day),
            // "Kelas aktif" memakai koleksi kanonik yang sama dengan
            // /teacher/classes — bukan tafsir kedua (butir 172).
            'active_classes' => $this->classes($teacher),
            'homeroom_class' => $this->homeroomClass($teacher),
            'notifications' => $this->notifications(),
        ];
    }

    /**
     * Jadwal mengajar guru ini, seluruh minggu atau satu hari saja.
     *
     * Hanya jadwal miliknya sendiri: penyaringnya `class_subjects.teacher_id`,
     * bukan kelasnya — dua guru dapat mengajar di kelas yang sama, dan jadwal
     * guru lain bukan urusannya (butir 174).
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws AuthorizationException
     */
    public function schedule(User $teacher, ?DayOfWeek $day = null): array
    {
        $this->authorize($teacher);

        return $this->scheduleFor($teacher, $day);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function scheduleFor(User $teacher, ?DayOfWeek $day): array
    {
        $year = $this->activeAcademicYear($teacher);

        if ($year === null) {
            return [];
        }

        return Schedule::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $teacher->school_id)
            ->whereHas('classSubject', fn (Builder $query) => $query
                ->withoutGlobalScope(SchoolScope::class)
                ->where('teacher_id', $teacher->getKey())
                ->where('academic_year_id', $year->getKey()))
            ->when($day !== null, fn (Builder $query) => $query->where('day_of_week', $day->value))
            ->with(['classSubject.subject', 'classSubject.schoolClass'])
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
                'class_id' => $schedule->classSubject?->schoolClass?->getKey(),
                'class_name' => $schedule->classSubject?->schoolClass?->name,
                'subject_name' => $schedule->classSubject?->subject?->name,
                'subject_code' => $schedule->classSubject?->subject?->code,
                'room' => $schedule->room,
            ])
            ->all();
    }

    /**
     * Satu kelas yang diampu guru ini — untuk halaman daftar siswa.
     *
     * Kelas yang tidak diampunya menjadi 404, termasuk kelas lain di cabang
     * yang sama: guru tidak boleh memeriksa kelas sembarangan hanya dengan
     * mengganti angka di alamat (butir 173).
     *
     * @throws AuthorizationException|ModelNotFoundException
     */
    public function teachingClass(User $teacher, int $classId): SchoolClass
    {
        $this->authorize($teacher);

        if (! in_array($classId, $this->teachingClassIds($teacher), true)) {
            throw new ModelNotFoundException;
        }

        return SchoolClass::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $teacher->school_id)
            ->findOrFail($classId);
    }

    /**
     * SIS-04 — "daftar siswa di kelas yang saya ampu", hanya siswa aktif.
     *
     * @return Collection<int, Student>
     *
     * @throws AuthorizationException|ModelNotFoundException
     */
    public function classStudents(User $teacher, int $classId): Collection
    {
        $class = $this->teachingClass($teacher, $classId);
        $year = $this->activeAcademicYear($teacher);

        if ($year === null) {
            return new Collection;
        }

        return Student::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('students.school_id', $teacher->school_id)
            ->active()
            ->whereHas('studentClasses', fn (Builder $query) => $query
                ->withoutGlobalScope(SchoolScope::class)
                ->where('class_id', $class->getKey())
                ->where('academic_year_id', $year->getKey())
                ->where('status', StudentClassStatus::Active->value))
            ->orderBy('full_name')
            ->get();
    }

    /**
     * Kelas yang diwalikan guru ini pada tahun ajaran aktif, bila ada.
     *
     * Sengaja terpisah dari daftar kelas ajar. Wali kelas yang tidak mengajar
     * satu pun mata pelajaran di kelas perwaliannya **tidak** dibuat seolah
     * mengajar di sana — penugasan mengajar dan perwalian dua hal berbeda, dan
     * memalsukan yang satu menjadi yang lain akan membuat `/teacher/classes`
     * mengembalikan kelas yang tidak punya mata pelajaran (butir 172).
     */
    public function homeroomClass(User $teacher): ?SchoolClass
    {
        $year = $this->activeAcademicYear($teacher);

        if ($year === null) {
            return null;
        }

        return SchoolClass::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $teacher->school_id)
            ->where('academic_year_id', $year->getKey())
            ->where('homeroom_teacher_id', $teacher->getKey())
            ->first();
    }

    /**
     * API 4.11 menyebut "notifikasi masuk" pada dashboard guru, dan
     * subsistemnya milik Sprint 8 — belum ada tabel, belum ada modulnya.
     *
     * Yang dikembalikan keadaan "belum tersedia" secara eksplisit, bentuk yang
     * sama dengan kehadiran pada Batch 7.1 (butir 152). Angka nol akan terbaca
     * sebagai "tidak ada notifikasi", padahal yang benar adalah "belum ada
     * cara mengetahuinya" (butir 175).
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

    protected function activeAcademicYear(User $teacher): ?AcademicYear
    {
        if ($teacher->school_id === null) {
            return null;
        }

        return once(fn () => AcademicYear::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $teacher->school_id)
            ->active()
            ->first());
    }

    /**
     * Penomoran hari mengikuti ERD (1 = Senin … 7 = Minggu); `Carbon` menomori
     * Minggu sebagai 0. Konversinya sama dengan jadwal orang tua (butir 165).
     */
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
     * Portal ini milik GURU dan WALI_KELAS.
     *
     * Wali kelas memegang seluruh akses guru ditambah tanggung jawab
     * perwaliannya (PRD 1.1.1), jadi keduanya masuk. Peran lain ditolak:
     * "Auth Level: Auth" pada API map berarti wajib token, bukan boleh dipakai
     * setiap peran yang punya token (butir 171).
     *
     * @throws AuthorizationException
     */
    protected function authorize(User $teacher): void
    {
        $isTeacher = $teacher->hasRole(RoleName::Guru->value)
            || $teacher->hasRole(RoleName::WaliKelas->value);

        if (! $isTeacher) {
            throw new AuthorizationException('Portal ini hanya untuk guru dan wali kelas.');
        }

        if ($teacher->school_id === null) {
            throw new AuthorizationException('Akun Anda belum terhubung ke cabang mana pun.');
        }
    }
}
