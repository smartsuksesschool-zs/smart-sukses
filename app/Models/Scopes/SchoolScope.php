<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Arsitektur 3.2 — Isolasi Data (Shared Database, Shared Schema).
 *
 * Menambahkan `WHERE school_id = [current_user.school_id]` pada setiap query
 * model bisnis. Super Admin (school_id = NULL) melewati scope ini dan dapat
 * mengakses data seluruh cabang.
 */
class SchoolScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // Tanpa sesi tidak ada tenant yang dapat disimpulkan: seeder, perintah
        // artisan, dan worker antrean membawa cabangnya sendiri sebagai
        // argumen (lihat StudentFeeGenerator).
        if (! Auth::hasUser()) {
            return;
        }

        $user = Auth::user();

        // Peran Platform Level memang lintas cabang (Arsitektur 3.2.2).
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return;
        }

        $schoolId = $user->school_id;

        if ($schoolId === null) {
            // Akun School Level tanpa cabang. Sebelumnya keadaan ini jatuh ke
            // cabang yang sama dengan Super Admin — NULL — sehingga scope-nya
            // tidak memasang batasan apa pun dan akun itu justru membaca
            // seluruh cabang. Yang benar adalah kebalikannya: tanpa cabang,
            // tidak ada satu pun baris yang menjadi miliknya (butir 127).
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('school_id'), $schoolId);
    }

    /**
     * school_id tenant aktif, atau NULL bila query tidak boleh di-scope.
     *
     * Menggunakan `Auth::hasUser()` (bukan `Auth::user()`) supaya scope tidak
     * ikut aktif saat guard sedang me-resolve user dari sesi — pemanggilan
     * `Auth::user()` di dalam scope model User akan menyebabkan rekursi tak
     * terbatas. Konsekuensinya lookup login by email berjalan lintas cabang,
     * yang memang dibutuhkan alur identifikasi tenant (Arsitektur 3.2.2).
     */
    public static function currentSchoolId(): ?int
    {
        if (! Auth::hasUser()) {
            return null;
        }

        $user = Auth::user();

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return null;
        }

        return $user->school_id;
    }
}
