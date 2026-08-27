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

    public function test_every_role_entry_point_is_linked(): void
    {
        $response = $this->get('/')->assertOk();

        foreach ([
            route('filament.admin.auth.login'),
            route('student.login'),
            route('portal.login'),
            route('ppdb.schools'),
            route('ppdb.check-status'),
        ] as $url) {
            $response->assertSee('href="'.$url.'"', false);
        }
    }

    public function test_the_entry_point_urls_are_the_real_ones(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        foreach (['/admin/login', '/siswa/masuk', '/portal/masuk', '/ppdb'] as $path) {
            $this->assertStringContainsString('href="'.url($path).'"', $html, "Tautan {$path} tidak ada.");
        }
    }

    /**
     * Tidak ada `/login` umum di project ini, dan halaman muka tidak boleh
     * berpura-pura ada (butir 349).
     */
    public function test_no_universal_login_link_is_invented(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('href="'.url('/login').'"', $html);
        $this->assertNull(app('router')->getRoutes()->getByName('login'));

        // Tombol "Masuk ke Sistem" mengantar ke bagian akses peran.
        $this->assertStringContainsString('Masuk ke Sistem', $html);
        $this->assertStringContainsString('href="#akses"', $html);
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

        // Panel tetap menuntut masuk.
        $this->get(route('filament.admin.auth.login'))->assertOk();

        // Portal tetap tertutup bagi tamu.
        $this->get(route('student.exams'))->assertRedirect(route('student.login'));
        $this->get(route('portal.dashboard'))->assertRedirect(route('portal.login'));
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
