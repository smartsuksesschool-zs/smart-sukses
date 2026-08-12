<?php

namespace Tests\Feature\Grading;

use App\Enums\AssessmentType;
use App\Enums\AttitudePredicate;
use App\Enums\GradeType;
use App\Filament\Pages\PengaturanPenilaian;
use App\Models\Grade;
use App\Services\Grading\AttitudePredicateResolver;
use App\Services\Grading\ComponentScoreAggregator;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Keputusan Sprint 4 butir 3 — pemetaan sementara:
 * 86-100 = A · 76-85 = B · 66-75 = C · 0-65 = D.
 *
 * "Range attitude JANGAN hard-code. Simpan sebagai configuration agar dapat
 *  diubah Admin."
 */
class AttitudePredicateTest extends GradingTestCase
{
    protected AttitudePredicateResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(AttitudePredicateResolver::class);
    }

    /**
     * @return array<string, array{float, string}>
     */
    public static function scoreProvider(): array
    {
        return [
            'batas atas A' => [100.0, 'A'],
            'batas bawah A' => [86.0, 'A'],
            'batas atas B' => [85.0, 'B'],
            'batas bawah B' => [76.0, 'B'],
            'batas atas C' => [75.0, 'C'],
            'batas bawah C' => [66.0, 'C'],
            'batas atas D' => [65.0, 'D'],
            'batas bawah D' => [0.0, 'D'],
        ];
    }

    #[DataProvider('scoreProvider')]
    public function test_default_mapping_follows_the_decision(float $score, string $expected): void
    {
        $this->assertSame(
            $expected,
            $this->resolver->resolve($score, $this->school)?->value,
        );
    }

    public function test_no_score_yields_no_predicate(): void
    {
        $this->assertNull($this->resolver->resolve(null, $this->school));
    }

    public function test_range_is_not_hard_coded_and_follows_the_school_configuration(): void
    {
        // Inti keputusan: rentang harus dapat diubah Admin.
        $this->assertSame('B', $this->resolver->resolve(80.0, $this->school)?->value);

        $this->school->update(['attitude_scale' => ['A' => 75, 'B' => 60, 'C' => 45, 'D' => 0]]);

        $this->assertSame('A', $this->resolver->resolve(80.0, $this->school->fresh())?->value);
        $this->assertSame('B', $this->resolver->resolve(65.0, $this->school->fresh())?->value);
        $this->assertSame('C', $this->resolver->resolve(50.0, $this->school->fresh())?->value);
        $this->assertSame('D', $this->resolver->resolve(20.0, $this->school->fresh())?->value);
    }

    public function test_school_without_configuration_falls_back_to_the_default_scale(): void
    {
        $this->assertNull($this->school->attitude_scale);
        $this->assertSame(
            AttitudePredicate::defaultScale(),
            $this->resolver->scaleFor($this->school),
        );
    }

    public function test_admin_can_save_a_new_scale_from_the_settings_page(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(PengaturanPenilaian::class)
            ->fillForm([
                'attitude_scale' => [
                    ['predicate' => 'A', 'minimum' => 90],
                    ['predicate' => 'B', 'minimum' => 80],
                    ['predicate' => 'C', 'minimum' => 70],
                    ['predicate' => 'D', 'minimum' => 0],
                ],
            ])
            ->call('save');

        // JSON tidak membedakan 90 dan 90.0, jadi dibandingkan longgar.
        $this->assertEquals(
            ['A' => 90, 'B' => 80, 'C' => 70, 'D' => 0],
            $this->school->fresh()->attitude_scale,
        );

        $this->assertSame('B', $this->resolver->resolve(85.0, $this->school->fresh())?->value);
    }

    public function test_only_school_administrators_may_open_the_settings_page(): void
    {
        $this->actingAs($this->teacher);
        $this->assertFalse(PengaturanPenilaian::canAccess());

        $this->actingAs($this->homeroom);
        $this->assertFalse(PengaturanPenilaian::canAccess());

        $this->actingAs($this->admin);
        $this->assertTrue(PengaturanPenilaian::canAccess());
    }

    public function test_attitude_average_drives_the_report_card_predicate(): void
    {
        $this->actingAs($this->teacher);
        $this->activeConfig();

        $this->grade(GradeType::Attitude, 90);
        $this->grade(GradeType::Attitude, 80);

        // Rata-rata 85 → predikat B pada rentang default.
        $average = app(ComponentScoreAggregator::class)
            ->attitudeAverage(Grade::query()->forStudent($this->student->id)->get());

        $this->assertSame(85.0, $average);
        $this->assertSame('B', $this->resolver->resolve($average, $this->school)?->value);
    }

    public function test_formative_attitude_entries_do_not_move_the_predicate(): void
    {
        // Keputusan butir 3 — "Data harus membedakan formative dan summative."
        // Catatan sikap yang bersifat proses tidak boleh ikut menentukan
        // predikat yang tercetak di rapor.
        $this->actingAs($this->teacher);
        $this->activeConfig();

        $this->grade(GradeType::Attitude, 90);
        $this->grade(GradeType::Attitude, 30, AssessmentType::Formative);

        $average = app(ComponentScoreAggregator::class)
            ->attitudeAverage(Grade::query()->forStudent($this->student->id)->get());

        $this->assertSame(90.0, $average);
        $this->assertSame('A', $this->resolver->resolve($average, $this->school)?->value);
    }

    public function test_a_valid_custom_scale_still_resolves_every_predicate(): void
    {
        // Rentang tetap milik Admin — yang ditegakkan hanya koherensinya.
        $this->school->update(['attitude_scale' => ['A' => 90, 'B' => 80, 'C' => 70, 'D' => 5]]);
        $school = $this->school->fresh();

        $this->assertSame('A', $this->resolver->resolve(100.0, $school)?->value);
        $this->assertSame('A', $this->resolver->resolve(90.0, $school)?->value);
        $this->assertSame('B', $this->resolver->resolve(89.0, $school)?->value);
        $this->assertSame('B', $this->resolver->resolve(80.0, $school)?->value);
        $this->assertSame('C', $this->resolver->resolve(79.0, $school)?->value);
        $this->assertSame('C', $this->resolver->resolve(70.0, $school)?->value);
        $this->assertSame('D', $this->resolver->resolve(69.0, $school)?->value);
        $this->assertSame('D', $this->resolver->resolve(5.0, $school)?->value);
    }

    public function test_a_score_below_the_lowest_threshold_still_yields_d(): void
    {
        $this->school->update(['attitude_scale' => ['A' => 90, 'B' => 80, 'C' => 70, 'D' => 10]]);

        // 4 berada di bawah batas terendah sekalipun; predikat terendah tetap
        // yang berlaku, bukan NULL.
        $this->assertSame('D', $this->resolver->resolve(4.0, $this->school->fresh())?->value);
        $this->assertSame('D', $this->resolver->resolve(0.0, $this->school->fresh())?->value);
    }

    public function test_an_inverted_scale_is_rejected_when_saved(): void
    {
        // Inti perbaikan: A=50 dan B=80 masing-masing sah sebagai angka,
        // tetapi gabungannya membalik predikat.
        $this->actingAs($this->admin);

        Livewire::test(PengaturanPenilaian::class)
            ->fillForm([
                'attitude_scale' => [
                    ['predicate' => 'A', 'minimum' => 50],
                    ['predicate' => 'B', 'minimum' => 80],
                    ['predicate' => 'C', 'minimum' => 66],
                    ['predicate' => 'D', 'minimum' => 0],
                ],
            ])
            ->call('save')
            ->assertHasFormErrors(['attitude_scale']);

        $this->assertNull($this->school->fresh()->attitude_scale);
    }

    public function test_equal_thresholds_are_rejected_when_saved(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(PengaturanPenilaian::class)
            ->fillForm([
                'attitude_scale' => [
                    ['predicate' => 'A', 'minimum' => 80],
                    ['predicate' => 'B', 'minimum' => 80],
                    ['predicate' => 'C', 'minimum' => 66],
                    ['predicate' => 'D', 'minimum' => 0],
                ],
            ])
            ->call('save')
            ->assertHasFormErrors(['attitude_scale']);

        $this->assertNull($this->school->fresh()->attitude_scale);
    }

    public function test_a_scale_outside_zero_to_one_hundred_is_rejected(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(PengaturanPenilaian::class)
            ->fillForm([
                'attitude_scale' => [
                    ['predicate' => 'A', 'minimum' => 120],
                    ['predicate' => 'B', 'minimum' => 80],
                    ['predicate' => 'C', 'minimum' => 66],
                    ['predicate' => 'D', 'minimum' => 0],
                ],
            ])
            ->call('save')
            ->assertHasFormErrors(['attitude_scale']);

        $this->assertNull($this->school->fresh()->attitude_scale);
    }

    public function test_an_incoherent_stored_scale_falls_back_to_the_default(): void
    {
        // Jaring pengaman untuk data yang terlanjur tersimpan sebelum validasi
        // ada, atau yang ditulis langsung ke basis data: lebih baik memakai
        // rentang default daripada mengembalikan predikat yang terbalik.
        $this->school->update(['attitude_scale' => ['A' => 50, 'B' => 80, 'C' => 66, 'D' => 0]]);
        $school = $this->school->fresh();

        $this->assertSame(AttitudePredicate::defaultScale(), $this->resolver->scaleFor($school));
        $this->assertSame('B', $this->resolver->resolve(85.0, $school)?->value);
        $this->assertSame('D', $this->resolver->resolve(60.0, $school)?->value);
    }

    public function test_an_incomplete_stored_scale_falls_back_to_the_default(): void
    {
        $this->school->update(['attitude_scale' => ['A' => 90, 'C' => 70]]);

        $this->assertSame(
            AttitudePredicate::defaultScale(),
            $this->resolver->scaleFor($this->school->fresh()),
        );
    }

    public function test_attitude_without_a_summative_entry_yields_no_predicate(): void
    {
        $this->actingAs($this->teacher);
        $this->activeConfig();

        $this->grade(GradeType::Attitude, 95, AssessmentType::Formative);

        $average = app(ComponentScoreAggregator::class)
            ->attitudeAverage(Grade::query()->forStudent($this->student->id)->get());

        $this->assertNull($average);
        $this->assertNull($this->resolver->resolve($average, $this->school));
    }
}
