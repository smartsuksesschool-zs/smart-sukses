<?php

namespace Tests\Feature\Cbt\Concerns;

use App\Enums\RoleName;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Exam;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

/**
 * Dua cabang lengkap, masing-masing konsisten dengan dirinya sendiri.
 *
 * Seluruh yang diuji pada fondasi CBT adalah **kesesuaian cabang**: mana yang
 * boleh saling menunjuk dan mana yang tidak. Fiksur yang cabangnya sudah campur
 * sejak awal akan membuat test positif dan test negatif sama-sama tidak berarti,
 * karena tidak ada satu pun keadaan sah untuk dibandingkan.
 *
 * Karena itu setiap cabang di sini punya tahun ajaran, kelas, mata pelajaran,
 * guru pengampu, kelas-mapel, dan siswanya sendiri — dan tidak ada satu baris
 * pun yang menyeberang, kecuali bila sebuah test membuatnya menyeberang dengan
 * sengaja (butir 280).
 */
trait BuildsExamFixture
{
    protected School $schoolA;

    protected School $schoolB;

    protected AcademicYear $yearA;

    protected AcademicYear $yearB;

    protected SchoolClass $classA;

    protected SchoolClass $classB;

    protected ClassSubject $classSubjectA;

    protected ClassSubject $classSubjectB;

    protected User $teacherA;

    protected User $teacherB;

    protected Student $studentA;

    protected Student $studentB;

    protected function buildExamFixture(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create(['name' => 'SMP Madani']);
        $this->schoolB = School::factory()->create(['name' => 'SMP Seberang']);

        $this->yearA = $this->yearIn($this->schoolA);
        $this->yearB = $this->yearIn($this->schoolB);

        $this->classA = $this->classIn($this->schoolA, $this->yearA);
        $this->classB = $this->classIn($this->schoolB, $this->yearB);

        $this->teacherA = $this->userIn($this->schoolA, RoleName::Guru);
        $this->teacherB = $this->userIn($this->schoolB, RoleName::Guru);

        $this->classSubjectA = $this->classSubjectIn($this->schoolA, $this->classA, $this->teacherA);
        $this->classSubjectB = $this->classSubjectIn($this->schoolB, $this->classB, $this->teacherB);

        $this->studentA = $this->studentIn($this->schoolA);
        $this->studentB = $this->studentIn($this->schoolB);
    }

    protected function yearIn(School $school): AcademicYear
    {
        return AcademicYear::factory()->active()->create(['school_id' => $school->getKey()]);
    }

    protected function classIn(School $school, AcademicYear $year): SchoolClass
    {
        return SchoolClass::factory()->create([
            'school_id' => $school->getKey(),
            'academic_year_id' => $year->getKey(),
        ]);
    }

    protected function classSubjectIn(School $school, SchoolClass $class, User $teacher): ClassSubject
    {
        return ClassSubject::factory()->create([
            'school_id' => $school->getKey(),
            'class_id' => $class->getKey(),
            'academic_year_id' => $class->academic_year_id,
            'subject_id' => Subject::factory()->create(['school_id' => $school->getKey()])->getKey(),
            'teacher_id' => $teacher->getKey(),
        ]);
    }

    protected function studentIn(School $school): Student
    {
        return Student::factory()->create(['school_id' => $school->getKey()]);
    }

    protected function userIn(School $school, RoleName $role): User
    {
        return User::factory()->forSchool($school)->withRole($role)->create();
    }

    /**
     * Ujian yang seluruh keterkaitannya sah.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function examIn(ClassSubject $classSubject, array $overrides = []): Exam
    {
        // `$overrides` di kiri: pada penggabungan array PHP, kunci operan
        // kirilah yang menang.
        return Exam::factory()->create($overrides + [
            'class_subject_id' => $classSubject->getKey(),
            'school_id' => $classSubject->school_id,
            'academic_year_id' => $classSubject->academic_year_id,
            'created_by' => $classSubject->teacher_id,
        ]);
    }
}
