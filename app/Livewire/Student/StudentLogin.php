<?php

namespace App\Livewire\Student;

use App\Enums\RoleName;
use App\Support\PortalEligibility;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Pintu masuk Student Portal.
 *
 * Siswa ditolak seluruh panel (`canAccessPanel`), persis seperti orang tua,
 * jadi portal ini butuh halaman masuknya sendiri. Aturan kelayakannya dibaca
 * dari PortalEligibility — sumber yang sama dengan portal orang tua dan guru,
 * termasuk penanda ganti kata sandi (butir 158, 180).
 *
 * Throttle-nya lima percobaan per menit, mengikuti Arsitektur 3.4 dan panel.
 */
class StudentLogin extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function mount(): void
    {
        if (Auth::check() && Auth::user()->hasRole(RoleName::Siswa->value)) {
            $this->redirectRoute('student.dashboard', navigate: false);
        }
    }

    public function authenticate(): void
    {
        $this->validate();

        $this->ensureIsNotRateLimited();

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            // Pesan yang sama untuk email tak dikenal dan kata sandi salah
            // (butir 115).
            throw ValidationException::withMessages([
                'email' => 'Email atau kata sandi tidak cocok.',
            ]);
        }

        $user = Auth::user();
        $refusal = PortalEligibility::refusalReasonFor($user, [RoleName::Siswa]);

        if ($refusal !== null) {
            // Kredensialnya benar tetapi akun ini tidak berhak: sesinya dibuang
            // seluruhnya supaya tidak ada pengguna setengah masuk (butir 157).
            Auth::logout();
            session()->invalidate();
            session()->regenerateToken();

            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages(['email' => $refusal]);
        }

        RateLimiter::clear($this->throttleKey());
        session()->regenerate();

        event(new Login('web', $user, $this->remember));

        $this->redirectRoute('student.dashboard', navigate: false);
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
        return 'student-login:'.mb_strtolower($this->email).'|'.request()->ip();
    }

    public function render(): View
    {
        return view('livewire.student.login')
            ->layout('layouts.portal', [
                'title' => 'Masuk Portal Siswa',
                'bare' => true,
            ]);
    }
}
