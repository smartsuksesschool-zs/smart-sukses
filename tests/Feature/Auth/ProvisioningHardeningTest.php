<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Filament\Resources\StudentResource;
use App\Filament\Resources\UserResource;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\Sprint4DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Jebakan provisioning akun production — ditutup, lalu dipagari.
 *
 * Keempat jebakannya punya bentuk yang sama: UI yang membentuk apa yang
 * **terlihat** tanpa menolak apa yang **dikirim**, sehingga akun yang salah lahir
 * tanpa satu pun galat dan baru terasa salah pada hari pemakaiannya (butir 593).
 */
class ProvisioningHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected School $cabangLain;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create(['code' => 'PUSAT']);
        $this->cabangLain = School::factory()->create(['code' => 'CABANG2']);

        $this->admin = User::factory()
            ->forSchool($this->school)
            ->withRole(RoleName::SchoolAdmin)
            ->create();
    }

    // ================================================== perkakas

    protected function superAdmin(): User
    {
        return User::factory()->withRole(RoleName::SuperAdmin)->create(['school_id' => null]);
    }

    protected function portalUser(RoleName $role, School $school): User
    {
        return User::factory()->forSchool($school)->withRole($role)->create();
    }

    protected function student(array $attributes = []): Student
    {
        return Student::factory()->create($attributes + [
            'school_id' => $this->school->id,
            'nis' => 'NIS-'.fake()->unique()->numberBetween(1000, 9999),
        ]);
    }

    protected function roleId(RoleName $role): int
    {
        return (int) Role::query()->where('name', $role->value)->value('id');
    }

    /**
     * Apakah aksi Reset Password ditawarkan untuk baris ini.
     */
    protected function resetVisibleFor(User $record): bool
    {
        $this->actingAs($this->admin);

        return Livewire::test(UserResource\Pages\ListUsers::class)
            ->instance()
            ->getTable()
            ->getAction('resetPassword')
            ->record($record)
            ->isVisible();
    }

    // ============================== 1. Reset Password peran portal

    public function test_reset_password_tidak_ditawarkan_untuk_siswa(): void
    {
        $siswa = $this->portalUser(RoleName::Siswa, $this->school);

        $this->assertFalse($this->resetVisibleFor($siswa));
    }

    public function test_reset_password_tidak_ditawarkan_untuk_orang_tua(): void
    {
        $orangTua = $this->portalUser(RoleName::OrangTua, $this->school);

        $this->assertFalse($this->resetVisibleFor($orangTua));
    }

    /**
     * Pagar arah sebaliknya: tanpa ini, menyembunyikan aksi untuk **semua** orang
     * juga akan membuat kedua tes di atas lulus.
     */
    public function test_reset_password_tetap_ditawarkan_untuk_peran_staf(): void
    {
        foreach ([RoleName::Guru, RoleName::WaliKelas, RoleName::Bendahara, RoleName::KepalaSekolah] as $role) {
            $staf = $this->portalUser($role, $this->school);

            $this->assertTrue(
                $this->resetVisibleFor($staf),
                "Reset Password harus tetap ada untuk {$role->value}.",
            );
        }
    }

    /**
     * Alur reset staf yang sudah ada tetap bekerja: sandinya berganti dan
     * penanda wajib-ganti menyala.
     */
    public function test_alur_reset_staf_masih_berfungsi(): void
    {
        $guru = $this->portalUser(RoleName::Guru, $this->school);
        $sandiLama = $guru->password;

        $this->actingAs($this->admin);

        Livewire::test(UserResource\Pages\ListUsers::class)
            ->callTableAction('resetPassword', $guru);

        $guru->refresh();

        $this->assertNotSame($sandiLama, $guru->password);
        $this->assertTrue($guru->must_change_password);
    }

    // ============================== 2. Tenant safety penautan akun

    public function test_akun_portal_satu_cabang_diterima(): void
    {
        $siswa = $this->student();
        $akun = $this->portalUser(RoleName::Siswa, $this->school);
        $ortu = $this->portalUser(RoleName::OrangTua, $this->school);

        $this->actingAs($this->admin);

        Livewire::test(StudentResource\Pages\EditStudent::class, ['record' => $siswa->getRouteKey()])
            ->fillForm(['user_id' => $akun->id, 'parent_user_id' => $ortu->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $siswa->refresh();
        $this->assertSame($akun->id, $siswa->user_id);
        $this->assertSame($ortu->id, $siswa->parent_user_id);
    }

    public function test_akun_portal_cabang_lain_ditolak(): void
    {
        $siswa = $this->student();
        $asing = $this->portalUser(RoleName::Siswa, $this->cabangLain);

        $this->actingAs($this->superAdmin());

        Livewire::test(StudentResource\Pages\EditStudent::class, ['record' => $siswa->getRouteKey()])
            ->fillForm(['user_id' => $asing->id])
            ->call('save')
            ->assertHasFormErrors(['user_id']);

        $this->assertNull($siswa->fresh()->user_id);
    }

    public function test_akun_orang_tua_cabang_lain_ditolak(): void
    {
        $siswa = $this->student();
        $asing = $this->portalUser(RoleName::OrangTua, $this->cabangLain);

        $this->actingAs($this->superAdmin());

        Livewire::test(StudentResource\Pages\EditStudent::class, ['record' => $siswa->getRouteKey()])
            ->fillForm(['parent_user_id' => $asing->id])
            ->call('save')
            ->assertHasFormErrors(['parent_user_id']);

        $this->assertNull($siswa->fresh()->parent_user_id);
    }

    public function test_akun_berperan_keliru_ditolak(): void
    {
        $siswa = $this->student();
        $guru = $this->portalUser(RoleName::Guru, $this->school);

        $this->actingAs($this->admin);

        Livewire::test(StudentResource\Pages\EditStudent::class, ['record' => $siswa->getRouteKey()])
            ->fillForm(['user_id' => $guru->id])
            ->call('save')
            ->assertHasFormErrors(['user_id']);

        $this->assertNull($siswa->fresh()->user_id);
    }

    /**
     * ERD 2.2 — satu User paling banyak satu Student sebagai akun portal siswa.
     */
    public function test_akun_siswa_tidak_dapat_tertaut_ke_dua_siswa(): void
    {
        $akun = $this->portalUser(RoleName::Siswa, $this->school);
        $pertama = $this->student(['user_id' => $akun->id]);
        $kedua = $this->student();

        $this->actingAs($this->admin);

        Livewire::test(StudentResource\Pages\EditStudent::class, ['record' => $kedua->getRouteKey()])
            ->fillForm(['user_id' => $akun->id])
            ->call('save')
            ->assertHasFormErrors(['user_id']);

        $this->assertNull($kedua->fresh()->user_id);
        $this->assertSame($akun->id, $pertama->fresh()->user_id);
    }

    /**
     * Sebaliknya, satu akun orang tua **memang** boleh menjadi wali banyak siswa
     * (ERD 2.2, mendukung PORTAL-01 AC-2).
     */
    public function test_satu_akun_orang_tua_boleh_untuk_beberapa_anak(): void
    {
        $ortu = $this->portalUser(RoleName::OrangTua, $this->school);
        $anakSatu = $this->student(['parent_user_id' => $ortu->id]);
        $anakDua = $this->student();

        $this->actingAs($this->admin);

        Livewire::test(StudentResource\Pages\EditStudent::class, ['record' => $anakDua->getRouteKey()])
            ->fillForm(['parent_user_id' => $ortu->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($ortu->id, $anakSatu->fresh()->parent_user_id);
        $this->assertSame($ortu->id, $anakDua->fresh()->parent_user_id);
    }

    // ============================== 3. school_id peran non-Super-Admin

    public function test_super_admin_boleh_dibuat_tanpa_cabang(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(UserResource\Pages\CreateUser::class)
            ->fillForm([
                'name' => 'Super Kedua',
                'email' => 'super.kedua@example.test',
                'roles' => [$this->roleId(RoleName::SuperAdmin)],
                'locale' => 'id',
                'is_active' => true,
                'password' => 'SandiUjiKuat123',
                'school_id' => null,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(User::query()->where('email', 'super.kedua@example.test')->value('school_id'));
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function peranWajibBercabang(): array
    {
        return [
            'Guru' => [RoleName::Guru],
            'Bendahara' => [RoleName::Bendahara],
            'Kepala Sekolah' => [RoleName::KepalaSekolah],
            'Wali Kelas' => [RoleName::WaliKelas],
        ];
    }

    #[DataProvider('peranWajibBercabang')]
    public function test_peran_school_level_tanpa_cabang_ditolak(RoleName $role): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(UserResource\Pages\CreateUser::class)
            ->fillForm([
                'name' => 'Tanpa Cabang',
                'email' => 'tanpa.cabang@example.test',
                'roles' => [$this->roleId($role)],
                'locale' => 'id',
                'is_active' => true,
                'password' => 'SandiUjiKuat123',
                'school_id' => null,
            ])
            ->call('create')
            ->assertHasFormErrors(['school_id']);

        $this->assertFalse(User::query()->where('email', 'tanpa.cabang@example.test')->exists());
    }

    public function test_peran_school_level_dengan_cabang_diterima(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(UserResource\Pages\CreateUser::class)
            ->fillForm([
                'name' => 'Guru Bercabang',
                'email' => 'guru.bercabang@example.test',
                'roles' => [$this->roleId(RoleName::Guru)],
                'locale' => 'id',
                'is_active' => true,
                'password' => 'SandiUjiKuat123',
                'school_id' => $this->school->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(
            $this->school->id,
            User::query()->where('email', 'guru.bercabang@example.test')->value('school_id'),
        );
    }

    public function test_admin_sekolah_memperoleh_cabangnya_sendiri_otomatis(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(UserResource\Pages\CreateUser::class)
            ->fillForm([
                'name' => 'Guru Baru',
                'email' => 'guru.baru@example.test',
                'roles' => [$this->roleId(RoleName::Guru)],
                'locale' => 'id',
                'is_active' => true,
                'password' => 'SandiUjiKuat123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(
            $this->school->id,
            User::query()->where('email', 'guru.baru@example.test')->value('school_id'),
        );
    }

    /**
     * Admin Sekolah tidak dapat menitipkan akun ke cabang lain: field cabangnya
     * tersembunyi, sehingga apa pun yang dikirim diabaikan dan `CreateUser`
     * memakai cabang akun pembuatnya.
     */
    public function test_admin_sekolah_tidak_dapat_membuat_akun_di_cabang_lain(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(UserResource\Pages\CreateUser::class)
            ->fillForm([
                'name' => 'Titipan',
                'email' => 'titipan@example.test',
                'roles' => [$this->roleId(RoleName::Guru)],
                'locale' => 'id',
                'is_active' => true,
                'password' => 'SandiUjiKuat123',
                'school_id' => $this->cabangLain->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(
            $this->school->id,
            User::query()->where('email', 'titipan@example.test')->value('school_id'),
        );
    }

    // ============================== 4. Pagar seeder demo

    public function test_seeder_demo_menolak_produksi(): void
    {
        app()->detectEnvironment(fn (): string => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak boleh berjalan di produksi');

        app(Sprint4DemoSeeder::class)->run();
    }

    /**
     * Di lingkungan uji ia tetap dapat dipakai — pagar yang menolak di mana-mana
     * akan mematikan demo dan simulasi yang memang dibutuhkan.
     *
     * Yang diperiksa hanya bahwa pagar produksinya **tidak** menyala; isi
     * seedernya sendiri sudah diuji di tempat lain.
     */
    public function test_seeder_demo_tidak_menolak_lingkungan_uji(): void
    {
        $this->assertSame('testing', app()->environment());

        try {
            app(Sprint4DemoSeeder::class)->run();
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('tidak boleh berjalan di produksi', $e->getMessage());
        }

        $this->assertTrue(true);
    }
}
