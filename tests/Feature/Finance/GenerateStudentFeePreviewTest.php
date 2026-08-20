<?php

namespace Tests\Feature\Finance;

use App\Enums\RoleName;
use App\Enums\StudentStatus;
use App\Filament\Pages\GenerateTagihan;
use App\Jobs\GenerateStudentFees;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\FeeType;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SPP-02 poin 3 — "Preview daftar tagihan ditampilkan sebelum konfirmasi
 * generate", dan poin 2 — due date otomatis terisi.
 */
class GenerateStudentFeePreviewTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected User $bendahara;

    protected FeeType $feeType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create();
        $this->bendahara = User::factory()->forSchool($this->school)->withRole(RoleName::Bendahara)->create();

        AcademicYear::factory()->create(['school_id' => $this->school->id, 'is_active' => true]);

        $this->feeType = FeeType::factory()->create([
            'school_id' => $this->school->id,
            'name' => 'SPP',
            'amount' => 150000,
        ]);

        $this->actingAs($this->bendahara);
    }

    protected function student(string $name, StudentStatus $status = StudentStatus::Active, ?School $school = null): Student
    {
        return Student::factory()->create([
            'school_id' => ($school ?? $this->school)->id,
            'full_name' => $name,
            'status' => $status->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function page(array $overrides = []): Testable
    {
        return Livewire::test(GenerateTagihan::class)
            ->fillForm(array_merge([
                'fee_type_id' => $this->feeType->id,
                'period' => '2026-08',
                'due_date' => '2026-08-10',
            ], $overrides));
    }

    public function test_preview_lists_every_active_student(): void
    {
        $this->student('Ahmad');
        $this->student('Budi');

        $component = $this->page()->call('preview');

        $preview = $component->get('preview');

        $this->assertSame(2, $preview['active_count']);
        $this->assertSame(2, $preview['target_count']);
        $this->assertSame(0, $preview['skipped_count']);
        $this->assertCount(2, $preview['targets']);
        $this->assertStringContainsString('Ahmad', implode(' ', $preview['targets']));
        $this->assertStringContainsString('Budi', implode(' ', $preview['targets']));
    }

    /**
     * @return array<string, array{StudentStatus}>
     */
    public static function inactiveStatuses(): array
    {
        return [
            'lulus' => [StudentStatus::Graduated],
            'keluar' => [StudentStatus::DroppedOut],
            'pindah' => [StudentStatus::Transferred],
        ];
    }

    /**
     * SPP-02 poin 1 — "setiap siswa aktif". Status lain tidak ditagih.
     */
    #[DataProvider('inactiveStatuses')]
    public function test_non_active_students_are_excluded(StudentStatus $status): void
    {
        $this->student('Ahmad');
        $this->student('Bukan Target', $status);

        $preview = $this->page()->call('preview')->get('preview');

        $this->assertSame(1, $preview['active_count']);
        $this->assertSame(1, $preview['target_count']);
        $this->assertStringNotContainsString('Bukan Target', implode(' ', $preview['targets']));
    }

    public function test_preview_creates_nothing(): void
    {
        $this->student('Ahmad');
        $this->student('Budi');

        Queue::fake();

        $this->page()->call('preview');

        $this->assertSame(0, StudentFee::query()->withoutGlobalScopes()->count());
        // Data induk yang dibuat factory tetap terekam audit; yang harus nihil
        // adalah jejak tagihan, karena pratinjau memang tidak menerbitkan apa pun.
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()
            ->where('auditable_type', StudentFee::class)
            ->count());
        Queue::assertNothingPushed();
    }

    public function test_preview_separates_students_that_already_have_the_bill(): void
    {
        $ahmad = $this->student('Ahmad');
        $this->student('Budi');

        StudentFee::factory()->create([
            'school_id' => $this->school->id,
            'student_id' => $ahmad->id,
            'fee_type_id' => $this->feeType->id,
            'period' => '2026-08',
        ]);

        $preview = $this->page()->call('preview')->get('preview');

        $this->assertSame(2, $preview['active_count']);
        $this->assertSame(1, $preview['target_count']);
        $this->assertSame(1, $preview['skipped_count']);
        $this->assertStringContainsString('Ahmad', implode(' ', $preview['skipped']));
        $this->assertStringContainsString('Budi', implode(' ', $preview['targets']));
    }

    /**
     * Gerbang SPP-02 poin 3: tanpa pratinjau, tidak ada penerbitan.
     */
    public function test_generate_is_refused_before_a_preview(): void
    {
        $this->student('Ahmad');

        Queue::fake();

        $this->page()->call('generate');

        Queue::assertNothingPushed();
    }

    /**
     * Pratinjau menjadi basi begitu isian berubah — yang dikonfirmasi harus
     * persis yang dilihat.
     */
    public function test_changing_the_form_invalidates_the_preview(): void
    {
        $this->student('Ahmad');

        Queue::fake();

        $component = $this->page()->call('preview');

        $this->assertTrue($component->instance()->hasFreshPreview());

        $component->set('data.period', '2026-09');

        $this->assertFalse($component->instance()->hasFreshPreview());

        $component->call('generate');

        Queue::assertNothingPushed();
    }

    public function test_changing_only_the_due_date_also_invalidates_the_preview(): void
    {
        $this->student('Ahmad');

        Queue::fake();

        $this->page()
            ->call('preview')
            ->set('data.due_date', '2026-08-25')
            ->call('generate');

        Queue::assertNothingPushed();
    }

    /**
     * SPP-02 poin 2 — "due date otomatis diisi (misal: tanggal 10 bulan
     * berjalan)". Yang dipakai adalah bulan periode, bukan bulan berjalan.
     */
    public function test_default_due_date_follows_the_chosen_period(): void
    {
        $this->assertSame('2027-03-10', GenerateTagihan::defaultDueDate('2027-03'));
        $this->assertNull(GenerateTagihan::defaultDueDate('2027-13'));
        $this->assertNull(GenerateTagihan::defaultDueDate('bukan-periode'));

        // State mentah DatePicker menyertakan jam (lihat GenerateTagihan::
        // normalizedDueDate), sehingga yang diperiksa adalah bagian tanggalnya.
        $component = Livewire::test(GenerateTagihan::class);

        $this->assertStringStartsWith(now()->format('Y-m').'-10', (string) $component->get('data.due_date'));

        $component->set('data.period', '2027-03');

        $this->assertStringStartsWith('2027-03-10', (string) $component->get('data.due_date'));
    }

    public function test_due_date_may_be_overridden_by_the_operator(): void
    {
        $this->student('Ahmad');

        Queue::fake();

        $this->page(['due_date' => '2026-08-25'])
            ->call('preview')
            ->call('generate');

        Queue::assertPushed(
            GenerateStudentFees::class,
            fn (GenerateStudentFees $job) => $job->dueDate === '2026-08-25',
        );
    }

    public function test_period_must_be_formatted_as_year_month(): void
    {
        $this->student('Ahmad');

        $this->page(['period' => '2026-8'])
            ->call('preview')
            ->assertHasFormErrors(['period']);
    }

    /**
     * Tech stack 3.1 — penerbitan massal berjalan di antrean, bukan di dalam
     * request.
     */
    public function test_confirming_dispatches_the_queue_job(): void
    {
        $this->student('Ahmad');
        $this->student('Budi');

        Queue::fake();

        $this->page()
            ->call('preview')
            ->call('generate');

        Queue::assertPushed(
            GenerateStudentFees::class,
            fn (GenerateStudentFees $job) => $job->schoolId === $this->school->id
                && $job->feeTypeId === $this->feeType->id
                && $job->period === '2026-08'
                && $job->dueDate === '2026-08-10',
        );
        Queue::assertPushed(GenerateStudentFees::class, 1);

        // Request tidak menulis satu tagihan pun; itu pekerjaan worker.
        $this->assertSame(0, StudentFee::query()->withoutGlobalScopes()->count());
    }

    /**
     * Setelah dispatch, pratinjau dilupakan: klik kedua harus melewati
     * pratinjau lagi.
     */
    public function test_a_second_click_requires_a_fresh_preview(): void
    {
        $this->student('Ahmad');

        Queue::fake();

        $this->page()
            ->call('preview')
            ->call('generate')
            ->call('generate');

        Queue::assertPushed(GenerateStudentFees::class, 1);
    }
}
