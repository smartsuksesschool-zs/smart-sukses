<?php

namespace App\Models;

use App\Enums\DayOfWeek;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ERD 2.2 — schedules. Tabel ini hanya memiliki created_at.
 */
class Schedule extends Model
{
    use BelongsToSchool, HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'school_id',
        'class_subject_id',
        'day_of_week',
        'start_time',
        'end_time',
        'room',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => DayOfWeek::class,
        ];
    }

    public function classSubject(): BelongsTo
    {
        return $this->belongsTo(ClassSubject::class);
    }

    public function scopeOnDay(Builder $query, int $day): Builder
    {
        return $query->where('day_of_week', $day);
    }

    /**
     * API 4.6 — GET /schedules filter teacher_id ("Guru: jadwal diri sendiri").
     */
    public function scopeForTeacher(Builder $query, int $teacherId): Builder
    {
        return $query->whereHas(
            'classSubject',
            fn (Builder $q) => $q->where('teacher_id', $teacherId),
        );
    }

    public function scopeForClass(Builder $query, int $classId): Builder
    {
        return $query->whereHas(
            'classSubject',
            fn (Builder $q) => $q->where('class_id', $classId),
        );
    }

    /**
     * KELAS-03 poin 2 — "Sistem mendeteksi konflik jadwal (guru/ruangan/kelas
     * yang sama di waktu bersamaan)".
     *
     * Dua jadwal beririsan bila berada pada hari yang sama dan rentang waktunya
     * tumpang tindih: existing.start < baru.end AND existing.end > baru.start.
     *
     * @return Collection<int, self>
     */
    public static function conflictsFor(
        ClassSubject $classSubject,
        int $dayOfWeek,
        string $startTime,
        string $endTime,
        ?string $room = null,
        ?int $ignoreId = null,
    ) {
        return static::query()
            ->with(['classSubject.subject', 'classSubject.schoolClass', 'classSubject.teacher'])
            ->when($ignoreId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->where('day_of_week', $dayOfWeek)
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->where(function (Builder $query) use ($classSubject, $room): void {
                $query->whereHas(
                    'classSubject',
                    fn (Builder $q) => $q
                        ->where('class_id', $classSubject->class_id)
                        ->orWhere('teacher_id', $classSubject->teacher_id),
                );

                if (filled($room)) {
                    $query->orWhere('room', $room);
                }
            })
            ->get();
    }

    /**
     * Ringkasan bentrok untuk ditampilkan sebagai pesan validasi.
     */
    public function conflictSummary(): string
    {
        $classSubject = $this->classSubject;

        return sprintf(
            '%s %s–%s (%s, %s%s)',
            $this->day_of_week->label(),
            substr((string) $this->start_time, 0, 5),
            substr((string) $this->end_time, 0, 5),
            $classSubject?->schoolClass?->name ?? '-',
            $classSubject?->teacher?->name ?? '-',
            filled($this->room) ? ', ruang '.$this->room : '',
        );
    }
}
