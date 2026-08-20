<?php

namespace Tests\Feature\Finance;

use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Enums\StudentFeeStatus;
use App\Filament\Resources\StudentFeeResource;
use App\Filament\Resources\StudentFeeResource\Pages\ListStudentFees;
use App\Filament\Resources\StudentFeeResource\Pages\ViewStudentFee;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use App\Services\Finance\PaymentRecorder;
use Database\Seeders\RolePermissionSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SPP-03 poin 1 — form pencatatan pembayaran: "nama siswa, periode, metode
 * bayar, jumlah, tanggal, referensi".
 */
class RecordPaymentUiTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected User $bendahara;

    protected StudentFee $fee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create();
        $this->bendahara = User::factory()->forSchool($this->school)
            ->withRole(RoleName::Bendahara)->create();

        $this->fee = StudentFee::factory()->create([
            'school_id' => $this->school->id,
            'student_id' => Student::factory()->create(['school_id' => $this->school->id])->id,
            'fee_type_id' => FeeType::factory()->create(['school_id' => $this->school->id])->id,
            'amount' => 1_000_000,
        ]);

        $this->actingAs($this->bendahara);
    }

    public function test_recording_a_cash_payment_from_the_list_updates_the_fee(): void
    {
        Livewire::test(ListStudentFees::class)
            ->callTableAction('recordPayment', $this->fee, [
                'payment_method' => PaymentMethod::Cash->value,
                'amount' => 400_000,
                'payment_date' => '2026-08-05',
            ])
            ->assertHasNoTableActionErrors();

        $fee = $this->fee->fresh();

        $this->assertSame('400000.00', (string) $fee->amount_paid);
        $this->assertSame(StudentFeeStatus::Partial, $fee->status);
        $this->assertDatabaseHas('payments', [
            'student_fee_id' => $fee->id,
            'school_id' => $this->school->id,
            'student_id' => $fee->student_id,
            'received_by' => $this->bendahara->id,
            'payment_method' => PaymentMethod::Cash->value,
        ]);
    }

    public function test_recording_a_transfer_payment_from_the_detail_page(): void
    {
        Livewire::test(ViewStudentFee::class, ['record' => $this->fee->getKey()])
            ->callAction('recordPayment', [
                'payment_method' => PaymentMethod::Transfer->value,
                'amount' => 1_000_000,
                'payment_date' => '2026-08-05',
                'reference_number' => 'TRF-99',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(StudentFeeStatus::Paid, $this->fee->fresh()->status);
        $this->assertDatabaseHas('payments', [
            'reference_number' => 'TRF-99',
            'payment_method' => PaymentMethod::Transfer->value,
        ]);
    }

    /**
     * SPP-03 Phase 1 hanya "cash atau transfer"; PAYMENT_GATEWAY tidak boleh
     * dapat dipilih maupun dikirim.
     */
    public function test_payment_gateway_is_not_selectable_and_is_refused_when_crafted(): void
    {
        $methodField = collect(StudentFeeResource::paymentFormSchema($this->fee))
            ->first(fn ($component) => $component instanceof Select
                && $component->getName() === 'payment_method');

        $this->assertArrayNotHasKey(
            PaymentMethod::PaymentGateway->value,
            $methodField->getOptions(),
        );

        Livewire::test(ListStudentFees::class)
            ->callTableAction('recordPayment', $this->fee, [
                'payment_method' => PaymentMethod::PaymentGateway->value,
                'amount' => 400_000,
                'payment_date' => '2026-08-05',
            ])
            ->assertHasTableActionErrors(['payment_method']);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_the_form_rejects_an_amount_above_the_remaining_balance(): void
    {
        Livewire::test(ListStudentFees::class)
            ->callTableAction('recordPayment', $this->fee, [
                'payment_method' => PaymentMethod::Cash->value,
                'amount' => 1_500_000,
                'payment_date' => '2026-08-05',
            ])
            ->assertHasTableActionErrors(['amount']);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_the_form_rejects_a_non_positive_amount(): void
    {
        Livewire::test(ListStudentFees::class)
            ->callTableAction('recordPayment', $this->fee, [
                'payment_method' => PaymentMethod::Cash->value,
                'amount' => 0,
                'payment_date' => '2026-08-05',
            ])
            ->assertHasTableActionErrors(['amount']);

        $this->assertDatabaseCount('payments', 0);
    }

    /**
     * Form hanya mengumpulkan isian; siapa yang mencatat dan untuk siapa tidak
     * pernah berasal darinya, bahkan bila diselundupkan lewat state Livewire.
     */
    public function test_smuggled_identity_fields_in_the_action_payload_are_ignored(): void
    {
        $otherSchool = School::factory()->create();
        $someoneElse = User::factory()->forSchool($otherSchool)->withRole(RoleName::Bendahara)->create();

        Livewire::test(ListStudentFees::class)
            ->callTableAction('recordPayment', $this->fee, [
                'payment_method' => PaymentMethod::Cash->value,
                'amount' => 400_000,
                'payment_date' => '2026-08-05',
                'received_by' => $someoneElse->id,
                'school_id' => $otherSchool->id,
                'student_id' => 999_999,
            ])
            ->assertHasNoTableActionErrors();

        $payment = Payment::query()->sole();

        $this->assertSame($this->bendahara->id, $payment->received_by);
        $this->assertSame($this->school->id, $payment->school_id);
        $this->assertSame($this->fee->student_id, $payment->student_id);
    }

    /**
     * Isian form persis yang disebut SPP-03, ditambah bukti dan catatan yang
     * memang ada di ERD `payments`.
     */
    public function test_the_form_exposes_the_fields_the_story_asks_for(): void
    {
        $names = collect(StudentFeeResource::paymentFormSchema($this->fee))
            ->map(fn ($component) => $component->getName())
            ->all();

        foreach (['payment_method', 'amount', 'payment_date', 'reference_number', 'proof_url', 'notes'] as $field) {
            $this->assertContains($field, $names);
        }
    }

    /**
     * ERD: `reference_number` VARCHAR(100) NULL. SPP-03 menyebutnya sebagai
     * isian form, bukan isian wajib — dan pembayaran tunai memang tidak punya
     * nomor transfer.
     */
    public function test_reference_number_stays_optional_for_both_methods(): void
    {
        Livewire::test(ListStudentFees::class)
            ->callTableAction('recordPayment', $this->fee, [
                'payment_method' => PaymentMethod::Transfer->value,
                'amount' => 400_000,
                'payment_date' => '2026-08-05',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertNull(Payment::query()->sole()->reference_number);
        $this->assertTrue(
            Schema::hasColumn('payments', 'reference_number'),
        );
    }

    public function test_the_action_disappears_once_the_fee_is_settled(): void
    {
        app(PaymentRecorder::class)->record($this->fee->getKey(), [
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => 1_000_000,
            'payment_date' => '2026-08-05',
        ], $this->bendahara);

        Livewire::test(ListStudentFees::class)
            ->assertTableActionHidden('recordPayment', $this->fee->fresh());
    }
}
