<?php

namespace App\Support;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
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
     * - PersonalAccessToken: token API adalah perkakas autentikasi, bukan data
     *   bisnis. Ia juga tidak punya `school_id`, sehingga setiap login lewat
     *   API akan menulis baris audit tak bercabang — dan setiap logout satu
     *   baris DELETED lagi. Security 3.4 meminta jejak aksi CUD atas data;
     *   waktu login sendiri sudah tersimpan di `users.last_login_at`
     *   (butir 126).
     *
     * @var array<int, class-string>
     */
    protected const IGNORED = [
        AuditLog::class,
        Role::class,
        Permission::class,
        PersonalAccessToken::class,
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
     * Mencatat aksi atas satu id pada satu kelas model.
     *
     * Dipakai jalur write yang **tidak** memicu model event dan karena itu
     * diinstrumentasi eksplisit: mass update (butir 46) serta perubahan peran
     * dan izin lewat relationship Filament (butir 47). Berbeda dari `record()`,
     * method ini tidak memeriksa daftar pengecualian — pengecualian itu milik
     * listener otomatis, sedangkan pemanggilan di sini memang disengaja.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function recordFor(string $modelClass, int|string $id, AuditAction $action, ?int $schoolId = null): AuditLog
    {
        return AuditLog::query()->create([
            'school_id' => $schoolId ?? SchoolScope::currentSchoolId(),
            'user_id' => Auth::id(),
            'action' => $action->value,
            'auditable_type' => $modelClass,
            'auditable_id' => $id,
            'ip_address' => $this->ipAddress(),
        ]);
    }

    /**
     * Bentuk jamak `recordFor()` — dipakai jalur mass update (butir 46).
     *
     * @param  class-string<Model>  $modelClass
     * @param  iterable<int|string>  $ids
     */
    public function recordMany(string $modelClass, iterable $ids, AuditAction $action, ?int $schoolId = null): void
    {
        foreach ($ids as $id) {
            $this->recordFor($modelClass, $id, $action, $schoolId);
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
