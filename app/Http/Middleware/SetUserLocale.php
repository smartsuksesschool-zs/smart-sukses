<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * AUTH-05 — Preferensi bahasa tersimpan di profil pengguna (users.locale).
 */
class SetUserLocale
{
    /** @var array<int, string> */
    protected array $supported = ['id', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale;

        if (in_array($locale, $this->supported, true)) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
