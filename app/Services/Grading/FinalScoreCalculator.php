<?php

namespace App\Services\Grading;

use App\Enums\GradeType;
use App\Models\Grade;
use App\Models\GradeConfig;
use Illuminate\Support\Collection;

/**
 * NILAI-02 — "Formula: Nilai Akhir = (Harian × bobot) + (UTS × bobot) +
 * (UAS × bobot). Hasil pembulatan 2 desimal."
 *
 * Keputusan Sprint 4 butir 2 menentukan sumber bobotnya: **snapshot pada
 * `grades.weight`**, bukan konfigurasi yang berlaku saat rapor dibuka. Dengan
 * begitu mengubah Grade Config tidak pernah menggeser nilai yang sudah ada.
 */
class FinalScoreCalculator
{
    public function __construct(
        protected ComponentScoreAggregator $aggregator,
    ) {}

    /**
     * Menghitung nilai akhir satu mata pelajaran dari sekumpulan nilai siswa.
     *
     * @param  Collection<int, Grade>  $grades  seluruh nilai siswa pada satu class_subject
     */
    public function calculate(Collection $grades): FinalScoreResult
    {
        $valid = $this->aggregator->valid($grades);

        if ($valid->isEmpty()) {
            return FinalScoreResult::incomplete('Belum ada nilai sumatif yang diinput.');
        }

        $averages = $this->aggregator->averagesByType($valid);
        $config = $this->configUsedBy($valid);

        // Komponen yang wajib terisi ditentukan konfigurasi yang dipakai; bila
        // tidak terlacak, pakai komponen yang benar-benar ada nilainya.
        $requiredTypes = $config?->componentTypes() ?? array_map(
            fn (string $type) => GradeType::from($type),
            array_keys($averages),
        );

        // Komponen sumatif yang ada nilainya tetapi tidak tercantum di
        // konfigurasi. Nilainya sudah terlanjur diinput guru, tetapi tidak
        // pernah ikut menghitung apa pun — dan sampai sekarang tidak ada satu
        // pun pesan yang mengatakannya. Dikumpulkan di sini agar bisa
        // dilaporkan; perhitungannya sendiri tidak berubah.
        $ignored = array_values(array_diff(
            array_keys($averages),
            array_map(fn (GradeType $type) => $type->value, $requiredTypes),
        ));

        $snapshots = $this->weightsFrom($valid);
        $weights = [];
        $missing = [];
        $total = 0.0;

        foreach ($requiredTypes as $type) {
            if (! array_key_exists($type->value, $averages)) {
                $missing[] = $type->value;

                continue;
            }

            // Tanpa snapshot, bobot komponen ini tidak diketahui. Nilainya
            // TIDAK ditambal dari konfigurasi yang berlaku sekarang — itu akan
            // membuat rapor bergantung pada kebijakan terbaru, persis yang
            // dicegah keputusan butir 2.
            $weight = $snapshots[$type->value] ?? null;

            if ($weight === null) {
                $missing[] = $type->value;

                continue;
            }

            $weights[$type->value] = $weight;
            $total += $averages[$type->value] * $weight;
        }

        if ($missing !== []) {
            return FinalScoreResult::incomplete(
                'Komponen belum lengkap: '.implode(', ', $missing).'.',
                $averages,
                $missing,
                $config?->getKey(),
                $ignored,
            );
        }

        // Seluruh komponen terisi, tetapi bobotnya belum tentu utuh: nilai yang
        // lahir sebelum dan sesudah pergantian versi konfigurasi membawa
        // snapshot dari kebijakan yang berbeda, dan jumlahnya bisa meleset dari
        // 1.00. Menghitungnya tetap akan menghasilkan skor di luar skala tanpa
        // seorang pun menyadarinya.
        $weightTotal = array_sum($weights);

        if (abs($weightTotal - GradeConfig::TOTAL_WEIGHT) >= GradeConfig::WEIGHT_EPSILON) {
            return FinalScoreResult::inconsistentWeights(
                $weightTotal,
                $averages,
                $weights,
                $config?->getKey(),
                $ignored,
            );
        }

        return new FinalScoreResult(
            score: round($total, 2),
            isComplete: true,
            componentAverages: $averages,
            componentWeights: $weights,
            missingComponents: [],
            gradeConfigId: $config?->getKey(),
            ignoredComponents: $ignored,
        );
    }

    /**
     * Bobot snapshot per komponen.
     *
     * Bila satu komponen memiliki beberapa nilai dengan bobot berbeda — akibat
     * kebijakan bobot yang berganti versi di tengah semester — yang dipakai
     * adalah snapshot dari entri terbaru, yakni kebijakan terakhir yang
     * berlaku untuk komponen tersebut.
     *
     * @param  Collection<int, Grade>  $grades
     * @return array<string, float>
     */
    protected function weightsFrom(Collection $grades): array
    {
        return $grades
            ->filter(fn (Grade $grade) => $grade->weight !== null)
            ->sortBy([
                fn (Grade $a, Grade $b) => ($a->graded_at?->timestamp ?? 0) <=> ($b->graded_at?->timestamp ?? 0),
                fn (Grade $a, Grade $b) => $a->getKey() <=> $b->getKey(),
            ])
            ->groupBy(fn (Grade $grade) => $grade->grade_type->value)
            ->map(fn (Collection $group) => (float) $group->last()->weight)
            ->all();
    }

    /**
     * Konfigurasi yang benar-benar dipakai menilai — diambil dari snapshot
     * `grades.grade_config_id` entri terbaru.
     *
     * @param  Collection<int, Grade>  $grades
     */
    protected function configUsedBy(Collection $grades): ?GradeConfig
    {
        $configId = $grades
            ->filter(fn (Grade $grade) => $grade->grade_config_id !== null)
            ->sortBy([
                fn (Grade $a, Grade $b) => ($a->graded_at?->timestamp ?? 0) <=> ($b->graded_at?->timestamp ?? 0),
                fn (Grade $a, Grade $b) => $a->getKey() <=> $b->getKey(),
            ])
            ->last()?->grade_config_id;

        return $configId === null ? null : GradeConfig::query()->find($configId);
    }
}
