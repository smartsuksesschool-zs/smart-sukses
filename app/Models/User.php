<?php

namespace App\Models;

use App\Enums\RoleName;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * ERD 2.2 — users. Semua peran tersimpan di tabel ini;
 * Super Admin memiliki school_id = NULL.
 */
class User extends Authenticatable implements FilamentUser, HasAvatar
{
    /** @use HasFactory<UserFactory> */
    use BelongsToSchool, HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'school_id',
        'name',
        'email',
        'password',
        'phone',
        'avatar_url',
        'locale',
        'is_active',
        'must_change_password',
    ];

    /**
     * Arsitektur 3.4 — begitu pengguna mengganti sendiri passwordnya, penanda
     * "wajib ganti password" dilepas. Reset oleh Admin menyalakan penanda ini
     * secara eksplisit pada operasi simpan yang sama, sehingga dikecualikan.
     */
    protected static function booted(): void
    {
        static::updating(function (self $user): void {
            if ($user->isDirty('password') && ! $user->isDirty('must_change_password')) {
                $user->must_change_password = false;
            }
        });
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * Siswa yang menjadikan akun ini sebagai akun portal orang tuanya
     * (ERD: `students.parent_user_id`).
     *
     * Dipakai penyaringan penerima notifikasi bertarget kelas (butir 199).
     */
    public function parentedStudents(): HasMany
    {
        return $this->hasMany(Student::class, 'parent_user_id');
    }

    /**
     * Peran utama pengguna (PRD 1.1.1: tepat satu peran per pengguna).
     */
    public function primaryRole(): ?RoleName
    {
        $name = $this->roles->first()?->name;

        return $name ? RoleName::tryFrom($name) : null;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(RoleName::SuperAdmin->value);
    }

    public function isSchoolAdmin(): bool
    {
        return $this->hasRole(RoleName::SchoolAdmin->value);
    }

    /**
     * Filament: hanya pengguna aktif dengan peran non-portal yang boleh
     * membuka admin panel (Siswa & Orang Tua dilayani portal terpisah).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && (bool) $this->primaryRole()?->canAccessAdminPanel();
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar_url;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
