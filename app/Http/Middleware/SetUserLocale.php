<?php

namespace App\Http\Middleware;

use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * AUTH-05 — preferensi bahasa tersimpan di profil pengguna (`users.locale`).
 * NFR 1.4 — Bahasa Indonesia sebagai bawaan, English tersedia.
 *
 * Sejak Batch S9.3 middleware ini juga melayani **tamu**: halaman muka dan PPDB
 * dapat dibaca siapa saja, dan calon siswa yang tidak berbahasa Indonesia tidak
 * punya akun untuk menyimpan preferensinya. Pilihannya disimpan di sesi
 * (butir 379).
 *
 * Urutannya diputuskan `Locale::forRequest()`: preferensi akun menang atas
 * sesi.
 */
class SetUserLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(Locale::forRequest($request));

        return $next($request);
    }
}
