<?php

namespace App\Models;

use App\Enums\TransactionType;
use App\Models\Concerns\BelongsToSchool;
use App\Services\Finance\TransactionRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * ERD 2.2 — transactions. "Buku kas sekolah. Mencatat semua pemasukan dan
 * pengeluaran umum **di luar tagihan SPP**."
 *
 * Kalimat terakhir itu menentukan bentuknya: tidak ada relasi ke `payments`
 * maupun `student_fees`. ERD tidak memuat kolom penghubungnya, dan blueprint
 * tidak menjelaskan bagaimana penerimaan SPP direkonsiliasi ke buku kas —
 * membuat kaitannya sendiri berarti mengarang aturan akuntansi (butir 75).
 *
 * Tabel ini hanya memiliki `created_at`; riwayat perubahannya ada di
 * `audit_logs`.
 */
class Transaction extends Model
{
    use BelongsToSchool, HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'school_id',
        'type',
        'category',
        'amount',
        'description',
        'reference_number',
        'proof_url',
        'transaction_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'transaction_date' => 'date',
            'type' => TransactionType::class,
        ];
    }

    /**
     * Pengguna yang mencatat transaksi ini. ERD: "FK → users.id".
     *
     * Tetap pembuat pertamanya walaupun transaksinya kemudian diedit — siapa
     * yang mengubah tercatat di `audit_logs`, bukan dengan menimpa kolom ini.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOfType(Builder $query, TransactionType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    public function scopeBetweenDates(Builder $query, ?string $from, ?string $until): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->whereDate('transaction_date', '>=', $from))
            ->when($until, fn (Builder $q) => $q->whereDate('transaction_date', '<=', $until));
    }

    public function isIncome(): bool
    {
        return $this->type === TransactionType::Income;
    }

    /**
     * Apakah bukti transaksinya benar-benar ada di disk privat.
     *
     * Jalur yang tercatat tanpa berkas di baliknya lebih buruk daripada tidak
     * ada bukti sama sekali: tombol unduh yang berujung 404 membuat operator
     * mengira berkasnya hilang, padahal mungkin memang tidak pernah diunggah.
     */
    public function hasDownloadableProof(): bool
    {
        return filled($this->proof_url)
            && Storage::disk(TransactionRecorder::PROOF_DISK)->exists($this->proof_url);
    }
}
