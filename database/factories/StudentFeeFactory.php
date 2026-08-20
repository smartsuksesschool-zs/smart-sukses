<?php

namespace Database\Factories;

use App\Enums\StudentFeeStatus;
use App\Models\FeeType;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentFee>
 */
class StudentFeeFactory extends Factory
{
    protected $model = StudentFee::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'student_id' => Student::factory(),
            'fee_type_id' => FeeType::factory(),
            'academic_year_id' => null,
            'amount' => 150_000,
            'amount_paid' => 0,
            'due_date' => '2026-08-10',
            'period' => '2026-08',
            'status' => StudentFeeStatus::Unpaid->value,
        ];
    }
}
