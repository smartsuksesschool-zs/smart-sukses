<?php

namespace App\Support;

use App\Enums\RoleName;
use App\Enums\StudentClassStatus;
use App\Models\ClassSubject;
use App\Models\Schedule;
use App\Models\SchoolClass;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Baris mana yang boleh dilihat seorang guru, di luar batas cabang.
 *
 * Pasangan dari `StudentVisibility` (butir 170), untuk peran yang berbeda dan
 * dengan aturan yang berbeda pula. Persoalannya sama: `SchoolScope` menjawab
 * "cabang mana", dan matriks PRD 1.1.2 memberi GURU/WALI ⭕ pada modul Data
 * Siswa serta Kelas & Jadwal — tetapi ⭕ menyatakan boleh membaca **modulnya**,
 * bukan boleh membaca **setiap barisnya**.
 *
 * Yang lebih spesifik menyebutkan batasnya:
 *
 *  - PRD 1.1.1 — "GURU | Guru Mata Pelajaran | Input nilai, **lihat daftar
 *    siswa kelas ajar**, **jadwal mengajar**";
 *  - SIS-04 — "daftar siswa di kelas **yang saya ampu**";
 *  - KELAS-04 — "jadwal mengajar **saya**".
 *
 * Sengaja **tidak** dipasang di policy. Policy rapor dan nilai dipakai alur
 * akademik yang sudah berjalan dan diuji sejak Sprint 4; membatasinya di sana
 * akan mengubah perilaku penilaian, dan itu di luar lingkup batch ini
 * (butir 176).
 */
class TeacherClassVisibility
{
    /**
     * Apakah pengguna ini dibatasi ke kelas ajarnya sendiri.
     *
     * Peran administratif dan platform tidak: mereka memang melihat seluruh
     * cabangnya. Yang menentukan adalah perannya guru **dan** bukan peran
     * administratif — akun yang merangkap tetap memakai kewenangan yang lebih
     * luas, seperti sebelumnya.
     */
    public static function isRestricted(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $isTeacher = $user->hasRole(RoleName::Guru->value)
            || $user->hasRole(RoleName::WaliKelas->value);

        if (! $isTeacher) {
            return false;
        }

        return ! $user->hasRole(RoleName::SuperAdmin->value)
            && ! $user->hasRole(RoleName::SchoolAdmin->value)
            && ! $user->hasRole(RoleName::KepalaSekolah->value);
    }

    /**
     * Kelas yang boleh dilihat guru ini pada tahun ajaran aktif: kelas yang
     * diajarnya, ditambah kelas perwaliannya bila ada.
     *
     * Perwalian ikut karena wali kelas memang bertanggung jawab atas rapor dan
     * absensi kelas itu (PRD 1.1.1), walaupun ia tidak mengajar satu pun mata
     * pelajaran di sana. Yang tidak dilakukan adalah sebaliknya: kelas
     * perwalian tidak pernah dilaporkan sebagai penugasan mengajar
     * (butir 172).
     *
     * @return array<int, int>
     */
    public static function visibleClassIds(User $user): array
    {
        $schoolId = $user->school_id;

        if ($schoolId === null) {
            return [];
        }

        $taught = ClassSubject::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)
            ->where('teacher_id', $user->getKey())
            ->whereHas('academicYear', fn (Builder $year) => $year
                ->withoutGlobalScope(SchoolScope::class)
                ->where('is_active', true))
            ->distinct()
            ->pluck('class_id');

        $homeroom = SchoolClass::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)
            ->where('homeroom_teacher_id', $user->getKey())
            ->whereHas('academicYear', fn (Builder $year) => $year
                ->withoutGlobalScope(SchoolScope::class)
                ->where('is_active', true))
            ->pluck('id');

        return $taught->merge($homeroom)
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Menyaring query siswa ke siswa kelas ajarnya.
     *
     * @param  Builder<Student>  $query
     * @return Builder<Student>
     */
    public static function constrainStudents(Builder $query, ?User $user): Builder
    {
        if (! self::isRestricted($user)) {
            return $query;
        }

        $classIds = self::visibleClassIds($user);

        if ($classIds === []) {
            // Guru tanpa penugasan aktif tidak melihat siapa pun, bukan
            // melihat semuanya.
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('studentClasses', fn (Builder $placement) => $placement
            ->withoutGlobalScope(SchoolScope::class)
            ->whereIn('class_id', $classIds)
            ->where('status', StudentClassStatus::Active->value));
    }

    /**
     * Menyaring query jadwal ke jadwal mengajarnya sendiri.
     *
     * Penyaringnya guru pada `class_subjects`, bukan kelasnya: dua guru dapat
     * mengajar di kelas yang sama, dan jadwal guru lain bukan urusannya.
     *
     * @param  Builder<Schedule>  $query
     * @return Builder<Schedule>
     */
    public static function constrainSchedules(Builder $query, ?User $user): Builder
    {
        if (! self::isRestricted($user)) {
            return $query;
        }

        return $query->whereHas('classSubject', fn (Builder $assignment) => $assignment
            ->withoutGlobalScope(SchoolScope::class)
            ->where('teacher_id', $user->getKey()));
    }
}
