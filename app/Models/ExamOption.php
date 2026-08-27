<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu pilihan jawaban. Di luar ERD 2.2.
 *
 * `is_correct` adalah kunci jawaban dan tidak boleh pernah sampai ke peramban
 * siswa sebelum pengerjaannya final (butir 270).
 */
class ExamOption extends Model
{
    use BelongsToSchool, HasFactory;

    protected $fillable = [
        'school_id',
        'exam_question_id',
        'option_text',
        'is_correct',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ExamQuestion::class, 'exam_question_id');
    }

    public function scopeCorrect(Builder $query): Builder
    {
        return $query->where('is_correct', true);
    }
}
