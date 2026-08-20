<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'student_fee_id' => StudentFee::factory(),
            'student_id' => Student::factory(),
            'payment_method' => PaymentMethod::Cash->value,
            'amount_paid' => 150_000,
            'payment_date' => '2026-08-05',
            'received_by' => User::factory(),
        ];
    }
}
