<?php

namespace Tests\Feature\MasterData;

use App\Enums\RoleName;
use App\Filament\Resources\StudentResource;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\Subject;
use App\Models\User;
use App\Support\StudentPhoto;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Halaman detail siswa (SIS-01).
 *
 * Yang dijaga bukan tata letaknya melainkan pagarnya: halaman ini mengambil
 * record lewat `StudentResource::getEloquentQuery()`, sehingga SchoolScope dan
 * visibilitas kelas guru berlaku sama persis seperti di daftar siswa. Siswa
 * cabang lain karena itu **tidak ditemukan** — 404, bukan 403 — dan
 * keberadaannya pun tidak terbocorkan.
 *
 * Foto siswa tetap privat: yang dirender rute berwenang, bukan URL disk.
 *
 * Seluruh data di sini sintetis.
 */
class StudentViewPageTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create();
        $this->schoolB = School::factory()->create();
    }

    protected function adminOf(School $school): User
    {
        return User::factory()->forSchool($school)->withRole(RoleName::SchoolAdmin)->create();
    }

    protected function viewUrl(Student $student): string
    {
        return StudentResource::getUrl('view', ['record' => $student->getKey()]);
    }

    public function test_admin_sekolah_melihat_detail_siswa_cabangnya(): void
    {
        $student = Student::factory()->create([
            'school_id' => $this->schoolA->id,
            'nis' => 'ZZ-9001',
            'full_name' => 'Siswa Sintetis Satu',
        ]);

        $this->actingAs($this->adminOf($this->schoolA))
            ->get($this->viewUrl($student))
            ->assertSuccessful()
            ->assertSee('ZZ-9001')
            ->assertSee('Siswa Sintetis Satu');
    }

    public function test_admin_sekolah_lain_tidak_menemukan_siswa(): void
    {
        $student = Student::factory()->create([
            'school_id' => $this->schoolB->id,
            'nis' => 'ZZ-9002',
            'full_name' => 'Siswa Cabang Lain',
        ]);

        $response = $this->actingAs($this->adminOf($this->schoolA))
            ->get($this->viewUrl($student));

        $response->assertNotFound();
        $response->assertDontSee('Siswa Cabang Lain');
    }

    public function test_super_admin_melihat_siswa_cabang_mana_pun(): void
    {
        $student = Student::factory()->create([
            'school_id' => $this->schoolB->id,
            'nis' => 'ZZ-9003',
        ]);

        $superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create(['school_id' => null]);

        $this->actingAs($superAdmin)
            ->get($this->viewUrl($student))
            ->assertSuccessful()
            ->assertSee('ZZ-9003')
            // Cabangnya disebut justru karena Super Admin melihat banyak cabang.
            ->assertSee($this->schoolB->name);
    }

    public function test_guru_hanya_melihat_siswa_kelas_ajarnya(): void
    {
        $year = AcademicYear::factory()->create(['school_id' => $this->schoolA->id, 'is_active' => true]);
        $guru = User::factory()->forSchool($this->schoolA)->withRole(RoleName::Guru)->create();

        $class = SchoolClass::factory()->create([
            'school_id' => $this->schoolA->id,
            'academic_year_id' => $year->id,
            'homeroom_teacher_id' => $guru->id,
        ]);

        $diKelasnya = Student::factory()->create(['school_id' => $this->schoolA->id, 'nis' => 'ZZ-9004']);
        $diLuarKelas = Student::factory()->create(['school_id' => $this->schoolA->id, 'nis' => 'ZZ-9005']);

        StudentClass::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_id' => $diKelasnya->id,
            'class_id' => $class->id,
            'academic_year_id' => $year->id,
        ]);

        $this->actingAs($guru);

        $this->get($this->viewUrl($diKelasnya))->assertSuccessful();
        $this->get($this->viewUrl($diLuarKelas))->assertNotFound();
    }

    public function test_tamu_tidak_dapat_membuka_detail_siswa(): void
    {
        $student = Student::factory()->create(['school_id' => $this->schoolA->id]);

        $this->get($this->viewUrl($student))->assertRedirect();
    }

    public function test_foto_dirender_lewat_rute_berwenang_bukan_url_disk(): void
    {
        Storage::fake(StudentPhoto::DISK);

        $path = StudentPhoto::DIRECTORY.'/detail-sintetis.jpg';
        Storage::disk(StudentPhoto::DISK)->put($path, 'gambar-sintetis');

        $student = Student::factory()->create([
            'school_id' => $this->schoolA->id,
            'nis' => 'ZZ-9006',
            'photo_url' => $path,
        ]);

        $html = $this->actingAs($this->adminOf($this->schoolA))
            ->get($this->viewUrl($student))
            ->assertSuccessful()
            ->getContent();

        $this->assertStringContainsString("/admin/siswa/{$student->getKey()}/foto", $html);
        $this->assertStringNotContainsString('/storage/'.StudentPhoto::DIRECTORY, $html);
        // Jalur penyimpanannya sendiri tidak pernah menjadi bagian halaman.
        $this->assertStringNotContainsString('detail-sintetis.jpg', $html);
    }

    public function test_detail_tidak_membocorkan_kolom_sensitif(): void
    {
        $student = Student::factory()->create([
            'school_id' => $this->schoolA->id,
            'nis' => 'ZZ-9007',
            'user_id' => User::factory()->forSchool($this->schoolA)->withRole(RoleName::Siswa)->create()->id,
        ]);

        $html = $this->actingAs($this->adminOf($this->schoolA))
            ->get($this->viewUrl($student))
            ->assertSuccessful()
            ->getContent();

        foreach (['password', 'remember_token', 'provider_subject', 'api_token'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "Kolom {$needle} tidak boleh muncul di detail siswa.");
        }
    }

    public function test_relasi_akademik_tidak_ikut_terbuka_di_detail(): void
    {
        // Detail siswa bukan tempat nilai atau tagihan; keduanya punya modul
        // sendiri dengan policy-nya sendiri.
        $year = AcademicYear::factory()->create(['school_id' => $this->schoolA->id, 'is_active' => true]);
        $class = SchoolClass::factory()->create([
            'school_id' => $this->schoolA->id,
            'academic_year_id' => $year->id,
        ]);
        $subject = Subject::factory()->create(['school_id' => $this->schoolA->id, 'name' => 'ZZMapelRahasia']);
        ClassSubject::factory()->create([
            'school_id' => $this->schoolA->id,
            'academic_year_id' => $year->id,
            'class_id' => $class->id,
            'subject_id' => $subject->id,
        ]);

        $student = Student::factory()->create(['school_id' => $this->schoolA->id, 'nis' => 'ZZ-9008']);

        $this->actingAs($this->adminOf($this->schoolA))
            ->get($this->viewUrl($student))
            ->assertSuccessful()
            ->assertDontSee('ZZMapelRahasia');
    }
}
