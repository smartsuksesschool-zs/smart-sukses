<?php

namespace App\Models;

use App\Enums\StudentClassStatus;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ERD 2.2 — student_classes. Tabel ini hanya memiliki created_at.
 */
class StudentClass extends Model
{
    use BelongsToSchool, HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'school_id',
        'student_id',
        'class_id',
        'academic_year_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => StudentClassStatus::class,
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StudentClassStatus::Active->value);
    }
}
