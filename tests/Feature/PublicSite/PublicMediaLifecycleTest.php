<?php

namespace Tests\Feature\PublicSite;

use App\Enums\SiteBlockType;
use App\Models\School;
use App\Models\SiteBlock;
use App\Models\SiteSetting;
use App\Support\PublicSite;
use App\Support\SchoolBranding;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Media publik: berkas yang hilang jatuh ke bawaannya, dan berkas yang diganti
 * tidak menjadi yatim (butir 587).
 *
 * Seluruh berkas di sini sintetis.
 */
class PublicMediaLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(SiteSetting::MEDIA_DISK);
    }

    protected function disk(): Filesystem
    {
        return Storage::disk(SiteSetting::MEDIA_DISK);
    }

    // ----------------------------------------------------- berkas yang hilang

    public function test_blok_yang_berkasnya_hilang_tidak_mengaku_punya_gambar(): void
    {
        $block = SiteBlock::create([
            'type' => SiteBlockType::Gallery->value,
            'title' => 'Kegiatan Sintetis',
            'image_path' => 'site/hilang.jpg',
            'is_published' => true,
        ]);

        $this->assertFalse($block->hasImage());
        $this->assertNull($block->imageUrl());

        $this->disk()->put('site/hilang.jpg', 'gambar-sintetis');

        $this->assertTrue($block->hasImage());
        $this->assertStringEndsWith('/storage/site/hilang.jpg', (string) $block->imageUrl());
    }

    public function test_halaman_muka_merender_penanda_untuk_blok_yang_berkasnya_hilang(): void
    {
        SiteBlock::create([
            'type' => SiteBlockType::Gallery->value,
            'title' => 'Kegiatan Sintetis',
            'image_path' => 'site/hilang.webp',
            'is_published' => true,
        ]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('site/hilang.webp', $html);
        $this->assertStringContainsString('photo__ph', $html);
    }

    public function test_blok_dengan_jalur_di_luar_direktori_media_tidak_disajikan(): void
    {
        $this->disk()->put('schools/logos/logo.png', 'logo-cabang');

        $block = new SiteBlock(['image_path' => 'schools/logos/logo.png']);

        $this->assertNull($block->imageUrl());
    }

    public function test_logo_situs_yang_berkasnya_hilang_jatuh_ke_logo_bawaan(): void
    {
        SiteSetting::set('logo_path', 'site/logo-hilang.png');

        $this->assertSame(asset(PublicSite::DEFAULT_LOGO), app(PublicSite::class)->logoUrl());

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(PublicSite::DEFAULT_LOGO, $html);
        $this->assertStringNotContainsString('site/logo-hilang.png', $html);
    }

    public function test_gambar_utama_yang_berkasnya_hilang_tidak_dirender(): void
    {
        SiteSetting::set('hero_image_path', 'site/utama-hilang.jpg');

        $this->assertNull(app(PublicSite::class)->heroImageUrl());

        $this->disk()->put('site/utama-hilang.jpg', 'gambar-sintetis');

        $this->assertStringEndsWith('/storage/site/utama-hilang.jpg', (string) app(PublicSite::class)->heroImageUrl());
    }

    public function test_logo_cabang_yang_berkasnya_hilang_jatuh_ke_tanpa_logo(): void
    {
        $school = School::factory()->create(['logo_url' => 'schools/logos/hilang.png']);

        $this->assertNull(app(SchoolBranding::class)->logoUrl($school));

        $this->disk()->put('schools/logos/hilang.png', 'logo-sintetis');

        $this->assertStringEndsWith('/storage/schools/logos/hilang.png', (string) app(SchoolBranding::class)->logoUrl($school));
    }

    public function test_logo_cabang_berupa_url_penuh_tetap_diteruskan(): void
    {
        $school = School::factory()->create(['logo_url' => 'https://cdn.example.test/logo.png']);

        $this->assertSame('https://cdn.example.test/logo.png', app(SchoolBranding::class)->logoUrl($school));
    }

    // ------------------------------------------------ logo & gambar utama situs

    public function test_logo_situs_lama_terhapus_sesudah_diganti(): void
    {
        $this->disk()->put('site/logo-lama.png', 'lama');
        $this->disk()->put('site/logo-baru.png', 'baru');

        SiteSetting::set('logo_path', 'site/logo-lama.png');
        SiteSetting::set('logo_path', 'site/logo-baru.png');

        $this->disk()->assertMissing('site/logo-lama.png');
        $this->disk()->assertExists('site/logo-baru.png');
    }

    public function test_gambar_utama_yang_dikosongkan_ikut_terhapus(): void
    {
        $this->disk()->put('site/utama.jpg', 'utama');

        SiteSetting::set('hero_image_path', 'site/utama.jpg');
        SiteSetting::set('hero_image_path', null);

        $this->disk()->assertMissing('site/utama.jpg');
    }

    public function test_berkas_yang_masih_dirujuk_kunci_lain_tidak_dihapus(): void
    {
        $this->disk()->put('site/bersama.png', 'bersama');

        SiteSetting::set('logo_path', 'site/bersama.png');
        SiteSetting::set('hero_image_path', 'site/bersama.png');
        SiteSetting::set('logo_path', null);

        $this->disk()->assertExists('site/bersama.png');
    }

    public function test_kunci_teks_tidak_pernah_menghapus_berkas(): void
    {
        // Nilai teks yang kebetulan berbentuk jalur bukan milik disk media.
        $this->disk()->put('site/kebetulan.png', 'jangan-dihapus');

        SiteSetting::set('hero_heading', 'site/kebetulan.png');
        SiteSetting::set('hero_heading', 'Judul Sintetis');

        $this->disk()->assertExists('site/kebetulan.png');
    }

    public function test_logo_bawaan_tidak_pernah_dihapus(): void
    {
        SiteSetting::set('logo_path', PublicSite::DEFAULT_LOGO);
        SiteSetting::set('logo_path', null);

        $this->assertFileExists(public_path(PublicSite::DEFAULT_LOGO));
    }

    public function test_berkas_lama_bertahan_bila_penggantian_dibatalkan(): void
    {
        $this->disk()->put('site/logo-lama.png', 'lama');
        SiteSetting::set('logo_path', 'site/logo-lama.png');

        try {
            DB::transaction(function (): void {
                SiteSetting::set('logo_path', 'site/logo-baru.png');

                throw new RuntimeException('penyimpanan gagal');
            });
        } catch (RuntimeException) {
            // Yang diuji justru akibat pembatalannya.
        }

        $this->assertSame('site/logo-lama.png', SiteSetting::get('logo_path'));
        $this->disk()->assertExists('site/logo-lama.png');
    }

    public function test_gambar_blok_lama_bertahan_bila_penggantian_dibatalkan(): void
    {
        $this->disk()->put('site/blok-lama.jpg', 'lama');

        $block = SiteBlock::create([
            'type' => SiteBlockType::Gallery->value,
            'title' => 'Kegiatan Sintetis',
            'image_path' => 'site/blok-lama.jpg',
        ]);

        try {
            DB::transaction(function () use ($block): void {
                $block->update(['image_path' => 'site/blok-baru.jpg']);

                throw new RuntimeException('penyimpanan gagal');
            });
        } catch (RuntimeException) {
            // Yang diuji justru akibat pembatalannya.
        }

        $this->disk()->assertExists('site/blok-lama.jpg');
    }

    // --------------------------------------------------------------- logo cabang

    public function test_logo_cabang_lama_terhapus_sesudah_diganti(): void
    {
        $this->disk()->put('schools/logos/lama.png', 'lama');
        $this->disk()->put('schools/logos/baru.png', 'baru');

        $school = School::factory()->create(['logo_url' => 'schools/logos/lama.png']);
        $school->update(['logo_url' => 'schools/logos/baru.png']);

        $this->disk()->assertMissing('schools/logos/lama.png');
        $this->disk()->assertExists('schools/logos/baru.png');
    }

    public function test_logo_cabang_lain_tidak_pernah_terhapus(): void
    {
        $this->disk()->put('schools/logos/bersama.png', 'bersama');

        $school = School::factory()->create(['logo_url' => 'schools/logos/bersama.png']);
        School::factory()->create(['logo_url' => 'schools/logos/bersama.png']);

        $school->update(['logo_url' => null]);

        $this->disk()->assertExists('schools/logos/bersama.png');
    }

    public function test_logo_cabang_di_luar_direktorinya_tidak_dihapus(): void
    {
        $this->disk()->put('site/logo.png', 'logo-situs');

        $school = School::factory()->create(['logo_url' => 'site/logo.png']);
        $school->update(['logo_url' => null]);

        $this->disk()->assertExists('site/logo.png');
    }
}
