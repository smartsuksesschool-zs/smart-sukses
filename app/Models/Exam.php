<?php

namespace App\Models;

use App\Enums\ExamStatus;
use App\Models\Concerns\BelongsToSchool;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu ujian online untuk satu kelas-mata pelajaran.
 *
 * Di luar ERD 2.2 — CBT adalah fitur Phase 2 yang dipercepat atas permintaan
 * pemilik (docs/owner-scope-changes.md).
 *
 * Model ini hanya menyimpan dan membaca. Aturan terbit, penilaian, dan
 * pengerjaan tidak berada di sini — batch ini sengaja tidak membangun satu pun
 * di antaranya (docs/cbt-mvp-scope.md).
 */
class Exam extends Model
{
    use BelongsToSchool, HasFactory;

    protected $fillable = [
        'school_id',
        'class_subject_id',
        'academic_year_id',
        'title',
        'description',
        'duration_minutes',
        'available_from',
        'available_until',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'available_from' => 'datetime',
            'available_until' => 'datetime',
            'status' => ExamStatus::class,
        ];
    }

    public function classSubject(): BelongsTo
    {
        return $this->belongsTo(ClassSubject::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Guru yang membuat ujian ini. NULL bila akunnya sudah dihapus — ujiannya
     * sendiri tetap milik cabang, sama seperti `report_cards.published_by`.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Soal selalu berurutan. Urutannya ditetapkan di relasi, bukan diserahkan
     * ke pemanggil, supaya daftar soal tidak pernah tampil berbeda antara satu
     * halaman dan halaman lain.
     */
    public function questions(): HasMany
    {
        return $this->hasMany(ExamQuestion::class)
            ->orderBy('position')
            ->orderBy('id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ExamStatus::Published->value);
    }

    public function scopeForClassSubject(Builder $query, int $classSubjectId): Builder
    {
        return $query->where('class_subject_id', $classSubjectId);
    }

    /**
     * Ujian yang jadwalnya sedang berjalan pada satu titik waktu. Statusnya
     * diperiksa terpisah: terbit dan sedang berjalan adalah dua syarat.
     */
    public function scopeAvailableAt(Builder $query, CarbonInterface $moment): Builder
    {
        return $query
            ->where('available_from', '<=', $moment)
            ->where('available_until', '>=', $moment);
    }

    /**
     * Total bobot seluruh soal — penyebut rumus nilai.
     *
     * Memakai soal yang sudah dimuat bila ada, supaya memanggilnya di dalam
     * perulangan daftar tidak berubah menjadi satu query per ujian.
     */
    public function totalPoints(): float
    {
        return (float) ($this->relationLoaded('questions')
            ? $this->questions->sum(fn (ExamQuestion $question) => (float) $question->points)
            : $this->questions()->sum('points'));
    }

    public function isOpenAt(CarbonInterface $moment): bool
    {
        return $this->status?->isVisibleToStudents() === true
            && $this->available_from !== null
            && $this->available_until !== null
            && $this->available_from->lessThanOrEqualTo($moment)
            && $this->available_until->greaterThanOrEqualTo($moment);
    }

    /**
     * Apakah sudah ada yang mengerjakan. Menjadi syarat aturan-aturan yang
     * dibangun batch berikutnya (soal beku, tarik-kembali hanya bila kosong),
     * sehingga jawabannya ditulis sekali di sini.
     */
    public function hasAttempts(): bool
    {
        return $this->attempts()->exists();
    }
}
