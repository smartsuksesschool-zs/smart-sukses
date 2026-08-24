<?php

namespace App\Models;

use App\Enums\NotificationTargetType;
use App\Enums\NotificationType;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ERD 2.2 — notifications. "Pengumuman dan notifikasi yang dibuat oleh Admin
 * atau dipicu otomatis oleh sistem."
 *
 * `target_id` menyimpan `class_id` **atau** `user_id` tergantung
 * `target_type`, dan sengaja tanpa foreign key — satu kolom yang menunjuk dua
 * tabel tidak dapat dijaga FK. Karena itu tidak ada relasi Eloquent tunggal ke
 * sana; yang menafsirkannya adalah NotificationRecipientResolver (butir 194).
 */
class Notification extends Model
{
    use BelongsToSchool, HasFactory;

    protected $fillable = [
        'school_id',
        'sender_id',
        'title',
        'message',
        'type',
        'target_type',
        'target_id',
        'wa_template',
        'is_draft',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'target_type' => NotificationTargetType::class,
            'is_draft' => 'boolean',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * Pembuat notifikasi; NULL untuk notifikasi sistem (NOTIF-03).
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(NotificationRead::class);
    }

    /**
     * Notifikasi yang benar-benar sudah diterbitkan.
     *
     * Draf tidak pernah terlihat penerima, tidak ikut hitungan belum-dibaca,
     * dan tidak dapat ditandai terbaca (butir 195).
     */
    public function scopeSent(Builder $query): Builder
    {
        return $query->where('is_draft', false)->whereNotNull('sent_at');
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('is_draft', true);
    }

    public function isSent(): bool
    {
        return ! $this->is_draft && $this->sent_at !== null;
    }

    /**
     * Kelas yang menjadi target — hanya berarti bila `target_type` CLASS.
     */
    public function targetClass(): ?SchoolClass
    {
        if ($this->target_type !== NotificationTargetType::SchoolClass) {
            return null;
        }

        return SchoolClass::query()->find($this->target_id);
    }

    /**
     * Pengguna yang menjadi target — hanya berarti bila INDIVIDUAL.
     */
    public function targetUser(): ?User
    {
        if ($this->target_type !== NotificationTargetType::Individual) {
            return null;
        }

        return User::query()->find($this->target_id);
    }
}
