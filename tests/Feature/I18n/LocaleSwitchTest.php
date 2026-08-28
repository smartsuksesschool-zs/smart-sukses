<?php

namespace Tests\Feature\I18n;

use App\Enums\RoleName;
use App\Models\School;
use App\Models\User;
use App\Support\Locale;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NFR 1.4 / AUTH-05 — mekanisme pemilih bahasa ID/EN.
 *
 * Yang diuji di sini bukan terjemahannya (itu di `BilingualCoverageTest`),
 * melainkan **jalurnya**: dari mana bahasa sebuah request berasal, apa yang
 * terjadi pada nilai yang tidak dikenal, dan siapa yang boleh mengubah
 * preferensi siapa.
 *
 * Bagian terakhir itu yang paling mudah salah. Nilai locale ikut menentukan
 * berkas terjemahan yang dimuat Laravel, jadi nilai sembarang dari URL tidak
 * boleh pernah sampai ke `App::setLocale()`; dan preferensi bahasa adalah
 * kolom pada baris pengguna, jadi rutenya tidak boleh menerima id pengguna
 * dari luar (butir 377 & 380).
 */
class LocaleSwitchTest extends TestCase
{
    use RefreshDatabase;

    // --------------------------------------------------- bawaan & dukungan

    public function test_the_default_language_is_indonesian(): void
    {
        $this->assertSame('id', Locale::DEFAULT);
        $this->assertSame('id', config('app.locale'));
    }

    /**
     * Bawaan tetap Indonesia meskipun `.env` tidak menyebutkannya sama sekali.
     * Yang diperiksa nilai bawaan pada berkas config, bukan nilai `.env` mesin
     * yang sedang menjalankan tes (butir 384).
     */
    public function test_indonesian_is_the_default_even_without_an_env_value(): void
    {
        $config = file_get_contents(config_path('app.php'));

        $this->assertStringContainsString("env('APP_LOCALE', 'id')", $config);
        $this->assertStringNotContainsString("env('APP_LOCALE', 'en')", $config);
    }

    public function test_only_indonesian_and_english_are_supported(): void
    {
        $this->assertSame(['id', 'en'], Locale::supported());

        $this->assertTrue(Locale::isSupported('id'));
        $this->assertTrue(Locale::isSupported('en'));

        foreach (['fr', 'ID', 'en-US', 'id_ID', '', 'xx', 'e', 'idd'] as $rejected) {
            $this->assertFalse(Locale::isSupported($rejected), $rejected.' should be rejected');
        }
    }

    public function test_unsupported_values_fall_back_to_indonesian_without_erroring(): void
    {
        foreach (['fr', 'ID', '', null, 123, ['en'], '../../config/app'] as $value) {
            $this->assertSame('id', Locale::sanitize($value));
        }
    }

    // ------------------------------------------------------- tamu (sesi)

    public function test_a_guest_can_switch_to_english_without_logging_in(): void
    {
        $this->get('/')->assertOk();

        $this->post(route('locale.switch', ['locale' => 'en']))
            ->assertRedirect()
            ->assertSessionHas(Locale::sessionKey(), 'en');

        $this->assertGuest();
    }

    public function test_a_guest_language_choice_creates_no_database_row(): void
    {
        $before = User::query()->count();

        $this->post(route('locale.switch', ['locale' => 'en']));

        $this->assertSame($before, User::query()->count());
    }

    public function test_a_guest_choice_survives_to_the_next_request(): void
    {
        $this->post(route('locale.switch', ['locale' => 'en']));

        $this->get('/')
            ->assertOk()
            ->assertSee('Ready to use Smart Sukses School?', escape: false);
    }

    /**
     * Kode bahasa yang tidak dikenal tidak boleh menimbulkan galat maupun
     * tersimpan apa adanya di sesi. Yang tersimpan selalu nilai yang sudah
     * disaring.
     */
    public function test_an_unknown_locale_in_the_url_falls_back_instead_of_erroring(): void
    {
        $this->post('/bahasa/fr')
            ->assertRedirect()
            ->assertSessionHas(Locale::sessionKey(), 'id');
    }

    /**
     * Rutenya sendiri hanya menerima huruf, sehingga jalur berkas maupun
     * pemisah direktori tidak pernah mencapai controller.
     */
    public function test_no_path_like_locale_value_can_reach_the_route(): void
    {
        foreach (['..%2F..%2Fconfig', 'en/../id', 'en.json', 'en%00', 'en-US', 'id_ID'] as $attempt) {
            $this->post('/bahasa/'.$attempt)->assertNotFound();
        }
    }

    public function test_switching_back_to_indonesian_works(): void
    {
        $this->post(route('locale.switch', ['locale' => 'en']));

        $this->post(route('locale.switch', ['locale' => 'id']))
            ->assertSessionHas(Locale::sessionKey(), 'id');

        $this->get('/')->assertOk()->assertSee('Siap menggunakan Smart Sukses School?', escape: false);
    }

    // -------------------------------------------------- pengguna (users.locale)

    public function test_a_logged_in_user_choice_is_persisted_on_their_own_row(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $school = School::factory()->create();
        $user = User::factory()->forSchool($school)->withRole(RoleName::Guru)->create(['locale' => 'id']);

        $this->actingAs($user)->post(route('locale.switch', ['locale' => 'en']))->assertRedirect();

        $this->assertSame('en', $user->fresh()->locale);
    }

    /**
     * Tidak ada id pengguna di URL maupun di badan request: rutenya hanya
     * menerima kode bahasa, dan yang ditulis selalu `$request->user()`. Karena
     * itu tidak ada bentuk permintaan apa pun yang dapat mengubah bahasa akun
     * orang lain (butir 380).
     */
    public function test_a_user_cannot_change_another_users_language(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $school = School::factory()->create();
        $attacker = User::factory()->forSchool($school)->withRole(RoleName::Guru)->create(['locale' => 'id']);
        $victim = User::factory()->forSchool($school)->withRole(RoleName::Guru)->create(['locale' => 'id']);

        // Setiap bentuk yang mungkin dicoba: query string, badan permintaan,
        // dan segmen tambahan pada URL.
        $this->actingAs($attacker)
            ->post(route('locale.switch', ['locale' => 'en']).'?user_id='.$victim->id.'&user='.$victim->id);

        $this->actingAs($attacker)
            ->post(route('locale.switch', ['locale' => 'en']), [
                'user_id' => $victim->id,
                'user' => $victim->id,
                'id' => $victim->id,
                'locale' => 'en',
            ])
            ->assertRedirect();

        $this->actingAs($attacker)
            ->post('/bahasa/en/'.$victim->id)
            ->assertNotFound();

        $this->assertSame('id', $victim->fresh()->locale, 'the victim locale must not change');
        $this->assertSame('en', $attacker->fresh()->locale);
    }

    /**
     * Nilai yang tidak dikenal tidak pernah masuk ke kolom `users.locale` —
     * validasinya di server, bukan pada tombol.
     */
    public function test_an_unknown_locale_is_never_written_to_the_user_row(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $school = School::factory()->create();
        $user = User::factory()->forSchool($school)->withRole(RoleName::Guru)->create(['locale' => 'en']);

        $this->actingAs($user)->post('/bahasa/fr');

        $this->assertSame('id', $user->fresh()->locale);
        $this->assertContains($user->fresh()->locale, Locale::supported());
    }

    /**
     * Preferensi akun menang atas sesi: pilihan yang pernah ditekan sebagai
     * tamu di peramban yang sama tidak boleh menimpa pilihan profil
     * (butir 378).
     */
    public function test_the_account_preference_wins_over_a_leftover_guest_session(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $school = School::factory()->create();
        $user = User::factory()->forSchool($school)->withRole(RoleName::Guru)->create(['locale' => 'id']);

        $this->withSession([Locale::sessionKey() => 'en'])
            ->actingAs($user)
            ->get(route('teacher.dashboard'))
            ->assertOk()
            ->assertSee('Dasbor Kerja', escape: false)
            ->assertDontSee('Work Dashboard', escape: false);
    }

    /**
     * Sebuah baris pengguna yang entah bagaimana memuat nilai di luar daftar
     * tidak boleh membuat halaman jatuh ke locale sembarang.
     */
    public function test_a_corrupt_stored_locale_falls_back_instead_of_being_used(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $school = School::factory()->create();
        $user = User::factory()->forSchool($school)->withRole(RoleName::Guru)->create();
        $user->forceFill(['locale' => 'zz'])->saveQuietly();

        $this->actingAs($user)->get(route('teacher.dashboard'))->assertOk();

        $this->assertSame('id', app()->getLocale());
    }

    // ---------------------------------------------------------- tampilan

    public function test_the_switch_is_present_on_the_public_landing_page(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(route('locale.switch', ['locale' => 'en'], absolute: false), $html);
        $this->assertStringContainsString('locale-switch', $html);
    }

    /**
     * Bentuknya form POST beserta token CSRF-nya, bukan tautan. Diperiksa pada
     * kelima permukaan sekaligus supaya tidak ada satu pun yang tertinggal
     * memakai GET (butir 388).
     */
    public function test_the_switch_posts_with_a_csrf_token_on_every_surface(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $school = School::factory()->create(['code' => 'MADANI', 'is_active' => true]);
        $teacher = User::factory()->forSchool($school)->withRole(RoleName::Guru)->create();

        $pages = [
            'landing' => fn () => $this->get('/'),
            'ppdb' => fn () => $this->get(route('ppdb.schools')),
            'student login' => fn () => $this->get(route('student.login')),
            'parent login' => fn () => $this->get(route('portal.login')),
            'portal shell' => fn () => $this->actingAs($teacher)->get(route('teacher.dashboard')),
            'filament panel' => fn () => $this->actingAs($teacher)->get(route('filament.admin.pages.dashboard')),
        ];

        foreach ($pages as $label => $visit) {
            $html = $visit()->assertOk()->getContent();

            $action = route('locale.switch', ['locale' => 'en'], absolute: false);

            $this->assertMatchesRegularExpression(
                '/<form[^>]+method="POST"[^>]*>/i',
                $html,
                $label.' must render the switch as a POST form',
            );

            $this->assertStringContainsString('name="_token"', $html, $label.' must carry a CSRF token');

            $this->assertMatchesRegularExpression(
                '/formaction="[^"]*'.preg_quote($action, '/').'"/',
                $html,
                $label.' must post to the switch endpoint',
            );

            // Dan tidak ada satu pun tautan GET ke endpoint itu.
            $this->assertStringNotContainsString(
                'href="'.$action.'"',
                $html,
                $label.' must not link to the switch endpoint',
            );
        }
    }

    /**
     * Rutenya berada di grup `web`, sehingga `ValidateCsrfToken` benar-benar
     * berlaku padanya. Yang diperiksa daftar middleware rutenya, bukan
     * keberadaan token pada halaman — token dapat dirender tanpa ada yang
     * memeriksanya.
     *
     * Penolakan token yang salah tidak diuji di sini: `ValidateCsrfToken`
     * sengaja melewati dirinya sendiri selama `runningUnitTests()`, sehingga
     * uji semacam itu hanya akan menguji tiruan, bukan middleware yang
     * sebenarnya berjalan di produksi. Yang dapat dibuktikan — dan dibuktikan —
     * adalah bahwa middleware-nya terpasang dan metodenya POST, sehingga
     * jalan pintas `isReading()` tidak berlaku (butir 388).
     */
    public function test_the_switch_route_is_csrf_protected(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => $route->getName() === 'locale.switch');

        $this->assertNotNull($route);
        $this->assertSame(['POST'], array_values(array_diff($route->methods(), ['HEAD'])));
        $this->assertContains('web', $route->middleware());

        $this->assertContains(
            ValidateCsrfToken::class,
            app('router')->getMiddlewareGroups()['web'],
        );

        // POST, sehingga jalan pintas `isReading()` pada middleware itu tidak
        // pernah berlaku bagi rute ini.
        $this->assertNotContains('GET', $route->methods());
    }

    public function test_the_switch_is_present_on_the_public_ppdb_page(): void
    {
        School::factory()->create(['code' => 'MADANI', 'is_active' => true]);

        $html = $this->get(route('ppdb.schools'))->assertOk()->getContent();

        $this->assertStringContainsString(route('locale.switch', ['locale' => 'en'], absolute: false), $html);
    }

    public function test_the_switch_is_present_on_the_student_login_page(): void
    {
        $html = $this->get(route('student.login'))->assertOk()->getContent();

        $this->assertStringContainsString(route('locale.switch', ['locale' => 'en'], absolute: false), $html);
    }

    public function test_the_switch_is_present_on_the_parent_login_page(): void
    {
        $html = $this->get(route('portal.login'))->assertOk()->getContent();

        $this->assertStringContainsString(route('locale.switch', ['locale' => 'en'], absolute: false), $html);
    }

    public function test_the_switch_is_present_inside_the_portal_shell(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $school = School::factory()->create();
        $teacher = User::factory()->forSchool($school)->withRole(RoleName::Guru)->create();

        $html = $this->actingAs($teacher)->get(route('teacher.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString(route('locale.switch', ['locale' => 'en'], absolute: false), $html);
    }

    /**
     * Tombol submit HTML biasa, bukan tombol JavaScript: dapat difokuskan papan
     * ketik dan dibaca pembaca layar tanpa penanganan tambahan. Bahasa yang
     * sedang aktif bukan tombol ke dirinya sendiri.
     */
    public function test_the_switch_is_a_plain_keyboard_reachable_button(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        // Bahasa lain: tombol submit sungguhan.
        $this->assertMatchesRegularExpression(
            '/<button[^>]+type="submit"[^>]*formaction="[^"]*'
                .preg_quote(route('locale.switch', ['locale' => 'en'], absolute: false), '/').'"/s',
            $html,
        );

        // Bahasa aktif: bukan tombol.
        $this->assertStringNotContainsString(
            'formaction="'.route('locale.switch', ['locale' => 'id'], absolute: false).'"',
            $html,
        );

        $this->assertStringContainsString('aria-current="true"', $html);

        // Tidak ada JavaScript yang dipakai hanya untuk ini.
        $this->assertStringNotContainsString('onclick=', $html);
        $this->assertStringNotContainsString('onchange=', $html);
        $this->assertStringNotContainsString('x-on:click', $html);
    }

    /**
     * Persyaratan yang paling mudah hilang tanpa disadari: setelah pemilihnya
     * menjadi POST, tidak boleh ada satu pun rute GET yang masih menyimpan
     * bahasa. `GET /bahasa/en` karena itu menjawab 405 — URI-nya ada, tetapi
     * hanya menerima POST — dan tidak menyentuh apa pun.
     */
    public function test_no_get_route_performs_the_locale_persistence(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $school = School::factory()->create();
        $user = User::factory()->forSchool($school)->withRole(RoleName::Guru)->create(['locale' => 'id']);

        // Tamu.
        $this->get('/bahasa/en')->assertStatus(405)->assertSessionMissing(Locale::sessionKey());

        // Pengguna yang login.
        $this->actingAs($user)->get('/bahasa/en')->assertStatus(405);
        $this->assertSame('id', $user->fresh()->locale);

        // Dan tidak ada rute GET lain yang menunjuk ke controller-nya.
        $getRoutes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('GET', $route->methods(), true))
            ->filter(fn ($route) => str_contains((string) $route->getActionName(), 'LocaleController'));

        $this->assertCount(0, $getRoutes);
    }

    /**
     * `<form>` di dalam `<form>` adalah HTML tidak sah, dan peramban
     * membuangnya diam-diam — tombolnya masih terlihat, tetapi tidak lagi
     * mengirim apa pun. Karena pemilihnya kini form, penempatannya diperiksa
     * pada kelima permukaan.
     */
    public function test_the_switch_form_is_never_nested_inside_another_form(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $school = School::factory()->create(['code' => 'MADANI', 'is_active' => true]);
        $teacher = User::factory()->forSchool($school)->withRole(RoleName::Guru)->create();

        $pages = [
            'landing' => fn () => $this->get('/'),
            'ppdb' => fn () => $this->get(route('ppdb.schools')),
            'student login' => fn () => $this->get(route('student.login')),
            'parent login' => fn () => $this->get(route('portal.login')),
            'portal shell' => fn () => $this->actingAs($teacher)->get(route('teacher.dashboard')),
            'filament panel' => fn () => $this->actingAs($teacher)->get(route('filament.admin.pages.dashboard')),
        ];

        foreach ($pages as $label => $visit) {
            $html = $visit()->assertOk()->getContent();

            $depth = 0;
            $maxDepth = 0;

            preg_match_all('/<\/?form\b/i', $html, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [$tag, $_]) {
                if (str_starts_with(strtolower($tag), '</')) {
                    $depth--;

                    continue;
                }

                $depth++;
                $maxDepth = max($maxDepth, $depth);
            }

            $this->assertSame(0, $depth, $label.' has unbalanced form tags');
            $this->assertSame(1, $maxDepth, $label.' must never nest one form inside another');
        }
    }

    /**
     * Sasaran sentuh pada ponsel: pemilihnya memakai angka yang sama dengan
     * tombol portal lain, bukan tautan setinggi satu baris teks.
     */
    public function test_the_switch_has_a_finger_sized_touch_target(): void
    {
        foreach (['layouts/portal.blade.php', 'layouts/landing.blade.php', 'layouts/ppdb.blade.php'] as $layout) {
            $css = file_get_contents(resource_path('views/'.$layout));

            $this->assertMatchesRegularExpression(
                '/\.locale-switch__item\s*\{[^}]*min-height:\s*2\.75rem/s',
                $css,
                $layout.' must give the language switch a 2.75rem touch target',
            );
        }
    }

    // ------------------------------------------------------------ keamanan

    /**
     * Tidak ada satu pun keluaran terjemahan yang dirender mentah. Kalau ada,
     * teks terjemahan menjadi jalur injeksi HTML.
     */
    public function test_no_translation_output_is_rendered_unescaped(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            $contents = file_get_contents($file);

            if (preg_match('/\{!!\s*(__|trans|trans_choice|@lang)/', $contents)) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'translations must never be rendered with {!! !!}');
    }

    /**
     * Keluaran `__()` selalu di-escape Blade, jadi kunci maupun terjemahan tidak
     * boleh memuat entitas HTML. `'Admin &amp; Guru'` dirender menjadi
     * "Admin &amp;amp; Guru" — teks yang terlihat rusak di halaman.
     *
     * Ditemukan Batch S9.3 justru oleh `LandingPageTest`, setelah pembungkusan
     * `__()` mengubah teks HTML mentah menjadi keluaran yang di-escape.
     */
    public function test_no_translation_string_carries_an_html_entity(): void
    {
        foreach (['id.json', 'en.json'] as $file) {
            $entries = json_decode(file_get_contents(lang_path($file)), true);

            foreach ($entries as $key => $value) {
                $this->assertDoesNotMatchRegularExpression(
                    '/&(amp|lt|gt|quot|nbsp|middot|#\d+);/',
                    $key,
                    $file.' key contains an HTML entity: '.$key,
                );

                $this->assertDoesNotMatchRegularExpression(
                    '/&(amp|lt|gt|quot|nbsp|middot|#\d+);/',
                    (string) $value,
                    $file.' value contains an HTML entity: '.$value,
                );
            }
        }
    }

    /**
     * @return array<int, string>
     */
    protected function bladeFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
