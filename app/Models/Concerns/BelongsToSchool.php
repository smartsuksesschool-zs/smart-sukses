<?php

namespace App\Models\Concerns;

use App\Models\School;
use App\Models\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dipakai oleh setiap model bisnis yang memiliki kolom `school_id`.
 *
 * Trait ini memasang global scope tenant dan mengisi `school_id` otomatis
 * saat record baru dibuat oleh pengguna School Level.
 */
trait BelongsToSchool
{
    public static function bootBelongsToSchool(): void
    {
        static::addGlobalScope(new SchoolScope);

        static::creating(function ($model) {
            if ($model->school_id === null) {
                $model->school_id = SchoolScope::currentSchoolId();
            }
        });
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Lepas isolasi tenant — hanya untuk kebutuhan Super Admin / job sistem.
     */
    public function scopeAcrossSchools(Builder $query): Builder
    {
        return $query->withoutGlobalScope(SchoolScope::class);
    }

    public function scopeForSchool(Builder $query, int $schoolId): Builder
    {
        return $query->withoutGlobalScope(SchoolScope::class)
            ->where($this->qualifyColumn('school_id'), $schoolId);
    }
}
