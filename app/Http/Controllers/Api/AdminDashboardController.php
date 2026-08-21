<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\SchoolStatisticsResource;
use App\Services\Admin\SchoolStatisticsService;
use App\Services\Admin\SuperAdminDashboardService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API 4.3 — GET /admin/dashboard dan GET /admin/schools/{id}/stats.
 *
 * Keduanya Auth Level **Super**, dan keduanya satu-satunya endpoint keuangan
 * yang memang lintas atau lintas-pilih cabang secara sah. Rutenya dipagari
 * `auth_level:super`; service-nya memeriksa lagi, karena panel memanggil
 * service yang sama tanpa melewati middleware itu.
 */
class AdminDashboardController extends Controller
{
    /**
     * GET /admin/dashboard — "Dashboard ringkasan semua cabang: total siswa,
     * total SPP terkumpul, PPDB aktif".
     *
     * Tiga angka itu saja. Saldo kas platform, total pengeluaran, dan
     * persentase lunas per cabang tidak ditambahkan: yang terakhir sudah punya
     * dashboardnya sendiri (KAS-03), dan dua yang pertama tidak diminta
     * siapa pun.
     */
    public function dashboard(Request $request): JsonResponse
    {
        return ApiResponse::success(
            app(SuperAdminDashboardService::class)->summarize($request->user()),
        );
    }

    /**
     * GET /admin/schools/{id}/stats — "Statistik: jumlah siswa, guru, tagihan
     * terkumpul bulan ini, tunggakan".
     *
     * Cabang yang tidak ada menjadi 404 lewat `findOrFail`; cabang yang
     * **nonaktif** tetap dapat dibaca, karena riwayatnya justru itu yang
     * dicari setelah sebuah cabang ditutup (butir 144).
     */
    public function schoolStats(Request $request, int $id): JsonResponse
    {
        $stats = app(SchoolStatisticsService::class)->forSchool($id, $request->user());

        return ApiResponse::success((new SchoolStatisticsResource($stats))->resolve());
    }
}
