<?php

namespace App\Services\Notification;

use App\Enums\NotificationTargetType;
use App\Enums\RoleName;
use App\Enums\StudentClassStatus;
use App\Models\Notification;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * NOTIF-01 — siapa yang menerima sebuah notifikasi.
 *
 * Satu-satunya tempat pertanyaan itu dijawab. Jawabannya dipakai empat hal
 * berbeda — umpan penerima, hitungan belum dibaca, penandaan terbaca, dan nanti
 * pembuatan tautan wa.me (NOTIF-02) — dan menuliskannya empat kali berarti
 * empat definisi "penerima" yang perlahan berbeda (butir 196).
 *
 * Kelas ini menyediakan dua bentuk dari aturan yang sama:
 *
 *  - `recipientsOf()` — daftar penerima satu notifikasi, untuk sisi admin;
 *  - `visibleTo()` — predikat query untuk umpan seorang pengguna, supaya daftar
 *    notifikasi tidak perlu memanggil resolver sekali per baris.
 *
 * Keduanya wajib menghasilkan keputusan yang sama, dan ada test yang
 * membandingkan keduanya.
 */
class NotificationRecipientResolver
{
    /**
     * Penerima satu notifikasi.
     *
     * @return Collection<int, User>
     */
    public function recipientsOf(Notification $notification): Collection
    {
        $schoolId = (int) $notification->school_id;

        return match ($notification->target_type) {
            NotificationTargetType::All => $this->activeUsersOf($schoolId)->get(),
            NotificationTargetType::SchoolClass => $this->parentsOfClass($schoolId, (int) $notification->target_id),
            NotificationTargetType::Individual => $this->activeUsersOf($schoolId)
                ->whereKey($notification->target_id)
                ->get(),
        };
    }

    /**
     * Menyaring query notifikasi ke yang benar-benar ditujukan kepada pengguna
     * ini.
     *
     * Ditulis sebagai predikat, bukan sebagai perulangan atas hasil: umpan
     * penerima memuat banyak notifikasi sekaligus, dan memanggil resolver per
     * baris akan menghasilkan satu query per notifikasi (butir 197).
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function visibleTo(Builder $query, User $user): Builder
    {
        // Akun tanpa cabang bukan penerima siapa pun: `notifications.school_id`
        // NOT NULL, jadi tidak ada baris yang dapat cocok.
        if ($user->school_id === null) {
            return $query->whereRaw('1 = 0');
        }

        // Akun nonaktif tidak menerima apa pun, sejalan dengan target ALL yang
        // hanya menjangkau pengguna aktif.
        if (! $user->is_active) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('notifications.school_id', $user->school_id)
            ->where(function (Builder $targets) use ($user): void {
                $targets
                    ->where('target_type', NotificationTargetType::All->value)
                    ->orWhere(function (Builder $individual) use ($user): void {
                        $individual
                            ->where('target_type', NotificationTargetType::Individual->value)
                            ->where('target_id', $user->getKey());
                    });

                if ($this->isParent($user)) {
                    $targets->orWhere(function (Builder $class) use ($user): void {
                        $class
                            ->where('target_type', NotificationTargetType::SchoolClass->value)
                            // Orang tua menerima notifikasi kelas bila ia punya
                            // anak yang **sedang** terdaftar di kelas itu.
                            ->whereIn('target_id', $this->classIdsOfChildren($user));
                    });
                }
            });
    }

    /**
     * NOTIF-01 poin 2 — "Target 'semua' mengirim ke semua user **aktif** di
     * cabang".
     *
     * Super Admin tidak ikut: `school_id`-nya NULL, jadi ia bukan pengguna
     * cabang mana pun. Ia tetap dapat menjadi penerima bila dipilih secara
     * eksplisit sebagai target INDIVIDUAL — tetapi target itu pun akan gagal
     * validasi karena cabangnya tidak cocok, dan itu memang konsisten: dashboard
     * platform bukan tempat pengumuman cabang (butir 198).
     *
     * @return Builder<User>
     */
    protected function activeUsersOf(int $schoolId): Builder
    {
        return User::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)
            ->active();
    }

    /**
     * NOTIF-01 poin 3 — "Target 'per kelas' **hanya untuk orang tua siswa
     * kelas tersebut**".
     *
     * Bukan siswanya, bukan gurunya, bukan wali kelasnya — kalimatnya menyebut
     * orang tua, dan hanya orang tua. Satu orang tua dengan dua anak di kelas
     * yang sama muncul **sekali** (butir 199).
     *
     * @return Collection<int, User>
     */
    protected function parentsOfClass(int $schoolId, int $classId): Collection
    {
        return $this->activeUsersOf($schoolId)
            ->whereHas('roles', fn ($roles) => $roles->where('name', RoleName::OrangTua->value))
            ->whereHas('parentedStudents', fn (Builder $students) => $students
                ->withoutGlobalScope(SchoolScope::class)
                ->where('school_id', $schoolId)
                ->whereHas('studentClasses', fn (Builder $placement) => $placement
                    ->withoutGlobalScope(SchoolScope::class)
                    ->where('class_id', $classId)
                    ->where('status', StudentClassStatus::Active->value)))
            // `whereHas` sudah menjamin satu baris per pengguna, jadi dua anak
            // di kelas yang sama tidak menggandakan orang tuanya.
            ->orderBy('name')
            ->get();
    }

    /**
     * Kelas yang sedang ditempati anak-anak pengguna ini.
     *
     * Dipakai sebagai subquery pada `visibleTo()` supaya penyaringannya terjadi
     * di database, bukan dengan menarik daftar kelas lebih dulu.
     *
     * Yang dikembalikan adalah query builder dasar, bukan Eloquent builder:
     * `whereIn` menerima subquery mentah, dan melewatkan Eloquent builder ke
     * sana hanya akan membawa serta global scope yang justru sudah dilepas di
     * sini.
     */
    protected function classIdsOfChildren(User $parent): QueryBuilder
    {
        return StudentClass::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->select('student_classes.class_id')
            ->where('student_classes.status', StudentClassStatus::Active->value)
            ->whereIn(
                'student_classes.student_id',
                Student::query()
                    ->withoutGlobalScope(SchoolScope::class)
                    ->select('students.id')
                    ->where('students.parent_user_id', $parent->getKey())
                    ->where('students.school_id', $parent->school_id)
                    ->getQuery(),
            )
            ->getQuery();
    }

    protected function isParent(User $user): bool
    {
        return $user->hasRole(RoleName::OrangTua->value);
    }
}
