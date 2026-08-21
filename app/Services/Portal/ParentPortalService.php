<?php

namespace App\Services\Portal;

use App\Enums\DayOfWeek;
use App\Enums\RoleName;
use App\Enums\StudentClassStatus;
use App\Enums\StudentFeeStatus;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\ReportCard;
use App\Models\Schedule;
use App\Models\SchoolClass;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentFee;
use App\Models\User;
use App\Services\Grading\FinalScoreCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * PORTAL-01 / API 4.11 — Parent Portal.
 *
 * Satu-satunya tempat kepemilikan anak diputuskan dan ringkasannya disusun,
 * dipakai bersama oleh endpoint REST dan halaman portal. Kalau keduanya
 * menyusun querynya sendiri, syarat kepemilikan akan hidup di dua tempat — dan
 * yang satu cepat atau lambat akan tertinggal dari yang lain.
 *
 * Seluruh kelas ini **hanya membaca**. Tidak ada nilai yang dihitung ulang
 * dengan rumus baru, tidak ada rapor yang disentuh, dan tidak ada satu pun
 * baris yang ditulis.
 */
class ParentPortalService
{
    /**
     * API 4.11 — "nilai terbaru 5 mapel".
     *
     * PORTAL-01 menyebut "3 nilai terbaru" untuk tampilan dashboard. Keduanya
     * tidak dipertentangkan: service menyediakan lima, API mengembalikan lima
     * sesuai kontraknya, dan dashboard menampilkan tiga teratas dari data yang
     * sama (butir 150).
     */
    public const SUMMARY_SUBJECTS = 5;

    /** PORTAL-01 poin 1 — "3 nilai terbaru" di dashboard. */
    public const DASHBOARD_SUBJECTS = 3;

    /** Mengikuti DECIMAL(12,2) pada ERD. */
    protected const SCALE = 2;

    public function __construct(protected FinalScoreCalculator $finalScore) {}

    /**
     * Anak-anak yang benar-benar milik orang tua ini.
     *
     * Dua syarat, dan keduanya wajib: `parent_user_id` menunjuk akun ini, dan
     * `school_id` anak sama dengan cabang akun ini. Syarat kedua bukan
     * kelebihan — tanpa itu, akun yang berpindah cabang akan tetap membawa
     * anak dari cabang lamanya (butir 148).
     *
     * Yang dikembalikan **seluruh** anak yang tertaut, bukan hanya yang
     * berstatus ACTIVE: dokumen tidak menetapkan penyaringan, dan menyaring
     * akan membuat anak yang sudah lulus menghilang dari portal orang tuanya
     * bersama seluruh riwayat tagihannya (butir 149).
     *
     * @return Collection<int, Student>
     *
     * @throws AuthorizationException
     */
    public function children(User $parent): Collection
    {
        $this->authorize($parent);

        return $this->ownedQuery($parent)
            // Dimuat sekaligus, bukan per anak: orang tua dengan sepuluh anak
            // tidak boleh menghasilkan sepuluh query tambahan.
            ->with(['activeStudentClass.schoolClass', 'activeStudentClass.academicYear'])
            ->orderBy('full_name')
            ->get();
    }

    /**
     * Satu anak milik orang tua ini.
     *
     * Anak orang tua lain dan anak cabang lain sama-sama menjadi
     * ModelNotFoundException, bukan penolakan izin: membedakan keduanya berarti
     * memberi tahu bahwa anak itu ada (butir 148).
     *
     * @throws AuthorizationException|ModelNotFoundException
     */
    public function child(User $parent, int $studentId): Student
    {
        $this->authorize($parent);

        return $this->ownedQuery($parent)
            ->with(['activeStudentClass.schoolClass', 'activeStudentClass.academicYear'])
            ->findOrFail($studentId);
    }

    /**
     * API 4.11 — "Dashboard anak: nilai terbaru 5 mapel, hadir bulan ini,
     * tagihan pending".
     *
     * @return array{
     *     child: Student,
     *     latest_grades: array<int, array<string, mixed>>,
     *     attendance: array{available: bool, reason: string, present_count: null},
     *     pending_fees: array{count: int, outstanding_amount: string}
     * }
     *
     * @throws AuthorizationException|ModelNotFoundException
     */
    public function summary(User $parent, int $studentId): array
    {
        $child = $this->child($parent, $studentId);

        return [
            'child' => $child,
            'latest_grades' => $this->latestGrades($child),
            'attendance' => $this->attendance(),
            'pending_fees' => $this->pendingFees($child),
        ];
    }

    /**
     * NILAI-04 / API 4.11 — "Nilai lengkap anak per tahun ajaran aktif".
     *
     * Dua hal hidup berdampingan di sini, dan itu memang bunyi user story-nya:
     * nilai real-time **selalu** terlihat ("tampil segera setelah guru
     * menyimpan"), sedangkan rapor final hanya muncul setelah wali kelas
     * menerbitkannya. Yang satu tidak menggantikan yang lain — rapor yang
     * terbit tidak menyembunyikan komponen nilainya, dan komponen nilai tidak
     * menggantikan angka rapor (butir 160).
     *
     * @return array{
     *     child: Student,
     *     academic_year: AcademicYear|null,
     *     subjects: array<int, array<string, mixed>>,
     *     report_card: ReportCard|null
     * }
     *
     * @throws AuthorizationException|ModelNotFoundException
     */
    public function grades(User $parent, int $studentId): array
    {
        $child = $this->child($parent, $studentId);
        $year = $this->activeAcademicYear((int) $child->school_id);

        if ($year === null) {
            // Cabang tanpa tahun ajaran aktif: respons tetap sah dan kosong,
            // bukan 500 dan bukan tahun ajaran lain yang dipilihkan diam-diam
            // (butir 153).
            return [
                'child' => $child,
                'academic_year' => null,
                'subjects' => [],
                'report_card' => null,
            ];
        }

        return [
            'child' => $child,
            'academic_year' => $year,
            'subjects' => $this->subjectGrades($child, (int) $year->getKey()),
            'report_card' => $this->publishedReportCard($child, (int) $year->getKey()),
        ];
    }

    /**
     * Rincian nilai per mata pelajaran pada satu tahun ajaran.
     *
     * Satu query untuk seluruh nilai anak, lalu dikelompokkan di memori.
     * Menambah mata pelajaran atau menambah ulangan tidak menambah query.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function subjectGrades(Student $child, int $academicYearId): array
    {
        $grades = Grade::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $child->school_id)
            ->where('student_id', $child->getKey())
            ->where('academic_year_id', $academicYearId)
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
                    // Rincian komponen dipertahankan apa adanya: jenis penilaian
                    // dan sifat formatif/sumatifnya adalah perbedaan yang
                    // bermakna bagi pembacanya, dan meringkasnya menjadi satu
                    // angka akan menghilangkan alasan angka itu terbentuk
                    // (butir 161).
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
                ];
            })
            ->sortBy('subject_name')
            ->values()
            ->all();
    }

    /**
     * Rapor anak pada tahun ajaran itu — hanya bila sudah diterbitkan.
     *
     * NILAI-04 poin 2: "Rapor final hanya tampil setelah Wali Kelas
     * menerbitkan". Rapor draf tidak pernah keluar lewat portal, dalam bentuk
     * apa pun (butir 162).
     */
    protected function publishedReportCard(Student $child, int $academicYearId): ?ReportCard
    {
        return ReportCard::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $child->school_id)
            ->where('student_id', $child->getKey())
            ->where('academic_year_id', $academicYearId)
            ->published()
            ->with('schoolClass')
            ->first();
    }

    /**
     * Rapor yang boleh diunduh orang tua ini.
     *
     * Kepemilikan diputuskan di sini, bukan oleh ReportCardPolicy. Policy itu
     * memberi ORANG_TUA `report_card.view` untuk **seluruh cabang** —
     * memadai bagi peran panel yang memang melihat semua siswa, tetapi jauh
     * terlalu longgar bagi orang tua, yang hanya boleh melihat rapor anaknya
     * sendiri. Karena itu anaknya diresolusi lebih dulu lewat pagar Batch 7.1,
     * dan rapornya wajib milik anak itu serta sudah terbit (butir 162).
     *
     * @throws AuthorizationException|ModelNotFoundException
     */
    public function publishedReportCardFor(User $parent, int $studentId, int $reportCardId): ReportCard
    {
        $child = $this->child($parent, $studentId);

        return ReportCard::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $child->school_id)
            ->where('student_id', $child->getKey())
            ->published()
            ->with(['student', 'schoolClass', 'academicYear', 'school'])
            ->findOrFail($reportCardId);
    }

    /**
     * SPP-04 / API 4.11 — "Semua tagihan anak + status + riwayat bayar".
     *
     * @return array{child: Student, fees: EloquentCollection<int, StudentFee>}
     *
     * @throws AuthorizationException|ModelNotFoundException
     */
    public function fees(User $parent, int $studentId): array
    {
        $child = $this->child($parent, $studentId);

        return [
            'child' => $child,
            'fees' => StudentFee::query()
                ->withoutGlobalScope(SchoolScope::class)
                ->where('school_id', $child->school_id)
                ->where('student_id', $child->getKey())
                // Scope yang sudah ada sejak Batch 5.4, memang disiapkan untuk
                // konsumen berikutnya: siswa, jenis tagihan, dan seluruh
                // riwayat pembayarannya dimuat sekaligus.
                ->withBillingDetail()
                // SPP-04 poin 1 — "tampil dalam daftar per periode". Yang
                // terbaru lebih dulu karena itu yang sedang berjalan; `id`
                // sebagai pemutus supaya dua tagihan pada periode yang sama
                // selalu berurutan tetap (butir 163).
                ->orderByDesc('period')
                ->orderByDesc('due_date')
                ->orderByDesc('id')
                ->get(),
        ];
    }

    /**
     * API 4.11 — "Jadwal pelajaran kelas anak hari ini & minggu ini".
     *
     * @return array{
     *     child: Student,
     *     academic_year: AcademicYear|null,
     *     current_class: SchoolClass|null,
     *     today: int,
     *     lessons: array<int, array<string, mixed>>
     * }
     *
     * @throws AuthorizationException|ModelNotFoundException
     */
    public function schedule(User $parent, int $studentId): array
    {
        $child = $this->child($parent, $studentId);
        $year = $this->activeAcademicYear((int) $child->school_id);
        $class = $year === null ? null : $this->currentClass($child, (int) $year->getKey());

        return [
            'child' => $child,
            'academic_year' => $year,
            'current_class' => $class,
            'today' => $this->todayDayOfWeek(),
            'lessons' => $class === null ? [] : $this->lessonsFor($child, $class),
        ];
    }

    /**
     * Kelas anak pada tahun ajaran aktif.
     *
     * Sengaja **bukan** baris StudentClass terakhir: anak yang pernah pindah
     * kelas punya beberapa baris, dan yang terakhir belum tentu yang berlaku
     * pada tahun ajaran yang sedang berjalan. Yang dipakai penempatan berstatus
     * ACTIVE pada tahun ajaran aktif — dan bila tidak ada, hasilnya NULL, bukan
     * kelas lama yang sudah selesai (butir 164).
     */
    protected function currentClass(Student $child, int $academicYearId): ?SchoolClass
    {
        $placement = StudentClass::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $child->school_id)
            ->where('student_id', $child->getKey())
            ->where('academic_year_id', $academicYearId)
            ->where('status', StudentClassStatus::Active->value)
            ->with('schoolClass')
            ->latest('id')
            ->first();

        return $placement?->schoolClass;
    }

    /**
     * Seluruh jadwal mingguan kelas itu, terurut hari lalu jam.
     *
     * Satu query, dengan mata pelajaran dan gurunya ikut dimuat: jumlah query
     * tidak tumbuh mengikuti banyaknya jam pelajaran.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function lessonsFor(Student $child, SchoolClass $class): array
    {
        return Schedule::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $child->school_id)
            ->whereHas(
                'classSubject',
                fn ($query) => $query
                    ->withoutGlobalScope(SchoolScope::class)
                    ->where('class_id', $class->getKey()),
            )
            ->with(['classSubject.subject', 'classSubject.teacher'])
            ->orderBy('day_of_week')
            ->orderBy('start_time')
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
                // Nama guru saja. Surel, nomor telepon, dan id akunnya bukan
                // urusan orang tua (butir 165).
                'teacher_name' => $schedule->classSubject?->teacher?->name,
                'room' => $schedule->room,
            ])
            ->all();
    }

    /**
     * Hari ini menurut zona waktu aplikasi, dalam penomoran ERD
     * (1 = Senin … 7 = Minggu).
     *
     * `Carbon::dayOfWeek` menomori Minggu sebagai 0, jadi konversinya
     * dilakukan sekali di sini alih-alih ditebar ke pemanggil (butir 165).
     */
    protected function todayDayOfWeek(): int
    {
        $day = (int) CarbonImmutable::now()->dayOfWeek;

        return $day === 0 ? DayOfWeek::Sunday->value : $day;
    }

    protected function clock(mixed $time): ?string
    {
        if (blank($time)) {
            return null;
        }

        return substr((string) $time, 0, 5);
    }

    protected function activeAcademicYear(int $schoolId): ?AcademicYear
    {
        return AcademicYear::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)
            ->active()
            ->first();
    }

    /**
     * `groupBy` pada koleksi Eloquent mengembalikan koleksi biasa; kalkulator
     * nilai meminta koleksi Eloquent.
     *
     * @param  \Illuminate\Support\Collection<int, Grade>|EloquentCollection<int, Grade>  $grades
     * @return EloquentCollection<int, Grade>
     */
    protected function asEloquentCollection($grades): EloquentCollection
    {
        return $grades instanceof EloquentCollection
            ? $grades
            : new EloquentCollection($grades->all());
    }

    /**
     * Nilai terbaru, satu entri ringkas **per mata pelajaran**.
     *
     * Bukan lima baris penilaian terakhir: lima ulangan harian pada mapel yang
     * sama akan memenuhi seluruh kartu dan menyembunyikan empat mapel lain.
     * Yang diurutkan karena itu mapelnya — berdasarkan penilaian terakhir yang
     * masuk pada mapel itu — lalu lima teratas diambil.
     *
     * Nilainya dihitung `FinalScoreCalculator`, kalkulator yang sama dengan
     * yang dipakai panel dan rapor. Tidak ada rumus kedua di sini: portal
     * hanya membaca (butir 151).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function latestGrades(Student $child): array
    {
        $academicYearId = $this->activeAcademicYearId((int) $child->school_id);

        if ($academicYearId === null) {
            // Tanpa tahun ajaran aktif tidak ada konteks akademik yang berlaku.
            // Ini keadaan yang wajar di cabang baru, bukan kesalahan — jadi
            // daftarnya kosong dan sisa ringkasan tetap terkirim (butir 153).
            return [];
        }

        // Satu query untuk seluruh nilai anak pada tahun ajaran aktif, lengkap
        // dengan mapelnya. Pengelompokan per mapel dilakukan di memori atas
        // data yang sudah ada — bukan satu query per mapel.
        $grades = Grade::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $child->school_id)
            ->where('student_id', $child->getKey())
            ->where('academic_year_id', $academicYearId)
            ->with('classSubject.subject')
            ->get();

        return $grades
            ->filter(fn (Grade $grade) => $grade->classSubject?->subject !== null)
            ->groupBy(fn (Grade $grade) => $grade->classSubject->subject->getKey())
            ->map(function (Collection|\Illuminate\Support\Collection $subjectGrades) {
                /** @var Grade $first */
                $first = $subjectGrades->first();
                $subject = $first->classSubject->subject;
                $result = $this->finalScore->calculate($subjectGrades instanceof Collection
                    ? $subjectGrades
                    : new Collection($subjectGrades->all()));

                return [
                    'subject_id' => $subject->getKey(),
                    'subject_code' => $subject->code,
                    'subject_name' => $subject->name,
                    'score' => $result->score,
                    'is_complete' => $result->isComplete,
                    'graded_at' => $subjectGrades
                        ->max(fn (Grade $grade) => $grade->created_at?->toIso8601String()),
                ];
            })
            ->sortByDesc('graded_at')
            ->take(self::SUMMARY_SUBJECTS)
            ->values()
            ->all();
    }

    /**
     * PORTAL-01 meminta "kehadiran bulan ini", dan Phase 1 tidak punya
     * sumbernya.
     *
     * Tidak ada tabel kehadiran di antara 21 tabel ERD, tidak ada satu pun
     * baris presensi harian di mana pun, dan "Presensi Digital" justru
     * tercantum sebagai fitur **Phase 2**. `report_cards` memang punya
     * `attend_present` dan tiga saudaranya, tetapi keduanya beda pertanyaan:
     * kolom itu rekap satu tahun ajaran, bukan satu bulan — dan sampai kini
     * tidak ada satu pun jalur yang mengisinya, sehingga membacanya berarti
     * menyajikan NULL sebagai angka.
     *
     * Yang dikembalikan karena itu keadaan "belum tersedia" secara eksplisit,
     * bukan angka nol. Nol adalah pernyataan bahwa anak tidak pernah hadir,
     * dan itu keliru dengan cara yang tidak terlihat (butir 152).
     *
     * @return array{available: bool, reason: string, present_count: null}
     */
    protected function attendance(): array
    {
        return [
            'available' => false,
            'reason' => 'attendance_source_not_available',
            'present_count' => null,
        ];
    }

    /**
     * PORTAL-01 — "tagihan belum lunas (jumlah & nominal)".
     *
     * Aturannya sama persis dengan tunggakan laporan SPP: UNPAID dan PARTIAL
     * saja. PAID sisanya nol dengan sendirinya, dan WAIVED adalah tagihan yang
     * sudah dibebaskan — menagihkannya kembali kepada orang tua di dashboard
     * akan membatalkan keringanan yang sudah diberikan sekolah (butir 154).
     *
     * @return array{count: int, outstanding_amount: string}
     */
    protected function pendingFees(Student $child): array
    {
        $row = StudentFee::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $child->school_id)
            ->where('student_id', $child->getKey())
            ->whereIn('status', [
                StudentFeeStatus::Unpaid->value,
                StudentFeeStatus::Partial->value,
            ])
            ->selectRaw('COUNT(*) as pending_count')
            ->selectRaw('SUM(amount - amount_paid) as outstanding')
            ->first();

        return [
            'count' => (int) ($row->pending_count ?? 0),
            'outstanding_amount' => number_format(
                (float) ($row->outstanding ?? 0), self::SCALE, '.', ''
            ),
        ];
    }

    /**
     * Tahun ajaran aktif cabang anak — bukan cabang sesi, dan bukan id yang
     * dititipkan pemanggil.
     */
    protected function activeAcademicYearId(int $schoolId): ?int
    {
        $id = AcademicYear::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)
            ->active()
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Anak yang boleh dilihat akun ini — satu-satunya definisi kepemilikan.
     *
     * Global scope dilepas dan `school_id` disaring eksplisit dari akun orang
     * tuanya: scope bergantung pada sesi, sedangkan pagar di sini harus
     * berasal dari akun yang argumennya jelas.
     *
     * @return Builder<Student>
     */
    protected function ownedQuery(User $parent): Builder
    {
        return Student::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('parent_user_id', $parent->getKey())
            ->where('school_id', $parent->school_id);
    }

    /**
     * Portal ini milik ORANG_TUA saja.
     *
     * API 4.11 memberi endpointnya Auth Level "Auth", tetapi "wajib token"
     * bukan "boleh dibaca semua peran" — sama seperti pada laporan keuangan
     * (butir 137). Admin sekolah yang ingin melihat data siswa punya jalurnya
     * sendiri di panel; ia tidak menjadi orang tua siapa pun.
     *
     * Akun tanpa cabang ditolak di sini juga: `school_id` NULL akan membuat
     * syarat kepemilikan membandingkan NULL dan tidak pernah cocok, tetapi
     * mengandalkan itu berarti bergantung pada kebetulan (butir 148).
     *
     * @throws AuthorizationException
     */
    protected function authorize(User $parent): void
    {
        if (! $parent->hasRole(RoleName::OrangTua->value)) {
            throw new AuthorizationException('Portal ini hanya untuk akun orang tua.');
        }

        if ($parent->school_id === null) {
            throw new AuthorizationException('Akun Anda belum terhubung ke cabang mana pun.');
        }
    }
}
