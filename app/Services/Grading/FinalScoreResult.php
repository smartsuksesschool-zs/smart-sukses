<?php

namespace App\Services\Grading;

/**
 * Hasil perhitungan nilai akhir satu mata pelajaran untuk satu siswa.
 *
 * Dipisahkan menjadi objek tersendiri agar ReportCardGenerator dapat
 * membedakan "nilai akhir belum lengkap" dari "nilai akhir 0", dan dapat
 * menjelaskan komponen mana yang belum terisi (NILAI-03 poin 1).
 */
readonly class FinalScoreResult
{
    /**
     * @param  array<string, float>  $componentAverages  rata-rata per komponen
     * @param  array<string, float>  $componentWeights  bobot snapshot per komponen
     * @param  array<int, string>  $missingComponents  komponen tanpa nilai valid
     * @param  array<int, string>  $ignoredComponents  komponen sumatif yang ada
     *                                                 nilainya tetapi tidak
     *                                                 dikonfigurasi, sehingga
     *                                                 tidak ikut nilai akhir
     */
    public function __construct(
        public ?float $score,
        public bool $isComplete,
        public array $componentAverages = [],
        public array $componentWeights = [],
        public array $missingComponents = [],
        public ?int $gradeConfigId = null,
        public ?string $reason = null,
        public array $ignoredComponents = [],
    ) {}

    /**
     * @param  array<int, string>  $ignoredComponents
     */
    public static function incomplete(
        string $reason,
        array $componentAverages = [],
        array $missingComponents = [],
        ?int $gradeConfigId = null,
        array $ignoredComponents = [],
    ): self {
        return new self(
            score: null,
            isComplete: false,
            componentAverages: $componentAverages,
            missingComponents: $missingComponents,
            gradeConfigId: $gradeConfigId,
            reason: $reason,
            ignoredComponents: $ignoredComponents,
        );
    }

    /**
     * Seluruh komponen terisi, tetapi bobot snapshot-nya tidak berjumlah 1.00 —
     * gejala nilai yang lahir dari beberapa versi konfigurasi yang berbeda.
     * Skornya sengaja tidak dihitung: angka dari bobot yang tidak utuh berada
     * di luar skala 0–100 dan akan menyesatkan bila tercetak di rapor.
     *
     * @param  array<string, float>  $componentAverages
     * @param  array<string, float>  $componentWeights
     * @param  array<int, string>  $ignoredComponents
     */
    public static function inconsistentWeights(
        float $weightTotal,
        array $componentAverages = [],
        array $componentWeights = [],
        ?int $gradeConfigId = null,
        array $ignoredComponents = [],
    ): self {
        return new self(
            score: null,
            isComplete: false,
            componentAverages: $componentAverages,
            componentWeights: $componentWeights,
            gradeConfigId: $gradeConfigId,
            ignoredComponents: $ignoredComponents,
            reason: sprintf(
                'Total bobot snapshot %.2f, seharusnya 1.00 — nilai berasal dari '
                    .'versi konfigurasi yang berbeda. Perbaiki dengan menginput ulang '
                    .'komponen yang bobotnya sudah tidak berlaku.',
                $weightTotal,
            ),
        );
    }
}
