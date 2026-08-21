<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ChildFeeResource;
use App\Http\Resources\Api\ChildResource;
use App\Models\ReportCard;
use App\Services\Portal\ParentPortalService;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API 4.11 — tiga endpoint rincian anak: nilai, tagihan, dan jadwal.
 *
 * Ketiganya memakai `ParentPortalService`, dan karena itu memakai pagar
 * kepemilikan yang sama dengan Batch 7.1: anak orang lain dan anak cabang lain
 * sama-sama 404, tanpa satu pun `Student::find()` di controller ini.
 */
class ParentPortalDetailController extends Controller
{
    /**
     * GET /parent/children/{studentId}/grades — "Nilai lengkap anak per tahun
     * ajaran aktif".
     *
     * @throws AuthorizationException|ModelNotFoundException
     */
    public function grades(Request $request, int $studentId): JsonResponse
    {
        $data = app(ParentPortalService::class)->grades($request->user(), $studentId);

        return ApiResponse::success([
            'child' => (new ChildResource($data['child']))->resolve(),
            'academic_year' => $data['academic_year'] === null ? null : [
                'id' => $data['academic_year']->getKey(),
                'name' => $data['academic_year']->name,
            ],
            'subjects' => $data['subjects'],
            // NILAI-04 poin 2 — rapor hanya muncul setelah diterbitkan; yang
            // masih draf tidak pernah keluar lewat portal (butir 162).
            'report_card' => $this->reportCardPayload($data['report_card']),
        ]);
    }

    /**
     * GET /parent/children/{studentId}/fees — "Semua tagihan anak + status +
     * riwayat bayar".
     *
     * @throws AuthorizationException|ModelNotFoundException
     */
    public function fees(Request $request, int $studentId): JsonResponse
    {
        $data = app(ParentPortalService::class)->fees($request->user(), $studentId);

        return ApiResponse::success([
            'child' => (new ChildResource($data['child']))->resolve(),
            'fees' => ChildFeeResource::collection($data['fees'])->resolve(),
        ]);
    }

    /**
     * GET /parent/children/{studentId}/schedule — "Jadwal pelajaran kelas anak
     * hari ini & minggu ini".
     *
     * `today` disaring dari daftar mingguan yang sama, bukan dari query kedua:
     * keduanya menjawab pertanyaan yang sama pada data yang sama.
     *
     * @throws AuthorizationException|ModelNotFoundException
     */
    public function schedule(Request $request, int $studentId): JsonResponse
    {
        $data = app(ParentPortalService::class)->schedule($request->user(), $studentId);

        $today = array_values(array_filter(
            $data['lessons'],
            fn (array $lesson) => $lesson['day_of_week'] === $data['today'],
        ));

        return ApiResponse::success([
            'child' => (new ChildResource($data['child']))->resolve(),
            'academic_year' => $data['academic_year'] === null ? null : [
                'id' => $data['academic_year']->getKey(),
                'name' => $data['academic_year']->name,
            ],
            'current_class' => $data['current_class'] === null ? null : [
                'id' => $data['current_class']->getKey(),
                'name' => $data['current_class']->name,
            ],
            'today' => $today,
            'week' => $data['lessons'],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function reportCardPayload(?ReportCard $reportCard): ?array
    {
        if ($reportCard === null) {
            return null;
        }

        return [
            'id' => $reportCard->getKey(),
            'class_name' => $reportCard->schoolClass?->name,
            'published_at' => $reportCard->published_at?->toIso8601String(),
            'average_score' => $reportCard->averageScore(),
            // Jalur berkasnya tidak pernah keluar; yang diberi tahu hanya
            // apakah unduhan itu ada (butir 162).
            'is_downloadable' => true,
        ];
    }
}
