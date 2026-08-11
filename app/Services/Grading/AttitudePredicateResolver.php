<?php

namespace App\Services\Grading;

use App\Enums\AttitudePredicate;
use App\Models\School;

/**
 * Keputusan Sprint 4 butir 3 — nilai sikap TIDAK masuk nilai akademik dan
 * dilaporkan terpisah sebagai predikat:
 *
 *   86–100 = A · 76–85 = B · 66–75 = C · 0–65 = D
 *
 * "Range attitude JANGAN hard-code. Simpan sebagai configuration agar dapat
 * diubah Admin." Rentang karena itu dibaca dari `schools.attitude_scale`;
 * nilai di App\Enums\AttitudePredicate::defaultScale() hanya dipakai bila
 * cabang belum menyetel apa pun.
 */
class AttitudePredicateResolver
{
    /**
     * Rentang yang berlaku pada satu cabang: batas bawah per predikat,
     * terurut dari yang tertinggi.
     *
     * @return array<string, float>
     */
    public function scaleFor(?School $school): array
    {
        $scale = $school?->attitude_scale;

        if (! is_array($scale) || $scale === []) {
            return AttitudePredicate::defaultScale();
        }

        $normalised = [];

        foreach (AttitudePredicate::cases() as $predicate) {
            if (isset($scale[$predicate->value]) && is_numeric($scale[$predicate->value])) {
                $normalised[$predicate->value] = (float) $scale[$predicate->value];
            }
        }

        if ($normalised === []) {
            return AttitudePredicate::defaultScale();
        }

        arsort($normalised);

        return $normalised;
    }

    /**
     * Mengubah skor sikap 0–100 menjadi predikat sesuai rentang cabang.
     */
    public function resolve(?float $score, ?School $school = null): ?AttitudePredicate
    {
        if ($score === null) {
            return null;
        }

        foreach ($this->scaleFor($school) as $predicate => $minimum) {
            if ($score >= $minimum) {
                return AttitudePredicate::from($predicate);
            }
        }

        // Skor di bawah seluruh batas — jatuh ke predikat terendah.
        return AttitudePredicate::D;
    }

    /**
     * Deskripsi rentang untuk ditampilkan di UI, contoh "86 – 100".
     *
     * @return array<string, string>
     */
    public function describe(?School $school): array
    {
        $scale = $this->scaleFor($school);
        $bounds = array_values($scale);
        $described = [];
        $index = 0;

        foreach ($scale as $predicate => $minimum) {
            $upper = $index === 0 ? 100.0 : $bounds[$index - 1] - 1;
            $described[$predicate] = sprintf('%g – %g', $minimum, $upper);
            $index++;
        }

        return $described;
    }
}
