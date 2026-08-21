<?php

namespace App\Services\Portal;

use App\Enums\RoleName;
use App\Enums\StudentFeeStatus;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use App\Services\Grading\FinalScoreCalculator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
