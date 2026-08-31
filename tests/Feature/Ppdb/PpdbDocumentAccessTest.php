<?php

namespace Tests\Feature\Ppdb;

use App\Enums\PpdbStatus;
use App\Enums\RoleName;
use App\Filament\Resources\PpdbRegistrationResource;
use App\Filament\Resources\PpdbRegistrationResource\Pages\ListPpdbRegistrations;
use App\Filament\Resources\PpdbRegistrationResource\Pages\ViewPpdbRegistration;
use App\Models\PpdbRegistration;
use App\Models\School;
use App\Models\User;
use App\Support\PpdbDocument;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * M0.1 — berkas pendukung PPDB adalah data aplikasi yang privat.
 *
 * Sebelum batch ini berkasnya disimpan di disk `public`: setelah
 * `storage:link`, siapa pun yang mengetahui jalurnya dapat mengunduhnya tanpa
 * login. Berkas itu memuat kartu keluarga, akta kelahiran, dan dokumen
 * identitas orang tua calon siswa (butir 411).
 *
 * Berkas uji di sini seluruhnya palsu.
 */
class PpdbDocumentAccessTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected School $otherSchool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        Storage::fake(PpdbDocument::DISK);
        Storage::fake(PpdbDocument::LEGACY_DISK);

        $this->school = School::factory()->create(['code' => 'MADANI', 'slug' => 'madani']);
        $this->otherSchool = School::factory()->create(['code' => 'CABANG2', 'slug' => 'cabang2']);
    }

    /**
     * Satu pendaftaran dengan satu berkas palsu di disk privat.
     */
    protected function registrationWithDocument(?School $school = null, string $disk = PpdbDocument::DISK): PpdbRegistration
    {
        $school ??= $this->school;
        $path = PpdbDocument::directoryFor($school).'/berkas-palsu.pdf';

        Storage::disk($disk)->put($path, 'isi-berkas-palsu');

        return PpdbRegistration::factory()->create([
            'school_id' => $school->id,
            'documents' => [$path],
        ]);
    }

    protected function urlFor(PpdbRegistration $registration, int $key = 0): string
    {
        return route('filament.admin.ppdb.document', [
            'registration' => $registration->getKey(),
            'documentKey' => $key,
        ]);
    }

    protected function userWith(RoleName $role, ?School $school = null): User
    {
        return User::factory()->forSchool($school ?? $this->school)->withRole($role)->create();
    }

    // ------------------------------------------------------------ tamu & peran

    public function test_a_guest_cannot_download_a_document(): void
    {
        $registration = $this->registrationWithDocument();

        $this->get($this->urlFor($registration))
            ->assertRedirect(route('filament.admin.auth.login'));
    }

    /**
     * Guru, Bendahara, Siswa, dan Orang Tua tidak punya modul PPDB sama sekali
     * pada matriks PRD 1.1.2.
     */
    public function test_a_role_without_ppdb_access_cannot_download_a_document(): void
    {
        $registration = $this->registrationWithDocument();

        foreach ([RoleName::Guru, RoleName::Bendahara] as $role) {
            $this->actingAs($this->userWith($role))
                ->get($this->urlFor($registration))
                ->assertForbidden();
        }
    }

    public function test_a_user_from_another_school_cannot_download_a_document(): void
    {
        $registration = $this->registrationWithDocument();

        // Global scope membuat pendaftaran cabang lain tidak ditemukan sama
        // sekali: 404, bukan 403 — keberadaannya pun tidak dibocorkan.
        $this->actingAs($this->userWith(RoleName::SchoolAdmin, $this->otherSchool))
            ->get($this->urlFor($registration))
            ->assertNotFound();
    }

    public function test_an_authorised_admin_receives_the_file(): void
    {
        $registration = $this->registrationWithDocument();

        $response = $this->actingAs($this->userWith(RoleName::SchoolAdmin))
            ->get($this->urlFor($registration));

        $response->assertOk();
        $this->assertSame('isi-berkas-palsu', $response->streamedContent());
    }

    /**
     * Kepala Sekolah hanya ⭕ (baca) pada matriks, dan membaca berkas adalah
     * bagian dari membaca pendaftarannya.
     */
    public function test_a_headmaster_may_download_a_document(): void
    {
        $registration = $this->registrationWithDocument();

        $this->actingAs($this->userWith(RoleName::KepalaSekolah))
            ->get($this->urlFor($registration))
            ->assertOk();
    }

    /**
     * Arsitektur 3.2.2 — Super Admin melewati seluruh pemeriksaan izin dan
     * global scope. Perilaku itu berasal dari `Gate::before` yang sudah ada,
     * bukan dari perlakuan khusus di controller ini.
     */
    public function test_a_super_admin_may_download_across_schools(): void
    {
        $registration = $this->registrationWithDocument();

        $superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create(['school_id' => null]);

        $this->actingAs($superAdmin)
            ->get($this->urlFor($registration))
            ->assertOk();
    }

    // ------------------------------------------------------------------ jalur

    public function test_an_unknown_document_key_is_not_found(): void
    {
        $registration = $this->registrationWithDocument();

        $this->actingAs($this->userWith(RoleName::SchoolAdmin))
            ->get($this->urlFor($registration, 7))
            ->assertNotFound();
    }

    public function test_a_registration_without_documents_is_not_found(): void
    {
        $registration = PpdbRegistration::factory()->create([
            'school_id' => $this->school->id,
            'documents' => null,
        ]);

        $this->actingAs($this->userWith(RoleName::SchoolAdmin))
            ->get($this->urlFor($registration))
            ->assertNotFound();
    }

    /**
     * Berkas yang tercatat tetapi hilang dari penyimpanan tidak boleh menjadi
     * galat 500 — dan tidak boleh menyebut letaknya.
     */
    public function test_a_recorded_but_missing_file_is_a_safe_404(): void
    {
        $registration = PpdbRegistration::factory()->create([
            'school_id' => $this->school->id,
            'documents' => [PpdbDocument::directoryFor($this->school).'/hilang.pdf'],
        ]);

        $response = $this->actingAs($this->userWith(RoleName::SchoolAdmin))
            ->get($this->urlFor($registration));

        $response->assertNotFound();
        $this->assertStringNotContainsString('storage', $response->getContent());
    }

    /**
     * Kunci berkas adalah indeks, dan rutenya membatasinya ke angka. Jalur
     * apa pun karena itu tidak pernah cocok dengan rutenya sejak awal.
     */
    public function test_a_path_shaped_document_key_never_reaches_the_controller(): void
    {
        $registration = $this->registrationWithDocument();
        $base = '/admin/ppdb/'.$registration->getKey().'/dokumen/';

        $this->actingAs($this->userWith(RoleName::SchoolAdmin));

        foreach (['..%2F..%2Fenv', 'berkas.pdf', '0.pdf', '..'] as $key) {
            $this->get($base.$key)->assertNotFound();
        }
    }

    /**
     * Jalur yang tersimpan di basis data pun tidak dipercaya buta: pagar yang
     * sama dengan App\Support\ProofPath.
     */
    public function test_a_stored_path_outside_the_ppdb_directory_is_refused(): void
    {
        Storage::disk(PpdbDocument::DISK)->put('payment-proofs/1/bukti.pdf', 'bukan-milik-ppdb');

        $registration = PpdbRegistration::factory()->create([
            'school_id' => $this->school->id,
            'documents' => ['payment-proofs/1/bukti.pdf'],
        ]);

        $this->actingAs($this->userWith(RoleName::SchoolAdmin))
            ->get($this->urlFor($registration))
            ->assertNotFound();
    }

    public function test_a_stored_path_containing_traversal_is_refused(): void
    {
        $registration = PpdbRegistration::factory()->create([
            'school_id' => $this->school->id,
            'documents' => ['ppdb/../../.env'],
        ]);

        $this->actingAs($this->userWith(RoleName::SchoolAdmin))
            ->get($this->urlFor($registration))
            ->assertNotFound();
    }

    // ------------------------------------------------------- nama & kebocoran

    /**
     * Nama unduhan dibentuk dari nomor pendaftaran; nama penyimpanannya tidak
     * ikut keluar, dan jalurnya tidak muncul di header mana pun.
     */
    public function test_the_download_reveals_no_storage_path(): void
    {
        $registration = $this->registrationWithDocument();

        $response = $this->actingAs($this->userWith(RoleName::SchoolAdmin))
            ->get($this->urlFor($registration));

        $disposition = (string) $response->headers->get('content-disposition');

        $this->assertStringContainsString(strtolower($registration->reg_number).'-berkas-1.pdf', strtolower($disposition));
        $this->assertStringNotContainsString('berkas-palsu', $disposition);
        $this->assertStringNotContainsString('ppdb/', $disposition);
        $this->assertStringNotContainsString(storage_path(), $disposition);
    }

    /**
     * Halaman detail tidak boleh lagi memuat URL penyimpanan apa pun — yang ada
     * hanya tautan ke rute berwenang.
     */
    public function test_the_detail_page_emits_no_public_storage_url(): void
    {
        $registration = $this->registrationWithDocument();

        $html = Livewire::actingAs($this->userWith(RoleName::SchoolAdmin))
            ->test(ViewPpdbRegistration::class, ['record' => $registration->getKey()])
            ->html();

        $this->assertStringNotContainsString('/storage/', $html);
        $this->assertStringNotContainsString('berkas-palsu.pdf', $html);
        $this->assertStringContainsString(
            '/admin/ppdb/'.$registration->getKey().'/dokumen/0',
            $html,
        );
    }

    // -------------------------------------------------------------- warisan

    /**
     * Baris lama masih menunjuk berkas di disk publik. Ia tetap dapat diunduh
     * lewat rute berwenang sampai `ppdb:privatize-documents` dijalankan —
     * pengerasan ini tidak mematahkan data yang sudah ada.
     */
    public function test_a_legacy_file_on_the_public_disk_is_still_served(): void
    {
        $registration = $this->registrationWithDocument(disk: PpdbDocument::LEGACY_DISK);

        $this->actingAs($this->userWith(RoleName::SchoolAdmin))
            ->get($this->urlFor($registration))
            ->assertOk();
    }

    // ------------------------------------------------- hapus / ganti / enroll

    /**
     * PPDB-05 tidak menyentuh `documents`. Diuji, bukan diasumsikan: enroll
     * adalah satu-satunya aksi yang mengubah pendaftaran setelah tersimpan,
     * dan kalau suatu saat ia mulai menghapus berkas, itu harus terlihat.
     */
    public function test_enrolling_leaves_the_documents_untouched(): void
    {
        $path = PpdbDocument::directoryFor($this->school).'/berkas-palsu.pdf';
        Storage::disk(PpdbDocument::DISK)->put($path, 'isi-berkas-palsu');

        $registration = PpdbRegistration::factory()->passed()->create([
            'school_id' => $this->school->id,
            'documents' => [$path],
        ]);

        $admin = $this->userWith(RoleName::SchoolAdmin);

        Livewire::actingAs($admin)
            ->test(ListPpdbRegistrations::class)
            ->callTableAction('enroll', $registration, [
                'nis' => '2026001',
                'full_name' => 'Calon Palsu',
                'gender' => 'L',
            ])
            ->assertHasNoTableActionErrors();

        $registration->refresh();

        $this->assertSame(PpdbStatus::Enrolled, $registration->status);
        $this->assertNotNull($registration->converted_student_id);
        $this->assertSame([$path], $registration->documents);
        Storage::disk(PpdbDocument::DISK)->assertExists($path);

        $this->actingAs($admin)->get($this->urlFor($registration))->assertOk();
    }

    /**
     * Pendaftaran tidak dapat dihapus maupun diubah dari panel — tidak ada
     * halaman ubah, dan policy menolak keduanya. Karena itu tidak ada jalur
     * yang dapat meninggalkan berkas yatim, dan tes ini menjaga ketiadaan itu.
     */
    public function test_there_is_no_edit_or_delete_path_that_could_orphan_a_file(): void
    {
        $registration = $this->registrationWithDocument();
        $admin = $this->userWith(RoleName::SchoolAdmin);

        $this->assertFalse($admin->can('update', $registration) && $admin->can('delete', $registration));
        $this->assertFalse($admin->can('delete', $registration));
        $this->assertFalse($admin->can('create', PpdbRegistration::class));
        $this->assertSame(
            ['index', 'view'],
            array_keys(PpdbRegistrationResource::getPages()),
        );
    }

    /**
     * Ketika keduanya ada, yang dilayani adalah salinan privatnya.
     */
    public function test_the_private_copy_wins_over_a_leftover_public_copy(): void
    {
        $path = PpdbDocument::directoryFor($this->school).'/berkas-palsu.pdf';

        Storage::disk(PpdbDocument::LEGACY_DISK)->put($path, 'salinan-publik-lama');
        Storage::disk(PpdbDocument::DISK)->put($path, 'salinan-privat-baru');

        $registration = PpdbRegistration::factory()->create([
            'school_id' => $this->school->id,
            'documents' => [$path],
        ]);

        $response = $this->actingAs($this->userWith(RoleName::SchoolAdmin))
            ->get($this->urlFor($registration));

        $this->assertSame('salinan-privat-baru', $response->streamedContent());
    }
}
