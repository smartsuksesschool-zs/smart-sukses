<?php

namespace Tests\Feature\MasterData;

use App\Enums\DayOfWeek;
use App\Enums\Gender;
use App\Enums\RoleName;
use App\Enums\StudentStatus;
use App\Filament\Resources\AcademicYearResource\Pages\ManageAcademicYears;
use App\Filament\Resources\ScheduleResource\Pages\ManageSchedules;
use App\Filament\Resources\SchoolClassResource\Pages\CreateSchoolClass;
use App\Filament\Resources\SchoolClassResource\Pages\EditSchoolClass;
use App\Filament\Resources\StudentResource\Pages\CreateStudent;
use App\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Filament\Resources\SubjectResource\Pages\ManageSubjects;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Schedule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Penetapan cabang pada lima resource yang dibuat lewat panel (butir 588).
 *
 * `BelongsToSchool` mengisi `school_id` dari `SchoolScope::currentSchoolId()`,
 * dan nilai itu **NULL untuk Super Admin** — disengaja, karena peran Platform
 * Level memang tidak terikat cabang. Selama form tidak pernah menanyakan
 * cabangnya, setiap penyimpanan oleh Super Admin berakhir sebagai INSERT dengan
 * `school_id` NULL, lalu ditolak basis data sebagai galat mentah di tengah
 * request Livewire. Itu yang terjadi di produksi saat go-live disiapkan.
 *
 * Yang dijaga di sini dua arah sekaligus:
 *
 *  1. Super Admin **harus** memilih cabang, dan tidak satu pun baris lahir
 *     tanpa cabang;
 *  2. peran School Level tidak pernah dapat menembus cabangnya sendiri, bahkan
 *     ketika klien mengirim `school_id` cabang lain.
 *
 * Seluruh data di sini sintetis.
 */
class TenantAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected User $superAdmin;

    protected User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create(['is_active' => true]);
        $this->schoolB = School::factory()->create(['is_active' => true]);

        $this->superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create(['school_id' => null]);
        $this->adminA = User::factory()->forSchool($this->schoolA)->withRole(RoleName::SchoolAdmin)->create();
    }

    /** @return array<string, mixed> */
    protected function studentData(array $overrides = []): array
    {
        return array_merge([
            'nis' => 'ZZ-'.fake()->unique()->numberBetween(1000, 9999),
            'full_name' => 'Siswa Sintetis',
            'gender' => Gender::Male->value,
            'status' => StudentStatus::Active->value,
        ], $overrides);
    }

    protected function academicYearFor(School $school): AcademicYear
    {
        return AcademicYear::factory()->create(['school_id' => $school->id, 'is_active' => true]);
    }

    // ------------------------------------------------------------- SISWA

    public function test_super_admin_tanpa_cabang_ditolak_dan_tidak_menulis_baris(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(CreateStudent::class)
            ->fillForm($this->studentData())
            ->call('create')
            ->assertHasFormErrors(['school_id']);

        // Yang paling penting: tidak ada INSERT sama sekali, bukan galat 500
        // sesudah baris terlanjur dicoba ditulis.
        $this->assertSame(0, Student::query()->withoutGlobalScopes()->count());
    }

    public function test_super_admin_dengan_cabang_menulis_ke_cabang_itu(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(CreateStudent::class)
            ->fillForm($this->studentData(['school_id' => $this->schoolB->id, 'nis' => 'ZZ-2001']))
            ->call('create')
            ->assertHasNoFormErrors();

        $student = Student::query()->withoutGlobalScopes()->where('nis', 'ZZ-2001')->firstOrFail();

        $this->assertSame($this->schoolB->id, $student->school_id);
    }

    public function test_admin_sekolah_selalu_menulis_ke_cabang_akunnya(): void
    {
        $this->actingAs($this->adminA);

        Livewire::test(CreateStudent::class)
            ->fillForm($this->studentData(['nis' => 'ZZ-2002']))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(
            $this->schoolA->id,
            Student::query()->withoutGlobalScopes()->where('nis', 'ZZ-2002')->value('school_id'),
        );
    }

    public function test_school_id_kiriman_klien_tidak_dapat_menembus_cabang_lain(): void
    {
        $this->actingAs($this->adminA);

        // Field cabang tidak dirender untuk peran School Level; nilai yang
        // tetap dikirim klien tidak boleh menjadi cabang barisnya.
        Livewire::test(CreateStudent::class)
            ->fillForm($this->studentData(['nis' => 'ZZ-2003']))
            ->set('data.school_id', $this->schoolB->id)
            ->call('create')
            ->assertHasNoFormErrors();

        $student = Student::query()->withoutGlobalScopes()->where('nis', 'ZZ-2003')->firstOrFail();

        $this->assertSame($this->schoolA->id, $student->school_id);
        $this->assertNotSame($this->schoolB->id, $student->school_id);
    }

    public function test_menyunting_siswa_tidak_memindahkan_cabangnya(): void
    {
        $student = Student::factory()->create(['school_id' => $this->schoolA->id, 'nis' => 'ZZ-2004']);

        $this->actingAs($this->adminA);

        Livewire::test(EditStudent::class, ['record' => $student->getRouteKey()])
            ->fillForm(['full_name' => 'Siswa Sintetis Diubah'])
            ->set('data.school_id', $this->schoolB->id)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($this->schoolA->id, $student->fresh()->school_id);
    }

    public function test_super_admin_menyunting_tanpa_memindahkan_cabang(): void
    {
        $student = Student::factory()->create(['school_id' => $this->schoolA->id, 'nis' => 'ZZ-2005']);

        $this->actingAs($this->superAdmin);

        // Field cabang dinonaktifkan pada edit, sehingga nilainya tidak ikut
        // tersimpan walau dikirim.
        Livewire::test(EditStudent::class, ['record' => $student->getRouteKey()])
            ->set('data.school_id', $this->schoolB->id)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($this->schoolA->id, $student->fresh()->school_id);
    }

    // --------------------------------------------------- NIS unik per cabang

    public function test_nis_yang_sama_boleh_ada_di_cabang_lain(): void
    {
        Student::factory()->create(['school_id' => $this->schoolA->id, 'nis' => 'ZZ-3001']);

        $this->actingAs($this->superAdmin);

        Livewire::test(CreateStudent::class)
            ->fillForm($this->studentData(['school_id' => $this->schoolB->id, 'nis' => 'ZZ-3001']))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(
            2,
            Student::query()->withoutGlobalScopes()->where('nis', 'ZZ-3001')->count(),
        );
    }

    public function test_nis_ganda_di_cabang_yang_dipilih_ditolak(): void
    {
        Student::factory()->create(['school_id' => $this->schoolB->id, 'nis' => 'ZZ-3002']);

        $this->actingAs($this->superAdmin);

        // Sebelum butir 588 aturan unik menyaring `school_id IS NULL` untuk
        // Super Admin, sehingga bentrok di cabang yang dipilih tidak pernah
        // terlihat sampai basis data menolaknya.
        Livewire::test(CreateStudent::class)
            ->fillForm($this->studentData(['school_id' => $this->schoolB->id, 'nis' => 'ZZ-3002']))
            ->call('create')
            ->assertHasFormErrors(['nis']);

        $this->assertSame(
            1,
            Student::query()->withoutGlobalScopes()->where('nis', 'ZZ-3002')->count(),
        );
    }

    // ------------------------------------------------------------- ROMBEL

    public function test_rombel_super_admin_wajib_bercabang(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(CreateSchoolClass::class)
            ->fillForm(['name' => 'X-A', 'grade_level' => 10, 'capacity' => 30])
            ->call('create')
            ->assertHasFormErrors(['school_id']);

        $this->assertSame(0, SchoolClass::query()->withoutGlobalScopes()->count());
    }

    public function test_rombel_super_admin_mengikuti_cabang_yang_dipilih(): void
    {
        $year = $this->academicYearFor($this->schoolB);

        $this->actingAs($this->superAdmin);

        Livewire::test(CreateSchoolClass::class)
            ->fillForm([
                'school_id' => $this->schoolB->id,
                'academic_year_id' => $year->id,
                'name' => 'XI-B',
                'grade_level' => 11,
                'capacity' => 30,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $class = SchoolClass::query()->withoutGlobalScopes()->where('name', 'XI-B')->firstOrFail();

        $this->assertSame($this->schoolB->id, $class->school_id);
    }

    public function test_rombel_admin_sekolah_tidak_dapat_dipaksa_ke_cabang_lain(): void
    {
        $year = $this->academicYearFor($this->schoolA);

        $this->actingAs($this->adminA);

        // Cabang palsu dikirim lebih dulu, lalu sisa formulir: urutan ini
        // meniru klien yang menyisipkan `school_id` sendiri, tanpa memicu
        // pengosongan field turunan milik Super Admin.
        Livewire::test(CreateSchoolClass::class)
            ->set('data.school_id', $this->schoolB->id)
            ->fillForm([
                'academic_year_id' => $year->id,
                'name' => 'X-C',
                'grade_level' => 10,
                'capacity' => 30,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(
            $this->schoolA->id,
            SchoolClass::query()->withoutGlobalScopes()->where('name', 'X-C')->value('school_id'),
        );
    }

    public function test_menyunting_rombel_tidak_memindahkan_cabangnya(): void
    {
        $year = $this->academicYearFor($this->schoolA);
        $class = SchoolClass::factory()->create([
            'school_id' => $this->schoolA->id,
            'academic_year_id' => $year->id,
            'name' => 'X-D',
        ]);

        $this->actingAs($this->adminA);

        Livewire::test(EditSchoolClass::class, ['record' => $class->getRouteKey()])
            ->set('data.school_id', $this->schoolB->id)
            ->fillForm(['room' => 'R-101', 'academic_year_id' => $year->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($this->schoolA->id, $class->fresh()->school_id);
    }

    // ------------------------------------------------------ MATA PELAJARAN

    public function test_mata_pelajaran_super_admin_wajib_bercabang(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(ManageSubjects::class)
            ->callAction('create', ['name' => 'Matematika Sintetis', 'code' => 'ZZMTK'])
            ->assertHasActionErrors(['school_id']);

        $this->assertSame(0, Subject::query()->withoutGlobalScopes()->count());
    }

    public function test_mata_pelajaran_super_admin_mengikuti_cabang_yang_dipilih(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(ManageSubjects::class)
            ->callAction('create', [
                'school_id' => $this->schoolB->id,
                'name' => 'Fisika Sintetis',
                'code' => 'ZZFIS',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(
            $this->schoolB->id,
            Subject::query()->withoutGlobalScopes()->where('code', 'ZZFIS')->value('school_id'),
        );
    }

    public function test_mata_pelajaran_admin_sekolah_tetap_di_cabangnya(): void
    {
        $this->actingAs($this->adminA);

        Livewire::test(ManageSubjects::class)
            ->callAction('create', [
                'school_id' => $this->schoolB->id,
                'name' => 'Kimia Sintetis',
                'code' => 'ZZKIM',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(
            $this->schoolA->id,
            Subject::query()->withoutGlobalScopes()->where('code', 'ZZKIM')->value('school_id'),
        );
    }

    // -------------------------------------------------------- TAHUN AJARAN

    public function test_tahun_ajaran_super_admin_wajib_bercabang(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(ManageAcademicYears::class)
            ->callAction('create', [
                'name' => '2030/2031 Ganjil',
                'semester' => 1,
                'start_date' => '2030-07-01',
                'end_date' => '2030-12-31',
            ])
            ->assertHasActionErrors(['school_id']);

        $this->assertSame(0, AcademicYear::query()->withoutGlobalScopes()->count());
    }

    public function test_tahun_ajaran_super_admin_mengikuti_cabang_yang_dipilih(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(ManageAcademicYears::class)
            ->callAction('create', [
                'school_id' => $this->schoolB->id,
                'name' => '2031/2032 Ganjil',
                'semester' => 1,
                'start_date' => '2031-07-01',
                'end_date' => '2031-12-31',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(
            $this->schoolB->id,
            AcademicYear::query()->withoutGlobalScopes()->where('name', '2031/2032 Ganjil')->value('school_id'),
        );
    }

    public function test_tahun_ajaran_admin_sekolah_tetap_di_cabangnya(): void
    {
        $this->actingAs($this->adminA);

        Livewire::test(ManageAcademicYears::class)
            ->callAction('create', [
                'school_id' => $this->schoolB->id,
                'name' => '2032/2033 Ganjil',
                'semester' => 1,
                'start_date' => '2032-07-01',
                'end_date' => '2032-12-31',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(
            $this->schoolA->id,
            AcademicYear::query()->withoutGlobalScopes()->where('name', '2032/2033 Ganjil')->value('school_id'),
        );
    }

    // -------------------------------------------------------------- JADWAL

    protected function classSubjectFor(School $school): ClassSubject
    {
        $year = $this->academicYearFor($school);
        $class = SchoolClass::factory()->create([
            'school_id' => $school->id,
            'academic_year_id' => $year->id,
        ]);
        $subject = Subject::factory()->create(['school_id' => $school->id]);
        $teacher = User::factory()->forSchool($school)->withRole(RoleName::Guru)->create();

        return ClassSubject::factory()->create([
            'school_id' => $school->id,
            'academic_year_id' => $year->id,
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
        ]);
    }

    public function test_jadwal_super_admin_wajib_bercabang(): void
    {
        $classSubject = $this->classSubjectFor($this->schoolB);

        $this->actingAs($this->superAdmin);

        Livewire::test(ManageSchedules::class)
            ->callAction('create', [
                'class_subject_id' => $classSubject->id,
                'day_of_week' => DayOfWeek::Monday->value,
                'start_time' => '07:00',
                'end_time' => '08:00',
            ])
            ->assertHasActionErrors(['school_id']);

        $this->assertSame(0, Schedule::query()->withoutGlobalScopes()->count());
    }

    public function test_jadwal_super_admin_mengikuti_cabang_yang_dipilih(): void
    {
        $classSubject = $this->classSubjectFor($this->schoolB);

        $this->actingAs($this->superAdmin);

        Livewire::test(ManageSchedules::class)
            ->callAction('create', [
                'school_id' => $this->schoolB->id,
                'class_subject_id' => $classSubject->id,
                'day_of_week' => DayOfWeek::Monday->value,
                'start_time' => '07:00',
                'end_time' => '08:00',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(
            $this->schoolB->id,
            Schedule::query()->withoutGlobalScopes()->value('school_id'),
        );
    }

    public function test_jadwal_admin_sekolah_tetap_di_cabangnya(): void
    {
        $classSubject = $this->classSubjectFor($this->schoolA);

        $this->actingAs($this->adminA);

        Livewire::test(ManageSchedules::class)
            ->callAction('create', [
                'school_id' => $this->schoolB->id,
                'class_subject_id' => $classSubject->id,
                'day_of_week' => DayOfWeek::Tuesday->value,
                'start_time' => '07:00',
                'end_time' => '08:00',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(
            $this->schoolA->id,
            Schedule::query()->withoutGlobalScopes()->value('school_id'),
        );
    }
}
