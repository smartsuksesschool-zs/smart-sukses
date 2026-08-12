<?php

namespace App\Models;

use App\Enums\GradeConfigStatus;
use App\Enums\GradeType;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ERD 2.2 — grade_configs. Master rule bobot komponen penilaian.
 *
 * Keputusan Sprint 4: konfigurasi ini adalah *master rule*; bobot yang benar-benar
 * dipakai menghitung rapor adalah snapshot pada `grades.weight`, sehingga
 * perubahan konfigurasi tidak pernah mengubah histori nilai yang sudah ada.
 */
class GradeConfig extends Model
{
    use BelongsToSchool, HasFactory;

    /**
     * Total bobot seluruh komponen wajib tepat 1.00 (NILAI-02 poin 2).
     */
    public const TOTAL_WEIGHT = 1.0;

    /**
     * Toleransi pembanding float saat memvalidasi total bobot.
     */
    public const WEIGHT_EPSILON = 0.0001;

    protected $fillable = [
        'school_id',
        'subject_id',
        'academic_year_id',
        'components',
        'created_by',
        'version',
        'status',
        'activated_at',
        'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'components' => 'array',
            'version' => 'integer',
            'status' => GradeConfigStatus::class,
            'activated_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', GradeConfigStatus::Active->value);
    }

    public function scopeForSubjectYear(Builder $query, int $subjectId, int $academicYearId): Builder
    {
        return $query->where('subject_id', $subjectId)
            ->where('academic_year_id', $academicYearId);
    }

    /**
     * Konfigurasi ACTIVE untuk satu mapel pada satu tahun ajaran — sumber
     * snapshot bobot bagi nilai baru. NULL bila belum ada yang diaktifkan.
     */
    public static function activeFor(int $subjectId, int $academicYearId): ?self
    {
        return static::query()
            ->forSubjectYear($subjectId, $academicYearId)
            ->active()
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Konfigurasi LOCKED terakhir untuk satu mapel pada satu tahun ajaran.
     *
     * Dipakai untuk membedakan dua keadaan yang sama-sama membuat
     * `activeFor()` mengembalikan NULL: mapel yang memang belum pernah
     * dikonfigurasi, dan mapel yang konfigurasinya sudah dikunci setelah rapor
     * terbit. Yang kedua perlu versi baru; yang pertama tidak.
     */
    public static function lockedFor(int $subjectId, int $academicYearId): ?self
    {
        return static::query()
            ->forSubjectYear($subjectId, $academicYearId)
            ->where('status', GradeConfigStatus::Locked->value)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Bobot komponen tertentu, atau NULL bila tipe itu tidak dikonfigurasi.
     */
    public function weightFor(GradeType $type): ?float
    {
        foreach ($this->components ?? [] as $component) {
            if (($component['type'] ?? null) === $type->value) {
                return (float) $component['weight'];
            }
        }

        return null;
    }

    /**
     * Tipe komponen yang diperhitungkan konfigurasi ini.
     *
     * @return array<int, GradeType>
     */
    public function componentTypes(): array
    {
        return array_values(array_filter(array_map(
            fn (array $component) => GradeType::tryFrom($component['type'] ?? ''),
            $this->components ?? [],
        )));
    }

    public function totalWeight(): float
    {
        return array_sum(array_map(
            fn (array $component) => (float) ($component['weight'] ?? 0),
            $this->components ?? [],
        ));
    }

    public function hasBalancedWeights(): bool
    {
        return abs($this->totalWeight() - self::TOTAL_WEIGHT) < self::WEIGHT_EPSILON;
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function label(): string
    {
        return trim(($this->subject?->code ?? '?').' v'.$this->version);
    }
}
