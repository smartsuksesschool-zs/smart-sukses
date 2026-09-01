<?php

namespace App\Livewire\Auth;

use App\Enums\RoleName;
use App\Support\LoginDestination;
use App\Support\PortalEligibility;
use Illuminate\Auth\Events\Login as LoginEvent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Satu pintu masuk untuk seluruh peran.
 *
 * Sebelumnya ada tiga: panel Filament untuk staf, `/siswa/masuk`, dan
 * `/portal/masuk`. Pengunjung harus memutuskan lebih dulu ia "jenis pengguna
 * apa" — pertanyaan yang tidak pernah perlu ditanyakan, karena jawabannya
 * sudah tersimpan di akun yang sedang ia buktikan (butir 437).
 *
 * Formulir ini karena itu **tidak punya pemilih peran**, dan yang membuatnya
 * aman bukan ketiadaan field tersebut: App\Support\LoginDestination tidak
 * pernah membaca request sama sekali. Nilai `role` yang dipalsukan ke dalam
 * payload Livewire tidak punya satu pun tempat untuk masuk.
 *
 * Kegagalan memakai satu kalimat yang sama untuk seluruh sebabnya — surel tak
 * dikenal, kata sandi salah, akun nonaktif, tanpa peran, berperan ganda,
 * maupun tidak berhak. Membedakannya berarti memberi tahu penyerang mana yang
 * benar (butir 115). Sebab sesungguhnya dicatat ke log, tanpa surel dan tanpa
 * kata sandi.
 */
class Login extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    /**
     * Satu kalimat untuk seluruh kegagalan.
     */
    public const REFUSED = 'Email atau kata sandi tidak cocok.';

    /**
     * Satu ruang nama throttle untuk seluruh peran.
     *
     * Ketiga pintu lama memakai kuncinya sendiri, sehingga lima percobaan per
     * pintu berarti lima belas percobaan per menit untuk satu surel yang sama.
     * Satu pintu menutup celah itu tanpa perlu aturan baru (butir 440).
     */
    public const MAX_ATTEMPTS = 5;

    public function mount(): void
    {
        // Sudah masuk: langsung ke tujuannya, tanpa formulir.
        $destination = LoginDestination::urlFor(Auth::user());

        if ($destination !== null) {
            $this->redirect($destination, navigate: false);
        }
    }

    public function authenticate(): void
    {
        $this->validate();

        $this->ensureIsNotRateLimited();

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            $this->fail('credentials did not match');
        }

        $user = Auth::user();

        /*
         * Kata sandi sementara ditangani terpisah dari penolakan biasa: bagi
         * portal ia bukan penolakan diam-diam melainkan petunjuk, dan
         * kalimatnya sudah ada sejak butir 158. Bagi staf ia tidak diperiksa di
         * sini sama sekali — `EnsurePasswordIsChanged` di dalam panel yang
         * mengalihkan mereka ke halaman ganti kata sandi (butir 438).
         */
        $role = LoginDestination::soleRoleOf($user);

        if ($user->must_change_password
            && in_array($role, [RoleName::Siswa, RoleName::OrangTua], true)) {
            $this->fail('temporary password must be changed', PortalEligibility::PASSWORD_CHANGE_REQUIRED);
        }

        $destination = LoginDestination::urlFor($user);

        if ($destination === null) {
            $this->fail(LoginDestination::diagnosisFor($user));
        }

        RateLimiter::clear($this->throttleKey());

        // Sesi lama dibuang seluruhnya: tanpa ini token sesi tamu terbawa ke
        // dalam sesi yang sudah terautentikasi.
        session()->regenerate();

        // `last_login_at` dan jejak audit menumpang peristiwa ini, jadi ia
        // tetap dikirim persis seperti ketiga pintu lama (AppServiceProvider).
        event(new LoginEvent('web', $user, $this->remember));

        $this->redirect($destination, navigate: false);
    }

    /**
     * Menolak, mencatat sebabnya, dan membuang sesi setengah jadi.
     *
     * @throws ValidationException
     */
    protected function fail(string $diagnosis, ?string $message = null): never
    {
        if (Auth::check()) {
            // Kredensialnya benar tetapi akunnya tidak berhak: tidak boleh ada
            // pengguna yang setengah masuk (butir 157).
            Auth::logout();
            session()->invalidate();
            session()->regenerateToken();
        }

        RateLimiter::hit($this->throttleKey());

        // Tanpa surel, tanpa kata sandi, tanpa nama.
        Log::info('login refused', ['reason' => $diagnosis, 'ip' => request()->ip()]);

        throw ValidationException::withMessages(['email' => $message ?? self::REFUSED]);
    }

    /**
     * @throws ValidationException
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => __('Terlalu banyak percobaan masuk. Coba lagi dalam :seconds detik.', [
                'seconds' => RateLimiter::availableIn($this->throttleKey()),
            ]),
        ]);
    }

    protected function throttleKey(): string
    {
        return 'login:'.mb_strtolower($this->email).'|'.request()->ip();
    }

    public function render(): View
    {
        return view('livewire.auth.login')
            ->layout('layouts.landing', [
                'title' => config('app.name').' — '.__('Masuk ke Sistem'),
            ]);
    }
}
