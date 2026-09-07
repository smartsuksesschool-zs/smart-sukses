<?php

namespace Tests\Feature\Auth;

use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use RuntimeException;

/**
 * Batas terluar Google, dipalsukan — dan hanya itu.
 *
 * Yang digantikan persis satu hal: objek pengguna yang dikembalikan Socialite
 * setelah menukar kode otorisasi. Seluruh yang ada di sebelah dalamnya —
 * pemeriksaan `email_verified`, normalisasi surel, pencarian akun lewat
 * `provider_subject`, penitipan ke sesi, seluruh alur permintaan — berjalan apa
 * adanya. Tidak ada satu pun permintaan jaringan ke Google dalam test mana pun.
 */
trait FakesGoogleOAuth
{
    protected function enableGoogleOAuth(): void
    {
        config([
            'services.google.client_id' => 'client-id-uji',
            'services.google.client_secret' => 'client-secret-uji',
            'services.google.redirect' => 'http://localhost/masuk/google/callback',
        ]);
    }

    /**
     * Identitas yang dikembalikan penyedia pada callback.
     *
     * @param  string|null  $token  token akses palsu; ada di sini justru untuk
     *                              membuktikan ia tidak pernah ikut tersimpan
     */
    protected function fakeGoogleUser(
        string $subject,
        string $email,
        ?string $name = 'Pengguna Google',
        bool $verified = true,
        ?string $token = null,
    ): void {
        $socialiteUser = new SocialiteUser;

        $socialiteUser->map([
            'id' => $subject === '' ? null : $subject,
            'name' => $name,
            'email' => $email === '' ? null : $email,
        ]);

        // Klaim mentah ID token; `email_verified` dibaca dari sini.
        $socialiteUser->user = [
            'sub' => $subject,
            'email' => $email,
            'email_verified' => $verified,
            'name' => $name,
        ];

        $socialiteUser->token = $token;

        $this->mockGoogleProvider(fn (Mockery\MockInterface $provider) => $provider
            ->shouldReceive('user')
            ->andReturn($socialiteUser));
    }

    /**
     * Callback yang gagal: kode kedaluwarsa, `state` tidak cocok, atau dibatalkan.
     */
    protected function fakeGoogleFailure(): void
    {
        $this->mockGoogleProvider(fn (Mockery\MockInterface $provider) => $provider
            ->shouldReceive('user')
            ->andThrow(new RuntimeException('invalid_grant')));
    }

    /**
     * Hanya pengalihan awalnya; `user()` tidak akan dipanggil.
     */
    protected function fakeGoogleRedirect(): void
    {
        $this->mockGoogleProvider(fn () => null);
    }

    /**
     * @param  callable(Mockery\MockInterface): mixed  $configure
     */
    protected function mockGoogleProvider(callable $configure): void
    {
        $provider = Mockery::mock(GoogleProvider::class);

        // Rantai `->scopes(...)` pada controller mengembalikan providernya sendiri.
        $provider->shouldReceive('scopes')->andReturnSelf();
        $provider->shouldReceive('redirect')
            ->andReturn(new RedirectResponse('https://accounts.google.com/o/oauth2/v2/auth?client_id=uji'));

        $configure($provider);

        /*
         * Instansnya **ditukar**, bukan ditambahi harapan.
         *
         * `Socialite::shouldReceive()` yang dipanggil dua kali dalam satu test
         * menumpuk pada mock facade yang sama, dan pemanggilan berikutnya tetap
         * menerima provider yang pertama. Test yang berpindah identitas —
         * pemohon kedua atas siswa yang sama, misalnya — akan diam-diam
         * memakai identitas yang pertama dan lulus karena alasan yang keliru.
         */
        Socialite::swap(Mockery::mock(Factory::class, function (Mockery\MockInterface $factory) use ($provider) {
            $factory->shouldReceive('driver')->with('google')->andReturn($provider);
        }));
    }
}
