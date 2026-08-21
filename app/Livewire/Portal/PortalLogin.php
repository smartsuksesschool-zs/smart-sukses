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

        if (! $user->is_active || ! $user->hasRole(RoleName::OrangTua->value)) {
            // Kredensialnya benar tetapi akun ini bukan untuk portal ini.
            // Sesinya dibatalkan supaya tidak ada pengguna setengah masuk.
            Auth::logout();
            session()->invalidate();
            session()->regenerateToken();

            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => 'Akun ini tidak memiliki akses ke Portal Orang Tua.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        session()->regenerate();

        // Listener `last_login_at` yang sudah ada dipakai kembali, bukan
        // ditulis ulang di sini.
        event(new Login('web', $user, $this->remember));

        $this->redirectRoute('portal.dashboard', navigate: false);
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
