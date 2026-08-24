<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Portal\TeacherPortalService;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API 4.11 — GET /teacher/dashboard dan GET /teacher/classes (PORTAL-02).
 *
 * Keduanya memakai `TeacherPortalService`, service yang sama dengan halaman
 * portal, sehingga arti "kelas yang diampu" tidak dapat berbeda antara layar
 * dan API.
 */
class TeacherPortalController extends Controller
{
    /**
     * GET /teacher/dashboard — "Dashboard guru: jadwal hari ini, kelas aktif,
     * notifikasi masuk".
     *
     * @throws AuthorizationException
     */
    public function dashboard(Request $request): JsonResponse
    {
        $data = app(TeacherPortalService::class)->dashboard($request->user());

        return ApiResponse::success([
            // Nama dan label peran saja. Surel, telepon, cabang, dan daftar
            // izinnya tidak menambah apa pun bagi pemilik akunnya sendiri.
            'teacher' => [
                'name' => $data['teacher']->name,
                'role_label' => $data['teacher']->primaryRole()?->label(),
            ],
            'academic_year' => $data['academic_year'] === null ? null : [
                'id' => $data['academic_year']->getKey(),
                'name' => $data['academic_year']->name,
            ],
            'today' => $data['today'],
            'today_schedule' => $data['today_schedule'],
            'active_classes' => $data['active_classes'],
            'homeroom_class' => $data['homeroom_class'] === null ? null : [
                'id' => $data['homeroom_class']->getKey(),
                'name' => $data['homeroom_class']->name,
            ],
            // Subsistemnya milik Sprint 8; keadaannya dinyatakan apa adanya
            // alih-alih dikarang menjadi angka (butir 175).
            'notifications' => $data['notifications'],
        ]);
    }

    /**
     * GET /teacher/classes — "Kelas yang diampu guru yang login (tahun ajaran
     * aktif)".
     *
     * @throws AuthorizationException
     */
    public function classes(Request $request): JsonResponse
    {
        return ApiResponse::success(
            app(TeacherPortalService::class)->classes($request->user()),
        );
    }
}
