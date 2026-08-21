<?php

use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FeeTypeController;
use App\Http\Controllers\Api\FinanceExportController;
use App\Http\Controllers\Api\FinanceReportController;
use App\Http\Controllers\Api\ParentPortalController;
use App\Http\Controllers\Api\ParentPortalDetailController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\StudentFeeController;
use App\Http\Controllers\Api\TransactionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| REST API v1
|--------------------------------------------------------------------------
|
| API 4.1 — Base URL `https://apps.smartsukses.sch.id/api/v1`. Prefiks `api`
| dipasang bootstrap/app.php; `v1` di sini supaya versi berikutnya dapat hidup
| berdampingan tanpa menyentuh bootstrap.
|
| Auth Level API 4.1:
|   Public — tanpa token
|   Auth   — wajib token; dibatasi ke data sekolah user (SchoolScope)
|   Admin  — wajib token + SCHOOL_ADMIN / SUPER_ADMIN
|   Super  — wajib token + SUPER_ADMIN
|
| Beberapa endpoint keuangan berlabel "Admin" pada API map sengaja tidak
| memakai middleware `auth_level:admin`: user story-nya menyebut Bendahara
| secara eksplisit, dan policy domainnya sudah menegakkan kewenangan yang lebih
| tepat (butir 117). Endpoint yang labelnya tidak tertimpa apa pun tetap
| memakai `auth_level:admin`.
|
| Throttle mengikuti Arsitektur 3.4: login 5 percobaan/menit, API 60
| request/menit per pengguna.
|
*/

Route::prefix('v1')->group(function (): void {
    // ------------------------------------------------------------- 4.2 Auth
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum', 'throttle:60,1', 'auth_level:auth'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        // ------------------------------------------------- 4.9.1 Jenis tagihan
        Route::get('fee-types', [FeeTypeController::class, 'index']);
        Route::post('fee-types', [FeeTypeController::class, 'store']);
        Route::put('fee-types/{id}', [FeeTypeController::class, 'update'])->whereNumber('id');

        // ---------------------------------------------------- 4.9.1 Tagihan
        // `export` didaftarkan sebelum `{id}` supaya tidak tertangkap sebagai id.
        Route::get('student-fees/export', [FinanceExportController::class, 'studentFees']);
        Route::get('student-fees', [StudentFeeController::class, 'index']);
        Route::post('student-fees/generate-bulk', [StudentFeeController::class, 'generateBulk']);
        Route::get('student-fees/{id}', [StudentFeeController::class, 'show'])->whereNumber('id');
        Route::patch('student-fees/{id}/waive', [StudentFeeController::class, 'waive'])->whereNumber('id');

        // -------------------------------------------------- 4.9.1 Pembayaran
        Route::get('payments', [PaymentController::class, 'index']);
        Route::post('payments', [PaymentController::class, 'store']);
        Route::post('payments/{id}/proof', [PaymentController::class, 'attachProof'])->whereNumber('id');

        // ------------------------------------------------- 4.9.2 Buku kas
        Route::get('transactions', [TransactionController::class, 'index']);
        Route::post('transactions', [TransactionController::class, 'store']);
        Route::put('transactions/{id}', [TransactionController::class, 'update'])->whereNumber('id');
        // "Soft delete": kewenangannya lebih sempit daripada create/update dan
        // ditegakkan TransactionPolicy::delete(), bukan oleh middleware di sini
        // (butir 129).
        Route::delete('transactions/{id}', [TransactionController::class, 'destroy'])->whereNumber('id');

        // ------------------------------------------- 4.9.2 Laporan keuangan
        Route::get('finance/summary', [FinanceReportController::class, 'summary']);
        Route::get('finance/spp-report', [FinanceReportController::class, 'sppReport']);
        Route::get('finance/export', [FinanceExportController::class, 'cashLedger']);

        // --------------------------------------------- 4.11 Parent Portal
        // Auth Level "Auth" pada API map; perannya sendiri ditegakkan
        // ParentPortalService, sama seperti pada laporan keuangan (butir 148).
        Route::get('parent/children', [ParentPortalController::class, 'children']);
        Route::get('parent/children/{studentId}/summary', [ParentPortalController::class, 'summary'])
            ->whereNumber('studentId');
        Route::get('parent/children/{studentId}/grades', [ParentPortalDetailController::class, 'grades'])
            ->whereNumber('studentId');
        Route::get('parent/children/{studentId}/fees', [ParentPortalDetailController::class, 'fees'])
            ->whereNumber('studentId');
        Route::get('parent/children/{studentId}/schedule', [ParentPortalDetailController::class, 'schedule'])
            ->whereNumber('studentId');

        // ------------------------------------------------- 4.3 Super Admin
        // Satu-satunya kelompok yang memang lintas cabang. Pagarnya berlapis:
        // `auth_level:super` di sini, dan pemeriksaan yang sama di dalam
        // service karena panel memanggil service itu tanpa melewati rute.
        Route::middleware('auth_level:super')->group(function (): void {
            Route::get('admin/dashboard', [AdminDashboardController::class, 'dashboard']);
            Route::get('admin/schools/{id}/stats', [AdminDashboardController::class, 'schoolStats'])
                ->whereNumber('id');
        });
    });
});

/*
| Yang tersisa dari API 4.3 dan 4.4 — GET/POST/PUT/PATCH /admin/schools serta
| seluruh /users — memang belum dibuat; keduanya milik manajemen tenant dan
| pengguna, yang di panel sudah berjalan lewat SchoolResource dan UserResource.
| Lihat butir 145, 146.
|
| Dari API 4.11, seluruh endpoint orang tua kini ada. Yang tersisa —
| /teacher/dashboard, /teacher/classes, dan seluruh /student — menyusul pada
| batch Sprint 7 berikutnya, dan sengaja tidak didaftarkan sebagai placeholder
| (butir 155).
*/
