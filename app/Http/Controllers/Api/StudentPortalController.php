<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\ReportCard;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\Portal\StudentPortalService;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API 4.11 — /student/dashboard, /student/schedule, /student/grades
 * (PORTAL-03).
 *
 * Ketiganya memakai StudentPortalService, yang selalu menurunkan identitas
 * siswa dari akun yang login. Tidak ada satu pun `student_id` yang diterima
 * dari pemanggil (butir 181).
 */
class StudentPortalController extends Controller
{
    /**
     * GET /student/dashboard — "jadwal hari ini, 5 nilai terbaru, notifikasi".
     *
     * @throws AuthorizationException|ModelNotFoundException
     */
    public function dashboard(Request $request): JsonResponse
    {
        $data = app(StudentPortalService::class)->dashboard($request->user());

        return ApiResponse::success([
            'student' => $this->studentPayload($data['student']),
            'academic_year' => $this->yearPayload($data['academic_year']),
            'current_class' => $this->classPayload($data['current_class']),
            'today' => $data['today'],
            'today_schedule' => $data['today_schedule'],
            'latest_grades' => $data['latest_grades'],
            'notifications' => $data['notifications'],
        ]);
    }

    /**
     * GET /student/schedule — "jadwal pelajaran siswa (tahun ajaran aktif)".
     *
     * @throws AuthorizationException|ModelNotFoundException
     */
    public function schedule(Request $request): JsonResponse
    {
        $data = app(StudentPortalService::class)->schedule($request->user());

        $today = array_values(array_filter(
            $data['lessons'],
            fn (array $lesson) => $lesson['day_of_week'] === $data['today'],
        ));

        return ApiResponse::success([
            'student' => $this->studentPayload($data['student']),
            'academic_year' => $this->yearPayload($data['academic_year']),
            'current_class' => $this->classPayload($data['current_class']),
            'today' => $today,
            'week' => $data['lessons'],
        ]);
    }

    /**
     * GET /student/grades — "nilai siswa yang login (tahun ajaran aktif)".
     *
     * @throws AuthorizationException|ModelNotFoundException
     */
    public function grades(Request $request): JsonResponse
    {
        $data = app(StudentPortalService::class)->grades($request->user());

        return ApiResponse::success([
            'student' => $this->studentPayload($data['student']),
            'academic_year' => $this->yearPayload($data['academic_year']),
            'current_class' => $this->classPayload($data['current_class']),
            'subjects' => $data['subjects'],
            'report_card' => $this->reportCardPayload($data['report_card']),
        ]);
    }

    /**
     * Daftar-izin. `user_id`, `parent_user_id`, `school_id`, dan jalur foto
     * mentah tidak pernah keluar (butir 118).
     *
     * @return array<string, mixed>
     */
    protected function studentPayload(Student $student): array
    {
        return [
            'id' => $student->getKey(),
            'nis' => $student->nis,
            'nisn' => $student->nisn,
            'full_name' => $student->full_name,
            'status' => $student->status?->value,
            'has_photo' => filled($student->photo_url),
            'school_name' => $student->school?->name,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function yearPayload(?AcademicYear $year): ?array
    {
        return $year === null ? null : [
            'id' => $year->getKey(),
            'name' => $year->name,
            // PORTAL-03 poin 2 — "per semester". Nilainya nyata dari ERD
            // (academic_years.semester), bukan dikarang (butir 184).
            'semester' => $year->semester,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function classPayload(?SchoolClass $class): ?array
    {
        return $class === null ? null : [
            'id' => $class->getKey(),
            'name' => $class->name,
            'grade_level' => $class->grade_level,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function reportCardPayload(?ReportCard $reportCard): ?array
    {
        return $reportCard === null ? null : [
            'id' => $reportCard->getKey(),
            'class_name' => $reportCard->schoolClass?->name,
            'published_at' => $reportCard->published_at?->toIso8601String(),
            'average_score' => $reportCard->averageScore(),
            'is_downloadable' => true,
        ];
    }
}
