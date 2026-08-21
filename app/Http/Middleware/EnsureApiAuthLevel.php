<?php

namespace App\Http\Middleware;

use App\Enums\RoleName;
use App\Support\Api\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API 4.1 — Auth Level.
 *
 *  - `Auth`  : wajib token; aksesnya dibatasi ke data sekolah user tersebut
 *  - `Admin` : wajib token + role SCHOOL_ADMIN / SUPER_ADMIN
 *  - `Super` : wajib token + role SUPER_ADMIN
 *
 * Middleware ini hanya menegakkan tingkat **kasar** itu. Kewenangan sebenarnya
 * tetap ditentukan policy dan service masing-masing domain — dan pada beberapa
 * endpoint keuangan memang lebih longgar daripada label "Admin", karena user
 * story menyebut Bendahara secara eksplisit (butir 73, 98, 105). Karena itu
 * `auth_level:admin` sengaja **tidak** dipasang pada endpoint tersebut; yang
 * dipakai `auth_level:auth` ditambah policy domainnya (butir 117).
 */
class EnsureApiAuthLevel
{
    public function handle(Request $request, Closure $next, string $level = 'auth'): Response
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error('Token tidak valid atau sudah tidak berlaku.', null, 401);
        }

        // Arsitektur 3.2 — akun nonaktif tidak boleh dipakai, sama seperti di
        // panel (User::canAccessPanel). Token yang sudah terbit tidak otomatis
        // gugur saat akun dinonaktifkan, jadi diperiksa di setiap request.
        if (! $user->is_active) {
            return ApiResponse::error('Akun Anda tidak aktif.', null, 403);
        }

        $allowed = match ($level) {
            'super' => $user->isSuperAdmin(),
            'admin' => $user->isSuperAdmin() || $user->hasRole(RoleName::SchoolAdmin->value),
            default => true,
        };

        if (! $allowed) {
            return ApiResponse::error('Anda tidak berwenang melakukan tindakan ini.', null, 403);
        }

        return $next($request);
    }
}
