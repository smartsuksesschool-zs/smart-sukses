<?php

namespace Tests\Feature\Finance;

use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Enums\StudentFeeStatus;
use App\Filament\Resources\StudentFeeResource;
use App\Filament\Resources\StudentFeeResource\Pages\ListStudentFees;
use App\Filament\Resources\StudentFeeResource\Pages\ViewStudentFee;
use App\Models\AuditLog;
use App\Models\FeeType;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use App\Services\Finance\PaymentRecorder;
use App\Services\Finance\StudentFeeWaiver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aksi "Bebaskan Tagihan" pada StudentFeeResource, jejak auditnya, dan tampilan
 * status WAIVED.
 */
class WaiveStudentFeeUiTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected User $admin;

    protected StudentFee $fee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create();
        $this->admin = User::factory()->forSchool($this->school)
            ->withRole(RoleName::SchoolAdmin)->create();

        $this->fee = StudentFee::factory()->create([
            'school_id' => $this->school->id,
            'student_id' => Student::factory()->create(['school_id' => $this->school->id])->id,
            'fee_type_id' => FeeType::factory()->create(['school_id' => $this->school->id])->id,
            'amount' => 1_000_000,
        ]);

        $this->actingAs($this->admin);
    }

    // ------------------------------------------------------------------ UI

    public function test_waiving_from_the_list_updates_the_fee(): void
    {
        Livewire::test(ListStudentFees::class)
            ->callTableAction('waive', $this->fee, ['waive_reason' => 'Beasiswa penuh yayasan'])
            ->assertHasNoTableActionErrors();

        $fee = $this->fee->fresh();

        $this->assertSame(StudentFeeStatus::Waived, $fee->status);
        $this->assertSame('Beasiswa penuh yayasan', $fee->waive_reason);
    }

    public function test_waiving_from_the_detail_page_updates_the_fee(): void
    {
        Livewire::test(ViewStudentFee::class, ['record' => $this->fee->getKey()])
            ->callAction('waive', ['waive_reason' => 'Musibah keluarga'])
            ->assertHasNoActionErrors();

        $this->assertSame(StudentFeeStatus::Waived, $this->fee->fresh()->status);
    }

    public function test_the_form_requires_a_reason(): void
    {
        Livewire::test(ListStudentFees::class)
            ->callTableAction('waive', $this->fee, ['waive_reason' => ''])
            ->assertHasTableActionErrors(['waive_reason']);

        $this->assertSame(StudentFeeStatus::Unpaid, $this->fee->fresh()->status);
    }

    public function test_the_form_caps_the_reason_at_the_erd_column_length(): void
    {
        Livewire::test(ListStudentFees::class)
            ->callTableAction('waive', $this->fee, [
                'waive_reason' => str_repeat('a', StudentFeeWaiver::REASON_MAX_LENGTH + 1),
            ])
            ->assertHasTableActionErrors(['waive_reason']);

        $this->assertNull($this->fee->fresh()->waive_reason);
    }

    /**
     * Statusnya adalah akibat aksi, bukan masukan: form pembebasan hanya punya
     * satu isian.
     */
    public function test_the_waive_form_has_no_status_field(): void
    {
        $names = collect(StudentFeeResource::waiveFormSchema())
            ->map(fn ($component) => $component->getName())
            ->all();

        $this->assertSame(['waive_reason'], $names);
        $this->assertNotContains('status', $names);
        $this->assertNotContains('amount', $names);
        $this->assertNotContains('amount_paid', $names);
    }

    public function test_the_action_is_hidden_for_roles_that_may_not_waive(): void
    {
        foreach ([RoleName::Bendahara, RoleName::KepalaSekolah] as $role) {
            $this->actingAs(User::factory()->forSchool($this->school)->withRole($role)->create());

            $this->assertFalse(StudentFeeResource::canWaive($this->fee));
        }

        $this->actingAs(User::factory()->forSchool($this->school)->withRole(RoleName::Bendahara)->create());

        Livewire::test(ListStudentFees::class)
            ->assertTableActionHidden('waive', $this->fee);
    }

    /**
     * Menyembunyikan aksi bukan proteksinya. Filament sendiri menolak
     * memanggil aksi yang tersembunyi, sehingga yang perlu dibuktikan adalah
     * lapisan di baliknya: memanggil layanannya langsung — persis yang dapat
     * dilakukan request Livewire yang dirakit sendiri — tetap ditolak.
     */
    public function test_a_crafted_call_from_an_unauthorized_role_does_not_bypass_the_policy(): void
    {
        $bendahara = User::factory()->forSchool($this->school)
            ->withRole(RoleName::Bendahara)->create();

        $this->actingAs($bendahara);

        Livewire::test(ListStudentFees::class)
            ->assertTableActionHidden('waive', $this->fee);

        $this->assertThrows(
            fn () => app(StudentFeeWaiver::class)
                ->waive($this->fee->getKey(), 'Coba selundup', $bendahara),
            AuthorizationException::class,
        );

        $fee = $this->fee->fresh();

        $this->assertSame(StudentFeeStatus::Unpaid, $fee->status);
        $this->assertNull($fee->waive_reason);
    }

    public function test_the_action_is_hidden_once_the_fee_is_waived(): void
    {
        app(StudentFeeWaiver::class)->waive($this->fee->getKey(), 'Beasiswa', $this->admin);

        Livewire::test(ListStudentFees::class)
            ->assertTableActionHidden('waive', $this->fee->fresh());

        $this->assertFalse(StudentFeeResource::canWaive($this->fee->fresh()));
    }

    public function test_the_action_is_hidden_once_the_fee_has_received_money(): void
    {
        $bendahara = User::factory()->forSchool($this->school)
            ->withRole(RoleName::Bendahara)->create();

        app(PaymentRecorder::class)->record($this->fee->getKey(), [
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => 400_000,
            'payment_date' => '2026-08-05',
        ], $bendahara);

        Livewire::test(ListStudentFees::class)
            ->assertTableActionHidden('waive', $this->fee->fresh());
    }

    /**
     * Kedua arah guard-nya terlihat di UI: yang dibebaskan tidak dapat dibayar,
     * yang dibayar tidak dapat dibebaskan.
     */
    public function test_a_waived_fee_hides_the_record_payment_action(): void
    {
        app(StudentFeeWaiver::class)->waive($this->fee->getKey(), 'Beasiswa', $this->admin);

        Livewire::test(ListStudentFees::class)
            ->assertTableActionHidden('recordPayment', $this->fee->fresh());
    }

    // -------------------------------------------------------------- display

    public function test_the_list_shows_the_waived_status_label(): void
    {
        app(StudentFeeWaiver::class)->waive($this->fee->getKey(), 'Beasiswa penuh', $this->admin);

        Livewire::test(ListStudentFees::class)
            ->assertCanSeeTableRecords([$this->fee->fresh()])
            ->assertSee(StudentFeeStatus::Waived->label());
    }

    public function test_the_detail_page_shows_the_waive_reason(): void
    {
        app(StudentFeeWaiver::class)->waive($this->fee->getKey(), 'Musibah kebakaran rumah', $this->admin);

        Livewire::test(ViewStudentFee::class, ['record' => $this->fee->getKey()])
            ->assertSee('Alasan Pembebasan')
            ->assertSee('Musibah kebakaran rumah');
    }

    public function test_an_unwaived_fee_hides_the_waiver_section(): void
    {
        Livewire::test(ViewStudentFee::class, ['record' => $this->fee->getKey()])
            ->assertDontSee('Alasan Pembebasan');
    }

    /**
     * Keempat status harus dapat dibedakan operator.
     */
    public function test_every_status_has_a_distinct_label_and_colour(): void
    {
        $labels = array_map(fn (StudentFeeStatus $s) => $s->label(), StudentFeeStatus::cases());

        $this->assertSame($labels, array_unique($labels));
        $this->assertCount(4, StudentFeeStatus::cases());
        $this->assertSame('gray', StudentFeeStatus::Waived->color());
    }

    // ---------------------------------------------------------------- audit

    public function test_a_successful_waiver_writes_an_updated_audit_row(): void
    {
        app(StudentFeeWaiver::class)->waive($this->fee->getKey(), 'Beasiswa', $this->admin);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => StudentFee::class,
            'auditable_id' => $this->fee->id,
            'action' => AuditAction::Updated->value,
            'user_id' => $this->admin->id,
            'school_id' => $this->school->id,
        ]);
    }

    public function test_a_rejected_waiver_writes_no_audit_row(): void
    {
        $before = AuditLog::query()->count();

        try {
            app(StudentFeeWaiver::class)->waive($this->fee->getKey(), '', $this->admin);
        } catch (\Throwable) {
            // diharapkan
        }

        $this->assertSame($before, AuditLog::query()->count());
        $this->assertSame(StudentFeeStatus::Unpaid, $this->fee->fresh()->status);
    }

    // ------------------------------------------------ transaksi & penguncian

    public function test_the_waiver_runs_inside_a_transaction_holding_a_row_lock(): void
    {
        $depth = null;
        $locked = false;

        DB::listen(function ($query) use (&$locked): void {
            if (str_contains($query->sql, 'student_fees') && str_contains(strtolower($query->sql), 'for update')) {
                $locked = true;
            }
        });

        StudentFee::updating(function () use (&$depth): void {
            $depth = DB::transactionLevel();
        });

        app(StudentFeeWaiver::class)->waive($this->fee->getKey(), 'Beasiswa', $this->admin);

        StudentFee::flushEventListeners();
        StudentFee::boot();

        $this->assertNotNull($depth);
        $this->assertGreaterThanOrEqual(1, $depth);

        // SQLite tidak mengenal FOR UPDATE; MySQL adalah target produksinya.
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->assertTrue($locked, 'SELECT tagihan harus memakai FOR UPDATE.');
        } else {
            $this->assertFalse($locked);
        }
    }

    /**
     * Kegagalan setelah baris terkunci tidak meninggalkan pembebasan setengah
     * jadi.
     */
    public function test_a_failure_while_saving_rolls_the_waiver_back(): void
    {
        $before = AuditLog::query()->count();

        StudentFee::updating(function (): void {
            throw new \RuntimeException('gagal menyimpan pembebasan');
        });

        try {
            app(StudentFeeWaiver::class)->waive($this->fee->getKey(), 'Beasiswa', $this->admin);
            $this->fail('Kegagalan penyimpanan seharusnya menggagalkan seluruh operasi.');
        } catch (\RuntimeException $e) {
            $this->assertSame('gagal menyimpan pembebasan', $e->getMessage());
        } finally {
            StudentFee::flushEventListeners();
            StudentFee::boot();
        }

        $fee = $this->fee->fresh();

        $this->assertSame(StudentFeeStatus::Unpaid, $fee->status);
        $this->assertNull($fee->waive_reason);
        $this->assertSame($before, AuditLog::query()->count());
    }

    // ------------------------------------------ tidak ada jalur destruktif

    public function test_waiving_never_deletes_the_fee_or_its_history(): void
    {
        $bendahara = User::factory()->forSchool($this->school)
            ->withRole(RoleName::Bendahara)->create();
        $other = StudentFee::factory()->create([
            'school_id' => $this->school->id,
            'student_id' => Student::factory()->create(['school_id' => $this->school->id])->id,
            'fee_type_id' => FeeType::factory()->create(['school_id' => $this->school->id])->id,
            'amount' => 1_000_000,
        ]);

        app(PaymentRecorder::class)->record($other->getKey(), [
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => 250_000,
            'payment_date' => '2026-08-05',
        ], $bendahara);

        app(StudentFeeWaiver::class)->waive($this->fee->getKey(), 'Beasiswa', $this->admin);

        // Tagihan yang dibebaskan tetap ada, dan pembayaran tagihan lain utuh.
        $this->assertDatabaseCount('student_fees', 2);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('250000.00', (string) $other->fresh()->amount_paid);
    }

    /**
     * Tidak ada refund, kredit, maupun pembalikan yang dibuat sendiri — tidak
     * satu pun dari ketiganya ada di blueprint.
     */
    public function test_waiving_creates_no_reversal_or_credit_record(): void
    {
        app(StudentFeeWaiver::class)->waive($this->fee->getKey(), 'Beasiswa', $this->admin);

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('1000000.00', (string) $this->fee->fresh()->amount);
        $this->assertSame('0.00', (string) $this->fee->fresh()->amount_paid);
    }

    public function test_student_fees_still_have_no_manual_create_edit_or_delete(): void
    {
        $this->assertFalse(StudentFeeResource::canCreate());
        $this->assertSame(['index', 'view'], array_keys(StudentFeeResource::getPages()));
        $this->assertFalse($this->admin->can('delete', $this->fee));

        Livewire::test(ListStudentFees::class)
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');
    }
}
