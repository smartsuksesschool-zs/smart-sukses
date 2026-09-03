<?php

namespace Tests\Feature\Migration;

use App\Enums\PpdbStatus;
use App\Enums\StudentClassStatus;
use App\Models\AcademicYear;
use App\Models\PpdbRegistration;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Support\Migration\LegacyWorkbook;
use App\Support\Migration\MigrationWriteGuard;
use App\Support\Migration\NisnNormalizer;
use App\Support\Migration\StudentImportApply;
use App\Support\Migration\StudentImportPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

/**
 * M3 — impor uji siswa: normalisasi NISN, kontrak sebagian, dan pagar tulis.
 *
 * Seluruh berkas uji **dibangun saat tes berjalan** dari data karangan, lalu
 * dihapus. Tidak ada NIS, NISN, nama, atau alamat siswa sungguhan di berkas
 * ini — berkas sekolah yang asli tidak pernah masuk repositori (butir 458).
 *
 * NIS karangan memakai awalan `Z` yang tidak dipakai sekolah, dan NISN karangan
 * dipilih agar tidak menyerupai rentang NISN yang sesungguhnya.
 */
class StudentTestImportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Judul kolom berkas 2026/2027, apa adanya — termasuk "TKB" yang tidak
     * dipetakan dan "Kelas di SMAN 11" yang menyebut sekolah mitra.
     *
     * @var array<int, string>
     */
    protected const HEADINGS = ['No', 'NIS', 'NISN', 'Nama', 'Jenis Kelamin', 'TKB', 'Kelas di SMAN 11'];

    protected string $path = '';

    protected function tearDown(): void
    {
        if ($this->path !== '' && is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    // ================================================== perkakas

    /**
     * Meniru bentuk berkas 2026/2027: satu lembar per tingkat, satu baris
     * heading, lalu baris data bernomor.
     *
     * @param  array<string, array<int, array<int, string>>>  $sheets
     */
    protected function workbook(array $sheets): LegacyWorkbook
    {
        $book = new Spreadsheet;
        $first = true;

        foreach ($sheets as $title => $rows) {
            $sheet = $first ? $book->getActiveSheet() : $book->createSheet();
            $first = false;
            $sheet->setTitle($title);
            $sheet->fromArray(self::HEADINGS, null, 'A1');

            foreach ($rows as $i => $row) {
                $sheet->fromArray($row, null, 'A'.($i + 2));
            }
        }

        $this->path = tempnam(sys_get_temp_dir(), 'm3').'.xlsx';
        (new Xlsx($book))->save($this->path);

        return new LegacyWorkbook($this->path);
    }

    /**
     * @param  array<string, array<int, array<int, string>>>  $sheets
     * @return array<string, mixed>
     */
    protected function plan(array $sheets, ?School $school = null, ?AcademicYear $year = null): array
    {
        $school ??= $this->school();
        $workbook = $this->workbook($sheets);

        return (new StudentImportPlan($school, $year))
            ->build($workbook->students($workbook->detectStudentSheets())['rows']);
    }

    protected function school(string $code = 'UJI'): School
    {
        return School::factory()->create(['code' => $code]);
    }

    /**
     * @return array<int, string>
     */
    protected function row(
        int $seq,
        string $nis,
        string $nisn,
        string $name,
        string $gender = 'L',
        string $class = 'X Terbuka - 2',
    ): array {
        return [(string) $seq, $nis, $nisn, $name, $gender, 'SMART Sukses School', $class];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<int, array<string, mixed>>
     */
    protected function rowsWith(array $plan, string $outcome): array
    {
        return array_values(array_filter($plan['rows'], fn (array $r): bool => $r['outcome'] === $outcome));
    }

    // ================================================== normalisasi NISN

    public function test_nisn_delapan_digit_diberi_nol_di_depan(): void
    {
        $this->assertSame(
            ['value' => '0088888888', 'state' => NisnNormalizer::NORMALIZED],
            NisnNormalizer::normalise('88888888'),
        );
    }

    public function test_nisn_sembilan_digit_diberi_nol_di_depan(): void
    {
        $this->assertSame(
            ['value' => '0999999999', 'state' => NisnNormalizer::NORMALIZED],
            NisnNormalizer::normalise('999999999'),
        );
    }

    public function test_nisn_sepuluh_digit_disalin_apa_adanya(): void
    {
        $this->assertSame(
            ['value' => '7777777777', 'state' => NisnNormalizer::VALID],
            NisnNormalizer::normalise('7777777777'),
        );
    }

    public function test_nisn_kosong_menjadi_null(): void
    {
        foreach (['', '   ', null] as $blank) {
            $this->assertSame(
                ['value' => null, 'state' => NisnNormalizer::BLANK],
                NisnNormalizer::normalise($blank),
            );
        }
    }

    public function test_nisn_strip_menjadi_null_bukan_nol(): void
    {
        $this->assertSame(
            ['value' => null, 'state' => NisnNormalizer::BLANK],
            NisnNormalizer::normalise('-'),
        );
    }

    public function test_nisn_bukan_digit_ditolak_bukan_dibersihkan(): void
    {
        foreach (['12A45678', '1234 5678', 'tidak ada'] as $rubbish) {
            $result = NisnNormalizer::normalise($rubbish);

            $this->assertSame(NisnNormalizer::INVALID, $result['state']);
            $this->assertNull($result['value']);
        }
    }

    public function test_nisn_lebih_dari_sepuluh_digit_tidak_pernah_dipotong(): void
    {
        $result = NisnNormalizer::normalise('12345678901');

        $this->assertSame(NisnNormalizer::INVALID, $result['state']);
        $this->assertNull($result['value']);
    }

    public function test_nisn_yang_terbaca_sebagai_angka_besar_tetap_identitas(): void
    {
        // PhpSpreadsheet mengembalikan sel angka sebagai float; `(string)` atas
        // float besar bisa menjadi notasi ilmiah.
        $this->assertSame('7777777777', NisnNormalizer::normalise(7777777777.0)['value']);
        $this->assertSame('0088888888', NisnNormalizer::normalise(88888888.0)['value']);
    }

    public function test_normalisasi_deterministik(): void
    {
        foreach (['88888888', '999999999', '7777777777', '-', 'abc'] as $input) {
            $this->assertSame(
                NisnNormalizer::normalise($input),
                NisnNormalizer::normalise($input),
            );
        }
    }

    public function test_tabrakan_nisn_setelah_normalisasi_terdeteksi(): void
    {
        $plan = $this->plan(['Kelas 10' => [
            $this->row(1, 'Z0001', '0012345678', 'Siswa Karangan Satu'),
            $this->row(2, 'Z0002', '12345678', 'Siswa Karangan Dua'),
        ]]);

        // Keduanya menjadi "0012345678"; yang kedua ditahan, bukan digabung.
        $this->assertSame(1, $plan['outcomes'][StudentImportPlan::READY_CREATE]);
        $this->assertSame(1, $plan['outcomes'][StudentImportPlan::PENDING_DUPLICATE_NISN]);
    }

    public function test_baris_dengan_nisn_rusak_tertunda_bukan_diimpor_tanpa_nisn(): void
    {
        $plan = $this->plan(['Kelas 10' => [
            $this->row(1, 'Z0001', '12A45678', 'Siswa Karangan Satu'),
        ]]);

        $this->assertSame(1, $plan['outcomes'][StudentImportPlan::PENDING_INVALID_NISN]);
        $this->assertSame(0, $plan['reconciliation']['ready']);
    }

    // ================================================== kontrak sebagian

    public function test_satu_baris_tanpa_nis_tidak_menghalangi_baris_lain(): void
    {
        $rows = [];

        for ($i = 1; $i <= 39; $i++) {
            $rows[] = $this->row($i, 'Z'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), '9'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), "Siswa Karangan {$i}");
        }

        $rows[] = $this->row(40, '-', '-', 'Siswa Karangan Tanpa NIS', 'P', '-');

        $plan = $this->plan(['Kelas 10' => $rows]);

        $this->assertSame(40, $plan['reconciliation']['source']);
        $this->assertSame(39, $plan['reconciliation']['ready']);
        $this->assertSame(1, $plan['reconciliation']['pending']);
        $this->assertSame(0, $plan['reconciliation']['rejected']);
        $this->assertTrue($plan['reconciliation']['balanced']);
        $this->assertSame(1, $plan['outcomes'][StudentImportPlan::PENDING_MISSING_NIS]);
    }

    public function test_baris_tanpa_nis_tidak_pernah_diberi_identitas_sementara(): void
    {
        $plan = $this->plan(['Kelas 10' => [
            $this->row(1, '-', '88888888', 'Siswa Karangan Tanpa NIS'),
        ]]);

        $pending = $this->rowsWith($plan, StudentImportPlan::PENDING_MISSING_NIS);

        $this->assertCount(1, $pending);
        $this->assertNull($pending[0]['nis']);
    }

    public function test_baris_tertunda_tidak_pernah_ditulis(): void
    {
        $school = $this->school();
        $plan = $this->plan([
            'Kelas 10' => [
                $this->row(1, 'Z0001', '88888888', 'Siswa Karangan Satu'),
                $this->row(2, '-', '-', 'Siswa Karangan Tanpa NIS'),
            ],
        ], $school);

        (new StudentImportApply($school))->run($plan);

        $this->assertSame(1, Student::query()->where('school_id', $school->id)->count());
        $this->assertDatabaseHas('students', ['nis' => 'Z0001', 'nisn' => '0088888888']);
    }

    public function test_nis_ganda_di_dalam_berkas_ditahan_bukan_ditimpa(): void
    {
        $plan = $this->plan(['Kelas 10' => [
            $this->row(1, 'Z0001', '88888881', 'Siswa Karangan Satu'),
            $this->row(2, 'Z0001', '88888882', 'Siswa Karangan Dua'),
        ]]);

        $this->assertSame(1, $plan['outcomes'][StudentImportPlan::READY_CREATE]);
        $this->assertSame(1, $plan['outcomes'][StudentImportPlan::PENDING_DUPLICATE_NIS]);
    }

    public function test_baris_tanpa_nama_atau_gender_ditolak_bukan_ditunda(): void
    {
        $plan = $this->plan(['Kelas 10' => [
            $this->row(1, 'Z0001', '88888888', 'Siswa Karangan Satu', 'X'),
        ]]);

        $this->assertSame(1, $plan['reconciliation']['rejected']);
        $this->assertSame(
            ['gender'],
            $this->rowsWith($plan, StudentImportPlan::REJECTED_MASTER_INCOMPLETE)[0]['reasons'],
        );
    }

    // ================================================== pencocokan identitas

    public function test_nis_yang_sudah_ada_dicocokkan_bukan_diduplikasi(): void
    {
        $school = $this->school();
        Student::factory()->create([
            'school_id' => $school->id,
            'nis' => 'Z0001',
            'full_name' => 'Nama Yang Sudah Diperbaiki',
            'nisn' => null,
        ]);

        $plan = $this->plan(['Kelas 10' => [
            $this->row(1, 'Z0001', '88888888', 'Nama Lama Di Berkas'),
        ]], $school);

        (new StudentImportApply($school))->run($plan);

        $this->assertSame(1, Student::query()->where('school_id', $school->id)->count());

        // NISN yang kosong diisikan; nama yang sudah ada tidak pernah ditimpa.
        $this->assertDatabaseHas('students', [
            'nis' => 'Z0001',
            'nisn' => '0088888888',
            'full_name' => 'Nama Yang Sudah Diperbaiki',
        ]);
    }

    public function test_nama_sama_dengan_identitas_berbeda_tidak_pernah_dicocokkan(): void
    {
        $school = $this->school();
        Student::factory()->create([
            'school_id' => $school->id,
            'nis' => 'Z9999',
            'full_name' => 'Siswa Karangan Satu',
        ]);

        $plan = $this->plan(['Kelas 10' => [
            $this->row(1, 'Z0001', '88888888', 'Siswa Karangan Satu'),
        ]], $school);

        (new StudentImportApply($school))->run($plan);

        $this->assertSame(2, Student::query()->where('school_id', $school->id)->count());
    }

    public function test_cabang_lain_tidak_pernah_ikut_tercocokkan(): void
    {
        $school = $this->school();
        $other = $this->school('LAIN');

        Student::factory()->create(['school_id' => $other->id, 'nis' => 'Z0001']);

        $plan = $this->plan(['Kelas 10' => [
            $this->row(1, 'Z0001', '88888888', 'Siswa Karangan Satu'),
        ]], $school);

        (new StudentImportApply($school))->run($plan);

        $this->assertSame(1, Student::query()->where('school_id', $school->id)->count());
        $this->assertSame(1, Student::query()->where('school_id', $other->id)->count());
    }

    // ================================================== kelas / rombel

    public function test_kelas_yang_ada_dipakai_untuk_penempatan(): void
    {
        $school = $this->school();
        $year = AcademicYear::factory()->for($school)->create(['is_active' => true]);
        $class = SchoolClass::factory()->create([
            'school_id' => $school->id,
            'academic_year_id' => $year->id,
            'name' => 'X Terbuka - 2',
            'grade_level' => 10,
        ]);

        $plan = $this->plan(['Kelas 10' => [
            $this->row(1, 'Z0001', '88888888', 'Siswa Karangan Satu'),
        ]], $school, $year);

        $result = (new StudentImportApply($school, $year))->run($plan);

        $this->assertSame(1, $result['placed']);
        $this->assertDatabaseHas('student_classes', [
            'class_id' => $class->id,
            'academic_year_id' => $year->id,
            'status' => StudentClassStatus::Active->value,
        ]);
    }

    public function test_kelas_yang_belum_ada_dilaporkan_dan_tidak_pernah_dibuat(): void
    {
        $school = $this->school();
        $year = AcademicYear::factory()->for($school)->create(['is_active' => true]);

        $plan = $this->plan(['Kelas 10' => [
            $this->row(1, 'Z0001', '88888888', 'Siswa Karangan Satu', 'L', 'XII Terbuka - I'),
        ]], $school, $year);

        $this->assertSame(1, $plan['placements'][StudentImportPlan::CLASS_NOT_FOUND]);

        (new StudentImportApply($school, $year))->run($plan);

        // Induk siswa tetap masuk; rombelnya tidak dikarang.
        $this->assertDatabaseHas('students', ['nis' => 'Z0001']);
        $this->assertSame(0, SchoolClass::query()->where('school_id', $school->id)->count());
        $this->assertSame(0, StudentClass::query()->count());
    }

    /**
     * Koreksi data terkonfirmasi: `XII Terbuka - I` adalah salah ketik dari
     * `XII Terbuka - 1` (butir 506).
     */
    public function test_label_salah_ketik_yang_dikoreksi_terkonfirmasi_dipetakan(): void
    {
        $school = $this->school();
        $year = AcademicYear::factory()->for($school)->create(['is_active' => true]);
        $class = SchoolClass::factory()->create([
            'school_id' => $school->id,
            'academic_year_id' => $year->id,
            'name' => 'XII Terbuka - 1',
            'grade_level' => 12,
        ]);

        $plan = $this->plan(['Kelas 12' => [
            $this->row(1, 'Z0001', '88888888', 'Siswa Karangan Satu', 'L', 'XII Terbuka - I'),
        ]], $school, $year);

        $this->assertSame(1, $plan['placements'][StudentImportPlan::PLACEMENT_READY]);
        $this->assertSame('XII Terbuka - 1', $plan['classes'][0]['label']);
        $this->assertSame('XII Terbuka - I', $plan['classes'][0]['source_label']);
        $this->assertTrue($plan['classes'][0]['corrected']);

        (new StudentImportApply($school, $year))->run($plan);

        $this->assertDatabaseHas('student_classes', ['class_id' => $class->id]);
    }

    /**
     * Koreksinya sebuah alias yang ditulis satu per satu, bukan aturan umum
     * yang mengubah angka Romawi di label mana pun.
     */
    public function test_angka_romawi_lain_tidak_ikut_diubah(): void
    {
        $this->assertSame('XII Terbuka - 1', StudentImportPlan::canonicalClassLabel('XII Terbuka - I'));
        $this->assertSame('XII Terbuka - 1', StudentImportPlan::canonicalClassLabel('xii terbuka - i'));

        // Tidak terdaftar sebagai koreksi -> dikembalikan apa adanya.
        $this->assertSame('XI Terbuka - I', StudentImportPlan::canonicalClassLabel('XI Terbuka - I'));
        $this->assertSame('X Terbuka - II', StudentImportPlan::canonicalClassLabel('X Terbuka - II'));
        $this->assertSame('Kelas I', StudentImportPlan::canonicalClassLabel('Kelas I'));
    }

    public function test_label_yang_tidak_dikoreksi_tetap_menuntut_rombel_yang_persis(): void
    {
        $school = $this->school();
        $year = AcademicYear::factory()->for($school)->create(['is_active' => true]);
        SchoolClass::factory()->create([
            'school_id' => $school->id,
            'academic_year_id' => $year->id,
            'name' => 'XI Terbuka - 1',
            'grade_level' => 11,
        ]);

        // "XI Terbuka - I" tidak terdaftar sebagai koreksi terkonfirmasi.
        $plan = $this->plan(['Kelas 11' => [
            $this->row(1, 'Z0001', '88888888', 'Siswa Karangan Satu', 'L', 'XI Terbuka - I'),
        ]], $school, $year);

        $this->assertSame(1, $plan['placements'][StudentImportPlan::CLASS_NOT_FOUND]);
    }

    public function test_keempat_label_kanonis_cocok_ketika_rombelnya_ada(): void
    {
        $school = $this->school();
        $year = AcademicYear::factory()->for($school)->create(['is_active' => true]);

        $canonical = ['X Terbuka - 2' => 10, 'XI Terbuka - 1' => 11, 'XII Terbuka - 1' => 12, 'XII Terbuka - 2' => 12];

        foreach ($canonical as $name => $grade) {
            SchoolClass::factory()->create([
                'school_id' => $school->id,
                'academic_year_id' => $year->id,
                'name' => $name,
                'grade_level' => $grade,
            ]);
        }

        $plan = $this->plan(['Kelas 12' => [
            $this->row(1, 'Z0001', '88888881', 'Siswa Karangan Satu', 'L', 'X Terbuka - 2'),
            $this->row(2, 'Z0002', '88888882', 'Siswa Karangan Dua', 'P', 'XI Terbuka - 1'),
            $this->row(3, 'Z0003', '88888883', 'Siswa Karangan Tiga', 'L', 'XII Terbuka - I'),
            $this->row(4, 'Z0004', '88888884', 'Siswa Karangan Empat', 'P', 'XII Terbuka - 2'),
        ]], $school, $year);

        $this->assertSame(4, $plan['placements'][StudentImportPlan::PLACEMENT_READY]);
        $this->assertArrayNotHasKey(StudentImportPlan::CLASS_NOT_FOUND, $plan['placements']);
    }

    public function test_tingkat_dibaca_dari_token_depan_label(): void
    {
        $this->assertSame(10, StudentImportPlan::gradeLevel('X Terbuka - 2'));
        $this->assertSame(11, StudentImportPlan::gradeLevel('XI Terbuka - 1'));
        $this->assertSame(12, StudentImportPlan::gradeLevel('XII Terbuka - I'));
        $this->assertNull(StudentImportPlan::gradeLevel('Persiapan'));
        $this->assertNull(StudentImportPlan::gradeLevel('-'));
    }

    public function test_tanpa_tahun_ajaran_penempatan_ditunda_tetapi_induk_tetap_siap(): void
    {
        $school = $this->school();

        $plan = $this->plan(['Kelas 10' => [
            $this->row(1, 'Z0001', '88888888', 'Siswa Karangan Satu'),
        ]], $school);

        $this->assertSame(1, $plan['reconciliation']['ready']);
        $this->assertSame(1, $plan['placements'][StudentImportPlan::ACADEMIC_YEAR_MISSING]);
    }

    // ================================================== PPDB

    public function test_tingkat_x_dengan_nama_sama_di_ppdb_ditahan_untuk_diperiksa(): void
    {
        $school = $this->school();

        PpdbRegistration::factory()->create([
            'school_id' => $school->id,
            'full_name' => 'Siswa Karangan Satu',
            'converted_student_id' => null,
            'status' => PpdbStatus::Passed->value,
        ]);

        $plan = $this->plan(['Kelas 10' => [
            $this->row(1, 'Z0001', '88888888', 'Siswa Karangan Satu'),
        ]], $school);

        $this->assertSame(1, $plan['outcomes'][StudentImportPlan::PENDING_PPDB_RECONCILIATION]);
        $this->assertSame(0, $plan['reconciliation']['ready']);
    }

    public function test_pendaftar_ppdb_yang_sudah_dikonversi_tidak_menahan_barisnya(): void
    {
        $school = $this->school();
        $student = Student::factory()->create(['school_id' => $school->id, 'nis' => 'Z5555']);

        PpdbRegistration::factory()->create([
            'school_id' => $school->id,
            'full_name' => 'Siswa Karangan Satu',
            'converted_student_id' => $student->id,
        ]);

        $plan = $this->plan(['Kelas 10' => [
            $this->row(1, 'Z0001', '88888888', 'Siswa Karangan Satu'),
        ]], $school);

        $this->assertSame(1, $plan['outcomes'][StudentImportPlan::READY_CREATE]);
    }

    public function test_tingkat_selain_x_tidak_diperiksa_terhadap_ppdb(): void
    {
        $school = $this->school();

        PpdbRegistration::factory()->create([
            'school_id' => $school->id,
            'full_name' => 'Siswa Karangan Satu',
            'converted_student_id' => null,
        ]);

        $plan = $this->plan(['Kelas 11' => [
            $this->row(1, 'Z0001', '88888888', 'Siswa Karangan Satu', 'L', 'XI Terbuka - 1'),
        ]], $school);

        $this->assertSame(1, $plan['outcomes'][StudentImportPlan::READY_CREATE]);
    }

    // ================================================== akun

    public function test_induk_siswa_boleh_ada_tanpa_akun(): void
    {
        $school = $this->school();
        $plan = $this->plan(['Kelas 10' => [
            $this->row(1, 'Z0001', '88888888', 'Siswa Karangan Satu'),
        ]], $school);

        (new StudentImportApply($school))->run($plan);

        $student = Student::query()->where('nis', 'Z0001')->firstOrFail();

        $this->assertNull($student->user_id);
        $this->assertSame(0, Student::query()->whereNotNull('user_id')->count());
    }

    public function test_tidak_ada_surel_yang_dikarang_untuk_siswa(): void
    {
        $school = $this->school();
        $before = User::query()->count();

        $plan = $this->plan(['Kelas 10' => [
            $this->row(1, 'Z0001', '88888888', 'Siswa Karangan Satu'),
        ]], $school);

        (new StudentImportApply($school))->run($plan);

        $this->assertSame($before, User::query()->count());
    }

    // ================================================== idempotensi & transaksi

    public function test_menjalankan_dua_kali_tidak_menambah_baris(): void
    {
        $school = $this->school();
        $year = AcademicYear::factory()->for($school)->create(['is_active' => true]);
        SchoolClass::factory()->create([
            'school_id' => $school->id,
            'academic_year_id' => $year->id,
            'name' => 'X Terbuka - 2',
            'grade_level' => 10,
        ]);

        $sheets = ['Kelas 10' => [
            $this->row(1, 'Z0001', '88888888', 'Siswa Karangan Satu'),
            $this->row(2, 'Z0002', '88888881', 'Siswa Karangan Dua', 'P'),
        ]];

        $workbook = $this->workbook($sheets);
        $rows = $workbook->students($workbook->detectStudentSheets())['rows'];

        $first = (new StudentImportApply($school, $year))
            ->run((new StudentImportPlan($school, $year))->build($rows));

        $this->assertSame(2, $first['created']);
        $this->assertSame(2, $first['placed']);

        // Rencana dibangun ulang: pada jalannya yang kedua siswa sudah ada.
        $second = (new StudentImportApply($school, $year))
            ->run((new StudentImportPlan($school, $year))->build($rows));

        $this->assertSame(0, $second['created']);
        $this->assertSame(2, $second['matched']);
        $this->assertSame(0, $second['placed']);

        $this->assertSame(2, Student::query()->where('school_id', $school->id)->count());
        $this->assertSame(2, StudentClass::query()->count());
    }

    public function test_siswa_dan_penempatannya_ditulis_dalam_satu_transaksi(): void
    {
        $school = $this->school();
        $year = AcademicYear::factory()->for($school)->create(['is_active' => true]);
        SchoolClass::factory()->create([
            'school_id' => $school->id,
            'academic_year_id' => $year->id,
            'name' => 'X Terbuka - 2',
            'grade_level' => 10,
        ]);

        $plan = $this->plan(['Kelas 10' => [
            $this->row(1, 'Z0001', '88888888', 'Siswa Karangan Satu'),
        ]], $school, $year);

        (new StudentImportApply($school, $year))->run($plan);

        // Tidak ada siswa yang separuh jadi: yang punya baris students juga
        // punya baris student_classes ketika rombelnya ada.
        $this->assertSame(1, Student::query()->where('school_id', $school->id)->count());
        $this->assertSame(1, StudentClass::query()->count());
    }

    // ================================================== pagar tulis

    public function test_pagar_menolak_basis_data_yang_bukan_basis_data_uji(): void
    {
        $this->assertFalse(MigrationWriteGuard::looksLikeTestDatabase('smartsukses'));
        $this->assertFalse(MigrationWriteGuard::looksLikeTestDatabase('smartsukses_production'));
        $this->assertFalse(MigrationWriteGuard::looksLikeTestDatabase('testing_utama'));

        $this->assertTrue(MigrationWriteGuard::looksLikeTestDatabase('smartsukses_test'));
        $this->assertTrue(MigrationWriteGuard::looksLikeTestDatabase(':memory:'));
        $this->assertTrue(MigrationWriteGuard::looksLikeTestDatabase('testing'));
        $this->assertTrue(MigrationWriteGuard::looksLikeTestDatabase('C:/data/smartsukses_test.sqlite'));
    }

    public function test_mode_terap_menolak_lingkungan_produksi(): void
    {
        $school = $this->school();
        $plan = $this->plan(['Kelas 10' => [
            $this->row(1, 'Z0001', '88888888', 'Siswa Karangan Satu'),
        ]], $school);

        app()->detectEnvironment(fn (): string => 'production');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('production');

            (new StudentImportApply($school))->run($plan);
        } finally {
            app()->detectEnvironment(fn (): string => 'testing');
        }
    }

    public function test_perintah_terap_tanpa_konfirmasi_tidak_menulis_apa_pun(): void
    {
        $school = $this->school('PUSAT');
        AcademicYear::factory()->for($school)->create(['is_active' => true]);

        $this->workbook(['Kelas 10' => [
            $this->row(1, 'Z0001', '88888888', 'Siswa Karangan Satu'),
        ]]);

        $this->artisan('migrasi:terapkan-uji', ['berkas' => $this->path, '--school' => 'PUSAT'])
            ->assertExitCode(0);

        $this->assertSame(0, Student::query()->count());
    }

    public function test_perintah_terap_menulis_hanya_baris_yang_siap(): void
    {
        $school = $this->school('PUSAT');
        AcademicYear::factory()->for($school)->create(['is_active' => true]);

        $this->workbook(['Kelas 10' => [
            $this->row(1, 'Z0001', '88888888', 'Siswa Karangan Satu'),
            $this->row(2, '-', '-', 'Siswa Karangan Tanpa NIS', 'P', '-'),
        ]]);

        $this->artisan('migrasi:terapkan-uji', [
            'berkas' => $this->path,
            '--school' => 'PUSAT',
            '--konfirmasi' => true,
        ])->assertExitCode(1);

        $this->assertSame(1, Student::query()->count());
        $this->assertDatabaseHas('students', ['nis' => 'Z0001']);
    }

    public function test_perintah_terap_berhenti_bila_cabangnya_tidak_ada(): void
    {
        $this->workbook(['Kelas 10' => [
            $this->row(1, 'Z0001', '88888888', 'Siswa Karangan Satu'),
        ]]);

        $this->artisan('migrasi:terapkan-uji', [
            'berkas' => $this->path,
            '--school' => 'TIDAK-ADA',
            '--konfirmasi' => true,
        ])->assertExitCode(2);

        $this->assertSame(0, Student::query()->count());
    }

    // ================================================== pembacaan berkas

    public function test_lembar_per_tingkat_dibaca_menjadi_satu_daftar(): void
    {
        $workbook = $this->workbook([
            'Kelas 12' => [$this->row(1, 'Z1201', '88888888', 'Siswa Karangan Satu', 'L', 'XII Terbuka - 2')],
            'Kelas 11' => [$this->row(1, 'Z1101', '88888881', 'Siswa Karangan Dua', 'P', 'XI Terbuka - 1')],
            'Kelas 10' => [$this->row(1, 'Z1001', '88888882', 'Siswa Karangan Tiga', 'L', 'X Terbuka - 2')],
        ]);

        $this->assertSame(['Kelas 12', 'Kelas 11', 'Kelas 10'], $workbook->detectStudentSheets());
        $this->assertSame([], $workbook->detectTeacherSheets());

        $read = $workbook->students($workbook->detectStudentSheets());

        $this->assertCount(3, $read['rows']);
        $this->assertSame('Kelas 12', $read['rows'][0]['sheet']);
        $this->assertSame(['TKB'], $read['unknown_headings']);
    }

    public function test_berkas_sumber_tidak_pernah_diubah(): void
    {
        $this->workbook(['Kelas 10' => [
            $this->row(1, 'Z0001', '88888888', 'Siswa Karangan Satu'),
        ]]);

        $before = [filesize($this->path), md5_file($this->path)];

        $school = $this->school();
        $workbook = new LegacyWorkbook($this->path);
        $plan = (new StudentImportPlan($school))
            ->build($workbook->students($workbook->detectStudentSheets())['rows']);
        (new StudentImportApply($school))->run($plan);

        clearstatcache(true, $this->path);

        $this->assertSame($before, [filesize($this->path), md5_file($this->path)]);
    }
}
