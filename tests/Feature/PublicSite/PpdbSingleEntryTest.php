<?php

namespace Tests\Feature\PublicSite;

use App\Enums\AccountClaimType;
use App\Livewire\Auth\GoogleClaim;
use App\Models\AccountClaim;
use App\Models\School;
use App\Models\SiteSetting;
use App\Models\Student;
use App\Support\GoogleIdentity;
use App\Support\PublicSite;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Satu tujuan pendaftaran, dan satu cabang yang terlihat (M7.2).
 *
 * Dua keputusan pemilik diuji bersama di sini karena keduanya menyentuh
 * permukaan yang sama: apa yang dilihat pengunjung yang belum menjadi siapa-siapa
 * di basis data ini.
 *
 * Seluruh alamat dan nama cabang pada berkas ini sintetis.
 */
class PpdbSingleEntryTest extends TestCase
{
    use RefreshDatabase;

    protected const FORM = 'https://forms.example.test/pendaftaran';

    protected School $aktif;

    protected School $disembunyikan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->aktif = School::factory()->create([
            'code' => 'PUSAT',
            'name' => 'Sekolah Contoh Pusat',
            'is_active' => true,
        ]);

        // Berdiri sebagai cabang yang **ada tetapi disembunyikan** — persis
        // keadaan cabang kedua sejak M7.2.
        $this->disembunyikan = School::factory()->create([
            'code' => 'CABANG2',
            'name' => 'Sekolah Contoh Cabang Dua',
            'is_active' => false,
        ]);
    }

    // ------------------------------------------------- satu tujuan pendaftaran

    public function test_ppdb_mengalihkan_ke_alamat_yang_disetel(): void
    {
        SiteSetting::set('ppdb_url', self::FORM);

        $this->get(route('ppdb.schools'))
            ->assertRedirect(self::FORM)
            // 302, bukan 301: keputusannya berbunyi "untuk saat ini", dan
            // pengalihan permanen tersimpan di peramban setiap pengunjung.
            ->assertStatus(302);
    }

    public function test_penanda_halaman_lama_per_cabang_tetap_hidup(): void
    {
        SiteSetting::set('ppdb_url', self::FORM);

        // Alamat yang sudah tersebar tidak menjadi 404; ia mengantar ke tujuan
        // yang berlaku sekarang.
        $this->get(route('ppdb.register', ['schoolCode' => 'pusat']))
            ->assertRedirect(self::FORM);

        $this->get('/ppdb/cabang2')->assertRedirect(self::FORM);
    }

    public function test_cek_status_tidak_ikut_dialihkan(): void
    {
        SiteSetting::set('ppdb_url', self::FORM);

        // Bukan pendaftaran melainkan pemeriksaan pendaftar yang sudah ada di
        // basis data ini; Google Form tidak dapat menjawabnya. Urutan rutenya
        // yang menjaga ini (butir 555).
        $this->get(route('ppdb.check-status'))->assertOk();
    }

    public function test_seluruh_cta_pendaftaran_menunjuk_alamat_yang_sama(): void
    {
        SiteSetting::set('ppdb_url', self::FORM);

        $landing = $this->get(route('landing'))->assertOk()->getContent();

        // Hero, bagian PPDB, dan footer — ketiganya.
        $this->assertSame(3, substr_count($landing, 'href="'.self::FORM.'"'));

        // Tidak satu pun CTA pendaftaran yang tertinggal menunjuk alur internal.
        $this->assertStringNotContainsString('href="'.route('ppdb.schools').'"', $landing);

        // Termasuk yang di halaman masuk — sebelum M7.2 ia satu-satunya yang
        // tidak ikut berpindah (butir 556).
        $login = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('href="'.self::FORM.'"', $login);
        $this->assertStringNotContainsString('href="'.route('ppdb.schools').'"', $login);
    }

    public function test_tanpa_alamat_yang_disetel_alur_internal_tetap_utuh(): void
    {
        // Cadangannya bukan alamat karangan dan bukan galat: halaman PPDB
        // aplikasi ini, persis seperti sebelum batch ini (butir 554).
        $this->assertNull(SiteSetting::get('ppdb_url'));

        $this->get(route('ppdb.schools'))->assertOk();
        $this->get(route('ppdb.register', ['schoolCode' => 'pusat']))->assertOk();

        $this->assertSame(route('ppdb.schools'), app(PublicSite::class)->ppdbUrl());
        $this->assertFalse(app(PublicSite::class)->ppdbIsExternal());
    }

    // --------------------------------------------------------- keamanan alamat

    #[DataProvider('unsafeUrls')]
    public function test_alamat_yang_tidak_aman_tidak_pernah_dipakai(string $unsafe): void
    {
        SiteSetting::set('ppdb_url', $unsafe);

        $site = app(PublicSite::class);

        $this->assertNull($site->externalPpdbUrl(), "{$unsafe} seharusnya ditolak");
        $this->assertFalse($site->ppdbIsExternal());

        // Jatuh ke cadangan yang aman, bukan mengalihkan ke nilai itu.
        $this->assertSame(route('ppdb.schools'), $site->ppdbUrl());
        $this->get(route('ppdb.schools'))->assertOk();

        // Dan tidak pernah tercetak sebagai href di halaman muka.
        $this->assertStringNotContainsString(
            'href="'.$unsafe.'"',
            $this->get(route('landing'))->assertOk()->getContent(),
        );
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function unsafeUrls(): array
    {
        return [
            ['javascript:alert(1)'],
            ['JavaScript:alert(1)'],
            ['data:text/html;base64,PHNjcmlwdD4='],
            ['data://text/html,<script>'],
            ['file:///etc/passwd'],
            ['ftp://contoh.test/formulir'],
            ['//contoh.test/tanpa-skema'],
            ['bukan alamat sama sekali'],
            ['https://'],
        ];
    }

    #[DataProvider('safeUrls')]
    public function test_alamat_http_dan_https_diterima(string $safe): void
    {
        SiteSetting::set('ppdb_url', $safe);

        $this->assertSame($safe, app(PublicSite::class)->externalPpdbUrl());
        $this->get(route('ppdb.schools'))->assertRedirect($safe);
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function safeUrls(): array
    {
        return [
            ['https://forms.example.test/x'],
            ['http://forms.example.test/x'],
            ['HTTPS://forms.example.test/x'],
            ['https://forms.example.test/x?usp=sf_link'],
        ];
    }

    // ------------------------------------------------------ cabang tersembunyi

    public function test_cabang_nonaktif_tidak_muncul_di_daftar_publik(): void
    {
        $html = $this->get(route('ppdb.schools'))->assertOk()->getContent();

        $this->assertStringContainsString('Sekolah Contoh Pusat', $html);
        $this->assertStringNotContainsString('Sekolah Contoh Cabang Dua', $html);
    }

    public function test_halaman_pendaftaran_cabang_nonaktif_tidak_dapat_dibuka(): void
    {
        // Tanpa alamat luar, alur internal berlaku — dan cabang yang
        // disembunyikan tetap tidak menerima pendaftar.
        $this->get(route('ppdb.register', ['schoolCode' => 'cabang2']))->assertNotFound();
    }

    public function test_cabang_nonaktif_tidak_ditawarkan_pada_permintaan_akun_staf(): void
    {
        $this->arriveAsGoogleUser();

        $component = Livewire::test(GoogleClaim::class)
            ->set('type', AccountClaimType::StafSekolah->value);

        $options = $component->instance()->schoolOptions();

        $this->assertArrayHasKey($this->aktif->id, $options);
        $this->assertArrayNotHasKey($this->disembunyikan->id, $options);

        $component->assertDontSee('Sekolah Contoh Cabang Dua');
    }

    public function test_id_cabang_tersembunyi_yang_dipaksakan_tidak_pernah_menjadi_permintaan(): void
    {
        // Dengan satu cabang layak, pemilihnya tidak ditampilkan dan nilainya
        // diisi sendiri — sehingga id yang diselundupkan ke payload Livewire
        // ditimpa sebelum sempat divalidasi. Yang dijamin di sini bukan pesan
        // galatnya melainkan akibatnya: tidak ada permintaan yang pernah
        // menunjuk cabang tersembunyi.
        $this->arriveAsGoogleUser();

        Livewire::test(GoogleClaim::class)
            ->set('type', AccountClaimType::StafSekolah->value)
            ->set('schoolId', (string) $this->disembunyikan->id)
            ->call('submit')
            ->assertHasNoErrors();

        $claim = AccountClaim::query()->withoutGlobalScopes()->sole();

        $this->assertSame($this->aktif->id, $claim->school_id);
        $this->assertNotSame($this->disembunyikan->id, $claim->school_id);
    }

    public function test_cabang_nonaktif_ditolak_validasi_ketika_pemilih_ditampilkan(): void
    {
        // Dua cabang layak: pemilihnya nyata, tidak ada pengisian sendiri, dan
        // id di luar daftar ditolak validasi.
        School::factory()->create(['code' => 'CABANG3', 'name' => 'Cabang Tiga', 'is_active' => true]);

        $this->arriveAsGoogleUser();

        Livewire::test(GoogleClaim::class)
            ->set('type', AccountClaimType::StafSekolah->value)
            ->set('schoolId', (string) $this->disembunyikan->id)
            ->call('submit')
            ->assertHasErrors('schoolId');

        $this->assertSame(0, AccountClaim::query()->withoutGlobalScopes()->count());
    }

    public function test_satu_cabang_layak_diisi_sendiri_tanpa_pemilih(): void
    {
        $this->arriveAsGoogleUser();

        $component = Livewire::test(GoogleClaim::class)
            ->set('type', AccountClaimType::StafSekolah->value);

        $this->assertTrue($component->instance()->schoolChoiceIsImplicit());
        $this->assertSame('Sekolah Contoh Pusat', $component->instance()->implicitSchoolName());

        // Dikirim tanpa menyentuh pemilih sama sekali.
        $component->call('submit')->assertHasNoErrors();

        $claim = AccountClaim::query()->withoutGlobalScopes()->sole();

        $this->assertSame($this->aktif->id, $claim->school_id);
    }

    public function test_pemilih_muncul_kembali_begitu_cabang_kedua_diaktifkan(): void
    {
        // Aturannya diturunkan dari data, bukan dari nama cabang: mengaktifkan
        // kembali cabang kedua sudah cukup (butir 557).
        $this->disembunyikan->forceFill(['is_active' => true])->save();

        $this->arriveAsGoogleUser();

        $component = Livewire::test(GoogleClaim::class)
            ->set('type', AccountClaimType::StafSekolah->value);

        $this->assertFalse($component->instance()->schoolChoiceIsImplicit());
        $this->assertCount(2, $component->instance()->schoolOptions());
        $component->assertSee('Sekolah Contoh Cabang Dua');
    }

    // ------------------------------------------------------ arsitektur cabang

    public function test_arsitektur_cabang_tidak_dihapus(): void
    {
        // "Disembunyikan", bukan "dihapus": barisnya masih ada, tabelnya masih
        // ada, dan kolom tenant di seluruh model bisnis tidak tersentuh
        // (butir 558).
        $this->assertTrue(Schema::hasTable('schools'));
        $this->assertTrue(Schema::hasColumn('users', 'school_id'));
        $this->assertTrue(Schema::hasColumn('students', 'school_id'));
        $this->assertTrue(Schema::hasColumn('account_claims', 'school_id'));

        $this->assertSame(2, School::query()->count());

        $tersembunyi = School::query()->findOrFail($this->disembunyikan->id);

        $this->assertFalse($tersembunyi->is_active);
        $this->assertSame('Sekolah Contoh Cabang Dua', $tersembunyi->name);
    }

    public function test_data_cabang_tersembunyi_tetap_dapat_dibaca_admin(): void
    {
        // Menyembunyikan dari alur publik tidak boleh berarti kehilangan data
        // operasionalnya.
        $siswa = Student::factory()->create([
            'school_id' => $this->disembunyikan->id,
        ]);

        $this->assertSame(
            1,
            Student::query()
                ->withoutGlobalScopes()
                ->where('school_id', $this->disembunyikan->id)
                ->count(),
        );

        $this->assertSame($this->disembunyikan->id, $siswa->fresh()->school_id);
    }

    // ---------------------------------------------- PPDB ≠ klaim akun Google

    public function test_pendaftaran_ppdb_tidak_pernah_mengantar_ke_klaim_akun(): void
    {
        SiteSetting::set('ppdb_url', self::FORM);

        // Yang menekan "Daftar PPDB" adalah calon siswa yang belum tercatat di
        // basis data ini; ia tidak punya apa pun untuk diklaim (butir 556).
        $this->get(route('ppdb.schools'))
            ->assertRedirect(self::FORM)
            ->assertRedirectContains('forms.example.test');

        $this->get(route('ppdb.schools'))->assertRedirect(self::FORM);

        $landing = $this->get(route('landing'))->assertOk()->getContent();

        $this->assertStringNotContainsString(route('oauth.google.claim'), $landing);
        $this->assertStringNotContainsString(route('oauth.google.redirect'), $landing);
    }

    public function test_klaim_akun_google_tetap_di_halaman_masuk(): void
    {
        SiteSetting::set('ppdb_url', self::FORM);

        config([
            'services.google.client_id' => 'uji',
            'services.google.client_secret' => 'uji',
            'services.google.redirect' => 'http://localhost/masuk/google/callback',
        ]);

        $login = $this->get(route('login'))->assertOk()->getContent();

        // Kedua jalur berdiri berdampingan di halaman yang sama, dan menuju
        // tempat yang berbeda.
        $this->assertStringContainsString(route('oauth.google.redirect'), $login);
        $this->assertStringContainsString(self::FORM, $login);

        // Halaman pencocokan tidak dapat dicapai dari alur PPDB.
        $this->get(route('oauth.google.claim'))->assertRedirect(route('login'));
    }

    protected function arriveAsGoogleUser(): void
    {
        (new GoogleIdentity('sub-m72', 'staf.uji@example.test', 'Pengguna Uji'))->putInSession();
    }
}
