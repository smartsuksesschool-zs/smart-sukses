<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\School;
use App\Models\User;
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
        $password = env('SEED_ADMIN_PASSWORD', 'Password123');
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
