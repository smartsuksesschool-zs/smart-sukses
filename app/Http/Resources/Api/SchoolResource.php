<?php

namespace App\Http\Resources\Api;

use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API 4.2 — POST /auth/login mengembalikan "school config (logo, colors)".
 *
 * Sengaja sempit: identitas cabang dan konfigurasi white-label saja. Pengaturan
 * internal cabang (mis. skala predikat sikap) tidak ikut — klien API belum
 * membutuhkannya, dan menambah field selalu lebih mudah daripada menariknya
 * kembali setelah ada yang memakainya.
 *
 * @mixin School
 */
class SchoolResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'slug' => $this->slug,
            'logo_url' => $this->logo_url,
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
