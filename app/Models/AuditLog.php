<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Jejak aksi CUD — `03-architecture/04-security.md` baris "Audit Log".
 *
 * Baris audit bersifat **imutabel**: tidak punya `updated_at`, tidak disunting,
 * dan tidak dihapus dari UI mana pun. Tidak ada penghapusan otomatis pula —
 * tidak ada satu pun requirement retensi untuk audit log (yang ada hanya
 * retensi notifikasi 90 hari pada NOTIF-04, dan itu modul berbeda).
 */
class AuditLog extends Model
{
    use BelongsToSchool;

    /** Baris audit tidak pernah berubah setelah ditulis. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'school_id',
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Nama tabel yang disebut Security 3.4 ("user, action, **table**, id"),
     * diturunkan dari kelas model supaya tidak ada kolom kedua yang harus
     * dijaga tetap sinkron.
     */
    public function tableName(): string
    {
        $class = $this->auditable_type;

        if (! is_string($class) || ! class_exists($class)) {
            return (string) $class;
        }

        $model = new $class;

        return $model instanceof Model ? $model->getTable() : $class;
    }
}
