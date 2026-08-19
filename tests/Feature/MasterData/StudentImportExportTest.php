<?php

namespace Tests\Feature\MasterData;

use App\Enums\RoleName;
use App\Exports\StudentsExport;
use App\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Imports\StudentsImport;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * SIS-05 / API 4.5 — export & import data siswa.
 */
class StudentImportExportTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create(['code' => 'PUSAT']);
        $this->admin = User::factory()->forSchool($this->school)->withRole(RoleName::SchoolAdmin)->create();

        $this->actingAs($this->admin);
    }

    public function test_export_contains_a_row_per_student(): void
    {
        Student::factory()->count(3)->create(['school_id' => $this->school->id]);

        $export = new StudentsExport(Student::query());

        $this->assertCount(15, $export->headings());
        $this->assertSame(3, $export->query()->count());
    }

    public function test_export_row_is_mapped_to_documented_columns(): void
    {
        $student = Student::factory()->create([
            'school_id' => $this->school->id,
            'nis' => '2024010',
            'full_name' => 'Siti Aminah',
            'gender' => 'P',
        ]);

        $row = (new StudentsExport(Student::query()))->map($student);

        $this->assertSame('2024010', $row[0]);
        $this->assertSame('Siti Aminah', $row[2]);
        $this->assertSame('P', $row[3]);
    }

    public function test_valid_rows_are_imported(): void
    {
        $path = $this->makeSheet([
            ['nis', 'nisn', 'nama_lengkap', 'jenis_kelamin', 'tanggal_lahir', 'status'],
            ['3001', '1111111111', 'Budi Santoso', 'L', '2010-05-12', 'ACTIVE'],
            ['3002', '2222222222', 'Dewi Lestari', 'P', '2010-08-30', 'ACTIVE'],
        ]);

        $import = new StudentsImport($this->school->id);
        Excel::import($import, $path);

        $this->assertSame(2, $import->imported);
        $this->assertSame([], $import->errors);
        $this->assertDatabaseHas('students', [
            'nis' => '3001',
            'full_name' => 'Budi Santoso',
            'school_id' => $this->school->id,
        ]);
    }

    public function test_invalid_rows_are_reported_per_line_and_skipped(): void
    {
        Student::factory()->create(['school_id' => $this->school->id, 'nis' => '3010']);

        $path = $this->makeSheet([
            ['nis', 'nisn', 'nama_lengkap', 'jenis_kelamin', 'status'],
            ['3011', '3333333333', 'Siswa Benar', 'L', 'ACTIVE'],
            ['3012', '123', 'NISN Pendek', 'P', 'ACTIVE'],
            ['3010', '4444444444', 'NIS Duplikat', 'L', 'ACTIVE'],
            ['3013', '5555555555', 'Gender Salah', 'X', 'ACTIVE'],
        ]);

        $import = new StudentsImport($this->school->id);
        Excel::import($import, $path);

        $this->assertSame(1, $import->imported);
        $this->assertCount(3, $import->errors);

        // Nomor baris mengikuti baris di berkas (baris 1 = heading).
        $this->assertStringStartsWith('Baris 3:', $import->errors[0]);
        $this->assertStringStartsWith('Baris 4:', $import->errors[1]);
        $this->assertStringStartsWith('Baris 5:', $import->errors[2]);
    }

    public function test_import_is_scoped_to_the_given_school(): void
    {
        $other = School::factory()->create();

        $path = $this->makeSheet([
            ['nis', 'nama_lengkap', 'jenis_kelamin', 'status'],
            ['4001', 'Siswa Cabang Lain', 'L', 'ACTIVE'],
        ]);

        $import = new StudentsImport($other->id);
        Excel::import($import, $path);

        $this->assertDatabaseHas('students', ['nis' => '4001', 'school_id' => $other->id]);
        $this->assertSame(0, Student::query()->count());
    }

    public function test_the_page_removes_the_uploaded_file_after_a_successful_import(): void
    {
        Storage::fake('local');

        $uploaded = $this->uploadedSheet([
            ['nis', 'nama_lengkap', 'jenis_kelamin', 'status'],
            ['5001', 'Siswa Unggahan', 'L', 'ACTIVE'],
        ]);

        Livewire::test(ListStudents::class)
            ->callAction('import', ['file' => [$uploaded]]);

        $this->assertDatabaseHas('students', ['nis' => '5001', 'school_id' => $this->school->id]);

        // Berkas unggahan hanya perantara.
        Storage::disk('local')->assertMissing($uploaded);
    }

    public function test_the_uploaded_file_is_removed_even_when_it_cannot_be_read(): void
    {
        Storage::fake('local');

        // Berkas ber-ekstensi .xlsx yang isinya bukan spreadsheet: persis yang
        // terjadi bila admin mengunggah berkas rusak atau salah simpan.
        $uploaded = 'imports/rusak.xlsx';
        Storage::disk('local')->put($uploaded, 'ini bukan spreadsheet');

        $failed = false;

        try {
            Livewire::test(ListStudents::class)
                ->callAction('import', ['file' => [$uploaded]]);
        } catch (\Throwable) {
            $failed = true;
        }

        $this->assertTrue($failed, 'Berkas yang tidak dapat dibaca seharusnya menggagalkan import.');
        $this->assertSame(0, Student::query()->count());

        Storage::disk('local')->assertMissing($uploaded);
    }

    public function test_the_uploaded_file_is_removed_when_a_super_admin_is_turned_away(): void
    {
        // Super Admin tidak terikat cabang, sehingga import massal ditolak
        // sebelum berkasnya dibaca — berkasnya tetap tidak boleh tertinggal.
        //
        // Keluar dulu dari akun Admin Sekolah yang dipasang setUp: selama ia
        // masih aktif, BelongsToSchool mengisi `school_id` pengguna baru dengan
        // cabangnya, sehingga Super Admin-nya lahir terikat cabang.
        auth()->logout();

        $this->actingAs(User::factory()->superAdmin()->create());

        Storage::fake('local');

        $uploaded = $this->uploadedSheet([
            ['nis', 'nama_lengkap', 'jenis_kelamin', 'status'],
            ['5002', 'Tidak Akan Masuk', 'L', 'ACTIVE'],
        ]);

        Livewire::test(ListStudents::class)
            ->callAction('import', ['file' => [$uploaded]]);

        $this->assertSame(0, Student::query()->count());
        Storage::disk('local')->assertMissing($uploaded);
    }

    /**
     * Berkas .xlsx sungguhan yang sudah berada di disk `local`, seperti hasil
     * unggahan FileUpload.
     *
     * @param  array<int, array<int, string>>  $rows
     */
    protected function uploadedSheet(array $rows): string
    {
        $path = 'imports/siswa.xlsx';

        Storage::disk('local')->put($path, File::get($this->makeSheet($rows)));

        return $path;
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    protected function makeSheet(array $rows): string
    {
        $export = new class($rows) implements FromArray
        {
            /**
             * @param  array<int, array<int, string>>  $rows
             */
            public function __construct(protected array $rows) {}

            /**
             * @return array<int, array<int, string>>
             */
            public function array(): array
            {
                return $this->rows;
            }
        };

        Excel::store($export, 'import-test.xlsx', 'local');

        return Storage::disk('local')->path('import-test.xlsx');
    }
}
