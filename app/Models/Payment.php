<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ERD 2.2 — payments. Tabel ini hanya memiliki created_at.
 *
 * Batch 5.1 baru menyediakan model & relasinya; pencatatan pembayaran dan
 * perhitungan ulang status tagihan adalah lingkup SPP-03.
 */
class Payment extends Model
{
    use BelongsToSchool, HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'school_id',
        'student_fee_id',
        'student_id',
        'payment_method',
        'amount_paid',
        'reference_number',
        'proof_url',
        'payment_date',
        'received_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount_paid' => 'decimal:2',
            'payment_date' => 'date',
            'payment_method' => PaymentMethod::class,
        ];
    }

    public function studentFee(): BelongsTo
    {
        return $this->belongsTo(StudentFee::class);
    }

    /**
     * Denormalisasi ERD: "FK → students.id (denormalized untuk query cepat)".
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Bendahara yang mencatat pembayaran.
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
