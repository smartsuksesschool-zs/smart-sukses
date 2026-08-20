<?php

namespace Tests\Feature\Finance;

use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Filament\Resources\StudentFeeResource;
use App\Filament\Resources\StudentFeeResource\Pages\ListStudentFees;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use App\Services\Finance\PaymentRecorder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PRD 1.1.2 memisahkan "Tagihan SPP" dari "Catat Pembayaran". Pada baris kedua
 * KEPALA = ❌, bukan ⭕: kepala sekolah melihat tagihan tetapi tidak boleh
 * menyentuh pembayarannya.
 */
class RecordPaymentRbacTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected StudentFee $feeA;

    protected StudentFee $feeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create();
        $this->schoolB = School::factory()->create();

        $this->feeA = $this->makeFee($this->schoolA);
        $this->feeB = $this->makeFee($this->schoolB);
    }

    protected function makeFee(School $school): StudentFee
    {
        return StudentFee::factory()->create([
            'school_id' => $school->id,
            'student_id' => Student::factory()->create(['school_id' => $school->id])->id,
            'fee_type_id' => FeeType::factory()->create(['school_id' => $school->id])->id,
            'amount' => 1_000_000,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validInput(): array
    {
        return [
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => 100_000,
            'payment_date' => '2026-08-05',
        ];
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function rolesThatMayRecord(): array
    {
        return [
            'bendahara' => [RoleName::Bendahara],
            'admin sekolah' => [RoleName::SchoolAdmin],
        ];
    }

    #[DataProvider('rolesThatMayRecord')]
    public function test_authorized_roles_may_record_a_payment(RoleName $role): void
    {
        $user = User::factory()->forSchool($this->schoolA)->withRole($role)->create();

        $this->assertTrue($user->can('create', Payment::class));

        app(PaymentRecorder::class)->record($this->feeA->getKey(), $this->validInput(), $user);

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_super_admin_may_record_a_payment(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $payment = app(PaymentRecorder::class)
            ->record($this->feeA->getKey(), $this->validInput(), $superAdmin);

        // Cabangnya tetap cabang tagihan, bukan cabang akun (yang NULL).
        $this->assertSame($this->schoolA->id, $payment->school_id);
        $this->assertSame($superAdmin->id, $payment->received_by);
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function rolesThatMayNotRecord(): array
    {
        return [
            // Matriks "Catat Pembayaran": KEPALA ❌ walaupun "Tagihan SPP" ⭕.
            'kepala sekolah' => [RoleName::KepalaSekolah],
            'guru' => [RoleName::Guru],
            'wali kelas' => [RoleName::WaliKelas],
        ];
    }

    #[DataProvider('rolesThatMayNotRecord')]
    public function test_unauthorized_roles_are_rejected_by_the_service(RoleName $role): void
    {
        $user = User::factory()->forSchool($this->schoolA)->withRole($role)->create();

        $this->assertFalse($user->can('create', Payment::class));

        $this->expectException(AuthorizationException::class);

        app(PaymentRecorder::class)->record($this->feeA->getKey(), $this->validInput(), $user);
    }

    public function test_kepala_sekolah_sees_the_list_but_not_the_record_payment_action(): void
    {
        $kepala = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::KepalaSekolah)->create();

        $this->actingAs($kepala);

        Livewire::test(ListStudentFees::class)
            ->assertCanSeeTableRecords([$this->feeA])
            ->assertTableActionHidden('recordPayment', $this->feeA);

        $this->assertFalse(StudentFeeResource::canRecordPaymentFor($this->feeA));
    }

    public function test_bendahara_sees_the_record_payment_action(): void
    {
        $this->actingAs(User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::Bendahara)->create());

        Livewire::test(ListStudentFees::class)
            ->assertTableActionVisible('recordPayment', $this->feeA);
    }

    /**
     * Menyembunyikan aksi bukan proteksi: request Livewire tetap dapat dikirim
     * langsung, dan yang harus menolaknya adalah jalur tulisnya.
     */
    public function test_hidden_action_is_not_the_only_guard(): void
    {
        $kepala = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::KepalaSekolah)->create();

        $this->actingAs($kepala);

        try {
            app(PaymentRecorder::class)->record($this->feeA->getKey(), $this->validInput(), $kepala);
            $this->fail('Kepala sekolah seharusnya tidak dapat mencatat pembayaran.');
        } catch (AuthorizationException) {
            // diharapkan
        }

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('0.00', (string) $this->feeA->fresh()->amount_paid);
    }

    public function test_cross_tenant_student_fee_cannot_be_paid(): void
    {
        $bendaharaA = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::Bendahara)->create();

        try {
            app(PaymentRecorder::class)->record($this->feeB->getKey(), $this->validInput(), $bendaharaA);
            $this->fail('Tagihan cabang lain seharusnya tidak dapat dibayar.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('student_fee_id', $e->errors());
        }

        $this->assertDatabaseCount('payments', 0);
    }

    /**
     * `received_by`, `student_id`, dan `school_id` tidak pernah dibaca dari
     * payload — kalaupun diselundupkan, ketiganya diturunkan ulang.
     */
    public function test_malicious_payload_fields_are_never_trusted(): void
    {
        $bendaharaA = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::Bendahara)->create();
        $someoneElse = User::factory()->forSchool($this->schoolB)
            ->withRole(RoleName::Bendahara)->create();
        $foreignStudent = Student::factory()->create(['school_id' => $this->schoolB->id]);

        $payment = app(PaymentRecorder::class)->record($this->feeA->getKey(), [
            ...$this->validInput(),
            'received_by' => $someoneElse->id,
            'school_id' => $this->schoolB->id,
            'student_id' => $foreignStudent->id,
            'student_fee_id' => $this->feeB->getKey(),
        ], $bendaharaA);

        $this->assertSame($bendaharaA->id, $payment->received_by);
        $this->assertSame($this->schoolA->id, $payment->school_id);
        $this->assertSame($this->feeA->student_id, $payment->student_id);
        $this->assertSame($this->feeA->getKey(), $payment->student_fee_id);
    }

    /**
     * Akun School Level tanpa cabang tidak boleh berubah menjadi lintas cabang
     * hanya karena `school_id`-nya NULL seperti Super Admin.
     */
    public function test_school_level_user_without_a_branch_cannot_pay_anything(): void
    {
        $orphan = User::factory()->withRole(RoleName::Bendahara)->create(['school_id' => null]);

        $this->expectException(ValidationException::class);

        app(PaymentRecorder::class)->record($this->feeA->getKey(), $this->validInput(), $orphan);
    }
}
