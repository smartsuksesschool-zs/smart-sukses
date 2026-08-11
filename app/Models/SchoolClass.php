<?php

namespace App\Models;

use App\Enums\StudentClassStatus;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ERD 2.2 — classes (rombel).
 *
 * Nama model memakai prefix "School" karena `Class` adalah reserved word PHP;
 * nama tabel tetap `classes` sesuai ERD.
 */
class SchoolClass extends Model
{
    use BelongsToSchool, HasFactory;

    protected $table = 'classes';

    protected $fillable = [
        'school_id',
        'academic_year_id',
        'name',
        'grade_level',
        'homeroom_teacher_id',
        'room',
        'capacity',
    ];

    protected function casts(): array
    {
        return [
            'grade_level' => 'integer',
            'capacity' => 'integer',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Wali kelas — user dengan peran WALI_KELAS (ERD 2.2).
     */
    public function homeroomTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'homeroom_teacher_id');
    }

    public function studentClasses(): HasMany
    {
        return $this->hasMany(StudentClass::class, 'class_id');
    }

    public function classSubjects(): HasMany
    {
        return $this->hasMany(ClassSubject::class, 'class_id');
    }

    public function reportCards(): HasMany
    {
        return $this->hasMany(ReportCard::class, 'class_id');
    }

    public function scopeForActiveYear(Builder $query): Builder
    {
        return $query->whereHas('academicYear', fn (Builder $q) => $q->where('is_active', true));
    }

    /**
     * Jumlah siswa yang sedang aktif di kelas ini (dasar validasi kapasitas).
     */
    public function activeStudentCount(): int
    {
        return $this->studentClasses()
            ->where('status', StudentClassStatus::Active->value)
            ->count();
    }

    public function hasRemainingCapacity(): bool
    {
        return $this->activeStudentCount() < $this->capacity;
    }
}
