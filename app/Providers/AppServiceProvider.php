<?php

namespace App\Providers;

use App\Enums\AuditAction;
use App\Enums\RoleName;
use App\Models\User;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use App\Support\AuditLogger;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton karena IP-nya disetel middleware di awal request lalu dibaca
        // listener audit kapan pun terjadi write; instance baru per resolusi
        // akan membuat IP itu hilang.
        $this->app->singleton(AuditLogger::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureAuthorization();
        $this->configurePasswordRules();
        $this->recordLastLogin();
        $this->recordAuditTrail();
    }

    /**
     * Arsitektur 3.2.2 — Super Admin melewati seluruh pemeriksaan izin dan
     * global scope tenant, sehingga dapat mengakses data semua cabang.
     */
    protected function configureAuthorization(): void
    {
        Gate::before(function (User $user, string $ability) {
            return $user->hasRole(RoleName::SuperAdmin->value) ? true : null;
        });

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
    }

    /**
     * Arsitektur 3.4 — Password minimum 8 karakter.
     */
    protected function configurePasswordRules(): void
    {
        Password::defaults(fn () => Password::min(8)->letters()->numbers());
    }

    /**
     * ERD 2.2 — users.last_login_at.
     *
     * `saveQuietly()` disengaja: penandaan waktu login bukan mutasi data bisnis,
     * dan mencatatnya sebagai UPDATED akan membuat setiap login memproduksi satu
     * baris audit yang tidak menerangkan apa-apa.
     */
    protected function recordLastLogin(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });
    }

    /**
     * NFR 1.4 & Arsitektur 3.4 — jejak seluruh aksi CUD.
     *
     * Satu listener wildcard menangkap **setiap** model tanpa satu baris pun di
     * model-modelnya: tidak ada trait yang bisa lupa dipasang saat modul baru
     * ditambahkan. Aksi baca sengaja tidak didengarkan (Security 3.4 menyebut
     * CUD, bukan CRUD — butir 45).
     */
    protected function recordAuditTrail(): void
    {
        $actions = [
            'eloquent.created: *' => AuditAction::Created,
            'eloquent.updated: *' => AuditAction::Updated,
            'eloquent.deleted: *' => AuditAction::Deleted,
        ];

        foreach ($actions as $pattern => $action) {
            Event::listen($pattern, function (string $eventName, array $payload) use ($action): void {
                $model = $payload[0] ?? null;

                if ($model instanceof Model) {
                    app(AuditLogger::class)->record($model, $action);
                }
            });
        }
    }
}
