<?php

namespace App\Services\Grading;

use App\Enums\AuditAction;
use App\Enums\GradeConfigStatus;
use App\Enums\GradeType;
use App\Models\GradeConfig;
use App\Models\Scopes\SchoolScope;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Keputusan Sprint 4 butir 4 — siklus hidup konfigurasi bobot.
 *
 *   DRAFT   : bebas diedit
 *   ACTIVE  : sudah dipakai menilai, tidak boleh diubah sembarangan
 *   LOCKED  : setelah rapor/finalisasi semester
 *
 * "Perubahan kebijakan bobot harus membuat Grade Config versi baru,
 *  bukan overwrite konfigurasi lama."
 */
class GradeConfigVersionManager
{
    /**
     * Validasi susunan komponen sebelum disimpan.
     *
     * Dua aturan: ATTITUDE tidak boleh menjadi komponen akademik (keputusan
     * butir 3), dan total bobot wajib tepat 1.00 agar formula NILAI-02
     * menghasilkan skala 0–100.
     *
     * @param  array<int, array{type?: string, weight?: mixed}>  $components
     *
     * @throws ValidationException
     */
    public function validateComponents(array $components): void
    {
        if ($components === []) {
            throw ValidationException::withMessages([
                'components' => 'Minimal satu komponen penilaian harus dikonfigurasi.',
            ]);
        }

        $seen = [];

        foreach ($components as $component) {
            $type = GradeType::tryFrom($component['type'] ?? '');

            if ($type === null) {
                throw ValidationException::withMessages([
                    'components' => 'Komponen penilaian tidak dikenal: '.($component['type'] ?? '(kosong)').'.',
                ]);
            }

            if (! $type->isAcademic()) {
                throw ValidationException::withMessages([
                    'components' => 'Sikap (ATTITUDE) tidak masuk nilai akademik dan tidak boleh dijadikan komponen berbobot.',
                ]);
            }

            if (in_array($type->value, $seen, true)) {
                throw ValidationException::withMessages([
                    'components' => "Komponen {$type->label()} tercantum lebih dari sekali.",
                ]);
            }

            $seen[] = $type->value;
        }

        $total = array_sum(array_map(fn (array $c) => (float) ($c['weight'] ?? 0), $components));

        if (abs($total - GradeConfig::TOTAL_WEIGHT) >= GradeConfig::WEIGHT_EPSILON) {
            throw ValidationException::withMessages([
                'components' => sprintf(
                    'Total bobot harus tepat 1.00 (100%%). Saat ini %.2f.',
                    $total,
                ),
            ]);
        }
    }

    /**
     * Mengaktifkan sebuah DRAFT. Konfigurasi ACTIVE sebelumnya untuk mapel &
     * tahun ajaran yang sama otomatis dikunci, sehingga hanya ada satu ACTIVE
     * pada satu waktu.
     *
     * @throws ValidationException
     */
    public function activate(GradeConfig $config): GradeConfig
    {
        if (! $config->status->canBeActivated()) {
            throw ValidationException::withMessages([
                'status' => "Konfigurasi berstatus {$config->status->label()} tidak dapat diaktifkan.",
            ]);
        }

        $this->validateComponents($config->components ?? []);

        return DB::transaction(function () use ($config): GradeConfig {
            // Mass update lewat query builder tidak memicu model event, sehingga
            // penguncian versi sebelumnya tidak akan pernah sampai ke listener
            // audit. Id-nya diambil lebih dulu lalu dicatat eksplisit (butir 46).
            // Paling banyak satu baris: unique index menjamin hanya satu ACTIVE
            // per (cabang, mapel, tahun ajaran).
            $lockedIds = GradeConfig::query()
                ->withoutGlobalScope(SchoolScope::class)
                ->where('school_id', $config->school_id)
                ->forSubjectYear($config->subject_id, $config->academic_year_id)
                ->active()
                ->whereKeyNot($config->getKey())
                ->pluck('id')
                ->all();

            if ($lockedIds !== []) {
                GradeConfig::query()
                    ->withoutGlobalScope(SchoolScope::class)
                    ->whereKey($lockedIds)
                    ->update([
                        'status' => GradeConfigStatus::Locked->value,
                        'locked_at' => now(),
                    ]);

                app(AuditLogger::class)->recordMany(
                    GradeConfig::class,
                    $lockedIds,
                    AuditAction::Updated,
                    (int) $config->school_id,
                );
            }

            $config->forceFill([
                'status' => GradeConfigStatus::Active,
                'activated_at' => now(),
            ])->save();

            return $config->refresh();
        });
    }

    /**
     * Mengunci konfigurasi ACTIVE — dipakai saat semester difinalisasi.
     *
     * @throws ValidationException
     */
    public function lock(GradeConfig $config): GradeConfig
    {
        if (! $config->status->canBeLocked()) {
            throw ValidationException::withMessages([
                'status' => "Konfigurasi berstatus {$config->status->label()} tidak dapat dikunci.",
            ]);
        }

        $config->forceFill([
            'status' => GradeConfigStatus::Locked,
            'locked_at' => now(),
        ])->save();

        return $config->refresh();
    }

    /**
     * Varian `lock()` yang aman dipanggil berulang dan tanpa tahu status
     * konfigurasi lebih dulu — dipakai oleh penguncian otomatis saat rapor
     * diterbitkan, di mana sebagian konfigurasi biasanya sudah LOCKED.
     *
     * @return bool true bila konfigurasi ini yang baru saja dikunci
     */
    public function lockIfActive(GradeConfig $config): bool
    {
        if (! $config->status->canBeLocked()) {
            return false;
        }

        $this->lock($config);

        return true;
    }

    /**
     * Membuat versi berikutnya dari konfigurasi yang sudah ACTIVE/LOCKED.
     * Versi baru selalu lahir sebagai DRAFT sehingga masih bebas disunting,
     * dan konfigurasi lama tidak tersentuh.
     */
    public function createNextVersion(GradeConfig $config, User $author): GradeConfig
    {
        return DB::transaction(function () use ($config, $author): GradeConfig {
            $nextVersion = (int) GradeConfig::query()
                ->withoutGlobalScope(SchoolScope::class)
                ->where('school_id', $config->school_id)
                ->forSubjectYear($config->subject_id, $config->academic_year_id)
                ->max('version') + 1;

            return GradeConfig::query()->create([
                'school_id' => $config->school_id,
                'subject_id' => $config->subject_id,
                'academic_year_id' => $config->academic_year_id,
                'components' => $config->components,
                'created_by' => $author->getKey(),
                'version' => $nextVersion,
                'status' => GradeConfigStatus::Draft,
            ]);
        });
    }

    /**
     * Nomor versi berikutnya untuk pasangan mapel + tahun ajaran.
     */
    public function nextVersionNumber(int $schoolId, int $subjectId, int $academicYearId): int
    {
        return (int) GradeConfig::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('school_id', $schoolId)
            ->forSubjectYear($subjectId, $academicYearId)
            ->max('version') + 1;
    }
}
