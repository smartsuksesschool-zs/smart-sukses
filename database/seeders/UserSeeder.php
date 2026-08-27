<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Akun awal: satu Super Administrator (school_id = NULL) dan satu Admin
 * Sekolah untuk cabang PUSAT. Password diambil dari env SEED_ADMIN_PASSWORD.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('SEED_ADMIN_PASSWORD', 'Password123');

        /*
         * Pagar produksi.
         *
         * Nilai cadangan di atas ada di repository, jadi ia diketahui siapa pun
         * yang dapat membaca kode ini. Di lokal itu memang kenyamanan yang
         * disengaja. Di produksi ia berarti akun Super Administrator lahir
         * dengan kata sandi yang tercetak di repository — dan walaupun
         * `must_change_password` menutupnya pada login pertama, jendela antara
         * seeding dan login pertama itu nyata (butir 363).
         *
         * Yang dilempar RuntimeException, bukan peringatan: seeding yang
         * "berhasil dengan catatan" akan terlewat di tengah keluaran deployment.
         * Kata sandinya sendiri tidak pernah ikut dicetak.
         */
        if (app()->environment('production') && blank(env('SEED_ADMIN_PASSWORD'))) {
            throw new RuntimeException(
                'SEED_ADMIN_PASSWORD wajib disetel sebelum seeding di produksi. '
                .'Tanpa itu akun awal memakai kata sandi bawaan yang ada di dalam repository.'
            );
        }
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
