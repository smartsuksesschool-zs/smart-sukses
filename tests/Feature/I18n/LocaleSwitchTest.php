<?php

namespace Tests\Feature\I18n;

use App\Enums\RoleName;
use App\Models\School;
use App\Models\User;
use App\Support\Locale;
use Database\Seeders\RolePermissionSeeder;
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

        $this->get(route('locale.switch', ['locale' => 'en']))
            ->assertRedirect()
            ->assertSessionHas(Locale::sessionKey(), 'en');

        $this->assertGuest();
    }

    public function test_a_guest_language_choice_creates_no_database_row(): void
    {
        $before = User::query()->count();

        $this->get(route('locale.switch', ['locale' => 'en']));

        $this->assertSame($before, User::query()->count());
    }

    public function test_a_guest_choice_survives_to_the_next_request(): void
    {
        $this->get(route('locale.switch', ['locale' => 'en']));

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
        $this->get('/bahasa/fr')
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
            $this->get('/bahasa/'.$attempt)->assertNotFound();
        }
    }

    public function test_switching_back_to_indonesian_works(): void
    {
        $this->get(route('locale.switch', ['locale' => 'en']));

        $this->get(route('locale.switch', ['locale' => 'id']))
            ->assertSessionHas(Locale::sessionKey(), 'id');

        $this->get('/')->assertOk()->assertSee('Siap menggunakan Smart Sukses School?', escape: false);
    }

    // -------------------------------------------------- pengguna (users.locale)

    public function test_a_logged_in_user_choice_is_persisted_on_their_own_row(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $school = School::factory()->create();
        $user = User::factory()->forSchool($school)->withRole(RoleName::Guru)->create(['locale' => 'id']);

        $this->actingAs($user)->get(route('locale.switch', ['locale' => 'en']))->assertRedirect();

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

        // Setiap bentuk yang mungkin dicoba: query string, badan POST, dan
        // segmen tambahan pada URL.
        $this->actingAs($attacker)
            ->get(route('locale.switch', ['locale' => 'en']).'?user_id='.$victim->id.'&user='.$victim->id);

        $this->actingAs($attacker)
            ->post(route('locale.switch', ['locale' => 'en']), ['user_id' => $victim->id])
            ->assertStatus(405);

        $this->actingAs($attacker)
            ->get('/bahasa/en/'.$victim->id)
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

        $this->actingAs($user)->get('/bahasa/fr');

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
     * Tautan biasa, bukan tombol JavaScript: dapat difokuskan papan ketik dan
     * dibaca pembaca layar tanpa penanganan tambahan. Bahasa yang sedang aktif
     * bukan tautan ke dirinya sendiri.
     */
    public function test_the_switch_is_a_plain_keyboard_reachable_link(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        // Bahasa lain: tautan sungguhan.
        $this->assertMatchesRegularExpression(
            '/<a[^>]+href="[^"]*'.preg_quote(route('locale.switch', ['locale' => 'en'], absolute: false), '/').'"/',
            $html,
        );

        // Bahasa aktif: bukan tautan.
        $this->assertStringNotContainsString(
            'href="'.route('locale.switch', ['locale' => 'id'], absolute: false).'"',
            $html,
        );

        $this->assertStringContainsString('aria-current="true"', $html);
        $this->assertStringNotContainsString('onclick=', $html);
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
