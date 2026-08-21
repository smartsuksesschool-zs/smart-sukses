<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ChildResource;
use App\Services\Portal\ParentPortalService;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API 4.11 — /parent/children dan /parent/children/{studentId}/summary.
 *
 * Controller tipis: kepemilikan anak, penyusunan ringkasan, dan pagar perannya
 * semua ada di ParentPortalService — service yang sama dengan yang dipakai
 * halaman portal, sehingga API dan layar tidak dapat berbeda pendapat tentang
 * anak siapa yang boleh dilihat.
 */
class ParentPortalController extends Controller
{
    /**
     * GET /parent/children — "Daftar anak yang terdaftar sebagai anak dari user
     * yang login (role ORANG_TUA)".
     *
     * @throws AuthorizationException
     */
    public function children(Request $request): JsonResponse
    {
        $children = app(ParentPortalService::class)->children($request->user());

        return ApiResponse::success(
            ChildResource::collection($children)->resolve(),
        );
    }

    /**
     * GET /parent/children/{studentId}/summary — "Dashboard anak: nilai terbaru
     * 5 mapel, hadir bulan ini, tagihan pending".
     *
     * Anak yang bukan miliknya menjadi 404 lewat `findOrFail` di service:
     * membedakan "bukan milik Anda" dari "tidak ada" akan memberi tahu bahwa
     * anak itu ada (butir 148).
     *
     * @throws AuthorizationException|ModelNotFoundException
     */
    public function summary(Request $request, int $studentId): JsonResponse
    {
        $summary = app(ParentPortalService::class)->summary($request->user(), $studentId);

        return ApiResponse::success([
            'child' => (new ChildResource($summary['child']))->resolve(),
            'latest_grades' => $summary['latest_grades'],
            'attendance' => $summary['attendance'],
            'pending_fees' => $summary['pending_fees'],
        ]);
    }
}
