<?php

namespace App\Http\Resources\Api;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * API 4.10 — bentuk notifikasi bagi penerimanya.
 *
 * Daftar-izin. Yang sengaja tidak ikut: `school_id`, `target_id` (id kelas atau
 * pengguna lain), `wa_template` (milik NOTIF-02 dan berisi teks yang sudah
 * diisi variabel), serta `is_draft`/`user_id` pivot — penerima tidak pernah
 * melihat draf, jadi menyebutkan statusnya pun tidak ada gunanya (butir 203).
 *
 * @mixin Notification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'message' => $this->message,
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            // NULL berarti notifikasi sistem (NOTIF-03).
            'sender_name' => $this->sender?->name,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'is_read' => $this->read_at !== null,
            'read_at' => $this->read_at === null
                ? null
                : Carbon::parse($this->read_at)->toIso8601String(),
        ];
    }
}
