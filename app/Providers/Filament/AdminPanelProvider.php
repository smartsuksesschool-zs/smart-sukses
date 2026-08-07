<?php

namespace App\Providers\Filament;

use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\SetUserLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
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
            ->brandName(config('app.name'))
            // AUTH-01: login email + password (rate limit bawaan 5 percobaan/menit).
            ->login()
            // AUTH-04: reset password melalui email, link berlaku 60 menit.
            ->passwordReset()
            // AUTH-05: profil pengguna (nama, dan preferensi lain).
            ->profile(isSimple: false)
            ->authGuard('web')
            ->colors([
                // Warna default white-label (ERD 2.2 schools.primary_color).
                'primary' => Color::hex('#1B3A6B'),
                'secondary' => Color::hex('#E07020'),
            ])
            ->navigationGroups([
                NavigationGroup::make()->label('Manajemen Akses'),
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
            ])
            ->authMiddleware([
                Authenticate::class,
                SetUserLocale::class,
                EnsurePasswordIsChanged::class,
            ]);
    }
}
