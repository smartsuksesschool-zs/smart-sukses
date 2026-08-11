<?php

namespace App\Models;

use App\Enums\AssessmentType;
use App\Enums\GradeType;
use App\Models\Concerns\BelongsToSchool;
use App\Services\Grading\GradeWeightSnapshotter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * ERD 2.2 — grades. Satu baris = satu entri nilai dari satu guru.
 *
 * Keputusan Sprint 4: baris ini adalah *transaction snapshot* — `weight`
 * menyimpan bobot yang berlaku saat nilai dibuat, dan `grade_config_id`
 * mencatat konfigurasi mana yang menjadi sumbernya.
 */
class Grade extends Model
{
    use BelongsToSchool, HasFactory;

    public const MIN_SCORE = 0;

    public const MAX_SCORE = 100;

    protected $fillable = [
        'school_id',
        'student_id',
        'class_subject_id',
        'academic_year_id',
        'grade_type',
        'score',
        'weight',
        'description',
        'graded_by',
        'graded_at',
        'assessment_type',
        'grade_config_id',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'weight' => 'decimal:2',
            'grade_type' => GradeType::class,
            'assessment_type' => AssessmentType::class,
            'graded_at' => 'datetime',
        ];
    }

    /**
     * Keputusan Sprint 4 butir 2 — snapshot bobot diambil saat nilai dibuat.
     * Dipasang di model (bukan di halaman Filament) supaya input satuan, input
     * massal, dan import Excel sama-sama terlindungi.
     */
    protected static function booted(): void
    {
        static::creating(function (self $grade): void {
            $grade->graded_at ??= now();
            $grade->graded_by ??= Auth::id();

            if ($grade->weight === null && $grade->grade_type?->isAcademic()) {
                app(GradeWeightSnapshotter::class)->apply($grade);
            }
        });
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function classSubject(): BelongsTo
    {
        return $this->belongsTo(ClassSubject::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    public function gradeConfig(): BelongsTo
    {
        return $this->belongsTo(GradeConfig::class);
    }

    /**
     * Nilai yang diperhitungkan sebagai komponen rapor (keputusan Sprint 4 butir 3).
     */
    public function scopeSummative(Builder $query): Builder
    {
        return $query->where('assessment_type', AssessmentType::Summative->value);
    }

    public function scopeOfType(Builder $query, GradeType $type): Builder
    {
        return $query->where('grade_type', $type->value);
    }

    public function scopeForStudent(Builder $query, int $studentId): Builder
    {
        return $query->where('student_id', $studentId);
    }

    public function scopeForClassSubject(Builder $query, int $classSubjectId): Builder
    {
        return $query->where('class_subject_id', $classSubjectId);
    }

    /**
     * NILAI-01 poin 3 — "dapat diedit selama rapor belum diterbitkan".
     * Rapor bersifat per siswa per tahun ajaran (ERD 2.2 report_cards).
     */
    public function isLocked(): bool
    {
        return ReportCard::query()
            ->where('student_id', $this->student_id)
            ->where('academic_year_id', $this->academic_year_id)
            ->where('is_published', true)
            ->exists();
    }
}
