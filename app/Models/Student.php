<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\StudentClassStatus;
use App\Enums\StudentStatus;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * ERD 2.2 — students. Data induk siswa.
 */
class Student extends Model
{
    use BelongsToSchool, HasFactory;

    protected $fillable = [
        'school_id',
        'user_id',
        'nis',
        'nisn',
        'full_name',
        'gender',
        'birth_place',
        'birth_date',
        'religion',
        'address',
        'photo_url',
        'parent_name',
        'parent_phone',
        'parent_email',
        'parent_user_id',
        'entry_year',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'entry_year' => 'integer',
            'gender' => Gender::class,
            'status' => StudentStatus::class,
        ];
    }

    /**
     * Akun portal siswa (opsional).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Akun portal orang tua (opsional).
     */
    public function parentUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }

    public function studentClasses(): HasMany
    {
        return $this->hasMany(StudentClass::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    public function reportCards(): HasMany
    {
        return $this->hasMany(ReportCard::class);
    }

    /**
     * Penempatan kelas yang sedang berlaku pada tahun ajaran aktif.
     */
    public function activeStudentClass(): HasOne
    {
        return $this->hasOne(StudentClass::class)
            ->where('status', StudentClassStatus::Active->value)
            ->latestOfMany();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StudentStatus::Active->value);
    }

    /**
     * KELAS-02 poin 1 — "Siswa dipilih dari daftar siswa aktif yang belum
     * terdaftar di kelas manapun untuk tahun ajaran tersebut."
     */
    public function scopeEligibleForYear(Builder $query, int $academicYearId): Builder
    {
        return $query
            ->active()
            ->whereDoesntHave('studentClasses', fn (Builder $q) => $q
                ->where('academic_year_id', $academicYearId)
                ->where('status', StudentClassStatus::Active->value));
    }

    /**
     * SIS-04 / API 4.5 — daftar siswa pada satu kelas.
     */
    public function scopeInClass(Builder $query, int $classId): Builder
    {
        return $query->whereHas(
            'studentClasses',
            fn (Builder $q) => $q->where('class_id', $classId)
                ->where('status', StudentClassStatus::Active->value),
        );
    }

    /**
     * Varian scopeInClass untuk beberapa kelas sekaligus — dipakai saat menilai
     * apakah satu kebijakan penilaian sudah selesai dipakai di seluruh kelas
     * yang mengampu mata pelajarannya.
     *
     * @param  array<int, int>  $classIds
     */
    public function scopeInAnyClass(Builder $query, array $classIds): Builder
    {
        return $query->whereHas(
            'studentClasses',
            fn (Builder $q) => $q->whereIn('class_id', $classIds)
                ->where('status', StudentClassStatus::Active->value),
        );
    }
}
