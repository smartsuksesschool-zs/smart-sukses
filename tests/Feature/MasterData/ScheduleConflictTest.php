<?php

namespace Tests\Feature\MasterData;

use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Schedule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KELAS-03 poin 2 — "Sistem mendeteksi konflik jadwal
 * (guru/ruangan/kelas yang sama di waktu bersamaan)".
 */
class ScheduleConflictTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->year = AcademicYear::factory()->create([
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
    }

    public function test_same_teacher_at_the_same_time_is_a_conflict(): void
    {
        $teacher = User::factory()->forSchool($this->school)->create();

        $first = $this->classSubject(teacher: $teacher);
        $second = $this->classSubject(teacher: $teacher);

        $this->schedule($first, '07:00:00', '08:30:00', 'R101');

        $conflicts = Schedule::conflictsFor($second, 1, '08:00:00', '09:00:00', 'R202');

        $this->assertCount(1, $conflicts);
    }

    public function test_same_class_at_the_same_time_is_a_conflict(): void
    {
        $class = SchoolClass::factory()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->year->id,
        ]);

        $first = $this->classSubject(class: $class);
        $second = $this->classSubject(class: $class);

        $this->schedule($first, '07:00:00', '08:30:00', 'R101');

        $conflicts = Schedule::conflictsFor($second, 1, '08:00:00', '09:00:00', 'R303');

        $this->assertCount(1, $conflicts);
    }

    public function test_same_room_at_the_same_time_is_a_conflict(): void
    {
        $first = $this->classSubject();
        $second = $this->classSubject();

        $this->schedule($first, '07:00:00', '08:30:00', 'LAB-1');

        $conflicts = Schedule::conflictsFor($second, 1, '08:00:00', '09:00:00', 'LAB-1');

        $this->assertCount(1, $conflicts);
    }

    public function test_adjacent_slots_do_not_conflict(): void
    {
        $first = $this->classSubject();
        $second = $this->classSubject();

        $this->schedule($first, '07:00:00', '08:30:00', 'R101');

        // Mulai tepat saat jadwal sebelumnya berakhir — bukan bentrok.
        $conflicts = Schedule::conflictsFor($second, 1, '08:30:00', '10:00:00', 'R101');

        $this->assertCount(0, $conflicts);
    }

    public function test_different_day_does_not_conflict(): void
    {
        $first = $this->classSubject();
        $second = $this->classSubject();

        $this->schedule($first, '07:00:00', '08:30:00', 'R101');

        $conflicts = Schedule::conflictsFor($second, 2, '07:00:00', '08:30:00', 'R101');

        $this->assertCount(0, $conflicts);
    }

    public function test_editing_a_schedule_ignores_itself(): void
    {
        $classSubject = $this->classSubject();
        $schedule = $this->schedule($classSubject, '07:00:00', '08:30:00', 'R101');

        $conflicts = Schedule::conflictsFor(
            $classSubject,
            1,
            '07:00:00',
            '08:30:00',
            'R101',
            ignoreId: $schedule->id,
        );

        $this->assertCount(0, $conflicts);
    }

    protected function classSubject(?SchoolClass $class = null, ?User $teacher = null): ClassSubject
    {
        return ClassSubject::factory()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->year->id,
            'class_id' => $class?->id ?? SchoolClass::factory()->create([
                'school_id' => $this->school->id,
                'academic_year_id' => $this->year->id,
            ])->id,
            'subject_id' => Subject::factory()->create(['school_id' => $this->school->id])->id,
            'teacher_id' => $teacher?->id ?? User::factory()->forSchool($this->school)->create()->id,
        ]);
    }

    protected function schedule(ClassSubject $classSubject, string $start, string $end, string $room): Schedule
    {
        return Schedule::factory()->create([
            'school_id' => $this->school->id,
            'class_subject_id' => $classSubject->id,
            'day_of_week' => 1,
            'start_time' => $start,
            'end_time' => $end,
            'room' => $room,
        ]);
    }
}
