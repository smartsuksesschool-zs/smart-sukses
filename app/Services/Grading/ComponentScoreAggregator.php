<?php

namespace App\Services\Grading;

use App\Enums\AssessmentType;
use App\Enums\GradeType;
use App\Models\Grade;
use Illuminate\Support\Collection;

/**
 * Keputusan Sprint 4 butir 1 — "Jika satu siswa memiliki beberapa grade DAILY
 * yang valid, nilai 'Harian' dihitung dengan RATA-RATA seluruh nilai DAILY
 * yang valid. Contoh: 80, 90, 70 = 80."
 *
 * Aturan yang sama berlaku untuk seluruh komponen akademik lain.
 *
 * Definisi "valid" (keputusan butir 3):
 *   1. assessment_type = SUMMATIVE — nilai formatif tidak masuk rapor;
 *   2. grade_type termasuk komponen akademik (ATTITUDE dikecualikan).
 */
class ComponentScoreAggregator
{
    /**
     * Menyaring nilai yang boleh masuk perhitungan rapor.
     *
     * @param  Collection<int, Grade>  $grades
     * @return Collection<int, Grade>
     */
    public function valid(Collection $grades): Collection
    {
        return $grades->filter(fn (Grade $grade) => $this->isSummative($grade)
            && $grade->grade_type?->isAcademic() === true);
    }

    /**
     * Rata-rata skor per komponen.
     *
     * @param  Collection<int, Grade>  $grades
     * @return array<string, float> kunci = GradeType->value
     */
    public function averagesByType(Collection $grades): array
    {
        return $this->valid($grades)
            ->groupBy(fn (Grade $grade) => $grade->grade_type->value)
            ->map(fn (Collection $group) => round(
                $group->sum(fn (Grade $grade) => (float) $grade->score) / $group->count(),
                2,
            ))
            ->all();
    }

    /**
     * Rata-rata satu komponen tertentu, atau NULL bila tidak ada nilai valid.
     *
     * @param  Collection<int, Grade>  $grades
     */
    public function averageForType(Collection $grades, GradeType $type): ?float
    {
        return $this->averagesByType($grades)[$type->value] ?? null;
    }

    /**
     * Rata-rata nilai sikap — dipisahkan karena ATTITUDE tidak pernah masuk
     * perhitungan akademik (keputusan butir 3), tetapi tetap dilaporkan
     * sebagai predikat pada rapor.
     *
     * Pembedaan formatif/sumatif berlaku di sini juga: observasi sikap yang
     * dicatat sebagai FORMATIVE adalah catatan proses dan tidak menggeser
     * predikat yang tercetak di rapor.
     *
     * @param  Collection<int, Grade>  $grades
     */
    public function attitudeAverage(Collection $grades): ?float
    {
        $attitude = $grades->filter(
            fn (Grade $grade) => $grade->grade_type === GradeType::Attitude
                && $this->isSummative($grade),
        );

        if ($attitude->isEmpty()) {
            return null;
        }

        return round(
            $attitude->sum(fn (Grade $grade) => (float) $grade->score) / $attitude->count(),
            2,
        );
    }

    /**
     * "Data harus membedakan formative dan summative" — hanya yang sumatif
     * yang boleh mempengaruhi apa pun di rapor, baik nilai akademik maupun
     * predikat sikap.
     */
    protected function isSummative(Grade $grade): bool
    {
        return $grade->assessment_type === AssessmentType::Summative;
    }
}
