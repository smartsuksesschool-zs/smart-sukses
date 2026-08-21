<?php

namespace App\Livewire\Portal;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Pintu masuk Parent Portal.
 *
 * Orang tua tidak dapat memakai halaman login panel: `User::canAccessPanel()`
 * memang menolak mereka, dan itu aturan yang tidak boleh dilonggarkan. Halaman
 * ini karena itu ada — memakai guard `web` dan tabel `users` yang **sama**
 * dengan panel, bukan guard atau basis pengguna kedua (butir 147).
 *
 * Throttle-nya mengikuti Arsitektur 3.4 dan panel: lima percobaan per menit.
 */
class PortalLogin extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function mount(): void
    {
        // Sesi yang sudah ada tidak perlu login lagi.
        if (Auth::check() && Auth::user()->hasRole(RoleName::OrangTua->value)) {
            $this->redirectRoute('portal.dashboard', navigate: false);
        }
    }

    public function authenticate(): void
    {
        $this->validate();

        $this->ensureIsNotRateLimited();

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            // Pesan yang sama untuk email tak dikenal dan password salah:
            // membedakannya memberi tahu email mana yang terdaftar (butir 115).
            throw ValidationException::withMessages([
                'email' => 'Email atau kata sandi tidak cocok.',
            ]);
        }

        $user = Auth::user();

        // Kredensial boleh benar dan akunnya tetap tidak berhak masuk. Ketiga
        // keadaan di bawah dinilai **sebelum** sesi diakui, supaya tidak pernah
        // ada pengguna setengah masuk yang memegang sesi `web` sah tanpa dapat
        // memakainya (butir 157).
        $refusal = $this->refusalReasonFor($user);

        if ($refusal !== null) {
            $this->abandonSession();

            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages(['email' => $refusal]);
        }

        RateLimiter::clear($this->throttleKey());
        session()->regenerate();

        // Listener `last_login_at` yang sudah ada dipakai kembali, bukan
        // ditulis ulang di sini.
        event(new Login('web', $user, $this->remember));

        $this->redirectRoute('portal.dashboard', navigate: false);
    }

    /**
     * Alasan menolak akun yang kredensialnya sudah benar, atau NULL bila ia
     * memang boleh masuk.
     *
     * `must_change_password` termasuk di sini, dan itu yang paling mudah
     * terlewat. Arsitektur 3.4 dan NFR keamanan menyatakan "password pertama
     * wajib diganti saat login pertama" untuk **seluruh** pengguna — bukan
     * hanya pengguna panel. Penegaknya selama ini `EnsurePasswordIsChanged`,
     * yang terpasang sebagai middleware panel dan bergantung pada halaman
     * profil panel, sehingga tidak dapat dipakai ulang apa adanya di sini.
     * Tanpa pemeriksaan ini, portal menjadi jalan memutar: password sementara
     * hasil reset admin (PORTAL-04) akan berlaku selamanya selama pemiliknya
     * hanya membuka portal (butir 158).
     *
     * Yang ditawarkan sebagai jalan keluar adalah alur yang sudah ada —
     * "lupa kata sandi" lewat surel (AUTH-04). Alur itu rute tamu, dan justru
     * karena akun ini tidak pernah diberi sesi, alur itu selalu dapat
     * dijangkau. Menggantinya dengan password baru otomatis melepas penanda
     * ini lewat hook `updating` pada User.
     */
    protected function refusalReasonFor(?User $user): ?string
    {
        if ($user === null || ! $user->is_active) {
            return 'Akun ini tidak memiliki akses ke Portal Orang Tua.';
        }

        if (! $user->hasRole(RoleName::OrangTua->value)) {
            return 'Akun ini tidak memiliki akses ke Portal Orang Tua.';
        }

        // Akun School Level tanpa cabang tidak punya satu pun anak yang dapat
        // menjadi miliknya (butir 127, 148).
        if ($user->school_id === null) {
            return 'Akun ini tidak memiliki akses ke Portal Orang Tua.';
        }

        if ($user->must_change_password) {
            return 'Kata sandi sementara wajib diganti sebelum masuk. '
                .'Gunakan tautan lupa kata sandi untuk menyetel kata sandi baru.';
        }

        return null;
    }

    /**
     * Membuang sesi yang terlanjur dibuat `Auth::attempt()`.
     */
    protected function abandonSession(): void
    {
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();
    }

    /**
     * @throws ValidationException
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => 'Terlalu banyak percobaan masuk. Coba lagi dalam '
                .RateLimiter::availableIn($this->throttleKey()).' detik.',
        ]);
    }

    protected function throttleKey(): string
    {
        return 'portal-login:'.mb_strtolower($this->email).'|'.request()->ip();
    }

    public function render(): View
    {
        return view('livewire.portal.portal-login')
            ->layout('layouts.portal', [
                'title' => 'Masuk Portal Orang Tua',
                'bare' => true,
            ]);
    }
}
