<?php

namespace App\Http\Middleware;

use App\Enums\RoleName;
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

        abort_unless($user->is_active, 403, 'Akun Anda tidak aktif.');

        $isTeacher = $user->hasRole(RoleName::Guru->value)
            || $user->hasRole(RoleName::WaliKelas->value);

        abort_unless($isTeacher, 403);

        abort_if($user->school_id === null, 403, 'Akun Anda belum terhubung ke cabang mana pun.');

        // Arsitektur 3.4 — "password pertama wajib diganti saat login pertama".
        // Portal berada di luar panel, jadi EnsurePasswordIsChanged tidak ikut
        // berlaku di sini; tanpa baris ini portal guru akan menjadi jalan
        // memutar yang sama seperti yang ditutup untuk orang tua (butir 158).
        abort_if(
            (bool) $user->must_change_password,
            403,
            'Kata sandi sementara wajib diganti sebelum menggunakan portal.',
        );

        return $next($request);
    }
}
