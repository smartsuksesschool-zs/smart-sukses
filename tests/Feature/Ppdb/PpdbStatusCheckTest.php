<?php

namespace Tests\Feature\Ppdb;

use App\Enums\PpdbStatus;
use App\Livewire\Ppdb\StatusCheck;
use App\Models\PpdbRegistration;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PPDB-02 — cek status pendaftaran.
 * API 4.7 — GET /ppdb/check-status (Public): "nomor daftar + tanggal lahir".
 */
class PpdbStatusCheckTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected PpdbRegistration $registration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create(['code' => 'MADANI']);

        $this->registration = PpdbRegistration::factory()->create([
            'school_id' => $this->school->id,
            'reg_number' => 'MADANI-2026-0001',
            'full_name' => 'Ahmad Fauzi',
            'birth_date' => '2010-05-17',
            'status' => PpdbStatus::DocumentReview->value,
        ]);
    }

    public function test_status_page_is_reachable_without_login(): void
    {
        // PPDB-02 poin 1 — halaman cek status dapat diakses publik.
        $this->get(route('ppdb.check-status'))->assertSuccessful();
    }

    public function test_correct_reg_number_and_birth_date_reveal_the_current_status(): void
    {
        Livewire::test(StatusCheck::class)
            ->set('regNumber', 'MADANI-2026-0001')
            ->set('birthDate', '2010-05-17')
            ->call('check')
            ->assertHasNoErrors()
            // PPDB-02 poin 2 — tampil status terkini.
            ->assertSet('result.status', PpdbStatus::DocumentReview->value)
            ->assertSee(PpdbStatus::DocumentReview->label())
            ->assertSee('Ahmad Fauzi');
    }

    public function test_reg_number_is_matched_case_insensitively(): void
    {
        Livewire::test(StatusCheck::class)
            ->set('regNumber', ' madani-2026-0001 ')
            ->set('birthDate', '2010-05-17')
            ->call('check')
            ->assertHasNoErrors()
            ->assertSet('result.reg_number', 'MADANI-2026-0001');
    }

    public function test_wrong_birth_date_does_not_reveal_the_registration(): void
    {
        Livewire::test(StatusCheck::class)
            ->set('regNumber', 'MADANI-2026-0001')
            ->set('birthDate', '2011-01-01')
            ->call('check')
            ->assertHasErrors('regNumber')
            ->assertSet('result', null)
            ->assertDontSee('Ahmad Fauzi');
    }

    public function test_unknown_reg_number_does_not_reveal_anything(): void
    {
        Livewire::test(StatusCheck::class)
            ->set('regNumber', 'MADANI-2026-9999')
            ->set('birthDate', '2010-05-17')
            ->call('check')
            ->assertHasErrors('regNumber')
            ->assertSet('result', null);
    }

    public function test_both_fields_are_required(): void
    {
        Livewire::test(StatusCheck::class)
            ->call('check')
            ->assertHasErrors(['regNumber', 'birthDate']);
    }

    public function test_every_status_of_the_ppdb_flow_can_be_reported(): void
    {
        // PPDB-02 poin 2 — REGISTERED, DOCUMENT_REVIEW, PASSED, FAILED, ENROLLED.
        foreach (PpdbStatus::cases() as $status) {
            $this->registration->update(['status' => $status]);

            Livewire::test(StatusCheck::class)
                ->set('regNumber', 'MADANI-2026-0001')
                ->set('birthDate', '2010-05-17')
                ->call('check')
                ->assertSet('result.status', $status->value)
                ->assertSee($status->label());
        }
    }
}
