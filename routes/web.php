<?php

use App\Http\Controllers\Portal\ReportCardDownloadController;
use App\Http\Controllers\Portal\StudentReportCardController;
use App\Http\Middleware\EnsureParentPortalAccess;
use App\Http\Middleware\EnsureStudentPortalAccess;
use App\Http\Middleware\EnsureTeacherPortalAccess;
use App\Livewire\Portal\ParentDashboard;
use App\Livewire\Portal\ParentFees;
use App\Livewire\Portal\ParentGrades;
use App\Livewire\Portal\ParentSchedule;
use App\Livewire\Portal\PortalLogin;
use App\Livewire\Ppdb\RegistrationForm;
use App\Livewire\Ppdb\SchoolList;
use App\Livewire\Ppdb\StatusCheck;
use App\Livewire\Student\StudentDashboard;
use App\Livewire\Student\StudentGrades;
use App\Livewire\Student\StudentLogin;
use App\Livewire\Student\StudentProfile;
use App\Livewire\Student\StudentSchedule;
use App\Livewire\Teacher\TeacherClasses;
use App\Livewire\Teacher\TeacherClassStudents;
use App\Livewire\Teacher\TeacherDashboard;
use App\Livewire\Teacher\TeacherSchedule;
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
        Route::get('/nilai', ParentGrades::class)->name('grades');
        Route::get('/tagihan', ParentFees::class)->name('fees');
        Route::get('/jadwal', ParentSchedule::class)->name('schedule');

        // NILAI-04 poin 3. Kepemilikan anak dan status terbitnya rapor
        // diperiksa di controller lewat pagar Batch 7.1 (butir 162).
        Route::get('/nilai/{studentId}/rapor/{reportCardId}', ReportCardDownloadController::class)
            ->whereNumber(['studentId', 'reportCardId'])
            ->name('report-card');
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

/*
 * PORTAL-02 / API 4.11 — Teacher Portal.
 *
 * Menumpang sesi `web` yang sama dengan panel: guru memang berhak memasuki
 * panel (Input Nilai), jadi tidak ada halaman masuk ketiga yang dibuat.
 * Kelayakannya diperiksa EnsureTeacherPortalAccess, dengan aturan yang sama
 * dengan portal orang tua termasuk penanda ganti kata sandi (butir 171, 177).
 */
Route::prefix('teacher')->name('teacher.')->middleware(EnsureTeacherPortalAccess::class)->group(function () {
    Route::get('/', TeacherDashboard::class)->name('dashboard');
    Route::get('/kelas', TeacherClasses::class)->name('classes');
    Route::get('/kelas/{classId}', TeacherClassStudents::class)
        ->whereNumber('classId')
        ->name('class-students');
    Route::get('/jadwal', TeacherSchedule::class)->name('schedule');
});

/*
 * PORTAL-03 / API 4.11 — Student Portal.
 *
 * Siswa ditolak seluruh panel, persis seperti orang tua, jadi portal ini punya
 * halaman masuknya sendiri. Syarat kelayakannya dibaca dari PortalEligibility,
 * sumber yang sama dengan kedua portal lain (butir 180).
 */
Route::prefix('siswa')->name('student.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/masuk', StudentLogin::class)->name('login');
    });

    Route::middleware(EnsureStudentPortalAccess::class)->group(function () {
        Route::get('/', StudentDashboard::class)->name('dashboard');
        Route::get('/jadwal', StudentSchedule::class)->name('schedule');
        Route::get('/nilai', StudentGrades::class)->name('grades');
        Route::get('/profil', StudentProfile::class)->name('profile');

        Route::get('/nilai/rapor/{reportCardId}', StudentReportCardController::class)
            ->whereNumber('reportCardId')
            ->name('report-card');
    });

    Route::post('/keluar', function () {
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        return redirect()->route('student.login');
    })->name('logout');
});
