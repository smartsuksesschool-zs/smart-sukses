<?php

namespace App\Services\Admin;

use App\Enums\StudentClassStatus;
use App\Enums\StudentStatus;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Ringkasan jumlah siswa untuk halaman Data Siswa.
 *
 * Satu tempat untuk seluruh rumusnya, dipakai widget dan test. Rumus di dalam
 * widget berarti rumus yang hanya dapat diuji lewat HTML (butir 578).
 *
 * Tiga aturan yang menentukan benar-tidaknya angka ini:
 *
 *  1. **Tingkat dibaca dari `classes.grade_level`, bukan dari nama kelas.**
 *     Nama rombel adalah teks bebas milik sekolah; "XII Terbuka - 1" hari ini
 *     dapat menjadi "12 Terbuka 1" besok, dan penghitung yang membaca teks akan
 *     ikut berubah diam-diam tanpa satu pun yang berubah maksudnya.
 *
 *  2. **Satu siswa dihitung sekali.** `student_classes` menyimpan riwayat:
 *     baris lama tetap tinggal dengan status MOVED ketika siswa pindah kelas
 *     (KELAS-02). Menghitung barisnya, bukan siswanya, akan melipatgandakan
 *     setiap anak yang pernah pindah.
 *
 *  3. **Hanya tahun ajaran aktif.** Penempatan tahun lalu bukan keadaan hari
 *     ini, dan ikut menghitungnya membuat angka membengkak setiap tahun ajaran
 *     baru dibuka.
 */
class StudentRosterSummary
{
    /**
     * @return array{
     *     scope: string,
     *     total: int,
     *     placed: int,
     *     unplaced: int,
     *     by_grade: array<int, int>,
     *     by_class: array<string, int>,
     * }
     */
    public function for(?User $user): array
    {
        /*
         * Cabang mana yang dihitung mengikuti apa yang dilihat pengguna pada
         * tabel di bawahnya, bukan aturan tersendiri. Super Admin memang
         * melihat seluruh cabang di halaman ini (SchoolScope melewatinya),
         * sehingga ringkasannya pun lintas cabang — dan diberi label demikian,
         * supaya tidak terbaca sebagai angka satu sekolah (butir 579).
         */
        $schoolId = ($user === null || $user->isSuperAdmin()) ? null : $user->school_id;

        return [
            'scope' => $schoolId === null ? 'all' : 'school',
            'total' => $this->activeStudents($schoolId)->count(),
            'placed' => $this->placedStudentIds($schoolId)->count(),
            'unplaced' => $this->unplacedCount($schoolId),
            'by_grade' => $this->byGrade($schoolId),
            'by_class' => $this->byClass($schoolId),
        ];
    }

    /**
     * Siswa berstatus aktif pada cabang yang dihitung.
     *
     * Yang lulus, pindah, dan keluar sengaja tidak ikut: halaman ini
     * menyaringnya ke ACTIVE sebagai bawaan, dan ringkasan yang menghitung
     * lebih banyak daripada tabel di bawahnya adalah ringkasan yang membingungkan.
     *
     * @return Builder<Student>
     */
    protected function activeStudents(?int $schoolId)
    {
        return Student::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('status', StudentStatus::Active->value)
            ->when($schoolId !== null, fn ($q) => $q->where('school_id', $schoolId));
    }

    /**
     * Id siswa yang punya penempatan aktif pada tahun ajaran yang sedang aktif.
     *
     * `distinct` pada kolom siswa, bukan `count()` atas barisnya — lihat aturan
     * kedua pada docblock kelas.
     *
     * @return Collection<int, int>
     */
    protected function placedStudentIds(?int $schoolId)
    {
        return StudentClass::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->join('classes', 'student_classes.class_id', '=', 'classes.id')
            ->join('academic_years', 'classes.academic_year_id', '=', 'academic_years.id')
            ->join('students', 'student_classes.student_id', '=', 'students.id')
            ->where('student_classes.status', StudentClassStatus::Active->value)
            ->where('academic_years.is_active', true)
            ->where('students.status', StudentStatus::Active->value)
            ->when($schoolId !== null, fn ($q) => $q->where('student_classes.school_id', $schoolId))
            ->distinct()
            ->pluck('student_classes.student_id');
    }

    protected function unplacedCount(?int $schoolId): int
    {
        $placed = $this->placedStudentIds($schoolId);

        return $this->activeStudents($schoolId)
            ->when($placed->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $placed->all()))
            ->count();
    }

    /**
     * @return array<int, int> tingkat -> jumlah siswa
     */
    protected function byGrade(?int $schoolId): array
    {
        return StudentClass::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->join('classes', 'student_classes.class_id', '=', 'classes.id')
            ->join('academic_years', 'classes.academic_year_id', '=', 'academic_years.id')
            ->join('students', 'student_classes.student_id', '=', 'students.id')
            ->where('student_classes.status', StudentClassStatus::Active->value)
            ->where('academic_years.is_active', true)
            ->where('students.status', StudentStatus::Active->value)
            ->when($schoolId !== null, fn ($q) => $q->where('student_classes.school_id', $schoolId))
            ->groupBy('classes.grade_level')
            ->selectRaw('classes.grade_level as grade, count(distinct student_classes.student_id) as n')
            ->pluck('n', 'grade')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * Rincian per rombel — hanya untuk ditampilkan, tidak pernah menjadi dasar
     * penghitungan tingkat.
     *
     * Pengelompokannya selalu per **rombel**, bukan per nama rombel. Nama
     * rombel hanya unik di dalam satu cabang: "X Terbuka - 2" dapat ada di dua
     * cabang sekaligus. Mengelompokkan dengan namanya saja akan meleburkan
     * keduanya menjadi satu baris, sehingga Super Admin membaca "X Terbuka - 2:
     * 24" untuk dua rombel berisi dua belas — angka yang tidak dimiliki cabang
     * mana pun, dan persis pencampuran tenant yang harus dihindari (butir 579).
     *
     * Pada cakupan lintas cabang, labelnya karena itu diberi kode cabang.
     *
     * @return array<string, int>
     */
    protected function byClass(?int $schoolId): array
    {
        $rows = StudentClass::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->join('classes', 'student_classes.class_id', '=', 'classes.id')
            ->join('academic_years', 'classes.academic_year_id', '=', 'academic_years.id')
            ->join('students', 'student_classes.student_id', '=', 'students.id')
            ->join('schools', 'classes.school_id', '=', 'schools.id')
            ->where('student_classes.status', StudentClassStatus::Active->value)
            ->where('academic_years.is_active', true)
            ->where('students.status', StudentStatus::Active->value)
            ->when($schoolId !== null, fn ($q) => $q->where('student_classes.school_id', $schoolId))
            ->groupBy('classes.id', 'classes.name', 'schools.code')
            ->orderBy('schools.code')
            ->orderBy('classes.name')
            ->selectRaw('classes.name as name, schools.code as school_code, count(distinct student_classes.student_id) as n')
            ->get();

        $summary = [];

        foreach ($rows as $row) {
            $label = $schoolId === null
                ? $row->school_code.' · '.$row->name
                : $row->name;

            // Satu cabang tidak boleh punya dua rombel bernama sama pada tahun
            // yang sama, tetapi menjumlahkan lebih aman daripada menimpa diam-diam.
            $summary[$label] = ($summary[$label] ?? 0) + (int) $row->n;
        }

        return $summary;
    }
}
