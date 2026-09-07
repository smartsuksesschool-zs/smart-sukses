<?php

namespace Tests\Feature\Auth;

use App\Enums\AccountClaimStatus;
use App\Enums\AccountClaimType;
use App\Enums\AuthProvider;
use App\Enums\RoleName;
use App\Exceptions\AccountClaimException;
use App\Filament\Resources\AccountClaimResource;
use App\Filament\Resources\AccountClaimResource\Pages\ListAccountClaims;
use App\Livewire\Auth\GoogleClaim;
use App\Livewire\Auth\Login;
use App\Models\AccountClaim;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\Auth\AccountClaimRegistrar;
use App\Services\Auth\AccountClaimReviewer;
use App\Support\GoogleIdentity;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pagar-pagar yang membuat alur ini boleh dibuka ke publik.
 *
 * Alur pendaftaran mandiri menempelkan formulir tanpa autentikasi pada data
 * induk siswa. Yang diuji di sini bukan bahwa alurnya bekerja — itu urusan
 * kedua berkas sebelah — melainkan bahwa ia tidak dapat dipakai untuk hal lain:
 * memindai NIS, menebak identitas, menaikkan peran sendiri, menyeberang cabang,
 * atau meninggalkan pengenal siswa di dalam log.
 */
class AccountClaimSecurityTest extends TestCase
{
    use FakesGoogleOAuth, RefreshDatabase;

    protected School $school;

    protected School $cabangLain;

    protected Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->enableGoogleOAuth();

        $this->school = School::factory()->create(['code' => 'PUSAT', 'is_active' => true]);
        $this->cabangLain = School::factory()->create(['code' => 'CABANG2', 'is_active' => true]);

        $this->student = Student::factory()->create([
            'school_id' => $this->school->id,
            'nis' => '2026001',
            'nisn' => '0091234567',
            'full_name' => 'Ananda Sintetis',
        ]);
    }

    public function test_percobaan_pencocokan_dibatasi(): void
    {
        // Yang dilindungi bukan satu akun melainkan seluruh daftar siswa:
        // percobaan berulang atas NIS yang berganti-ganti adalah pemindaian.
        $this->arriveAsGoogleUser('sub-pindai', 'pindai@example.test');

        for ($i = 0; $i < GoogleClaim::MAX_ATTEMPTS; $i++) {
            $this->claimForm()
                ->set('type', AccountClaimType::Siswa->value)
                ->set('nis', '99999'.$i)
                ->set('nisn', '99999999'.$i)
                ->call('submit')
                ->assertHasErrors('nis');
        }

        $component = $this->claimForm()
            ->set('type', AccountClaimType::Siswa->value)
            // NIS yang **benar**, dan tetap ditolak: batasnya sudah tercapai.
            ->set('nis', '2026001')
            ->set('nisn', '0091234567')
            ->call('submit')
            ->assertHasErrors('nis');

        $this->assertStringContainsString('Terlalu banyak percobaan', $component->errors()->first('nis'));
        $this->assertSame(0, AccountClaim::query()->count());
    }

    public function test_rute_oauth_dibatasi_laju(): void
    {
        $this->fakeGoogleRedirect();

        // 20 per menit per IP; yang ke-21 ditolak.
        for ($i = 0; $i < 20; $i++) {
            $this->get(route('oauth.google.redirect'))->assertRedirect();
        }

        $this->get(route('oauth.google.redirect'))->assertStatus(429);
    }

    public function test_identitas_penyedia_unik_di_tingkat_basis_data(): void
    {
        User::factory()->forSchool($this->school)->create([
            'auth_provider' => AuthProvider::Google->value,
            'provider_subject' => 'sub-kembar',
        ]);

        $this->expectException(QueryException::class);

        User::factory()->forSchool($this->school)->create([
            'auth_provider' => AuthProvider::Google->value,
            'provider_subject' => 'sub-kembar',
        ]);
    }

    public function test_akun_berkata_sandi_tidak_terpengaruh_indeks_identitas(): void
    {
        // Keduanya NULL, dan NULL dianggap berbeda oleh indeks unik: seluruh
        // akun staf yang ada tetap dapat hidup berdampingan.
        User::factory()->count(3)->forSchool($this->school)->create();

        $this->assertSame(3, User::query()->whereNull('auth_provider')->count());
    }

    public function test_hanya_satu_permintaan_disetujui_per_siswa_di_tingkat_basis_data(): void
    {
        // Pagar terakhir, di bawah seluruh pemeriksaan aplikasi: seandainya dua
        // transaksi lolos berbarengan, basis data tetap menolak yang kedua.
        $this->makeClaim('sub-x', 'x@example.test', AccountClaimType::Siswa, AccountClaimStatus::Approved);

        $this->expectException(QueryException::class);

        $this->makeClaim('sub-y', 'y@example.test', AccountClaimType::Siswa, AccountClaimStatus::Approved);
    }

    public function test_permintaan_menunggu_yang_sama_persis_tidak_dapat_menjadi_dua_baris(): void
    {
        $this->makeClaim('sub-z', 'z@example.test', AccountClaimType::Siswa, AccountClaimStatus::Pending);

        $this->expectException(QueryException::class);

        $this->makeClaim('sub-z', 'z@example.test', AccountClaimType::Siswa, AccountClaimStatus::Pending);
    }

    /**
     * Tidak satu pun nama peran dapat diminta dari jalur publik.
     *
     * Yang menahannya bukan sebuah daftar hitam melainkan **jenis kolomnya**:
     * `requested_type` hanya menerima `AccountClaimType`, dan tidak satu pun
     * dari ketiganya berupa peran. `GURU` yang diselundupkan ke payload karena
     * itu bukan sekadar ditolak — ia tidak punya tempat untuk mendarat
     * (butir 544).
     */
    #[DataProvider('staffRoles')]
    public function test_nama_peran_tidak_dapat_diminta_sendiri(string $role): void
    {
        $this->assertNotContains(
            $role,
            AccountClaimType::values(),
            "{$role} tidak boleh menjadi jenis permintaan yang dapat diminta sendiri",
        );

        $this->arriveAsGoogleUser('sub-naik-'.$role, 'naik@example.test');

        $this->claimForm()
            ->set('type', $role)
            ->set('nis', '2026001')
            ->set('nisn', '0091234567')
            ->call('submit')
            ->assertHasErrors('type');

        $this->assertSame(0, AccountClaim::query()->count());
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function staffRoles(): array
    {
        return [
            [RoleName::SuperAdmin->value],
            [RoleName::SchoolAdmin->value],
            [RoleName::KepalaSekolah->value],
            [RoleName::Guru->value],
            [RoleName::WaliKelas->value],
            [RoleName::Bendahara->value],
        ];
    }

    public function test_daftar_putih_peninjau_tidak_pernah_memuat_admin(): void
    {
        // Keduanya dapat membuat pengguna lain — SUPER_ADMIN bahkan melewati
        // seluruh policy lewat Gate::before. Jalur publik yang dapat berakhir
        // di salah satunya berarti pendaftaran mandiri yang menyerahkan cabang,
        // atau platform (butir 547).
        $this->assertNotContains(RoleName::SchoolAdmin, AccountClaim::REVIEWER_ASSIGNABLE_ROLES);
        $this->assertNotContains(RoleName::SuperAdmin, AccountClaim::REVIEWER_ASSIGNABLE_ROLES);
        $this->assertNotContains(RoleName::Siswa, AccountClaim::REVIEWER_ASSIGNABLE_ROLES);
        $this->assertNotContains(RoleName::OrangTua, AccountClaim::REVIEWER_ASSIGNABLE_ROLES);

        $this->assertEqualsCanonicalizing(
            [RoleName::Guru, RoleName::WaliKelas, RoleName::Bendahara, RoleName::KepalaSekolah],
            AccountClaim::REVIEWER_ASSIGNABLE_ROLES,
        );

        // Daftar yang sama dipakai pemilih peran pada layar admin.
        $this->assertSame(
            array_map(fn (RoleName $role) => $role->value, AccountClaim::REVIEWER_ASSIGNABLE_ROLES),
            array_keys(AccountClaim::assignableRoleOptions()),
        );
    }

    public function test_permintaan_siswa_tidak_dapat_dibuat_lewat_jalur_staf(): void
    {
        // Pagar kedua, di bawah validasi: bahkan pemanggilan langsung ditolak.
        $identity = new GoogleIdentity('sub-langsung', 'langsung@example.test', 'Langsung');

        $this->expectException(InvalidArgumentException::class);

        app(AccountClaimRegistrar::class)
            ->registerForStudent($identity, AccountClaimType::StafSekolah, $this->student);
    }

    #[DataProvider('rolesWithoutUserModule')]
    public function test_peran_tanpa_modul_user_tidak_melihat_antrean(string $role): void
    {
        $user = User::factory()->forSchool($this->school)->withRole(RoleName::from($role))->create();

        $this->assertFalse(AccountClaimResource::canViewAny());
        $this->actingAs($user);
        $this->assertFalse(AccountClaimResource::canViewAny());
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function rolesWithoutUserModule(): array
    {
        return [
            [RoleName::KepalaSekolah->value],
            [RoleName::Guru->value],
            [RoleName::WaliKelas->value],
            [RoleName::Bendahara->value],
            [RoleName::Siswa->value],
            [RoleName::OrangTua->value],
        ];
    }

    public function test_admin_sekolah_melihat_dan_memutuskan_antrean_cabangnya(): void
    {
        $claim = $this->makeClaim('sub-boleh', 'boleh@example.test', AccountClaimType::Siswa);
        $admin = $this->adminOf($this->school);

        $this->actingAs($admin);

        $this->assertTrue($admin->can('view', $claim));
        $this->assertTrue($admin->can('approve', $claim));
        $this->assertTrue($admin->can('reject', $claim));

        // Tetapi tidak boleh menyunting maupun menghapus.
        $this->assertFalse($admin->can('update', $claim));
        $this->assertFalse($admin->can('delete', $claim));
        $this->assertFalse($admin->can('create', AccountClaim::class));
    }

    public function test_permintaan_cabang_lain_tidak_terlihat_dan_tidak_dapat_disetujui(): void
    {
        $claim = $this->makeClaim('sub-seberang', 'seberang@example.test', AccountClaimType::Siswa);
        $asing = $this->adminOf($this->cabangLain);

        $this->actingAs($asing);

        // Global scope: barisnya tidak ada sama sekali baginya.
        $this->assertSame(0, AccountClaim::query()->count());

        // Policy: pagar kedua, seandainya barisnya tetap sampai ke tangannya.
        $this->assertFalse($asing->can('view', $claim));
        $this->assertFalse($asing->can('approve', $claim));

        Livewire::actingAs($asing)
            ->test(ListAccountClaims::class)
            ->assertCanNotSeeTableRecords([$claim]);
    }

    public function test_persetujuan_lintas_cabang_ditolak_walaupun_dipanggil_langsung(): void
    {
        $claim = $this->makeClaim('sub-paksa', 'paksa@example.test', AccountClaimType::Siswa);
        $asing = $this->adminOf($this->cabangLain);

        // Aksi Filament menyembunyikan tombolnya, tetapi pagar yang menentukan
        // bukan tombolnya melainkan policy — dan itu diuji terpisah di atas.
        $this->actingAs($asing);

        $this->assertFalse($asing->can('approve', $claim));
    }

    public function test_akun_google_yang_sudah_dipakai_tidak_dapat_mengklaim_siswa_kedua(): void
    {
        $kedua = Student::factory()->create([
            'school_id' => $this->school->id,
            'nis' => '2026002',
            'nisn' => '0091111111',
        ]);

        $admin = $this->adminOf($this->school);

        $pertama = $this->makeClaim('sub-serakah', 'serakah@example.test', AccountClaimType::Siswa);
        app(AccountClaimReviewer::class)->approve($pertama, $admin);

        $lagi = $this->makeClaim('sub-serakah', 'serakah@example.test', AccountClaimType::Siswa, student: $kedua);

        $this->expectException(AccountClaimException::class);

        app(AccountClaimReviewer::class)->approve($lagi, $admin);
    }

    public function test_surel_yang_sudah_dimiliki_akun_lain_tidak_digabungkan_diam_diam(): void
    {
        // Google membuktikan penguasaan kotak surel; ia tidak membuktikan bahwa
        // pemiliknya adalah orang yang dulu diberi akun staf beralamat itu.
        $staf = User::factory()
            ->forSchool($this->school)
            ->withRole(RoleName::Guru)
            ->create(['email' => 'guru@example.test']);

        $claim = $this->makeClaim('sub-tabrakan', 'guru@example.test', AccountClaimType::Siswa);

        try {
            app(AccountClaimReviewer::class)->approve($claim, $this->adminOf($this->school));
            $this->fail('persetujuan seharusnya ditolak');
        } catch (AccountClaimException) {
            // yang diperiksa akibatnya, bukan pesannya
        }

        // Akun staf tidak tersentuh sedikit pun.
        $staf->refresh();
        $this->assertNull($staf->auth_provider);
        $this->assertNull($staf->provider_subject);
        $this->assertSame([RoleName::Guru->value], $staf->roles()->pluck('name')->all());
        $this->assertNull($this->student->fresh()->user_id);
    }

    public function test_klaim_lintas_cabang_menempel_pada_cabang_siswanya(): void
    {
        $siswaCabangLain = Student::factory()->create([
            'school_id' => $this->cabangLain->id,
            'nis' => '9000001',
            'nisn' => '0095555555',
        ]);

        $this->arriveAsGoogleUser('sub-cabang', 'cabang@example.test');

        $this->claimForm()
            ->set('type', AccountClaimType::Siswa->value)
            ->set('nis', '9000001')
            ->set('nisn', '0095555555')
            ->call('submit')
            ->assertHasNoErrors();

        $claim = AccountClaim::query()->withoutGlobalScopes()->sole();

        // Cabangnya diturunkan dari siswanya, bukan dari pemohon — yang memang
        // tidak punya cabang untuk disebut.
        $this->assertSame($this->cabangLain->id, $claim->school_id);
        $this->assertSame($siswaCabangLain->id, $claim->student_id);
    }

    public function test_log_tidak_pernah_memuat_nis_nisn_atau_surel(): void
    {
        $baris = [];

        Log::listen(function ($pesan) use (&$baris): void {
            $baris[] = $pesan->message.' '.json_encode($pesan->context);
        });

        $this->arriveAsGoogleUser('sub-log', 'rahasia.surel@example.test');

        // Satu gagal, satu berhasil: keduanya menulis ke log.
        $this->claimForm()
            ->set('type', AccountClaimType::Siswa->value)
            ->set('nis', '2026001')
            ->set('nisn', '0000000000')
            ->call('submit');

        $this->claimForm()
            ->set('type', AccountClaimType::Siswa->value)
            ->set('nis', '2026001')
            ->set('nisn', '0091234567')
            ->call('submit');

        $semua = implode("\n", $baris);

        $this->assertNotSame('', $semua, 'tidak ada baris log yang tertangkap');

        foreach (['2026001', '0091234567', '0000000000', 'rahasia.surel@example.test'] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, $semua);
        }
    }

    public function test_pintu_masuknya_tetap_satu(): void
    {
        // Tidak ada halaman masuk kedua yang lahir dari batch ini.
        foreach (['/login/siswa', '/login/orangtua', '/login/admin', '/login/google'] as $tidakAda) {
            $this->get($tidakAda)->assertNotFound();
        }

        // Alamat lama tetap bermuara ke pintu yang sama.
        $this->get('/siswa/masuk')->assertRedirect(route('login'));
        $this->get('/portal/masuk')->assertRedirect(route('login'));
    }

    public function test_masuk_dengan_kata_sandi_tidak_berubah(): void
    {
        $staf = User::factory()
            ->forSchool($this->school)
            ->withRole(RoleName::SchoolAdmin)
            ->create([
                'email' => 'admin@example.test',
                'password' => bcrypt('rahasia123'),
            ]);

        Livewire::test(Login::class)
            ->set('email', 'admin@example.test')
            ->set('password', 'rahasia123')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($staf);
    }

    protected function arriveAsGoogleUser(string $subject, string $email): void
    {
        RateLimiter::clear('account-claim:'.sha1($subject).'|127.0.0.1');

        $this->fakeGoogleUser($subject, $email, 'Pengguna Uji');

        $this->get(route('oauth.google.callback'))->assertRedirect(route('oauth.google.claim'));
    }

    protected function claimForm(): Testable
    {
        return Livewire::test(GoogleClaim::class);
    }

    /**
     * Baris permintaan yang ditulis langsung — dipakai menguji pagar basis
     * data dan policy, yang tidak memerlukan alur publiknya.
     */
    protected function makeClaim(
        string $subject,
        string $email,
        AccountClaimType $type,
        AccountClaimStatus $status = AccountClaimStatus::Pending,
        ?Student $student = null,
    ): AccountClaim {
        $student ??= $this->student;

        return AccountClaim::query()->create([
            'school_id' => $student->school_id,
            'provider' => AuthProvider::Google->value,
            'provider_subject' => $subject,
            'email' => $email,
            'name' => 'Pengguna Uji',
            'requested_type' => $type->value,
            'student_id' => $student->getKey(),
            'status' => $status->value,
            'requested_at' => now(),
        ]);
    }

    protected function adminOf(School $school): User
    {
        return User::factory()
            ->forSchool($school)
            ->withRole(RoleName::SchoolAdmin)
            ->create();
    }
}
