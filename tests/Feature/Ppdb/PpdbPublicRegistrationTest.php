<?php

namespace Tests\Feature\Ppdb;

use App\Enums\PpdbStatus;
use App\Livewire\Ppdb\RegistrationForm;
use App\Livewire\Ppdb\SchoolList;
use App\Models\AcademicYear;
use App\Models\PpdbRegistration;
use App\Models\School;
use App\Support\PpdbDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PPDB-01 — formulir pendaftaran PPDB online tanpa login.
 * API 4.7 — GET /ppdb/schools, GET /ppdb/{schoolCode}/info, POST /ppdb/{schoolCode}/register.
 */
class PpdbPublicRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create(['code' => 'MADANI', 'slug' => 'madani']);
    }

    /**
     * @return array<string, string>
     */
    protected function validForm(): array
    {
        return [
            'full_name' => 'Ahmad Fauzi',
            'gender' => 'L',
            'birth_date' => '2010-05-17',
            'origin_school' => 'SMP Negeri 1 Serang',
            'parent_name' => 'Bapak Fauzi',
            'parent_phone' => '081234567890',
            'parent_email' => 'ortu@example.test',
        ];
    }

    public function test_registration_page_is_reachable_without_login(): void
    {
        // PPDB-01 poin 1 — halaman PPDB dapat diakses publik via /ppdb/[kode_sekolah].
        $this->get(route('ppdb.register', ['schoolCode' => 'madani']))->assertSuccessful();
        $this->get(route('ppdb.register', ['schoolCode' => 'MADANI']))->assertSuccessful();
    }

    public function test_public_landing_page_lists_only_active_schools(): void
    {
        // API 4.7 — GET /ppdb/schools: daftar cabang yang membuka PPDB.
        $inactive = School::factory()->inactive()->create();

        Livewire::test(SchoolList::class)
            ->assertSee($this->school->name)
            ->assertDontSee($inactive->name);
    }

    public function test_unknown_school_code_returns_not_found(): void
    {
        $this->get(route('ppdb.register', ['schoolCode' => 'TIDAKADA']))->assertNotFound();
    }

    public function test_inactive_school_does_not_accept_registrations(): void
    {
        $inactive = School::factory()->inactive()->create(['code' => 'TUTUP']);

        $this->get(route('ppdb.register', ['schoolCode' => $inactive->code]))->assertNotFound();
    }

    public function test_submitting_the_form_creates_a_registration_and_shows_the_reg_number(): void
    {
        $year = AcademicYear::factory()->create(['school_id' => $this->school->id, 'is_active' => true]);

        Livewire::test(RegistrationForm::class, ['schoolCode' => 'madani'])
            ->set($this->validForm())
            ->call('submit')
            ->assertHasNoErrors()
            // PPDB-01 poin 3 — setelah submit, tampil nomor pendaftaran unik.
            ->assertSet('regNumber', 'MADANI-'.now()->format('Y').'-0001')
            ->assertSee('MADANI-'.now()->format('Y').'-0001');

        $registration = PpdbRegistration::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame($this->school->id, $registration->school_id);
        $this->assertSame($year->id, $registration->academic_year_id);
        $this->assertSame(PpdbStatus::Registered, $registration->status);
        $this->assertSame('Ahmad Fauzi', $registration->full_name);
        $this->assertNotNull($registration->registered_at);
    }

    public function test_reg_number_follows_the_erd_format_and_increments_per_school(): void
    {
        // ERD 2.2 — "Nomor pendaftaran unik: [KODE_CABANG]-[TAHUN]-[SEQ]".
        $other = School::factory()->create(['code' => 'PUSAT']);
        $year = now()->format('Y');

        for ($i = 0; $i < 3; $i++) {
            Livewire::test(RegistrationForm::class, ['schoolCode' => 'MADANI'])
                ->set($this->validForm())
                ->call('submit')
                ->assertHasNoErrors();
        }

        Livewire::test(RegistrationForm::class, ['schoolCode' => 'PUSAT'])
            ->set($this->validForm())
            ->call('submit')
            ->assertSet('regNumber', "PUSAT-{$year}-0001");

        $this->assertSame(
            ["MADANI-{$year}-0001", "MADANI-{$year}-0002", "MADANI-{$year}-0003"],
            PpdbRegistration::query()->withoutGlobalScopes()
                ->where('school_id', $this->school->id)
                ->orderBy('reg_number')
                ->pluck('reg_number')
                ->all(),
        );

        $this->assertSame(1, PpdbRegistration::query()->withoutGlobalScopes()
            ->where('school_id', $other->id)->count());
    }

    public function test_reg_number_never_exceeds_the_erd_column_length(): void
    {
        // ERD memberi reg_number VARCHAR(20) sekaligus schools.code VARCHAR(20),
        // jadi kode terpanjang yang sah pun harus tetap muat setelah digabung.
        $long = School::factory()->create(['code' => str_repeat('K', 20)]);

        $regNumber = PpdbRegistration::generateRegNumber($long);

        $this->assertSame(str_repeat('K', 10).'-'.now()->format('Y').'-0001', $regNumber);
        $this->assertLessThanOrEqual(20, strlen($regNumber));

        // Nomor sepanjang itu benar-benar tersimpan (MySQL menolak yang melebihi).
        $registration = PpdbRegistration::registerPublicly($long, [
            'full_name' => 'Siswa Uji',
            'gender' => 'L',
            'birth_date' => '2010-01-01',
        ]);

        $this->assertSame($regNumber, $registration->reg_number);
    }

    public function test_required_fields_are_validated(): void
    {
        Livewire::test(RegistrationForm::class, ['schoolCode' => 'MADANI'])
            ->call('submit')
            ->assertHasErrors(['full_name', 'gender', 'birth_date', 'parent_name', 'parent_phone']);

        $this->assertSame(0, PpdbRegistration::query()->withoutGlobalScopes()->count());
    }

    public function test_parent_email_must_be_a_valid_address(): void
    {
        Livewire::test(RegistrationForm::class, ['schoolCode' => 'MADANI'])
            ->set([...$this->validForm(), 'parent_email' => 'bukan-email'])
            ->call('submit')
            ->assertHasErrors(['parent_email']);
    }

    /**
     * Berkas pendaftaran memuat dokumen keluarga, jadi ia **tidak** boleh
     * mendarat di disk publik yang dilayani web server tanpa autentikasi
     * (M-1, butir 411).
     */
    public function test_supporting_documents_are_stored_privately(): void
    {
        // ERD 2.2 — documents: path file dokumen yang diupload (array URL).
        Storage::fake(PpdbDocument::DISK);
        Storage::fake(PpdbDocument::LEGACY_DISK);

        Livewire::test(RegistrationForm::class, ['schoolCode' => 'MADANI'])
            ->set($this->validForm())
            ->set('documents', [UploadedFile::fake()->create('ijazah.pdf', 100, 'application/pdf')])
            ->call('submit')
            ->assertHasNoErrors();

        $documents = PpdbRegistration::query()->withoutGlobalScopes()->value('documents');

        $this->assertIsArray($documents);
        $this->assertCount(1, $documents);

        Storage::disk(PpdbDocument::DISK)->assertExists($documents[0]);
        Storage::disk(PpdbDocument::LEGACY_DISK)->assertMissing($documents[0]);
    }

    public function test_only_jpg_png_and_pdf_documents_are_accepted(): void
    {
        // Arsitektur 3.4 — File Upload: hanya JPG/PNG/PDF diperbolehkan.
        Storage::fake(PpdbDocument::DISK);

        Livewire::test(RegistrationForm::class, ['schoolCode' => 'MADANI'])
            ->set($this->validForm())
            ->set('documents', [UploadedFile::fake()->create('virus.exe', 10)])
            ->call('submit')
            ->assertHasErrors(['documents.0']);
    }

    public function test_registration_without_an_active_academic_year_is_still_accepted(): void
    {
        // ERD 2.2 — academic_year_id nullable.
        Livewire::test(RegistrationForm::class, ['schoolCode' => 'MADANI'])
            ->set($this->validForm())
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertNull(PpdbRegistration::query()->withoutGlobalScopes()->value('academic_year_id'));
    }
}
