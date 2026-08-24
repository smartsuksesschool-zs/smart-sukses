<?php

namespace App\Livewire\Student\Concerns;

use App\Services\Portal\StudentPortalService;
use Illuminate\Support\Facades\Auth;

/**
 * Akun berperan SISWA yang belum tertaut ke data siswa.
 *
 * `students.user_id` boleh NULL menurut ERD, jadi keadaan ini wajar — akun
 * sudah dibuat, penautannya belum. Halaman portal karena itu menampilkan
 * keterangan alih-alih halaman kesalahan, dan tidak pernah mengambil siswa lain
 * sebagai gantinya (butir 182).
 */
trait ResolvesStudent
{
    public function hasStudent(): bool
    {
        return once(fn () => app(StudentPortalService::class)->hasLinkedStudent(Auth::user()));
    }
}
