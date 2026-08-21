<?php

namespace App\Support;

use App\Enums\RoleName;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Siswa mana yang boleh dilihat seorang pengguna, di luar batas cabang.
 *
 * `SchoolScope` menjawab pertanyaan "cabang mana", dan itu memadai untuk
 * peran yang memang melihat seluruh siswa cabangnya — admin sekolah, kepala
 * sekolah, bendahara, guru. Untuk dua peran ia tidak memadai:
 *
 *  - ORANG_TUA hanya berhak atas anaknya sendiri;
 *  - SISWA hanya berhak atas dirinya sendiri.
 *
 * Keduanya memegang izin baca modul (`fee.view`, `report_card.view`) menurut
 * matriks PRD 1.1.2, sehingga pemeriksaan izin + cabang saja meloloskan mereka
 * ke seluruh record cabang itu. Batasan barisnya karena itu ditulis sekali di
 * sini dan dipakai bersama policy maupun query — bukan disalin ke setiap
 * endpoint yang kebetulan mengembalikan data siswa (butir 170).
 */
class StudentVisibility
{
    /**
     * Apakah peran pengguna ini dibatasi ke sebagian siswa saja.
     */
    public static function isRestricted(User $user): bool
    {
        return $user->hasRole(RoleName::OrangTua->value)
            || $user->hasRole(RoleName::Siswa->value);
    }

    /**
     * Apakah pengguna ini boleh melihat data satu siswa tertentu.
     */
    public static function allows(User $user, ?Student $student): bool
    {
        if (! self::isRestricted($user)) {
            return true;
        }

        if ($student === null) {
            return false;
        }

        if ($user->hasRole(RoleName::OrangTua->value)
            && (int) $student->parent_user_id === (int) $user->getKey()) {
            return true;
        }

        return $user->hasRole(RoleName::Siswa->value)
            && $student->user_id !== null
            && (int) $student->user_id === (int) $user->getKey();
    }

    /**
     * Menyaring query yang punya relasi `student` ke siswa yang boleh dilihat.
     *
     * Peran yang tidak dibatasi melewatinya tanpa perubahan sama sekali,
     * sehingga perilaku admin, bendahara, kepala sekolah, dan guru tetap persis
     * seperti sebelumnya.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function constrain(Builder $query, User $user, string $relation = 'student'): Builder
    {
        if (! self::isRestricted($user)) {
            return $query;
        }

        return $query->whereHas($relation, function (Builder $students) use ($user): void {
            $students->where(function (Builder $owned) use ($user): void {
                if ($user->hasRole(RoleName::OrangTua->value)) {
                    $owned->orWhere('parent_user_id', $user->getKey());
                }

                if ($user->hasRole(RoleName::Siswa->value)) {
                    $owned->orWhere('user_id', $user->getKey());
                }
            });
        });
    }
}
