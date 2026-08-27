<?php

namespace App\Models;

use App\Enums\ExamAttemptStatus;
use App\Models\Concerns\BelongsToSchool;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu pengerjaan ujian oleh satu siswa. Di luar ERD 2.2.
 *
 * Skala nilainya 0.00–100.00, sama dengan `grades.score`, sehingga jembatan
 * "Masukkan ke Nilai" nanti tidak perlu mengubah skala apa pun. Jembatan itu
 * **tidak** dibangun pada batch ini dan nilai CBT tidak pernah otomatis menjadi
 * Grade (butir 272).
 */
class ExamAttempt extends Model
{
    use BelongsToSchool, HasFactory;

    public const MIN_SCORE = 0;

    public const MAX_SCORE = 100;

    protected $fillable = [
        'school_id',
        'exam_id',
        'student_id',
        'status',
        'started_at',
        'expires_at',
        'submitted_at',
        'score',
        'grade_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExamAttemptStatus::class,
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'score' => 'decimal:2',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Baris nilai akademik yang lahir dari pengerjaan ini, bila gurunya pernah
     * memasukkannya. NULL selama belum — dan itu keadaan bawaannya.
     */
    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ExamAnswer::class);
    }

    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where('status', ExamAttemptStatus::InProgress->value);
    }

    public function scopeSubmitted(Builder $query): Builder
    {
        return $query->where('status', ExamAttemptStatus::Submitted->value);
    }

    public function scopeForStudent(Builder $query, int $studentId): Builder
    {
        return $query->where('student_id', $studentId);
    }

    public function isFinal(): bool
    {
        return $this->status?->isFinal() === true;
    }

    /**
     * Batas waktunya sudah lewat pada titik waktu ini.
     *
     * Dibaca dari `expires_at` yang ditetapkan server saat pengerjaan dimulai —
     * tidak pernah dihitung ulang dari durasi, yang akan membuat jawabannya
     * bergantung pada kapan halaman kebetulan dibuka.
     */
    public function isExpiredAt(CarbonInterface $moment): bool
    {
        return $this->expires_at !== null && $this->expires_at->lessThan($moment);
    }
}
