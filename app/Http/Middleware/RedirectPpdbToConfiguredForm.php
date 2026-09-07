<?php

namespace App\Http\Middleware;

use App\Support\PublicSite;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Satu tujuan pendaftaran — keputusan pemilik (M7.2).
 *
 * Pendaftaran publik kini bermuara ke satu alamat: Google Form yang disetel
 * pemilik pada `ppdb_url`. Alur PPDB internal aplikasi ini **tidak dihapus**;
 * ia berdiri di belakang sebagai cadangan, dan middleware inilah yang
 * memutuskan mana yang berlaku (butir 554).
 *
 * Pemisahannya di middleware, bukan di dalam komponen Livewire-nya, karena yang
 * berubah bukan isi halaman melainkan apakah halamannya ditampilkan sama
 * sekali. Komponen `SchoolList` dan `RegistrationForm` tidak disentuh satu baris
 * pun, dan tetap teruji apa adanya.
 *
 * **302, bukan 301.** Keputusan pemiliknya berbunyi "untuk saat ini": alamat
 * lama akan dipakai lagi bila formulirnya ditarik. Pengalihan permanen tersimpan
 * di peramban setiap pengunjung dan tidak dapat ditarik kembali dari sisi
 * server — biaya yang tidak sebanding dengan penghematan satu permintaan.
 *
 * Ketika `ppdb_url` kosong **atau tidak lolos pemeriksaan**, permintaannya
 * diteruskan apa adanya: pengunjung melihat halaman PPDB aplikasi ini, persis
 * seperti sebelum batch ini. Tidak ada alamat karangan, tidak ada galat.
 */
class RedirectPpdbToConfiguredForm
{
    public function handle(Request $request, Closure $next): Response
    {
        $url = app(PublicSite::class)->externalPpdbUrl();

        if ($url === null) {
            return $next($request);
        }

        return redirect()->away($url, 302);
    }
}
