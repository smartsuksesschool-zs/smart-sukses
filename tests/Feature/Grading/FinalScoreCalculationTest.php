<?php

namespace Tests\Feature\Grading;

use App\Enums\AssessmentType;
use App\Enums\GradeType;
use App\Models\Grade;
use App\Models\GradeConfig;
use App\Services\Grading\FinalScoreCalculator;
use App\Services\Grading\GradeConfigVersionManager;

/**
 * NILAI-02 — "Formula: Nilai Akhir = (Harian × bobot) + (UTS × bobot) +
 * (UAS × bobot). Hasil pembulatan 2 desimal."
 */
class FinalScoreCalculationTest extends GradingTestCase
{
    protected FinalScoreCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = app(FinalScoreCalculator::class);
        $this->actingAs($this->teacher);
    }

    protected function grades()
    {
        return Grade::query()
            ->forStudent($this->student->id)
            ->forClassSubject($this->classSubject->id)
            ->get();
    }

    public function test_final_score_follows_the_documented_formula(): void
    {
        $this->activeConfig();

        // Harian: rata-rata 80, 90, 70 = 80
        $this->grade(GradeType::Daily, 80);
        $this->grade(GradeType::Daily, 90);
        $this->grade(GradeType::Daily, 70);
        $this->grade(GradeType::Midterm, 75);
        $this->grade(GradeType::Final, 85);

        $result = $this->calculator->calculate($this->grades());

        // (80 × 0.40) + (75 × 0.30) + (85 × 0.30) = 32 + 22.5 + 25.5 = 80.00
        $this->assertTrue($result->isComplete);
        $this->assertSame(80.0, $result->score);
    }

    public function test_result_is_rounded_to_two_decimals(): void
    {
        $this->activeConfig([['type' => GradeType::Daily->value, 'weight' => 1.0]]);

        $this->grade(GradeType::Daily, 80);
        $this->grade(GradeType::Daily, 85);
        $this->grade(GradeType::Daily, 83);

        $result = $this->calculator->calculate($this->grades());

        $this->assertSame(82.67, $result->score);
    }

    public function test_skill_can_be_a_weighted_component(): void
    {
        // Keputusan butir 3 — "Skill = dapat menjadi komponen nilai akhir
        // tersendiri dan bobotnya configurable."
        $this->activeConfig([
            ['type' => GradeType::Daily->value, 'weight' => 0.50],
            ['type' => GradeType::Skill->value, 'weight' => 0.50],
        ]);

        $this->grade(GradeType::Daily, 80);
        $this->grade(GradeType::Skill, 90);

        $result = $this->calculator->calculate($this->grades());

        $this->assertSame(85.0, $result->score);
    }

    public function test_assignment_can_be_a_weighted_component(): void
    {
        $this->activeConfig([
            ['type' => GradeType::Daily->value, 'weight' => 0.40],
            ['type' => GradeType::Assignment->value, 'weight' => 0.20],
            ['type' => GradeType::Midterm->value, 'weight' => 0.20],
            ['type' => GradeType::Final->value, 'weight' => 0.20],
        ]);

        $this->grade(GradeType::Daily, 80);
        $this->grade(GradeType::Assignment, 90);
        $this->grade(GradeType::Midterm, 70);
        $this->grade(GradeType::Final, 60);

        // 32 + 18 + 14 + 12 = 76.00
        $this->assertSame(76.0, $this->calculator->calculate($this->grades())->score);
    }

    public function test_attitude_does_not_affect_the_academic_score(): void
    {
        // Keputusan butir 3 — "Attitude = TIDAK masuk nilai akademik".
        $this->activeConfig([['type' => GradeType::Daily->value, 'weight' => 1.0]]);

        $this->grade(GradeType::Daily, 80);
        $this->grade(GradeType::Attitude, 20);

        $this->assertSame(80.0, $this->calculator->calculate($this->grades())->score);
    }

    public function test_formative_grades_do_not_affect_the_score(): void
    {
        $this->activeConfig([['type' => GradeType::Daily->value, 'weight' => 1.0]]);

        $this->grade(GradeType::Daily, 80);
        $this->grade(GradeType::Daily, 20, AssessmentType::Formative);

        $this->assertSame(80.0, $this->calculator->calculate($this->grades())->score);
    }

    public function test_missing_component_makes_the_score_incomplete(): void
    {
        $this->activeConfig();

        $this->grade(GradeType::Daily, 80);
        $this->grade(GradeType::Midterm, 75);
        // UAS belum diinput.

        $result = $this->calculator->calculate($this->grades());

        $this->assertFalse($result->isComplete);
        $this->assertNull($result->score);
        $this->assertContains(GradeType::Final->value, $result->missingComponents);
    }

    public function test_no_grades_at_all_is_incomplete(): void
    {
        $this->activeConfig();

        $result = $this->calculator->calculate($this->grades());

        $this->assertFalse($result->isComplete);
        $this->assertNull($result->score);
    }

    public function test_calculation_uses_the_snapshot_not_the_current_configuration(): void
    {
        // Bukti langsung keputusan butir 2.
        $this->activeConfig([['type' => GradeType::Daily->value, 'weight' => 1.0]]);

        $this->grade(GradeType::Daily, 80);

        $before = $this->calculator->calculate($this->grades())->score;

        // Bobot pada tabel konfigurasi diubah tanpa membuat nilai baru.
        GradeConfig::query()->update(['components' => [
            ['type' => GradeType::Daily->value, 'weight' => 0.10],
        ]]);

        $after = $this->calculator->calculate($this->grades())->score;

        $this->assertSame($before, $after);
        $this->assertSame(80.0, $after);
    }

    /**
     * Mengaktifkan versi berikutnya dengan bobot yang berbeda, persis alur
     * keputusan butir 4 ("perubahan kebijakan membuat versi baru").
     *
     * @param  array<int, array{type: string, weight: float}>  $components
     */
    protected function activateNextVersion(array $components): GradeConfig
    {
        $manager = app(GradeConfigVersionManager::class);

        $next = $manager->createNextVersion(GradeConfig::query()->latest('version')->firstOrFail(), $this->admin);
        $next->forceFill(['components' => $components])->save();

        return $manager->activate($next);
    }

    public function test_grades_spanning_two_configuration_versions_are_not_scored(): void
    {
        // Kebijakan berganti di tengah semester: Harian sudah dinilai memakai
        // v1 (0.40), UTS & UAS menyusul memakai v2 (0.25 + 0.25). Bobot efektif
        // hanya 0.90 — menghitungnya akan memangkas nilai ~10% diam-diam.
        $this->activeConfig();

        $this->grade(GradeType::Daily, 80);

        $this->activateNextVersion([
            ['type' => GradeType::Daily->value, 'weight' => 0.50],
            ['type' => GradeType::Midterm->value, 'weight' => 0.25],
            ['type' => GradeType::Final->value, 'weight' => 0.25],
        ]);

        $this->grade(GradeType::Midterm, 75);
        $this->grade(GradeType::Final, 85);

        $result = $this->calculator->calculate($this->grades());

        $this->assertFalse($result->isComplete);
        $this->assertNull($result->score);
        $this->assertSame([], $result->missingComponents);
        $this->assertStringContainsString('0.90', (string) $result->reason);
    }

    public function test_reinputting_the_stale_component_restores_a_consistent_score(): void
    {
        // Jalan keluar dari kasus di atas: komponen yang bobotnya sudah tidak
        // berlaku dinilai ulang, sehingga snapshot terbarunya ikut v2.
        $this->activeConfig();

        $this->grade(GradeType::Daily, 60);

        $this->activateNextVersion([
            ['type' => GradeType::Daily->value, 'weight' => 0.50],
            ['type' => GradeType::Midterm->value, 'weight' => 0.25],
            ['type' => GradeType::Final->value, 'weight' => 0.25],
        ]);

        $this->grade(GradeType::Midterm, 80);
        $this->grade(GradeType::Final, 90);
        $this->grade(GradeType::Daily, 100);

        $result = $this->calculator->calculate($this->grades());

        // Harian: rata-rata 60 & 100 = 80 → (80 × 0.50) + (80 × 0.25) + (90 × 0.25)
        $this->assertTrue($result->isComplete);
        $this->assertSame(82.5, $result->score);
    }

    public function test_component_without_a_snapshot_is_not_patched_from_the_configuration(): void
    {
        // Keputusan butir 2: bobot rapor berasal dari snapshot nilai, bukan
        // dari konfigurasi yang kebetulan berlaku saat rapor dibuka.
        $config = $this->activeConfig([['type' => GradeType::Daily->value, 'weight' => 1.0]]);

        $this->grade(GradeType::Daily, 80);
        // Tugas belum menjadi komponen, jadi nilainya tidak mendapat snapshot.
        $this->grade(GradeType::Assignment, 90);

        // Konfigurasi kemudian memasukkan Tugas sebagai komponen berbobot.
        $config->forceFill(['components' => [
            ['type' => GradeType::Daily->value, 'weight' => 0.50],
            ['type' => GradeType::Assignment->value, 'weight' => 0.50],
        ]])->save();

        $result = $this->calculator->calculate($this->grades());

        $this->assertFalse($result->isComplete);
        $this->assertContains(GradeType::Assignment->value, $result->missingComponents);
    }
}
