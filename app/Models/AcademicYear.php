<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Models\Concerns\BelongsToSchool;
use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * ERD 2.2 — academic_years.
 */
class AcademicYear extends Model
{
    use BelongsToSchool, HasFactory;

    protected $fillable = [
        'school_id',
        'name',
        'start_date',
        'end_date',
        'semester',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'semester' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

    public function classSubjects(): HasMany
    {
        return $this->hasMany(ClassSubject::class);
    }

    public function studentClasses(): HasMany
    {
        return $this->hasMany(StudentClass::class);
    }

    public function gradeConfigs(): HasMany
    {
        return $this->hasMany(GradeConfig::class);
    }

    public function reportCards(): HasMany
    {
        return $this->hasMany(ReportCard::class);
    }

    /**
     * Jenis tagihan yang terikat tahun ajaran ini (fee_types.academic_year_id
     * NULL berarti tagihan berulang dan tidak muncul di sini).
     */
    public function feeTypes(): HasMany
    {
        return $this->hasMany(FeeType::class);
    }

    public function studentFees(): HasMany
    {
        return $this->hasMany(StudentFee::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * API 4.6 — PATCH /academic-years/{id}/activate:
     * "Set tahun ajaran sebagai aktif (menonaktifkan yang lain)".
     *
     * ERD: "Hanya satu tahun ajaran per sekolah boleh aktif."
     */
    public function activate(): void
    {
        DB::transaction(function (): void {
            $deactivated = static::query()
                ->withoutGlobalScopes()
                ->where('school_id', $this->school_id)
                ->whereKeyNot($this->getKey())
                ->where('is_active', true);

            // Mass update lewat query builder tidak memicu model event, jadi
            // penonaktifan tahun ajaran lain tidak akan pernah sampai ke
            // listener audit. Id-nya diambil lebih dulu lalu dicatat eksplisit
            // (butir 46). Satu query tambahan, dan paling banyak satu baris —
            // ERD menjamin hanya satu tahun ajaran aktif per cabang.
            $ids = $deactivated->pluck('id')->all();

            if ($ids !== []) {
                static::query()
                    ->withoutGlobalScopes()
                    ->whereKey($ids)
                    ->update(['is_active' => false]);

                app(AuditLogger::class)->recordMany(
                    static::class,
                    $ids,
                    AuditAction::Updated,
                    (int) $this->school_id,
                );
            }

            $this->forceFill(['is_active' => true])->save();
        });
    }

    /**
     * Tahun ajaran aktif milik tenant yang sedang berjalan.
     */
    public static function current(): ?self
    {
        return static::query()->active()->first();
    }
}
