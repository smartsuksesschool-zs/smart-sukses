<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Enums\RoleName;
use App\Filament\Resources\RoleResource;
use App\Filament\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Arsitektur 3.4 — aksi CUD yang mengubah kewenangan.
 *
 * Peran disimpan lewat relationship `belongsToMany`, yang tidak memicu model
 * event apa pun, dan `config/permission.php` menyetel `events_enabled => false`.
 * Kedua jalur UI yang mengubah kewenangan karena itu diinstrumentasi eksplisit —
 * lihat butir 47.
 */
class RoleChangeAuditTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create();
        $this->admin = User::factory()->forSchool($this->school)->withRole(RoleName::SchoolAdmin)->create();
    }

    protected function clearLogs(): void
    {
        AuditLog::query()->withoutGlobalScopes()->delete();
    }

    /**
     * @return Collection<int, AuditLog>
     */
    protected function logs(): Collection
    {
        return AuditLog::query()->withoutGlobalScopes()->orderBy('id')->get();
    }

    public function test_changing_only_the_role_of_a_user_is_recorded(): void
    {
        $target = User::factory()->forSchool($this->school)->withRole(RoleName::Guru)->create();
        $waliKelas = Role::findByName(RoleName::WaliKelas->value);

        $this->actingAs($this->admin);
        $this->clearLogs();

        Livewire::test(UserResource\Pages\EditUser::class, ['record' => $target->getKey()])
            ->fillForm(['roles' => [$waliKelas->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        // Perilaku RBAC tetap: perannya benar-benar berpindah.
        $this->assertTrue($target->fresh()->hasRole(RoleName::WaliKelas->value));
        $this->assertFalse($target->fresh()->hasRole(RoleName::Guru->value));

        $logs = $this->logs();

        // Tepat satu baris untuk satu aksi manusia.
        $this->assertCount(1, $logs);

        $log = $logs->first();

        $this->assertSame(AuditAction::Updated, $log->action);
        $this->assertSame(User::class, $log->auditable_type);
        $this->assertSame($target->getKey(), (int) $log->auditable_id);
        $this->assertSame($this->admin->getKey(), $log->user_id);
        $this->assertSame($this->school->id, $log->school_id);
    }

    public function test_changing_an_attribute_and_the_role_together_produces_one_row(): void
    {
        // Tanpa penjagaan `wasChanged()`, penyimpanan ini menghasilkan dua baris
        // yang identik di setiap kolom — noise, bukan informasi.
        $target = User::factory()->forSchool($this->school)->withRole(RoleName::Guru)->create();
        $waliKelas = Role::findByName(RoleName::WaliKelas->value);

        $this->actingAs($this->admin);
        $this->clearLogs();

        Livewire::test(UserResource\Pages\EditUser::class, ['record' => $target->getKey()])
            ->fillForm(['name' => 'Nama Baru', 'roles' => [$waliKelas->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Nama Baru', $target->fresh()->name);
        $this->assertTrue($target->fresh()->hasRole(RoleName::WaliKelas->value));

        $logs = $this->logs();

        $this->assertCount(1, $logs);
        $this->assertSame($target->getKey(), (int) $logs->first()->auditable_id);
    }

    public function test_saving_without_changing_the_role_still_records_only_the_attribute_change(): void
    {
        $target = User::factory()->forSchool($this->school)->withRole(RoleName::Guru)->create();
        $guru = Role::findByName(RoleName::Guru->value);

        $this->actingAs($this->admin);
        $this->clearLogs();

        Livewire::test(UserResource\Pages\EditUser::class, ['record' => $target->getKey()])
            ->fillForm(['name' => 'Hanya Nama', 'roles' => [$guru->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertCount(1, $this->logs());
    }

    public function test_the_request_ip_is_recorded_for_a_role_change(): void
    {
        $target = User::factory()->forSchool($this->school)->withRole(RoleName::Guru)->create();
        $waliKelas = Role::findByName(RoleName::WaliKelas->value);

        Route::post('/__role-change-probe__', function () use ($target, $waliKelas) {
            Livewire::test(UserResource\Pages\EditUser::class, ['record' => $target->getKey()])
                ->fillForm(['roles' => [$waliKelas->id]])
                ->call('save');

            return response()->noContent();
        })->middleware('web');

        $this->actingAs($this->admin);
        $this->clearLogs();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
            ->post('/__role-change-probe__')
            ->assertNoContent();

        $log = $this->logs()->last();

        $this->assertNotNull($log);
        $this->assertSame('203.0.113.77', $log->ip_address);
    }

    public function test_creating_a_user_with_a_role_produces_a_single_created_row(): void
    {
        // Pembuatan pengguna sudah menghasilkan satu baris CREATED lewat listener;
        // menambah baris kedua untuk perannya hanya akan menduplikasi satu aksi.
        $guru = Role::findByName(RoleName::Guru->value);

        $this->actingAs($this->admin);
        $this->clearLogs();

        Livewire::test(UserResource\Pages\CreateUser::class)
            ->fillForm([
                'name' => 'Guru Baru',
                'email' => 'guru.baru@example.test',
                'locale' => 'id',
                'is_active' => true,
                'password' => 'Password123',
                'roles' => [$guru->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::query()->where('email', 'guru.baru@example.test')->firstOrFail();

        $logs = $this->logs();

        $this->assertCount(1, $logs);
        $this->assertSame(AuditAction::Created, $logs->first()->action);
        $this->assertSame(User::class, $logs->first()->auditable_type);
        $this->assertSame($created->getKey(), (int) $logs->first()->auditable_id);
        $this->assertTrue($created->hasRole(RoleName::Guru->value));
    }

    public function test_changing_the_permissions_of_a_role_is_recorded(): void
    {
        // Jalur kedua: mengubah izin sebuah peran mengubah kewenangan setiap
        // pengguna yang memegangnya sekaligus.
        auth()->logout();

        $superAdmin = User::factory()->superAdmin()->create();
        $role = Role::findByName(RoleName::Guru->value);
        $permission = Permission::query()->firstOrFail();

        $this->actingAs($superAdmin);
        $this->clearLogs();

        Livewire::test(RoleResource\Pages\EditRole::class, ['record' => $role->getKey()])
            ->fillForm(['permissions' => [$permission->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $logs = $this->logs();

        $this->assertCount(1, $logs);
        $this->assertSame(AuditAction::Updated, $logs->first()->action);
        $this->assertSame(Role::class, $logs->first()->auditable_type);
        $this->assertSame($role->getKey(), (int) $logs->first()->auditable_id);
        $this->assertSame($superAdmin->getKey(), $logs->first()->user_id);

        // Definisi peran bersifat platform-wide — tidak berada di cabang mana pun.
        $this->assertNull($logs->first()->school_id);
    }

    public function test_the_seeder_produces_no_audit_noise(): void
    {
        // RolePermissionSeeder membuat 8 peran dan puluhan izin; tak satu pun
        // boleh menjadi baris audit.
        $this->clearLogs();

        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->count());
    }

    public function test_the_user_factory_role_helper_produces_no_extra_rows(): void
    {
        // `UserFactory::withRole()` memanggil syncRoles() di afterCreating —
        // hanya baris CREATED milik user itu sendiri yang boleh muncul.
        $this->clearLogs();

        User::factory()->forSchool($this->school)->withRole(RoleName::Guru)->create();

        $logs = $this->logs();

        $this->assertCount(1, $logs);
        $this->assertSame(User::class, $logs->first()->auditable_type);
        $this->assertSame(AuditAction::Created, $logs->first()->action);
    }

    public function test_existing_rbac_behaviour_is_unchanged(): void
    {
        $target = User::factory()->forSchool($this->school)->withRole(RoleName::Guru)->create();
        $waliKelas = Role::findByName(RoleName::WaliKelas->value);

        $this->actingAs($this->admin);

        Livewire::test(UserResource\Pages\EditUser::class, ['record' => $target->getKey()])
            ->fillForm(['roles' => [$waliKelas->id]])
            ->call('save');

        $target->refresh();

        // Tepat satu peran utama (PRD 1.1.1), dan izinnya ikut berpindah.
        $this->assertCount(1, $target->roles);
        $this->assertTrue($target->can('report_card.manage'));
        $this->assertFalse($target->can('user.manage'));
    }
}
