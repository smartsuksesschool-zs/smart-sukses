<?php

namespace App\Http\Controllers\Admin;

use App\Exports\AccountTemplateExport;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Unduhan berkas contoh import akun (PORTAL-04 AC-1).
 *
 * Rutenya didaftarkan lewat `authenticatedRoutes()` milik panel, sehingga ia
 * melewati persis tumpukan middleware yang sama dengan seluruh halaman panel —
 * sesi, CSRF, Authenticate, SetUserLocale, dan EnsurePasswordIsChanged. Tidak ada
 * tumpukan autentikasi kedua yang dibuat untuk berkas ini (butir 412).
 *
 * Berkasnya dibangkitkan setiap kali diminta dan **tidak menyentuh basis data**:
 * isinya hanya judul kolom, penjelasan, dan contoh karangan. Tidak ada satu pun
 * surel, nama, atau sandi sungguhan di dalamnya.
 */
class AccountTemplateController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        Gate::authorize('import', User::class);

        return Excel::download(new AccountTemplateExport, AccountTemplateExport::filename());
    }
}
