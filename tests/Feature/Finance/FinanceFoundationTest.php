<?php

namespace Tests\Feature\Finance;

use App\Enums\FeeFrequency;
use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Enums\StudentFeeStatus;
use App\Models\AcademicYear;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ERD 2.2 (Keuangan) — skema fondasi `fee_types`, `student_fees`, `payments`.
 *
 * Batch 5.1 hanya membangun skema dan modelnya; alur SPP-02/03/04 belum ada.
 */
class FinanceFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_fee_types_table_matches_the_erd(): void
    {
        $this->assertSame([
            'id',
            'school_id',
            'name',
            'amount',
            'frequency',
            'academic_year_id',
            'description',
            'is_active',
            'created_at',
        ], Schema::getColumnListing('fee_types'));
    }

    /**
     * ERD mencantumkan `created_at` saja untuk fee_types dan payments —
     * preseden tabel tanpa `updated_at` sudah ada pada `subjects`.
     */
    public function test_fee_types_and_payments_have_no_updated_at(): void
    {
        $this->assertFalse(Schema::hasColumn('fee_types', 'updated_at'));
        $this->assertFalse(Schema::hasColumn('payments', 'updated_at'));
        $this->assertTrue(Schema::hasColumn('student_fees', 'updated_at'));
    }

    /**
     * Histori tidak boleh hilang: tidak satu pun tabel keuangan memakai
     * soft delete (SPP-01 poin 2 menuntut penonaktifan, bukan penghapusan).
     */
    public function test_finance_tables_have_no_soft_deletes(): void
    {
        foreach (['fee_types', 'student_fees', 'payments'] as $table) {
            $this->assertFalse(
                Schema::hasColumn($table, 'deleted_at'),
                "Tabel {$table} seharusnya tidak memiliki deleted_at.",
            );
        }
    }

    public function test_student_fees_table_matches_the_erd(): void
    {
        $this->assertSame([
            'id',
            'school_id',
            'student_id',
            'fee_type_id',
            'academic_year_id',
            'amount',
            'amount_paid',
            'due_date',
            'period',
            'status',
            'waive_reason',
            'created_at',
            'updated_at',
        ], Schema::getColumnListing('student_fees'));
    }

    public function test_payments_table_matches_the_erd(): void
    {
        $this->assertSame([
            'id',
            'school_id',
            'student_fee_id',
            'student_id',
            'payment_method',
            'amount_paid',
            'reference_number',
            'proof_url',
            'payment_date',
            'received_by',
            'notes',
            'created_at',
        ], Schema::getColumnListing('payments'));
    }

    public function test_enums_carry_exactly_the_erd_values(): void
    {
        $this->assertSame(['MONTHLY', 'YEARLY', 'ONCE'], FeeFrequency::values());
        $this->assertSame(['UNPAID', 'PARTIAL', 'PAID', 'WAIVED'], StudentFeeStatus::values());
        $this->assertSame(['CASH', 'TRANSFER', 'PAYMENT_GATEWAY'], PaymentMethod::values());
    }

    public function test_student_fee_defaults_follow_the_erd(): void
    {
        $school = School::factory()->create();
        $student = Student::factory()->create(['school_id' => $school->id]);
        $feeType = FeeType::factory()->create(['school_id' => $school->id]);

        $fee = StudentFee::query()->create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'fee_type_id' => $feeType->id,
            'amount' => 150000,
            'due_date' => '2026-08-10',
            'period' => '2026-08',
        ]);

        // "amount_paid ... Default: 0.00" dan "status ... Default: UNPAID".
        $this->assertSame('0.00', $fee->fresh()->amount_paid);
        $this->assertSame(StudentFeeStatus::Unpaid, $fee->fresh()->status);
    }

    public function test_fee_type_amount_is_cast_as_decimal(): void
    {
        $feeType = FeeType::factory()->create(['amount' => 150000]);

        $this->assertSame('150000.00', $feeType->fresh()->amount);
        $this->assertInstanceOf(FeeFrequency::class, $feeType->frequency);
    }

    /**
     * ERD: fee_types.academic_year_id "NULL untuk tagihan berulang".
     */
    public function test_fee_type_academic_year_is_optional(): void
    {
        $feeType = FeeType::factory()->create(['academic_year_id' => null]);

        $this->assertNull($feeType->fresh()->academic_year_id);
        $this->assertNull($feeType->academicYear);
    }

    public function test_core_relationships_are_wired(): void
    {
        $school = School::factory()->create();
        $year = AcademicYear::factory()->create(['school_id' => $school->id]);
        $student = Student::factory()->create(['school_id' => $school->id]);
        $bendahara = User::factory()->forSchool($school)->withRole(RoleName::Bendahara)->create();

        $feeType = FeeType::factory()->create([
            'school_id' => $school->id,
            'academic_year_id' => $year->id,
        ]);

        $fee = StudentFee::factory()->create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'fee_type_id' => $feeType->id,
            'academic_year_id' => $year->id,
        ]);

        $payment = Payment::factory()->create([
            'school_id' => $school->id,
            'student_fee_id' => $fee->id,
            'student_id' => $student->id,
            'received_by' => $bendahara->id,
        ]);

        $this->assertTrue($feeType->academicYear->is($year));
        $this->assertTrue($feeType->studentFees->first()->is($fee));

        $this->assertTrue($fee->feeType->is($feeType));
        $this->assertTrue($fee->student->is($student));
        $this->assertTrue($fee->academicYear->is($year));
        // "Satu tagihan dapat memiliki beberapa pembayaran (cicilan)."
        $this->assertTrue($fee->payments->first()->is($payment));

        $this->assertTrue($payment->studentFee->is($fee));
        $this->assertTrue($payment->student->is($student));
        $this->assertTrue($payment->receivedBy->is($bendahara));

        $this->assertTrue($student->studentFees->first()->is($fee));
        $this->assertTrue($student->payments->first()->is($payment));
        $this->assertTrue($year->feeTypes->first()->is($feeType));
        $this->assertTrue($year->studentFees->first()->is($fee));
    }

    /**
     * Arsitektur 3.2 — global scope `school_id` berlaku untuk seluruh model
     * bisnis, termasuk ketiga tabel keuangan.
     */
    public function test_finance_models_are_tenant_scoped(): void
    {
        $schoolA = School::factory()->create();
        $schoolB = School::factory()->create();

        $mine = FeeType::factory()->create(['school_id' => $schoolA->id]);
        $foreign = FeeType::factory()->create(['school_id' => $schoolB->id]);

        $studentA = Student::factory()->create(['school_id' => $schoolA->id]);
        $studentB = Student::factory()->create(['school_id' => $schoolB->id]);
        $bendaharaB = User::factory()->forSchool($schoolB)->withRole(RoleName::Bendahara)->create();

        $myFee = StudentFee::factory()->create([
            'school_id' => $schoolA->id,
            'student_id' => $studentA->id,
            'fee_type_id' => $mine->id,
        ]);

        $foreignFee = StudentFee::factory()->create([
            'school_id' => $schoolB->id,
            'student_id' => $studentB->id,
            'fee_type_id' => $foreign->id,
        ]);

        Payment::factory()->create([
            'school_id' => $schoolB->id,
            'student_fee_id' => $foreignFee->id,
            'student_id' => $studentB->id,
            'received_by' => $bendaharaB->id,
        ]);

        $this->actingAs(User::factory()->forSchool($schoolA)->withRole(RoleName::Bendahara)->create());

        $this->assertSame([$mine->id], FeeType::query()->pluck('id')->all());
        $this->assertSame([$myFee->id], StudentFee::query()->pluck('id')->all());
        $this->assertSame([], Payment::query()->pluck('id')->all());

        // Bahkan pencarian by-id tidak menembus batas cabang.
        $this->assertNull(FeeType::query()->find($foreign->id));
        $this->assertNull(StudentFee::query()->find($foreignFee->id));
    }

    /**
     * Peran School Level tidak dapat menulis ke cabang lain: `school_id` yang
     * kosong diisi dari konteks tenant, bukan dari pemanggil.
     */
    public function test_school_id_is_filled_from_the_tenant_context(): void
    {
        $school = School::factory()->create();

        $this->actingAs(User::factory()->forSchool($school)->withRole(RoleName::Bendahara)->create());

        $feeType = FeeType::query()->create([
            'name' => 'SPP',
            'amount' => 150000,
            'frequency' => FeeFrequency::Monthly->value,
        ]);

        $this->assertSame($school->id, $feeType->school_id);
    }
}
