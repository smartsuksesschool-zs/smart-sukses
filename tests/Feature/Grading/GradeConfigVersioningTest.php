<?php

namespace Tests\Feature\Grading;

use App\Enums\GradeConfigStatus;
use App\Enums\GradeType;
use App\Enums\RoleName;
use App\Filament\Resources\GradeConfigResource\Pages\CreateGradeConfig;
use App\Filament\Resources\GradeConfigResource\Pages\ListGradeConfigs;
use App\Models\AcademicYear;
use App\Models\GradeConfig;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use App\Services\Grading\GradeConfigVersionManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Keputusan Sprint 4 butir 4 — DRAFT → ACTIVE → LOCKED, dan perubahan
 * kebijakan bobot membuat versi baru alih-alih menimpa konfigurasi lama.
 */
class GradeConfigVersioningTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected User $admin;

    protected Subject $subject;

    protected AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create();
        $this->admin = User::factory()->forSchool($this->school)->withRole(RoleName::SchoolAdmin)->create();
        $this->subject = Subject::factory()->create(['school_id' => $this->school->id, 'code' => 'MTK']);
        $this->year = AcademicYear::factory()->create(['school_id' => $this->school->id, 'is_active' => true]);

        $this->actingAs($this->admin);
    }

    protected function config(GradeConfigStatus $status = GradeConfigStatus::Draft, int $version = 1): GradeConfig
    {
        return GradeConfig::factory()->status($status)->create([
            'school_id' => $this->school->id,
            'subject_id' => $this->subject->id,
            'academic_year_id' => $this->year->id,
            'created_by' => $this->admin->id,
            'version' => $version,
        ]);
    }

    public function test_new_configuration_starts_as_draft_with_version_one(): void
    {
        Livewire::test(CreateGradeConfig::class)
            ->fillForm([
                'subject_id' => $this->subject->id,
                'academic_year_id' => $this->year->id,
                'components' => [
                    ['type' => GradeType::Daily->value, 'weight' => 0.40],
                    ['type' => GradeType::Midterm->value, 'weight' => 0.30],
                    ['type' => GradeType::Final->value, 'weight' => 0.30],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $config = GradeConfig::query()->firstOrFail();

        $this->assertSame(GradeConfigStatus::Draft, $config->status);
        $this->assertSame(1, $config->version);
        $this->assertSame($this->admin->id, $config->created_by);
    }

    /**
     * Super Admin adalah peran Platform Level dengan school_id = NULL
     * (SchoolScope::currentSchoolId() sengaja melepas scope untuk mereka),
     * sehingga cabang konfigurasi harus datang dari form.
     */
    public function test_super_admin_creates_configuration_for_the_chosen_school(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(CreateGradeConfig::class)
            ->fillForm([
                'school_id' => $this->school->id,
                'subject_id' => $this->subject->id,
                'academic_year_id' => $this->year->id,
                'components' => [
                    ['type' => GradeType::Daily->value, 'weight' => 0.40],
                    ['type' => GradeType::Midterm->value, 'weight' => 0.30],
                    ['type' => GradeType::Final->value, 'weight' => 0.30],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $config = GradeConfig::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame($this->school->id, $config->school_id);
        $this->assertSame(GradeConfigStatus::Draft, $config->status);
        $this->assertSame(1, $config->version);
        $this->assertNotNull($config->created_by);
    }

    public function test_super_admin_must_choose_a_school(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(CreateGradeConfig::class)
            ->fillForm([
                'subject_id' => $this->subject->id,
                'academic_year_id' => $this->year->id,
                'components' => [
                    ['type' => GradeType::Daily->value, 'weight' => 0.40],
                    ['type' => GradeType::Midterm->value, 'weight' => 0.30],
                    ['type' => GradeType::Final->value, 'weight' => 0.30],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['school_id']);

        $this->assertSame(0, GradeConfig::query()->withoutGlobalScopes()->count());
    }

    /**
     * Global scope tidak membatasi apa pun bagi Super Admin, jadi tanpa
     * penyaringan per cabang mereka dapat memasangkan mapel cabang lain dengan
     * cabang yang sedang dipilih.
     */
    public function test_super_admin_cannot_pair_a_subject_from_another_school(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $otherSchool = School::factory()->create();
        $otherSubject = Subject::factory()->create(['school_id' => $otherSchool->id, 'code' => 'BIN']);

        Livewire::test(CreateGradeConfig::class)
            ->fillForm([
                'school_id' => $this->school->id,
                'subject_id' => $otherSubject->id,
                'academic_year_id' => $this->year->id,
                'components' => [
                    ['type' => GradeType::Daily->value, 'weight' => 0.40],
                    ['type' => GradeType::Midterm->value, 'weight' => 0.30],
                    ['type' => GradeType::Final->value, 'weight' => 0.30],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['subject_id']);

        $this->assertSame(0, GradeConfig::query()->withoutGlobalScopes()->count());
    }

    public function test_super_admin_cannot_pair_an_academic_year_from_another_school(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $otherSchool = School::factory()->create();
        $otherYear = AcademicYear::factory()->create(['school_id' => $otherSchool->id]);

        Livewire::test(CreateGradeConfig::class)
            ->fillForm([
                'school_id' => $this->school->id,
                'subject_id' => $this->subject->id,
                'academic_year_id' => $otherYear->id,
                'components' => [
                    ['type' => GradeType::Daily->value, 'weight' => 0.40],
                    ['type' => GradeType::Midterm->value, 'weight' => 0.30],
                    ['type' => GradeType::Final->value, 'weight' => 0.30],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['academic_year_id']);

        $this->assertSame(0, GradeConfig::query()->withoutGlobalScopes()->count());
    }

    /**
     * Field cabang tidak dirender untuk Admin Sekolah, sehingga nilai yang
     * diselundupkan ke state Livewire tidak boleh ikut tersimpan.
     */
    public function test_school_admin_cannot_create_configuration_for_another_school(): void
    {
        $otherSchool = School::factory()->create();

        Livewire::test(CreateGradeConfig::class)
            // Diselundupkan lebih dulu; field ini tidak dirender untuk Admin
            // Sekolah, sehingga hanya berupa kunci liar pada state Livewire.
            ->set('data.school_id', $otherSchool->id)
            ->fillForm([
                'subject_id' => $this->subject->id,
                'academic_year_id' => $this->year->id,
                'components' => [
                    ['type' => GradeType::Daily->value, 'weight' => 0.40],
                    ['type' => GradeType::Midterm->value, 'weight' => 0.30],
                    ['type' => GradeType::Final->value, 'weight' => 0.30],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $config = GradeConfig::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame($this->school->id, $config->school_id);
        $this->assertNotSame($otherSchool->id, $config->school_id);
    }

    public function test_component_weights_must_total_one(): void
    {
        Livewire::test(CreateGradeConfig::class)
            ->fillForm([
                'subject_id' => $this->subject->id,
                'academic_year_id' => $this->year->id,
                'components' => [
                    ['type' => GradeType::Daily->value, 'weight' => 0.40],
                    ['type' => GradeType::Midterm->value, 'weight' => 0.30],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['components']);
    }

    public function test_attitude_cannot_be_a_weighted_component(): void
    {
        // Keputusan butir 3: sikap TIDAK masuk nilai akademik.
        $this->expectException(ValidationException::class);

        app(GradeConfigVersionManager::class)->validateComponents([
            ['type' => GradeType::Attitude->value, 'weight' => 1.0],
        ]);
    }

    public function test_duplicate_component_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        app(GradeConfigVersionManager::class)->validateComponents([
            ['type' => GradeType::Daily->value, 'weight' => 0.50],
            ['type' => GradeType::Daily->value, 'weight' => 0.50],
        ]);
    }

    public function test_draft_can_be_edited_but_active_and_locked_cannot(): void
    {
        $draft = $this->config(GradeConfigStatus::Draft);
        $active = $this->config(GradeConfigStatus::Active, 2);
        $locked = $this->config(GradeConfigStatus::Locked, 3);

        $this->assertTrue($this->admin->can('update', $draft));
        $this->assertFalse($this->admin->can('update', $active));
        $this->assertFalse($this->admin->can('update', $locked));
    }

    public function test_activating_a_draft_locks_the_previous_active_version(): void
    {
        $previous = $this->config(GradeConfigStatus::Active, 1);
        $draft = $this->config(GradeConfigStatus::Draft, 2);

        Livewire::test(ListGradeConfigs::class)
            ->callTableAction('activate', $draft);

        $this->assertSame(GradeConfigStatus::Active, $draft->fresh()->status);
        $this->assertSame(GradeConfigStatus::Locked, $previous->fresh()->status);
        $this->assertNotNull($draft->fresh()->activated_at);
    }

    public function test_only_one_active_configuration_exists_per_subject_and_year(): void
    {
        $this->config(GradeConfigStatus::Active, 1);
        $second = $this->config(GradeConfigStatus::Draft, 2);

        app(GradeConfigVersionManager::class)->activate($second);

        $this->assertSame(1, GradeConfig::query()
            ->forSubjectYear($this->subject->id, $this->year->id)
            ->active()
            ->count());
    }

    public function test_active_configuration_can_be_locked(): void
    {
        $active = $this->config(GradeConfigStatus::Active);

        Livewire::test(ListGradeConfigs::class)
            ->callTableAction('lock', $active);

        $this->assertSame(GradeConfigStatus::Locked, $active->fresh()->status);
        $this->assertNotNull($active->fresh()->locked_at);
    }

    public function test_a_draft_cannot_be_locked_directly(): void
    {
        $draft = $this->config(GradeConfigStatus::Draft);

        $this->assertFalse($this->admin->can('lock', $draft));

        Livewire::test(ListGradeConfigs::class)
            ->assertTableActionHidden('lock', $draft);
    }

    public function test_creating_a_new_version_leaves_the_old_one_untouched(): void
    {
        // "Perubahan kebijakan bobot harus membuat Grade Config versi baru,
        //  bukan overwrite konfigurasi lama."
        $active = $this->config(GradeConfigStatus::Active, 1);

        Livewire::test(ListGradeConfigs::class)
            ->callTableAction('createVersion', $active);

        $versions = GradeConfig::query()
            ->forSubjectYear($this->subject->id, $this->year->id)
            ->orderBy('version')
            ->get();

        $this->assertCount(2, $versions);
        $this->assertSame(GradeConfigStatus::Active, $versions[0]->status);
        $this->assertSame(GradeConfigStatus::Draft, $versions[1]->status);
        $this->assertSame(2, $versions[1]->version);
        $this->assertSame($active->components, $versions[1]->components);
    }

    public function test_version_number_is_unique_per_subject_and_year(): void
    {
        $this->config(GradeConfigStatus::Active, 1);

        $next = app(GradeConfigVersionManager::class)
            ->nextVersionNumber($this->school->id, $this->subject->id, $this->year->id);

        $this->assertSame(2, $next);
    }

    public function test_configurations_are_never_deleted(): void
    {
        $config = $this->config();

        $this->assertFalse($this->admin->can('delete', $config));
    }
}
