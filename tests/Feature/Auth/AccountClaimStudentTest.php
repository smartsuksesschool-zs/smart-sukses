<?php

namespace Tests\Feature\Auth;

use App\Enums\AccountClaimStatus;
use App\Enums\AccountClaimType;
use App\Enums\AuthProvider;
use App\Enums\RoleName;
use App\Exceptions\AccountClaimException;
use App\Livewire\Auth\GoogleClaim;
use App\Models\AccountClaim;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\Auth\AccountClaimReviewer;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Klaim siswa: dari pencocokan NIS/NISN sampai akun yang benar-benar dapat masuk.
 *
 * Yang paling dijaga di sini bukan bahwa alurnya bekerja, melainkan bahwa
 * **pencocokan saja tidak pernah cukup**. NIS dan NISN tercetak di kartu
 * pelajar; kalau keduanya sudah membuat akun, kartu pelajar yang terjatuh
 * menjadi kunci portal.
 */
class AccountClaimStudentTest extends TestCase
{
    use FakesGoogleOAuth, RefreshDatabase;

    protected School $school;

    protected Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->enableGoogleOAuth();
        RateLimiter::clear('');

        $this->school = School::factory()->create(['code' => 'PUSAT', 'is_active' => true]);

        $this->student = Student::factory()->create([
            'school_id' => $this->school->id,
            'nis' => '2026001',
            'nisn' => '0091234567',
            'full_name' => 'Ananda Sintetis',
            'parent_name' => 'Wali Sintetis',
        ]);
    }

    public function test_nis_dan_nisn_yang_menunjuk_siswa_yang_sama_menjadi_permintaan_menunggu(): void
    {
        $this->arriveAsGoogleUser('sub-siswa-1', 'ananda@example.test');

        $this->claimForm()
            ->set('type', AccountClaimType::Siswa->value)
            ->set('nis', '2026001')
            ->set('nisn', '0091234567')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('state', 'pending');

        $claim = AccountClaim::query()->sole();

        $this->assertSame(AccountClaimStatus::Pending, $claim->status);
        $this->assertSame(AccountClaimType::Siswa, $claim->requested_type);
        $this->assertSame($this->student->id, $claim->student_id);
        $this->assertSame($this->school->id, $claim->school_id);

        // Yang paling penting: belum ada akun, dan belum ada tautan.
        $this->assertSame(0, User::query()->where('auth_provider', AuthProvider::Google->value)->count());
        $this->assertNull($this->student->fresh()->user_id);
    }

    public function test_nis_dan_nisn_yang_menunjuk_dua_siswa_berbeda_ditolak(): void
    {
        $lain = Student::factory()->create([
            'school_id' => $this->school->id,
            'nis' => '2026002',
            'nisn' => '0099999999',
        ]);

        $this->arriveAsGoogleUser('sub-konflik', 'konflik@example.test');

        $this->claimForm()
            ->set('type', AccountClaimType::Siswa->value)
            ->set('nis', '2026001')
            ->set('nisn', $lain->nisn)
            ->call('submit')
            ->assertHasErrors('nis')
            ->assertSet('state', 'form');

        $this->assertSame(0, AccountClaim::query()->count());
    }

    public function test_satu_nilai_yang_salah_sudah_cukup_untuk_gagal(): void
    {
        $this->arriveAsGoogleUser('sub-separuh', 'separuh@example.test');

        $this->claimForm()
            ->set('type', AccountClaimType::Siswa->value)
            ->set('nis', '2026001')
            ->set('nisn', '0000000000')
            ->call('submit')
            ->assertHasErrors('nis');

        $this->assertSame(0, AccountClaim::query()->count());
    }

    public function test_seluruh_kegagalan_memakai_kalimat_yang_sama(): void
    {
        // Pesan yang berbeda-beda akan mengubah formulir ini menjadi alat
        // menebak NIS: "tidak ditemukan" dan "hampir benar" adalah dua jawaban
        // yang sangat berbeda nilainya bagi orang yang menebak (butir 533).
        $lain = Student::factory()->create([
            'school_id' => $this->school->id,
            'nis' => '2026003',
            'nisn' => '0098888888',
        ]);

        $pesan = [];

        foreach ([['9999999', '9999999999'], ['2026001', '9999999999'], ['2026001', $lain->nisn]] as [$nis, $nisn]) {
            $this->arriveAsGoogleUser('sub-pesan-'.$nis.$nisn, 'pesan@example.test');

            $component = $this->claimForm()
                ->set('type', AccountClaimType::Siswa->value)
                ->set('nis', $nis)
                ->set('nisn', $nisn)
                ->call('submit');

            $pesan[] = $component->errors()->first('nis');

            RateLimiter::clear('account-claim:'.sha1('sub-pesan-'.$nis.$nisn).'|127.0.0.1');
        }

        $this->assertSame([GoogleClaim::REFUSED], array_values(array_unique($pesan)));
    }

    public function test_nama_tidak_pernah_dipakai_mencocokkan(): void
    {
        $this->arriveAsGoogleUser('sub-nama', 'nama@example.test');

        $this->claimForm()
            ->set('type', AccountClaimType::Siswa->value)
            ->set('nis', 'Ananda Sintetis')
            ->set('nisn', 'Ananda Sintetis')
            ->call('submit')
            ->assertHasErrors('nis');

        $this->assertSame(0, AccountClaim::query()->count());
    }

    public function test_siswa_yang_sudah_punya_akun_tidak_dapat_diklaim_lagi(): void
    {
        $pemilik = User::factory()->forSchool($this->school)->withRole(RoleName::Siswa)->create();
        $this->student->forceFill(['user_id' => $pemilik->id])->save();

        $this->arriveAsGoogleUser('sub-pengambil-alih', 'pengambil.alih@example.test');

        $this->claimForm()
            ->set('type', AccountClaimType::Siswa->value)
            ->set('nis', '2026001')
            ->set('nisn', '0091234567')
            ->call('submit')
            ->assertHasErrors('nis');

        $this->assertSame(0, AccountClaim::query()->count());
        $this->assertSame($pemilik->id, $this->student->fresh()->user_id);
    }

    public function test_permintaan_yang_diulang_tidak_menjadi_dua_baris(): void
    {
        $this->arriveAsGoogleUser('sub-ulang', 'ulang@example.test');

        $this->claimForm()
            ->set('type', AccountClaimType::Siswa->value)
            ->set('nis', '2026001')
            ->set('nisn', '0091234567')
            ->call('submit')
            ->assertSet('state', 'pending');

        // Kembali lewat Google sebelum disetujui: yang muncul keadaannya,
        // bukan formulir kosong yang mengundang permintaan kedua (§13).
        $this->arriveAsGoogleUser('sub-ulang', 'ulang@example.test');

        $this->claimForm()->assertSet('state', 'pending');

        $this->assertSame(1, AccountClaim::query()->count());
    }

    public function test_persetujuan_membuat_akun_siswa_yang_tertaut(): void
    {
        $claim = $this->pendingClaim('sub-setuju', 'setuju@example.test', AccountClaimType::Siswa);

        $user = app(AccountClaimReviewer::class)->approve($claim, $this->schoolAdmin());

        $this->assertSame($this->school->id, $user->school_id);
        $this->assertSame('setuju@example.test', $user->email);
        $this->assertSame('Ananda Sintetis', $user->name);
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->must_change_password);

        // Tepat satu peran (PRD 1.1.1).
        $this->assertSame([RoleName::Siswa->value], $user->roles()->pluck('name')->all());

        // Tanpa kata sandi sama sekali — tidak ada yang perlu dibagikan admin.
        $this->assertNull($user->password);
        $this->assertFalse($user->usesPasswordAuth());

        $this->assertSame($user->id, $this->student->fresh()->user_id);

        $claim->refresh();
        $this->assertSame(AccountClaimStatus::Approved, $claim->status);
        $this->assertSame($user->id, $claim->approved_user_id);
        $this->assertNotNull($claim->reviewed_at);
        $this->assertNotNull($claim->reviewed_by);
    }

    public function test_persetujuan_tidak_menyentuh_nis_nisn_maupun_baris_siswa_lain(): void
    {
        $claim = $this->pendingClaim('sub-utuh', 'utuh@example.test', AccountClaimType::Siswa);

        app(AccountClaimReviewer::class)->approve($claim, $this->schoolAdmin());

        $student = $this->student->fresh();

        $this->assertSame('2026001', $student->nis);
        $this->assertSame('0091234567', $student->nisn);
        $this->assertSame('Ananda Sintetis', $student->full_name);

        // Tidak ada siswa kedua yang lahir dari persetujuan.
        $this->assertSame(1, Student::query()->count());
    }

    public function test_akun_yang_disetujui_tidak_dapat_masuk_dengan_kata_sandi_apa_pun(): void
    {
        $claim = $this->pendingClaim('sub-tanpa-sandi', 'tanpa.sandi@example.test', AccountClaimType::Siswa);

        app(AccountClaimReviewer::class)->approve($claim, $this->schoolAdmin());

        // Hash NULL ditolak `AbstractHasher::check()`, jadi tidak ada satu pun
        // kata sandi yang cocok — termasuk string kosong.
        foreach (['', 'password', 'rahasia123'] as $percobaan) {
            $this->assertFalse(auth()->attempt([
                'email' => 'tanpa.sandi@example.test',
                'password' => $percobaan,
            ]));
        }

        $this->assertGuest();
    }

    public function test_penolakan_tidak_membuat_akun_maupun_tautan(): void
    {
        $claim = $this->pendingClaim('sub-tolak', 'tolak@example.test', AccountClaimType::Siswa);

        app(AccountClaimReviewer::class)->reject($claim, $this->schoolAdmin(), 'Bukan siswa kami.');

        $claim->refresh();

        $this->assertSame(AccountClaimStatus::Rejected, $claim->status);
        $this->assertNull($claim->approved_user_id);
        $this->assertSame(0, User::query()->where('auth_provider', AuthProvider::Google->value)->count());
        $this->assertNull($this->student->fresh()->user_id);
    }

    public function test_persetujuan_kedua_atas_permintaan_yang_sama_ditolak(): void
    {
        $claim = $this->pendingClaim('sub-dobel', 'dobel@example.test', AccountClaimType::Siswa);
        $admin = $this->schoolAdmin();

        app(AccountClaimReviewer::class)->approve($claim, $admin);

        $this->expectException(AccountClaimException::class);

        // Klik kedua pada tombol yang sama; barisnya sudah tidak dapat ditinjau.
        app(AccountClaimReviewer::class)->approve($claim->fresh(), $admin);
    }

    public function test_dua_permintaan_atas_satu_siswa_hanya_satu_yang_dapat_disetujui(): void
    {
        $pertama = $this->pendingClaim('sub-a', 'a@example.test', AccountClaimType::Siswa);
        $kedua = $this->pendingClaim('sub-b', 'b@example.test', AccountClaimType::Siswa);

        $admin = $this->schoolAdmin();

        app(AccountClaimReviewer::class)->approve($pertama, $admin);

        $this->expectException(AccountClaimException::class);

        app(AccountClaimReviewer::class)->approve($kedua, $admin);
    }

    public function test_setelah_disetujui_masuk_google_langsung_ke_dasbor_siswa(): void
    {
        $claim = $this->pendingClaim('sub-mendarat', 'mendarat@example.test', AccountClaimType::Siswa);

        $user = app(AccountClaimReviewer::class)->approve($claim, $this->schoolAdmin());

        $this->fakeGoogleUser('sub-mendarat', 'mendarat@example.test', 'Ananda');

        $this->get(route('oauth.google.callback'))
            ->assertRedirect(route('student.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_permintaan_yang_ditolak_terlihat_sebagai_keadaan_bukan_formulir(): void
    {
        $claim = $this->pendingClaim('sub-ditolak', 'ditolak@example.test', AccountClaimType::Siswa);

        app(AccountClaimReviewer::class)->reject($claim, $this->schoolAdmin(), 'catatan internal');

        $this->arriveAsGoogleUser('sub-ditolak', 'ditolak@example.test');

        $component = $this->claimForm()->assertSet('state', 'rejected');

        // Catatan internalnya tidak pernah sampai ke pemohon (butir 533).
        $component->assertDontSee('catatan internal');
        $component->assertSee(GoogleClaim::REJECTED_MESSAGE);
    }

    public function test_pemohon_dapat_membatalkan_permintaannya_sendiri(): void
    {
        $this->arriveAsGoogleUser('sub-batal', 'batal@example.test');

        $this->claimForm()
            ->set('type', AccountClaimType::Siswa->value)
            ->set('nis', '2026001')
            ->set('nisn', '0091234567')
            ->call('submit')
            ->assertSet('state', 'pending')
            ->call('cancel')
            ->assertSet('state', 'form');

        $this->assertSame(AccountClaimStatus::Cancelled, AccountClaim::query()->sole()->status);

        // Tempatnya pada indeks unik ikut dilepas, sehingga permintaan yang
        // benar dapat dikirim ulang.
        $this->claimForm()
            ->set('type', AccountClaimType::Siswa->value)
            ->set('nis', '2026001')
            ->set('nisn', '0091234567')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('state', 'pending');

        $this->assertSame(1, AccountClaim::query()->pending()->count());
    }

    /**
     * Menyelesaikan OAuth sebagai identitas tertentu, sampai identitasnya
     * benar-benar berada di sesi.
     */
    protected function arriveAsGoogleUser(string $subject, string $email): void
    {
        $this->fakeGoogleUser($subject, $email, 'Pengguna Uji');

        $this->get(route('oauth.google.callback'))->assertRedirect(route('oauth.google.claim'));
    }

    protected function claimForm(): Testable
    {
        return Livewire::test(GoogleClaim::class);
    }

    /**
     * Permintaan menunggu, dibuat lewat alur publiknya sendiri.
     */
    protected function pendingClaim(string $subject, string $email, AccountClaimType $type): AccountClaim
    {
        $this->arriveAsGoogleUser($subject, $email);

        $this->claimForm()
            ->set('type', $type->value)
            ->set('nis', '2026001')
            ->set('nisn', '0091234567')
            ->call('submit')
            ->assertHasNoErrors();

        return AccountClaim::query()
            ->where('provider_subject', $subject)
            ->pending()
            ->sole();
    }

    protected function schoolAdmin(): User
    {
        return User::factory()
            ->forSchool($this->school)
            ->withRole(RoleName::SchoolAdmin)
            ->create();
    }
}
