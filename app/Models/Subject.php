<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ERD 2.2 — subjects. Tabel ini hanya memiliki created_at.
 */
class Subject extends Model
{
    use BelongsToSchool, HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'school_id',
        'name',
        'code',
        'credit_hours',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'credit_hours' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function classSubjects(): HasMany
    {
        return $this->hasMany(ClassSubject::class);
    }

    public function gradeConfigs(): HasMany
    {
        return $this->hasMany(GradeConfig::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
