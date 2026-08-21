<?php

use App\Http\Middleware\EnsureParentPortalAccess;
use App\Livewire\Portal\ParentDashboard;
use App\Livewire\Portal\PortalLogin;
use App\Livewire\Ppdb\RegistrationForm;
use App\Livewire\Ppdb\SchoolList;
use App\Livewire\Ppdb\StatusCheck;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * API 4.7 PPDB Online — Auth Level: Public.
 * Halaman-halaman berikut sengaja tidak memakai middleware auth
 * (PPDB-01 poin 1: "dapat diakses publik via URL: /ppdb/[kode_sekolah]").
 */
Route::prefix('ppdb')->name('ppdb.')->group(function () {
    Route::get('/', SchoolList::class)->name('schools');
    Route::get('/cek-status', StatusCheck::class)->name('check-status');
    Route::get('/{schoolCode}', RegistrationForm::class)->name('register');
});

/*
 * PORTAL-01 / API 4.11 — Parent Portal.
 *
 * Rute web biasa dengan guard `web` yang sama dengan panel, bukan panel
 * Filament kedua: ORANG_TUA memang sengaja ditolak seluruh panel
 * (User::canAccessPanel), dan melonggarkannya demi portal akan menyentuh
 * aturan akses admin yang sudah berlaku. Lihat butir 147.
 */
Route::prefix('portal')->name('portal.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/masuk', PortalLogin::class)->name('login');
    });

    /*
     * Pagarnya EnsureParentPortalAccess sendiri, bukan middleware `auth`
     * bawaan: `auth` mengarahkan tamu ke rute bernama `login`, dan rute itu
     * tidak ada di project ini — halaman masuknya milik panel
     * (`filament.admin.auth.login`), yang justru bukan tempat orang tua.
     * Middleware portal mengarahkan ke halaman masuk portal (butir 147).
     */
    Route::middleware(EnsureParentPortalAccess::class)->group(function () {
        Route::get('/', ParentDashboard::class)->name('dashboard');
    });

    // Logout memakai POST supaya tidak dapat dipicu lewat tautan atau
    // prefetch peramban.
    Route::post('/keluar', function () {
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        return redirect()->route('portal.login');
    })->name('logout');
});
