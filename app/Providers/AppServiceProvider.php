<?php

namespace App\Providers;

use App\Enums\AuditAction;
use App\Enums\RoleName;
use App\Models\User;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use App\Support\AuditLogger;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\DB;
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
        $this->configureTrustedProxies();
        $this->configureAuthorization();
        $this->configurePasswordRules();
        $this->recordLastLogin();
        $this->revokeCredentialsAfterPasswordReset();
        $this->recordAuditTrail();
    }

    /**
     * Arsitektur 3.3.1 — Internet → Cloudflare → Nginx → PHP-FPM.
     *
     * Disetel di sini, bukan di `bootstrap/app.php`: callback middleware pada
     * berkas itu dijalankan sebelum .env dimuat, sehingga nilainya akan selalu
     * kosong. Nilainya sendiri dibaca dari **config**, bukan langsung dari
     * `env()`, supaya tetap ada setelah `config:cache` dijalankan di produksi
     * (butir 357).
     *
     * Tanpa proxy tepercaya, `TrustProxies` tidak berbuat apa pun — itulah
     * keadaan bawaan dan keadaan pemasangan lokal.
     */
    protected function configureTrustedProxies(): void
    {
        $proxies = config('trustedproxy.proxies');

        if (filled($proxies)) {
            TrustProxies::at($proxies);
        }

        // Selalu dipersempit, bahkan ketika tidak ada proxy yang dipercaya:
        // daftar header yang lebih sempit tidak dapat menjadi lebih longgar
        // secara tidak sengaja (butir 358).
        TrustProxies::withHeaders((int) config('trustedproxy.headers'));
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
     * AUTH-04 AC-3 / CON-30 — seluruh sesi aktif di-invalidate setelah reset.
     *
     * Requirement-nya tidak ambigu dan disebut di empat tempat: CON-30
     * (`01-PRD.md:317`), AUTH-04 AC-3 (`:485`), AC-M0-10 (`:1008`), dan diagram
     * alurnya (`03-USER_FLOW.md:917`). Sebelum ini tidak ada satu pun jalur yang
     * mencabut apa pun: Filament memperbarui `remember_token` — sehingga cookie
     * "ingat saya" mati — tetapi **baris sesi** dan **token Sanctum** tetap hidup,
     * sehingga peramban yang sudah masuk di perangkat lain tetap masuk dengan
     * sandi yang sudah tidak berlaku. Itu justru keadaan yang reset sandi
     * dimaksudkan untuk mengakhiri (butir 591).
     *
     * Pengguna yang baru saja mereset **tidak** sedang masuk: halaman reset
     * Filament tidak memanggil `Auth::login`, ia mengembalikan pengunjung ke
     * halaman masuk. Karena itu menghapus seluruh baris sesi miliknya tidak
     * memutus alurnya sendiri.
     *
     * Sesi hanya dapat dicabut ketika penyimpanannya dapat dijangkau — driver
     * `database`, seperti yang diwajibkan `.env.example` dan berkas contoh
     * produksi. Pada driver lain (`file`, `cookie`) tidak ada yang dapat
     * dilakukan dari sini, dan mendiamkannya lebih baik daripada berpura-pura:
     * token Sanctum tetap dicabut.
     */
    protected function revokeCredentialsAfterPasswordReset(): void
    {
        Event::listen(PasswordReset::class, function (PasswordReset $event): void {
            if (! $event->user instanceof User) {
                return;
            }

            $event->user->tokens()->delete();

            if (config('session.driver') !== 'database') {
                return;
            }

            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $event->user->getAuthIdentifier())
                ->delete();
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
