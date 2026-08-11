<?php

namespace Tests\Feature\Ppdb;

use App\Enums\PpdbStatus;
use App\Enums\RoleName;
use App\Filament\Resources\PpdbRegistrationResource;
use App\Filament\Resources\PpdbRegistrationResource\Pages\ListPpdbRegistrations;
use App\Models\AcademicYear;
use App\Models\PpdbRegistration;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PPDB-03 — Admin melihat semua pendaftar, memfilter status, memperbarui status.
 * API 4.7 — GET /admin/ppdb, GET /admin/ppdb/{id}, PATCH /admin/ppdb/{id}/status.
 */
class PpdbAdminReviewTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create(['code' => 'MADANI']);
        $this->admin = User::factory()->forSchool($this->school)->withRole(RoleName::SchoolAdmin)->create();

        $this->actingAs($this->admin);
    }

    public function test_admin_sees_the_registrant_list(): void
    {
        $registrations = PpdbRegistration::factory()->count(3)->create(['school_id' => $this->school->id]);

        $this->get(PpdbRegistrationResource::getUrl('index'))->assertSuccessful();

        Livewire::test(ListPpdbRegistrations::class)
            ->assertCanSeeTableRecords($registrations)
            // PPDB-03 poin 1 — kolom: no. daftar, nama, asal sekolah, status, tanggal daftar.
            ->assertCanRenderTableColumn('reg_number')
            ->assertCanRenderTableColumn('full_name')
            ->assertCanRenderTableColumn('origin_school')
            ->assertCanRenderTableColumn('status')
            ->assertCanRenderTableColumn('registered_at');
    }

    public function test_detail_page_is_reachable(): void
    {
        // API 4.7 — GET /admin/ppdb/{id}.
        $registration = PpdbRegistration::factory()->create(['school_id' => $this->school->id]);

        $this->get(PpdbRegistrationResource::getUrl('view', ['record' => $registration]))
            ->assertSuccessful()
            ->assertSee($registration->reg_number);
    }

    public function test_list_can_be_filtered_by_status(): void
    {
        // PPDB-03 poin 2 — filter per status tersedia.
        $passed = PpdbRegistration::factory()->passed()->create(['school_id' => $this->school->id]);
        $failed = PpdbRegistration::factory()->status(PpdbStatus::Failed)->create(['school_id' => $this->school->id]);

        Livewire::test(ListPpdbRegistrations::class)
            ->filterTable('status', PpdbStatus::Passed->value)
            ->assertCanSeeTableRecords([$passed])
            ->assertCanNotSeeTableRecords([$failed]);
    }

    public function test_list_can_be_filtered_by_academic_year(): void
    {
        // API 4.7 — GET /admin/ppdb. Filter: status, academic_year_id.
        $yearA = AcademicYear::factory()->create(['school_id' => $this->school->id]);
        $yearB = AcademicYear::factory()->create(['school_id' => $this->school->id]);

        $inA = PpdbRegistration::factory()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $yearA->id,
        ]);
        $inB = PpdbRegistration::factory()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $yearB->id,
        ]);

        Livewire::test(ListPpdbRegistrations::class)
            ->filterTable('academic_year_id', $yearA->id)
            ->assertCanSeeTableRecords([$inA])
            ->assertCanNotSeeTableRecords([$inB]);
    }

    public function test_status_change_is_saved_together_with_the_reason(): void
    {
        // PPDB-03 poin 3 — perubahan status disimpan dengan catatan alasan.
        $registration = PpdbRegistration::factory()->create(['school_id' => $this->school->id]);

        Livewire::test(ListPpdbRegistrations::class)
            ->callTableAction('changeStatus', $registration, [
                'status' => PpdbStatus::Passed->value,
                'status_notes' => 'Nilai rapor memenuhi passing grade.',
            ])
            ->assertHasNoTableActionErrors();

        $registration->refresh();

        $this->assertSame(PpdbStatus::Passed, $registration->status);
        $this->assertSame('Nilai rapor memenuhi passing grade.', $registration->status_notes);
    }

    public function test_status_change_requires_a_reason(): void
    {
        $registration = PpdbRegistration::factory()->create(['school_id' => $this->school->id]);

        Livewire::test(ListPpdbRegistrations::class)
            ->callTableAction('changeStatus', $registration, [
                'status' => PpdbStatus::Failed->value,
                'status_notes' => '',
            ])
            ->assertHasTableActionErrors(['status_notes']);

        $this->assertSame(PpdbStatus::Registered, $registration->fresh()->status);
    }

    public function test_registrations_are_never_deleted(): void
    {
        // API 4.7 tidak menyediakan endpoint hapus pendaftar.
        $registration = PpdbRegistration::factory()->create(['school_id' => $this->school->id]);

        $this->assertFalse($this->admin->can('delete', $registration));
        $this->assertFalse($this->admin->can('create', PpdbRegistration::class));
    }
}
