<?php

namespace Tests\Feature\PublicSite;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\SiteBlockType;
use App\Filament\Pages\PengaturanSitusPublik;
use App\Filament\Resources\SiteBlockResource;
use App\Filament\Resources\SiteBlockResource\Pages\ManageSiteBlocks;
use App\Models\School;
use App\Models\SiteBlock;
use App\Models\SiteSetting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Penyuntingan isi halaman muka dari panel admin.
 *
 * Dua hal yang paling mudah salah pada fitur seperti ini, dan karena itu diuji
 * tersendiri: siapa yang boleh menyunting situs payung, dan apa yang boleh
 * diunggah ke disk publik.
 *
 * Seluruh berkas di sini palsu (`UploadedFile::fake()`) dan disk-nya diganti
 * disk uji, sehingga tidak ada satu berkas pun yang mendarat di penyimpanan
 * sungguhan.
 */
class PublicContentAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(SiteSetting::MEDIA_DISK);

        $this->seed(RolePermissionSeeder::class);
    }

    protected function superAdmin(): User
    {
        return User::factory()->withRole(RoleName::SuperAdmin)->create([
            'school_id' => null,
            'must_change_password' => false,
        ]);
    }

    protected function schoolAdmin(): User
    {
        $school = School::factory()->create();

        return User::factory()->forSchool($school)->withRole(RoleName::SchoolAdmin)->create([
            'must_change_password' => false,
        ]);
    }

    // ------------------------------------------------------------ otorisasi

    public function test_super_admin_dapat_mengelola_isi_situs_publik(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin);

        $this->assertTrue($admin->can(PermissionName::PublicContentManage->value));
        $this->assertTrue(SiteBlockResource::canViewAny());
        $this->assertTrue(PengaturanSitusPublik::canAccess());
    }

    /**
     * Admin Sekolah **tidak** boleh menyunting situs payung.
     *
     * Ia berhak penuh atas cabangnya sendiri, termasuk white-label cabangnya —
     * dan justru karena itu batasnya harus tegas: halaman muka Smart Sukses
     * School bukan milik satu cabang (butir 469).
     */
    public function test_admin_sekolah_tidak_dapat_mengelola_isi_situs_publik(): void
    {
        $admin = $this->schoolAdmin();

        $this->actingAs($admin);

        $this->assertFalse($admin->can(PermissionName::PublicContentManage->value));
        $this->assertFalse(SiteBlockResource::canViewAny());
        $this->assertFalse(PengaturanSitusPublik::canAccess());

        // Dan ia tetap berhak atas tampilan cabangnya sendiri: batas ini tidak
        // mengambil apa pun yang sudah dimilikinya.
        $this->assertTrue($admin->can(PermissionName::WhiteLabelManage->value));
    }

    public function test_peran_lain_tidak_dapat_mengelola_isi_situs_publik(): void
    {
        $school = School::factory()->create();

        foreach ([RoleName::Guru, RoleName::Bendahara, RoleName::Siswa, RoleName::OrangTua] as $role) {
            $user = User::factory()->forSchool($school)->withRole($role)->create([
                'must_change_password' => false,
            ]);

            $this->actingAs($user);

            $this->assertFalse(
                $user->can(PermissionName::PublicContentManage->value),
                "Peran {$role->value} tidak boleh menyunting situs publik.",
            );
            $this->assertFalse(PengaturanSitusPublik::canAccess());
        }
    }

    public function test_tamu_tidak_dapat_membuka_halaman_pengaturan(): void
    {
        $this->get('/admin/pengaturan-situs-publik')->assertRedirect();
    }

    // ------------------------------------------------------- menyunting isi

    public function test_super_admin_menyimpan_pengaturan_halaman_muka(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(PengaturanSitusPublik::class)
            ->set('data.hero_heading', 'Judul Baru dari Pemilik')
            ->set('data.ppdb_url', 'https://forms.gle/contoh')
            ->set('data.blog_url', 'https://blog.contoh.test')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Judul Baru dari Pemilik', SiteSetting::get('hero_heading'));
        $this->assertSame('https://forms.gle/contoh', SiteSetting::get('ppdb_url'));

        // Dan perubahannya langsung terlihat publik, tanpa deployment ulang.
        $this->get('/')->assertOk()->assertSee('Judul Baru dari Pemilik');
    }

    public function test_alamat_yang_bukan_url_ditolak(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(PengaturanSitusPublik::class)
            ->set('data.blog_url', 'bukan-alamat')
            ->call('save')
            ->assertHasErrors('data.blog_url');

        $this->assertNull(SiteSetting::get('blog_url'));
    }

    public function test_kunci_di_luar_daftar_tidak_pernah_tersimpan(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(PengaturanSitusPublik::class)
            ->set('data.kunci_selundupan', 'nilai apa pun')
            ->call('save');

        // Tabel pengaturan tidak boleh menjadi tempat penyimpanan sembarang
        // nilai: hanya kunci yang dikenal form yang ditulis.
        $this->assertSame(0, SiteSetting::query()->where('key', 'kunci_selundupan')->count());
    }

    // --------------------------------------------------------- unggahan

    public function test_gambar_tersimpan_sebagai_path_bukan_biner(): void
    {
        $block = SiteBlock::create([
            'type' => SiteBlockType::Gallery->value,
            'title' => 'Kegiatan Contoh',
            'is_published' => true,
        ]);

        $file = UploadedFile::fake()->image('kegiatan.jpg', 800, 600);
        $path = $file->store(SiteSetting::MEDIA_DIRECTORY, SiteSetting::MEDIA_DISK);

        $block->update(['image_path' => $path]);

        // Kolomnya memuat kunci penyimpanan, dan tidak lebih.
        $stored = $block->fresh()->image_path;

        $this->assertSame($path, $stored);
        $this->assertLessThan(500, strlen($stored));
        $this->assertStringStartsWith(SiteSetting::MEDIA_DIRECTORY.'/', $stored);

        // Tidak ada bytes gambar di basis data.
        $this->assertStringNotContainsString('JFIF', $stored);
        $this->assertStringNotContainsString(base64_encode('') ?: 'data:image', $stored);

        // Berkasnya memang di disk.
        Storage::disk(SiteSetting::MEDIA_DISK)->assertExists($path);
    }

    public function test_hanya_gambar_yang_diterima(): void
    {
        $this->actingAs($this->superAdmin());

        $form = Form::make(new ManageSiteBlocks);

        $image = collect(SiteBlockResource::form($form)->getComponents())
            ->first(fn ($component) => $component instanceof FileUpload);

        // Aturannya dibaca dari definisi field, bukan ditebak: batas MIME dan
        // ukuran adalah janji yang harus tetap tertulis di sana.
        $this->assertNotNull($image);
        $this->assertSame(['image/jpeg', 'image/png', 'image/webp'], $image->getAcceptedFileTypes());
        $this->assertSame(4096, $image->getMaxSize());
        $this->assertSame(SiteSetting::MEDIA_DISK, $image->getDiskName());
        $this->assertSame(SiteSetting::MEDIA_DIRECTORY, $image->getDirectory());

        // SVG tidak pernah diizinkan: ia dapat memuat skrip (butir 474).
        $this->assertNotContains('image/svg+xml', $image->getAcceptedFileTypes());

        // Nama berkas asli tidak dipertahankan; Filament menamainya ulang.
        $this->assertFalse($image->shouldPreserveFilenames());
    }

    public function test_berkas_bukan_gambar_ditolak_validasi(): void
    {
        $pdf = UploadedFile::fake()->create('dokumen.pdf', 200, 'application/pdf');

        $validator = validator(
            ['image_path' => $pdf],
            ['image_path' => ['image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:4096']],
        );

        $this->assertTrue($validator->fails());
    }

    public function test_gambar_terlalu_besar_ditolak_validasi(): void
    {
        $besar = UploadedFile::fake()->create('besar.jpg', 5 * 1024, 'image/jpeg');

        $validator = validator(
            ['image_path' => $besar],
            ['image_path' => ['mimetypes:image/jpeg,image/png,image/webp', 'max:4096']],
        );

        $this->assertTrue($validator->fails());
    }

    // ------------------------------------------------- daur hidup berkas

    public function test_berkas_lama_dihapus_ketika_gambarnya_diganti(): void
    {
        $lama = UploadedFile::fake()->image('lama.jpg')
            ->store(SiteSetting::MEDIA_DIRECTORY, SiteSetting::MEDIA_DISK);

        $baru = UploadedFile::fake()->image('baru.jpg')
            ->store(SiteSetting::MEDIA_DIRECTORY, SiteSetting::MEDIA_DISK);

        $block = SiteBlock::create([
            'type' => SiteBlockType::Gallery->value,
            'title' => 'Kegiatan',
            'image_path' => $lama,
        ]);

        $block->update(['image_path' => $baru]);

        Storage::disk(SiteSetting::MEDIA_DISK)->assertMissing($lama);
        Storage::disk(SiteSetting::MEDIA_DISK)->assertExists($baru);
    }

    public function test_berkas_ikut_terhapus_bersama_barisnya(): void
    {
        $path = UploadedFile::fake()->image('kegiatan.jpg')
            ->store(SiteSetting::MEDIA_DIRECTORY, SiteSetting::MEDIA_DISK);

        $block = SiteBlock::create([
            'type' => SiteBlockType::Gallery->value,
            'title' => 'Kegiatan',
            'image_path' => $path,
        ]);

        $block->delete();

        Storage::disk(SiteSetting::MEDIA_DISK)->assertMissing($path);
    }

    /**
     * Penghapusan tidak pernah keluar dari direktori media.
     *
     * Kolom path berasal dari basis data, dan basis data dapat berisi apa saja
     * seiring waktu. Nilai seperti `../../.env` harus ditolak, bukan dituruti
     * (butir 474).
     */
    public function test_penghapusan_tidak_dapat_keluar_dari_direktori_media(): void
    {
        Storage::disk(SiteSetting::MEDIA_DISK)->put('rahasia.txt', 'jangan dihapus');

        $block = SiteBlock::create([
            'type' => SiteBlockType::Gallery->value,
            'title' => 'Kegiatan',
            'image_path' => '../rahasia.txt',
        ]);

        $block->delete();

        Storage::disk(SiteSetting::MEDIA_DISK)->assertExists('rahasia.txt');
    }

    public function test_path_di_luar_direktori_media_tidak_dihapus(): void
    {
        Storage::disk(SiteSetting::MEDIA_DISK)->put('schools/logos/logo.png', 'logo cabang');

        $block = SiteBlock::create([
            'type' => SiteBlockType::Unit->value,
            'title' => 'Unit',
            'image_path' => 'schools/logos/logo.png',
        ]);

        $block->delete();

        // Logo cabang bukan milik halaman muka; menghapusnya akan merusak
        // white-label yang tidak ada hubungannya dengan situs publik.
        Storage::disk(SiteSetting::MEDIA_DISK)->assertExists('schools/logos/logo.png');
    }

    // ------------------------------------------------- pemisahan berkas privat

    public function test_media_publik_tidak_pernah_menyentuh_disk_privat(): void
    {
        $this->assertSame('public', SiteSetting::MEDIA_DISK);

        // Berkas PPDB dan bukti bayar tetap di disk privat; batch ini tidak
        // menyentuh keduanya.
        $this->assertSame(
            storage_path('app/private'),
            config('filesystems.disks.local.root'),
        );
    }

    /**
     * Perlindungan berkas privat PPDB tidak boleh ikut berubah karena batch
     * situs publik.
     *
     * Dibaca dari kode yang benar-benar berjalan, bukan dari ingatan: unggahan
     * PPDB harus tetap memakai disk privat, dan tidak satu pun berkas PPDB
     * boleh mendarat di disk publik tempat media halaman muka tinggal.
     */
    public function test_unggahan_ppdb_tetap_di_penyimpanan_privat(): void
    {
        $ppdb = file_get_contents(app_path('Livewire/Ppdb/RegistrationForm.php'));

        $this->assertStringNotContainsString(
            "disk('public')",
            $ppdb,
            'Unggahan PPDB berpindah ke disk publik.',
        );

        // Dan konstanta disknya memang bukan disk publik.
        $this->assertNotSame(
            SiteSetting::MEDIA_DISK,
            config('ppdb.documents_disk', 'local'),
        );
    }

    /**
     * Tidak ada foto siswa dari sumber luar yang ikut terpasang.
     *
     * Satu-satunya berkas gambar di repositori adalah berkas merek yang
     * diserahkan pemilik. Halaman muka juga tidak memuat satu pun alamat gambar
     * dari domain lain — foto stok atau foto sekolah lain tidak boleh masuk
     * lewat pintu mana pun (butir 467).
     */
    public function test_tidak_ada_foto_dari_sumber_luar(): void
    {
        $gambar = collect(glob(public_path('images/**/*'), GLOB_BRACE))
            ->merge(glob(public_path('images/*')))
            ->filter(fn (string $path): bool => is_file($path))
            ->map(fn (string $path): string => basename($path))
            ->unique()
            ->values()
            ->all();

        $this->assertSame(['smart-sukses-school-zakat-sukses.webp'], $gambar);

        $html = $this->get('/')->assertOk()->getContent();

        preg_match_all('/<img[^>]+src="([^"]+)"/', $html, $matches);

        foreach ($matches[1] as $src) {
            $this->assertStringStartsWith(
                url('/'),
                $src,
                "Halaman muka memuat gambar dari luar: {$src}",
            );
        }
    }

    /**
     * Tidak ada halaman masuk per peran — bukan hanya tidak ada tautannya,
     * tetapi rutenya memang tidak pernah dibuat.
     */
    public function test_tidak_ada_rute_masuk_per_peran(): void
    {
        $uris = collect(app('router')->getRoutes())
            ->map(fn ($route): string => $route->uri())
            ->all();

        foreach (['login/siswa', 'login/guru', 'login/admin', 'login/ortu', 'masuk'] as $dikarang) {
            $this->assertNotContains($dikarang, $uris, "Rute masuk per peran ada: {$dikarang}");
        }

        $this->assertContains('login', $uris);
    }
}
