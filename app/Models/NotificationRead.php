<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ERD 2.2 — notification_reads. "Tabel pivot yang merecord siapa saja yang
 * sudah membaca notifikasi tertentu."
 *
 * Tidak punya `school_id`, dan karena itu **tidak** memakai SchoolScope: pivot
 * ini tidak dapat menjawab pertanyaan cabang sendirian. Setiap pembacaannya
 * harus disandarkan pada `notifications` yang sudah terotorisasi lebih dulu
 * (butir 193).
 *
 * Tidak punya `created_at`/`updated_at` pula — `read_at` adalah satu-satunya
 * waktu yang berarti.
 */
class NotificationRead extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'notification_id',
        'user_id',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
