<?php

namespace Tests\Feature\Ppdb;

use App\Models\PpdbRegistration;
use App\Models\School;
use App\Support\PpdbDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * M0.1 — `ppdb:privatize-documents`, pemindahan satu kali berkas PPDB yang
 * sudah terlanjur berada di disk publik.
 *
 * Yang diuji di sini bukan hanya "berkasnya pindah", melainkan pagarnya:
 * simulasi tidak menulis apa pun, berkas yang tidak dirujuk tidak disentuh,
 * berkas privat yang berbeda tidak ditimpa, dan yang hilang dilaporkan hilang
 * alih-alih dianggap selesai (butir 414).
 *
 * Seluruh berkas uji palsu.
 */
class PpdbDocumentPrivatizeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(PpdbDocument::DISK);
        Storage::fake(PpdbDocument::LEGACY_DISK);

        $this->school = School::factory()->create(['code' => 'MADANI', 'slug' => 'madani']);
    }

    /**
     * Satu pendaftaran dengan berkasnya masih di disk publik.
     *
     * @return array{0: PpdbRegistration, 1: string}
     */
    protected function legacyRegistration(string $name = 'berkas-palsu.pdf', string $contents = 'isi-berkas-palsu'): array
    {
        $path = PpdbDocument::directoryFor($this->school).'/'.$name;

        Storage::disk(PpdbDocument::LEGACY_DISK)->put($path, $contents);

        $registration = PpdbRegistration::factory()->create([
            'school_id' => $this->school->id,
            'documents' => [$path],
        ]);

        return [$registration, $path];
    }

    // --------------------------------------------------------------- simulasi

    public function test_the_dry_run_is_the_default_and_writes_nothing(): void
    {
        [, $path] = $this->legacyRegistration();

        $this->artisan('ppdb:privatize-documents')
            ->expectsOutputToContain('MODE SIMULASI')
            ->assertSuccessful();

        Storage::disk(PpdbDocument::LEGACY_DISK)->assertExists($path);
        Storage::disk(PpdbDocument::DISK)->assertMissing($path);
    }

    public function test_an_explicit_dry_run_flag_also_writes_nothing(): void
    {
        [, $path] = $this->legacyRegistration();

        $this->artisan('ppdb:privatize-documents', ['--dry-run' => true])->assertSuccessful();

        Storage::disk(PpdbDocument::LEGACY_DISK)->assertExists($path);
        Storage::disk(PpdbDocument::DISK)->assertMissing($path);
    }

    public function test_dry_run_and_apply_together_are_refused(): void
    {
        [, $path] = $this->legacyRegistration();

        $this->artisan('ppdb:privatize-documents', ['--apply' => true, '--dry-run' => true])
            ->assertFailed();

        Storage::disk(PpdbDocument::LEGACY_DISK)->assertExists($path);
    }

    // ----------------------------------------------------------- pemindahan

    public function test_apply_copies_to_private_storage_and_removes_the_public_copy(): void
    {
        [$registration, $path] = $this->legacyRegistration();

        $this->artisan('ppdb:privatize-documents', ['--apply' => true])->assertSuccessful();

        Storage::disk(PpdbDocument::DISK)->assertExists($path);
        Storage::disk(PpdbDocument::LEGACY_DISK)->assertMissing($path);

        $this->assertSame('isi-berkas-palsu', Storage::disk(PpdbDocument::DISK)->get($path));

        // Skema dan isi basis data tidak berubah: yang pindah hanya berkasnya.
        $this->assertSame([$path], $registration->fresh()->documents);
    }

    public function test_rerunning_after_a_successful_move_is_idempotent(): void
    {
        [, $path] = $this->legacyRegistration();

        $this->artisan('ppdb:privatize-documents', ['--apply' => true])->assertSuccessful();
        $this->artisan('ppdb:privatize-documents', ['--apply' => true])
            ->expectsOutputToContain('sudah privat')
            ->assertSuccessful();

        Storage::disk(PpdbDocument::DISK)->assertExists($path);
        $this->assertSame('isi-berkas-palsu', Storage::disk(PpdbDocument::DISK)->get($path));
    }

    /**
     * Jalan yang terputus setelah menyalin tetapi sebelum menghapus: salinan
     * privatnya sudah utuh, jadi yang tersisa hanya membuang salinan publiknya.
     */
    public function test_an_interrupted_move_is_finished_not_duplicated(): void
    {
        [, $path] = $this->legacyRegistration();

        Storage::disk(PpdbDocument::DISK)->put($path, 'isi-berkas-palsu');

        $this->artisan('ppdb:privatize-documents', ['--apply' => true])->assertSuccessful();

        Storage::disk(PpdbDocument::LEGACY_DISK)->assertMissing($path);
        $this->assertSame('isi-berkas-palsu', Storage::disk(PpdbDocument::DISK)->get($path));
    }

    /**
     * Berkas privat yang berbeda di jalur yang sama tidak boleh ditimpa, dan
     * berkas publiknya tidak boleh dihapus — keduanya perlu dilihat manusia.
     */
    public function test_a_different_private_file_is_never_overwritten(): void
    {
        [, $path] = $this->legacyRegistration(contents: 'isi-publik-yang-lama');

        Storage::disk(PpdbDocument::DISK)->put($path, 'isi-privat-yang-berbeda-panjangnya');

        $this->artisan('ppdb:privatize-documents', ['--apply' => true])
            ->expectsOutputToContain('DILEWATI')
            ->assertSuccessful();

        $this->assertSame('isi-privat-yang-berbeda-panjangnya', Storage::disk(PpdbDocument::DISK)->get($path));
        Storage::disk(PpdbDocument::LEGACY_DISK)->assertExists($path);
    }

    // -------------------------------------------------------------- pelaporan

    public function test_a_missing_source_is_reported_and_not_counted_as_migrated(): void
    {
        PpdbRegistration::factory()->create([
            'school_id' => $this->school->id,
            'documents' => [PpdbDocument::directoryFor($this->school).'/tidak-pernah-ada.pdf'],
        ]);

        $this->artisan('ppdb:privatize-documents', ['--apply' => true])
            ->expectsOutputToContain('HILANG')
            ->assertSuccessful();
    }

    public function test_an_unsafe_stored_path_is_skipped_untouched(): void
    {
        Storage::disk(PpdbDocument::LEGACY_DISK)->put('payment-proofs/1/bukti.pdf', 'bukan-milik-ppdb');

        PpdbRegistration::factory()->create([
            'school_id' => $this->school->id,
            'documents' => ['payment-proofs/1/bukti.pdf', 'ppdb/../../.env'],
        ]);

        $this->artisan('ppdb:privatize-documents', ['--apply' => true])
            ->expectsOutputToContain('DILEWATI')
            ->assertSuccessful();

        Storage::disk(PpdbDocument::LEGACY_DISK)->assertExists('payment-proofs/1/bukti.pdf');
        Storage::disk(PpdbDocument::DISK)->assertMissing('payment-proofs/1/bukti.pdf');
    }

    /**
     * Perintah ini bekerja dari daftar rujukan, bukan dari isi direktori.
     * Berkas publik yang sah — logo cabang, foto siswa — tidak boleh ikut
     * terbawa.
     */
    public function test_unrelated_public_files_are_never_touched(): void
    {
        Storage::disk(PpdbDocument::LEGACY_DISK)->put('logos/madani.png', 'logo-cabang');
        Storage::disk(PpdbDocument::LEGACY_DISK)->put('ppdb/madani/yatim-piatu.pdf', 'tidak-dirujuk-siapa-pun');

        $this->legacyRegistration();

        $this->artisan('ppdb:privatize-documents', ['--apply' => true])->assertSuccessful();

        Storage::disk(PpdbDocument::LEGACY_DISK)->assertExists('logos/madani.png');
        Storage::disk(PpdbDocument::LEGACY_DISK)->assertExists('ppdb/madani/yatim-piatu.pdf');
        Storage::disk(PpdbDocument::DISK)->assertMissing('logos/madani.png');
        Storage::disk(PpdbDocument::DISK)->assertMissing('ppdb/madani/yatim-piatu.pdf');
    }

    /**
     * Perintah berjalan tanpa sesi, sehingga SchoolScope tidak memasang pagar
     * apa pun (butir 407). Yang dituntut di sini justru itu: seluruh cabang
     * ikut dipindah, bukan satu.
     */
    public function test_every_school_is_covered(): void
    {
        $other = School::factory()->create(['code' => 'CABANG2', 'slug' => 'cabang2']);
        $otherPath = PpdbDocument::directoryFor($other).'/berkas-palsu.pdf';

        Storage::disk(PpdbDocument::LEGACY_DISK)->put($otherPath, 'isi-cabang-dua');
        PpdbRegistration::factory()->create(['school_id' => $other->id, 'documents' => [$otherPath]]);

        [, $path] = $this->legacyRegistration();

        $this->artisan('ppdb:privatize-documents', ['--apply' => true])->assertSuccessful();

        Storage::disk(PpdbDocument::DISK)->assertExists($path);
        Storage::disk(PpdbDocument::DISK)->assertExists($otherPath);
    }

    public function test_a_registration_without_documents_is_left_alone(): void
    {
        PpdbRegistration::factory()->create([
            'school_id' => $this->school->id,
            'documents' => null,
        ]);

        $this->artisan('ppdb:privatize-documents', ['--apply' => true])->assertSuccessful();
    }
}
