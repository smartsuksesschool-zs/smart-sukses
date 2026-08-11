<?php

namespace App\Models;

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

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
