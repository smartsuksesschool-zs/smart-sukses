<?php

namespace Tests\Feature\Finance;

use App\Enums\RoleName;
use App\Enums\StudentClassStatus;
use App\Enums\StudentFeeStatus;
use App\Exports\StudentFeesExport;
use App\Filament\Resources\StudentFeeResource\Pages\ListStudentFees;
use App\Models\AcademicYear;
use App\Models\FeeType;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentFee;
use App\Models\User;
use App\Services\Finance\StudentFeeReportExporter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SPP-05 — "mengekspor laporan tagihan per periode ke Excel". Kolom: nama
 * siswa, kelas, periode, jumlah tagihan, jumlah bayar, sisa, status. Filter:
 * kelas, periode, status.
 */
class StudentFeeExportTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected User $bendaharaA;

    protected AcademicYear $yearA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        Storage::fake('local');

        $this->schoolA = School::factory()->create(['code' => 'ALPHA01']);
        $this->schoolB = School::factory()->create(['code' => 'BETA02']);

        $this->bendaharaA = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::Bendahara)->create();

        $this->yearA = AcademicYear::factory()->create([
            'school_id' => $this->schoolA->id,
            'name' => '2026/2027 Ganjil',
            'is_active' => true,
        ]);
    }

    protected function classIn(School $school, string $name, ?AcademicYear $year = null): SchoolClass
    {
        return SchoolClass::factory()->create([
            'school_id' => $school->id,
            'name' => $name,
            'academic_year_id' => ($year ?? $this->yearA)->id,
        ]);
    }

    protected function placeInClass(Student $student, SchoolClass $class, AcademicYear $year, StudentClassStatus $status = StudentClassStatus::Active): StudentClass
    {
        return StudentClass::factory()->create([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'class_id' => $class->id,
            'academic_year_id' => $year->id,
            'status' => $status->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function fee(School $school, Student $student, array $overrides = []): StudentFee
    {
        return StudentFee::factory()->create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'fee_type_id' => FeeType::factory()->create(['school_id' => $school->id])->id,
            'academic_year_id' => $this->yearA->id,
            'amount' => '1000000',
            'amount_paid' => '0',
            'due_date' => '2026-08-10',
            'period' => '2026-08',
            'status' => StudentFeeStatus::Unpaid->value,
            ...$overrides,
        ]);
    }

    protected function studentIn(School $school, string $name): Student
    {
        return Student::factory()->create(['school_id' => $school->id, 'full_name' => $name]);
    }

    /**
     * Menjalankan export dan mengembalikan seluruh baris sheet pertama,
     * termasuk barisan header.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<int, mixed>>
     */
    protected function exportRows(array $filters, ?User $actor = null): array
    {
        $exporter = app(StudentFeeReportExporter::class);
        $export = new StudentFeesExport($exporter->query($actor ?? $this->bendaharaA, $filters));

        // Ditulis sebagai .xlsx sungguhan lalu dibaca ulang PhpSpreadsheet:
        // yang diuji berkasnya, bukan array yang kebetulan dikembalikan
        // exporter. Pola store-ke-disk-lokal mengikuti StudentImportExportTest.
        Excel::store($export, 'laporan-tagihan-test.xlsx', 'local');

        $path = Storage::disk('local')->path('laporan-tagihan-test.xlsx');

        $this->assertFileExists($path);

        $spreadsheet = IOFactory::load($path);

        return $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<int, mixed>>
     */
    protected function dataRows(array $filters, ?User $actor = null): array
    {
        $rows = $this->exportRows($filters, $actor);

        array_shift($rows);

        return array_values($rows);
    }

    // -------------------------------------------------------------- akses

    public function test_bendahara_may_export(): void
    {
        $this->assertTrue($this->bendaharaA->can('export', StudentFee::class));

        $this->assertIsArray($this->exportRows(['period' => '2026-08']));
    }

    /**
     * @return array<string, array{RoleName, bool}>
     */
    public static function roleExpectations(): array
    {
        return [
            'bendahara' => [RoleName::Bendahara, true],
            'admin sekolah' => [RoleName::SchoolAdmin, true],
            // Matriks "Laporan Keuangan": KEPALA = ⭕ — melihat, tidak
            // mengunduh; mereka hanya punya financial_report.view.
            'kepala sekolah' => [RoleName::KepalaSekolah, false],
            'guru' => [RoleName::Guru, false],
            'wali kelas' => [RoleName::WaliKelas, false],
        ];
    }

    #[DataProvider('roleExpectations')]
    public function test_the_export_ability_follows_the_financial_report_matrix(RoleName $role, bool $allowed): void
    {
        $user = User::factory()->forSchool($this->schoolA)->withRole($role)->create();

        $this->assertSame($allowed, $user->can('export', StudentFee::class));
    }

    public function test_super_admin_may_export(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->assertTrue($superAdmin->can('export', StudentFee::class));

        $rows = $this->dataRows(['period' => '2026-08', 'school_id' => $this->schoolA->id], $superAdmin);

        $this->assertIsArray($rows);
    }

    /**
     * Menyembunyikan tombol bukan proteksinya: layanannya menolak juga.
     *
     * @return array<string, array{RoleName}>
     */
    public static function rolesThatMayNotExport(): array
    {
        return [
            'kepala sekolah' => [RoleName::KepalaSekolah],
            'guru' => [RoleName::Guru],
            'wali kelas' => [RoleName::WaliKelas],
        ];
    }

    #[DataProvider('rolesThatMayNotExport')]
    public function test_unauthorized_roles_are_refused_by_the_service(RoleName $role): void
    {
        $user = User::factory()->forSchool($this->schoolA)->withRole($role)->create();

        $this->expectException(AuthorizationException::class);

        app(StudentFeeReportExporter::class)->download($user, ['period' => '2026-08']);
    }

    public function test_the_action_is_hidden_from_roles_that_may_not_export(): void
    {
        $this->actingAs(User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::KepalaSekolah)->create());

        Livewire::test(ListStudentFees::class)
            ->assertActionHidden('export');
    }

    public function test_the_action_is_visible_to_bendahara(): void
    {
        $this->actingAs($this->bendaharaA);

        Livewire::test(ListStudentFees::class)
            ->assertActionVisible('export');
    }

    // ---------------------------------------------------------------- isi

    public function test_the_header_is_exactly_the_seven_documented_columns(): void
    {
        $header = $this->exportRows(['period' => '2026-08'])[0] ?? [];

        $this->assertSame([
            'Nama Siswa',
            'Kelas',
            'Periode',
            'Jumlah Tagihan',
            'Jumlah Bayar',
            'Sisa',
            'Status',
        ], $header);
    }

    public function test_a_row_carries_the_documented_values(): void
    {
        $student = $this->studentIn($this->schoolA, 'Ahmad Fauzi');
        $class = $this->classIn($this->schoolA, '7A');
        $this->placeInClass($student, $class, $this->yearA);

        $this->fee($this->schoolA, $student, [
            'amount' => '1000000',
            'amount_paid' => '400000',
            'status' => StudentFeeStatus::Partial->value,
        ]);

        $rows = $this->dataRows(['period' => '2026-08']);

        $this->assertCount(1, $rows);
        $this->assertSame('Ahmad Fauzi', $rows[0][0]);
        $this->assertSame('7A', $rows[0][1]);
        $this->assertSame('2026-08', $rows[0][2]);
        $this->assertEqualsWithDelta(1000000, $rows[0][3], 0.001);
        $this->assertEqualsWithDelta(400000, $rows[0][4], 0.001);
        $this->assertEqualsWithDelta(600000, $rows[0][5], 0.001);
        $this->assertSame(StudentFeeStatus::Partial->label(), $rows[0][6]);
    }

    /**
     * Sisa memakai helper domain yang sama dengan layar, bukan rumus kedua.
     */
    public function test_the_remaining_column_matches_the_model_helper(): void
    {
        $student = $this->studentIn($this->schoolA, 'Budi');
        $fee = $this->fee($this->schoolA, $student, ['amount' => '0.30', 'amount_paid' => '0.10']);

        $rows = $this->dataRows(['period' => '2026-08']);

        $this->assertSame('0.20', $fee->fresh()->remaining());
        $this->assertEqualsWithDelta(0.20, $rows[0][5], 0.0001);
    }

    /**
     * Nominal tetap sel angka supaya dapat dijumlahkan dan diurutkan di Excel;
     * rupiahnya hanya format tampilan.
     */
    public function test_money_columns_stay_numeric(): void
    {
        $student = $this->studentIn($this->schoolA, 'Citra');
        $this->fee($this->schoolA, $student, ['amount' => '1500000', 'amount_paid' => '250000']);

        $rows = $this->dataRows(['period' => '2026-08']);

        foreach ([3, 4, 5] as $column) {
            $this->assertIsNumeric($rows[0][$column]);
            $this->assertIsNotString($rows[0][$column]);
        }

        $formats = (new StudentFeesExport(StudentFee::query()))->columnFormats();

        $this->assertSame(['D', 'E', 'F'], array_keys($formats));
    }

    // ------------------------------------------------------------- filter

    public function test_the_period_filter_narrows_the_report(): void
    {
        $student = $this->studentIn($this->schoolA, 'Dewi');

        $this->fee($this->schoolA, $student, ['period' => '2026-07']);
        $this->fee($this->schoolA, $student, ['period' => '2026-08']);

        $this->assertCount(1, $this->dataRows(['period' => '2026-07']));
        $this->assertCount(1, $this->dataRows(['period' => '2026-08']));
        $this->assertSame('2026-07', $this->dataRows(['period' => '2026-07'])[0][2]);
    }

    public function test_the_status_filter_narrows_the_report(): void
    {
        $student = $this->studentIn($this->schoolA, 'Eka');

        $this->fee($this->schoolA, $student, ['status' => StudentFeeStatus::Unpaid->value]);
        $this->fee($this->schoolA, $student, ['status' => StudentFeeStatus::Paid->value, 'amount_paid' => '1000000']);

        $rows = $this->dataRows(['period' => '2026-08', 'status' => StudentFeeStatus::Paid->value]);

        $this->assertCount(1, $rows);
        $this->assertSame(StudentFeeStatus::Paid->label(), $rows[0][6]);
    }

    public function test_the_class_filter_narrows_the_report(): void
    {
        $classA = $this->classIn($this->schoolA, '7A');
        $classB = $this->classIn($this->schoolA, '7B');

        $inA = $this->studentIn($this->schoolA, 'Siswa A');
        $inB = $this->studentIn($this->schoolA, 'Siswa B');

        $this->placeInClass($inA, $classA, $this->yearA);
        $this->placeInClass($inB, $classB, $this->yearA);

        $this->fee($this->schoolA, $inA);
        $this->fee($this->schoolA, $inB);

        $rows = $this->dataRows(['period' => '2026-08', 'class_id' => $classA->id]);

        $this->assertCount(1, $rows);
        $this->assertSame('Siswa A', $rows[0][0]);
        $this->assertSame('7A', $rows[0][1]);
    }

    public function test_all_three_filters_combine(): void
    {
        $classA = $this->classIn($this->schoolA, '7A');
        $student = $this->studentIn($this->schoolA, 'Fajar');
        $this->placeInClass($student, $classA, $this->yearA);

        $this->fee($this->schoolA, $student, ['period' => '2026-08', 'status' => StudentFeeStatus::Paid->value, 'amount_paid' => '1000000']);
        $this->fee($this->schoolA, $student, ['period' => '2026-08', 'status' => StudentFeeStatus::Unpaid->value]);
        $this->fee($this->schoolA, $student, ['period' => '2026-09', 'status' => StudentFeeStatus::Paid->value, 'amount_paid' => '1000000']);

        $rows = $this->dataRows([
            'period' => '2026-08',
            'class_id' => $classA->id,
            'status' => StudentFeeStatus::Paid->value,
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(StudentFeeStatus::Paid->label(), $rows[0][6]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPeriods(): array
    {
        return [
            'kosong' => [''],
            'bulan 13' => ['2026-13'],
            'bulan 00' => ['2026-00'],
            'tahun 2 digit' => ['26-08'],
            'teks' => ['agustus'],
        ];
    }

    #[DataProvider('invalidPeriods')]
    public function test_an_invalid_period_is_rejected(string $period): void
    {
        try {
            $this->exportRows(['period' => $period]);
            $this->fail("Periode {$period} seharusnya ditolak.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('period', $e->errors());
        }
    }

    public function test_a_missing_period_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->exportRows([]);
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        try {
            $this->exportRows(['period' => '2026-08', 'status' => 'LUNAS_SEBAGIAN']);
            $this->fail('Status tidak dikenal seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    public function test_a_blank_class_or_status_means_no_filter(): void
    {
        $student = $this->studentIn($this->schoolA, 'Gita');
        $this->fee($this->schoolA, $student);

        foreach ([null, ''] as $blank) {
            $rows = $this->dataRows(['period' => '2026-08', 'class_id' => $blank, 'status' => $blank]);

            $this->assertCount(1, $rows);
        }
    }

    // -------------------------------------------------------------- tenant

    public function test_another_branch_never_appears_in_the_report(): void
    {
        $mine = $this->studentIn($this->schoolA, 'Milik Saya');
        $theirs = $this->studentIn($this->schoolB, 'Cabang Lain');

        $this->fee($this->schoolA, $mine);
        $this->fee($this->schoolB, $theirs, ['academic_year_id' => null]);

        $rows = $this->dataRows(['period' => '2026-08']);

        $this->assertCount(1, $rows);
        $this->assertSame('Milik Saya', $rows[0][0]);
    }

    /**
     * Peran School Level tidak pernah melihat field cabang; apa pun yang muncul
     * di payload adalah selundupan.
     */
    public function test_a_crafted_school_id_cannot_move_the_report(): void
    {
        $mine = $this->studentIn($this->schoolA, 'Milik Saya');
        $theirs = $this->studentIn($this->schoolB, 'Cabang Lain');

        $this->fee($this->schoolA, $mine);
        $this->fee($this->schoolB, $theirs, ['academic_year_id' => null]);

        $rows = $this->dataRows(['period' => '2026-08', 'school_id' => $this->schoolB->id]);

        $this->assertCount(1, $rows);
        $this->assertSame('Milik Saya', $rows[0][0]);
    }

    /**
     * Kelas cabang lain menghasilkan laporan kosong, bukan data cabang itu.
     */
    public function test_a_class_from_another_branch_leaks_nothing(): void
    {
        $yearB = AcademicYear::factory()->create(['school_id' => $this->schoolB->id, 'name' => '2026/2027 Ganjil']);
        $foreignClass = SchoolClass::factory()->create([
            'school_id' => $this->schoolB->id,
            'name' => '7A',
            'academic_year_id' => $yearB->id,
        ]);

        $theirs = $this->studentIn($this->schoolB, 'Cabang Lain');
        $this->placeInClass($theirs, $foreignClass, $yearB);
        $this->fee($this->schoolB, $theirs, ['academic_year_id' => $yearB->id]);

        $mine = $this->studentIn($this->schoolA, 'Milik Saya');
        $this->fee($this->schoolA, $mine);

        $rows = $this->dataRows(['period' => '2026-08', 'class_id' => $foreignClass->id]);

        $this->assertSame([], $rows);
    }

    public function test_class_options_are_limited_to_the_report_branch(): void
    {
        $this->classIn($this->schoolA, '7A');

        $yearB = AcademicYear::factory()->create(['school_id' => $this->schoolB->id]);
        SchoolClass::factory()->create([
            'school_id' => $this->schoolB->id,
            'name' => 'Kelas Cabang Lain',
            'academic_year_id' => $yearB->id,
        ]);

        $options = app(StudentFeeReportExporter::class)->classOptions((int) $this->schoolA->id);

        $this->assertContains('7A', $options);
        $this->assertNotContains('Kelas Cabang Lain', $options);
        $this->assertSame([], app(StudentFeeReportExporter::class)->classOptions(null));
    }

    public function test_a_school_level_account_without_a_branch_cannot_export(): void
    {
        $orphan = User::factory()->withRole(RoleName::Bendahara)->create(['school_id' => null]);

        try {
            $this->exportRows(['period' => '2026-08'], $orphan);
            $this->fail('Akun tanpa cabang seharusnya tidak dapat mengekspor.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('school_id', $e->errors());
        }
    }

    public function test_super_admin_must_choose_a_branch(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        foreach ([null, '', 'bukan-angka', 999_999] as $value) {
            try {
                $this->exportRows(['period' => '2026-08', 'school_id' => $value], $superAdmin);
                $this->fail('Cabang tidak sah seharusnya ditolak.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('school_id', $e->errors());
            }
        }
    }

    public function test_super_admin_exports_one_branch_at_a_time(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->fee($this->schoolA, $this->studentIn($this->schoolA, 'Milik Alpha'));
        $this->fee($this->schoolB, $this->studentIn($this->schoolB, 'Milik Beta'), ['academic_year_id' => null]);

        $rows = $this->dataRows(['period' => '2026-08', 'school_id' => $this->schoolB->id], $superAdmin);

        $this->assertCount(1, $rows);
        $this->assertSame('Milik Beta', $rows[0][0]);
    }

    // ------------------------------------------------------ kelas historis

    /**
     * Siswa yang naik kelas tidak boleh membuat tagihan tahun lalu tertulis
     * dengan rombel tahun ini.
     */
    public function test_the_class_column_follows_the_academic_year_of_the_fee(): void
    {
        $lastYear = AcademicYear::factory()->create([
            'school_id' => $this->schoolA->id,
            'name' => '2025/2026 Genap',
        ]);

        $student = $this->studentIn($this->schoolA, 'Naik Kelas');
        $classSeven = $this->classIn($this->schoolA, '7A', $lastYear);
        $classEight = $this->classIn($this->schoolA, '8A');

        $this->placeInClass($student, $classSeven, $lastYear);
        $this->placeInClass($student, $classEight, $this->yearA);

        $this->fee($this->schoolA, $student, ['academic_year_id' => $lastYear->id, 'period' => '2026-05']);
        $this->fee($this->schoolA, $student, ['academic_year_id' => $this->yearA->id, 'period' => '2026-08']);

        $this->assertSame('7A', $this->dataRows(['period' => '2026-05'])[0][1]);
        $this->assertSame('8A', $this->dataRows(['period' => '2026-08'])[0][1]);
    }

    /**
     * Penempatan yang sudah MOVED tidak dipakai; yang dicetak penempatan ACTIVE
     * pada tahun ajaran itu.
     */
    public function test_a_moved_placement_is_not_used_for_the_class_column(): void
    {
        $student = $this->studentIn($this->schoolA, 'Pindah Rombel');
        $old = $this->classIn($this->schoolA, '7A');
        $new = $this->classIn($this->schoolA, '7B');

        $this->placeInClass($student, $old, $this->yearA, StudentClassStatus::Moved);
        $this->placeInClass($student, $new, $this->yearA);

        $this->fee($this->schoolA, $student);

        $this->assertSame('7B', $this->dataRows(['period' => '2026-08'])[0][1]);
    }

    /**
     * Tagihan berulang tanpa tahun ajaran (ERD membolehkan NULL) tidak punya
     * tahun untuk dicocokkan; kelasnya dibiarkan kosong alih-alih ditebak.
     */
    public function test_a_fee_without_an_academic_year_leaves_the_class_blank(): void
    {
        $student = $this->studentIn($this->schoolA, 'Tanpa Tahun Ajaran');
        $this->placeInClass($student, $this->classIn($this->schoolA, '7A'), $this->yearA);

        $this->fee($this->schoolA, $student, ['academic_year_id' => null]);

        $this->assertNull($this->dataRows(['period' => '2026-08'])[0][1]);
    }

    public function test_a_student_without_any_placement_leaves_the_class_blank(): void
    {
        $student = $this->studentIn($this->schoolA, 'Belum Ditempatkan');

        $this->fee($this->schoolA, $student);

        $this->assertNull($this->dataRows(['period' => '2026-08'])[0][1]);
    }

    // -------------------------------------------------------------- WAIVED

    public function test_a_waived_fee_is_exported_with_its_historical_amounts(): void
    {
        $student = $this->studentIn($this->schoolA, 'Dibebaskan');
        $this->placeInClass($student, $this->classIn($this->schoolA, '9C'), $this->yearA);

        $this->fee($this->schoolA, $student, [
            'amount' => '750000',
            'amount_paid' => '0',
            'status' => StudentFeeStatus::Waived->value,
            'waive_reason' => 'Beasiswa penuh',
        ]);

        $rows = $this->dataRows(['period' => '2026-08', 'status' => StudentFeeStatus::Waived->value]);

        $this->assertCount(1, $rows);
        $this->assertSame('9C', $rows[0][1]);
        $this->assertEqualsWithDelta(750000, $rows[0][3], 0.001);
        $this->assertEqualsWithDelta(0, $rows[0][4], 0.001);
        $this->assertEqualsWithDelta(750000, $rows[0][5], 0.001);
        $this->assertSame(StudentFeeStatus::Waived->label(), $rows[0][6]);
    }

    public function test_exporting_never_changes_the_underlying_records(): void
    {
        $student = $this->studentIn($this->schoolA, 'Utuh');
        $fee = $this->fee($this->schoolA, $student, [
            'amount' => '750000',
            'status' => StudentFeeStatus::Waived->value,
            'waive_reason' => 'Beasiswa',
        ]);

        $this->dataRows(['period' => '2026-08']);

        $fresh = $fee->fresh();

        $this->assertSame(StudentFeeStatus::Waived, $fresh->status);
        $this->assertSame('750000.00', (string) $fresh->amount);
        $this->assertSame('Beasiswa', $fresh->waive_reason);
    }

    // ---------------------------------------------------------- nama berkas

    public function test_the_file_name_follows_the_sis_05_convention(): void
    {
        $name = app(StudentFeeReportExporter::class)
            ->fileName(['school_id' => $this->schoolA->id, 'period' => '2026-08']);

        $this->assertSame('tagihan_alpha01_2026-08.xlsx', $name);
    }

    public function test_the_file_name_carries_no_unsafe_characters(): void
    {
        $messy = School::factory()->create(['code' => 'A B/C:01']);

        $name = app(StudentFeeReportExporter::class)
            ->fileName(['school_id' => $messy->id, 'period' => '2026-08']);

        $this->assertMatchesRegularExpression('/^tagihan_[a-z0-9\-]+_\d{4}-\d{2}\.xlsx$/', $name);
        $this->assertStringNotContainsString('/', $name);
        $this->assertStringNotContainsString(':', $name);
        $this->assertStringNotContainsString(' ', $name);
    }

    // --------------------------------------------------------- performance

    /**
     * Relasi dimuat sekali untuk seluruh hasil, bukan sekali per baris.
     */
    public function test_the_query_count_does_not_grow_with_the_number_of_rows(): void
    {
        $class = $this->classIn($this->schoolA, '7A');

        $makeFees = function (int $count) use ($class): void {
            for ($i = 0; $i < $count; $i++) {
                $student = $this->studentIn($this->schoolA, "Siswa {$i}");
                $this->placeInClass($student, $class, $this->yearA);
                $this->fee($this->schoolA, $student);
            }
        };

        $makeFees(1);

        // Pemanasan: izin Spatie dimuat lazy sekali saja.
        $this->dataRows(['period' => '2026-08']);

        DB::enableQueryLog();
        $this->dataRows(['period' => '2026-08']);
        $withOneRow = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        $makeFees(19);

        DB::enableQueryLog();
        $rows = $this->dataRows(['period' => '2026-08']);
        $withTwentyRows = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(20, $rows);
        $this->assertSame(
            $withOneRow,
            $withTwentyRows,
            'Jumlah query export harus konstan terhadap jumlah baris.',
        );
    }

    public function test_every_row_still_resolves_its_class_without_extra_queries(): void
    {
        $class = $this->classIn($this->schoolA, '7A');

        for ($i = 0; $i < 5; $i++) {
            $student = $this->studentIn($this->schoolA, "Siswa {$i}");
            $this->placeInClass($student, $class, $this->yearA);
            $this->fee($this->schoolA, $student);
        }

        $rows = $this->dataRows(['period' => '2026-08']);

        $this->assertCount(5, $rows);

        foreach ($rows as $row) {
            $this->assertSame('7A', $row[1]);
        }
    }

    // -------------------------------------------------------------- batasan

    public function test_no_schema_was_added_for_the_report(): void
    {
        foreach (['student_fee_reports', 'fee_exports'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }

        $this->assertFalse(Schema::hasColumn('student_fees', 'class_id'));
    }
}
