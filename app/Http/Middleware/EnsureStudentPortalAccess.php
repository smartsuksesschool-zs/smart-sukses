<?php

namespace App\Http\Middleware;

use App\Enums\RoleName;
use App\Support\PortalEligibility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pagar halaman Student Portal.
 *
 * Siswa ditolak seluruh panel (`canAccessPanel`), sama seperti orang tua, jadi
 * portal ini punya halaman masuknya sendiri. Syarat kelayakannya dibaca dari
 * PortalEligibility — sumber yang sama dengan portal orang tua dan guru, supaya
 * ketiganya tidak mulai berbeda tanpa alasan (butir 180).
 */
class EnsureStudentPortalAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('student.login');
        }

        $refusal = PortalEligibility::refusalReasonFor($user, [RoleName::Siswa]);

        abort_if($refusal !== null, 403, $refusal ?? '');

        return $next($request);
    }
}
