<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Foto profil pengguna: media publik, tetapi hanya gambar raster (butir 587).
 *
 * Seluruh berkas di sini sintetis.
 */
class UserAvatarTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        Storage::fake(User::AVATAR_DISK);

        $this->school = School::factory()->create();
        $this->admin = User::factory()->forSchool($this->school)->withRole(RoleName::SchoolAdmin)->create();
    }

    protected function newUserForm(UploadedFile $avatar): Testable
    {
        $this->actingAs($this->admin);

        return Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Pengguna Sintetis',
                'email' => 'pengguna.sintetis@example.test',
                'locale' => 'id',
                'is_active' => true,
                'password' => 'Password123',
                'roles' => [Role::findByName(RoleName::Guru->value)->id],
                'avatar_url' => [$avatar],
            ])
            ->call('create');
    }

    // ------------------------------------------------------------- format

    public function test_svg_ditolak_sebagai_foto_profil(): void
    {
        $svg = UploadedFile::fake()->createWithContent(
            'profil.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $this->newUserForm($svg)->assertHasFormErrors(['avatar_url']);

        $this->assertNull(User::where('email', 'pengguna.sintetis@example.test')->first());
        $this->assertSame([], Storage::disk(User::AVATAR_DISK)->allFiles());
    }

    public function test_svg_dengan_mime_yang_dinyatakan_juga_ditolak(): void
    {
        $this->newUserForm(UploadedFile::fake()->create('profil.svg', 2, 'image/svg+xml'))
            ->assertHasFormErrors(['avatar_url']);
    }

    public function test_jpeg_png_dan_webp_diterima(): void
    {
        foreach (['profil.jpg', 'profil.png', 'profil.webp'] as $i => $filename) {
            $this->actingAs($this->admin);

            Livewire::test(CreateUser::class)
                ->fillForm([
                    'name' => "Pengguna Sintetis {$i}",
                    'email' => "pengguna.{$i}@example.test",
                    'locale' => 'id',
                    'is_active' => true,
                    'password' => 'Password123',
                    'roles' => [Role::findByName(RoleName::Guru->value)->id],
                    'avatar_url' => [UploadedFile::fake()->image($filename, 64, 64)],
                ])
                ->call('create')
                ->assertHasNoFormErrors();

            $path = (string) User::where('email', "pengguna.{$i}@example.test")->value('avatar_url');

            $this->assertStringStartsWith(User::AVATAR_DIRECTORY.'/', $path, "{$filename} seharusnya diterima.");
            Storage::disk(User::AVATAR_DISK)->assertExists($path);
        }
    }

    // ---------------------------------------------------------------- URL

    public function test_url_avatar_dibangun_dari_disk_publik(): void
    {
        Storage::disk(User::AVATAR_DISK)->put('avatars/profil.png', 'gambar-sintetis');
        $user = User::factory()->forSchool($this->school)->create(['avatar_url' => 'avatars/profil.png']);

        $this->assertSame(
            Storage::disk(User::AVATAR_DISK)->url('avatars/profil.png'),
            $user->getFilamentAvatarUrl(),
        );
        $this->assertNotSame('avatars/profil.png', $user->getFilamentAvatarUrl());
    }

    public function test_avatar_yang_berkasnya_hilang_tidak_mengembalikan_url_rusak(): void
    {
        $user = User::factory()->forSchool($this->school)->create(['avatar_url' => 'avatars/hilang.png']);

        $this->assertNull($user->getFilamentAvatarUrl());
    }

    public function test_avatar_kosong_atau_di_luar_direktorinya_tidak_punya_url(): void
    {
        Storage::disk(User::AVATAR_DISK)->put('site/logo.png', 'bukan-avatar');

        foreach ([null, '', 'site/logo.png', 'avatars/../site/logo.png'] as $value) {
            $user = new User(['avatar_url' => $value]);

            $this->assertNull($user->getFilamentAvatarUrl(), var_export($value, true));
        }
    }

    public function test_panel_menampilkan_avatar_yang_diunggah(): void
    {
        Storage::disk(User::AVATAR_DISK)->put('avatars/admin.png', 'gambar-sintetis');
        $this->admin->update(['avatar_url' => 'avatars/admin.png']);

        $this->actingAs($this->admin->fresh())
            ->get('/admin')
            ->assertSuccessful()
            ->assertSee(Storage::disk(User::AVATAR_DISK)->url('avatars/admin.png'), escape: false);
    }

    // --------------------------------------------------------- berkas yatim

    public function test_avatar_lama_terhapus_sesudah_diganti_lewat_panel(): void
    {
        Storage::disk(User::AVATAR_DISK)->put('avatars/lama.png', 'gambar-lama');
        $user = User::factory()->forSchool($this->school)->withRole(RoleName::Guru)
            ->create(['avatar_url' => 'avatars/lama.png']);

        $this->actingAs($this->admin);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['avatar_url' => [UploadedFile::fake()->image('baru.png', 64, 64)]])
            ->call('save')
            ->assertHasNoFormErrors();

        $baru = (string) $user->fresh()->avatar_url;

        $this->assertNotSame('avatars/lama.png', $baru);
        Storage::disk(User::AVATAR_DISK)->assertMissing('avatars/lama.png');
        Storage::disk(User::AVATAR_DISK)->assertExists($baru);
    }

    public function test_avatar_ikut_terhapus_bersama_akunnya(): void
    {
        Storage::disk(User::AVATAR_DISK)->put('avatars/akun.png', 'gambar-sintetis');
        $user = User::factory()->forSchool($this->school)->create(['avatar_url' => 'avatars/akun.png']);

        $user->delete();

        Storage::disk(User::AVATAR_DISK)->assertMissing('avatars/akun.png');
    }

    public function test_avatar_yang_masih_dirujuk_akun_lain_tidak_dihapus(): void
    {
        Storage::disk(User::AVATAR_DISK)->put('avatars/bersama.png', 'gambar-sintetis');
        $user = User::factory()->forSchool($this->school)->create(['avatar_url' => 'avatars/bersama.png']);
        User::factory()->forSchool(School::factory()->create())->create(['avatar_url' => 'avatars/bersama.png']);

        $user->update(['avatar_url' => null]);

        Storage::disk(User::AVATAR_DISK)->assertExists('avatars/bersama.png');
    }
}
