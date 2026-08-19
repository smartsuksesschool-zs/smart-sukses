<?php

namespace Tests\Feature\Grading;

use App\Enums\AssessmentType;
use App\Enums\GradeType;
use App\Enums\RoleName;
use App\Exports\GradeTemplateExport;
use App\Filament\Resources\GradeResource\Pages\ListGrades;
use App\Imports\GradesImport;
use App\Models\ClassSubject;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * NILAI-01 poin 2 — "Input dapat dilakukan satu per satu atau melalui import
 * Excel"; API 4.8 `POST /grades/import` — "Import nilai dari Excel template".
 *
 * Pelengkap GradeImportTest, yang memanggil importer atas baris yang sudah
 * terbaca. Di sini berkas .xlsx-nya dibuat sungguhan lalu dibaca lewat
 * `Excel::import()`, tanpa `Excel::fake()`. Yang hanya dapat dibuktikan di sini:
 *
 *   - heading `nis` dan `nilai` benar-benar terpetakan oleh WithHeadingRow;
 *   - NIS yang seluruhnya angka — bentuk yang dihasilkan StudentFactory dan
 *     lazim di sekolah — ditulis Excel sebagai sel *angka*, bukan teks, dan
 *     tetap cocok dengan kolom `students.nis` yang bertipe string;
 *   - template yang diunduh client memang diterima importer.
 */
class GradeImportXlsxTest extends GradingTestCase
{
    /** @var array<int, string> */
    protected array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->teacher);
    }

    protected function tearDown(): void
    {
        File::delete($this->tempFiles);

        $this->tempFiles = [];

        parent::tearDown();
    }

    public function test_a_real_xlsx_is_read_through_its_heading_row(): void
    {
        $this->activeConfig();

        $path = $this->xlsxWith([
            [$this->student->nis, 80],
        ]);

        $import = new GradesImport(
            $this->classSubject,
            GradeType::Daily,
            AssessmentType::Summative,
            'Ulangan Harian Bab 3',
        );

        Excel::import($import, $path);

        $this->assertSame(1, $import->imported);
        $this->assertSame([], $import->errors);

        $grade = Grade::query()->firstOrFail();

        $this->assertSame($this->student->id, $grade->student_id);
        $this->assertSame($this->classSubject->id, $grade->class_subject_id);
        $this->assertSame(GradeType::Daily, $grade->grade_type);
        $this->assertSame(AssessmentType::Summative, $grade->assessment_type);
        $this->assertSame('80.00', $grade->score);
        $this->assertSame('Ulangan Harian Bab 3', $grade->description);
    }

    public function test_each_score_lands_on_the_student_named_by_its_nis(): void
    {
        $this->activeConfig();

        $classmate = $this->classmate('654321');

        // Sengaja tidak berurutan dengan urutan pembuatan siswa: yang menentukan
        // nilai jatuh ke siapa adalah NIS di berkas, bukan posisi barisnya.
        $path = $this->xlsxWith([
            [$classmate->nis, 95],
            [$this->student->nis, 70],
        ]);

        $import = new GradesImport($this->classSubject, GradeType::Daily, AssessmentType::Summative);

        Excel::import($import, $path);

        $this->assertSame(2, $import->imported);

        $this->assertSame(
            '70.00',
            Grade::query()->where('student_id', $this->student->id)->firstOrFail()->score,
        );

        $this->assertSame(
            '95.00',
            Grade::query()->where('student_id', $classmate->id)->firstOrFail()->score,
        );
    }

    public function test_rows_from_a_real_file_receive_the_weight_snapshot(): void
    {
        // Keputusan butir 2 berlaku sama untuk setiap jalur input. Importer
        // menulis lewat model, jadi hook snapshot ikut jalan — yang dibuktikan
        // di sini adalah bahwa jalur berkas nyata tidak melewatkannya.
        $config = $this->activeConfig();

        $path = $this->xlsxWith([[$this->student->nis, 80]]);

        Excel::import(
            new GradesImport($this->classSubject, GradeType::Daily, AssessmentType::Summative),
            $path,
        );

        $grade = Grade::query()->firstOrFail();

        $this->assertSame('0.40', $grade->weight);
        $this->assertSame($config->id, $grade->grade_config_id);
        $this->assertSame($this->teacher->id, $grade->graded_by);
        $this->assertSame($this->school->id, $grade->school_id);
    }

    public function test_a_nis_outside_the_class_is_reported_with_its_line_number(): void
    {
        $this->activeConfig();

        $outsider = Student::factory()->create([
            'school_id' => $this->school->id,
            'nis' => '111222',
        ]);

        $path = $this->xlsxWith([
            [$this->student->nis, 80],
            [$outsider->nis, 90],
        ]);

        $import = new GradesImport($this->classSubject, GradeType::Daily, AssessmentType::Summative);

        Excel::import($import, $path);

        $this->assertSame(1, $import->imported);
        $this->assertCount(1, $import->errors);

        // Baris 1 berkas adalah heading, jadi baris data kedua adalah baris 3 —
        // penomoran yang hanya benar-benar teruji terhadap berkas sungguhan.
        $this->assertStringContainsString('Baris 3', $import->errors[0]);
        $this->assertStringContainsString('111222', $import->errors[0]);
        $this->assertSame(1, Grade::query()->count());
    }

    public function test_a_nis_of_another_school_is_not_matched_from_a_real_file(): void
    {
        $this->activeConfig();

        $foreign = Student::factory()->create(['nis' => '999888']);

        $path = $this->xlsxWith([[$foreign->nis, 90]]);

        $import = new GradesImport($this->classSubject, GradeType::Daily, AssessmentType::Summative);

        Excel::import($import, $path);

        $this->assertSame(0, $import->imported);
        $this->assertCount(1, $import->errors);
        $this->assertSame(0, Grade::query()->count());
    }

    public function test_the_downloaded_template_is_accepted_by_the_importer(): void
    {
        // Berkas yang diunduh client dan berkas yang dibaca importer tidak boleh
        // menyimpang: template ini diisi lalu diimpor kembali apa adanya.
        $this->activeConfig();

        Storage::fake('local');

        Excel::store(new GradeTemplateExport, 'template_nilai.xlsx', 'local');

        $path = Storage::disk('local')->path('template_nilai.xlsx');

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('nis', $sheet->getCell('A1')->getValue());
        $this->assertSame('nilai', $sheet->getCell('B1')->getValue());

        // Template sengaja tidak membawa baris contoh: baris apa pun di bawah
        // heading akan ikut terbaca sebagai data.
        $this->assertNull($sheet->getCell('A2')->getValue());

        $sheet->fromArray([[$this->student->nis, 88]], null, 'A2');

        (new XlsxWriter($spreadsheet))->save($path);

        $import = new GradesImport($this->classSubject, GradeType::Daily, AssessmentType::Summative);

        Excel::import($import, $path);

        $this->assertSame(1, $import->imported);
        $this->assertSame([], $import->errors);
        $this->assertSame('88.00', Grade::query()->firstOrFail()->score);
    }

    public function test_the_page_imports_a_real_file_and_removes_it_afterwards(): void
    {
        $this->activeConfig();

        Storage::fake('local');

        $uploaded = $this->uploadedXlsx([[$this->student->nis, 75]]);

        Livewire::test(ListGrades::class)
            ->callAction('import', [
                'class_subject_id' => $this->classSubject->id,
                'grade_type' => GradeType::Daily->value,
                'assessment_type' => AssessmentType::Summative->value,
                'file' => [$uploaded],
            ]);

        $this->assertSame(1, Grade::query()->count());
        $this->assertSame('75.00', Grade::query()->firstOrFail()->score);
        $this->assertSame('1 nilai berhasil diimport', $this->lastNotification()['title'] ?? null);

        // Berkas unggahan hanya perantara.
        Storage::disk('local')->assertMissing($uploaded);
    }

    public function test_the_uploaded_file_is_removed_even_when_it_cannot_be_read(): void
    {
        $this->activeConfig();

        Storage::fake('local');

        // Berkas ber-ekstensi .xlsx yang isinya bukan spreadsheet: persis yang
        // terjadi bila guru mengunggah berkas rusak atau salah simpan.
        $uploaded = 'imports/rusak.xlsx';
        Storage::disk('local')->put($uploaded, 'ini bukan spreadsheet');

        $failed = false;

        try {
            Livewire::test(ListGrades::class)
                ->callAction('import', [
                    'class_subject_id' => $this->classSubject->id,
                    'grade_type' => GradeType::Daily->value,
                    'assessment_type' => AssessmentType::Summative->value,
                    'file' => [$uploaded],
                ]);
        } catch (\Throwable) {
            $failed = true;
        }

        $this->assertTrue($failed, 'Berkas yang tidak dapat dibaca seharusnya menggagalkan import.');
        $this->assertSame(0, Grade::query()->count());

        // Yang dijaga di sini hanya kebersihan storage; penanganan galatnya
        // sendiri tetap seperti sebelumnya.
        Storage::disk('local')->assertMissing($uploaded);
    }

    public function test_the_uploaded_file_is_removed_when_the_payload_is_refused(): void
    {
        // Kelas-mapel yang ditolak policy keluar sebelum berkasnya dibaca —
        // berkasnya tetap tidak boleh tertinggal.
        $this->activeConfig();

        $colleague = User::factory()->forSchool($this->school)->withRole(RoleName::Guru)->create();

        $otherClass = SchoolClass::factory()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->year->id,
            'homeroom_teacher_id' => $colleague->id,
        ]);

        $notMine = ClassSubject::factory()->create([
            'school_id' => $this->school->id,
            'class_id' => $otherClass->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $colleague->id,
            'academic_year_id' => $this->year->id,
        ]);

        Storage::fake('local');

        $uploaded = $this->uploadedXlsx([[$this->student->nis, 80]]);

        Livewire::test(ListGrades::class)
            ->callAction('import', [
                'class_subject_id' => $notMine->id,
                'grade_type' => GradeType::Daily->value,
                'assessment_type' => AssessmentType::Summative->value,
                'file' => [$uploaded],
            ]);

        $this->assertSame('Tidak diizinkan', $this->lastNotification()['title'] ?? null);
        $this->assertSame(0, Grade::query()->count());

        Storage::disk('local')->assertMissing($uploaded);
    }

    public function test_a_component_outside_the_grade_config_is_warned_after_a_real_import(): void
    {
        // C-6 lewat jalur berkas nyata: konfigurasi hanya memuat Harian/UTS/UAS,
        // sedangkan yang diimpor adalah SKILL sumatif.
        $this->activeConfig();

        Storage::fake('local');

        $uploaded = $this->uploadedXlsx([[$this->student->nis, 82]]);

        Livewire::test(ListGrades::class)
            ->callAction('import', [
                'class_subject_id' => $this->classSubject->id,
                'grade_type' => GradeType::Skill->value,
                'assessment_type' => AssessmentType::Summative->value,
                'file' => [$uploaded],
            ]);

        $this->assertSame(1, Grade::query()->count());

        $notification = $this->lastNotification();

        $this->assertSame('Komponen ini tidak masuk nilai akhir', $notification['title'] ?? null);
        $this->assertStringContainsString(
            GradeType::Skill->label(),
            (string) ($notification['body'] ?? ''),
        );
    }

    /**
     * Siswa lain di kelas yang sama.
     */
    protected function classmate(string $nis): Student
    {
        $classmate = Student::factory()->create([
            'school_id' => $this->school->id,
            'nis' => $nis,
        ]);

        StudentClass::factory()->create([
            'school_id' => $this->school->id,
            'student_id' => $classmate->id,
            'class_id' => $this->class->id,
            'academic_year_id' => $this->year->id,
        ]);

        return $classmate;
    }

    /**
     * Berkas .xlsx sungguhan berisi heading yang dipakai importer dan baris
     * data yang diberikan. NIS ditulis apa adanya, sehingga NIS yang seluruhnya
     * angka menjadi sel angka — persis seperti berkas buatan Excel.
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    protected function xlsxWith(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->fromArray([['nis', 'nilai']], null, 'A1');

        if ($rows !== []) {
            $sheet->fromArray($rows, null, 'A2');
        }

        $path = $this->tempPath('nilai-'.count($this->tempFiles).'.xlsx');

        (new XlsxWriter($spreadsheet))->save($path);

        return $path;
    }

    /**
     * Berkas .xlsx sungguhan yang sudah berada di disk `local`, seperti hasil
     * unggahan FileUpload.
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    protected function uploadedXlsx(array $rows): string
    {
        $path = 'imports/nilai.xlsx';

        Storage::disk('local')->put($path, File::get($this->xlsxWith($rows)));

        return $path;
    }

    protected function tempPath(string $name): string
    {
        $directory = storage_path('framework/testing/xlsx');

        File::ensureDirectoryExists($directory);

        $path = $directory.DIRECTORY_SEPARATOR.$name;

        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * Notifikasi Filament terakhir; sesinya dibaca langsung, seperti pada
     * pengujian rapor.
     *
     * @return array<string, mixed>
     */
    protected function lastNotification(): array
    {
        return (array) (collect(session('filament.notifications', []))->last() ?? []);
    }
}
