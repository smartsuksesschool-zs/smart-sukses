<?php

use App\Livewire\Ppdb\RegistrationForm;
use App\Livewire\Ppdb\SchoolList;
use App\Livewire\Ppdb\StatusCheck;
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
