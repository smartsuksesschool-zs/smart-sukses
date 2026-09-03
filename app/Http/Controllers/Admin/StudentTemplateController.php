<?php

namespace App\Http\Controllers\Admin;

use App\Exports\StudentTemplateExport;
use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Unduhan berkas contoh import siswa.
 *
 * Rutenya didaftarkan lewat `authenticatedRoutes()` milik panel, sehingga ia
 * melewati **persis** tumpukan middleware yang sama dengan seluruh halaman
 * panel — sesi, CSRF, Authenticate, SetUserLocale, dan EnsurePasswordIsChanged.
 * Tidak ada tumpukan autentikasi kedua yang dibuat untuk berkas ini.
 *
 * Kewenangannya menumpang `StudentPolicy::import` apa adanya: yang boleh
 * mengunduh berkas contoh adalah yang memang sudah boleh mengimpor. Tidak ada
 * matriks peran baru.
 *
 * Berkasnya dibangkitkan setiap kali diminta dan **tidak menyentuh basis data**
 * sama sekali: isinya hanya judul kolom, penjelasan, dan satu baris contoh
 * karangan. Tidak ada satu pun data siswa di dalamnya (butir 501).
 */
class StudentTemplateController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        Gate::authorize('import', Student::class);

        return Excel::download(new StudentTemplateExport, StudentTemplateExport::filename());
    }
}
