<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
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
            static::query()
                ->withoutGlobalScopes()
                ->where('school_id', $this->school_id)
                ->whereKeyNot($this->getKey())
                ->update(['is_active' => false]);

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
