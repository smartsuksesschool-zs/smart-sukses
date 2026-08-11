<?php

namespace Tests\Feature\Grading;

use App\Enums\AssessmentType;
use App\Enums\GradeType;
use App\Models\Grade;
use App\Services\Grading\ComponentScoreAggregator;

/**
 * Keputusan Sprint 4 butir 1 — "Jika satu siswa memiliki beberapa grade DAILY
 * yang valid, nilai 'Harian' dihitung dengan RATA-RATA seluruh nilai DAILY
 * yang valid. Contoh: 80, 90, 70 = 80."
 */
class DailyAverageTest extends GradingTestCase
{
    protected ComponentScoreAggregator $aggregator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aggregator = app(ComponentScoreAggregator::class);
        $this->actingAs($this->teacher);
    }

    protected function grades()
    {
        return Grade::query()
            ->forStudent($this->student->id)
            ->forClassSubject($this->classSubject->id)
            ->get();
    }

    public function test_three_daily_scores_average_to_the_documented_example(): void
    {
        $this->activeConfig();

        $this->grade(GradeType::Daily, 80);
        $this->grade(GradeType::Daily, 90);
        $this->grade(GradeType::Daily, 70);

        $this->assertSame(80.0, $this->aggregator->averageForType($this->grades(), GradeType::Daily));
    }

    public function test_a_single_daily_score_is_its_own_average(): void
    {
        $this->activeConfig();

        $this->grade(GradeType::Daily, 77.5);

        $this->assertSame(77.5, $this->aggregator->averageForType($this->grades(), GradeType::Daily));
    }

    public function test_average_is_rounded_to_two_decimals(): void
    {
        $this->activeConfig();

        $this->grade(GradeType::Daily, 80);
        $this->grade(GradeType::Daily, 85);
        $this->grade(GradeType::Daily, 83);

        // 248 / 3 = 82.6666…
        $this->assertSame(82.67, $this->aggregator->averageForType($this->grades(), GradeType::Daily));
    }

    public function test_formative_scores_are_excluded_from_the_average(): void
    {
        // Keputusan butir 3 — "Tidak semua Daily/formative otomatis menjadi
        // komponen nilai rapor."
        $this->activeConfig();

        $this->grade(GradeType::Daily, 80);
        $this->grade(GradeType::Daily, 90);
        $this->grade(GradeType::Daily, 10, AssessmentType::Formative);

        $this->assertSame(85.0, $this->aggregator->averageForType($this->grades(), GradeType::Daily));
    }

    public function test_each_component_is_averaged_independently(): void
    {
        $this->activeConfig();

        $this->grade(GradeType::Daily, 80);
        $this->grade(GradeType::Daily, 90);
        $this->grade(GradeType::Midterm, 70);
        $this->grade(GradeType::Final, 60);

        $averages = $this->aggregator->averagesByType($this->grades());

        $this->assertSame(85.0, $averages[GradeType::Daily->value]);
        $this->assertSame(70.0, $averages[GradeType::Midterm->value]);
        $this->assertSame(60.0, $averages[GradeType::Final->value]);
    }

    public function test_attitude_is_never_part_of_the_academic_averages(): void
    {
        $this->activeConfig();

        $this->grade(GradeType::Daily, 80);
        $this->grade(GradeType::Attitude, 50);

        $averages = $this->aggregator->averagesByType($this->grades());

        $this->assertArrayNotHasKey(GradeType::Attitude->value, $averages);
        $this->assertSame(50.0, $this->aggregator->attitudeAverage($this->grades()));
    }

    public function test_no_valid_grades_yields_no_average(): void
    {
        $this->activeConfig();

        $this->grade(GradeType::Daily, 90, AssessmentType::Formative);

        $this->assertNull($this->aggregator->averageForType($this->grades(), GradeType::Daily));
    }
}
