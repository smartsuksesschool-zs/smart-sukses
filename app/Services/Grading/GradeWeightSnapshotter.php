<?php

namespace App\Services\Grading;

use App\Models\ClassSubject;
use App\Models\Grade;
use App\Models\GradeConfig;

/**
 * Keputusan Sprint 4 butir 2 — "grades.weight = SNAPSHOT bobot saat grade
 * dibuat/finalized. Tujuannya agar perubahan Grade Config tidak mengubah
 * histori nilai yang sudah ada."
 *
 * Snapshot diambil dari konfigurasi berstatus ACTIVE. Bila cabang belum
 * mengaktifkan konfigurasi apa pun, nilai tetap boleh disimpan dengan
 * `weight` NULL dan snapshot menyusul saat rapor digenerate.
 */
class GradeWeightSnapshotter
{
    /**
     * Atribut snapshot untuk satu entri nilai.
     *
     * @return array{weight: float|null, grade_config_id: int|null}
     */
    public function snapshotFor(Grade $grade): array
    {
        $config = $this->configFor($grade);

        if ($config === null) {
            return ['weight' => null, 'grade_config_id' => null];
        }

        return [
            'weight' => $config->weightFor($grade->grade_type),
            'grade_config_id' => $config->getKey(),
        ];
    }

    /**
     * Menuliskan snapshot ke model (tanpa menyimpan).
     */
    public function apply(Grade $grade): Grade
    {
        return $grade->forceFill($this->snapshotFor($grade));
    }

    /**
     * Melengkapi snapshot yang masih kosong — dipanggil saat rapor digenerate
     * agar setiap nilai punya bobot pada saat kalkulasi berjalan.
     *
     * @param  iterable<Grade>  $grades
     * @return int jumlah baris yang dilengkapi
     */
    public function backfill(iterable $grades): int
    {
        $filled = 0;

        foreach ($grades as $grade) {
            if ($grade->weight !== null) {
                continue;
            }

            $snapshot = $this->snapshotFor($grade);

            if ($snapshot['weight'] === null) {
                continue;
            }

            $grade->forceFill($snapshot)->save();
            $filled++;
        }

        return $filled;
    }

    /**
     * Konfigurasi ACTIVE yang berlaku untuk mata pelajaran di balik nilai ini.
     */
    protected function configFor(Grade $grade): ?GradeConfig
    {
        $subjectId = $grade->classSubject?->subject_id
            ?? ClassSubject::query()->whereKey($grade->class_subject_id)->value('subject_id');

        if ($subjectId === null || $grade->academic_year_id === null) {
            return null;
        }

        return GradeConfig::activeFor((int) $subjectId, (int) $grade->academic_year_id);
    }
}
