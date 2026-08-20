<?php

namespace App\Models;

use App\Enums\StudentFeeStatus;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ERD 2.2 — student_fees. Tagihan per siswa per periode.
 *
 * Batch 5.1 baru menyediakan model & relasinya; alur generate massal (SPP-02),
 * pencatatan pembayaran (SPP-03), dan pembebasan (WAIVED) belum ada.
 */
class StudentFee extends Model
{
    use BelongsToSchool, HasFactory;

    protected $fillable = [
        'school_id',
        'student_id',
        'fee_type_id',
        'academic_year_id',
        'amount',
        'amount_paid',
        'due_date',
        'period',
        'status',
        'waive_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'due_date' => 'date',
            'status' => StudentFeeStatus::class,
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * "Satu tagihan dapat memiliki beberapa pembayaran (cicilan)."
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function scopeForPeriod(Builder $query, string $period): Builder
    {
        return $query->where('period', $period);
    }
}
