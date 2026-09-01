<?php

namespace Tests\Feature\Landing;

use App\Enums\RoleName;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\SchoolBranding;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Halaman muka publik.
 *
 * Tambahan langsung atas permintaan pemilik (docs/owner-scope-changes.md bagian
 * A), bukan bagian Sprint 9 versi roadmap.
 *
 * Dua hal yang paling mudah salah pada halaman seperti ini, dan karena itu
 * diuji tersendiri: ia harus terbuka **tanpa sesi apa pun**, dan ia tidak boleh
 * memakai white-label satu cabang seolah-olah itu identitas seluruh platform
 * (butir 344).
 */
class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------- terbuka

    public function test_the_landing_page_opens_for_a_guest(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_the_landing_route_is_named(): void
    {
        $this->assertSame('/', route('landing', absolute: false));
        $this->get(route('landing'))->assertOk();
    }

    /**
     * Tanpa satu baris pun di database — bukan cabang, bukan pengguna, bukan
     * tahun ajaran. Halaman muka tidak boleh menuntut konteks tenant apa pun.
     */
    public function test_the_landing_page_opens_with_an_empty_database(): void
    {
        $this->assertSame(0, School::query()->count());
        $this->assertSame(0, User::query()->count());

        $this->get('/')
            ->assertOk()
            ->assertSee('Belum ada cabang yang membuka pendaftaran saat ini.');
    }

    public function test_the_landing_page_never_redirects_to_a_login(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $this->assertFalse($response->isRedirect());
    }

    public function test_the_stock_laravel_welcome_page_is_gone(): void
    {
        $this->assertFileDoesNotExist(resource_path('views/welcome.blade.php'));

        $html = $this->get('/')->assertOk()->getContent();

        foreach (['Laravel', 'laravel.com', 'Documentation', 'Laracasts', 'fonts.bunny.net'] as $stock) {
            $this->assertStringNotContainsString($stock, $html, "Sisa halaman bawaan Laravel: {$stock}");
        }
    }

    // ------------------------------------------------------------ identitas

    public function test_the_platform_branding_is_visible(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(config('app.name'))
            ->assertSee('Platform Manajemen Sekolah Terintegrasi');
    }

    /**
     * Butir 344 — inti keamanan tampilan halaman ini.
     *
     * Bila seorang pengguna cabang sedang login lalu membuka `/`, halaman muka
     * umum tidak boleh berubah menjadi warna sekolahnya. Yang dipakai konstanta
     * platform, bukan `SchoolBranding::currentSchool()`.
     */
    public function test_the_landing_page_never_wears_one_branch_white_label(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $school = School::factory()->create([
            'name' => 'SMP Madani',
            'primary_color' => '#AA1122',
            'secondary_color' => '#22BB33',
        ]);

        $admin = User::factory()->forSchool($school)->withRole(RoleName::SchoolAdmin)->create();

        $html = $this->actingAs($admin)->get('/')->assertOk()->getContent();

        // Warna cabang tidak ikut sama sekali.
        $this->assertStringNotContainsString('#AA1122', $html);
        $this->assertStringNotContainsString('#22BB33', $html);

        // Yang dipakai warna platform.
        $this->assertStringContainsString(SchoolBranding::FALLBACK_PRIMARY, $html);
        $this->assertStringContainsString(SchoolBranding::FALLBACK_SECONDARY, $html);
    }

    public function test_the_landing_page_looks_the_same_to_a_guest_and_to_a_logged_in_user(): void
    {
        $this->seed(RolePermissionSeeder::class);

        School::factory()->create(['name' => 'SMP Madani', 'code' => 'MDN']);

        $guest = $this->get('/')->assertOk()->getContent();

        $admin = User::factory()
            ->forSchool(School::query()->firstOrFail())
            ->withRole(RoleName::SchoolAdmin)
            ->create();

        $authenticated = $this->actingAs($admin)->get('/')->assertOk()->getContent();

        // Yang dibandingkan **blok warnanya**, bukan seluruh dokumen.
        //
        // Membandingkan seluruh HTML pernah lulus dan kemudian gagal tergantung
        // urutan test: Livewire menyuntikkan aset dan token CSRF-nya sendiri ke
        // dalam balasan HTML utuh, dan sudah-belumnya penyuntikan itu terjadi
        // berbeda antar proses. Tidak satu pun dari itu ada hubungannya dengan
        // yang sedang dibuktikan — yaitu bahwa identitas visualnya tidak
        // berubah ketika ada pengguna cabang yang login (butir 366).
        $this->assertSame(
            $this->brandingBlockOf($guest),
            $this->brandingBlockOf($authenticated),
            'Warna halaman muka berubah ketika ada yang login.',
        );
    }

    /**
     * Blok `:root { ... }` — tempat seluruh warna halaman muka ditetapkan.
     */
    protected function brandingBlockOf(string $html): string
    {
        preg_match('/:root\s*\{(.*?)\}/s', $html, $matches);

        $this->assertNotEmpty($matches[1] ?? '', 'Blok warna tidak ditemukan di halaman.');

        return preg_replace('/\s+/', ' ', trim($matches[1]));
    }

    // ---------------------------------------------------- pintu masuk peran

    /**
     * Keputusan pemilik: satu pintu masuk untuk semua peran. Ketiga kartu peran
     * karena itu menunjuk alamat yang sama, dan PPDB tetap punya alamatnya
     * sendiri (butir 437).
     */
    public function test_every_role_entry_point_is_linked(): void
    {
        $response = $this->get('/')->assertOk();

        foreach ([
            route('login'),
            route('ppdb.schools'),
            route('ppdb.check-status'),
        ] as $url) {
            $response->assertSee('href="'.$url.'"', false);
        }
    }

    public function test_the_entry_point_urls_are_the_real_ones(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        foreach (['/login', '/ppdb'] as $path) {
            $this->assertStringContainsString('href="'.url($path).'"', $html, "Tautan {$path} tidak ada.");
        }

        // Alamat lama tetap dapat dibuka, tetapi halaman muka tidak lagi
        // mengirim siapa pun ke sana.
        foreach (['/siswa/masuk', '/portal/masuk', '/admin/login'] as $legacy) {
            $this->assertStringNotContainsString('href="'.url($legacy).'"', $html);
            $this->get($legacy)->assertRedirect();
        }
    }

    /**
     * Premis terbalik, dan disengaja.
     *
     * Sampai batch ini `/login` memang tidak ada, dan tes ini menjaga agar
     * halaman muka tidak berpura-pura ada — sistemnya punya tiga pintu, dan
     * mengarang pintu keempat akan menyesatkan (butir 349).
     *
     * Keputusan pemilik membalikkannya: kini benar-benar ada satu pintu, jadi
     * yang dijaga berbalik pula — halaman muka harus menunjuk ke sana, dan
     * tidak boleh menawarkan pintu per peran (butir 446).
     */
    public function test_the_landing_points_at_the_single_login_door(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertNotNull(app('router')->getRoutes()->getByName('login'));
        $this->assertStringContainsString('href="'.url('/login').'"', $html);
        $this->assertStringContainsString('Masuk ke Sistem', $html);

        // Tidak ada alamat masuk per peran yang dikarang.
        foreach (['/login/siswa', '/login/admin', '/login/guru', '/masuk'] as $invented) {
            $this->assertStringNotContainsString('href="'.url($invented).'"', $html);
        }
    }

    public function test_the_role_labels_are_understandable(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Admin &amp; Guru', false)
            ->assertSee('Portal Siswa')
            ->assertSee('Portal Orang Tua')
            ->assertSee('Pendaftaran (PPDB)');
    }

    // ------------------------------------------------------------- cabang

    public function test_active_branches_are_listed_with_a_registration_link(): void
    {
        School::factory()->create(['name' => 'SMP Madani', 'code' => 'MDN']);
        School::factory()->create(['name' => 'SMP Cinangka', 'code' => 'CNK']);

        $response = $this->get('/')->assertOk();

        $response->assertSee('SMP Madani');
        $response->assertSee('SMP Cinangka');
        $response->assertSee('href="'.route('ppdb.register', ['schoolCode' => 'mdn']).'"', false);
        $response->assertSee('href="'.route('ppdb.register', ['schoolCode' => 'cnk']).'"', false);
    }

    /**
     * Perlakuan cabang nonaktif harus sama persis dengan halaman PPDB publik
     * yang sudah ada — keduanya memakai `School::active()` (butir 352).
     */
    public function test_an_inactive_branch_is_hidden_exactly_as_on_the_ppdb_page(): void
    {
        School::factory()->create(['name' => 'SMP Aktif', 'code' => 'AKT', 'is_active' => true]);
        School::factory()->create(['name' => 'SMP Nonaktif', 'code' => 'NON', 'is_active' => false]);

        $landing = $this->get('/')->assertOk();
        $landing->assertSee('SMP Aktif');
        $landing->assertDontSee('SMP Nonaktif');

        $ppdb = $this->get(route('ppdb.schools'))->assertOk();
        $ppdb->assertSee('SMP Aktif');
        $ppdb->assertDontSee('SMP Nonaktif');
    }

    public function test_branch_ordering_follows_the_ppdb_page(): void
    {
        foreach (['Zeta', 'Alfa', 'Mega'] as $name) {
            School::factory()->create(['name' => 'SMP '.$name]);
        }

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'SMP Mega'), strpos($html, 'SMP Alfa'));
        $this->assertLessThan(strpos($html, 'SMP Zeta'), strpos($html, 'SMP Mega'));
    }

    // ------------------------------------------------------ privasi & aman

    public function test_no_private_branch_data_reaches_the_page(): void
    {
        School::factory()->create([
            'name' => 'SMP Madani',
            'code' => 'MDN',
            'phone' => '02177889900',
            'email' => 'rahasia-admin@sekolah.test',
            'wa_template_ppdb' => 'RAHASIA-TEMPLATE-PPDB',
            'wa_template_spp' => 'RAHASIA-TEMPLATE-SPP',
            'head_name' => 'Nama Kepala Rahasia',
        ]);

        $html = $this->get('/')->assertOk()->getContent();

        foreach ([
            '02177889900',
            'rahasia-admin@sekolah.test',
            'RAHASIA-TEMPLATE-PPDB',
            'RAHASIA-TEMPLATE-SPP',
            'Nama Kepala Rahasia',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $html, "Data privat bocor: {$secret}");
        }
    }

    public function test_no_user_or_student_data_reaches_the_page(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $school = School::factory()->create(['name' => 'SMP Madani', 'code' => 'MDN']);

        $admin = User::factory()->forSchool($school)->withRole(RoleName::SchoolAdmin)->create([
            'name' => 'Admin Rahasia',
            'email' => 'admin-rahasia@sekolah.test',
        ]);

        $student = Student::factory()->create([
            'school_id' => $school->getKey(),
            'full_name' => 'Siswa Rahasia',
            'nis' => '987654',
        ]);

        $html = $this->get('/')->assertOk()->getContent();

        foreach ([$admin->name, $admin->email, $student->full_name, $student->nis] as $secret) {
            $this->assertStringNotContainsString($secret, $html, "Data pengguna bocor: {$secret}");
        }
    }

    public function test_the_page_renders_no_unescaped_database_copy(): void
    {
        School::factory()->create([
            'name' => 'SMP <script>alert(1)</script>',
            'code' => 'XSS',
            'address' => 'Jalan <b>Tebal</b> No. 1',
        ]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<b>Tebal</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // ------------------------------------------------------------- aset

    /**
     * Butir 345 — halaman ini tidak menuntut `npm run build`. Tidak ada rujukan
     * Vite dan tidak ada berkas dari `public/build`, yang memang tidak pernah
     * ada di project ini.
     */
    public function test_the_page_needs_no_build_step(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('/build/', $html);
        $this->assertStringNotContainsString('@vite', $html);
        $this->assertStringNotContainsString('localhost:5173', $html);
        $this->assertDirectoryDoesNotExist(public_path('build'));
    }

    public function test_every_referenced_asset_exists(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        preg_match_all('/(?:href|src)="([^"#]+\.(?:css|js|ico|png|jpg|jpeg|svg|webp))"/i', $html, $matches);

        $this->assertNotEmpty($matches[1], 'Halaman tidak merujuk satu aset pun.');

        foreach (array_unique($matches[1]) as $url) {
            $path = public_path(ltrim(parse_url($url, PHP_URL_PATH) ?? '', '/'));

            $this->assertFileExists($path, "Aset dirujuk tetapi tidak ada: {$url}");
        }
    }

    // ------------------------------------------------ struktur & aksesibilitas

    public function test_the_page_has_one_heading_one_and_the_expected_sections(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<h1>'), 'Halaman harus punya tepat satu <h1>.');

        foreach (['id="tentang"', 'id="fitur"', 'id="akses"', 'id="cabang"', 'id="konten"'] as $anchor) {
            $this->assertStringContainsString($anchor, $html, "Bagian {$anchor} tidak ada.");
        }
    }

    public function test_the_page_carries_the_basics_of_accessibility(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('lang="id"', $html);
        $this->assertStringContainsString('Lompat ke konten utama', $html);
        $this->assertStringContainsString('aria-label="Navigasi utama"', $html);
        $this->assertStringContainsString('focus-visible', $html);
        // Ikon hiasan disembunyikan dari pembaca layar, dan setiap kartu akses
        // punya nama yang terbaca.
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('sr-only', $html);
    }

    public function test_the_page_is_built_mobile_first_without_horizontal_overflow(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('width=device-width, initial-scale=1', $html);
        $this->assertStringContainsString('overflow-x: hidden', $html);
        // Kolom melebar hanya pada layar yang cukup lebar; bawaannya satu kolom.
        $this->assertStringContainsString('grid-template-columns: 1fr;', $html);
        $this->assertStringContainsString('@media (min-width: 48rem)', $html);
        // Sasaran sentuh sebesar jari, konvensi yang sama dengan portal.
        $this->assertStringContainsString('min-height: 2.75rem', $html);
    }

    // ------------------------------------------------------------ performa

    public function test_the_page_costs_a_bounded_number_of_queries(): void
    {
        School::factory()->count(1)->create();

        $this->get('/');

        $withOne = $this->countQueries(fn () => $this->get('/')->assertOk());

        School::factory()->count(5)->create();

        $withSix = $this->countQueries(fn () => $this->get('/')->assertOk());

        $this->assertSame(
            $withOne,
            $withSix,
            "Halaman muka membayar query tambahan per cabang: {$withOne} untuk satu, {$withSix} untuk enam.",
        );

        // Dan jumlahnya memang kecil: satu query untuk daftar cabang.
        $this->assertLessThanOrEqual(2, $withSix, "Halaman muka menjalankan {$withSix} query.");
    }

    // -------------------------------------------- permukaan lain tak berubah

    public function test_the_api_surface_is_untouched(): void
    {
        $api = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
            ->count();

        $this->assertSame(41, $api);
    }

    public function test_the_student_portal_surface_is_untouched(): void
    {
        $siswa = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'siswa'))
            ->count();

        $this->assertSame(11, $siswa);
    }

    public function test_every_existing_portal_route_name_still_resolves(): void
    {
        foreach ([
            'ppdb.schools', 'ppdb.check-status', 'ppdb.register',
            'portal.login', 'portal.dashboard', 'portal.grades', 'portal.fees',
            'portal.schedule', 'portal.notifications', 'portal.logout',
            'student.login', 'student.dashboard', 'student.schedule', 'student.grades',
            'student.profile', 'student.notifications', 'student.logout',
            'student.exams', 'student.exam', 'student.exam-result',
            'teacher.dashboard', 'teacher.classes', 'teacher.schedule', 'teacher.notifications',
            'filament.admin.auth.login',
        ] as $name) {
            $this->assertNotNull(
                app('router')->getRoutes()->getByName($name),
                "Rute {$name} hilang setelah halaman muka ditambahkan.",
            );
        }
    }

    public function test_the_other_public_and_guarded_doors_still_behave(): void
    {
        // PPDB tetap publik.
        $this->get(route('ppdb.schools'))->assertOk();
        $this->get(route('ppdb.check-status'))->assertOk();

        // Pintu masuk tunggal terbuka untuk tamu.
        $this->get(route('login'))->assertOk();

        // Alamat masuk lama tidak menjadi 404; keduanya mengantar ke sana.
        $this->get(route('filament.admin.auth.login'))->assertRedirect(route('login'));
        $this->get('/siswa/masuk')->assertRedirect(route('login'));
        $this->get('/portal/masuk')->assertRedirect(route('login'));

        // Portal tetap tertutup bagi tamu.
        $this->get(route('student.exams'))->assertRedirect(route('student.login'));
        $this->get(route('portal.dashboard'))->assertRedirect(route('portal.login'));
    }

    // ==================================================== Batch L2 — tata letak

    /**
     * Butir 418 — keputusan tata letak yang paling menentukan pada batch ini.
     *
     * Pertanyaan pertama pengunjung halaman muka sekolah bukan "apa saja
     * fiturnya", melainkan "saya masuk lewat mana". Karena itu bagian Akses
     * Pengguna berada tepat di bawah hero, sebelum daftar fitur maupun daftar
     * cabang — dan urutannya diuji, bukan sekadar disepakati.
     */
    public function test_the_access_section_comes_before_features_and_branches(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $akses = strpos($html, 'id="akses"');
        $fitur = strpos($html, 'id="fitur"');
        $cabang = strpos($html, 'id="cabang"');

        $this->assertIsInt($akses, 'Bagian akses tidak ada.');
        $this->assertIsInt($fitur, 'Bagian fitur tidak ada.');
        $this->assertIsInt($cabang, 'Bagian cabang tidak ada.');

        $this->assertLessThan($fitur, $akses, 'Akses Pengguna harus mendahului daftar fitur.');
        $this->assertLessThan($cabang, $akses, 'Akses Pengguna harus mendahului daftar cabang.');
    }

    /**
     * Layar pertama sudah menjawab keempat hal yang perlu diketahui pengunjung:
     * ini platform apa, siapa pemiliknya, di mana mendaftar, dan di mana masuk.
     */
    public function test_the_first_screen_states_the_identity_and_both_actions(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $hero = substr($html, 0, strpos($html, 'id="akses"'));

        $this->assertStringContainsString(config('app.name'), $hero);
        $this->assertStringContainsString('Platform Manajemen Sekolah Terintegrasi', $hero);
        $this->assertStringContainsString(route('ppdb.schools'), $hero);
        $this->assertStringContainsString('Masuk ke Sistem', $hero);
    }

    // ------------------------------------------------------------------- PPDB

    /**
     * Ajakan PPDB punya bagiannya sendiri, dan kedua tombolnya menunjuk rute
     * PPDB yang memang ada — bukan alamat yang dikarang.
     */
    public function test_the_ppdb_call_to_action_uses_the_real_ppdb_routes(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Penerimaan Peserta Didik Baru', $html);
        $this->assertStringContainsString('Mulai Pendaftaran', $html);
        $this->assertStringContainsString('href="'.route('ppdb.schools').'"', $html);
        $this->assertStringContainsString('href="'.route('ppdb.check-status').'"', $html);
    }

    // --------------------------------------------------------------- bahasa

    /**
     * NFR 1.4 — pemilih bahasa tetap dapat dicapai dari halaman muka, dan tetap
     * berupa form POST yang dilindungi CSRF (butir 388). Ia muncul dua kali:
     * di navbar dan di footer.
     */
    public function test_the_language_switch_stays_reachable(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertGreaterThanOrEqual(
            2,
            substr_count($html, 'class="locale-switch'),
            'Pemilih bahasa harus ada di navbar dan di footer.',
        );

        $this->assertStringContainsString('action="'.route('locale.switch', ['locale' => 'id']).'"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        // Menulis lewat GET tidak pernah ditawarkan.
        $this->assertStringNotContainsString('<a href="'.route('locale.switch', ['locale' => 'en']).'"', $html);
    }

    // --------------------------------------------------- naskah tanpa karangan

    /**
     * Batas naskah batch ini, dan alasan ia diuji: tidak ada satu pun nomor
     * telepon, surel, akun media sosial, atau angka pencapaian yang dikarang.
     * Pemilik belum menyerahkan naskah pemasaran, dan halaman yang tampak
     * meyakinkan karena data palsu lebih buruk daripada halaman yang jujur
     * (butir 420).
     */
    public function test_no_invented_contact_details_or_claims_are_published(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        foreach (['mailto:', 'tel:', 'wa.me', 'instagram.com', 'facebook.com', 'twitter.com', 'youtube.com'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "Kontak yang dikarang: {$needle}");
        }

        // Tidak ada penghitung capaian: "1.200+ siswa", "98% lulus", dan sejenisnya.
        $this->assertDoesNotMatchRegularExpression(
            '/\b\d[\d.,]*\s*\+?\s*(siswa|alumni|lulusan|students|graduates)\b/i',
            strip_tags($html),
            'Halaman memuat angka capaian yang tidak berasal dari data mana pun.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\b\d{1,3}\s*%\s*(kelulusan|kepuasan|graduation|satisfaction)\b/i',
            strip_tags($html),
        );
    }

    // ------------------------------------------------------ struktur & gerak

    public function test_the_page_uses_real_landmarks(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        foreach (['<header', '<nav', '<main', '<footer'] as $landmark) {
            $this->assertStringContainsString($landmark, $html, "Landmark {$landmark} tidak ada.");
        }

        // Kartu yang dapat diklik tetap <a>, bukan div dengan penangan klik.
        // Yang dilarang atributnya, bukan katanya: komentar CSS di tata letak
        // menyebut istilah itu justru untuk menerangkan mengapa ia tidak dipakai.
        $this->assertStringNotContainsString('onclick=', $html);
    }

    /**
     * Gubahan visual hero sepenuhnya hiasan: ia disembunyikan dari pembaca
     * layar, dan tidak memuat satu angka pun yang mengaku data sungguhan
     * (butir 417).
     */
    public function test_the_hero_composition_is_decorative_only(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('class="hero__visual" aria-hidden="true"', $html);

        $start = strpos($html, 'class="hero__visual"');
        $end = strpos($html, '</section>', $start);
        $visual = strip_tags(substr($html, $start, $end - $start));

        $this->assertDoesNotMatchRegularExpression(
            '/\d/',
            $visual,
            'Gubahan hiasan hero tidak boleh memuat angka yang tampak seperti data.',
        );
    }

    public function test_motion_respects_the_reduced_motion_preference(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('@keyframes', $html, 'Batch ini memang menambahkan gerak halus.');
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $html);
    }

    /**
     * Sebelum batch ini kedelapan kartu fitur memakai ikon centang yang sama,
     * sehingga ikonnya tidak membedakan apa pun (butir 419).
     */
    public function test_the_iconography_is_not_one_shape_repeated(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        preg_match_all('/<path d="([^"]+)"/', $html, $matches);

        $this->assertGreaterThanOrEqual(
            10,
            count(array_unique($matches[1])),
            'Halaman memakai terlalu sedikit bentuk ikon yang berbeda.',
        );
    }

    // ================================================ Batch L2.1 — tinjauan

    /**
     * Cacat yang ditemukan tinjauan manusia, dan alasan tes ini ada.
     *
     * Dalam mode EN kedelapan ringkasan fitur dan empat judulnya masih
     * berbahasa Indonesia, sementara seluruh suite hijau. Sebabnya kunci-kunci
     * itu dipanggil sebagai `__($name)` atas variabel, dan pemindai kunci di
     * BilingualCoverageTest hanya melihat `__('literal')` — jadi tidak ada satu
     * pemeriksaan pun yang pernah menengok ke sana (butir 424).
     *
     * Tes ini memeriksa **halaman yang benar-benar dirender**, bukan berkas
     * terjemahannya, sehingga kelas cacat yang sama tidak dapat kembali lewat
     * pintu lain: label apa pun yang lolos ke mode EN akan menjatuhkannya.
     */
    public function test_the_english_landing_renders_no_indonesian_implementation_copy(): void
    {
        School::factory()->create(['name' => 'SMP Madani', 'code' => 'MDN']);

        $text = $this->visibleTextOf($this->inEnglish()->get('/')->assertOk()->getContent());

        // Judul dan ringkasan yang benar-benar tertinggal pada tinjauan L2.
        foreach ([
            'Data Siswa & Kelas',
            'Akademik & E-Rapor',
            'Keuangan Sekolah',
            'Notifikasi & Pengumuman',
            'Formulir pendaftaran publik per cabang',
            'Data induk siswa',
            'Input nilai per komponen',
            'Jenis tagihan',
            'Ujian pilihan ganda',
            'Pengumuman ke seluruh sekolah',
            'Jadwal, nilai per mata pelajaran',
            'Ringkasan anak',
        ] as $indonesian) {
            $this->assertStringNotContainsString(
                $indonesian,
                $text,
                "Naskah Indonesia masih tampil di mode EN: {$indonesian}",
            );
        }

        // Dan penggantinya memang tampil.
        foreach ([
            'Student & Class Records',
            'Academics & Digital Report Cards',
            'School Finance',
            'Notifications & Announcements',
        ] as $english) {
            $this->assertStringContainsString($english, $text);
        }
    }

    /**
     * Jaring yang lebih lebar daripada daftar di atas: kata Indonesia yang
     * tidak mungkin muncul dalam kalimat Inggris. Nama cabang sengaja tidak
     * ikut diperiksa — ia data sekolah, bukan naskah antarmuka.
     */
    public function test_the_english_landing_carries_no_stray_indonesian_words(): void
    {
        $text = $this->visibleTextOf($this->inEnglish()->get('/')->assertOk()->getContent());

        foreach ([' yang ', ' dan ', ' dengan ', ' untuk ', ' tidak ', ' siswa ', ' sekolah '] as $word) {
            $this->assertStringNotContainsString(
                $word,
                ' '.strtolower($text).' ',
                "Kata Indonesia lolos ke mode EN:{$word}",
            );
        }
    }

    // -------------------------------------------------------- menu seluler

    /**
     * Tinjauan manusia: strip yang menggulung ke samping "berjalan, tetapi
     * tidak terlihat sebagai menu". Penggantinya `<details>`/`<summary>` —
     * dapat dibuka papan ketik dan tanpa satu baris JavaScript (butir 425).
     */
    public function test_the_mobile_menu_is_a_real_disclosure_without_javascript(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<details class="nav__disclosure">', $html);
        $this->assertStringContainsString('<summary class="nav__toggle"', $html);

        // Perilakunya tidak digerakkan skrip: tidak ada penangan peristiwa
        // sebaris, dan tidak ada atribut kerangka kerja yang mengendalikannya.
        //
        // Yang **tidak** diperiksa di sini keberadaan `<script>` sama sekali:
        // Livewire menyuntikkan asetnya sendiri ke dalam balasan HTML utuh, dan
        // menuntut nol tag skrip berarti menguji perilaku Livewire, bukan menu
        // ini (butir 366).
        foreach (['onclick=', 'onchange=', 'x-data', 'wire:click'] as $scripted) {
            $this->assertStringNotContainsString($scripted, $html);
        }
    }

    public function test_the_mobile_menu_carries_every_destination(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $panel = $this->between($html, '<nav class="nav__panel"', '</details>');

        foreach (['#konten', '#akses', '#fitur', '#tentang', '#cabang'] as $anchor) {
            $this->assertStringContainsString('href="'.$anchor.'"', $panel, "Menu kehilangan {$anchor}.");
        }

        foreach ([route('ppdb.schools'), route('ppdb.check-status')] as $url) {
            $this->assertStringContainsString('href="'.$url.'"', $panel, "Menu kehilangan {$url}.");
        }

        // Pemilih bahasa ikut di dalam panel.
        $this->assertStringContainsString('locale-switch', $panel);
    }

    /**
     * Aksi utama tidak ikut disembunyikan: tombol Masuk tetap di bar, di luar
     * `<details>`, sehingga terlihat tanpa membuka apa pun.
     */
    public function test_the_sign_in_action_stays_outside_the_mobile_menu(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('class="btn btn--primary nav__cta--bar"', $html);

        $afterMenu = substr($html, strpos($html, '</details>'));
        $this->assertStringContainsString('nav__cta--bar', $afterMenu);
    }

    // ------------------------------------------------------- aksi & bentuk

    /**
     * Aksi kartu akses berbentuk pil yang terlihat dapat ditekan, dan PPDB —
     * urutan pertama — mendapat pil terisi (butir 426).
     */
    public function test_the_access_actions_look_pressable_with_ppdb_first(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('.access__go {', $html);
        $this->assertStringContainsString('border-radius: var(--radius-pill);', $html);
        $this->assertStringContainsString('access--primary', $html);

        $section = $this->between($html, 'id="akses"', 'id="fitur"');

        // Urutannya dibaca dari judul kartunya: sejak ketiga peran berbagi satu
        // alamat, URL tidak lagi dapat membedakan mereka (butir 437).
        $order = [];

        foreach ([
            'Pendaftaran (PPDB)' => 'ppdb',
            'Portal Siswa' => 'siswa',
            'Portal Orang Tua' => 'ortu',
            'Admin &amp; Guru' => 'panel',
        ] as $title => $label) {
            $at = strpos($section, $title);

            $this->assertIsInt($at, "Kartu {$title} tidak ada.");

            $order[$at] = $label;
        }

        ksort($order);

        $this->assertSame(['ppdb', 'siswa', 'ortu', 'panel'], array_values($order));

        // Dan ketiga kartu peran memang mengarah ke pintu yang sama.
        $this->assertSame(3, substr_count($section, 'href="'.route('login').'"'));
    }

    /**
     * Bagian Tentang berhenti menjadi baris kartu putih ketiga (butir 427).
     */
    public function test_the_about_section_is_not_another_card_row(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $about = $this->between($html, 'id="tentang"', '</section>');

        $this->assertStringContainsString('class="about"', $about);
        $this->assertStringContainsString('class="about__item"', $about);
        $this->assertStringNotContainsString('class="card"', $about);

        // Ketiga alasannya tetap ada, kata demi kata.
        foreach ([
            'Terpadu sejak pendaftaran',
            'Mendukung banyak cabang',
            'Diakses lewat peramban',
        ] as $pillar) {
            $this->assertStringContainsString($pillar, $about);
        }
    }

    /**
     * Kartu cabang menonjolkan identitasnya, dan alamatnya tetap hanya yang
     * benar-benar tersimpan — tidak ada yang dikarang (butir 350).
     */
    public function test_the_branch_card_leads_with_its_identity(): void
    {
        School::factory()->create([
            'name' => 'SMP Madani',
            'code' => 'MDN',
            'address' => 'Jalan Contoh No. 1',
        ]);

        $html = $this->get('/')->assertOk()->getContent();
        $card = $this->between($html, 'id="cabang"', '</section>');

        $this->assertStringContainsString('class="branch__name"', $card);
        $this->assertStringContainsString('SMP Madani', $card);
        $this->assertStringContainsString('MDN', $card);
        $this->assertStringContainsString('class="branch__address"', $card);
        $this->assertStringContainsString('Jalan Contoh No. 1', $card);

        // Alamat kosong tidak menghasilkan baris kosong.
        School::query()->update(['address' => null]);
        $withoutAddress = $this->between($this->get('/')->getContent(), 'id="cabang"', '</section>');
        $this->assertStringNotContainsString('class="branch__address"', $withoutAddress);
    }

    /**
     * Label hero tetap hiasan: nama fitur yang benar-benar ada, tanpa angka.
     */
    public function test_the_hero_labels_name_real_features_without_numbers(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $visual = $this->between($html, 'class="hero__visual"', '</section>');

        foreach (['PPDB', 'Jadwal', 'Nilai', 'Ujian', 'Portal'] as $label) {
            $this->assertStringContainsString('>'.$label.'</span>', $visual);
        }

        $this->assertDoesNotMatchRegularExpression('/\d/', strip_tags($visual));
    }

    // ------------------------------------------------------------- bantuan

    // ============================================ Batch L2.2 — pagar seluler

    /*
     * Tes di bawah **tidak** membuktikan tampilannya benar. Yang dapat
     * dibuktikan dari sini hanya bahwa pagar strukturalnya masih terpasang:
     * kepala masih memuat ketiga kendalinya, wordmark masih boleh menyusut,
     * dan tidak ada lebar keras yang mustahil muat di 360px. Penilaian visual
     * tetap milik manusia yang melihat layarnya (butir 435).
     */

    /**
     * Cacat yang ditemukan tinjauan: kepala terpotong di 360px.
     *
     * Sebabnya `.brand { white-space: nowrap }` — wordmark satu baris menolak
     * menyusut di bawah lebar min-content-nya, barisnya meluber, lalu
     * `body { overflow-x: hidden }` memotongnya tanpa satu tanda pun. Tes ini
     * menjaga pelepasannya, karena mengembalikan `nowrap` akan mengembalikan
     * persis cacat itu (butir 433).
     */
    public function test_the_wordmark_may_shrink_on_a_phone(): void
    {
        $css = $this->styleOf($this->get('/')->assertOk()->getContent());

        $mobile = $this->between($css, '@media (max-width: 47.99rem) {', '/* ------------------------------------------------------------ hero */');

        $this->assertStringContainsString('.brand {', $mobile);
        $this->assertStringContainsString('white-space: normal;', $mobile);
        $this->assertStringContainsString('min-width: 0;', $mobile);

        // Yang menyusut wordmark-nya, bukan sasaran sentuhnya.
        $this->assertStringContainsString('.nav__disclosure { flex: 0 0 auto; }', $mobile);
    }

    /**
     * Ketiga kendali kepala tetap ada dan tetap dapat dicapai; tidak satu pun
     * disembunyikan demi memuat yang lain.
     */
    public function test_the_mobile_header_keeps_all_three_controls(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $header = $this->between($html, '<header class="nav">', '</header>');

        $this->assertStringContainsString('class="brand"', $header);
        $this->assertStringContainsString('<summary class="nav__toggle"', $header);
        $this->assertStringContainsString('nav__cta--bar', $header);

        // Menu dan Masuk tetap dua hal berbeda: Masuk di luar <details>.
        $this->assertLessThan(
            strpos($header, 'nav__cta--bar'),
            strpos($header, '</details>'),
            'Tombol Masuk tidak boleh berada di dalam menu.',
        );
    }

    /**
     * Tidak ada lebar keras yang mustahil muat di layar 360px. Sebuah
     * `min-width` dalam piksel pada wadah mana pun adalah cara paling umum
     * membuat halaman meluber tanpa disadari.
     */
    public function test_no_container_carries_a_desktop_width_floor(): void
    {
        $css = $this->styleOf($this->get('/')->assertOk()->getContent());

        $this->assertDoesNotMatchRegularExpression('/min-width:\s*\d+px/', $css);

        // `width:` dalam piksel pada wadah juga tidak dipakai.
        $this->assertDoesNotMatchRegularExpression('/\n\s+width:\s*\d{3,}px/', $css);
    }

    /**
     * Irama seluler datang dari token yang disetel ulang di satu tempat, bukan
     * dari puluhan penimpaan yang tidak saling tahu (butir 432).
     */
    public function test_the_mobile_rhythm_comes_from_tokens(): void
    {
        $css = $this->styleOf($this->get('/')->assertOk()->getContent());

        foreach (['--section-y', '--container-pad', '--card-pad', '--grid-gap', '--head-gap'] as $token) {
            $this->assertStringContainsString($token.':', $css, "Token {$token} tidak ada.");
        }

        $mobile = $this->between($css, '@media (max-width: 47.99rem) {', '/* =========================================================== navbar */');

        // Jaraknya benar-benar mengecil, bukan hanya token yang ditambahkan.
        $this->assertStringContainsString('--section-y: 2.75rem;', $mobile);
        $this->assertStringContainsString('--card-pad: 1.15rem;', $mobile);
    }

    /**
     * Kedelapan kemampuan tetap ada; yang berubah bentuknya di layar sempit
     * (butir 434).
     */
    public function test_the_capability_list_gets_a_compact_mobile_form(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $features = $this->between($html, 'id="fitur"', '</section>');

        $this->assertStringContainsString('grid--rows', $features);
        $this->assertSame(8, substr_count($features, '<article class="card">'));

        $css = $this->styleOf($html);
        $this->assertStringContainsString('@media (max-width: 30rem) {', $css);
        $this->assertStringContainsString('.grid--rows .card {', $css);
    }

    /**
     * Label Inggris terpanjang tidak boleh terkunci pada satu baris di layar
     * sempit — "Check Admission Status" akan mendorong barisnya keluar layar.
     */
    public function test_long_english_buttons_may_wrap_on_a_narrow_screen(): void
    {
        $css = $this->styleOf($this->inEnglish()->get('/')->assertOk()->getContent());

        $narrow = substr($css, strpos($css, '@media (max-width: 30rem) {'));

        $this->assertStringContainsString('.cta__buttons .btn {', $narrow);
        $this->assertStringContainsString('white-space: normal;', $narrow);

        // Dan sasaran sentuhnya tidak dikorbankan demi kepadatan.
        $this->assertStringContainsString('min-height: 2.9rem', $narrow);
    }

    /** Isi <style> halaman, tanpa markup di sekitarnya. */
    protected function styleOf(string $html): string
    {
        $start = strpos($html, '<style>');

        $this->assertIsInt($start, 'Halaman tidak memuat blok gaya.');

        return substr($html, $start, strpos($html, '</style>', $start) - $start);
    }

    /** Beralih ke bahasa Inggris lewat jalur yang sama dengan pengunjung. */
    protected function inEnglish(): static
    {
        $this->post(route('locale.switch', ['locale' => 'en']));

        return $this;
    }

    /** Teks yang benar-benar terbaca pengunjung, tanpa CSS dan tanpa atribut. */
    protected function visibleTextOf(string $html): string
    {
        $withoutStyles = preg_replace('#<(script|style)[^>]*>.*?</\1>#s', ' ', $html);

        return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($withoutStyles)));
    }

    /** Potongan dokumen di antara dua penanda. */
    protected function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);

        $this->assertIsInt($start, "Penanda tidak ditemukan: {$from}");

        $end = strpos($html, $to, $start);

        return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    }

    protected function countQueries(\Closure $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $work();

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $count;
    }
}
