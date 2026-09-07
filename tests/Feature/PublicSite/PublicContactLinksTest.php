<?php

namespace Tests\Feature\PublicSite;

use App\Enums\RoleName;
use App\Filament\Pages\PengaturanSitusPublik;
use App\Models\School;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\PublicSite;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tautan keluar halaman muka: peta, media sosial, blog (M8).
 *
 * Satu aturan untuk seluruhnya. Sebelum M8 hanya `ppdb_url` yang diperiksa,
 * karena hanya ia yang menjadi pengalihan server; sisanya dicetak apa adanya
 * sebagai `href` — padahal `href` berisi `javascript:` tetap berjalan ketika
 * seseorang menekannya, dan yang mengisi kolom-kolom itu adalah panel admin
 * yang sama (butir 563).
 *
 * Seluruh alamat pada berkas ini sintetis.
 */
class PublicContactLinksTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        School::factory()->create(['code' => 'PUSAT', 'is_active' => true]);

        $this->superAdmin = User::factory()->superAdmin()->create();
    }

    // ------------------------------------------------------------- peta lokasi

    public function test_super_admin_dapat_menyimpan_tautan_peta(): void
    {
        Livewire::actingAs($this->superAdmin)
            ->test(PengaturanSitusPublik::class)
            ->set('data.contact_address', 'Jalan Contoh No. 1')
            ->set('data.contact_maps_url', 'https://maps.example.test/lokasi')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('https://maps.example.test/lokasi', SiteSetting::get('contact_maps_url'));
    }

    public function test_tombol_peta_muncul_hanya_ketika_tautannya_ada(): void
    {
        SiteSetting::set('contact_address', 'Jalan Contoh No. 1');

        // Belum disetel: alamatnya tampil, tombolnya tidak.
        $html = $this->get(route('landing'))->assertOk()->getContent();
        $this->assertStringContainsString('Jalan Contoh No. 1', $html);
        $this->assertStringNotContainsString('Buka di peta', $html);

        SiteSetting::set('contact_maps_url', 'https://maps.example.test/lokasi');

        $html = $this->get(route('landing'))->assertOk()->getContent();
        $this->assertStringContainsString('Buka di peta', $html);
        $this->assertStringContainsString('href="https://maps.example.test/lokasi"', $html);
        $this->assertStringContainsString('rel="noopener"', $html);
    }

    public function test_alamat_tidak_pernah_dirangkai_menjadi_pencarian_peta(): void
    {
        // Alamat yang diketik manusia sering tidak persis sama dengan yang
        // dikenali peta; pencarian yang meleset lebih buruk daripada tidak ada
        // tautan sama sekali (butir 564).
        SiteSetting::set('contact_address', 'Jalan Contoh No. 1');

        $html = $this->get(route('landing'))->assertOk()->getContent();

        $this->assertStringNotContainsString('maps.google', $html);
        $this->assertStringNotContainsString('google.com/maps', $html);
        $this->assertStringNotContainsString('?q=Jalan', $html);
    }

    // -------------------------------------------------------- keamanan alamat

    /**
     * @param  string  $key  kunci site_settings yang diisi nilai tidak aman
     */
    #[DataProvider('outboundKeys')]
    public function test_alamat_tidak_aman_tidak_pernah_menjadi_href(string $key): void
    {
        foreach (['javascript:alert(1)', 'data:text/html,<script>', 'file:///etc/passwd', 'bukan alamat'] as $unsafe) {
            SiteSetting::set($key, $unsafe);

            $html = $this->get(route('landing'))->assertOk()->getContent();

            $this->assertStringNotContainsString('href="'.$unsafe.'"', $html, "{$key} = {$unsafe}");
            $this->assertStringNotContainsString('javascript:', $html);
        }
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function outboundKeys(): array
    {
        return [
            ['contact_maps_url'],
            ['social_instagram'],
            ['social_facebook'],
            ['social_youtube'],
            ['blog_url'],
        ];
    }

    public function test_alamat_yang_ditolak_diperlakukan_sama_dengan_belum_disetel(): void
    {
        SiteSetting::set('social_instagram', 'javascript:alert(1)');
        SiteSetting::set('contact_maps_url', 'data:text/html,x');
        SiteSetting::set('blog_url', 'ftp://contoh.test');

        $site = app(PublicSite::class);

        $this->assertNull($site->social('instagram'));
        $this->assertNull($site->mapsUrl());
        $this->assertNull($site->blogUrl());

        // Bukan galat, dan bukan ikon kosong: halamannya tetap terbuka.
        $this->get(route('landing'))->assertOk()->assertDontSee('Buka di peta');
    }

    public function test_alamat_http_dan_https_diterima(): void
    {
        SiteSetting::set('social_instagram', 'https://instagram.example.test/sekolah');
        SiteSetting::set('contact_maps_url', 'http://maps.example.test/lokasi');

        $site = app(PublicSite::class);

        $this->assertSame('https://instagram.example.test/sekolah', $site->social('instagram'));
        $this->assertSame('http://maps.example.test/lokasi', $site->mapsUrl());
    }

    // ------------------------------------------------ kolom kosong & tata letak

    public function test_mengosongkan_kolom_opsional_tidak_merusak_halaman_muka(): void
    {
        foreach ([
            'contact_address', 'contact_phone', 'contact_email', 'contact_maps_url',
            'social_instagram', 'social_facebook', 'social_youtube',
            'blog_url', 'ppdb_url', 'hero_image_path', 'logo_path',
        ] as $key) {
            SiteSetting::set($key, null);
        }

        $html = $this->get(route('landing'))->assertOk()->getContent();

        // Tidak ada satu pun baris kontak atau ikon sosial yang dirender kosong.
        $this->assertStringNotContainsString('href=""', $html);
        $this->assertStringNotContainsString('mailto:"', $html);
        $this->assertStringNotContainsString('tel:"', $html);

        // Logo jatuh ke berkas bawaan, bukan ke src kosong.
        $this->assertStringNotContainsString('src=""', $html);

        // CTA PPDB jatuh ke halaman PPDB aplikasi ini.
        $this->assertStringContainsString(route('ppdb.schools'), $html);
    }

    public function test_surel_dan_telepon_dirender_sebagai_tautan_yang_dapat_ditekan(): void
    {
        SiteSetting::set('contact_email', 'kontak@example.test');
        SiteSetting::set('contact_phone', '0800000000');

        $html = $this->get(route('landing'))->assertOk()->getContent();

        $this->assertStringContainsString('mailto:kontak@example.test', $html);
        $this->assertStringContainsString('tel:0800000000', $html);
    }

    public function test_bingkai_foto_kosong_tidak_menjanjikan_apa_pun(): void
    {
        // Sejak sekolah menyerahkan koleksi fotonya, "Foto menyusul" pada slot
        // yang kebetulan belum diisi menjadi janji, bukan keterangan
        // (butir 565).
        $html = $this->get(route('landing'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Foto menyusul', $html);
        $this->assertStringNotContainsString('Photo coming soon', $html);

        // Bingkainya tetap ada, sehingga tata letaknya tidak bergeser.
        $this->assertStringContainsString('photo__ph', $html);
    }

    // ------------------------------------------------------------- batas peran

    public function test_admin_sekolah_tetap_tidak_dapat_menyunting_tautan_payung(): void
    {
        $school = School::query()->firstOrFail();

        $admin = User::factory()
            ->forSchool($school)
            ->withRole(RoleName::SchoolAdmin)
            ->create();

        $this->actingAs($admin);

        // Batas yang sudah ada tidak dilonggarkan oleh kolom baru: isi situs
        // payung bukan milik satu cabang (butir 469).
        $this->assertFalse(PengaturanSitusPublik::canAccess());
    }
}
