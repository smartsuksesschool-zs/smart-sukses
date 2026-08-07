<?php

namespace Database\Seeders;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * PRD 1.1.2 — Matriks Izin per Modul (Phase 1).
 *
 * Seeder ini idempotent: aman dijalankan ulang setelah matriks berubah.
 * Peran yang sudah ada akan disinkronkan ulang izinnya.
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * Level akses per modul sesuai matriks:
     *   'manage' → ✅ akses penuh (mendapat izin *.view dan *.manage)
     *   'view'   → ⭕ akses baca saja (hanya izin *.view)
     *   modul yang tidak disebut → ❌ tanpa akses
     *
     * @var array<string, array<string, string>>
     */
    protected array $matrix = [
        RoleName::SuperAdmin->value => [
            // Akses penuh ke semua modul; ditegakkan juga lewat Gate::before.
            'tenant' => 'manage',
            'student' => 'manage',
            'ppdb' => 'manage',
            'class_schedule' => 'manage',
            'grade' => 'manage',
            'report_card' => 'manage',
            'fee' => 'manage',
            'payment' => 'manage',
            'accounting' => 'manage',
            'financial_report' => 'manage',
            'notification' => 'manage',
            'student_portal' => 'manage',
            'parent_portal' => 'manage',
            'white_label' => 'manage',
            'user' => 'manage',
        ],

        RoleName::SchoolAdmin->value => [
            'student' => 'manage',
            'ppdb' => 'manage',
            'class_schedule' => 'manage',
            'grade' => 'manage',
            'report_card' => 'manage',
            'fee' => 'manage',
            'payment' => 'manage',
            'accounting' => 'manage',
            'financial_report' => 'manage',
            'notification' => 'manage',
            'student_portal' => 'manage',
            'parent_portal' => 'manage',
            'white_label' => 'manage',
            'user' => 'manage',
        ],

        RoleName::KepalaSekolah->value => [
            'student' => 'view',
            'ppdb' => 'view',
            'class_schedule' => 'view',
            'grade' => 'view',
            'report_card' => 'view',
            'fee' => 'view',
            'accounting' => 'view',
            'financial_report' => 'view',
            'notification' => 'manage',
            'student_portal' => 'view',
            'parent_portal' => 'view',
        ],

        RoleName::Guru->value => [
            'student' => 'view',
            'class_schedule' => 'view',
            'grade' => 'manage',
            'student_portal' => 'view',
        ],

        // Wali Kelas: semua akses guru + kelola rapor kelas yang diampu.
        RoleName::WaliKelas->value => [
            'student' => 'view',
            'class_schedule' => 'view',
            'grade' => 'manage',
            'report_card' => 'manage',
            'student_portal' => 'view',
        ],

        RoleName::Bendahara->value => [
            'student' => 'view',
            'fee' => 'manage',
            'payment' => 'manage',
            'accounting' => 'manage',
            'financial_report' => 'manage',
        ],

        RoleName::Siswa->value => [
            'student' => 'view',
            'class_schedule' => 'view',
            'report_card' => 'view',
            'student_portal' => 'manage',
        ],

        RoleName::OrangTua->value => [
            'student' => 'view',
            'report_card' => 'view',
            'fee' => 'view',
            'parent_portal' => 'manage',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = config('auth.defaults.guard', 'web');

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, $guard);
        }

        foreach ($this->matrix as $roleName => $modules) {
            $role = Role::findOrCreate($roleName, $guard);

            $role->syncPermissions($this->permissionsFor($modules));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  array<string, string>  $modules
     * @return array<int, string>
     */
    protected function permissionsFor(array $modules): array
    {
        $granted = [];

        foreach ($modules as $module => $level) {
            $granted[] = "{$module}.view";

            if ($level === 'manage') {
                $granted[] = "{$module}.manage";
            }
        }

        // Jaga-jaga bila ada modul yang salah ketik pada matriks.
        return array_values(array_intersect($granted, PermissionName::values()));
    }
}
