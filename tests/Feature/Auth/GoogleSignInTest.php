<?php

namespace Tests\Feature\Auth;

use App\Enums\AuthProvider;
use App\Enums\RoleName;
use App\Models\AccountClaim;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\GoogleIdentity;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Events\Login as LoginEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Lapisan OAuth-nya sendiri: dari tombol sampai identitas terbukti.
 *
 * Tidak ada satu pun panggilan sungguhan ke Google di sini. Yang dipalsukan
 * hanya batas terluarnya — objek pengguna yang dikembalikan Socialite — supaya
 * seluruh yang ada di sebelah dalamnya diuji apa adanya.
 */
class GoogleSignInTest extends TestCase
{
    use FakesGoogleOAuth, RefreshDatabase;

    protected School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->enableGoogleOAuth();

        $this->school = School::factory()->create(['code' => 'PUSAT', 'is_active' => true]);
    }

    public function test_tombol_masuk_google_mengarahkan_ke_penyedia(): void
    {
        $this->fakeGoogleRedirect();

        $this->get(route('oauth.google.redirect'))
            ->assertRedirectContains('accounts.google.com');
    }

    public function test_rute_oauth_menghilang_ketika_kredensial_belum_disetel(): void
    {
        // Lingkungan yang belum disetel tidak memperlihatkan pintu yang tidak
        // dapat dibuka — dan tidak pula membocorkan bahwa fiturnya ada.
        config([
            'services.google.client_id' => null,
            'services.google.client_secret' => null,
            'services.google.redirect' => null,
        ]);

        $this->get(route('oauth.google.redirect'))->assertNotFound();
        $this->get(route('oauth.google.callback'))->assertNotFound();
    }

    public function test_tombolnya_tidak_muncul_ketika_kredensial_belum_disetel(): void
    {
        config(['services.google.client_id' => null]);

        $this->get(route('login'))->assertDontSee('Masuk dengan Google');
    }

    public function test_tombolnya_muncul_ketika_kredensial_sudah_disetel(): void
    {
        $this->get(route('login'))->assertSee('Masuk dengan Google');
    }

    public function test_identitas_baru_dibawa_ke_halaman_pencocokan(): void
    {
        $this->fakeGoogleUser('sub-baru-1', 'Calon.Siswa@Example.Test', 'Calon Siswa');

        $this->get(route('oauth.google.callback'))
            ->assertRedirect(route('oauth.google.claim'));

        $identity = GoogleIdentity::fromSession();

        $this->assertNotNull($identity);
        $this->assertSame('sub-baru-1', $identity->subject);

        // Dinormalkan: trim + huruf kecil.
        $this->assertSame('calon.siswa@example.test', $identity->email);

        $this->assertGuest();
    }

    public function test_kegagalan_pertukaran_kode_ditolak_dengan_satu_kalimat(): void
    {
        $this->fakeGoogleFailure();

        $this->get(route('oauth.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertNull(GoogleIdentity::fromSession());
        $this->assertGuest();
    }

    public function test_surel_yang_belum_diverifikasi_penyedia_ditolak(): void
    {
        // Tanpa syarat ini, siapa pun yang menguasai domain sendiri dapat
        // mengaku beralamat apa saja — dan alamat itulah yang dibaca admin.
        $this->fakeGoogleUser('sub-belum-verif', 'palsu@example.test', 'Palsu', verified: false);

        $this->get(route('oauth.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertNull(GoogleIdentity::fromSession());
    }

    public function test_identitas_tanpa_surel_ditolak(): void
    {
        $this->fakeGoogleUser('sub-tanpa-surel', '', 'Tanpa Surel');

        $this->get(route('oauth.google.callback'))->assertRedirect(route('login'));

        $this->assertNull(GoogleIdentity::fromSession());
    }

    public function test_identitas_tanpa_subject_ditolak(): void
    {
        // `sub` adalah satu-satunya pengenal yang dipakai. Tanpa ia, tidak ada
        // yang dapat ditaut ke apa pun.
        $this->fakeGoogleUser('', 'ada@example.test', 'Tanpa Sub');

        $this->get(route('oauth.google.callback'))->assertRedirect(route('login'));

        $this->assertNull(GoogleIdentity::fromSession());
    }

    public function test_callback_berulang_untuk_identitas_yang_sama_tidak_menambah_apa_pun(): void
    {
        $this->fakeGoogleUser('sub-berulang', 'berulang@example.test', 'Berulang');

        $this->get(route('oauth.google.callback'))->assertRedirect(route('oauth.google.claim'));
        $this->get(route('oauth.google.callback'))->assertRedirect(route('oauth.google.claim'));
        $this->get(route('oauth.google.callback'))->assertRedirect(route('oauth.google.claim'));

        // Callback tidak membuat apa pun: bukan akun, bukan permintaan.
        $this->assertSame(0, User::query()->where('auth_provider', AuthProvider::Google->value)->count());
        $this->assertSame(0, AccountClaim::query()->count());
    }

    public function test_akun_yang_sudah_tertaut_langsung_masuk_tanpa_ditanya_lagi(): void
    {
        Event::fake([LoginEvent::class]);

        $user = $this->linkedStudentUser('sub-tertaut', 'siswa.tertaut@example.test');

        $this->fakeGoogleUser('sub-tertaut', 'siswa.tertaut@example.test', 'Siswa Tertaut');

        $this->get(route('oauth.google.callback'))
            ->assertRedirect(route('student.dashboard'));

        $this->assertAuthenticatedAs($user);

        // Tidak singgah di halaman pencocokan sama sekali (§14).
        $this->assertNull(GoogleIdentity::fromSession());

        // `last_login_at` dan jejak audit menumpang peristiwa ini, persis
        // seperti jalur kata sandi.
        Event::assertDispatched(LoginEvent::class);
    }

    public function test_pencarian_akun_memakai_subject_bukan_surel(): void
    {
        // Surel Google yang dilepas dapat diberikan kepada orang lain; `sub`
        // tidak pernah dipakai ulang. Akun dengan surel yang sama tetapi
        // subject berbeda karena itu bukan akun yang sama.
        $this->linkedStudentUser('sub-asli', 'dipakai.ulang@example.test');

        $this->fakeGoogleUser('sub-pemilik-baru', 'dipakai.ulang@example.test', 'Pemilik Baru');

        $this->get(route('oauth.google.callback'))
            ->assertRedirect(route('oauth.google.claim'));

        $this->assertGuest();
    }

    public function test_akun_nonaktif_ditolak_tanpa_menyisakan_sesi(): void
    {
        $user = $this->linkedStudentUser('sub-nonaktif', 'nonaktif@example.test');
        $user->forceFill(['is_active' => false])->save();

        $this->fakeGoogleUser('sub-nonaktif', 'nonaktif@example.test', 'Nonaktif');

        $this->get(route('oauth.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        // Tidak boleh ada pengguna yang setengah masuk (butir 157).
        $this->assertGuest();
        $this->assertFalse(Auth::check());
    }

    public function test_tidak_ada_token_google_yang_disimpan_di_mana_pun(): void
    {
        $this->fakeGoogleUser('sub-token', 'token@example.test', 'Token', token: 'ya29.RAHASIA-SEKALI');

        $this->get(route('oauth.google.callback'))->assertRedirect(route('oauth.google.claim'));

        // Sesi hanya memuat tiga nilai, dan tidak satu pun di antaranya token.
        $stored = session(GoogleIdentity::SESSION_KEY);

        $this->assertSame(['subject', 'email', 'name'], array_keys($stored));
        $this->assertStringNotContainsString('ya29.', json_encode($stored));

        // Skemanya sendiri tidak menyediakan tempat untuk menyimpannya.
        $this->assertFalse(
            Schema::hasColumn('account_claims', 'token'),
        );
        $this->assertFalse(
            Schema::hasColumn('users', 'token'),
        );
    }

    public function test_halaman_pencocokan_menolak_dibuka_tanpa_identitas_di_sesi(): void
    {
        // Satu-satunya penulis identitas di sesi adalah callback.
        $this->get(route('oauth.google.claim'))->assertRedirect(route('login'));
    }

    /**
     * Akun siswa yang sudah tertaut penuh — hasil akhir sebuah persetujuan.
     */
    protected function linkedStudentUser(string $subject, string $email): User
    {
        $user = User::factory()
            ->forSchool($this->school)
            ->withRole(RoleName::Siswa)
            ->create([
                'email' => $email,
                'auth_provider' => AuthProvider::Google->value,
                'provider_subject' => $subject,
                'must_change_password' => false,
            ]);

        Student::factory()->create([
            'school_id' => $this->school->id,
            'user_id' => $user->id,
        ]);

        return $user;
    }
}
