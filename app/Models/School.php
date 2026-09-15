<?php

namespace App\Models;

use App\Support\ReplacedMedia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ERD 2.2 — schools. Satu baris = satu cabang (tenant).
 */
class School extends Model
{
    use HasFactory;

    /**
     * Disk penyimpanan logo white-label. Logo memang harus dapat dimuat browser
     * tanpa otorisasi, karena ikut tampil di halaman publik PPDB (butir 42).
     * Foto siswa dan berkas PPDB yang dulu berbagi disk ini sudah pindah ke
     * disk privat (butir 411, 587).
     */
    public const LOGO_DISK = 'public';

    public const LOGO_DIRECTORY = 'schools/logos';

    protected $fillable = [
        'name',
        'code',
        'slug',
        'logo_url',
        'primary_color',
        'secondary_color',
        'address',
        'phone',
        'email',
        'head_name',
        'wa_template_ppdb',
        'wa_template_spp',
        'wa_template_rapor',
        'attitude_scale',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            // Keputusan Sprint 4 butir 3 — rentang predikat sikap per cabang.
            'attitude_scale' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Logo yang diganti atau dikosongkan — dari Master Cabang maupun Pengaturan
     * Tampilan — membuang berkas lamanya sesudah nilai barunya tersimpan. Logo
     * berupa URL penuh tidak pernah disentuh (butir 587).
     */
    protected static function booted(): void
    {
        static::updated(fn (self $school) => ReplacedMedia::afterUpdate(
            $school, 'logo_url', self::LOGO_DISK, self::LOGO_DIRECTORY,
        ));
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
