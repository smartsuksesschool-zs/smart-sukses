<?php

namespace Tests\Feature\Migration;

use App\Models\AcademicYear;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Support\Migration\ImportFingerprint;
use App\Support\Migration\LegacyWorkbook;
use App\Support\Migration\ProductionImportAuthorization;
use App\Support\Migration\StudentImportApply;
use App\Support\Migration\StudentImportPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\PendingCommand;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

/**
 * M5 — pagar otorisasi impor produksi.
 *
 * Seluruh berkas uji dibangun saat tes berjalan dari data karangan lalu
 * dihapus. Tidak ada NIS, NISN, nama, atau alamat siswa sungguhan; NIS karangan
 * berawalan `Z`.
 *
 * Lingkungan `production` **disimulasikan** dengan `detectEnvironment` terhadap
 * basis data uji yang terisolasi. Tidak ada satu pun tes di berkas ini yang
 * menyentuh basis data sungguhan mana pun.
 */
class ProductionImportGateTest extends TestCase
{
    use RefreshDatabase;

    protected string $path = '';

    protected function tearDown(): void
    {
        app()->detectEnvironment(fn (): string => 'testing');

        if ($this->path !== '' && is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    // ================================================== perkakas

    protected function asProduction(): void
    {
        app()->detectEnvironment(fn (): string => 'production');
    }

    protected function school(string $code = 'PUSAT'): School
    {
        return School::factory()->create(['code' => $code]);
    }

    protected function year(School $school, string $name = '2026/2027 Ganjil'): AcademicYear
    {
        return AcademicYear::factory()->for($school)->create([
            'name' => $name,
            'semester' => 1,
            'is_active' => true,
        ]);
    }

    protected function rombel(School $school, AcademicYear $year): void
    {
        SchoolClass::factory()->create([
            'school_id' => $school->id,
            'academic_year_id' => $year->id,
            'name' => 'X Terbuka - 2',
            'grade_level' => 10,
        ]);
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    protected function workbook(array $rows): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Kelas 10');
        $sheet->fromArray(['No', 'NIS', 'NISN', 'Nama', 'Jenis Kelamin', 'TKB', 'Kelas di SMAN 11'], null, 'A1');

        foreach ($rows as $i => $row) {
            $sheet->fromArray($row, null, 'A'.($i + 2));
        }

        $this->path = tempnam(sys_get_temp_dir(), 'm5').'.xlsx';
        (new Xlsx($book))->save($this->path);

        return $this->path;
    }

    /**
     * Dua baris sehat plus satu baris tanpa NIS — pola sumber yang sebenarnya,
     * diperkecil.
     */
    protected function healthyWorkbook(): string
    {
        return $this->workbook([
            ['1', 'Z0001', '0088888881', 'Siswa Karangan Satu', 'L', 'SMART', 'X Terbuka - 2'],
            ['2', 'Z0002', '0088888882', 'Siswa Karangan Dua', 'P', 'SMART', 'X Terbuka - 2'],
            ['3', '-', '-', 'Siswa Karangan Tanpa NIS', 'L', 'SMART', '-'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function planFor(string $path, School $school, ?AcademicYear $year): array
    {
        $workbook = new LegacyWorkbook($path);

        return (new StudentImportPlan($school, $year))
            ->forSource($path)
            ->build($workbook->students($workbook->detectStudentSheets())['rows']);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function runCommand(string $path, array $extra = []): PendingCommand
    {
        return $this->artisan('migrasi:terapkan-produksi', array_merge([
            'berkas' => $path,
            '--school' => 'PUSAT',
            '--tahun-ajaran' => '2026/2027 Ganjil',
        ], $extra));
    }

    // ================================================== pagar lingkungan

    public function test_menolak_di_luar_produksi(): void
    {
        $school = $this->school();
        $year = $this->year($school);
        $this->rombel($school, $year);

        $path = $this->healthyWorkbook();

        // Lingkungan tetap `testing`.
        $this->runCommand($path, ['--konfirmasi' => true])->assertExitCode(1);

        $this->assertSame(0, Student::query()->count());
    }

    public function test_otorisasi_tidak_terbit_di_luar_produksi(): void
    {
        $school = $this->school();
        $year = $this->year($school);
        $this->rombel($school, $year);

        $plan = $this->planFor($this->healthyWorkbook(), $school, $year);

        $this->assertFalse(ProductionImportAuthorization::allowed($plan, $year));
        $this->assertNull(ProductionImportAuthorization::grant(
            $plan,
            $school,
            $year,
            ImportFingerprint::of($this->path, $school, $year, $plan),
        ));
    }

    // ================================================== sasaran wajib eksplisit

    public function test_menolak_tanpa_cabang(): void
    {
        $this->asProduction();
        $school = $this->school();
        $this->year($school);

        $this->artisan('migrasi:terapkan-produksi', [
            'berkas' => $this->healthyWorkbook(),
            '--tahun-ajaran' => '2026/2027 Ganjil',
        ])->assertExitCode(2);
    }

    public function test_menolak_tanpa_tahun_ajaran(): void
    {
        $this->asProduction();
        $school = $this->school();
        $this->year($school);

        $this->artisan('migrasi:terapkan-produksi', [
            'berkas' => $this->healthyWorkbook(),
            '--school' => 'PUSAT',
        ])->assertExitCode(2);
    }

    /**
     * Tidak pernah jatuh ke tahun ajaran aktif seperti perintah uji: tahun yang
     * salah menempatkan seluruh siswa di tahun yang keliru.
     */
    public function test_tidak_menebak_tahun_ajaran_aktif(): void
    {
        $this->asProduction();
        $school = $this->school();
        $year = $this->year($school);
        $this->rombel($school, $year);

        $this->artisan('migrasi:terapkan-produksi', [
            'berkas' => $this->healthyWorkbook(),
            '--school' => 'PUSAT',
            '--tahun-ajaran' => '2030/2031 Ganjil',
        ])->assertExitCode(2);

        $this->assertSame(0, Student::query()->count());
    }

    public function test_menolak_berkas_yang_tidak_ada(): void
    {
        $this->asProduction();
        $school = $this->school();
        $this->year($school);

        $this->artisan('migrasi:terapkan-produksi', [
            'berkas' => sys_get_temp_dir().'/tidak-ada-'.uniqid().'.xlsx',
            '--school' => 'PUSAT',
            '--tahun-ajaran' => '2026/2027 Ganjil',
        ])->assertExitCode(2);
    }

    // ================================================== pagar konfirmasi

    public function test_menolak_tanpa_pernyataan_backup(): void
    {
        $this->asProduction();
        $school = $this->school();
        $year = $this->year($school);
        $this->rombel($school, $year);

        $path = $this->healthyWorkbook();
        $plan = $this->planFor($path, $school, $year);
        $fingerprint = ImportFingerprint::of($path, $school, $year, $plan);

        $this->runCommand($path, [
            '--konfirmasi' => true,
            '--sidik-jari' => $fingerprint->value,
        ])->assertExitCode(1);

        $this->assertSame(0, Student::query()->count());
    }

    public function test_menolak_tanpa_sidik_jari(): void
    {
        $this->asProduction();
        $school = $this->school();
        $year = $this->year($school);
        $this->rombel($school, $year);

        $this->runCommand($this->healthyWorkbook(), [
            '--konfirmasi' => true,
            '--backup-terverifikasi' => true,
        ])->assertExitCode(1);

        $this->assertSame(0, Student::query()->count());
    }

    public function test_sidik_jari_yang_salah_ditolak(): void
    {
        $this->asProduction();
        $school = $this->school();
        $year = $this->year($school);
        $this->rombel($school, $year);

        $this->runCommand($this->healthyWorkbook(), [
            '--konfirmasi' => true,
            '--backup-terverifikasi' => true,
            '--sidik-jari' => '0000000000000000',
        ])->assertExitCode(1);

        $this->assertSame(0, Student::query()->count());
    }

    /**
     * Inti pagar sidik jari: analisis kering atas berkas A, terap atas berkas B.
     */
    public function test_sidik_jari_berkas_lain_ditolak(): void
    {
        $this->asProduction();
        $school = $this->school();
        $year = $this->year($school);
        $this->rombel($school, $year);

        // Berkas A ditinjau.
        $other = $this->workbook([
            ['1', 'Z9001', '0088889991', 'Siswa Karangan Lain', 'L', 'SMART', 'X Terbuka - 2'],
        ]);
        $planA = $this->planFor($other, $school, $year);
        $fingerprintA = ImportFingerprint::of($other, $school, $year, $planA);

        // Berkas B diterapkan dengan sidik jari berkas A.
        $target = $this->healthyWorkbook();

        $this->runCommand($target, [
            '--konfirmasi' => true,
            '--backup-terverifikasi' => true,
            '--sidik-jari' => $fingerprintA->value,
        ])->assertExitCode(1);

        $this->assertSame(0, Student::query()->count());
    }

    public function test_kalimat_konfirmasi_yang_salah_ditolak(): void
    {
        $this->asProduction();
        $school = $this->school();
        $year = $this->year($school);
        $this->rombel($school, $year);

        $path = $this->healthyWorkbook();
        $plan = $this->planFor($path, $school, $year);
        $fingerprint = ImportFingerprint::of($path, $school, $year, $plan);

        $this->runCommand($path, [
            '--konfirmasi' => true,
            '--backup-terverifikasi' => true,
            '--sidik-jari' => $fingerprint->value,
        ])
            ->expectsQuestion('Kalimat konfirmasi', 'IMPOR SEMUA SISWA')
            ->assertExitCode(1);

        $this->assertSame(0, Student::query()->count());
    }

    public function test_kalimat_konfirmasi_diturunkan_dari_rencana(): void
    {
        $this->asProduction();
        $school = $this->school();
        $year = $this->year($school);
        $this->rombel($school, $year);

        $path = $this->healthyWorkbook();
        $plan = $this->planFor($path, $school, $year);

        $authorization = ProductionImportAuthorization::grant(
            $plan,
            $school,
            $year,
            ImportFingerprint::of($path, $school, $year, $plan),
        );

        // Jumlahnya dari rencana, bukan ditulis tetap di kode.
        $this->assertSame('IMPOR 2 SISWA PUSAT 2026/2027 Ganjil', $authorization->confirmationPhrase());
        $this->assertTrue($authorization->phraseMatches('  impor 2 siswa pusat 2026/2027 ganjil '));
        $this->assertFalse($authorization->phraseMatches('IMPOR 3 SISWA PUSAT 2026/2027 Ganjil'));
    }

    // ================================================== rekonsiliasi wajib bersih

    public function test_baris_ditolak_menghalangi_impor_produksi(): void
    {
        $this->asProduction();
        $school = $this->school();
        $year = $this->year($school);
        $this->rombel($school, $year);

        // Jenis kelamin tidak terbaca -> REJECTED_MASTER_INCOMPLETE.
        $path = $this->workbook([
            ['1', 'Z0001', '0088888881', 'Siswa Karangan Satu', 'X', 'SMART', 'X Terbuka - 2'],
        ]);

        $plan = $this->planFor($path, $school, $year);

        $this->assertNotSame([], ProductionImportAuthorization::refusals($plan, $year));

        $this->runCommand($path, ['--konfirmasi' => true])->assertExitCode(1);
        $this->assertSame(0, Student::query()->count());
    }

    public function test_class_not_found_menghalangi_impor_produksi(): void
    {
        $this->asProduction();
        $school = $this->school();
        $year = $this->year($school);
        // Rombel sengaja tidak dibuat.

        $path = $this->healthyWorkbook();
        $plan = $this->planFor($path, $school, $year);

        $this->assertGreaterThan(0, $plan['placements'][StudentImportPlan::CLASS_NOT_FOUND]);
        $this->assertNotSame([], ProductionImportAuthorization::refusals($plan, $year));

        $this->runCommand($path, ['--konfirmasi' => true])->assertExitCode(1);
        $this->assertSame(0, Student::query()->count());
    }

    public function test_class_ambiguous_menghalangi_impor_produksi(): void
    {
        $this->asProduction();
        $school = $this->school();
        $year = $this->year($school);

        foreach ([1, 2] as $ignored) {
            $this->rombel($school, $year);
        }

        $path = $this->healthyWorkbook();
        $plan = $this->planFor($path, $school, $year);

        $this->assertGreaterThan(0, $plan['placements'][StudentImportPlan::CLASS_AMBIGUOUS]);

        $this->runCommand($path, ['--konfirmasi' => true])->assertExitCode(1);
        $this->assertSame(0, Student::query()->count());
    }

    public function test_nis_ganda_menghalangi_impor_produksi(): void
    {
        $this->asProduction();
        $school = $this->school();
        $year = $this->year($school);
        $this->rombel($school, $year);

        $path = $this->workbook([
            ['1', 'Z0001', '0088888881', 'Siswa Karangan Satu', 'L', 'SMART', 'X Terbuka - 2'],
            ['2', 'Z0001', '0088888882', 'Siswa Karangan Dua', 'P', 'SMART', 'X Terbuka - 2'],
        ]);

        $plan = $this->planFor($path, $school, $year);

        $this->assertSame(1, $plan['outcomes'][StudentImportPlan::PENDING_DUPLICATE_NIS]);
        $this->assertNotSame([], ProductionImportAuthorization::refusals($plan, $year));

        $this->runCommand($path, ['--konfirmasi' => true])->assertExitCode(1);
        $this->assertSame(0, Student::query()->count());
    }

    public function test_nisn_ganda_setelah_normalisasi_menghalangi_impor_produksi(): void
    {
        $this->asProduction();
        $school = $this->school();
        $year = $this->year($school);
        $this->rombel($school, $year);

        $path = $this->workbook([
            ['1', 'Z0001', '0012345678', 'Siswa Karangan Satu', 'L', 'SMART', 'X Terbuka - 2'],
            ['2', 'Z0002', '12345678', 'Siswa Karangan Dua', 'P', 'SMART', 'X Terbuka - 2'],
        ]);

        $plan = $this->planFor($path, $school, $year);

        $this->assertSame(1, $plan['outcomes'][StudentImportPlan::PENDING_DUPLICATE_NISN]);
        $this->assertNotSame([], ProductionImportAuthorization::refusals($plan, $year));

        $this->runCommand($path, ['--konfirmasi' => true])->assertExitCode(1);
        $this->assertSame(0, Student::query()->count());
    }

    /**
     * Satu siswa yang menunggu NIS resmi adalah keadaan yang sudah diputuskan
     * dan diterima. Ia tidak boleh menahan baris lain (butir 487).
     */
    public function test_satu_baris_tanpa_nis_tetap_boleh_diimpor_produksi(): void
    {
        $this->asProduction();
        $school = $this->school();
        $year = $this->year($school);
        $this->rombel($school, $year);

        $plan = $this->planFor($this->healthyWorkbook(), $school, $year);

        $this->assertSame(1, $plan['outcomes'][StudentImportPlan::PENDING_MISSING_NIS]);
        $this->assertSame([], ProductionImportAuthorization::refusals($plan, $year));
    }

    // ================================================== pratinjau tidak menulis

    public function test_pratinjau_tidak_menulis_apa_pun(): void
    {
        $this->asProduction();
        $school = $this->school();
        $year = $this->year($school);
        $this->rombel($school, $year);

        $this->runCommand($this->healthyWorkbook())->assertExitCode(0);

        $this->assertSame(0, Student::query()->count());
        $this->assertSame(0, StudentClass::query()->count());
    }

    // ================================================== jalur sah

    public function test_jalur_yang_diotorisasi_menulis_master_dan_penempatan(): void
    {
        $this->asProduction();
        $school = $this->school();
        $year = $this->year($school);
        $this->rombel($school, $year);

        $path = $this->healthyWorkbook();
        $plan = $this->planFor($path, $school, $year);
        $fingerprint = ImportFingerprint::of($path, $school, $year, $plan);

        $this->runCommand($path, [
            '--konfirmasi' => true,
            '--backup-terverifikasi' => true,
            '--sidik-jari' => $fingerprint->value,
        ])
            ->expectsQuestion('Kalimat konfirmasi', 'IMPOR 2 SISWA PUSAT 2026/2027 Ganjil')
            ->assertExitCode(0);

        $this->assertSame(2, Student::query()->count());
        $this->assertSame(2, StudentClass::query()->count());

        // Baris tertunda tidak menghasilkan apa pun.
        $this->assertDatabaseMissing('students', ['full_name' => 'Siswa Karangan Tanpa NIS']);
    }

    public function test_jalur_yang_diotorisasi_idempoten(): void
    {
        $this->asProduction();
        $school = $this->school();
        $year = $this->year($school);
        $this->rombel($school, $year);

        $path = $this->healthyWorkbook();

        for ($run = 1; $run <= 2; $run++) {
            $plan = $this->planFor($path, $school, $year);
            $fingerprint = ImportFingerprint::of($path, $school, $year, $plan);

            $this->runCommand($path, [
                '--konfirmasi' => true,
                '--backup-terverifikasi' => true,
                '--sidik-jari' => $fingerprint->value,
            ])
                ->expectsQuestion('Kalimat konfirmasi', 'IMPOR 2 SISWA PUSAT 2026/2027 Ganjil')
                ->assertExitCode(0);
        }

        $this->assertSame(2, Student::query()->count());
        $this->assertSame(2, StudentClass::query()->count());
    }

    public function test_impor_produksi_tidak_membuat_akun(): void
    {
        $this->asProduction();
        $school = $this->school();
        $year = $this->year($school);
        $this->rombel($school, $year);

        $before = User::query()->count();

        $path = $this->healthyWorkbook();
        $plan = $this->planFor($path, $school, $year);
        $fingerprint = ImportFingerprint::of($path, $school, $year, $plan);

        $this->runCommand($path, [
            '--konfirmasi' => true,
            '--backup-terverifikasi' => true,
            '--sidik-jari' => $fingerprint->value,
        ])
            ->expectsQuestion('Kalimat konfirmasi', 'IMPOR 2 SISWA PUSAT 2026/2027 Ganjil')
            ->assertExitCode(0);

        $this->assertSame($before, User::query()->count());
        $this->assertSame(0, Student::query()->whereNotNull('user_id')->count());
    }

    /**
     * Nama yang sama dengan identitas berbeda tidak pernah dicocokkan — jaminan
     * M3 yang harus tetap berlaku di jalur produksi.
     */
    public function test_tidak_pernah_mencocokkan_berdasarkan_nama(): void
    {
        $this->asProduction();
        $school = $this->school();
        $year = $this->year($school);
        $this->rombel($school, $year);

        Student::factory()->create([
            'school_id' => $school->id,
            'nis' => 'Z9999',
            'full_name' => 'Siswa Karangan Satu',
        ]);

        $path = $this->healthyWorkbook();
        $plan = $this->planFor($path, $school, $year);
        $fingerprint = ImportFingerprint::of($path, $school, $year, $plan);

        $this->runCommand($path, [
            '--konfirmasi' => true,
            '--backup-terverifikasi' => true,
            '--sidik-jari' => $fingerprint->value,
        ])
            ->expectsQuestion('Kalimat konfirmasi', 'IMPOR 2 SISWA PUSAT 2026/2027 Ganjil')
            ->assertExitCode(0);

        // Tiga: yang sudah ada, ditambah dua dari berkas.
        $this->assertSame(3, Student::query()->count());
    }

    public function test_cabang_lain_tidak_pernah_ikut_tersentuh(): void
    {
        $this->asProduction();
        $school = $this->school();
        $year = $this->year($school);
        $this->rombel($school, $year);

        $other = $this->school('LAIN');
        Student::factory()->create(['school_id' => $other->id, 'nis' => 'Z0001']);

        $path = $this->healthyWorkbook();
        $plan = $this->planFor($path, $school, $year);
        $fingerprint = ImportFingerprint::of($path, $school, $year, $plan);

        $this->runCommand($path, [
            '--konfirmasi' => true,
            '--backup-terverifikasi' => true,
            '--sidik-jari' => $fingerprint->value,
        ])
            ->expectsQuestion('Kalimat konfirmasi', 'IMPOR 2 SISWA PUSAT 2026/2027 Ganjil')
            ->assertExitCode(0);

        $this->assertSame(2, Student::query()->where('school_id', $school->id)->count());
        $this->assertSame(1, Student::query()->where('school_id', $other->id)->count());
    }

    // ================================================== otorisasi sebagai bukti

    public function test_otorisasi_tidak_dapat_dipakai_untuk_rencana_lain(): void
    {
        $this->asProduction();
        $school = $this->school();
        $year = $this->year($school);
        $this->rombel($school, $year);

        $pathA = $this->healthyWorkbook();
        $planA = $this->planFor($pathA, $school, $year);
        $authorization = ProductionImportAuthorization::grant(
            $planA,
            $school,
            $year,
            ImportFingerprint::of($pathA, $school, $year, $planA),
        );

        $this->assertNotNull($authorization);

        // Rencana lain, otorisasi yang sama.
        $pathB = $this->workbook([
            ['1', 'Z7001', '0088887771', 'Siswa Karangan Tiga', 'L', 'SMART', 'X Terbuka - 2'],
        ]);
        $planB = $this->planFor($pathB, $school, $year);

        $this->expectException(RuntimeException::class);

        (new StudentImportApply($school, $year))
            ->authorizedForProduction($authorization)
            ->run($planB);
    }

    public function test_otorisasi_ditolak_bila_lingkungan_berubah(): void
    {
        $this->asProduction();
        $school = $this->school();
        $year = $this->year($school);
        $this->rombel($school, $year);

        $path = $this->healthyWorkbook();
        $plan = $this->planFor($path, $school, $year);
        $authorization = ProductionImportAuthorization::grant(
            $plan,
            $school,
            $year,
            ImportFingerprint::of($path, $school, $year, $plan),
        );

        // Diterbitkan di produksi, dipakai di tempat lain.
        app()->detectEnvironment(fn (): string => 'staging');

        $this->expectException(RuntimeException::class);

        (new StudentImportApply($school, $year))
            ->authorizedForProduction($authorization)
            ->run($plan);
    }

    /**
     * Pagar basis data uji untuk `migrasi:terapkan-uji` tidak boleh melemah
     * karena adanya jalur produksi.
     */
    public function test_pagar_impor_uji_tidak_melemah(): void
    {
        $this->asProduction();
        $school = $this->school();
        $year = $this->year($school);
        $this->rombel($school, $year);

        $plan = $this->planFor($this->healthyWorkbook(), $school, $year);

        $this->expectException(RuntimeException::class);

        // Tanpa otorisasi: MigrationWriteGuard tetap menolak di produksi.
        (new StudentImportApply($school, $year))->run($plan);
    }

    // ================================================== sidik jari

    public function test_sidik_jari_deterministik_dan_tanpa_pii(): void
    {
        $this->asProduction();
        $school = $this->school();
        $year = $this->year($school);
        $this->rombel($school, $year);

        $path = $this->healthyWorkbook();
        $plan = $this->planFor($path, $school, $year);

        $a = ImportFingerprint::of($path, $school, $year, $plan);
        $b = ImportFingerprint::of($path, $school, $year, $plan);

        $this->assertSame($a->value, $b->value);
        $this->assertSame(ImportFingerprint::LENGTH, strlen($a->value));

        // Tidak memuat identitas apa pun dari berkasnya.
        foreach (['Z0001', '0088888881', 'Siswa Karangan Satu'] as $identity) {
            $this->assertStringNotContainsString($identity, $a->value);
            $this->assertStringNotContainsString($identity, $a->fileHash);
        }

        // Nama berkas saja, bukan jalur lengkapnya.
        $this->assertStringNotContainsString(dirname($path), $a->fileName);
    }

    public function test_sidik_jari_berubah_bila_cabang_berbeda(): void
    {
        $this->asProduction();
        $school = $this->school();
        $year = $this->year($school);
        $this->rombel($school, $year);

        $other = $this->school('LAIN');
        $otherYear = $this->year($other);

        $path = $this->healthyWorkbook();
        $plan = $this->planFor($path, $school, $year);

        $this->assertNotSame(
            ImportFingerprint::of($path, $school, $year, $plan)->value,
            ImportFingerprint::of($path, $other, $otherYear, $plan)->value,
        );
    }

    // ================================================== jejak audit

    public function test_jejak_audit_hanya_memuat_agregat(): void
    {
        $this->asProduction();
        $school = $this->school();
        $year = $this->year($school);
        $this->rombel($school, $year);

        $captured = [];

        Log::listen(function ($message) use (&$captured): void {
            $captured[] = $message;
        });

        $path = $this->healthyWorkbook();
        $plan = $this->planFor($path, $school, $year);
        $fingerprint = ImportFingerprint::of($path, $school, $year, $plan);

        $this->runCommand($path, [
            '--konfirmasi' => true,
            '--backup-terverifikasi' => true,
            '--sidik-jari' => $fingerprint->value,
        ])
            ->expectsQuestion('Kalimat konfirmasi', 'IMPOR 2 SISWA PUSAT 2026/2027 Ganjil')
            ->assertExitCode(0);

        $import = collect($captured)
            ->first(fn ($m): bool => str_contains($m->message, 'migrasi siswa produksi'));

        $this->assertNotNull($import, 'catatan impor produksi tidak tertulis');

        $context = $import->context;

        // Agregat yang memang harus ada.
        foreach (['school_code', 'academic_year', 'source_file', 'source_sha256', 'fingerprint',
            'source_rows', 'ready', 'pending', 'rejected', 'created', 'matched', 'placed'] as $key) {
            $this->assertArrayHasKey($key, $context, "kunci {$key} hilang dari jejak audit");
        }

        $this->assertSame(2, $context['created']);
        $this->assertSame(1, $context['pending']);
        $this->assertSame(0, $context['accounts_created']);

        // Tidak ada identitas apa pun — bahkan yang karangan.
        $encoded = json_encode($context);

        foreach (['Z0001', 'Z0002', '0088888881', '0088888882', 'Siswa Karangan'] as $identity) {
            $this->assertStringNotContainsString($identity, $encoded);
        }

        // Jalur berkas privat tidak pernah ikut.
        $this->assertStringNotContainsString(dirname($path), $encoded);
    }
}
