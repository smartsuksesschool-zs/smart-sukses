<?php

namespace Tests\Feature\Auth;

use App\Enums\AccountClaimStatus;
use App\Enums\AccountClaimType;
use App\Enums\AuthProvider;
use App\Enums\RoleName;
use App\Livewire\Auth\GoogleClaim;
use App\Models\AccountClaim;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\Auth\AccountClaimReviewer;
use App\Services\Portal\ParentPortalService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Klaim orang tua — tempat aturan keamanan batch ini paling penting.
 *
 * Mengetahui NIS dan NISN seorang anak membuktikan bahwa anak itu ada. Ia
 * tidak membuktikan bahwa yang mengetahuinya adalah orang tuanya: nomor itu
 * beredar di grup wali murid, tercetak di kartu, dan diketahui setiap teman
 * sekelas. Karena itu tidak ada satu pun jalan di sini yang berakhir pada akun
 * orang tua tanpa seorang admin menekan Setujui.
 */
class AccountClaimParentTest extends TestCase
{
    use FakesGoogleOAuth, RefreshDatabase;

    protected School $school;

    protected Student $anak;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->enableGoogleOAuth();

        $this->school = School::factory()->create(['code' => 'PUSAT', 'is_active' => true]);

        $this->anak = Student::factory()->create([
            'school_id' => $this->school->id,
            'nis' => '2026001',
            'nisn' => '0091234567',
            'full_name' => 'Anak Pertama',
            'parent_name' => 'Wali Sintetis',
            'parent_phone' => '08000000001',
        ]);
    }

    public function test_anak_yang_cocok_hanya_menghasilkan_permintaan_menunggu(): void
    {
        $this->arriveAsGoogleUser('sub-ortu', 'ortu@example.test');

        $this->claimForm()
            ->set('type', AccountClaimType::OrangTua->value)
            ->set('nis', '2026001')
            ->set('nisn', '0091234567')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('state', 'pending');

        $claim = AccountClaim::query()->sole();

        $this->assertSame(AccountClaimStatus::Pending, $claim->status);
        $this->assertSame(AccountClaimType::OrangTua, $claim->requested_type);

        // **Tidak** disetujui otomatis, walaupun NIS dan NISN keduanya benar.
        $this->assertSame(0, User::query()->where('auth_provider', AuthProvider::Google->value)->count());
        $this->assertNull($this->anak->fresh()->parent_user_id);
    }

    public function test_identitas_anak_yang_salah_gagal_dengan_kalimat_umum(): void
    {
        $this->arriveAsGoogleUser('sub-salah', 'salah@example.test');

        $component = $this->claimForm()
            ->set('type', AccountClaimType::OrangTua->value)
            ->set('nis', '2026001')
            ->set('nisn', '0000000000')
            ->call('submit')
            ->assertHasErrors('nis');

        $this->assertSame(GoogleClaim::REFUSED, $component->errors()->first('nis'));
        $this->assertSame(0, AccountClaim::query()->count());
    }

    public function test_persetujuan_menautkan_orang_tua_ke_anaknya(): void
    {
        $claim = $this->pendingParentClaim('sub-setuju', 'setuju@example.test', $this->anak);

        $parent = app(AccountClaimReviewer::class)->approve($claim, $this->schoolAdmin());

        $this->assertSame([RoleName::OrangTua->value], $parent->roles()->pluck('name')->all());
        $this->assertSame($this->school->id, $parent->school_id);
        $this->assertNull($parent->password);
        $this->assertSame($parent->id, $this->anak->fresh()->parent_user_id);

        // Nama akun diambil dari nama pada akun Google, bukan dari data siswa.
        $this->assertSame('Pengguna Uji', $parent->name);
    }

    public function test_orang_tua_tidak_pernah_mendapat_hak_staf(): void
    {
        $claim = $this->pendingParentClaim('sub-hak', 'hak@example.test', $this->anak);

        $parent = app(AccountClaimReviewer::class)->approve($claim, $this->schoolAdmin());

        $this->assertFalse($parent->canAccessPanel(Filament::getPanel('admin')));

        foreach (['user.manage', 'student.manage', 'fee.manage', 'tenant.view'] as $izin) {
            $this->assertFalse($parent->can($izin), "orang tua tidak boleh memiliki {$izin}");
        }
    }

    public function test_siswa_yang_tidak_terkait_tidak_terlihat_orang_tua(): void
    {
        $lain = Student::factory()->create([
            'school_id' => $this->school->id,
            'nis' => '2026099',
            'nisn' => '0099999999',
            'full_name' => 'Anak Orang Lain',
        ]);

        $claim = $this->pendingParentClaim('sub-lihat', 'lihat@example.test', $this->anak);
        $parent = app(AccountClaimReviewer::class)->approve($claim, $this->schoolAdmin());

        $children = app(ParentPortalService::class)->children($parent);

        $this->assertSame([$this->anak->id], $children->modelKeys());
        $this->assertNotContains($lain->id, $children->modelKeys());
    }

    public function test_masuk_google_berulang_tetap_berhasil_setelah_disetujui(): void
    {
        $claim = $this->pendingParentClaim('sub-berulang', 'berulang@example.test', $this->anak);
        $parent = app(AccountClaimReviewer::class)->approve($claim, $this->schoolAdmin());

        foreach (range(1, 3) as $_) {
            $this->fakeGoogleUser('sub-berulang', 'berulang@example.test', 'Pengguna Uji');

            $this->get(route('oauth.google.callback'))
                ->assertRedirect(route('portal.dashboard'));

            $this->assertAuthenticatedAs($parent);

            auth()->logout();
        }

        // Tidak ada akun kedua yang lahir dari masuk berulang.
        $this->assertSame(1, User::query()->where('auth_provider', AuthProvider::Google->value)->count());
    }

    public function test_anak_kedua_menjadi_permintaan_terpisah_pada_akun_yang_sama(): void
    {
        $kedua = Student::factory()->create([
            'school_id' => $this->school->id,
            'nis' => '2026002',
            'nisn' => '0091111111',
            'full_name' => 'Anak Kedua',
            // Nama dan nomor orang tua yang sama persis; tidak satu pun di
            // antaranya boleh menjadi dasar penautan.
            'parent_name' => 'Wali Sintetis',
            'parent_phone' => '08000000001',
        ]);

        $admin = $this->schoolAdmin();

        $pertama = $this->pendingParentClaim('sub-dua-anak', 'dua.anak@example.test', $this->anak);
        $parent = app(AccountClaimReviewer::class)->approve($pertama, $admin);

        // Anak kedua tidak ikut tertaut sendiri.
        $this->assertNull($kedua->fresh()->parent_user_id);
        $this->assertSame([$this->anak->id], app(ParentPortalService::class)->children($parent)->modelKeys());

        // Permintaan terpisah, dicocokkan dan disetujui terpisah.
        $klaimKedua = $this->pendingParentClaim('sub-dua-anak', 'dua.anak@example.test', $kedua);
        $lagi = app(AccountClaimReviewer::class)->approve($klaimKedua, $admin);

        // Satu pengguna dengan dua tautan, bukan dua pengguna bersurel sama.
        $this->assertSame($parent->id, $lagi->id);
        $this->assertSame(1, User::query()->where('auth_provider', AuthProvider::Google->value)->count());

        $children = app(ParentPortalService::class)->children($parent->fresh());

        $this->assertEqualsCanonicalizing(
            [$this->anak->id, $kedua->id],
            $children->modelKeys(),
        );
    }

    public function test_orang_tua_yang_sudah_masuk_hanya_dapat_meminta_peran_orang_tua(): void
    {
        // Halaman pencocokan tetap dapat dibuka orang tua yang sudah punya akun,
        // justru supaya anak kedua dapat diminta. Yang tidak boleh terjadi
        // adalah ia memakai halaman itu untuk meminta peran kedua.
        $claim = $this->pendingParentClaim('sub-peran', 'peran@example.test', $this->anak);
        app(AccountClaimReviewer::class)->approve($claim, $this->schoolAdmin());

        $this->signInWithGoogle('sub-peran', 'peran@example.test');

        $lain = Student::factory()->create([
            'school_id' => $this->school->id,
            'nis' => '2026077',
            'nisn' => '0097777777',
        ]);

        $this->claimForm()
            ->assertSet('type', AccountClaimType::OrangTua->value)
            ->set('type', AccountClaimType::Siswa->value)
            ->set('nis', (string) $lain->nis)
            ->set('nisn', (string) $lain->nisn)
            ->call('submit')
            ->assertHasErrors('type');

        $this->assertNull($lain->fresh()->user_id);
        $this->assertSame(0, AccountClaim::query()->pending()->count());
    }

    public function test_saudara_tidak_pernah_disimpulkan_dari_nama_nomor_atau_alamat(): void
    {
        $saudara = Student::factory()->create([
            'school_id' => $this->school->id,
            'nis' => '2026003',
            'nisn' => '0092222222',
            'full_name' => 'Anak Ketiga',
            'parent_name' => 'Wali Sintetis',
            'parent_phone' => '08000000001',
            'parent_email' => 'setuju@example.test',
            'address' => 'Alamat yang sama persis',
        ]);

        $this->anak->forceFill(['address' => 'Alamat yang sama persis', 'parent_email' => 'setuju@example.test'])->save();

        $claim = $this->pendingParentClaim('sub-saudara', 'setuju@example.test', $this->anak);

        app(AccountClaimReviewer::class)->approve($claim, $this->schoolAdmin());

        // Nama, nomor, surel, dan alamat orang tuanya identik — dan tetap tidak
        // menghasilkan satu pun tautan yang tidak diminta.
        $this->assertNull($saudara->fresh()->parent_user_id);
    }

    public function test_anak_yang_sudah_punya_akun_orang_tua_tidak_dapat_diklaim(): void
    {
        $pemilik = User::factory()->forSchool($this->school)->withRole(RoleName::OrangTua)->create();
        $this->anak->forceFill(['parent_user_id' => $pemilik->id])->save();

        $this->arriveAsGoogleUser('sub-rebut', 'rebut@example.test');

        $this->claimForm()
            ->set('type', AccountClaimType::OrangTua->value)
            ->set('nis', '2026001')
            ->set('nisn', '0091234567')
            ->call('submit')
            ->assertHasErrors('nis');

        $this->assertSame(0, AccountClaim::query()->count());
        $this->assertSame($pemilik->id, $this->anak->fresh()->parent_user_id);
    }

    public function test_klaim_orang_tua_tidak_menyentuh_tautan_akun_siswa(): void
    {
        $akunSiswa = User::factory()->forSchool($this->school)->withRole(RoleName::Siswa)->create();
        $this->anak->forceFill(['user_id' => $akunSiswa->id])->save();

        $claim = $this->pendingParentClaim('sub-terpisah', 'terpisah@example.test', $this->anak);
        $parent = app(AccountClaimReviewer::class)->approve($claim, $this->schoolAdmin());

        $anak = $this->anak->fresh();

        // Kedua kolom tautan berdiri sendiri: yang satu tidak menimpa yang lain.
        $this->assertSame($akunSiswa->id, $anak->user_id);
        $this->assertSame($parent->id, $anak->parent_user_id);
        $this->assertNotSame($akunSiswa->id, $parent->id);
    }

    protected function arriveAsGoogleUser(string $subject, string $email): void
    {
        $this->fakeGoogleUser($subject, $email, 'Pengguna Uji');

        $this->get(route('oauth.google.callback'))->assertRedirect(route('oauth.google.claim'));
    }

    /**
     * Menyelesaikan OAuth tanpa memastikan ke mana ia mendarat.
     *
     * Orang tua yang anak pertamanya sudah disetujui **sudah punya akun**,
     * sehingga callback yang sama membawanya langsung ke portal, bukan ke
     * halaman pencocokan. Keduanya sah; yang berikutnya dilakukan sama saja.
     */
    protected function signInWithGoogle(string $subject, string $email): void
    {
        $this->fakeGoogleUser($subject, $email, 'Pengguna Uji');

        $this->get(route('oauth.google.callback'));
    }

    protected function claimForm(): Testable
    {
        return Livewire::test(GoogleClaim::class);
    }

    protected function pendingParentClaim(string $subject, string $email, Student $student): AccountClaim
    {
        $this->signInWithGoogle($subject, $email);

        $this->claimForm()
            ->set('type', AccountClaimType::OrangTua->value)
            ->set('nis', (string) $student->nis)
            ->set('nisn', (string) $student->nisn)
            ->call('submit')
            ->assertHasNoErrors();

        return AccountClaim::query()
            ->where('provider_subject', $subject)
            ->where('student_id', $student->getKey())
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
