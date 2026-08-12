<?php

namespace App\Services\Grading;

use App\Enums\AttitudePredicate;
use App\Models\School;
use Illuminate\Validation\ValidationException;

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
     * terurut menurut PERINGKAT predikat (A → B → C → D).
     *
     * Urutan sengaja diambil dari urutan deklarasi case, bukan dari angka
     * batas yang diketik Admin. Mengurutkan berdasarkan angka (dulu memakai
     * `arsort()`) membuat konfigurasi keliru seperti A=50, B=80 tersusun
     * menjadi B lalu A — skor 85 diam-diam menghasilkan B dan skor 60
     * menghasilkan A. Predikat terbalik tanpa satu pun pesan kesalahan.
     *
     * Konfigurasi yang tidak utuh atau tidak menurun tegas karena itu
     * diabaikan, dan rentang default yang dipakai. Penolakan yang sebenarnya
     * terjadi di `validateScale()` saat Admin menyimpan; kelonggaran di sini
     * hanya jaring pengaman untuk data yang sudah terlanjur tersimpan.
     *
     * @return array<string, float>
     */
    public function scaleFor(?School $school): array
    {
        $scale = $this->normalise($school?->attitude_scale);

        if (! $this->isUsable($scale)) {
            return AttitudePredicate::defaultScale();
        }

        return $scale;
    }

    /**
     * Menyaring skala tersimpan menjadi batas numerik per predikat, selalu
     * dalam urutan peringkat A → B → C → D.
     *
     * @return array<string, float>
     */
    protected function normalise(mixed $scale): array
    {
        if (! is_array($scale)) {
            return [];
        }

        $normalised = [];

        foreach (AttitudePredicate::cases() as $predicate) {
            $minimum = $scale[$predicate->value] ?? null;

            if (is_numeric($minimum)) {
                $normalised[$predicate->value] = (float) $minimum;
            }
        }

        return $normalised;
    }

    /**
     * Skala dianggap layak pakai bila seluruh predikat punya batas dan
     * batas-batas itu menurun tegas mengikuti peringkat.
     *
     * @param  array<string, float>  $scale
     */
    protected function isUsable(array $scale): bool
    {
        if (count($scale) !== count(AttitudePredicate::cases())) {
            return false;
        }

        $previous = null;

        foreach ($scale as $minimum) {
            if ($previous !== null && $minimum >= $previous) {
                return false;
            }

            $previous = $minimum;
        }

        return true;
    }

    /**
     * Memvalidasi skala yang hendak disimpan Admin.
     *
     * Tiga aturan: setiap predikat punya batas numerik, batas berada pada
     * 0–100, dan batasnya menurun tegas A > B > C > D. Aturan ketiga yang
     * paling penting — tanpa itu predikat dapat terbalik diam-diam.
     *
     * Rentangnya sendiri tetap sepenuhnya milik Admin; yang ditegakkan hanya
     * koherensinya, bukan angka tertentu.
     *
     * @param  array<string, mixed>  $scale  batas bawah per predikat
     *
     * @throws ValidationException
     */
    public function validateScale(array $scale): void
    {
        $previousPredicate = null;
        $previousMinimum = null;

        foreach (AttitudePredicate::cases() as $predicate) {
            $minimum = $scale[$predicate->value] ?? null;

            if (! is_numeric($minimum)) {
                throw ValidationException::withMessages([
                    'attitude_scale' => "Batas bawah predikat {$predicate->value} harus diisi angka.",
                ]);
            }

            $minimum = (float) $minimum;

            if ($minimum < 0 || $minimum > 100) {
                throw ValidationException::withMessages([
                    'attitude_scale' => "Batas bawah predikat {$predicate->value} harus berada pada rentang 0–100.",
                ]);
            }

            if ($previousMinimum !== null && $minimum >= $previousMinimum) {
                throw ValidationException::withMessages([
                    'attitude_scale' => sprintf(
                        'Batas bawah harus menurun dari A ke D. Saat ini %s = %g tidak lebih kecil dari %s = %g.',
                        $predicate->value,
                        $minimum,
                        $previousPredicate,
                        $previousMinimum,
                    ),
                ]);
            }

            $previousPredicate = $predicate->value;
            $previousMinimum = $minimum;
        }
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
