<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountClaimStatus;
use App\Enums\AccountClaimType;
use App\Enums\AuthProvider;
use App\Enums\RoleName;
use App\Filament\Resources\AccountClaimResource;
use App\Filament\Resources\AccountClaimResource\Pages\ListAccountClaims;
use App\Filament\Resources\AccountClaimResource\Pages\ViewAccountClaim;
use App\Models\AccountClaim;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Layar "Permintaan Akun" — tempat keputusannya benar-benar diambil.
 *
 * Yang diuji di sini bukan tampilannya melainkan dua hal yang menentukan apakah
 * keputusannya dapat diambil dengan benar: bahwa admin melihat cukup untuk
 * memutuskan, dan bahwa yang tidak perlu ia lihat tidak ikut terpampang di
 * daftar yang dibuka sepanjang hari (butir 536).
 */
class AccountClaimReviewUiTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected Student $student;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create(['code' => 'PUSAT', 'is_active' => true]);

        $this->student = Student::factory()->create([
            'school_id' => $this->school->id,
            'nis' => '2026001',
            'nisn' => '0091234567',
            'full_name' => 'Ananda Sintetis',
        ]);

        $this->admin = User::factory()
            ->forSchool($this->school)
            ->withRole(RoleName::SchoolAdmin)
            ->create();
    }

    public function test_daftar_menyamarkan_surel_dan_rincian_menampilkannya_utuh(): void
    {
        $claim = $this->claim('sub-samar', 'namapanjang@example.test');

        $this->assertSame('na•••••••••@example.test', $claim->maskedEmail());

        Livewire::actingAs($this->admin)
            ->test(ListAccountClaims::class)
            ->assertSee($claim->maskedEmail())
            ->assertDontSee('namapanjang@example.test');
    }

    public function test_rincian_menampilkan_bahan_keputusannya(): void
    {
        $claim = $this->claim('sub-rinci', 'rinci@example.test');

        Livewire::actingAs($this->admin)
            ->test(ViewAccountClaim::class, ['record' => $claim->getKey()])
            // Alamat utuh: di sinilah keputusannya diambil.
            ->assertSee('rinci@example.test')
            ->assertSee('Ananda Sintetis')
            // Keadaan tautan yang berlaku sekarang — supaya admin tahu sebelum
            // menekan tombol, bukan sesudahnya.
            ->assertSee('Akun Siswa Saat Ini');
    }

    public function test_admin_menyetujui_lewat_aksi_tabel(): void
    {
        $claim = $this->claim('sub-setuju-ui', 'setuju.ui@example.test');

        Livewire::actingAs($this->admin)
            ->test(ListAccountClaims::class)
            ->callTableAction('approve', $claim)
            ->assertHasNoTableActionErrors();

        $claim->refresh();

        $this->assertSame(AccountClaimStatus::Approved, $claim->status);
        $this->assertSame($this->admin->id, $claim->reviewed_by);
        $this->assertNotNull($claim->reviewed_at);

        $akun = User::query()->findOrFail($claim->approved_user_id);

        $this->assertSame([RoleName::Siswa->value], $akun->roles()->pluck('name')->all());
        $this->assertSame($akun->id, $this->student->fresh()->user_id);
    }

    public function test_admin_menolak_dengan_catatan_internal(): void
    {
        $claim = $this->claim('sub-tolak-ui', 'tolak.ui@example.test');

        Livewire::actingAs($this->admin)
            ->test(ListAccountClaims::class)
            ->callTableAction('reject', $claim, ['review_notes' => 'Sudah dikonfirmasi TU: bukan wali sah.'])
            ->assertHasNoTableActionErrors();

        $claim->refresh();

        $this->assertSame(AccountClaimStatus::Rejected, $claim->status);
        $this->assertSame('Sudah dikonfirmasi TU: bukan wali sah.', $claim->review_notes);
        $this->assertNull($claim->approved_user_id);
        $this->assertNull($this->student->fresh()->user_id);
    }

    public function test_tombol_keputusan_hilang_setelah_permintaan_ditinjau(): void
    {
        $claim = $this->claim('sub-sekali', 'sekali@example.test');

        $table = Livewire::actingAs($this->admin)->test(ListAccountClaims::class);

        $table->callTableAction('approve', $claim)->assertHasNoTableActionErrors();

        // Klik kedua pada baris yang sama tidak punya tombol untuk ditekan.
        Livewire::actingAs($this->admin)
            ->test(ListAccountClaims::class)
            ->assertTableActionHidden('approve', $claim->refresh())
            ->assertTableActionHidden('reject', $claim);
    }

    public function test_persetujuan_yang_tidak_dapat_dilanjutkan_menjadi_pemberitahuan_bukan_galat(): void
    {
        // Siswanya keburu ditautkan lewat jalur lain di antara dua klik.
        $claim = $this->claim('sub-keburu', 'keburu@example.test');

        $pemilik = User::factory()->forSchool($this->school)->withRole(RoleName::Siswa)->create();
        $this->student->forceFill(['user_id' => $pemilik->id])->save();

        Livewire::actingAs($this->admin)
            ->test(ListAccountClaims::class)
            ->callTableAction('approve', $claim)
            ->assertNotified();

        $this->assertSame(AccountClaimStatus::Pending, $claim->fresh()->status);
        $this->assertSame($pemilik->id, $this->student->fresh()->user_id);
    }

    public function test_tidak_ada_aksi_massal_dan_tidak_ada_pembuatan_manual(): void
    {
        // Menyetujui berarti menaut seseorang ke seorang anak; tombol "setujui
        // 40 yang tercentang" adalah cara termurah melewatkan seluruhnya.
        $this->assertFalse(AccountClaimResource::canCreate());

        $claim = $this->claim('sub-massal', 'massal@example.test');

        Livewire::actingAs($this->admin)
            ->test(ListAccountClaims::class)
            ->assertTableBulkActionDoesNotExist('delete');

        $this->assertTrue($claim->exists);
    }

    public function test_lencana_navigasi_menghitung_yang_menunggu(): void
    {
        $this->actingAs($this->admin);

        $this->assertNull(AccountClaimResource::getNavigationBadge());

        $this->claim('sub-lencana-1', 'l1@example.test');
        $this->claim('sub-lencana-2', 'l2@example.test', $this->otherStudent());

        $this->assertSame('2', AccountClaimResource::getNavigationBadge());
    }

    public function test_permintaan_staf_menuntut_admin_memilih_peran(): void
    {
        $claim = $this->staffClaim('sub-staf-ui', 'staf.ui@example.test');

        Livewire::actingAs($this->admin)
            ->test(ListAccountClaims::class)
            ->callTableAction('approve', $claim, ['approved_role' => RoleName::Guru->value])
            ->assertHasNoTableActionErrors();

        $claim->refresh();

        $this->assertSame(AccountClaimStatus::Approved, $claim->status);
        $this->assertSame(RoleName::Guru, $claim->approved_role);

        $akun = User::query()->findOrFail($claim->approved_user_id);

        $this->assertSame([RoleName::Guru->value], $akun->roles()->pluck('name')->all());
    }

    public function test_pemilih_peran_tidak_pernah_menawarkan_admin(): void
    {
        // Daftar yang muncul di layar adalah daftar putih yang sama yang
        // ditegakkan service — bukan salinannya (butir 547).
        $pilihan = AccountClaim::assignableRoleOptions();

        $this->assertArrayNotHasKey(RoleName::SchoolAdmin->value, $pilihan);
        $this->assertArrayNotHasKey(RoleName::SuperAdmin->value, $pilihan);
        $this->assertSame(
            [RoleName::Guru->value, RoleName::WaliKelas->value, RoleName::Bendahara->value, RoleName::KepalaSekolah->value],
            array_keys($pilihan),
        );
    }

    public function test_permintaan_staf_tanpa_peran_ditolak_formulir(): void
    {
        $claim = $this->staffClaim('sub-staf-kosong', 'staf.kosong@example.test');

        Livewire::actingAs($this->admin)
            ->test(ListAccountClaims::class)
            ->callTableAction('approve', $claim, ['approved_role' => null])
            ->assertHasTableActionErrors(['approved_role']);

        $this->assertSame(AccountClaimStatus::Pending, $claim->fresh()->status);
        $this->assertSame(0, User::query()->whereNotNull('auth_provider')->count());
    }

    public function test_rincian_staf_menyebut_jenisnya_dan_menyembunyikan_bagian_siswa(): void
    {
        $claim = $this->staffClaim('sub-staf-rinci', 'staf.rinci@example.test');

        Livewire::actingAs($this->admin)
            ->test(ViewAccountClaim::class, ['record' => $claim->getKey()])
            ->assertSee('Jenis Permintaan')
            ->assertSee('Staf Sekolah')
            ->assertSee('staf.rinci@example.test')
            ->assertSee('Cabang yang Dituju')
            // Tidak ada bagian siswa: permintaan ini tidak merujuk siapa pun.
            ->assertDontSee('Siswa yang Dirujuk');
    }

    protected function staffClaim(string $subject, string $email): AccountClaim
    {
        return AccountClaim::query()->create([
            'school_id' => $this->school->id,
            'provider' => AuthProvider::Google->value,
            'provider_subject' => $subject,
            'email' => $email,
            'name' => 'Pengguna Uji',
            'requested_type' => AccountClaimType::StafSekolah->value,
            'status' => AccountClaimStatus::Pending->value,
            'requested_at' => now(),
        ]);
    }

    protected function otherStudent(): Student
    {
        return Student::factory()->create([
            'school_id' => $this->school->id,
            'nis' => '2026002',
            'nisn' => '0091111111',
        ]);
    }

    protected function claim(string $subject, string $email, ?Student $student = null): AccountClaim
    {
        $student ??= $this->student;

        return AccountClaim::query()->create([
            'school_id' => $student->school_id,
            'provider' => AuthProvider::Google->value,
            'provider_subject' => $subject,
            'email' => $email,
            'name' => 'Pengguna Uji',
            'requested_type' => AccountClaimType::Siswa->value,
            'student_id' => $student->getKey(),
            'status' => AccountClaimStatus::Pending->value,
            'requested_at' => now(),
        ]);
    }
}
