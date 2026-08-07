<?php

namespace App\Providers;

use App\Enums\RoleName;
use App\Models\User;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use Illuminate\Auth\Events\Login;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureAuthorization();
        $this->configurePasswordRules();
        $this->recordLastLogin();
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
     */
    protected function recordLastLogin(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });
    }
}
