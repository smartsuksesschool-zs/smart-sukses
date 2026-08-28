<?php

namespace Tests\Feature\Qa;

use App\Enums\RoleName;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NFR 1.3 / PORTAL-01 poin 3 — perilaku responsif pada markup dan CSS milik
 * sendiri.
 *
 * Yang **tidak** diuji di sini: apakah halamannya terlihat benar. Itu menuntut
 * mesin tata letak, dan tidak ada peramban yang dapat dicapai suite ini. Uji
 * yang mengaku memeriksa tampilan padahal hanya membaca berkas adalah uji yang
 * berbohong.
 *
 * Yang diuji: perangkap struktural yang **dapat** dibuktikan tanpa merender —
 * aturan CSS yang keberadaannya menentukan, dan nilai yang punya angka pasti
 * (sasaran sentuh 2,75rem). Setiap kasus di bawah lahir dari cacat yang
 * benar-benar ditemukan pada audit S9.4, bukan dari daftar keinginan.
 */
class ResponsiveMarkupTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------- perangkap flex item

    /**
     * Cacat paling serius yang ditemukan audit S9.4.
     *
     * `.nav__links` adalah flex item **dan** punya `overflow-x: auto`. Flex item
     * punya `min-width: auto`, artinya ia menolak menyusut di bawah lebar
     * min-content-nya. Tanpa `min-width: 0` penggulungnya tidak pernah aktif:
     * isinya meluber keluar `.nav__inner`, lalu `body { overflow-x: hidden }`
     * memotongnya begitu saja.
     *
     * Isi navbar halaman muka berjumlah sekitar 30rem — jauh di atas 22,5rem
     * yang tersedia pada layar 360px — dan tombol Masuk beserta pemilih bahasa
     * duduk paling kanan. Keduanya yang hilang (butir 390).
     */
    public function test_the_landing_nav_can_actually_scroll_instead_of_being_clipped(): void
    {
        $css = $this->layout('landing');

        $rule = $this->ruleFor($css, '.nav__links');

        $this->assertStringContainsString('overflow-x: auto', $rule);
        $this->assertStringContainsString(
            'min-width: 0',
            $rule,
            'a flex item with overflow-x:auto needs min-width:0 or it never scrolls',
        );
    }

    /**
     * Baris header portal memuat pemilih bahasa (5,5rem sejak S9.3), lonceng,
     * nama pengguna, dan tombol keluar. Nama pengguna itu data — panjangnya
     * tidak terbatas — jadi barisnya harus boleh membungkus (butir 391).
     */
    public function test_the_portal_user_row_wraps_and_the_name_can_break(): void
    {
        $css = $this->layout('portal');

        $rule = $this->ruleFor($css, '.portal-user');

        $this->assertStringContainsString('flex-wrap: wrap', $rule);
        $this->assertStringContainsString('min-width: 0', $rule);

        $nameRule = $this->ruleFor($css, '.portal-user__name');

        $this->assertStringContainsString('overflow-wrap: anywhere', $nameRule);

        // Dan kelasnya benar-benar dipakai, bukan CSS yatim.
        $this->assertStringContainsString(
            'portal-user__name',
            file_get_contents(resource_path('views/layouts/portal.blade.php')),
        );
    }

    // -------------------------------------------------- pagar lebar halaman

    /**
     * Ketiga tata letak publik harus memasang pagar yang sama. PPDB tidak
     * punya satu pun sebelum audit ini — justru permukaan yang paling banyak
     * dibuka dari ponsel (butir 392).
     */
    public function test_every_custom_layout_guards_against_page_wide_overflow(): void
    {
        foreach (['portal', 'landing', 'ppdb'] as $layout) {
            $css = $this->layout($layout);

            $this->assertMatchesRegularExpression(
                '/body\s*\{[^}]*overflow-x:\s*hidden/s',
                $css,
                $layout.' must stop any element from widening the page',
            );

            $this->assertStringContainsString(
                'max-width: 100%',
                $css,
                $layout.' must keep images inside the viewport',
            );
        }
    }

    // ------------------------------------------------------ sasaran sentuh

    /**
     * 2,75rem (44px) adalah angka yang dipakai seluruh sistem. PPDB memakai
     * padding saja — sekitar 2,3rem — pada tombol kirim dan setiap kolom
     * isian, dan formulir itu diisi dari ponsel (butir 393).
     */
    public function test_public_ppdb_controls_meet_the_touch_target_used_everywhere(): void
    {
        $css = $this->layout('ppdb');

        foreach (['.btn', '.field input, .field select, .field textarea'] as $selector) {
            $this->assertMatchesRegularExpression(
                '/min-height:\s*2\.75rem/',
                $this->ruleFor($css, $selector),
                $selector.' must be at least 2.75rem tall',
            );
        }
    }

    public function test_the_locale_switch_keeps_its_touch_target_on_every_layout(): void
    {
        foreach (['portal', 'landing', 'ppdb'] as $layout) {
            $rule = $this->ruleFor($this->layout($layout), '.locale-switch__item');

            $this->assertStringContainsString('min-height: 2.75rem', $rule, $layout);
            $this->assertStringContainsString('min-width: 2.75rem', $rule, $layout);
        }
    }

    /**
     * Tombol perlu setelan ulang gaya, tetapi shorthand `font` menghapus
     * `font-size` dan `font-weight` yang ditulis di atasnya. Kesalahan yang
     * sempat terjadi saat pemilih bahasa berubah menjadi form (butir 389).
     */
    public function test_the_locale_switch_reset_does_not_clobber_its_own_font_size(): void
    {
        foreach (['portal', 'landing', 'ppdb'] as $layout) {
            $rule = $this->ruleFor($this->layout($layout), '.locale-switch__item');

            $this->assertStringContainsString('font-family: inherit', $rule, $layout);
            $this->assertDoesNotMatchRegularExpression(
                '/(^|[;{\s])font:\s*inherit/',
                $rule,
                $layout.' must not use the font shorthand here',
            );
        }
    }

    // ------------------------------------------------------------ tabel data

    /**
     * Tabel data boleh menggulung; yang tidak boleh adalah halamannya yang
     * ikut melebar. Setiap tabel milik sendiri karena itu harus berada di
     * dalam penggulungnya sendiri (butir 395).
     */
    public function test_every_own_data_table_sits_inside_its_own_scroller(): void
    {
        $views = [
            'livewire/portal/parent-grades.blade.php' => 'portal-scroll',
            'livewire/student/grades.blade.php' => 'portal-scroll',
            'filament/pages/laporan-keuangan.blade.php' => 'overflow-x-auto',
            'filament/pages/laporan-keuangan-cabang.blade.php' => 'overflow-x-auto',
        ];

        foreach ($views as $view => $scroller) {
            $html = file_get_contents(resource_path('views/'.$view));

            $tableAt = strpos($html, '<table');
            $this->assertNotFalse($tableAt, $view.' was expected to contain a table');

            $before = substr($html, 0, $tableAt);
            $lastScroller = strrpos($before, $scroller);

            $this->assertNotFalse(
                $lastScroller,
                $view.' must wrap its table in a '.$scroller.' container',
            );
        }
    }

    // ------------------------------------------------------------- CBT mobile

    /**
     * Halaman pengerjaan ujian adalah permukaan interaktif paling berisiko di
     * ponsel: satu-satunya yang dipakai di bawah tekanan waktu, dan
     * satu-satunya yang tidak dapat diulang siswa kalau tombolnya meleset.
     */
    public function test_the_exam_taking_page_stays_usable_on_a_narrow_phone(): void
    {
        $html = file_get_contents(resource_path('views/livewire/student/exam.blade.php'));

        // Baris judul + timer membungkus, dan sisi judulnya boleh menyusut.
        $this->assertStringContainsString('flex-wrap:wrap', $html);
        $this->assertStringContainsString('min-width:0', $html);

        // Peta nomor soal: tombol sebesar jari, dan barisnya membungkus.
        $this->assertStringContainsString('min-width:2.75rem', $html);

        // Pilihan jawaban: setinggi jari, dan teks panjang dipatahkan alih-alih
        // mendorong halaman melebar.
        $this->assertStringContainsString('min-height:2.75rem', $html);
        $this->assertStringContainsString('overflow-wrap:anywhere', $html);

        // Teks soal menghormati baris barunya tanpa memakai <br> di data.
        $this->assertStringContainsString('white-space:pre-line', $html);
    }

    public function test_the_exam_list_lets_long_titles_break(): void
    {
        $html = file_get_contents(resource_path('views/livewire/student/exams.blade.php'));

        $this->assertStringContainsString('overflow-wrap:anywhere', $html);
        $this->assertStringContainsString('portal-badge', $html);
    }

    // --------------------------------------------- tidak ada lebar piksel mati

    /**
     * Lebar piksel tetap adalah cara paling langsung membuat halaman melebihi
     * layar. Yang dikecualikan hanya dua: keterangan pembaca layar 1px, dan
     * berkas PDF — PDF dicetak pada kertas, bukan pada layar ponsel.
     */
    public function test_no_web_view_pins_a_fixed_pixel_width(): void
    {
        $offenders = [];

        foreach ($this->webViews() as $file) {
            $contents = file_get_contents($file);

            preg_match_all('/(?<![a-z-])(min-|max-)?width:\s*(\d+)px/i', $contents, $m, PREG_SET_ORDER);

            foreach ($m as $match) {
                if ((int) $match[2] <= 1) {
                    continue; // sr-only
                }

                $offenders[] = basename($file).': '.$match[0];
            }
        }

        $this->assertSame([], $offenders);
    }

    // --------------------------------------------------- bilingual (S9.3)

    /**
     * Teks Inggris umumnya lebih panjang. Yang diperiksa di sini bukan
     * lebarnya — itu butuh peramban — melainkan bahwa wadah yang memuatnya
     * memang boleh membungkus, sehingga label yang lebih panjang menambah
     * tinggi, bukan lebar.
     */
    public function test_the_bilingual_surfaces_are_allowed_to_wrap(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $school = School::factory()->create(['code' => 'MADANI', 'is_active' => true]);
        $teacher = User::factory()->forSchool($school)->withRole(RoleName::Guru)->create(['locale' => 'en']);

        $portal = static::withoutCssComments(
            $this->actingAs($teacher)->get(route('teacher.dashboard'))->assertOk()->getContent(),
        );

        // Header portal membungkus, dan navigasinya menggulung sendiri.
        $this->assertStringContainsString('portal-header__inner', $portal);
        $this->assertStringContainsString('flex-wrap: wrap', $portal);
        $this->assertMatchesRegularExpression(
            '/\.portal-nav__inner\s*\{[^}]*overflow-x:\s*auto/s',
            $portal,
        );

        // Halaman muka dalam bahasa Inggris: navigasinya dapat menyusut.
        $this->post(route('locale.switch', ['locale' => 'en']));
        $landing = static::withoutCssComments($this->get('/')->assertOk()->getContent());

        $this->assertMatchesRegularExpression(
            '/\.nav__links\s*\{[^}]*min-width:\s*0/s',
            $landing,
        );
    }

    // ------------------------------------------------------------- penunjang

    protected function layout(string $name): string
    {
        return static::withoutCssComments(
            file_get_contents(resource_path('views/layouts/'.$name.'.blade.php')),
        );
    }

    /**
     * Komentar dibuang lebih dulu.
     *
     * Komentar penjelas di berkas ini memuat contoh seperti
     * `body { overflow-x: hidden }`, dan kurung tutup di dalamnya memotong
     * pencarian blok aturan tepat sebelum baris yang sedang diperiksa. Uji ini
     * sempat gagal justru karena komentar yang menjelaskannya.
     */
    protected static function withoutCssComments(string $css): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    /**
     * Isi satu blok aturan CSS, dicari dari selektornya.
     */
    protected function ruleFor(string $css, string $selector): string
    {
        $at = strpos($css, $selector.' {');

        $this->assertNotFalse($at, 'selector not found: '.$selector);

        $open = strpos($css, '{', $at);
        $close = strpos($css, '}', $open);

        return substr($css, $open, $close - $open);
    }

    /**
     * @return array<int, string>
     */
    protected function webViews(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            // PDF dicetak di kertas; lebar piksel di sana justru benar.
            if (str_contains(str_replace('\\', '/', $file->getPathname()), '/views/pdf/')) {
                continue;
            }

            $files[] = $file->getPathname();
        }

        return $files;
    }
}
