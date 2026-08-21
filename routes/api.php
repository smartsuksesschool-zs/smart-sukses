<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FeeTypeController;
use App\Http\Controllers\Api\FinanceExportController;
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

        Route::get('finance/export', [FinanceExportController::class, 'cashLedger']);
    });
});

/*
| Sengaja BELUM didaftarkan, dan tidak sebagai placeholder:
|
|   GET    /finance/summary       — `income` tingkat-atas belum dihitung
|   GET    /finance/spp-report    — `tunggakan` belum dihitung
|   GET    /admin/dashboard       — field-nya belum ada di service mana pun
|   GET    /admin/schools/{id}/stats — idem
|
| Lihat butir 125. DELETE /transactions/{id}, yang dulu juga ada di daftar ini,
| sudah dibuat pada Batch 6.7 (butir 128).
*/
