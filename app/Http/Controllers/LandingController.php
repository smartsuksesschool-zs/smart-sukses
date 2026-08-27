<?php

namespace App\Http\Controllers;

use App\Models\School;
use Illuminate\Contracts\View\View;

/**
 * Halaman muka publik Smart Sukses School.
 *
 * Tambahan langsung atas permintaan pemilik, di luar blueprint Phase 1
 * (docs/owner-scope-changes.md bagian A). Menggantikan halaman bawaan Laravel
 * yang sampai batch ini masih terpasang di `/`.
 *
 * Controller biasa, bukan komponen Livewire: halamannya statis, dan satu-satunya
 * data yang dibacanya adalah daftar cabang. Menjadikannya Livewire berarti
 * memuat runtime-nya pada halaman yang tidak punya satu pun interaksi
 * (butir 351).
 *
 * Publik sepenuhnya — tanpa middleware, tanpa sesi, tanpa konteks tenant.
 */
class LandingController extends Controller
{
    public function __invoke(): View
    {
        return view('landing', [
            // Semantik yang sama persis dengan halaman PPDB publik
            // (`App\Livewire\Ppdb\SchoolList`): cabang aktif saja, diurutkan
            // menurut nama. `School` tidak memakai SchoolScope — ia justru
            // tabel tenantnya — sehingga query ini berlaku sama bagi tamu
            // maupun bagi siapa pun yang kebetulan sedang login (butir 352).
            'schools' => School::query()
                ->active()
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'address']),
        ]);
    }
}
