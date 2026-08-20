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
 * `amount_paid` dan `status` bukan isian: keduanya turunan dari baris-baris
 * `payments` dan hanya ditulis PaymentRecorder (SPP-03). Pembebasan tagihan
 * (WAIVED) belum punya jalur sendiri.
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

    public function scopeForStudent(Builder $query, int $studentId): Builder
    {
        return $query->where('student_id', $studentId);
    }

    /**
     * Sisa tagihan sebagai string desimal, tidak pernah negatif.
     *
     * Turunan dari dua kolom yang sudah ada — sengaja bukan kolom ketiga yang
     * bisa menyimpang dari keduanya. Perhitungannya memakai bcmath: nominal
     * `DECIMAL(12,2)` dibaca Eloquent sebagai string dan tidak boleh melewati
     * float (docs/implementation-notes.md butir 58).
     */
    public function remaining(): string
    {
        $remaining = bcsub((string) $this->amount, (string) $this->amount_paid, 2);

        return bccomp($remaining, '0', 2) < 0 ? '0.00' : $remaining;
    }

    public function isWaived(): bool
    {
        return $this->status === StudentFeeStatus::Waived;
    }

    /**
     * Muatan yang dibutuhkan tampilan detail tagihan: siswa, jenis tagihannya,
     * dan seluruh riwayat pembayaran beserta pencatatnya.
     *
     * Disediakan di model, bukan di satu Resource Filament, karena konsumen
     * berikutnya adalah portal orang tua (SPP-04) yang membutuhkan bentuk data
     * yang sama persis. Tidak ada aturan bisnis di sini — hanya eager load.
     */
    public function scopeWithBillingDetail(Builder $query): Builder
    {
        return $query->with(['student', 'feeType', 'payments.receivedBy']);
    }
}
