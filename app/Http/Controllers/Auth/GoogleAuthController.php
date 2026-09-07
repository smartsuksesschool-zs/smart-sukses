<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuthProvider;
use App\Http\Controllers\Controller;
use App\Models\Scopes\SchoolScope;
use App\Models\User;
use App\Support\GoogleIdentity;
use App\Support\GoogleOAuth;
use App\Support\LoginDestination;
use Illuminate\Auth\Events\Login as LoginEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * "Masuk dengan Google" — keputusan pemilik (M7).
 *
 * Bukan sistem autentikasi kedua. Yang berubah hanya **cara membuktikan siapa
 * pemilik akun**; sesudah terbukti, akunnya melewati `LoginDestination` yang
 * sama persis dengan akun berkata sandi, dan seluruh policy, `canAccessPanel()`,
 * scope tenant, serta middleware portal tetap berlaku sesudahnya. Tidak ada
 * satu pun aturan otorisasi yang punya cabang "kalau lewat Google" (butir 529).
 *
 * Penolakan memakai satu kalimat yang sama untuk seluruh sebabnya, persis
 * seperti halaman masuk berkata sandi. Sebab sesungguhnya masuk ke log tanpa
 * surel, tanpa token, dan tanpa pengenal siswa.
 */
class GoogleAuthController extends Controller
{
    /**
     * Satu kalimat untuk seluruh kegagalan OAuth.
     */
    public const REFUSED = 'Masuk dengan Google tidak dapat diselesaikan. Silakan coba lagi.';

    /**
     * Cakupan minimum: identitas dan surel, tidak lebih.
     *
     * Tidak ada akses kalender, kontak, atau Drive yang diminta — bukan karena
     * aplikasi ini kebetulan tidak memakainya, melainkan supaya layar
     * persetujuan Google tidak pernah meminta orang tua menyerahkan sesuatu
     * yang tidak ada hubungannya dengan masuk ke portal anaknya.
     *
     * @var array<int, string>
     */
    protected const SCOPES = ['openid', 'profile', 'email'];

    public function redirect(): SymfonyRedirect|RedirectResponse
    {
        abort_unless(GoogleOAuth::isEnabled(), 404);

        return Socialite::driver('google')->scopes(self::SCOPES)->redirect();
    }

    public function callback(): RedirectResponse
    {
        abort_unless(GoogleOAuth::isEnabled(), 404);

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            // Kode otorisasi kedaluwarsa, `state` tidak cocok, pengguna menekan
            // Batal, jaringan putus — seluruhnya satu kalimat yang sama.
            return $this->refuse('oauth exchange failed');
        }

        $subject = (string) ($googleUser->getId() ?? '');
        $email = GoogleIdentity::normalizeEmail((string) ($googleUser->getEmail() ?? ''));

        if ($subject === '' || $email === '') {
            return $this->refuse('provider identity incomplete');
        }

        /*
         * Surel yang belum diverifikasi Google ditolak.
         *
         * Tanpa syarat ini, siapa pun yang dapat membuat akun Google berdomain
         * sendiri dapat mengaku beralamat apa saja — dan alamat itulah yang
         * dibaca admin ketika memutuskan menyetujui permintaan. Nilainya
         * diambil dari klaim mentah `email_verified` pada ID token.
         */
        if (! $this->emailIsVerified($googleUser)) {
            return $this->refuse('provider email not verified');
        }

        $identity = new GoogleIdentity(
            $subject,
            $email,
            $this->displayName($googleUser),
        );

        $user = $this->userForIdentity($subject);

        if ($user !== null) {
            return $this->signIn($user);
        }

        /*
         * Belum punya akun: identitasnya dititipkan ke sesi dan pemohon dibawa
         * ke halaman pencocokan. Yang dititipkan hanya tiga nilai, tanpa satu
         * pun token (lihat GoogleIdentity).
         */
        $identity->putInSession();

        return redirect()->route('oauth.google.claim');
    }

    /**
     * Akun yang sudah tertaut ke identitas ini.
     *
     * Kuncinya `provider_subject`, bukan surel. Tanpa scope tenant: yang masuk
     * belum punya sesi, jadi belum ada cabang yang dapat disimpulkan — sama
     * dengan pencarian akun lewat surel pada halaman masuk biasa.
     */
    protected function userForIdentity(string $subject): ?User
    {
        return User::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('auth_provider', AuthProvider::Google->value)
            ->where('provider_subject', $subject)
            ->first();
    }

    /**
     * Menyelesaikan sesi untuk akun yang sudah tertaut.
     *
     * Tidak ada pertanyaan NIS/NISN yang diulang: identitasnya sudah dikenali,
     * jadi yang tersisa hanya menentukan tujuannya (§14).
     */
    protected function signIn(User $user): RedirectResponse
    {
        /*
         * Masuk **dulu**, baru tentukan tujuannya — urutan yang sama dengan
         * halaman masuk berkata sandi, dan urutannya memang menentukan.
         *
         * Tujuan peran staf adalah `Filament::getPanel('admin')->getUrl()`, dan
         * nilai itu bergantung pada siapa yang sedang login: bagi tamu ia
         * menjawab `/admin/login`, bukan dasbor. Menghitungnya sebelum
         * `Auth::login()` karena itu mengirim staf yang baru saja terbukti ke
         * halaman masuk panel — yang memantulkannya kembali, dua kali, sebelum
         * akhirnya mendarat di tempat yang benar (butir 552).
         */
        Auth::login($user);

        $destination = LoginDestination::urlFor($user);

        if ($destination === null) {
            // Akun nonaktif, tanpa peran, berperan ganda, atau tidak berhak.
            // `refuse()` membuang sesinya: tidak boleh ada pengguna yang
            // setengah masuk (butir 157).
            return $this->refuse(LoginDestination::diagnosisFor($user));
        }

        // Sesi tamu dibuang seluruhnya sebelum sesi terautentikasi dipakai.
        session()->regenerate();

        GoogleIdentity::forgetSession();

        // `last_login_at` dan jejak audit menumpang peristiwa ini, persis
        // seperti jalur kata sandi.
        event(new LoginEvent('web', $user, false));

        return redirect()->to($destination);
    }

    /**
     * Klaim `email_verified` dari ID token.
     */
    protected function emailIsVerified(object $googleUser): bool
    {
        $raw = $googleUser->user ?? null;

        if (! is_array($raw)) {
            return false;
        }

        return ($raw['email_verified'] ?? null) === true
            || ($raw['email_verified'] ?? null) === 'true';
    }

    protected function displayName(object $googleUser): ?string
    {
        $name = $googleUser->getName();

        if (! is_string($name)) {
            return null;
        }

        $name = trim($name);

        return $name === '' ? null : mb_substr($name, 0, 150);
    }

    /**
     * Menolak, mencatat sebabnya, dan tidak meninggalkan sesi setengah jadi.
     */
    protected function refuse(string $diagnosis): RedirectResponse
    {
        if (Auth::check()) {
            Auth::logout();
            session()->invalidate();
            session()->regenerateToken();
        }

        GoogleIdentity::forgetSession();

        // Tanpa surel, tanpa nama, tanpa token, tanpa NIS/NISN.
        Log::info('google sign-in refused', [
            'reason' => $diagnosis,
            'ip' => request()->ip(),
        ]);

        return redirect()->route('login')->withErrors(['email' => __(self::REFUSED)]);
    }
}
