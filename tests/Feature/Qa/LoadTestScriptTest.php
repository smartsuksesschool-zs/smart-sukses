<?php

namespace Tests\Feature\Qa;

use Tests\TestCase;

/**
 * NFR 1.2 — kesiapan uji beban.
 *
 * Skrip k6 tidak dapat dijalankan suite ini: k6 tidak terpasang, dan tidak ada
 * server produksi untuk dituju. Yang **dapat** dipastikan adalah sifat-sifat
 * yang berbahaya bila salah, dan yang tidak menuntut eksekusi untuk
 * membuktikannya:
 *
 * - tidak ada rahasia yang ikut ter-commit;
 * - skenario yang menulis benar-benar berpagar;
 * - produksi tidak dapat dijadikan sasaran tulis lewat variabel environment;
 * - variabel yang dibutuhkan benar-benar didokumentasikan.
 *
 * Yang **tidak** diklaim: bahwa skripnya menghasilkan angka yang benar. Itu
 * hanya dapat dibuktikan dengan menjalankannya di server yang menyerupai
 * produksi (butir 399).
 */
class LoadTestScriptTest extends TestCase
{
    protected const DIR = 'ops/load-tests';

    // ------------------------------------------------------------ inventaris

    public function test_every_required_scenario_exists(): void
    {
        $expected = [
            '01-landing.js',
            '02-ppdb.js',
            '03-student-api.js',
            '04-cbt-read.js',
            '05-cbt-autosave.js',
            'lib/config.js',
            'lib/auth.js',
            'lib/livewire.js',
        ];

        foreach ($expected as $file) {
            $this->assertFileExists(base_path(static::DIR.'/'.$file));
        }
    }

    /**
     * k6 memakai runtime-nya sendiri (goja). Menuntut Node berarti menuntut
     * satu rantai peralatan lagi di server yang belum pernah dipasang siapa
     * pun menjelang go-live.
     */
    public function test_the_scripts_need_neither_node_nor_a_package_manager(): void
    {
        foreach ($this->scripts() as $path => $body) {
            $this->assertStringNotContainsString('require(', $body, $path);
            $this->assertStringNotContainsString('process.env', $body, $path);
            $this->assertStringNotContainsString('node_modules', $body, $path);
        }

        $this->assertFileDoesNotExist(base_path(static::DIR.'/package.json'));
    }

    // --------------------------------------------------------------- rahasia

    /**
     * Tidak ada kredensial, token, maupun host yang tertulis di dalam skrip.
     * Semuanya datang dari environment saat dijalankan.
     */
    public function test_no_script_carries_a_secret(): void
    {
        $forbidden = [
            // Penetapan kredensial literal.
            '/password\s*[:=]\s*[\'"][^\'"]{3,}[\'"]/i',
            '/token\s*[:=]\s*[\'"][a-z0-9|]{16,}[\'"]/i',
            '/Bearer\s+[A-Za-z0-9|_\-]{16,}/',
            // Alamat surel sungguhan.
            '/[a-z0-9._%+-]+@(?!example\.)[a-z0-9.-]+\.[a-z]{2,}/i',
            // Hash kata sandi.
            '/\$2y\$/',
        ];

        foreach ($this->scripts() as $path => $body) {
            foreach ($forbidden as $pattern) {
                $this->assertDoesNotMatchRegularExpression($pattern, $body, $path.' must carry no secret');
            }
        }
    }

    /**
     * Nama variabel environment boleh — dan harus — disebut. Yang dilarang
     * adalah nilainya. Uji ini memastikan pemeriksaan di atas tidak lulus
     * hanya karena skripnya memang tidak menyebut kredensial sama sekali.
     */
    public function test_the_scripts_read_their_credentials_from_the_environment(): void
    {
        $config = $this->script('lib/config.js');

        foreach (['STUDENT_PASSWORD', 'STUDENT_EMAIL_PATTERN', 'API_TOKEN', 'BASE_URL'] as $var) {
            $this->assertStringContainsString('__ENV.'.$var, $config, $var.' must come from the environment');
        }
    }

    // ---------------------------------------------------------------- pagar

    /**
     * Skenario tulis mati secara bawaan.
     */
    public function test_the_write_scenario_is_disabled_unless_explicitly_enabled(): void
    {
        $config = $this->script('lib/config.js');

        $this->assertStringContainsString('LOAD_TEST_ALLOW_WRITES', $config);
        $this->assertMatchesRegularExpression(
            "/__ENV\.LOAD_TEST_ALLOW_WRITES\s*!==\s*'true'/",
            $config,
            'writes must be opt-in, never opt-out',
        );

        $autosave = $this->script('05-cbt-autosave.js');

        $this->assertStringContainsString('guardWrites(', $autosave);
    }

    /**
     * Pagar produksi tidak boleh dapat dibuka lewat environment.
     *
     * Ini yang membedakan pagar dari peringatan. Sebuah variabel environment
     * dapat tersetel tidak sengaja di terminal seseorang, tertinggal di berkas
     * `.env`, atau tersalin dari catatan lama. Menyunting daftar host adalah
     * perubahan kode yang terlihat di review.
     */
    public function test_production_cannot_be_made_a_write_target_through_the_environment(): void
    {
        $config = $this->script('lib/config.js');

        $this->assertStringContainsString('apps.smartsukses.sch.id', $config);
        $this->assertStringContainsString('isProductionHost', $config);

        // Pagar host berada di `guardWrites`, dan tidak ada variabel
        // environment yang dapat melewatinya.
        $guard = $this->blockOf($config, 'export function guardWrites');

        $this->assertStringContainsString('isProductionHost', $guard);
        $this->assertMatchesRegularExpression(
            '/isProductionHost\([^)]*\)\s*\)\s*\{/',
            $guard,
            'the production check must be unconditional',
        );

        // Tidak ada nama environment apa pun yang muncul di dalam cabang host.
        $afterHostCheck = substr($guard, (int) strpos($guard, 'isProductionHost'));

        $this->assertDoesNotMatchRegularExpression(
            '/__ENV\./',
            $afterHostCheck,
            'no environment variable may override the production host refusal',
        );
    }

    /**
     * Modelnya satu percobaan per siswa. Bila seluruh VU memakai sesi yang
     * sama, yang terukur adalah pertengkaran kunci baris di MySQL, bukan
     * kapasitas aplikasi.
     */
    public function test_the_write_scenario_gives_each_virtual_user_its_own_student(): void
    {
        $auth = $this->script('lib/auth.js');
        $autosave = $this->script('05-cbt-autosave.js');

        $this->assertStringContainsString('{vu}', $auth);
        $this->assertStringContainsString('sessionHeaders(__VU)', $autosave);
    }

    /**
     * Skenario tulis menuntut ujian fiksur yang disebut eksplisit, bukan ujian
     * mana pun yang kebetulan ada.
     */
    public function test_the_write_scenario_requires_an_explicit_fixture_exam(): void
    {
        $autosave = $this->script('05-cbt-autosave.js');

        $this->assertStringContainsString('EXAM_ID', $autosave);
        $this->assertMatchesRegularExpression(
            '/if\s*\(!EXAM_ID\)\s*\{\s*throw new Error/s',
            $autosave,
        );
    }

    // ------------------------------------------------------------- ambang

    /**
     * Ambang batasnya angka roadmap, dan tidak dilonggarkan supaya hijau.
     */
    public function test_the_roadmap_thresholds_are_encoded_and_not_loosened(): void
    {
        $config = $this->script('lib/config.js');

        // API p95 < 500 ms.
        $this->assertStringContainsString('p(95)<500', $config);
        // Halaman utama < 3 detik.
        $this->assertStringContainsString('p(95)<3000', $config);
        // Kegagalan di bawah 1%.
        $this->assertStringContainsString('rate<0.01', $config);
    }

    /**
     * 200 VU tidak pernah menjadi langkah pertama.
     */
    public function test_the_staged_plan_starts_small_and_ends_at_two_hundred(): void
    {
        $config = $this->script('lib/config.js');

        $this->assertMatchesRegularExpression('/smoke:\s*\{\s*vus:\s*[1-5]\b/', $config);
        $this->assertMatchesRegularExpression('/baseline:\s*\{\s*vus:\s*(2\d|3\d|4\d|50)\b/', $config);
        $this->assertMatchesRegularExpression('/target:\s*\{\s*vus:\s*200\b/', $config);

        // Dan bawaannya smoke, bukan target.
        $this->assertStringContainsString("__ENV.STAGE || 'smoke'", $config);
    }

    // ------------------------------------------------------- dokumentasi

    public function test_every_environment_variable_the_scripts_read_is_documented(): void
    {
        $doc = file_get_contents(base_path('docs/load-testing.md'));

        $used = [];

        foreach ($this->scripts() as $body) {
            preg_match_all('/__ENV\.([A-Z0-9_]+)/', $body, $m);
            $used = array_merge($used, $m[1]);
        }

        $used = array_values(array_unique($used));

        $this->assertNotEmpty($used);

        foreach ($used as $var) {
            $this->assertStringContainsString(
                $var,
                $doc,
                $var.' is read by a script but not documented in docs/load-testing.md',
            );
        }
    }

    /**
     * Dokumen tidak boleh menyatakan syarat 200 pengguna sudah terpenuhi.
     * Tidak ada server yang menjalankannya.
     */
    public function test_the_documentation_does_not_claim_an_unexecuted_benchmark(): void
    {
        $doc = file_get_contents(base_path('docs/load-testing.md'));

        $this->assertStringContainsString('PREPARED', $doc);
        $this->assertStringContainsString('NOT YET EXECUTED', $doc);

        // Tidak ada baris yang menyebut 200 pengguna sebagai PASS.
        $this->assertDoesNotMatchRegularExpression(
            '/200[^\n]*\bPASS\b/i',
            $doc,
            'the 200-user requirement must not be marked PASS',
        );
    }

    // ------------------------------------------------------------ penunjang

    protected function script(string $name): string
    {
        return file_get_contents(base_path(static::DIR.'/'.$name));
    }

    /**
     * @return array<string, string>
     */
    protected function scripts(): array
    {
        $out = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path(static::DIR), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.js')) {
                $out[$file->getFilename()] = file_get_contents($file->getPathname());
            }
        }

        return $out;
    }

    /**
     * Badan sebuah fungsi JavaScript, dari deklarasinya sampai deklarasi
     * berikutnya.
     */
    protected function blockOf(string $source, string $declaration): string
    {
        $at = strpos($source, $declaration);

        $this->assertNotFalse($at, 'declaration not found: '.$declaration);

        $next = strpos($source, "\nexport function", $at + strlen($declaration));

        return $next === false ? substr($source, $at) : substr($source, $at, $next - $at);
    }
}
