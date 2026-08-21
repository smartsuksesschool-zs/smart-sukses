<?php

namespace App\Http\Resources\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API 4.2 — GET /auth/me: "profil user yang sedang login beserta role dan data
 * sekolahnya".
 *
 * Hanya field yang aman. `password` dan `remember_token` sudah tersembunyi di
 * model, tetapi daftar di sini bersifat allow-list: kolom baru pada `users`
 * tidak akan ikut bocor hanya karena ditambahkan.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_url' => $this->avatar_url,
            'locale' => $this->locale,
            'is_active' => (bool) $this->is_active,
            // Arsitektur 3.4 — klien perlu tahu bahwa password sementara masih
            // wajib diganti; ini status alur, bukan rahasia.
            'must_change_password' => (bool) $this->must_change_password,
            'role' => $this->primaryRole()?->value,
            'role_label' => $this->primaryRole()?->label(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'school' => $this->whenLoaded('school', fn () => new SchoolResource($this->school)),
        ];
    }
}
