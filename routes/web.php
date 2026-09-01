<?php

use App\Http\Controllers\LandingController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Portal\ReportCardDownloadController;
use App\Http\Controllers\Portal\StudentReportCardController;
use App\Http\Middleware\EnsureParentPortalAccess;
use App\Http\Middleware\EnsureStudentPortalAccess;
use App\Http\Middleware\EnsureTeacherPortalAccess;
use App\Livewire\Auth\Login;
use App\Livewire\Portal\NotificationInbox;
use App\Livewire\Portal\ParentDashboard;
use App\Livewire\Portal\ParentFees;
use App\Livewire\Portal\ParentGrades;
use App\Livewire\Portal\ParentSchedule;
use App\Livewire\Ppdb\RegistrationForm;
use App\Livewire\Ppdb\SchoolList;
use App\Livewire\Ppdb\StatusCheck;
use App\Livewire\Student\StudentDashboard;
use App\Livewire\Student\StudentExam;
use App\Livewire\Student\StudentExamResult;
use App\Livewire\Student\StudentExams;
use App\Livewire\Student\StudentGrades;
use App\Livewire\Student\StudentProfile;
use App\Livewire\Student\StudentSchedule;
use App\Livewire\Teacher\TeacherClasses;
use App\Livewire\Teacher\TeacherClassStudents;
use App\Livewire\Teacher\TeacherDashboard;
use App\Livewire\Teacher\TeacherSchedule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
 * Halaman muka publik — tambahan langsung atas permintaan pemilik, di luar
 * blueprint Phase 1 (docs/owner-scope-changes.md bagian A).
 *
 * Publik sepenuhnya: tanpa middleware, tanpa sesi, dan tanpa pengalihan ke
 * halaman masuk mana pun. Sistem ini punya tiga pintu masuk yang berbeda, jadi
 * halaman ini mengantar pengunjung ke pintu yang tepat alih-alih menebak
 * (butir 349).
 */
Route::get('/', LandingController::class)->name('landing');

/*
 * NFR 1.4 — pemilih bahasa ID/EN, untuk tamu maupun pengguna yang login.
 *
 * **POST, bukan GET.** Rute ini menulis: sesi bagi tamu, dan kolom
 * `users.locale` bagi pengguna yang login. Menyembunyikan tulisan di balik GET
 * berarti setiap pemuatan awal halaman, prefetch peramban, crawler, dan
 * pemindai tautan dapat mengubah preferensi seseorang tanpa ia menekan apa pun
 * — dan GET tidak dilindungi CSRF, sehingga sebuah `<img src>` di situs lain
 * cukup untuk mengganti bahasa akun korban. Satu-satunya jalur penulisan
 * karena itu POST, yang otomatis melewati `VerifyCsrfToken` milik grup `web`
 * (butir 388).
 *
 * Tidak ada rute GET yang tersisa untuk URI ini: `GET /bahasa/en` menjawab 405,
 * bukan diam-diam menyimpan.
 *
 * Kode bahasa yang tidak dikenal jatuh ke Indonesia tanpa galat — nilai locale
 * ikut menentukan berkas terjemahan yang dimuat, jadi nilai sembarang dari URL
 * tidak boleh sampai ke sana (butir 377).
 */
Route::post('/bahasa/{locale}', LocaleController::class)
    ->whereAlpha('locale')
    ->name('locale.switch');

/*
 * Pintu masuk tunggal — keputusan pemilik, menggantikan tiga pintu terpisah.
 *
 * Pengunjung tidak lagi memilih perannya sebelum masuk: ia mengetikkan
 * kredensial, dan server yang menentukan tujuannya dari peran yang tersimpan
 * di akunnya (App\Support\LoginDestination, butir 437).
 *
 * Rutenya bernama `login`. Sebelum ini project sengaja tidak punya rute dengan
 * nama itu, dan catatan di bawah menyebutnya sebagai alasan portal memakai
 * middleware sendiri. Alasan itu kini tinggal separuh: nama `login` ada, tetapi
 * setiap portal tetap perlu memeriksa **peran**, bukan sekadar "sudah masuk
 * atau belum" (butir 442).
 */
Route::get('/login', Login::class)->name('login');

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
        /*
         * Alamat lama tetap hidup: penanda halaman yang sudah tersebar tidak
         * boleh menjadi 404 hanya karena arsitekturnya disatukan (butir 443).
         *
         * `Route::get`, bukan `Route::redirect`: yang terakhir mendaftarkan
         * **seluruh** metode, sehingga alamat masuk lama akan ikut menjawab
         * POST dan DELETE — permukaan yang lebih lebar daripada halaman yang
         * digantikannya, tanpa satu alasan pun (butir 448).
         */
        Route::get('/masuk', fn () => redirect()->route('login'))->name('login');
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

        /*
         * NOTIF-04 — kotak masuk notifikasi orang tua.
         *
         * Penerimanya akun orang tua itu sendiri, bukan profil anak yang sedang
         * dipilih: berpindah anak tidak mengubah isi halaman ini (butir 212).
         * Komponennya sama dengan kedua portal lain; yang membedakan hanya
         * middleware di atasnya (butir 208).
         */
        Route::get('/notifikasi', NotificationInbox::class)->name('notifications');

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

    // NOTIF-04 — "notifikasi masuk" yang API 4.11 sebut pada dasbor guru, kini
    // punya halamannya sendiri. Tetap hanya sisi penerima: tidak ada jalan
    // membuat pengumuman dari portal guru (butir 213).
    Route::get('/notifikasi', NotificationInbox::class)->name('notifications');
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
        // Alamat lama tetap hidup, dan tetap GET saja (butir 443, 448).
        Route::get('/masuk', fn () => redirect()->route('login'))->name('login');
    });

    Route::middleware(EnsureStudentPortalAccess::class)->group(function () {
        Route::get('/', StudentDashboard::class)->name('dashboard');
        Route::get('/jadwal', StudentSchedule::class)->name('schedule');
        Route::get('/nilai', StudentGrades::class)->name('grades');
        Route::get('/profil', StudentProfile::class)->name('profile');

        /*
         * Ujian online (CBT) — tambahan scope atas permintaan pemilik, di luar
         * blueprint Phase 1 (docs/owner-scope-changes.md).
         *
         * Alamatnya hanya menyebut **ujiannya**. Tidak ada id siswa di mana pun,
         * karena identitas siswa selalu berasal dari akun yang login lewat
         * StudentPortalService — pola yang sama dengan seluruh halaman portal
         * lain (butir 186, 307).
         */
        Route::get('/ujian', StudentExams::class)->name('exams');
        Route::get('/ujian/{examId}', StudentExam::class)
            ->whereNumber('examId')
            ->name('exam');
        Route::get('/ujian/{examId}/hasil', StudentExamResult::class)
            ->whereNumber('examId')
            ->name('exam-result');

        // PORTAL-03 poin 1 menyebut menu Notifikasi; sejak Batch 8.2 menu itu
        // punya halamannya, jadi ia tautan sungguhan (butir 208).
        Route::get('/notifikasi', NotificationInbox::class)->name('notifications');

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
