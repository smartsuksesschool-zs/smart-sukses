<?php

namespace App\Http\Controllers;

use App\Enums\SiteBlockType;
use App\Support\PublicSite;
use Illuminate\Contracts\View\View;

/**
 * Halaman muka publik Smart Sukses School.
 *
 * **V2 mengubah maksud halamannya.** Sampai batch ini `/` menjelaskan
 * perangkat lunaknya — modul, fitur, portal — dan itu masuk akal selama
 * pembacanya calon pengguna sistem. Umpan balik pemilik setelah simulasi
 * menyatakan sebaliknya: pembaca `/` adalah orang tua calon siswa, dan yang
 * harus ia pahami dalam sepuluh detik pertama adalah sekolahnya, bukan sistem
 * informasinya. Sistem informasi tetap ada, turun menjadi satu bagian
 * "Akses Sistem" di dekat kaki halaman (butir 475).
 *
 * Controller biasa, bukan komponen Livewire: halamannya tetap statis, dan
 * seluruh isinya dibaca sekali saat render (butir 351).
 *
 * Publik sepenuhnya — tanpa middleware, tanpa sesi, tanpa konteks tenant.
 * Isinya global; tidak ada satu query pun yang menyaring menurut cabang
 * (butir 464).
 */
class LandingController extends Controller
{
    public function __invoke(PublicSite $site): View
    {
        return view('landing', [
            'site' => $site,
            'units' => $site->blocks(SiteBlockType::Unit),
            'programs' => $site->blocks(SiteBlockType::Program),
            'gallery' => $site->blocks(SiteBlockType::Gallery),
            'articles' => $site->blocks(SiteBlockType::Article),
        ]);
    }
}
