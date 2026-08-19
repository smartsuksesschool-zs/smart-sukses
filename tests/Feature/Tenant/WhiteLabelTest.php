<?php

namespace Tests\Feature\Tenant;

use App\Enums\RoleName;
use App\Filament\Pages\PengaturanTampilan;
use App\Models\School;
use App\Models\User;
use App\Support\SchoolBranding;
use Database\Seeders\RolePermissionSeeder;
use Filament\Support\Colors\Color;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AUTH-03 — "setelah login saya melihat tampilan (logo, warna utama) yang sesuai
 * dengan cabang sekolah saya", dan PRD 1.1.2 baris "White-label Settings"
 * (SUPER_ADMIN ✅, SCHOOL_ADMIN ✅).
 */
class WhiteLabelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_branding_follows_the_school_of_the_logged_in_user(): void
    {
        $school = School::factory()->create([
            'name' => 'Smart Sukses Madani',
            'primary_color' => '#112233',
            'secondary_color' => '#445566',
        ]);

        $this->actingAs(User::factory()->forSchool($school)->withRole(RoleName::Guru)->create());

        $branding = app(SchoolBranding::class);

        $this->assertSame($school->getKey(), $branding->currentSchool()?->getKey());
        $this->assertSame('#112233', $branding->primaryColor());
        $this->assertSame('#445566', $branding->secondaryColor());
        $this->assertSame('Smart Sukses Madani', $branding->brandName());
    }

    public function test_one_school_does_not_affect_another(): void
    {
        $madani = School::factory()->create(['primary_color' => '#AA0000']);
        $cinangka = School::factory()->create(['primary_color' => '#00AA00']);

        $branding = app(SchoolBranding::class);

        $this->actingAs(User::factory()->forSchool($madani)->withRole(RoleName::Guru)->create());
        $this->assertSame('#AA0000', $branding->primaryColor());

        $this->actingAs(User::factory()->forSchool($cinangka)->withRole(RoleName::Guru)->create());
        $this->assertSame('#00AA00', $branding->primaryColor());
    }

    public function test_super_admin_falls_back_to_the_platform_palette(): void
    {
        // Super Admin tidak terikat cabang (school_id NULL), jadi tidak ada
        // cabang yang boleh dipilihkan untuknya.
        $this->actingAs(User::factory()->superAdmin()->create());

        $branding = app(SchoolBranding::class);

        $this->assertNull($branding->currentSchool());
        $this->assertNull($branding->logoUrl());
        $this->assertSame(SchoolBranding::FALLBACK_PRIMARY, $branding->primaryColor());
        $this->assertSame(SchoolBranding::FALLBACK_SECONDARY, $branding->secondaryColor());
        $this->assertSame(config('app.name'), $branding->brandName());

        // Tanpa cabang, tidak ada CSS yang disuntikkan sama sekali: panel memakai
        // palet bawaan apa adanya.
        $this->assertSame('', $branding->cssVariables());
    }

    public function test_a_guest_never_triggers_branding_lookup(): void
    {
        $branding = app(SchoolBranding::class);

        $this->assertNull($branding->currentSchool());
        $this->assertSame('', $branding->cssVariables());
        $this->assertSame(SchoolBranding::FALLBACK_PRIMARY, $branding->primaryColor());
    }

    public function test_css_variables_override_the_filament_palette(): void
    {
        $school = School::factory()->create(['primary_color' => '#123456']);

        $this->actingAs(User::factory()->forSchool($school)->withRole(RoleName::Guru)->create());

        $css = app(SchoolBranding::class)->cssVariables();

        // Nama variabelnya harus persis milik Filament, jika tidak override-nya
        // tidak berpengaruh apa pun.
        $this->assertStringContainsString(':root{', $css);
        $this->assertStringContainsString('--primary-500:', $css);
        $this->assertStringContainsString('--primary-950:', $css);
        $this->assertStringContainsString('--secondary-500:', $css);

        // Nilainya adalah triplet RGB, format yang sama dengan yang ditulis
        // Filament sendiri.
        $expected = Color::hex('#123456')[500];
        $this->assertStringContainsString("--primary-500:{$expected};", $css);
    }

    public function test_a_broken_colour_value_falls_back_instead_of_breaking_the_panel(): void
    {
        // Nilai lama atau hasil impor bisa saja bukan hex; Color::hex() melempar
        // exception untuk masukan tak dikenal, dan itu berarti seluruh panel
        // gagal dirender hanya karena satu sel database.
        $school = School::factory()->create();
        $school->forceFill(['primary_color' => 'biru'])->save();

        $this->actingAs(User::factory()->forSchool($school)->withRole(RoleName::Guru)->create());

        $branding = app(SchoolBranding::class);

        $this->assertSame(SchoolBranding::FALLBACK_PRIMARY, $branding->primaryColor());
        $this->assertStringContainsString('--primary-500:', $branding->cssVariables());
    }

    public function test_the_panel_head_carries_the_school_colours(): void
    {
        // Pembuktian ujung-ke-ujung: warna cabang benar-benar sampai ke <head>
        // halaman, bukan sekadar tersedia di service-nya.
        $school = School::factory()->create(['primary_color' => '#654321']);

        $this->actingAs(User::factory()->forSchool($school)->withRole(RoleName::SchoolAdmin)->create());

        $expected = Color::hex('#654321')[500];

        $this->get('/admin')
            ->assertSuccessful()
            ->assertSee('school-branding', escape: false)
            ->assertSee("--primary-500:{$expected};", escape: false);
    }

    public function test_school_admin_saves_the_branding_of_its_own_school(): void
    {
        Storage::fake('public');

        $school = School::factory()->create();
        $admin = User::factory()->forSchool($school)->withRole(RoleName::SchoolAdmin)->create();

        $this->actingAs($admin);

        $logo = UploadedFile::fake()->image('logo.png', 200, 200);

        Livewire::test(PengaturanTampilan::class)
            ->fillForm([
                'logo_url' => [$logo],
                'primary_color' => '#0A0B0C',
                'secondary_color' => '#0D0E0F',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $school->refresh();

        $this->assertSame('#0A0B0C', $school->primary_color);
        $this->assertSame('#0D0E0F', $school->secondary_color);
        $this->assertNotNull($school->logo_url);
        Storage::disk('public')->assertExists($school->logo_url);
    }

    public function test_saved_branding_applies_without_redeployment(): void
    {
        // AUTH-03 poin 3. Dibaca ulang dari database pada request berikutnya,
        // bukan dari konfigurasi panel yang dibekukan saat boot.
        $school = School::factory()->create(['primary_color' => '#111111']);
        $admin = User::factory()->forSchool($school)->withRole(RoleName::SchoolAdmin)->create();

        $this->actingAs($admin);

        $before = app(SchoolBranding::class)->primaryColor();

        Livewire::test(PengaturanTampilan::class)
            ->fillForm(['primary_color' => '#222222', 'secondary_color' => '#333333'])
            ->call('save')
            ->assertHasNoFormErrors();

        $expected = Color::hex('#222222')[500];

        $this->assertSame('#111111', $before);
        $this->get('/admin')->assertSee("--primary-500:{$expected};", escape: false);
    }

    public function test_an_invalid_colour_is_rejected_by_the_form(): void
    {
        $school = School::factory()->create();

        $this->actingAs(User::factory()->forSchool($school)->withRole(RoleName::SchoolAdmin)->create());

        Livewire::test(PengaturanTampilan::class)
            ->fillForm(['primary_color' => 'merah', 'secondary_color' => '#E07020'])
            ->call('save')
            ->assertHasFormErrors(['primary_color']);
    }

    public function test_the_logo_upload_is_limited_by_mime_and_size(): void
    {
        $school = School::factory()->create();

        $this->actingAs(User::factory()->forSchool($school)->withRole(RoleName::SchoolAdmin)->create());

        $component = Livewire::test(PengaturanTampilan::class);

        $upload = $component->instance()->form->getComponent('data.logo_url');

        // Arsitektur 3.4 — "Hanya JPG/PNG/PDF diperbolehkan". Tidak ada
        // requirement khusus logo, jadi aturan global itu yang berlaku; PDF
        // dikecualikan karena bukan format gambar yang dapat dirender sebagai
        // logo panel (butir 42).
        $this->assertEqualsCanonicalizing(
            ['image/jpeg', 'image/png'],
            $upload->getAcceptedFileTypes(),
        );

        // Batas ukuran tidak diatur dokumen; disamakan dengan dua unggahan lain
        // yang batasnya tertulis (SIS-03 dan butir 17): 2 MB.
        $this->assertSame(2048, $upload->getMaxSize());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function disallowedLogoProvider(): array
    {
        return [
            // WEBP hanya diizinkan SIS-03, dan SIS-03 khusus "foto profil siswa".
            'webp' => ['logo.webp', 'image/webp'],
            // SVG tidak disebut satu dokumen pun.
            'svg' => ['logo.svg', 'image/svg+xml'],
            // PDF diizinkan aturan global, tetapi bukan gambar yang dapat
            // dirender sebagai logo panel.
            'pdf' => ['logo.pdf', 'application/pdf'],
        ];
    }

    #[DataProvider('disallowedLogoProvider')]
    public function test_a_disallowed_logo_format_is_rejected(string $filename, string $mime): void
    {
        Storage::fake('public');

        $school = School::factory()->create();

        $this->actingAs(User::factory()->forSchool($school)->withRole(RoleName::SchoolAdmin)->create());

        Livewire::test(PengaturanTampilan::class)
            ->fillForm([
                'logo_url' => [UploadedFile::fake()->create($filename, 10, $mime)],
                'primary_color' => '#0A0B0C',
                'secondary_color' => '#0D0E0F',
            ])
            ->call('save')
            ->assertHasFormErrors(['logo_url']);

        $this->assertNull($school->fresh()->logo_url);
    }

    public function test_an_allowed_logo_format_is_accepted(): void
    {
        // Sisi lain dari test di atas: tanpa ini, "ditolak" bisa saja berlaku
        // untuk setiap berkas dan test di atas tetap hijau.
        Storage::fake('public');

        $school = School::factory()->create();

        $this->actingAs(User::factory()->forSchool($school)->withRole(RoleName::SchoolAdmin)->create());

        foreach (['logo.jpg' => 'image/jpeg', 'logo.png' => 'image/png'] as $filename => $mime) {
            Livewire::test(PengaturanTampilan::class)
                ->fillForm([
                    'logo_url' => [UploadedFile::fake()->create($filename, 10, $mime)],
                    'primary_color' => '#0A0B0C',
                    'secondary_color' => '#0D0E0F',
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertNotNull($school->fresh()->logo_url, "{$filename} seharusnya diterima.");

            $school->forceFill(['logo_url' => null])->save();
        }
    }

    public function test_a_logo_above_the_size_limit_is_rejected(): void
    {
        Storage::fake('public');

        $school = School::factory()->create();

        $this->actingAs(User::factory()->forSchool($school)->withRole(RoleName::SchoolAdmin)->create());

        Livewire::test(PengaturanTampilan::class)
            ->fillForm([
                // 2 MB + 1 KB.
                'logo_url' => [UploadedFile::fake()->create('logo.png', 2049, 'image/png')],
                'primary_color' => '#0A0B0C',
                'secondary_color' => '#0D0E0F',
            ])
            ->call('save')
            ->assertHasFormErrors(['logo_url']);

        $this->assertNull($school->fresh()->logo_url);
    }

    public function test_a_school_admin_cannot_brand_another_school(): void
    {
        // Payload jahat: field "Cabang Sekolah" tidak pernah dirender untuk
        // Admin Sekolah, tetapi state Livewire tetap dapat dikirim apa adanya.
        $own = School::factory()->create(['primary_color' => '#AAAAAA']);
        $other = School::factory()->create(['primary_color' => '#BBBBBB']);

        $admin = User::factory()->forSchool($own)->withRole(RoleName::SchoolAdmin)->create();

        $this->actingAs($admin);

        Livewire::test(PengaturanTampilan::class)
            ->fillForm([
                'school_id' => $other->getKey(),
                'primary_color' => '#CCCCCC',
                'secondary_color' => '#DDDDDD',
            ])
            ->call('save');

        // Cabang lain tidak tersentuh; perubahannya jatuh ke cabang sendiri.
        $this->assertSame('#BBBBBB', $other->fresh()->primary_color);
        $this->assertSame('#CCCCCC', $own->fresh()->primary_color);
    }

    public function test_super_admin_chooses_the_school_to_brand(): void
    {
        $target = School::factory()->create(['primary_color' => '#EEEEEE']);

        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(PengaturanTampilan::class)
            ->fillForm([
                'school_id' => $target->getKey(),
                'primary_color' => '#FF0000',
                'secondary_color' => '#00FF00',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('#FF0000', $target->fresh()->primary_color);
    }

    public function test_super_admin_must_choose_a_school_before_saving(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(PengaturanTampilan::class)
            ->fillForm(['primary_color' => '#FF0000', 'secondary_color' => '#00FF00'])
            ->call('save')
            ->assertHasFormErrors(['school_id']);
    }

    public function test_roles_without_white_label_permission_are_blocked(): void
    {
        $school = School::factory()->create();

        foreach ([RoleName::Guru, RoleName::Bendahara, RoleName::Siswa, RoleName::OrangTua] as $role) {
            $this->actingAs(User::factory()->forSchool($school)->withRole($role)->create());

            $this->assertFalse(
                PengaturanTampilan::canAccess(),
                "{$role->value} seharusnya tidak dapat menyetel tampilan cabang.",
            );
        }

        $this->actingAs(User::factory()->forSchool($school)->withRole(RoleName::SchoolAdmin)->create());
        $this->assertTrue(PengaturanTampilan::canAccess());
    }
}
