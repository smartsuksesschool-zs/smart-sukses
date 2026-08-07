<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AUTH-01 — Login & akses panel admin.
 */
class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_school_admin_can_open_the_panel(): void
    {
        $admin = User::factory()->withRole(RoleName::SchoolAdmin)->create();

        $this->actingAs($admin)->get('/admin')->assertSuccessful();
    }

    public function test_inactive_user_cannot_access_the_panel(): void
    {
        $user = User::factory()->inactive()->withRole(RoleName::SchoolAdmin)->create();

        $this->assertFalse($user->canAccessPanel(filament()->getPanel('admin')));
    }

    public function test_student_and_parent_are_kept_out_of_the_admin_panel(): void
    {
        $panel = filament()->getPanel('admin');

        $siswa = User::factory()->withRole(RoleName::Siswa)->create();
        $ortu = User::factory()->withRole(RoleName::OrangTua)->create();

        $this->assertFalse($siswa->canAccessPanel($panel));
        $this->assertFalse($ortu->canAccessPanel($panel));
    }

    public function test_first_login_is_forced_to_change_the_temporary_password(): void
    {
        $user = User::factory()->withRole(RoleName::SchoolAdmin)->create([
            'must_change_password' => true,
        ]);

        $this->actingAs($user)
            ->get('/admin')
            ->assertRedirect(filament()->getPanel('admin')->getProfileUrl());

        $this->actingAs($user)
            ->get(filament()->getPanel('admin')->getProfileUrl())
            ->assertSuccessful();
    }

    public function test_flag_is_cleared_once_the_user_changes_its_own_password(): void
    {
        $user = User::factory()->withRole(RoleName::SchoolAdmin)->create([
            'must_change_password' => true,
        ]);

        $user->update(['password' => 'PasswordBaru123']);

        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_login_updates_last_login_timestamp(): void
    {
        $user = User::factory()->withRole(RoleName::SchoolAdmin)->create();

        $this->assertNull($user->last_login_at);

        auth()->login($user);

        $this->assertNotNull($user->fresh()->last_login_at);
    }
}
