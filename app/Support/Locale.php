<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * NFR 1.4 — "Bahasa: Bahasa Indonesia (default) + English, dapat diperluas."
 *
 * Satu-satunya tempat daftar bahasa yang didukung ditulis. Sebelumnya daftar
 * itu hidup di dalam `SetUserLocale` sebagai properti privat, sehingga halaman
 * pemilih bahasa dan validasi profil tidak punya cara menanyakannya tanpa
 * menyalinnya (butir 376).
 *
 * Bahasa yang tidak dikenal **selalu** jatuh ke Indonesia, tidak pernah
 * menimbulkan galat. Nilai locale ikut menentukan berkas terjemahan yang dimuat
 * Laravel, jadi nilai sembarang dari request tidak boleh pernah sampai ke
 * `App::setLocale()` (butir 377).
 */
class Locale
{
    /** Bawaan proyek: Bahasa Indonesia. */
    public const DEFAULT = 'id';

    /**
     * Bahasa yang didukung, berikut namanya dalam bahasa itu sendiri —
     * "English", bukan "Inggris". Pemilih bahasa dibaca justru oleh orang yang
     * belum tentu mengerti bahasa yang sedang aktif.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'id' => 'Bahasa Indonesia',
            'en' => 'English',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function supported(): array
    {
        return array_keys(static::options());
    }

    public static function isSupported(mixed $locale): bool
    {
        return is_string($locale) && in_array($locale, static::supported(), true);
    }

    /**
     * Menyaring nilai apa pun menjadi kode bahasa yang sah.
     */
    public static function sanitize(mixed $locale): string
    {
        return static::isSupported($locale) ? $locale : static::DEFAULT;
    }

    /** Label pendek untuk tombol pemilih: ID / EN. */
    public static function shortLabel(string $locale): string
    {
        return strtoupper(static::sanitize($locale));
    }

    /**
     * Bahasa yang berlaku untuk sebuah request.
     *
     * Urutannya disengaja: **preferensi akun menang atas sesi**. Pengguna yang
     * sudah memilih bahasa di profilnya tidak boleh mendapati pilihan itu
     * tertimpa oleh tombol yang pernah ia tekan sebagai tamu di peramban yang
     * sama (butir 378).
     */
    public static function forRequest(Request $request): string
    {
        $user = $request->user();

        if ($user instanceof User && static::isSupported($user->locale)) {
            return $user->locale;
        }

        return static::sanitize($request->session()->get(static::sessionKey()));
    }

    public static function sessionKey(): string
    {
        return 'locale';
    }
}
