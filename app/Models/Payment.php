<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToSchool;
use App\Services\Finance\PaymentRecorder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * ERD 2.2 — payments. Tabel ini hanya memiliki created_at.
 *
 * Barisnya ditulis satu-satunya lewat PaymentRecorder (SPP-03) dan tidak
 * pernah diubah maupun dihapus sesudahnya: ini riwayat, dan API 4.9 memang
 * tidak menyediakan PUT/DELETE /payments.
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

    /**
     * Apakah bukti pembayarannya benar-benar ada di disk privat.
     *
     * Jalur yang tercatat tanpa berkas di baliknya lebih buruk daripada tidak
     * ada bukti sama sekali: tombol unduh yang berujung 404 membuat operator
     * mengira berkasnya hilang, padahal mungkin memang tidak pernah diunggah.
     */
    public function hasDownloadableProof(): bool
    {
        return filled($this->proof_url)
            && Storage::disk(PaymentRecorder::PROOF_DISK)->exists($this->proof_url);
    }
}
