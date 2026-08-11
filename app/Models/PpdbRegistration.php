<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\PpdbStatus;
use App\Models\Concerns\BelongsToSchool;
use App\Support\PpdbWaTemplate;
use App\Support\WhatsAppLink;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ERD 2.2 (PPDB) — ppdb_registrations.
 *
 * Data pendaftar PPDB. Dapat diisi tanpa login (public form); setelah diterima,
 * data ini menjadi sumber untuk membuat record students (PPDB-05).
 */
class PpdbRegistration extends Model
{
    use BelongsToSchool, HasFactory;

    /**
     * Panjang bagian [SEQ] pada nomor pendaftaran.
     */
    public const SEQ_LENGTH = 4;

    /**
     * Panjang maksimum [KODE_CABANG] di dalam reg_number.
     *
     * ERD memberi reg_number VARCHAR(20) sekaligus schools.code VARCHAR(20),
     * sehingga kode cabang terpanjang tidak muat bersama "-[TAHUN]-[SEQ]"
     * (10 karakter). Kode dipotong sampai batas ini.
     */
    public const CODE_LENGTH = 20 - 1 - 4 - 1 - self::SEQ_LENGTH;

    protected $fillable = [
        'school_id',
        'academic_year_id',
        'reg_number',
        'full_name',
        'gender',
        'birth_date',
        'origin_school',
        'parent_name',
        'parent_phone',
        'parent_email',
        'documents',
        'status',
        'status_notes',
        'converted_student_id',
        'registered_at',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'documents' => 'array',
            'gender' => Gender::class,
            'status' => PpdbStatus::class,
            'registered_at' => 'datetime',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Siswa hasil enroll (PPDB-05).
     */
    public function convertedStudent(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'converted_student_id');
    }

    public function scopeStatus(Builder $query, PpdbStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /**
     * PPDB-05 — hanya pendaftar berstatus LULUS yang dapat di-enroll.
     */
    public function isEnrollable(): bool
    {
        return $this->status === PpdbStatus::Passed
            && $this->converted_student_id === null;
    }

    /**
     * PPDB-04 — teks notifikasi siap kirim untuk status saat ini.
     */
    public function waMessage(): string
    {
        return PpdbWaTemplate::render($this);
    }

    /**
     * PPDB-04 poin 2 / NOTIF-02 poin 1 — wa.me/62[nomorHP]?text=[pesan_ter-encode].
     */
    public function waLink(?string $message = null): ?string
    {
        return WhatsAppLink::to($this->parent_phone, $message ?? $this->waMessage());
    }

    /**
     * PPDB-01 — submit formulir publik. Mengembalikan pendaftaran yang tersimpan
     * beserta nomor pendaftaran uniknya.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function registerPublicly(School $school, array $attributes): self
    {
        $registeredAt = now();

        // Tabrakan nomor hanya mungkin terjadi bila dua pendaftar submit pada
        // saat yang sama; unique index menolak yang kalah dan nomor dihitung ulang.
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn () => static::query()->create([
                    ...$attributes,
                    'school_id' => $school->id,
                    'reg_number' => static::generateRegNumber($school, (int) $registeredAt->format('Y')),
                    'status' => PpdbStatus::Registered,
                    'registered_at' => $registeredAt,
                ]));
            } catch (QueryException $exception) {
                if ($attempt >= 5 || ! str_contains(strtolower($exception->getMessage()), 'unique')) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * ERD 2.2 — "Nomor pendaftaran unik: [KODE_CABANG]-[TAHUN]-[SEQ]".
     */
    public static function generateRegNumber(School $school, ?int $year = null): string
    {
        $prefix = static::regNumberPrefix($school, $year ?? (int) now()->format('Y'));

        $last = static::query()
            ->withoutGlobalScopes()
            ->where('reg_number', 'like', $prefix.'%')
            ->orderByDesc('reg_number')
            ->value('reg_number');

        $next = $last === null ? 1 : ((int) Str::afterLast($last, '-')) + 1;

        return $prefix.str_pad((string) $next, self::SEQ_LENGTH, '0', STR_PAD_LEFT);
    }

    protected static function regNumberPrefix(School $school, int $year): string
    {
        $code = Str::upper(Str::substr($school->code, 0, self::CODE_LENGTH));

        return "{$code}-{$year}-";
    }
}
