<?php

namespace Database\Factories;

use App\Enums\StudentClassStatus;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentClass>
 */
class StudentClassFactory extends Factory
{
    protected $model = StudentClass::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'student_id' => Student::factory(),
            'class_id' => SchoolClass::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'status' => StudentClassStatus::Active->value,
        ];
    }

    public function moved(): static
    {
        return $this->state(fn () => ['status' => StudentClassStatus::Moved->value]);
    }
}
