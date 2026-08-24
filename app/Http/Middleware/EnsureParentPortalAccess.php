<?php

namespace App\Http\Middleware;

use App\Enums\RoleName;
use App\Support\PortalEligibility;
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

        // Syaratnya dibaca dari PortalEligibility, sumber yang sama dengan
        // portal guru dan siswa — termasuk penanda ganti kata sandi, yang tidak
        // ikut berlaku di luar panel (butir 158, 180).
        $refusal = PortalEligibility::refusalReasonFor($user, [RoleName::OrangTua]);

        abort_if($refusal !== null, 403, $refusal ?? '');

        return $next($request);
    }
}
