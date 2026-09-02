<?php

namespace Tests\Feature\PublicSite;

use App\Enums\SiteBlockType;
use App\Models\SiteBlock;
use App\Models\SiteSetting;
use App\Support\PublicSite;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PublicSiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Halaman muka publik V2 — isi, merek, dan tautan yang dapat disetel.
 *
 * Yang dijaga di sini bukan tata letaknya melainkan janji-janjinya: halaman ini
 * bercerita tentang sekolah dan bukan tentang perangkat lunaknya, tagline resmi
 * tampil kata demi kata, kedua unit dikenali pada jenjangnya masing-masing,
 * tidak ada satu angka pun yang mengaku data, dan alamat PPDB maupun blog
 * benar-benar mengikuti pengaturan alih-alih dipatri di template.
 *
 * Seluruh media di sini sintetis; tidak ada berkas sungguhan dan tidak ada data
 * pribadi siapa pun.
 */
class PublicLandingContentTest extends TestCase
{
    use RefreshDatabase;

    protected function html(): string
    {
        return $this->get('/')->assertOk()->getContent();
    }

    // ------------------------------------------------------------ terbuka

    public function test_halaman_muka_terbuka_tanpa_isi_apa_pun(): void
    {
        // Tanpa seeder, tanpa pengaturan, tanpa blok: bawaan dari konstanta
        // sudah cukup untuk merender halaman yang utuh (butir 466).
        $this->assertSame(0, SiteBlock::query()->count());
        $this->assertSame(0, SiteSetting::query()->count());

        $this->get('/')
            ->assertOk()
            ->assertSee(PublicSite::TAGLINE)
            ->assertSee(PublicSite::DEFAULTS['about_heading']);
    }

    /**
     * `php artisan db:seed` tidak boleh menerbitkan apa pun ke situs sekolah.
     *
     * PublicSiteSeeder aman isinya, tetapi menjalankannya berarti menerbitkan
     * enam baris galeri yang belum berfoto ke halaman yang dilihat publik.
     * Di produksi itu keputusan, bukan efek samping (butir 481).
     */
    public function test_seeder_bawaan_tidak_menerbitkan_isi_situs_publik(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, SiteBlock::query()->count());
        $this->assertSame(0, SiteSetting::query()->count());

        // Dan halamannya tetap terbuka serta utuh.
        $this->get('/')->assertOk()->assertSee(PublicSite::TAGLINE);
    }

    public function test_seeder_isi_publik_tetap_dapat_dipanggil_sendiri(): void
    {
        $this->seed(PublicSiteSeeder::class);

        $this->assertSame(2, SiteBlock::query()->where('type', SiteBlockType::Unit->value)->count());
        $this->assertSame(5, SiteBlock::query()->where('type', SiteBlockType::Program->value)->count());
        $this->assertSame(6, SiteBlock::query()->where('type', SiteBlockType::Gallery->value)->count());
    }

    /**
     * Karena seeder tidak berjalan otomatis, "belum ada isi" adalah keadaan
     * awal produksi yang normal — dan harus tampil rapi, bukan sebagai judul
     * bagian di atas ruang kosong (butir 482).
     */
    public function test_bagian_tanpa_isi_tidak_dirender_sama_sekali(): void
    {
        $html = $this->html();

        foreach (['unit', 'program', 'kegiatan', 'artikel'] as $kosong) {
            $this->assertStringNotContainsString(
                'id="'.$kosong.'"',
                $html,
                "Bagian {$kosong} dirender tanpa isi.",
            );
        }

        // Yang tidak bergantung basis data tetap ada, sehingga halamannya utuh.
        foreach (['konten', 'tentang', 'ppdb', 'akses', 'kontak'] as $tetap) {
            $this->assertStringContainsString('id="'.$tetap.'"', $html);
        }
    }

    public function test_menu_tidak_pernah_menunjuk_jangkar_yang_tidak_ada(): void
    {
        foreach ([false, true] as $berisi) {
            if ($berisi) {
                $this->seed(PublicSiteSeeder::class);
                SiteSetting::set('blog_url', 'https://blog.contoh.test');
            }

            $html = $this->html();

            preg_match_all('/href="#([a-z-]+)"/', $html, $matches);

            $mati = array_values(array_unique(array_filter(
                $matches[1],
                fn (string $anchor): bool => ! str_contains($html, 'id="'.$anchor.'"'),
            )));

            $this->assertSame([], $mati, 'Tautan menuju jangkar yang tidak ada: '.implode(', ', $mati));
        }
    }

    public function test_bagian_artikel_muncul_hanya_karena_alamat_blognya(): void
    {
        // Tanpa satu pratinjau pun, tautan blognya sendiri sudah menjadi isi
        // yang berguna.
        SiteSetting::set('blog_url', 'https://blog.contoh.test');

        $this->get('/')
            ->assertOk()
            ->assertSee('id="artikel"', false)
            ->assertSee('Baca semua artikel');
    }

    // -------------------------------------------------------------- merek

    public function test_tagline_pemilik_tampil_kata_demi_kata(): void
    {
        $this->assertSame(
            'Belajar dengan Hati, Tumbuh dengan Aksi, Sukses untuk Masa Depan.',
            PublicSite::TAGLINE,
        );

        $this->get('/')->assertOk()->assertSee(PublicSite::TAGLINE);
    }

    public function test_logo_bawaan_adalah_berkas_merek_yang_diserahkan_pemilik(): void
    {
        $html = $this->html();

        $this->assertStringContainsString(PublicSite::DEFAULT_LOGO, $html);
        $this->assertFileExists(public_path(PublicSite::DEFAULT_LOGO));
    }

    public function test_logo_dapat_diganti_dari_pengaturan(): void
    {
        SiteSetting::set('logo_path', 'site/logo-baru.webp');

        $html = $this->html();

        $this->assertStringContainsString('site/logo-baru.webp', $html);
        // Bawaan tidak lagi dipakai begitu pemilik mengunggah miliknya sendiri.
        $this->assertStringNotContainsString(PublicSite::DEFAULT_LOGO, $html);
    }

    // ---------------------------------------------------- fokus sekolahnya

    public function test_hero_berbicara_tentang_sekolah_bukan_perangkat_lunak(): void
    {
        $html = $this->html();

        $hero = $this->between($html, '<section class="hero">', 'id="tentang"');

        $this->assertStringContainsString(config('app.name'), $hero);
        $this->assertStringContainsString(PublicSite::TAGLINE, $hero);

        foreach (['modul', 'platform', 'dashboard', 'fitur', 'terintegrasi'] as $software) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b'.preg_quote($software, '/').'\b/i',
                strip_tags($hero),
                "Hero masih menjual perangkat lunak: {$software}",
            );
        }
    }

    public function test_tidak_ada_statistik_yang_belum_disahkan(): void
    {
        $this->seed(PublicSiteSeeder::class);

        $text = strip_tags($this->html());

        // Angka apa pun di dalam naskah halaman ini akan terbaca sebagai klaim
        // — jumlah siswa, jumlah alumni, tahun berdiri. Tidak satu pun sudah
        // disahkan sumber terkini, jadi tidak satu pun ditulis (butir 468).
        //
        // Tahun hak cipta di kaki halaman dikecualikan: ia dihasilkan dari jam
        // sistem, bukan klaim tentang sekolahnya.
        $tanpaFooter = substr($text, 0, strrpos($text, '©'));

        $this->assertDoesNotMatchRegularExpression(
            '/\d{2,}/',
            $tanpaFooter,
            'Ada angka yang dapat terbaca sebagai statistik sekolah.',
        );
    }

    // ---------------------------------------------------- unit pendidikan

    public function test_smart_building_dikenali_sebagai_unit_sma(): void
    {
        $this->seed(PublicSiteSeeder::class);

        $unit = SiteBlock::query()
            ->where('type', SiteBlockType::Unit->value)
            ->where('title', 'Smart Building')
            ->firstOrFail();

        $this->assertSame('Jenjang SMA', $unit->subtitle);

        $section = $this->between($this->html(), 'id="unit"', 'id="program"');

        $this->assertStringContainsString('Smart Building', $section);
        $this->assertStringContainsString('Jenjang SMA', $section);
    }

    public function test_smart_bee_dikenali_sebagai_unit_sd(): void
    {
        $this->seed(PublicSiteSeeder::class);

        $unit = SiteBlock::query()
            ->where('type', SiteBlockType::Unit->value)
            ->where('title', 'Smart Bee')
            ->firstOrFail();

        $this->assertSame('Jenjang SD', $unit->subtitle);

        $section = $this->between($this->html(), 'id="unit"', 'id="program"');

        $this->assertStringContainsString('Smart Bee', $section);
        $this->assertStringContainsString('Jenjang SD', $section);
    }

    public function test_kedua_unit_disajikan_di_bawah_smart_sukses_school_bukan_sebagai_mitra(): void
    {
        $this->seed(PublicSiteSeeder::class);

        $section = $this->between($this->html(), 'id="unit"', 'id="program"');

        // Penegasan pemilik: Smart Building bukan mitra (butir 473).
        $this->assertStringContainsString('Dua unit di bawah Smart Sukses School', $section);

        foreach (['mitra', 'partner', 'kerja sama dengan'] as $kata) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b'.preg_quote($kata, '/').'\b/i',
                strip_tags(str_replace('bukan mitra', '', $section)),
                "Unit pendidikan disajikan seolah mitra: {$kata}",
            );
        }
    }

    // ------------------------------------------------------ tautan disetel

    public function test_ajakan_ppdb_memakai_alamat_yang_disetel(): void
    {
        SiteSetting::set('ppdb_url', 'https://forms.gle/contoh-formulir');

        $html = $this->html();

        $this->assertStringContainsString('https://forms.gle/contoh-formulir', $html);
        // Tautan luar dibuka di tab baru dan tidak mewariskan konteks halaman.
        $this->assertStringContainsString('rel="noopener"', $html);
    }

    public function test_ajakan_ppdb_kembali_ke_halaman_ppdb_aplikasi_bila_belum_disetel(): void
    {
        $this->assertNull(SiteSetting::get('ppdb_url'));

        // Fungsi PPDB Laravel yang sudah ada tidak dilemahkan; ia justru
        // bawaannya (butir 471).
        $this->assertSame(route('ppdb.schools'), app(PublicSite::class)->ppdbUrl());

        $this->assertStringContainsString(
            'href="'.route('ppdb.schools').'"',
            $this->html(),
        );
    }

    public function test_ajakan_blog_memakai_alamat_yang_disetel(): void
    {
        SiteSetting::set('blog_url', 'https://blog.contoh.test');

        $this->get('/')
            ->assertOk()
            ->assertSee('https://blog.contoh.test', false)
            ->assertSee('Baca semua artikel');
    }

    public function test_tautan_blog_disembunyikan_bila_belum_disetel(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertDontSee('Baca semua artikel');
    }

    public function test_tidak_ada_nama_host_yang_dipatri_di_template(): void
    {
        $blade = file_get_contents(resource_path('views/landing.blade.php'))
            .file_get_contents(resource_path('views/layouts/landing.blade.php'));

        // Alamat blog dan PPDB berpindah domain; nama host yang tersebar di
        // template membuat perpindahan itu menjadi pekerjaan menyisir
        // (butir 470).
        foreach (['smartsukses.sch.id', 'blog.', 'forms.gle', 'docs.google.com'] as $host) {
            $this->assertStringNotContainsString($host, $blade, "Nama host dipatri di template: {$host}");
        }
    }

    // --------------------------------------------------------- satu pintu

    public function test_seluruh_akses_menunjuk_satu_halaman_masuk(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('href="'.route('login').'"', $html);

        foreach (['/login/siswa', '/login/guru', '/login/admin', '/masuk'] as $dikarang) {
            $this->assertStringNotContainsString('href="'.url($dikarang).'"', $html);
        }
    }

    // ------------------------------------------------- urutan & keterbitan

    public function test_blok_tampil_menurut_urutan_yang_disetel(): void
    {
        // Judul yang tidak mungkin muncul di naskah halaman: kata biasa seperti
        // "Kedua" juga hidup di dalam komentar CSS yang ikut terkirim, dan
        // pencarian posisi akan menemukan yang salah.
        foreach ([['ZZZ-Ketiga', 3], ['ZZZ-Pertama', 1], ['ZZZ-Kedua', 2]] as [$judul, $posisi]) {
            SiteBlock::create([
                'type' => SiteBlockType::Program->value,
                'title' => $judul,
                'position' => $posisi,
                'is_published' => true,
            ]);
        }

        $html = $this->html();

        $this->assertLessThan(strpos($html, 'ZZZ-Kedua'), strpos($html, 'ZZZ-Pertama'));
        $this->assertLessThan(strpos($html, 'ZZZ-Ketiga'), strpos($html, 'ZZZ-Kedua'));
    }

    public function test_blok_yang_tidak_terbit_tidak_pernah_tampil(): void
    {
        SiteBlock::create([
            'type' => SiteBlockType::Program->value,
            'title' => 'Program Tersembunyi',
            'is_published' => false,
        ]);

        $this->get('/')->assertOk()->assertDontSee('Program Tersembunyi');
    }

    // ---------------------------------------------------------- foto

    public function test_bidang_foto_tetap_utuh_ketika_fotonya_belum_ada(): void
    {
        $this->seed(PublicSiteSeeder::class);

        $html = $this->html();

        // Seluruh blok galeri bawaan memang belum berfoto — keadaan normal
        // hari ini, bukan pengecualian (butir 467).
        $this->assertSame(
            0,
            SiteBlock::query()->whereNotNull('image_path')->count(),
        );

        $this->assertStringContainsString('photo__ph', $html);
        $this->assertStringContainsString('Foto menyusul', $html);
    }

    public function test_foto_yang_diunggah_menggantikan_penandanya(): void
    {
        SiteBlock::create([
            'type' => SiteBlockType::Gallery->value,
            'title' => 'Kegiatan Contoh',
            'image_path' => 'site/contoh.webp',
            'is_published' => true,
        ]);

        $section = $this->between($this->html(), 'id="kegiatan"', 'id="artikel"');

        $this->assertStringContainsString('site/contoh.webp', $section);
        $this->assertStringNotContainsString('photo__ph', $section);
    }

    // ------------------------------------------------------- global, bukan tenant

    public function test_isi_halaman_muka_tidak_terikat_cabang_mana_pun(): void
    {
        // Tidak ada kolom school_id sama sekali — bukan kolom nullable yang
        // kebetulan kosong (butir 464).
        $this->assertFalse(
            Schema::hasColumn('site_blocks', 'school_id'),
        );

        $this->assertFalse(
            Schema::hasColumn('site_settings', 'school_id'),
        );
    }

    public function test_halaman_muka_tidak_menyentuh_tabel_cabang(): void
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->get('/')->assertOk();

        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('`schools`', $sql, "Halaman muka membaca tabel cabang: {$sql}");
        }
    }

    // ------------------------------------------------------- struktur responsif

    public function test_tata_letak_satu_kolom_lebih_dulu_tanpa_luberan_mendatar(): void
    {
        $css = $this->between($this->html(), '<style>', '</style>');

        $this->assertStringContainsString('overflow-x: hidden', $css);
        $this->assertStringContainsString('max-width:100%', str_replace(' ', '', $css));

        // Kolom jamak selalu di balik media query, tidak pernah menjadi bawaan.
        $galeri = $this->between($css, '.gallery {', '.gallery__item {');
        $this->assertStringContainsString('grid-template-columns: 1fr;', $galeri);
    }

    protected function between(string $haystack, string $from, string $to): string
    {
        $start = strpos($haystack, $from);

        $this->assertIsInt($start, "Penanda tidak ditemukan: {$from}");

        $end = strpos($haystack, $to, $start);

        return substr($haystack, $start, ($end === false ? strlen($haystack) : $end) - $start);
    }
}
