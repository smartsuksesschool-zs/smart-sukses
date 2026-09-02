<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Satu baris teks atau tautan halaman muka publik.
 *
 * **Tanpa SchoolScope, dan bukan karena terlupa.** Ini isi payung Smart Sukses
 * School, bukan isi cabang: halaman muka dibaca tamu yang belum login, dan tamu
 * tidak punya `school_id`. Global scope apa pun di sini akan menyaring dengan
 * nilai yang tidak ada, lalu halaman publik kosong tanpa satu pun pesan galat
 * (butir 464).
 *
 * SchoolScope di model lain tidak disentuh sama sekali; model ini hanya tidak
 * pernah memakainya.
 */
class SiteSetting extends Model
{
    /** Disk berkas publik — sama dengan logo cabang dan foto siswa. */
    public const MEDIA_DISK = 'public';

    /** Direktori berkas halaman muka di dalam disk publik. */
    public const MEDIA_DIRECTORY = 'site';

    protected const CACHE_KEY = 'site_settings.all';

    protected $fillable = ['key', 'value'];

    /**
     * Seluruh pengaturan sekaligus.
     *
     * Halaman muka membaca belasan kunci; membiarkannya satu query per kunci
     * berarti belasan query pada halaman yang paling sering dibuka orang asing.
     * Satu query, lalu di-cache sampai ada yang menyunting.
     *
     * @return array<string, string|null>
     */
    public static function pairs(): array
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            fn () => static::query()->pluck('value', 'key')->all(),
        );
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = self::pairs()[$key] ?? null;

        // String kosong diperlakukan sama dengan belum diisi: form Filament
        // mengirim '' untuk field yang dikosongkan admin, dan "" bukan judul.
        return ($value === null || $value === '') ? $default : $value;
    }

    public static function set(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);

        self::forget();
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected static function booted(): void
    {
        // Cache dibuang dari model, bukan dari pemanggilnya: setiap jalur tulis
        // — form Filament, seeder, tinker — melewati sini.
        static::saved(fn () => self::forget());
        static::deleted(fn () => self::forget());
    }
}
