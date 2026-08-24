<?php

namespace App\Http\Resources\Api;

use App\Enums\NotificationTargetType;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API 4.10 — GET /admin/notifications: "semua notifikasi yang pernah dibuat di
 * cabang ini (termasuk draft)".
 *
 * Berbeda dari NotificationResource, bentuk ini memang menyebutkan status draf
 * dan ringkasan targetnya — itulah gunanya riwayat admin. Yang tetap tidak
 * ikut: data pribadi pengguna lain selain nama pengirim dan nama target.
 *
 * @mixin Notification
 */
class AdminNotificationResource extends JsonResource
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
            'target_type' => $this->target_type?->value,
            'target_label' => $this->targetLabel(),
            'status' => $this->isSent() ? 'SENT' : 'DRAFT',
            'sender_name' => $this->sender?->name,
            'created_at' => $this->created_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
        ];
    }

    /**
     * Ringkasan target dalam bahasa manusia, bukan id mentah.
     */
    protected function targetLabel(): string
    {
        return match ($this->target_type) {
            NotificationTargetType::All => $this->target_type->label(),
            NotificationTargetType::SchoolClass => 'Kelas '.($this->targetClass()?->name ?? '—'),
            NotificationTargetType::Individual => $this->targetUser()?->name ?? '—',
            default => '—',
        };
    }
}
