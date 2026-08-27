<?php

namespace App\Models;

use App\Enums\ExamQuestionType;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Satu soal pada satu ujian online. Di luar ERD 2.2.
 */
class ExamQuestion extends Model
{
    use BelongsToSchool, HasFactory;

    protected $fillable = [
        'school_id',
        'exam_id',
        'question_type',
        'question_text',
        'points',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'question_type' => ExamQuestionType::class,
            'points' => 'decimal:2',
            'position' => 'integer',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(ExamOption::class)
            ->orderBy('position')
            ->orderBy('id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ExamAnswer::class);
    }

    /**
     * Kunci jawaban soal ini.
     *
     * Relasi terpisah, dan sengaja **tidak** ikut di `options()`: memuatnya
     * hanya boleh terjadi di jalur penilaian dan jalur guru. Halaman
     * pengerjaan siswa memuat `options()` saja, sehingga tidak ada satu pun
     * jalan yang tanpa sengaja membawa kuncinya ke peramban (butir 270).
     */
    public function correctOption(): HasOne
    {
        return $this->hasOne(ExamOption::class)->where('is_correct', true);
    }

    /**
     * Apakah soal ini dapat dinilai sistem tanpa guru.
     */
    public function isAutoScored(): bool
    {
        return $this->question_type?->isAutoScored() === true;
    }
}
