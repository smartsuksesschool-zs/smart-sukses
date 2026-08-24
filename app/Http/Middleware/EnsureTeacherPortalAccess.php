<?php

namespace App\Http\Middleware;

use App\Enums\RoleName;
use App\Support\PortalEligibility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pagar halaman Teacher Portal.
 *
 * Berbeda dari Parent Portal, guru **tidak** memerlukan halaman masuk sendiri:
 * mereka sudah punya sesi dari halaman masuk panel, guard `web` yang sama, dan
 * memang berhak memasuki panel untuk Input Nilai. Portal ini menumpang sesi itu
 * alih-alih menjadi sistem login ketiga (butir 171).
 *
 * Aturan kelayakannya sama persis dengan portal orang tua — peran yang tepat,
 * akun aktif, punya cabang, dan kata sandi sementara sudah diganti — supaya
 * tidak ada dua aturan berbeda untuk dua portal (butir 177).
 */
class EnsureTeacherPortalAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            // Rute masuk milik panel; guru memang login di sana.
            return redirect()->route('filament.admin.auth.login');
        }

        // Syarat yang sama dengan kedua portal lain, dibaca dari satu tempat
        // (butir 180). Wali kelas ikut karena memegang seluruh akses guru.
        $refusal = PortalEligibility::refusalReasonFor(
            $user,
            [RoleName::Guru, RoleName::WaliKelas],
        );

        abort_if($refusal !== null, 403, $refusal ?? '');

        return $next($request);
    }
}
