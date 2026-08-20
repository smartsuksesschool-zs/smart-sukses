<?php

namespace App\Models;

use App\Enums\FeeFrequency;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ERD 2.2 — fee_types. Tabel ini hanya memiliki created_at.
 *
 * SPP-01 poin 2: "Jenis tagihan dapat dinonaktifkan tanpa menghapus histori."
 * Karena itu tidak ada jalur hapus — lihat FeeTypePolicy::delete().
 */
class FeeType extends Model
{
    use BelongsToSchool, HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'school_id',
        'name',
        'amount',
        'frequency',
        'academic_year_id',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'frequency' => FeeFrequency::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * NULL untuk tagihan berulang yang tidak terikat satu tahun ajaran.
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function studentFees(): HasMany
    {
        return $this->hasMany(StudentFee::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
