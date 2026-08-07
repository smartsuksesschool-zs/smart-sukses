<?php

namespace Database\Factories;

use App\Models\ClassSubject;
use App\Models\Schedule;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Schedule>
 */
class ScheduleFactory extends Factory
{
    protected $model = Schedule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'class_subject_id' => ClassSubject::factory(),
            'day_of_week' => fake()->numberBetween(1, 5),
            'start_time' => '07:00:00',
            'end_time' => '08:30:00',
            'room' => 'R101',
        ];
    }
}
