<?php

namespace App\Support;

use App\Models\School;
use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * AUTH-03 — "setelah login saya melihat tampilan (logo, warna utama) yang sesuai
 * dengan cabang sekolah saya".
 *
 * Arsitektur 3.2.3 menjelaskan alurnya: baca `schools.logo_url`,
 * `primary_color`, `secondary_color` berdasarkan `school_id` pengguna, lalu
 * suntikkan CSS variable ke `<head>`. Karena dibaca per request dari database,
 * perubahan tema berlaku seketika tanpa deployment ulang (AUTH-03 poin 3).
 *
 * Super Admin tidak terikat cabang (`school_id` NULL), sehingga selalu jatuh ke
 * warna platform — nilai yang sama dengan DEFAULT kolomnya di migration.
 */
class SchoolBranding
{
    /**
     * Warna platform. Sengaja disamakan dengan DEFAULT `schools.primary_color`
     * dan `schools.secondary_color` (butir 4) supaya cabang yang belum pernah
     * menyetel apa pun tampil identik dengan panel Super Admin.
     */
    public const FALLBACK_PRIMARY = '#1B3A6B';

    public const FALLBACK_SECONDARY = '#E07020';

    /**
     * Cabang milik pengguna yang sedang login.
     *
     * NULL untuk tamu dan untuk Super Admin — keduanya tidak terikat cabang,
     * dan tidak ada cabang mana pun yang boleh dipilihkan untuk mereka.
     */
    public function currentSchool(): ?School
    {
        if (! Auth::hasUser()) {
            return null;
        }

        $user = Auth::user();

        if ($user->school_id === null) {
            return null;
        }

        return $user->relationLoaded('school')
            ? $user->school
            : $user->school()->first();
    }

    public function primaryColor(?School $school = null): string
    {
        return $this->hexOrFallback(
            ($school ?? $this->currentSchool())?->primary_color,
            self::FALLBACK_PRIMARY,
        );
    }

    public function secondaryColor(?School $school = null): string
    {
        return $this->hexOrFallback(
            ($school ?? $this->currentSchool())?->secondary_color,
            self::FALLBACK_SECONDARY,
        );
    }

    /**
     * URL logo cabang, atau NULL bila cabang belum mengunggahnya.
     */
    public function logoUrl(?School $school = null): ?string
    {
        $path = ($school ?? $this->currentSchool())?->logo_url;

        if (blank($path)) {
            return null;
        }

        // Path yang sudah berupa URL penuh dibiarkan apa adanya; ERD menyebut
        // kolom ini "Path ke file logo", dan pengisian manual lewat seeder atau
        // migrasi data lama bisa saja sudah berbentuk URL.
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Storage::disk(School::LOGO_DISK)->url($path);
    }

    public function brandName(): string
    {
        return $this->currentSchool()?->name ?? config('app.name');
    }

    /**
     * Blok `<style>` berisi CSS variable warna cabang.
     *
     * Filament menuliskan paletnya sendiri sebagai `--primary-50 … --primary-950`
     * (RGB tanpa fungsi warna) di `:root`. Blok ini memakai nama variabel yang
     * persis sama dan disuntikkan setelahnya, sehingga menang tanpa `!important`
     * dan tanpa menyentuh satu pun berkas CSS yang dikompilasi.
     *
     * Mengembalikan string kosong bila pengguna tidak terikat cabang — dengan
     * begitu panel Super Admin memakai palet bawaan panel apa adanya.
     */
    public function cssVariables(): string
    {
        $school = $this->currentSchool();

        if ($school === null) {
            return '';
        }

        $declarations = [];

        foreach ([
            'primary' => $this->primaryColor($school),
            'secondary' => $this->secondaryColor($school),
        ] as $alias => $hex) {
            foreach (Color::hex($hex) as $shade => $rgb) {
                $declarations[] = "--{$alias}-{$shade}:{$rgb};";
            }
        }

        return '<style id="school-branding">:root{'.implode('', $declarations).'}</style>';
    }

    /**
     * Hanya hex 6 digit yang dipercaya. Kolomnya VARCHAR(7) dan formnya sudah
     * memvalidasi, tetapi nilai lama atau hasil impor bisa saja tidak valid —
     * dan `Color::hex()` melempar exception untuk masukan yang tidak dikenal,
     * yang berarti seluruh panel gagal dirender hanya karena satu sel database.
     */
    protected function hexOrFallback(?string $value, string $fallback): string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1 ? $value : $fallback;
    }
}
