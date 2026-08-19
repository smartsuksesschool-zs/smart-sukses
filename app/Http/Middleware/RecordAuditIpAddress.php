<?php

namespace App\Http\Middleware;

use App\Support\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `03-architecture/04-security.md` — Audit Log: *"**Custom Middleware** + Event"*.
 *
 * Bagian Event dipenuhi listener wildcard di AppServiceProvider; middleware ini
 * bagian yang satunya. Tugasnya satu: menyerahkan IP klien ke AuditLogger.
 *
 * Batas ini yang membuat NULL bermakna. `request()->ip()` tidak dibaca langsung
 * oleh AuditLogger karena di CLI Symfony mengisi `REMOTE_ADDR` dengan
 * `127.0.0.1` sebagai bawaan — seeder dan worker antrean akan tercatat ber-IP
 * padahal tidak punya klien. Karena hanya middleware yang mengisinya, IP hanya
 * ada bila memang ada request (butir 45).
 */
class RecordAuditIpAddress
{
    public function handle(Request $request, Closure $next): Response
    {
        app(AuditLogger::class)->withIpAddress($request->ip());

        return $next($request);
    }
}
