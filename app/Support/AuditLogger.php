<?php

namespace App\Support;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Satu-satunya tempat baris audit ditulis.
 *
 * `03-architecture/04-security.md` — Audit Log: *"Custom Middleware + Event |
 * Semua aksi CUD (Create, Update, Delete) dicatat: user, action, table, id,
 * timestamp, IP"*.
 *
 * Bagian **Event**-nya dipenuhi satu listener wildcard di AppServiceProvider,
 * sehingga tidak ada model yang perlu dipasangi trait dan tidak ada yang bisa
 * lupa dipasangi. Bagian **Middleware**-nya tidak diperlukan: IP request sudah
 * tersedia dari container, jadi menambah middleware hanya untuk mengambilnya
 * akan menjadi lapisan tanpa guna (butir 45).
 */
class AuditLogger
{
    /**
     * IP klien request yang sedang berjalan, disetel
     * App\Http\Middleware\RecordAuditIpAddress.
     *
     * Sengaja tidak membaca `request()->ip()` langsung: di CLI Symfony mengisi
     * `REMOTE_ADDR` dengan `127.0.0.1` sebagai bawaan, sehingga seeder, perintah
     * artisan, dan worker antrean akan tercatat ber-IP padahal tidak punya klien
     * sama sekali. Nilai palsu lebih buruk daripada NULL pada jejak audit.
     */
    protected ?string $ipAddress = null;

    public function withIpAddress(?string $ipAddress): void
    {
        $this->ipAddress = $ipAddress;
    }

    /**
     * Model yang tidak diaudit.
     *
     * - AuditLog: menulis baris audit akan memicu event `created` miliknya
     *   sendiri, dan itu rekursi tak terbatas.
     * - Role & Permission: definisi peran bersifat platform-wide dan hanya
     *   berubah lewat seeder (butir 2). Pivot `model_has_roles` pun tidak
     *   memicu model event sama sekali — lihat batasan di butir 45.
     *
     * @var array<int, class-string>
     */
    protected const IGNORED = [
        AuditLog::class,
        Role::class,
        Permission::class,
    ];

    public function shouldAudit(Model $model): bool
    {
        foreach (self::IGNORED as $ignored) {
            if ($model instanceof $ignored) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mencatat satu aksi atas satu model.
     */
    public function record(Model $model, AuditAction $action): ?AuditLog
    {
        if (! $this->shouldAudit($model)) {
            return null;
        }

        return AuditLog::query()->create([
            'school_id' => $this->schoolIdFor($model),
            'user_id' => Auth::id(),
            'action' => $action->value,
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'ip_address' => $this->ipAddress(),
        ]);
    }

    /**
     * Mencatat aksi atas sekumpulan id pada satu kelas model — dipakai jalur
     * mass update yang tidak memicu model event (lihat butir 46).
     *
     * @param  class-string<Model>  $modelClass
     * @param  iterable<int|string>  $ids
     */
    public function recordMany(string $modelClass, iterable $ids, AuditAction $action, ?int $schoolId = null): void
    {
        foreach ($ids as $id) {
            AuditLog::query()->create([
                'school_id' => $schoolId ?? SchoolScope::currentSchoolId(),
                'user_id' => Auth::id(),
                'action' => $action->value,
                'auditable_type' => $modelClass,
                'auditable_id' => $id,
                'ip_address' => $this->ipAddress(),
            ]);
        }
    }

    /**
     * Cabang tempat aksi terjadi.
     *
     * Diambil dari recordnya sendiri bila punya `school_id` — itulah kebenaran
     * yang paling dekat dengan data. Bila tidak (mis. tabel `schools` sendiri),
     * jatuh ke konteks tenant pengguna, yang NULL bagi Super Admin: aksi
     * platform memang tidak berada di dalam cabang mana pun.
     */
    protected function schoolIdFor(Model $model): ?int
    {
        $schoolId = $model->getAttribute('school_id');

        if ($schoolId !== null) {
            return (int) $schoolId;
        }

        return SchoolScope::currentSchoolId();
    }

    /**
     * IP request, atau NULL di luar konteks HTTP.
     *
     * Hanya middleware yang mengisinya, sehingga NULL berarti benar-benar tidak
     * ada request — bukan sekadar tidak terbaca.
     */
    protected function ipAddress(): ?string
    {
        return $this->ipAddress;
    }
}
