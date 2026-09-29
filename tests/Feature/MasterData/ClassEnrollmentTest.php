<?php

namespace Tests\Feature\MasterData;

use App\Enums\RoleName;
use App\Filament\Resources\SchoolClassResource\Pages\CreateSchoolClass;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * KELAS-01 & KELAS-02 — wali kelas dan penempatan siswa.
 */
class ClassEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create();
        $this->year = AcademicYear::factory()->create([
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
    }

    /**
     * Dua rombel bernama sama pada satu cabang dan tahun ajaran adalah data
     * yang keliru, dan akibatnya tidak terlihat di layar kelas: pencocokan
     * kelas saat impor siswa memilih salah satu di antaranya tanpa memberi
     * tahu siapa pun (butir 516).
     *
     * `classes` tidak punya indeks unik untuk itu, jadi pagarnya di form —
     * mengikuti pola yang sudah dipakai kolom wali kelas.
     */
    public function test_two_classes_cannot_share_a_name_in_one_year(): void
    {
        $admin = User::factory()->forSchool($this->school)->withRole(RoleName::SchoolAdmin)->create();

        $this->makeClass(['name' => 'X Terbuka - 2', 'grade_level' => 10]);

        Livewire::actingAs($admin)
            ->test(CreateSchoolClass::class)
            ->fillForm([
                'academic_year_id' => $this->year->id,
                'name' => 'X Terbuka - 2',
                'grade_level' => 10,
                'capacity' => 35,
            ])
            ->call('create')
            ->assertHasFormErrors(['name']);

        $this->assertSame(1, SchoolClass::query()->where('name', 'X Terbuka - 2')->count());
    }

    public function test_the_same_class_name_is_allowed_in_another_year(): void
    {
        $admin = User::factory()->forSchool($this->school)->withRole(RoleName::SchoolAdmin)->create();

        $this->makeClass(['name' => 'X Terbuka - 2', 'grade_level' => 10]);

        $next = AcademicYear::factory()->create([
            'school_id' => $this->school->id,
            'is_active' => false,
        ]);

        Livewire::actingAs($admin)
            ->test(CreateSchoolClass::class)
            ->fillForm([
                'academic_year_id' => $next->id,
                'name' => 'X Terbuka - 2',
                'grade_level' => 10,
                'capacity' => 35,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, SchoolClass::query()->where('name', 'X Terbuka - 2')->count());
    }

    public function test_a_teacher_cannot_be_homeroom_of_two_classes_in_one_year(): void
    {
        $teacher = User::factory()->forSchool($this->school)->withRole(RoleName::WaliKelas)->create();

        $this->makeClass(['homeroom_teacher_id' => $teacher->id]);

        $this->expectException(QueryException::class);

        $this->makeClass(['homeroom_teacher_id' => $teacher->id]);
    }

    // ============================================ AC-KELAS-05 — guru aktif

    /**
     * AC-KELAS-05 / KELAS-01 butir 2 — wali kelas hanya dari guru **aktif**.
     *
     * Diuji dengan benar-benar mengirim id guru nonaktif ke form, bukan dengan
     * membaca daftar pilihannya: daftar yang benar tidak menjamin apa pun bila
     * nilai di luar daftar tetap diterima saat disimpan — dan itulah bentuk
     * kegagalan yang sesungguhnya mungkin terjadi, mis. ketika guru
     * dinonaktifkan sementara form masih terbuka di layar admin (butir 592).
     */
    public function test_guru_nonaktif_ditolak_sebagai_wali_kelas(): void
    {
        $nonaktif = User::factory()
            ->forSchool($this->school)
            ->withRole(RoleName::WaliKelas)
            ->create(['is_active' => false]);

        $this->actingAs(
            User::factory()->forSchool($this->school)->withRole(RoleName::SchoolAdmin)->create()
        );

        Livewire::test(CreateSchoolClass::class)
            ->fillForm([
                'academic_year_id' => $this->year->id,
                'name' => 'X-A',
                'grade_level' => 10,
                'capacity' => 30,
                'homeroom_teacher_id' => $nonaktif->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['homeroom_teacher_id']);

        $this->assertSame(0, SchoolClass::query()->count());
    }

    /**
     * Pagar arah sebaliknya: guru **aktif** memang diterima. Tanpa ini,
     * penolakan di atas dapat lolos hanya karena seluruh wali kelas ditolak.
     */
    public function test_guru_aktif_diterima_sebagai_wali_kelas(): void
    {
        $aktif = User::factory()
            ->forSchool($this->school)
            ->withRole(RoleName::WaliKelas)
            ->create(['is_active' => true]);

        $this->actingAs(
            User::factory()->forSchool($this->school)->withRole(RoleName::SchoolAdmin)->create()
        );

        Livewire::test(CreateSchoolClass::class)
            ->fillForm([
                'academic_year_id' => $this->year->id,
                'name' => 'X-B',
                'grade_level' => 10,
                'capacity' => 30,
                'homeroom_teacher_id' => $aktif->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($aktif->id, SchoolClass::query()->value('homeroom_teacher_id'));
    }

    /**
     * Guru aktif milik **cabang lain** juga bukan pilihan yang sah, dan ini
     * pagar tenant — bukan sekadar pagar status aktif.
     */
    public function test_guru_cabang_lain_ditolak_sebagai_wali_kelas(): void
    {
        $cabangLain = School::factory()->create();
        $asing = User::factory()
            ->forSchool($cabangLain)
            ->withRole(RoleName::WaliKelas)
            ->create(['is_active' => true]);

        $this->actingAs(
            User::factory()->forSchool($this->school)->withRole(RoleName::SchoolAdmin)->create()
        );

        Livewire::test(CreateSchoolClass::class)
            ->fillForm([
                'academic_year_id' => $this->year->id,
                'name' => 'X-C',
                'grade_level' => 10,
                'capacity' => 30,
                'homeroom_teacher_id' => $asing->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['homeroom_teacher_id']);

        $this->assertSame(0, SchoolClass::query()->count());
    }

    public function test_the_same_teacher_may_lead_a_class_in_a_different_year(): void
    {
        $teacher = User::factory()->forSchool($this->school)->withRole(RoleName::WaliKelas)->create();
        $otherYear = AcademicYear::factory()->create(['school_id' => $this->school->id]);

        $this->makeClass(['homeroom_teacher_id' => $teacher->id]);
        $second = $this->makeClass([
            'homeroom_teacher_id' => $teacher->id,
            'academic_year_id' => $otherYear->id,
        ]);

        $this->assertSame($teacher->id, $second->homeroom_teacher_id);
    }

    public function test_classes_without_a_homeroom_teacher_are_allowed_more_than_once(): void
    {
        $this->makeClass(['homeroom_teacher_id' => null]);
        $this->makeClass(['homeroom_teacher_id' => null]);

        $this->assertSame(2, SchoolClass::query()->count());
    }

    public function test_enrolled_student_is_excluded_from_the_eligible_list(): void
    {
        $class = $this->makeClass();
        $enrolled = Student::factory()->create(['school_id' => $this->school->id]);
        $free = Student::factory()->create(['school_id' => $this->school->id]);

        StudentClass::factory()->create([
            'school_id' => $this->school->id,
            'student_id' => $enrolled->id,
            'class_id' => $class->id,
            'academic_year_id' => $this->year->id,
        ]);

        $eligible = Student::query()->eligibleForYear($this->year->id)->pluck('id');

        $this->assertTrue($eligible->contains($free->id));
        $this->assertFalse($eligible->contains($enrolled->id));
    }

    public function test_a_moved_student_becomes_eligible_again(): void
    {
        $class = $this->makeClass();
        $student = Student::factory()->create(['school_id' => $this->school->id]);

        $placement = StudentClass::factory()->create([
            'school_id' => $this->school->id,
            'student_id' => $student->id,
            'class_id' => $class->id,
            'academic_year_id' => $this->year->id,
        ]);

        $this->assertFalse(
            Student::query()->eligibleForYear($this->year->id)->pluck('id')->contains($student->id),
        );

        // Histori tetap tersimpan, hanya statusnya berubah (bukan dihapus).
        $placement->update(['status' => 'MOVED']);

        $this->assertTrue(
            Student::query()->eligibleForYear($this->year->id)->pluck('id')->contains($student->id),
        );
        $this->assertDatabaseCount('student_classes', 1);
    }

    public function test_inactive_students_are_never_eligible(): void
    {
        Student::factory()->graduated()->create(['school_id' => $this->school->id]);

        $this->assertSame(0, Student::query()->eligibleForYear($this->year->id)->count());
    }

    public function test_capacity_helpers_reflect_active_placements(): void
    {
        $class = $this->makeClass(['capacity' => 2]);

        foreach (range(1, 2) as $ignored) {
            StudentClass::factory()->create([
                'school_id' => $this->school->id,
                'student_id' => Student::factory()->create(['school_id' => $this->school->id])->id,
                'class_id' => $class->id,
                'academic_year_id' => $this->year->id,
            ]);
        }

        $this->assertSame(2, $class->activeStudentCount());
        $this->assertFalse($class->hasRemainingCapacity());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeClass(array $attributes = []): SchoolClass
    {
        return SchoolClass::factory()->create($attributes + [
            'school_id' => $this->school->id,
            'academic_year_id' => $this->year->id,
        ]);
    }
}
