<?php

namespace Tests\Feature\Auth;

use App\Enums\AccountClaimStatus;
use App\Enums\AccountClaimType;
use App\Enums\AuthProvider;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Exceptions\AccountClaimException;
use App\Livewire\Auth\GoogleClaim;
use App\Models\AccountClaim;
use App\Models\ClassSubject;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\Auth\AccountClaimReviewer;
use App\Support\GoogleIdentity;
use App\Support\PortalEligibility;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pendaftaran mandiri staf sekolah (M7.1).
 *
 * Alur ini memisahkan dua hal yang selama ini menyatu: **siapa** yang mendaftar,
 * dan **apa yang boleh ia lakukan**. Pemohon hanya menyatakan yang pertama —
 * "saya staf di cabang ini" — dan yang kedua sepenuhnya milik admin.
 *
 * Berbeda dari siswa dan orang tua, tidak ada satu pun yang dicocokkan: sekolah
 * ini tidak memegang pengenal induk pegawai, dan tidak ada yang dikarang sebagai
 * gantinya (butir 545). Karena itu persetujuan admin bukan lapisan tambahan di
 * sini — ia satu-satunya lapisan yang ada.
 *
 * Seluruh identitas pada berkas ini sintetis.
 */
class AccountClaimStaffTest extends TestCase
{
    use FakesGoogleOAuth, RefreshDatabase;

    protected School $school;

    protected School $cabangLain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->enableGoogleOAuth();

        $this->school = School::factory()->create(['code' => 'PUSAT', 'is_active' => true]);
        $this->cabangLain = School::factory()->create(['code' => 'CABANG2', 'is_active' => true]);
    }

    // ---------------------------------------------------------------- publik

    public function test_staf_dapat_mengirim_permintaan_menunggu(): void
    {
        $this->arriveAsGoogleUser('sub-staf', 'staf.baru@example.test');

        $this->claimForm()
            ->set('type', AccountClaimType::StafSekolah->value)
            ->set('schoolId', (string) $this->school->id)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('state', 'pending');

        $claim = AccountClaim::query()->sole();

        $this->assertSame(AccountClaimType::StafSekolah, $claim->requested_type);
        $this->assertSame(AccountClaimStatus::Pending, $claim->status);
        $this->assertSame($this->school->id, $claim->school_id);

        // Tidak menunjuk siswa mana pun, dan tidak mengarang satu pun.
        $this->assertNull($claim->student_id);

        // **Belum ada peran sama sekali.** Itulah seluruh maksudnya: pemohon
        // menyatakan identitas, admin menentukan kewenangan (butir 544).
        $this->assertNull($claim->approved_role);
        $this->assertNull($claim->approved_user_id);
        $this->assertSame(0, User::query()->where('auth_provider', AuthProvider::Google->value)->count());
    }

    public function test_formulir_staf_tidak_menanyakan_nis_maupun_nisn(): void
    {
        $this->arriveAsGoogleUser('sub-tanya', 'tanya@example.test');

        $this->claimForm()
            ->set('type', AccountClaimType::StafSekolah->value)
            ->assertSee('Cabang Tempat Bertugas')
            ->assertDontSee('NIS Siswa')
            ->assertDontSee('NISN Siswa');
    }

    public function test_pilihan_publik_memuat_tepat_tiga_jenis(): void
    {
        $this->arriveAsGoogleUser('sub-pilihan', 'pilihan@example.test');

        $this->claimForm()
            ->assertSee('Saya Siswa')
            ->assertSee('Saya Orang Tua/Wali')
            ->assertSee('Saya Staf Sekolah')
            // Tidak satu pun nama peran muncul sebagai pilihan publik.
            ->assertDontSee('Saya Guru')
            ->assertDontSee('Saya Bendahara')
            ->assertDontSee('Saya Kepala Sekolah');
    }

    public function test_cabang_wajib_dipilih(): void
    {
        $this->arriveAsGoogleUser('sub-tanpa-cabang', 'tanpa.cabang@example.test');

        $this->claimForm()
            ->set('type', AccountClaimType::StafSekolah->value)
            ->call('submit')
            ->assertHasErrors('schoolId');

        $this->assertSame(0, AccountClaim::query()->count());
    }

    public function test_cabang_nonaktif_tidak_dapat_dipilih(): void
    {
        $mati = School::factory()->create(['code' => 'TUTUP', 'is_active' => false]);

        $this->arriveAsGoogleUser('sub-mati', 'mati@example.test');

        $this->claimForm()
            ->set('type', AccountClaimType::StafSekolah->value)
            ->set('schoolId', (string) $mati->id)
            ->call('submit')
            ->assertHasErrors('schoolId');

        $this->assertSame(0, AccountClaim::query()->count());
    }

    public function test_masuk_berulang_selama_menunggu_menampilkan_keadaan_yang_sama(): void
    {
        $this->stafClaim('sub-ulang', 'ulang@example.test');

        // Kembali lewat Google sebelum disetujui: yang muncul keadaannya, bukan
        // formulir kosong yang mengundang permintaan kedua (§13).
        $this->arriveAsGoogleUser('sub-ulang', 'ulang@example.test');

        $this->claimForm()
            ->assertSet('state', 'pending')
            ->assertSee(GoogleClaim::PENDING_MESSAGE);

        $this->assertSame(1, AccountClaim::query()->count());
    }

    public function test_permintaan_staf_yang_diulang_tidak_menjadi_dua_baris(): void
    {
        // Kunci `pending_key` menyusun ketiadaan siswa menjadi nilai yang tetap
        // dibandingkan; tanpa itu pemohon staf dapat menumpuk antrean sebanyak
        // yang ia mau (butir 548).
        $this->stafClaim('sub-tumpuk', 'tumpuk@example.test');
        $this->stafClaim('sub-tumpuk', 'tumpuk@example.test');
        $this->stafClaim('sub-tumpuk', 'tumpuk@example.test');

        $this->assertSame(1, AccountClaim::query()->count());
    }

    // ------------------------------------------------------------ persetujuan

    #[DataProvider('assignableRoles')]
    public function test_admin_sekolah_menyetujui_dengan_peran_pilihannya(string $role): void
    {
        $claim = $this->stafClaim('sub-setuju-'.$role, 'setuju@example.test');

        $user = app(AccountClaimReviewer::class)
            ->approve($claim, $this->adminOf($this->school), RoleName::from($role));

        // Tepat satu peran (PRD 1.1.1) — bukan "peran ini ditambahkan".
        $this->assertSame([$role], $user->roles()->pluck('name')->all());

        $this->assertSame($this->school->id, $user->school_id);
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->must_change_password);
        $this->assertNull($user->password);
        $this->assertSame(AuthProvider::Google, $user->auth_provider);
        $this->assertSame('sub-setuju-'.$role, $user->provider_subject);

        $claim->refresh();
        $this->assertSame(AccountClaimStatus::Approved, $claim->status);
        $this->assertSame(RoleName::from($role), $claim->approved_role);
        $this->assertSame($user->id, $claim->approved_user_id);

        // Tidak ada baris siswa yang tersentuh oleh persetujuan staf.
        $this->assertSame(0, Student::query()->whereNotNull('user_id')->count());
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function assignableRoles(): array
    {
        return [
            [RoleName::Guru->value],
            [RoleName::WaliKelas->value],
            [RoleName::Bendahara->value],
            [RoleName::KepalaSekolah->value],
        ];
    }

    #[DataProvider('forbiddenOutcomeRoles')]
    public function test_peran_admin_tidak_dapat_diberikan_lewat_permintaan(string $role): void
    {
        $claim = $this->stafClaim('sub-naik-'.$role, 'naik@example.test');

        try {
            app(AccountClaimReviewer::class)
                ->approve($claim, $this->adminOf($this->school), RoleName::from($role));
            $this->fail("{$role} seharusnya tidak dapat diberikan lewat permintaan akun");
        } catch (AccountClaimException) {
            // yang diperiksa akibatnya, bukan pesannya
        }

        $this->assertSame(AccountClaimStatus::Pending, $claim->fresh()->status);
        $this->assertSame(0, User::query()->where('auth_provider', AuthProvider::Google->value)->count());
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function forbiddenOutcomeRoles(): array
    {
        return [
            [RoleName::SchoolAdmin->value],
            [RoleName::SuperAdmin->value],
            // Kedua peran portal pun bukan hasil yang sah bagi permintaan staf:
            // keduanya menuntut tautan ke baris siswa yang tidak ada di sini.
            [RoleName::Siswa->value],
            [RoleName::OrangTua->value],
        ];
    }

    public function test_persetujuan_staf_tanpa_peran_ditolak(): void
    {
        $claim = $this->stafClaim('sub-tanpa-peran', 'tanpa.peran@example.test');

        $this->expectException(AccountClaimException::class);

        app(AccountClaimReviewer::class)->approve($claim, $this->adminOf($this->school));
    }

    public function test_super_admin_dapat_meninjau_sebagai_pengawasan_platform(): void
    {
        $claim = $this->stafClaim('sub-super', 'super@example.test');

        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super);
        $this->assertTrue($super->can('approve', $claim));

        $user = app(AccountClaimReviewer::class)->approve($claim, $super, RoleName::Guru);

        // Cabangnya tetap cabang pada permintaan, bukan cabang peninjau — Super
        // Admin sendiri tidak punya cabang.
        $this->assertSame($this->school->id, $user->school_id);
        $this->assertSame($claim->fresh()->reviewed_by, $super->id);
    }

    public function test_admin_sekolah_tidak_dapat_menyetujui_permintaan_cabang_lain(): void
    {
        $claim = $this->stafClaim('sub-seberang', 'seberang@example.test', $this->cabangLain);

        $asing = $this->adminOf($this->school);

        $this->actingAs($asing);

        // Global scope: barisnya tidak ada sama sekali baginya.
        $this->assertSame(0, AccountClaim::query()->count());

        // Policy: pagar kedua.
        $this->assertFalse($asing->can('approve', $claim));
        $this->assertFalse($asing->can('view', $claim));
    }

    #[DataProvider('rolesThatCannotApprove')]
    public function test_peran_lain_tidak_dapat_menyetujui(string $role): void
    {
        $claim = $this->stafClaim('sub-tolak-'.$role, 'tolak@example.test');

        $user = User::factory()->forSchool($this->school)->withRole(RoleName::from($role))->create();

        $this->actingAs($user);

        $this->assertFalse($user->can('approve', $claim), "{$role} tidak boleh menyetujui");
        $this->assertFalse($user->can('reject', $claim), "{$role} tidak boleh menolak");
        $this->assertFalse($user->can(PermissionName::UserManage->value));
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function rolesThatCannotApprove(): array
    {
        return [
            [RoleName::Guru->value],
            [RoleName::WaliKelas->value],
            [RoleName::Bendahara->value],
            [RoleName::KepalaSekolah->value],
        ];
    }

    public function test_penolakan_tidak_memberi_akses_apa_pun(): void
    {
        $claim = $this->stafClaim('sub-ditolak', 'ditolak@example.test');

        app(AccountClaimReviewer::class)
            ->reject($claim, $this->adminOf($this->school), 'Tidak dikenal bagian TU.');

        $claim->refresh();

        $this->assertSame(AccountClaimStatus::Rejected, $claim->status);
        $this->assertNull($claim->approved_role);
        $this->assertNull($claim->approved_user_id);
        $this->assertSame(0, User::query()->where('auth_provider', AuthProvider::Google->value)->count());

        // Masuk lagi lewat Google tidak menghasilkan sesi apa pun.
        $this->fakeGoogleUser('sub-ditolak', 'ditolak@example.test', 'Pengguna Uji');
        $this->get(route('oauth.google.callback'))->assertRedirect(route('oauth.google.claim'));
        $this->assertGuest();
    }

    public function test_persetujuan_kedua_atas_permintaan_yang_sama_ditolak(): void
    {
        $claim = $this->stafClaim('sub-dobel', 'dobel@example.test');
        $admin = $this->adminOf($this->school);

        app(AccountClaimReviewer::class)->approve($claim, $admin, RoleName::Guru);

        $this->expectException(AccountClaimException::class);

        // Klik kedua pada tombol yang sama.
        app(AccountClaimReviewer::class)->approve($claim->fresh(), $admin, RoleName::Bendahara);
    }

    public function test_satu_identitas_google_tidak_dapat_memperoleh_dua_akun_staf(): void
    {
        $admin = $this->adminOf($this->school);

        $pertama = $this->stafClaim('sub-serakah', 'serakah@example.test');
        app(AccountClaimReviewer::class)->approve($pertama, $admin, RoleName::Guru);

        // Permintaan kedua dari identitas yang sama, setelah akunnya ada.
        $kedua = AccountClaim::query()->create([
            'school_id' => $this->school->id,
            'provider' => AuthProvider::Google->value,
            'provider_subject' => 'sub-serakah',
            'email' => 'serakah@example.test',
            'name' => 'Pengguna Uji',
            'requested_type' => AccountClaimType::StafSekolah->value,
            'status' => AccountClaimStatus::Pending->value,
            'requested_at' => now(),
        ]);

        try {
            app(AccountClaimReviewer::class)->approve($kedua, $admin, RoleName::Bendahara);
            $this->fail('identitas yang sama seharusnya tidak memperoleh akun kedua');
        } catch (AccountClaimException) {
            // yang diperiksa akibatnya, bukan pesannya
        }

        $this->assertSame(1, User::query()->where('auth_provider', AuthProvider::Google->value)->count());

        // Peran akun yang pertama tidak ikut berubah.
        $this->assertSame(
            [RoleName::Guru->value],
            User::query()->where('provider_subject', 'sub-serakah')->sole()->roles()->pluck('name')->all(),
        );
    }

    public function test_surel_milik_akun_lain_tidak_digabungkan_diam_diam(): void
    {
        $staf = User::factory()
            ->forSchool($this->school)
            ->withRole(RoleName::Bendahara)
            ->create(['email' => 'bendahara@example.test']);

        $claim = $this->stafClaim('sub-tabrakan', 'bendahara@example.test');

        try {
            app(AccountClaimReviewer::class)->approve($claim, $this->adminOf($this->school), RoleName::Guru);
            $this->fail('persetujuan seharusnya ditolak');
        } catch (AccountClaimException) {
            // yang diperiksa akibatnya, bukan pesannya
        }

        $staf->refresh();
        $this->assertNull($staf->auth_provider);
        $this->assertNull($staf->provider_subject);
        $this->assertSame([RoleName::Bendahara->value], $staf->roles()->pluck('name')->all());
    }

    public function test_staf_yang_disetujui_masuk_langsung_ke_panel(): void
    {
        $claim = $this->stafClaim('sub-panel', 'panel@example.test');

        $user = app(AccountClaimReviewer::class)
            ->approve($claim, $this->adminOf($this->school), RoleName::Guru);

        $this->fakeGoogleUser('sub-panel', 'panel@example.test', 'Pengguna Uji');

        $this->get(route('oauth.google.callback'))
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        $this->assertAuthenticatedAs($user);

        // Dan tidak pernah ditanyai apa pun lagi (§14).
        $this->assertNull(GoogleIdentity::fromSession());
    }

    public function test_staf_yang_sudah_disetujui_tidak_melihat_formulir_lagi(): void
    {
        $claim = $this->stafClaim('sub-selesai', 'selesai@example.test');

        app(AccountClaimReviewer::class)
            ->approve($claim, $this->adminOf($this->school), RoleName::Bendahara);

        $this->fakeGoogleUser('sub-selesai', 'selesai@example.test', 'Pengguna Uji');
        $this->get(route('oauth.google.callback'));

        // Halaman pencocokan mengembalikannya ke tujuannya; pengecualian "orang
        // tua menambah anak" tidak berlaku bagi staf.
        Livewire::test(GoogleClaim::class)
            ->assertRedirect(route('filament.admin.pages.dashboard'));
    }

    // ------------------------------------------------- guru & wali kelas (§17)

    public function test_tidak_ada_tabel_guru_terpisah_yang_dapat_terduplikasi(): void
    {
        /*
         * Temuan arsitektur M7.1: project ini tidak punya model maupun tabel
         * `teachers`. Guru **adalah** `users` berperan GURU/WALI_KELAS, dan
         * kedua kolom yang menyebut guru menunjuk `users` secara langsung:
         * `classes.homeroom_teacher_id` dan `class_subjects.teacher_id`.
         *
         * Akibatnya seluruh bahaya yang biasanya menyertai "penautan ke record
         * guru" tidak dapat terjadi di sini: tidak ada yang dapat dicocokkan
         * dari nama, tidak ada record yang dapat dikarang diam-diam, dan tidak
         * ada duplikat yang dapat lahir — karena tidak ada tabel keduanya
         * (butir 550).
         */
        $this->assertFalse(Schema::hasTable('teachers'));
        // Namanya sengaja string: mengimpornya akan membuat berkas ini
        // seolah bergantung pada kelas yang justru dibuktikan tidak ada.
        $this->assertFalse(class_exists('App\Models\Teacher'));

        $this->assertSame('users', (new SchoolClass)->homeroomTeacher()->getRelated()->getTable());
        $this->assertSame('users', (new ClassSubject)->teacher()->getRelated()->getTable());
    }

    public function test_guru_yang_disetujui_langsung_dapat_dipilih_sebagai_pengajar(): void
    {
        $claim = $this->stafClaim('sub-pengajar', 'pengajar@example.test');

        $guru = app(AccountClaimReviewer::class)
            ->approve($claim, $this->adminOf($this->school), RoleName::Guru);

        // Tidak ada langkah penautan tambahan: pemilih guru pada layar admin
        // menyaring berdasarkan **peran**, jadi akun ini sudah ada di dalamnya.
        $this->actingAs($this->adminOf($this->school));

        $this->assertArrayHasKey($guru->id, $this->teacherPickerOptions());
    }

    public function test_wali_kelas_yang_disetujui_dapat_dipilih_sebagai_pengajar_dan_wali(): void
    {
        $claim = $this->stafClaim('sub-wali', 'wali@example.test');

        $wali = app(AccountClaimReviewer::class)
            ->approve($claim, $this->adminOf($this->school), RoleName::WaliKelas);

        $this->actingAs($this->adminOf($this->school));

        // Satu orang, satu akun, dua daftar: pemilih guru pengajar memuat
        // GURU **dan** WALI_KELAS, dan pemilih wali kelas memuat WALI_KELAS
        // (butir 551).
        $this->assertArrayHasKey($wali->id, $this->teacherPickerOptions());
        $this->assertArrayHasKey($wali->id, $this->homeroomPickerOptions());

        // Dan tetap satu peran saja.
        $this->assertSame([RoleName::WaliKelas->value], $wali->roles()->pluck('name')->all());
    }

    public function test_wali_kelas_satu_akun_dapat_mengajar_dan_menerbitkan_rapor(): void
    {
        $claim = $this->stafClaim('sub-dua-tugas', 'dua.tugas@example.test');

        $wali = app(AccountClaimReviewer::class)
            ->approve($claim, $this->adminOf($this->school), RoleName::WaliKelas);

        // Izin mengajar — sama persis dengan yang dimiliki GURU.
        $this->assertTrue($wali->can(PermissionName::GradeManage->value));
        $this->assertTrue($wali->can(PermissionName::StudentView->value));
        $this->assertTrue($wali->can(PermissionName::ClassScheduleView->value));

        // Ditambah yang khas wali kelas.
        $this->assertTrue($wali->can(PermissionName::ReportCardManage->value));

        // Tanpa satu pun kewenangan yang bukan haknya.
        $this->assertFalse($wali->can(PermissionName::UserManage->value));
        $this->assertFalse($wali->can(PermissionName::FeeManage->value));

        // Portal guru menerima keduanya, jadi tidak perlu akun GURU kedua.
        $this->assertTrue(PortalEligibility::allows($wali, [RoleName::Guru, RoleName::WaliKelas]));
    }

    public function test_guru_cabang_lain_tidak_muncul_pada_pemilih(): void
    {
        $claim = $this->stafClaim('sub-luar', 'luar@example.test', $this->cabangLain);

        $guruLuar = app(AccountClaimReviewer::class)
            ->approve($claim, $this->adminOf($this->cabangLain), RoleName::Guru);

        // Pemilihnya memakai `User::query()`, yang membawa global scope tenant:
        // guru cabang lain tidak pernah ikut ditawarkan.
        $this->actingAs($this->adminOf($this->school));

        $this->assertArrayNotHasKey($guruLuar->id, $this->teacherPickerOptions());
        $this->assertArrayNotHasKey($guruLuar->id, $this->homeroomPickerOptions());
    }

    // ------------------------------------------------------------------ bantu

    /**
     * Pilihan "Guru Pengajar" pada layar Kelas → Mata Pelajaran.
     *
     * @return array<int, string>
     */
    protected function teacherPickerOptions(): array
    {
        return User::query()
            ->active()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', [
                RoleName::Guru->value,
                RoleName::WaliKelas->value,
            ]))
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Pilihan "Wali Kelas" pada layar Kelas.
     *
     * @return array<int, string>
     */
    protected function homeroomPickerOptions(): array
    {
        return User::query()
            ->active()
            ->whereHas('roles', fn ($query) => $query->where('name', RoleName::WaliKelas->value))
            ->pluck('name', 'id')
            ->all();
    }

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
     * Permintaan staf yang menunggu, dibuat lewat alur publiknya sendiri.
     */
    protected function stafClaim(string $subject, string $email, ?School $school = null): AccountClaim
    {
        $school ??= $this->school;

        $this->arriveAsGoogleUser($subject, $email);

        $this->claimForm()
            ->set('type', AccountClaimType::StafSekolah->value)
            ->set('schoolId', (string) $school->id)
            ->call('submit')
            ->assertHasNoErrors();

        return AccountClaim::query()
            ->withoutGlobalScopes()
            ->where('provider_subject', $subject)
            ->pending()
            ->sole();
    }

    protected function adminOf(School $school): User
    {
        return User::factory()
            ->forSchool($school)
            ->withRole(RoleName::SchoolAdmin)
            ->create();
    }
}
