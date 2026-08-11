<?php

namespace Tests\Feature\Grading;

use App\Enums\GradeType;
use App\Models\Grade;
use App\Models\GradeConfig;
use App\Services\Grading\GradeConfigVersionManager;
use App\Services\Grading\GradeWeightSnapshotter;

/**
 * Keputusan Sprint 4 butir 2:
 *
 *   grade_configs = master/default configuration
 *   grades.weight = SNAPSHOT bobot saat grade dibuat/finalized
 *
 * "Tujuannya agar perubahan Grade Config tidak mengubah histori nilai yang
 *  sudah ada."
 */
class GradeWeightSnapshotTest extends GradingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->teacher);
    }

    public function test_weight_is_snapshotted_from_the_active_configuration(): void
    {
        $config = $this->activeConfig();

        $grade = $this->grade(GradeType::Daily, 80);

        $this->assertSame('0.40', $grade->fresh()->weight);
        $this->assertSame($config->id, $grade->fresh()->grade_config_id);
    }

    public function test_each_component_takes_its_own_weight(): void
    {
        $this->activeConfig();

        $this->assertSame('0.40', $this->grade(GradeType::Daily, 80)->fresh()->weight);
        $this->assertSame('0.30', $this->grade(GradeType::Midterm, 80)->fresh()->weight);
        $this->assertSame('0.30', $this->grade(GradeType::Final, 80)->fresh()->weight);
    }

    public function test_changing_the_configuration_does_not_alter_existing_grades(): void
    {
        // Inti keputusan butir 2.
        $this->activeConfig();

        $old = $this->grade(GradeType::Daily, 80);

        $this->assertSame('0.40', $old->fresh()->weight);

        // Kebijakan bobot berubah lewat versi baru.
        $manager = app(GradeConfigVersionManager::class);
        $newVersion = $manager->createNextVersion($this->activeConfigModel(), $this->admin);
        $newVersion->update(['components' => [
            ['type' => GradeType::Daily->value, 'weight' => 0.50],
            ['type' => GradeType::Midterm->value, 'weight' => 0.25],
            ['type' => GradeType::Final->value, 'weight' => 0.25],
        ]]);
        $manager->activate($newVersion);

        // Nilai lama tidak bergeser sedikit pun.
        $this->assertSame('0.40', $old->fresh()->weight);

        // Nilai baru memakai kebijakan terbaru.
        $new = $this->grade(GradeType::Daily, 90);
        $this->assertSame('0.50', $new->fresh()->weight);
        $this->assertSame($newVersion->id, $new->fresh()->grade_config_id);
    }

    public function test_grade_is_still_accepted_when_no_configuration_is_active(): void
    {
        // Keputusan N-2 — nilai tetap tersimpan, snapshot menyusul.
        $grade = $this->grade(GradeType::Daily, 80);

        $this->assertNull($grade->fresh()->weight);
        $this->assertNull($grade->fresh()->grade_config_id);
    }

    public function test_missing_snapshot_is_backfilled_later(): void
    {
        $grade = $this->grade(GradeType::Daily, 80);

        $this->assertNull($grade->fresh()->weight);

        $config = $this->activeConfig();

        $filled = app(GradeWeightSnapshotter::class)->backfill(
            Grade::query()->forStudent($this->student->id)->get(),
        );

        $this->assertSame(1, $filled);
        $this->assertSame('0.40', $grade->fresh()->weight);
        $this->assertSame($config->id, $grade->fresh()->grade_config_id);
    }

    public function test_attitude_grades_never_receive_a_weight(): void
    {
        $this->activeConfig();

        $grade = $this->grade(GradeType::Attitude, 90);

        $this->assertNull($grade->fresh()->weight);
    }

    public function test_component_outside_the_configuration_gets_no_weight(): void
    {
        // Konfigurasi hanya memuat DAILY; ASSIGNMENT tidak dikonfigurasi.
        $this->activeConfig([['type' => GradeType::Daily->value, 'weight' => 1.0]]);

        $grade = $this->grade(GradeType::Assignment, 80);

        $this->assertNull($grade->fresh()->weight);
    }

    /**
     * Konfigurasi ACTIVE yang sedang berlaku untuk mapel bawaan fixture.
     */
    protected function activeConfigModel(): GradeConfig
    {
        return GradeConfig::activeFor($this->subject->id, $this->year->id);
    }
}
