<?php

namespace Tests\Feature\Grading;

use App\Enums\AssessmentType;
use App\Enums\GradeType;
use App\Enums\RoleName;
use App\Enums\StudentStatus;
use App\Filament\Resources\GradeResource\Pages\ListGrades;
use App\Imports\GradesImport;
use App\Models\ClassSubject;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Services\Grading\ComponentScoreAggregator;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

/**
 * NILAI-01 poin 2 — "Input dapat dilakukan satu per satu atau melalui import
 * Excel"; API 4.8 `POST /grades/import`.
 *
 * Pelaporannya mengikuti pola API 4.4 "Return: sukses + daftar error baris",
 * sama seperti import siswa Sprint 2 (butir 11).
 */
class GradeImportTest extends GradingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->teacher);
    }

    /**
     * Menjalankan importer langsung atas baris-baris yang sudah dibaca, tanpa
     * bergantung pada pembacaan berkas .xlsx.
     *
     * @param  array<int, array{nis?: mixed, nilai?: mixed}>  $rows
     */
    protected function import(
        array $rows,
        ?GradeType $type = null,
        ?AssessmentType $assessment = null,
        ?ClassSubject $classSubject = null,
        ?string $description = null,
    ): GradesImport {
        $import = new GradesImport(
            $classSubject ?? $this->classSubject,
            $type ?? GradeType::Daily,
            $assessment ?? AssessmentType::Summative,
            $description,
        );

        $import->collection(collect($rows)->map(fn (array $row) => collect($row)));

        return $import;
    }

    public function test_every_valid_row_becomes_a_grade(): void
    {
        $this->activeConfig();

        $import = $this->import([
            ['nis' => $this->student->nis, 'nilai' => 80],
        ]);

        $this->assertSame(1, $import->imported);
        $this->assertSame([], $import->errors);

        $grade = Grade::query()->firstOrFail();

        $this->assertSame($this->student->id, $grade->student_id);
        $this->assertSame($this->classSubject->id, $grade->class_subject_id);
        $this->assertSame(GradeType::Daily, $grade->grade_type);
        $this->assertSame(AssessmentType::Summative, $grade->assessment_type);
        $this->assertSame('80.00', $grade->score);
    }

    public function test_imported_grades_receive_the_weight_snapshot(): void
    {
        // Keputusan butir 2 berlaku sama untuk setiap jalur input; importer
        // menulis lewat model, bukan query builder.
        $config = $this->activeConfig();

        $this->import([['nis' => $this->student->nis, 'nilai' => 80]]);

        $grade = Grade::query()->firstOrFail();

        $this->assertSame('0.40', $grade->weight);
        $this->assertSame($config->id, $grade->grade_config_id);
        $this->assertSame($this->teacher->id, $grade->graded_by);
    }

    public function test_the_context_of_the_form_is_applied_to_every_row(): void
    {
        // Berkas hanya membawa nis & nilai; komponen dan jenis penilaian
        // datang dari form, mengikuti bentuk POST /grades/bulk.
        $this->activeConfig();

        $this->import(
            [['nis' => $this->student->nis, 'nilai' => 70]],
            GradeType::Midterm,
            AssessmentType::Formative,
            description: 'UTS Susulan',
        );

        $grade = Grade::query()->firstOrFail();

        $this->assertSame(GradeType::Midterm, $grade->grade_type);
        $this->assertSame(AssessmentType::Formative, $grade->assessment_type);
        $this->assertSame('UTS Susulan', $grade->description);
    }

    public function test_a_score_outside_zero_to_one_hundred_is_reported_per_row(): void
    {
        $this->activeConfig();

        $import = $this->import([
            ['nis' => $this->student->nis, 'nilai' => 80],
            ['nis' => $this->student->nis, 'nilai' => 120],
            ['nis' => $this->student->nis, 'nilai' => -5],
        ]);

        // Baris yang sah tetap masuk; yang gagal dilaporkan per nomor baris.
        $this->assertSame(1, $import->imported);
        $this->assertCount(2, $import->errors);
        $this->assertStringContainsString('Baris 3', $import->errors[0]);
        $this->assertStringContainsString('Baris 4', $import->errors[1]);
        $this->assertSame(1, Grade::query()->count());
    }

    public function test_a_row_without_a_score_is_reported_and_skipped(): void
    {
        $this->activeConfig();

        $import = $this->import([
            ['nis' => $this->student->nis, 'nilai' => null],
        ]);

        $this->assertSame(0, $import->imported);
        $this->assertCount(1, $import->errors);
        $this->assertSame(0, Grade::query()->count());
    }

    public function test_a_completely_empty_row_is_not_an_error(): void
    {
        // Baris kosong di akhir berkas sering ikut terbaca PhpSpreadsheet.
        $this->activeConfig();

        $import = $this->import([
            ['nis' => $this->student->nis, 'nilai' => 80],
            ['nis' => null, 'nilai' => null],
        ]);

        $this->assertSame(1, $import->imported);
        $this->assertSame([], $import->errors);
    }

    public function test_a_nis_outside_the_class_is_rejected(): void
    {
        // Siswa cabang yang sama, tetapi bukan anggota kelas ini.
        $outsider = Student::factory()->create([
            'school_id' => $this->school->id,
            'nis' => 'LUAR-001',
        ]);

        $this->activeConfig();

        $import = $this->import([
            ['nis' => $outsider->nis, 'nilai' => 90],
        ]);

        $this->assertSame(0, $import->imported);
        $this->assertStringContainsString('LUAR-001', $import->errors[0]);
        $this->assertSame(0, Grade::query()->count());
    }

    public function test_a_student_of_another_school_is_never_matched(): void
    {
        $foreign = Student::factory()->create(['nis' => 'ASING-001']);

        $this->activeConfig();

        $import = $this->import([
            ['nis' => $foreign->nis, 'nilai' => 90],
        ]);

        $this->assertSame(0, $import->imported);
        $this->assertSame(0, Grade::query()->count());
    }

    public function test_an_inactive_student_is_not_graded(): void
    {
        $this->student->update(['status' => StudentStatus::Graduated->value]);

        $this->activeConfig();

        $import = $this->import([
            ['nis' => $this->student->nis, 'nilai' => 80],
        ]);

        $this->assertSame(0, $import->imported);
        $this->assertSame(0, Grade::query()->count());
    }

    public function test_imported_daily_scores_average_like_any_other(): void
    {
        // Keputusan butir 1 tidak bergantung pada cara nilai masuk.
        $this->activeConfig();

        $this->import([
            ['nis' => $this->student->nis, 'nilai' => 80],
            ['nis' => $this->student->nis, 'nilai' => 90],
            ['nis' => $this->student->nis, 'nilai' => 70],
        ]);

        $this->assertSame(
            80.0,
            app(ComponentScoreAggregator::class)
                ->averageForType(Grade::query()->get(), GradeType::Daily),
        );
    }

    public function test_the_action_is_hidden_from_roles_without_grade_manage(): void
    {
        $siswa = User::factory()->forSchool($this->school)->withRole(RoleName::Siswa)->create();

        $this->assertTrue($this->teacher->can('import', Grade::class));
        $this->assertFalse($siswa->can('import', Grade::class));
    }

    public function test_the_template_can_be_downloaded_from_the_page(): void
    {
        // API 4.8 — "Import nilai dari Excel template". Isinya diuji terhadap
        // importer di GradeImportXlsxTest; yang diperiksa di sini hanya bahwa
        // tombolnya benar-benar menyerahkan berkas itu.
        Excel::fake();

        Livewire::test(ListGrades::class)
            ->callAction('template');

        Excel::assertDownloaded('template_nilai.xlsx');
    }

    public function test_the_template_is_gated_by_the_same_permission_as_the_import(): void
    {
        // Tombolnya memakai gate yang sama persis dengan Import Excel; peran
        // tanpa izin itu bahkan tidak dapat membuka halaman Nilai, jadi yang
        // diperiksa adalah gate-nya, seperti pada test di atas.
        $siswa = User::factory()->forSchool($this->school)->withRole(RoleName::Siswa)->create();

        Livewire::test(ListGrades::class)
            ->assertActionExists('template');

        $this->assertTrue($this->teacher->can('import', Grade::class));
        $this->assertFalse($siswa->can('import', Grade::class));
    }

    public function test_the_page_only_offers_class_subjects_taught_by_the_teacher(): void
    {
        // Wali kelas hanya boleh memegang satu kelas per tahun ajaran, jadi
        // kelas kedua ini diampu guru lain.
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

        Livewire::test(ListGrades::class)
            ->mountAction('import')
            ->assertFormFieldExists(
                'class_subject_id',
                'mountedActionForm',
                fn ($field) => array_key_exists($this->classSubject->id, $field->getOptions())
                    && ! array_key_exists($notMine->id, $field->getOptions()),
            );
    }

    public function test_importing_into_a_class_subject_of_another_teacher_is_refused(): void
    {
        // Wali kelas hanya boleh memegang satu kelas per tahun ajaran, jadi
        // kelas kedua ini diampu guru lain.
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

        $student = Student::factory()->create(['school_id' => $this->school->id, 'nis' => 'X-001']);
        StudentClass::factory()->create([
            'school_id' => $this->school->id,
            'student_id' => $student->id,
            'class_id' => $otherClass->id,
            'academic_year_id' => $this->year->id,
        ]);

        Storage::fake('local');
        Excel::fake();

        Livewire::test(ListGrades::class)
            ->callAction('import', [
                'class_subject_id' => $notMine->id,
                'grade_type' => GradeType::Daily->value,
                'assessment_type' => AssessmentType::Summative->value,
                // State FileUpload berupa array; berkasnya sendiri tidak pernah
                // dibaca karena penolakan terjadi lebih dulu.
                'file' => ['imports/whatever.xlsx'],
            ]);

        // API 4.8 — "Guru hanya bisa input nilai untuk kelas yang dia ampu".
        // Diperiksa lewat notifikasi, bukan lewat jumlah nilai: Excel::fake()
        // membuat pembacaan berkas tidak melakukan apa pun, sehingga tabel yang
        // kosong akan tetap kosong walau pagar otorisasinya dicabut.
        $notification = collect(session('filament.notifications', []))->last() ?? [];

        $this->assertSame('Tidak diizinkan', $notification['title'] ?? null);
        $this->assertSame(0, Grade::query()->count());
    }

    public function test_importing_into_an_owned_class_subject_is_allowed_through(): void
    {
        // Sisi lain dari test di atas: tanpa ini, "Tidak diizinkan" bisa saja
        // muncul untuk setiap import dan test di atas tetap hijau.
        Storage::fake('local');
        Excel::fake();

        Livewire::test(ListGrades::class)
            ->callAction('import', [
                'class_subject_id' => $this->classSubject->id,
                'grade_type' => GradeType::Daily->value,
                'assessment_type' => AssessmentType::Summative->value,
                'file' => ['imports/whatever.xlsx'],
            ]);

        $notification = collect(session('filament.notifications', []))->last() ?? [];

        $this->assertSame('0 nilai berhasil diimport', $notification['title'] ?? null);
    }
}
