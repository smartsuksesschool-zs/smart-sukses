<?php

namespace App\Http\Resources\Api;

use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API 4.3 — GET /admin/schools/{id}/stats.
 *
 * Daftar-izin, bukan `toArray()` model. Cabangnya diringkas ke empat kolom
 * identitas saja: konfigurasi white-label, template WA, dan pengaturan internal
 * lain tidak ada urusannya dengan statistik dan karena itu tidak pernah keluar
 * lewat endpoint ini (butir 118).
 *
 * @property-read array{school: School, period: string, student_count: int, teacher_count: int, collected_this_month: string, arrears: string} $resource
 */
class SchoolStatisticsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $school = $this->resource['school'];

        return [
            'school' => [
                'id' => $school->id,
                'code' => $school->code,
                'name' => $school->name,
                'is_active' => (bool) $school->is_active,
            ],
            // Periodenya disebutkan supaya arti "bulan ini" tidak bergantung
            // pada tebakan pemanggil tentang zona waktu server.
            'period' => $this->resource['period'],
            'student_count' => $this->resource['student_count'],
            'teacher_count' => $this->resource['teacher_count'],
            'collected_this_month' => $this->resource['collected_this_month'],
            'arrears' => $this->resource['arrears'],
        ];
    }
}
