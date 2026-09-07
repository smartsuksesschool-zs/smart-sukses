<?php

namespace App\Enums;

/**
 * Penyedia identitas luar — `users.auth_provider`.
 *
 * Hari ini hanya ada satu. Enum ini tetap dibuat karena nilainya masuk ke
 * indeks unik `(auth_provider, provider_subject)`: sebuah string yang diketik
 * ulang di lima tempat akan cepat atau lambat menjadi `'Google'` di salah
 * satunya, dan indeks itu tidak akan menahan apa pun lagi.
 */
enum AuthProvider: string
{
    case Google = 'google';

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
