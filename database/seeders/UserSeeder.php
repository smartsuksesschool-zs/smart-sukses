<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\School;
use App\Models\User;
use App\Support\SeedPassword;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Akun awal: satu Super Administrator (school_id = NULL) dan satu Admin
 * Sekolah untuk cabang PUSAT. Password diambil dari env SEED_ADMIN_PASSWORD.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        /*
         * Pagar kata sandi seeding ada di App\Support\SeedPassword.
         *
         * Semula pagar itu tertulis di sini dan hanya menyebut `production`.
         * Itu cukup selama satu-satunya lingkungan ber-hostname adalah
         * produksi; begitu staging.smartsukses.sch.id direncanakan,
         * `APP_ENV=staging` melewatinya begitu saja dan seluruh akun awal lahir
         * dengan kata sandi yang ada di dalam repository, di alamat yang dapat
         * dibuka siapa pun.
         *
         * Staging bukan lokal: ia sama terbukanya dengan produksi, hanya isinya
         * yang berbeda (butir 509).
         */
        $password = SeedPassword::resolve();

        $school = School::where('code', 'PUSAT')->first();

        $superAdmin = User::updateOrCreate(
            ['email' => 'superadmin@smartsukses.sch.id'],
            [
                'school_id' => null,
                'name' => 'Super Administrator',
                'password' => Hash::make($password),
                'locale' => 'id',
                'is_active' => true,
                'must_change_password' => true,
                'email_verified_at' => now(),
            ],
        );

        $superAdmin->syncRoles([RoleName::SuperAdmin->value]);

        if (! $school) {
            return;
        }

        $schoolAdmin = User::updateOrCreate(
            ['email' => 'admin.pusat@smartsukses.sch.id'],
            [
                'school_id' => $school->id,
                'name' => 'Admin Sekolah Pusat',
                'password' => Hash::make($password),
                'locale' => 'id',
                'is_active' => true,
                'must_change_password' => true,
                'email_verified_at' => now(),
            ],
        );

        $schoolAdmin->syncRoles([RoleName::SchoolAdmin->value]);
    }
}
