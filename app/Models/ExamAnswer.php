<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jawaban satu siswa atas satu soal. Di luar ERD 2.2.
 *
 * `is_correct` dan `points_earned` adalah snapshot penilaian — pola yang sama
 * dengan `grades.weight` (keputusan Sprint 4 butir 2). Keduanya ditulis sekali
 * oleh penilaian di sisi server dan tidak dihitung ulang saat hasilnya dibuka,
 * sehingga kunci jawaban yang berubah kemudian tidak menggeser nilai yang sudah
 * terjadi (butir 273).
 */
class ExamAnswer extends Model
{
    use BelongsToSchool, HasFactory;

    protected $fillable = [
        'school_id',
        'exam_attempt_id',
        'exam_question_id',
        'exam_option_id',
        'answer_text',
        'is_correct',
        'points_earned',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'points_earned' => 'decimal:2',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class, 'exam_attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ExamQuestion::class, 'exam_question_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(ExamOption::class, 'exam_option_id');
    }

    /**
     * Soal yang dilewati siswa. Bukan kesalahan — bernilai nol, dan tidak
     * pernah bernilai negatif (docs/cbt-mvp-scope.md).
     */
    public function isUnanswered(): bool
    {
        return $this->exam_option_id === null && blank($this->answer_text);
    }
}
