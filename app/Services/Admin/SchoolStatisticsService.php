<?php

namespace App\Services\Admin;

use App\Enums\RoleName;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\FinanceSummaryService;
use App\Services\Finance\SppReportService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * API 4.3 — GET /admin/schools/{id}/stats, Auth Level **Super**:
 * "Statistik: jumlah siswa, guru, tagihan terkumpul bulan ini, tunggakan".
 *
 * Keempat angkanya tidak didefinisikan di mana pun dalam dokumen — tidak di
 * ERD, tidak di PRD, tidak di arsitektur. Yang dipakai karena itu keputusan
 * implementasi Phase 1 (butir 140–143), dan ketiganya sengaja mengambil arti
 * yang **sudah** dipakai bagian lain sistem alih-alih membuat arti baru:
 *
 *  - "jumlah siswa" mengikuti `Student::scopeActive()`, definisi yang sama
 *    dengan yang dipakai penerbitan tagihan massal,
 *  - "tagihan terkumpul bulan ini" mengikuti `FinanceSummaryService::sppReceived()`,
 *    definisi "penerimaan" yang sama dengan dashboard cabang (KAS-02),
 *  - "tunggakan" mengikuti `SppReportService`, aturan yang sama dengan laporan
 *    SPP (butir 135).
 *
 * Statistik ini selalu **satu cabang** yang sudah diketahui dari path. Global
 * scope dilepas dan `school_id` disaring eksplisit: melepas scope tanpa pagar
 * pengganti akan membuat setiap hitungan diam-diam menjadi lintas cabang.
 */
class SchoolStatisticsService
{
    public function __construct(
        protected FinanceSummaryService $finance,
        protected SppReportService $sppReport,
    ) {}

    /**
     * @return array{
     *     school: School,
     *     period: string,
     *     student_count: int,
     *     teacher_count: int,
     *     collected_this_month: string,
     *     arrears: string
     * }
     *
     * @throws AuthorizationException|ModelNotFoundException
     */
    public function forSchool(int $schoolId, User $actor, ?CarbonImmutable $now = null): array
    {
        $this->authorize($actor);

        $school = $this->school($schoolId);
        $month = ($now ?? CarbonImmutable::now())->startOfMonth();

        return [
            'school' => $school,
            'period' => $month->format('Y-m'),
            'student_count' => $this->studentCount($schoolId),
            'teacher_count' => $this->teacherCount($schoolId),
            // Rentangnya bulan kalender berjalan, dan `sppReceived()` sudah
            // memakai `whereDate()` di kedua ujung — hari pertama dan hari
            // terakhir bulan sama-sama ikut terhitung (butir 139).
            'collected_this_month' => $this->finance->sppReceived(
                $schoolId,
                $month,
                $month->endOfMonth(),
            ),
            'arrears' => $this->sppReport->totalArrearsForSchool($schoolId),
        ];
    }

    /**
     * Cabang yang diminta, termasuk yang sudah dinonaktifkan.
     *
     * Cabang nonaktif tetap punya riwayat, dan Super Admin justru sering perlu
     * membacanya setelah cabang ditutup. Menjadikannya 404 hanya karena
     * `is_active` bernilai 0 akan menghilangkan riwayat itu tanpa satu pun
     * dokumen memintanya (butir 144).
     *
     * @throws ModelNotFoundException
     */
    protected function school(int $schoolId): School
    {
        return School::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->findOrFail($schoolId);
    }

    /**
     * "Jumlah siswa" — siswa berstatus ACTIVE.
     *
     * GRADUATED, DROPPED_OUT, dan TRANSFERRED bukan siswa yang sedang
     * bersekolah, dan menghitungnya akan membuat angka cabang lama terus
     * membengkak tanpa pernah turun. Definisinya sama persis dengan yang
     * dipakai `/admin/dashboard`, sehingga satu cabang tidak pernah punya dua
     * "jumlah siswa" yang berbeda (butir 140).
     */
    protected function studentCount(int $schoolId): int
    {
        return Student::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)
            ->active()
            ->count();
    }

    /**
     * "Jumlah guru" — akun aktif yang perannya GURU atau WALI_KELAS.
     *
     * Wali kelas tetap seorang guru dengan tanggung jawab tambahan, jadi ia
     * dihitung; School Admin, Kepala Sekolah, dan Bendahara tidak, walaupun
     * sebagian dari mereka mungkin juga mengajar — yang dapat dibaca sistem
     * hanyalah perannya. Akun nonaktif tidak dihitung: statistik ini
     * menggambarkan keadaan operasional sekarang (butir 141).
     *
     * Satu query dengan `whereHas`, bukan satu pemeriksaan peran per pengguna,
     * dan `distinct` menjaga pengguna dengan dua peran tetap terhitung sekali.
     */
    protected function teacherCount(int $schoolId): int
    {
        return User::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)
            ->active()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', [
                RoleName::Guru->value,
                RoleName::WaliKelas->value,
            ]))
            ->distinct()
            ->count('users.id');
    }

    /**
     * API 4.3 memberi endpoint ini Auth Level **Super**, dan tidak ada user
     * story yang menimpanya — berbeda dari endpoint keuangan yang labelnya
     * tertimpa KAS-01/SPP (butir 117).
     *
     * Diperiksa di service, bukan hanya di middleware: statistik ini juga
     * dipanggil dari panel, dan pagar yang hanya ada di rute API tidak
     * melindungi jalur itu.
     *
     * @throws AuthorizationException
     */
    protected function authorize(User $actor): void
    {
        if (! $actor->isSuperAdmin()) {
            throw new AuthorizationException('Statistik cabang hanya untuk Super Admin.');
        }
    }
}
