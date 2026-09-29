<?php

namespace Tests\Feature\MasterData;

use App\Enums\FeeFrequency;
use App\Enums\RoleName;
use App\Enums\StudentClassStatus;
use App\Exports\StudentsExport;
use App\Jobs\GenerateStudentFees;
use App\Models\AcademicYear;
use App\Models\FeeType;
use App\Models\ReportCard;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentFee;
use App\Models\User;
use App\Services\Finance\StudentFeeGenerator;
use App\Services\Grading\ReportCardGenerator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresi arsip siswa pada permukaan yang **tidak dijalankan orang lewat layar**.
 *
 * `StudentArchiveTest` sudah menjaga panel, roster, NIS, foto, dan wewenang.
 * Yang dijaga di sini tiga hal yang kegagalannya tidak terlihat siapa pun pada
 * saat terjadi: job antrean yang mati diam-diam, penerbitan rapor yang pecah di
 * tengah kelas, dan ekspor yang membocorkan baris terarsip ke berkas yang
 * dibagikan (butir 592).
 */
class StudentArchiveRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected AcademicYear $year;

    protected SchoolClass $class;

    protected Student $aktif;

    protected Student $terarsip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create(['code' => 'PUSAT']);
        $this->year = AcademicYear::factory()->create([
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $this->class = SchoolClass::factory()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->year->id,
        ]);

        $this->aktif = $this->enrolled('AKTIF-01', 'Siswa Aktif');
        $this->terarsip = $this->enrolled('ARSIP-01', 'Siswa Terarsip');
        $this->terarsip->delete();
    }

    protected function enrolled(string $nis, string $name): Student
    {
        $student = Student::factory()->create([
            'school_id' => $this->school->id,
            'nis' => $nis,
            'full_name' => $name,
        ]);

        StudentClass::create([
            'school_id' => $this->school->id,
            'student_id' => $student->id,
            'class_id' => $this->class->id,
            'academic_year_id' => $this->year->id,
            'status' => StudentClassStatus::Active->value,
        ]);

        return $student;
    }

    /**
     * Job antrean berjalan sampai selesai, dan tidak menagih siswa terarsip.
     *
     * Kegagalan di dalam worker tidak terlihat siapa pun pada saat terjadi: ia
     * hanya menjadi satu baris di `failed_jobs` yang mungkin tidak dibaca
     * berhari-hari, sementara tagihan sekelas tidak pernah terbit.
     */
    public function test_job_tagihan_massal_tidak_rusak_oleh_siswa_terarsip(): void
    {
        $feeType = FeeType::factory()->create([
            'school_id' => $this->school->id,
            'frequency' => FeeFrequency::Monthly->value,
        ]);

        (new GenerateStudentFees(
            $this->school->id,
            (int) $feeType->getKey(),
            '2026-09',
            '2026-09-10',
        ))->handle(app(StudentFeeGenerator::class));

        $tertagih = StudentFee::query()->pluck('student_id')->all();

        $this->assertContains($this->aktif->id, $tertagih);
        $this->assertNotContains($this->terarsip->id, $tertagih);
        $this->assertCount(1, $tertagih);
    }

    /**
     * Penerbitan rapor sekelas tidak pecah karena satu siswa diarsipkan.
     *
     * Bentuk kegagalan yang dijaga: satu siswa terarsip membuat seluruh
     * penerbitan kelas berhenti, sehingga rapor 29 siswa lain ikut tertahan.
     */
    public function test_pembuatan_rapor_sekelas_tidak_pecah_oleh_siswa_terarsip(): void
    {
        $siswa = app(ReportCardGenerator::class)->studentsOf($this->class);

        $this->assertTrue($siswa->contains('id', $this->aktif->id));
        $this->assertFalse($siswa->contains('id', $this->terarsip->id));

        // Tidak melempar apa pun, dan hanya siswa aktif yang memperoleh rapor.
        $hasil = app(ReportCardGenerator::class)->generateForClass($this->class);

        $this->assertIsArray($hasil);
        $this->assertSame(
            0,
            ReportCard::query()->where('student_id', $this->terarsip->id)->count(),
        );
    }

    /**
     * Ekspor Excel tidak memuat siswa terarsip.
     *
     * Berkas ekspor berpindah tangan — ke yayasan, ke dinas — dan baris siswa
     * yang sudah diarsipkan di dalamnya adalah kebocoran yang sulit ditarik
     * kembali setelah berkasnya terkirim.
     */
    public function test_ekspor_excel_tidak_memuat_siswa_terarsip(): void
    {
        $this->actingAs(
            User::factory()->forSchool($this->school)->withRole(RoleName::SchoolAdmin)->create()
        );

        $baris = (new StudentsExport(Student::query()))->query()->get();

        $this->assertTrue($baris->contains('nis', 'AKTIF-01'));
        $this->assertFalse($baris->contains('nis', 'ARSIP-01'));
    }

    /**
     * Pagar arah sebaliknya: baris terarsip memang masih ada secara fisik.
     *
     * Tanpa ini, seluruh tes di atas dapat lulus hanya karena barisnya terhapus
     * betulan — yang justru dilarang CON-45.
     */
    public function test_baris_terarsip_tetap_ada_secara_fisik(): void
    {
        $this->assertTrue(Student::withTrashed()->where('nis', 'ARSIP-01')->exists());
        $this->assertNotNull(Student::withTrashed()->where('nis', 'ARSIP-01')->first()->deleted_at);
    }
}
