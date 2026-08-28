<?php

namespace App\Providers\Filament;

use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\RecordAuditIpAddress;
use App\Http\Middleware\SetUserLocale;
use App\Support\SchoolBranding;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // AUTH-03 — nama, logo, dan warna mengikuti cabang pengguna. Ketiganya
            // ditutup Closure supaya dievaluasi per request, bukan sekali saat
            // panel diregistrasi: itulah yang membuat perubahan berlaku tanpa
            // deployment ulang (AUTH-03 poin 3).
            ->brandName(fn () => app(SchoolBranding::class)->brandName())
            ->brandLogo(fn () => app(SchoolBranding::class)->logoUrl())
            ->brandLogoHeight('2rem')
            // AUTH-01: login email + password (rate limit bawaan 5 percobaan/menit).
            ->login()
            // AUTH-04: reset password melalui email, link berlaku 60 menit.
            ->passwordReset()
            // AUTH-05: profil pengguna (nama, dan preferensi lain).
            ->profile(isSimple: false)
            ->authGuard('web')
            // Palet platform: dipakai tamu, Super Admin, dan cabang yang belum
            // menyetel apa pun. Nilainya sama dengan DEFAULT kolom
            // schools.primary_color / secondary_color (ERD 2.2, butir 4).
            // Warna per cabang menimpanya lewat render hook di bawah.
            ->colors([
                'primary' => Color::hex(SchoolBranding::FALLBACK_PRIMARY),
                'secondary' => Color::hex(SchoolBranding::FALLBACK_SECONDARY),
            ])
            // Arsitektur 3.2.3 — "inject CSS variables ke dalam <head>". Filament
            // menuliskan paletnya sendiri sebagai --primary-50 … --primary-950 di
            // `:root`; blok ini memakai nama variabel yang sama dan disuntikkan
            // setelahnya, sehingga menang tanpa !important. Lihat butir 41.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => app(SchoolBranding::class)->cssVariables(),
            )
            // AUTH-05 / NFR 1.4 — pemilih bahasa ID/EN pada topbar panel, di
            // sebelah menu pengguna. Panel dipakai Admin Sekolah, Kepala
            // Sekolah, Guru, Wali Kelas, dan Bendahara; tanpa tombol di sini
            // kelima peran itu tidak punya cara mengganti bahasa sama sekali
            // (butir 383).
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): View => view('filament.locale-switch'),
            )
            ->navigationGroups([
                NavigationGroup::make()->label(fn (): string => __('Manajemen Akses')),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                // Rute panel tidak melewati grup `web`, jadi middleware audit
                // dipasang di sini juga — polanya sama dengan SetUserLocale.
                RecordAuditIpAddress::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                SetUserLocale::class,
                EnsurePasswordIsChanged::class,
            ]);
    }
}
