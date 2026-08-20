<?php

namespace Tests\Feature\Finance;

use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Enums\StudentFeeStatus;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use App\Services\Finance\PaymentRecorder;
use App\Services\Finance\StudentFeeWaiver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * API 4.9 — PATCH /student-fees/{id}/waive, Auth Level **Admin**: "bebaskan
 * tagihan dengan alasan (status → WAIVED)". ERD 2.2 — `waive_reason`
 * VARCHAR(200) NULL, "alasan dibebaskan (jika status WAIVED)".
 */
class WaiveStudentFeeTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected User $adminA;

    protected StudentFee $feeA;

    protected StudentFee $feeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create();
        $this->schoolB = School::factory()->create();

        $this->adminA = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::SchoolAdmin)->create();

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

    protected function waive(?StudentFee $fee = null, mixed $reason = 'Siswa yatim, dibebaskan oleh yayasan', ?User $actor = null): StudentFee
    {
        return app(StudentFeeWaiver::class)->waive(
            ($fee ?? $this->feeA)->getKey(),
            $reason,
            $actor ?? $this->adminA,
        );
    }

    // ------------------------------------------------------------ happy path

    public function test_school_admin_waives_an_unpaid_fee(): void
    {
        $this->waive();

        $fee = $this->feeA->fresh();

        $this->assertSame(StudentFeeStatus::Waived, $fee->status);
        $this->assertSame('Siswa yatim, dibebaskan oleh yayasan', $fee->waive_reason);
    }

    /**
     * Pembebasan tidak menyentuh uang: nominal dan akumulasi tetap apa adanya.
     */
    public function test_waiving_never_touches_the_amounts(): void
    {
        $this->waive();

        $fee = $this->feeA->fresh();

        $this->assertSame('1000000.00', (string) $fee->amount);
        $this->assertSame('0.00', (string) $fee->amount_paid);
    }

    public function test_super_admin_may_waive_across_branches(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->waive($this->feeB, actor: $superAdmin);

        $fee = $this->feeB->fresh();

        $this->assertSame(StudentFeeStatus::Waived, $fee->status);
        // Cabangnya tetap cabang tagihan; pembebasan tidak memindahkan tenant.
        $this->assertSame($this->schoolB->id, $fee->school_id);
    }

    // -------------------------------------------------------------- alasan

    /**
     * @return array<string, array{mixed}>
     */
    public static function blankReasons(): array
    {
        return [
            'null' => [null],
            'kosong' => [''],
            'spasi saja' => ['   '],
            'bukan string' => [12345],
        ];
    }

    #[DataProvider('blankReasons')]
    public function test_a_waiver_without_a_reason_is_rejected(mixed $reason): void
    {
        try {
            $this->waive(reason: $reason);
            $this->fail('Pembebasan tanpa alasan seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('waive_reason', $e->errors());
        }

        $this->assertSame(StudentFeeStatus::Unpaid, $this->feeA->fresh()->status);
    }

    public function test_a_reason_beyond_the_erd_column_length_is_rejected(): void
    {
        try {
            $this->waive(reason: str_repeat('a', StudentFeeWaiver::REASON_MAX_LENGTH + 1));
            $this->fail('Alasan di atas 200 karakter seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('waive_reason', $e->errors());
        }

        $this->assertNull($this->feeA->fresh()->waive_reason);
    }

    public function test_a_reason_at_exactly_the_column_length_is_accepted(): void
    {
        $reason = str_repeat('a', StudentFeeWaiver::REASON_MAX_LENGTH);

        $this->waive(reason: $reason);

        $this->assertSame($reason, $this->feeA->fresh()->waive_reason);
    }

    public function test_the_reason_is_trimmed_before_it_is_stored(): void
    {
        $this->waive(reason: '  Beasiswa penuh  ');

        $this->assertSame('Beasiswa penuh', $this->feeA->fresh()->waive_reason);
    }

    // ---------------------------------------------------------------- RBAC

    /**
     * API 4.1 — "Auth Level: Admin = Wajib token + role SCHOOL_ADMIN /
     * SUPER_ADMIN". Bendahara memegang `fee.manage` tetapi tidak disebut
     * berwenang membebaskan tagihan oleh dokumen mana pun.
     *
     * @return array<string, array{RoleName}>
     */
    public static function rolesThatMayNotWaive(): array
    {
        return [
            'bendahara' => [RoleName::Bendahara],
            'kepala sekolah' => [RoleName::KepalaSekolah],
            'guru' => [RoleName::Guru],
            'wali kelas' => [RoleName::WaliKelas],
        ];
    }

    #[DataProvider('rolesThatMayNotWaive')]
    public function test_roles_outside_the_admin_level_cannot_waive(RoleName $role): void
    {
        $user = User::factory()->forSchool($this->schoolA)->withRole($role)->create();

        $this->assertFalse($user->can('waive', $this->feeA));

        try {
            $this->waive(actor: $user);
            $this->fail("Peran {$role->value} seharusnya tidak dapat membebaskan tagihan.");
        } catch (AuthorizationException) {
            // diharapkan
        }

        $this->assertSame(StudentFeeStatus::Unpaid, $this->feeA->fresh()->status);
        $this->assertNull($this->feeA->fresh()->waive_reason);
    }

    /**
     * Bendahara tetap boleh melihat dan mencatat pembayaran — yang ditolak
     * hanya pembebasannya.
     */
    public function test_bendahara_keeps_its_other_finance_rights(): void
    {
        $bendahara = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::Bendahara)->create();

        $this->assertTrue($bendahara->can('view', $this->feeA));
        $this->assertTrue($bendahara->can('create', Payment::class));
        $this->assertFalse($bendahara->can('waive', $this->feeA));
    }

    public function test_a_foreign_branch_fee_cannot_be_waived(): void
    {
        try {
            $this->waive($this->feeB);
            $this->fail('Tagihan cabang lain seharusnya tidak dapat dibebaskan.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('student_fee_id', $e->errors());
        }

        $this->assertSame(StudentFeeStatus::Unpaid, $this->feeB->fresh()->status);
    }

    /**
     * Cabang tidak pernah dibaca dari payload: yang menentukan hanyalah tagihan
     * dan akun pelakunya.
     */
    public function test_a_school_admin_of_another_branch_cannot_reach_this_fee(): void
    {
        $adminB = User::factory()->forSchool($this->schoolB)
            ->withRole(RoleName::SchoolAdmin)->create();

        $this->assertFalse($adminB->can('waive', $this->feeA));

        try {
            $this->waive($this->feeA, actor: $adminB);
            $this->fail('Admin cabang lain seharusnya tidak dapat membebaskan tagihan ini.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('student_fee_id', $e->errors());
        }

        $this->assertSame($this->schoolA->id, $this->feeA->fresh()->school_id);
        $this->assertSame(StudentFeeStatus::Unpaid, $this->feeA->fresh()->status);
    }

    public function test_a_school_level_account_without_a_branch_cannot_waive_anything(): void
    {
        $orphan = User::factory()->withRole(RoleName::SchoolAdmin)->create(['school_id' => null]);

        $this->expectException(ValidationException::class);

        $this->waive(actor: $orphan);
    }

    // -------------------------------------------------------- state guards

    public function test_a_waived_fee_cannot_be_waived_again(): void
    {
        $this->waive(reason: 'Alasan pertama');

        try {
            $this->waive(reason: 'Alasan kedua');
            $this->fail('Tagihan yang sudah dibebaskan seharusnya menolak pembebasan kedua.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('waive_reason', $e->errors());
        }

        // Alasan pertama tidak ditimpa diam-diam.
        $this->assertSame('Alasan pertama', $this->feeA->fresh()->waive_reason);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function paidAmounts(): array
    {
        return [
            'partial' => ['400000'],
            'lunas' => ['1000000'],
        ];
    }

    /**
     * Blueprint tidak memuat refund, kredit, maupun pembalikan pembayaran.
     * Tanpa itu, membebaskan tagihan yang sudah menerima uang meninggalkan
     * status dan riwayat finansial yang ambigu.
     */
    #[DataProvider('paidAmounts')]
    public function test_a_fee_that_already_received_money_cannot_be_waived(string $amount): void
    {
        $bendahara = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::Bendahara)->create();

        app(PaymentRecorder::class)->record($this->feeA->getKey(), [
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => $amount,
            'payment_date' => '2026-08-05',
        ], $bendahara);

        $expectedStatus = $this->feeA->fresh()->status;

        try {
            $this->waive();
            $this->fail('Tagihan yang sudah dibayar seharusnya tidak dapat dibebaskan.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('waive_reason', $e->errors());
        }

        $fee = $this->feeA->fresh();

        $this->assertSame($expectedStatus, $fee->status);
        $this->assertNull($fee->waive_reason);
        // Riwayat pembayarannya tidak pernah dihapus oleh percobaan pembebasan.
        $this->assertSame(1, $fee->payments()->count());
        $this->assertSame(
            number_format((float) $amount, 2, '.', ''),
            (string) $fee->amount_paid,
        );
    }

    /**
     * Bila kolom ringkasan `amount_paid` pernah menyimpang menjadi nol, riwayat
     * `payments` yang menjadi pagarnya — bukan angka yang salah itu.
     */
    public function test_a_drifted_amount_paid_cannot_smuggle_a_paid_fee_into_a_waiver(): void
    {
        $bendahara = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::Bendahara)->create();

        app(PaymentRecorder::class)->record($this->feeA->getKey(), [
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => 400_000,
            'payment_date' => '2026-08-05',
        ], $bendahara);

        // Simpangan disuntikkan tanpa event, meniru data yang rusak dari luar.
        StudentFee::query()->whereKey($this->feeA->getKey())->update([
            'amount_paid' => 0,
            'status' => StudentFeeStatus::Unpaid->value,
        ]);

        try {
            $this->waive();
            $this->fail('Riwayat pembayaran seharusnya tetap menghalangi pembebasan.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('waive_reason', $e->errors());
        }

        $this->assertSame(1, $this->feeA->fresh()->payments()->count());
    }

    // ---------------------------------------------- interaksi dengan payment

    /**
     * Guard Batch 5.3 tetap berlaku dari arah sebaliknya.
     */
    public function test_a_waived_fee_still_refuses_new_payments(): void
    {
        $this->waive();

        $bendahara = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::Bendahara)->create();

        try {
            app(PaymentRecorder::class)->record($this->feeA->getKey(), [
                'payment_method' => PaymentMethod::Cash->value,
                'amount' => 100_000,
                'payment_date' => '2026-08-05',
            ], $bendahara);
            $this->fail('Tagihan WAIVED seharusnya menolak pembayaran.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $fee = $this->feeA->fresh();

        $this->assertSame(StudentFeeStatus::Waived, $fee->status);
        $this->assertSame('0.00', (string) $fee->amount_paid);
        $this->assertDatabaseCount('payments', 0);
    }

    /**
     * Lomba antara pembebasan dan pembayaran berakhir pada satu keadaan: yang
     * kedua membaca hasil yang pertama dan ditolak. Yang diuji di sini adalah
     * kedua arahnya, bukan penjadwalannya — SQLite tidak menjalankan dua
     * koneksi paralel.
     */
    public function test_waive_and_payment_cannot_both_succeed_in_either_order(): void
    {
        $bendahara = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::Bendahara)->create();

        // Arah 1: pembebasan lebih dulu → pembayaran ditolak.
        $this->waive();

        $this->assertThrows(
            fn () => app(PaymentRecorder::class)->record($this->feeA->getKey(), [
                'payment_method' => PaymentMethod::Cash->value,
                'amount' => 100_000,
                'payment_date' => '2026-08-05',
            ], $bendahara),
            ValidationException::class,
        );

        // Arah 2: pembayaran lebih dulu → pembebasan ditolak.
        $other = $this->makeFee($this->schoolA);

        app(PaymentRecorder::class)->record($other->getKey(), [
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => 100_000,
            'payment_date' => '2026-08-05',
        ], $bendahara);

        $this->assertThrows(fn () => $this->waive($other), ValidationException::class);

        $this->assertSame(StudentFeeStatus::Waived, $this->feeA->fresh()->status);
        $this->assertSame(StudentFeeStatus::Partial, $other->fresh()->status);
    }
}
