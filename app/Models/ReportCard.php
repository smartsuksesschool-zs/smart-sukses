<?php

namespace App\Models;

use App\Enums\AttitudePredicate;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ERD 2.2 — report_cards. Rapor final per siswa per semester.
 *
 * Keputusan Sprint 4: rapor adalah *hasil kalkulasi* dari bobot yang tersimpan
 * pada `grades.weight` — bukan dari konfigurasi yang berlaku saat rapor dibuka.
 */
class ReportCard extends Model
{
    use BelongsToSchool, HasFactory;

    protected $fillable = [
        'school_id',
        'student_id',
        'class_id',
        'academic_year_id',
        'final_scores',
        'attitude_score',
        'attend_present',
        'attend_sick',
        'attend_permission',
        'attend_absent',
        'rank_in_class',
        'homeroom_notes',
        'is_published',
        'published_at',
        'published_by',
    ];

    protected function casts(): array
    {
        return [
            'final_scores' => 'array',
            'attitude_score' => AttitudePredicate::class,
            'attend_present' => 'integer',
            'attend_sick' => 'integer',
            'attend_permission' => 'integer',
            'attend_absent' => 'integer',
            'rank_in_class' => 'integer',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
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

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('is_published', false);
    }

    /**
     * Nilai akhir satu mata pelajaran berdasarkan kode mapel (subjects.code).
     */
    public function scoreFor(string $subjectCode): ?float
    {
        $score = ($this->final_scores ?? [])[$subjectCode] ?? null;

        return $score === null ? null : (float) $score;
    }

    /**
     * Rata-rata seluruh nilai akhir — dipakai pada tampilan & cetak rapor.
     */
    public function averageScore(): ?float
    {
        $scores = array_map('floatval', array_values($this->final_scores ?? []));

        return $scores === [] ? null : round(array_sum($scores) / count($scores), 2);
    }
}
