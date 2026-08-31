<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PpdbRegistration;
use App\Support\PpdbDocument;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unduhan berkas pendukung PPDB, satu-satunya jalan menuju berkas itu.
 *
 * Rutenya didaftarkan lewat `authenticatedRoutes()` milik panel
 * (App\Providers\Filament\AdminPanelProvider), sehingga ia melewati **persis**
 * tumpukan middleware yang sama dengan seluruh halaman panel — sesi, CSRF,
 * Authenticate, SetUserLocale, dan EnsurePasswordIsChanged. Tidak ada tumpukan
 * autentikasi kedua yang dibuat untuk berkas ini.
 *
 * Kewenangannya menumpang `PpdbRegistrationPolicy::view` apa adanya: yang boleh
 * mengunduh berkas adalah yang memang sudah boleh membuka pendaftarannya —
 * Admin Sekolah dan Kepala Sekolah di cabangnya sendiri, serta Super Admin
 * lewat `Gate::before` (Arsitektur 3.2.2). Tidak ada matriks peran baru.
 *
 * Isolasi cabang punya dua pagar. Route model binding menjalankan global scope
 * SchoolScope, sehingga pendaftaran cabang lain sudah **tidak ditemukan** —
 * 404, bukan 403, dan karena itu keberadaannya pun tidak terbocorkan. Policy
 * memeriksanya lagi lewat `sharesTenant()`.
 */
class PpdbDocumentController extends Controller
{
    public function __invoke(PpdbRegistration $registration, int $documentKey): StreamedResponse
    {
        Gate::authorize('view', $registration);

        // Kuncinya indeks di dalam `documents` milik pendaftaran ini; jalurnya
        // dibaca dari basis data, tidak pernah dirakit dari request.
        $path = PpdbDocument::pathFor($registration, $documentKey);

        abort_if($path === null, 404);

        // Berkas yang tercatat tetapi hilang dari penyimpanan adalah 404 —
        // bukan galat 500, dan bukan pesan yang menyebut letaknya.
        $disk = PpdbDocument::diskFor($path);

        abort_if($disk === null, 404);

        return Storage::disk($disk)->download(
            $path,
            PpdbDocument::filenameFor($registration, $documentKey, $path),
        );
    }
}
