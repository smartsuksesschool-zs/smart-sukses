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
        $schoolId = static::currentSchoolId();

        if ($schoolId === null) {
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
