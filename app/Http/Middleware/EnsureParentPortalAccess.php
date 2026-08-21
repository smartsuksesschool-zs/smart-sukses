<?php

namespace App\Http\Middleware;

use App\Enums\RoleName;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pagar halaman Parent Portal.
 *
 * Portal ini bukan panel admin, dan sebaliknya juga: admin sekolah tidak
 * menjadi orang tua siapa pun hanya karena ia berhasil login. Peran yang lain
 * karena itu ditolak 403 alih-alih diarahkan ke dashboard kosong.
 *
 * Akun nonaktif ditolak dengan alasan yang sama seperti di panel
 * (User::canAccessPanel) dan di API (EnsureApiAuthLevel): sesi yang sudah
 * terlanjur ada tidak boleh tetap berlaku setelah akunnya dimatikan.
 */
class EnsureParentPortalAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('portal.login');
        }

        abort_unless($user->is_active, 403, 'Akun Anda tidak aktif.');
        abort_unless($user->hasRole(RoleName::OrangTua->value), 403);

        // Akun School Level tanpa cabang tidak punya satu pun anak yang dapat
        // menjadi miliknya (butir 127); ditolak di sini supaya tidak sempat
        // melihat kerangka halaman yang kosong.
        abort_if($user->school_id === null, 403, 'Akun Anda belum terhubung ke cabang mana pun.');

        return $next($request);
    }
}
