<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Arsitektur 3.4 — "password pertama wajib diganti saat login pertama".
 *
 * Selama users.must_change_password bernilai true, seluruh halaman panel
 * dialihkan ke halaman profil sampai pengguna mengganti passwordnya.
 */
class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->must_change_password) {
            return $next($request);
        }

        $panel = Filament::getCurrentPanel();
        $profileUrl = $panel?->getProfileUrl();

        if ($profileUrl === null) {
            return $next($request);
        }

        $allowed = array_filter([$profileUrl, $panel?->getLogoutUrl()]);

        foreach ($allowed as $url) {
            if ($request->fullUrlIs($url) || $request->fullUrlIs($url.'/*')) {
                return $next($request);
            }
        }

        Notification::make()
            ->title('Ganti password terlebih dahulu')
            ->body('Password sementara wajib diganti sebelum menggunakan aplikasi.')
            ->warning()
            ->send();

        return redirect()->to($profileUrl);
    }
}
