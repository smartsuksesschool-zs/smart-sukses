<?php

namespace Tests\Feature\Api;

use App\Enums\FeeFrequency;
use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Enums\StudentFeeStatus;
use App\Enums\TransactionType;
use App\Jobs\GenerateStudentFees;
use App\Models\AcademicYear;
use App\Models\FeeType;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\PaymentRecorder;
use App\Services\Finance\StudentFeeGenerator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * API 4.9 — endpoint keuangan gelombang pertama.
 *
 * Yang diuji di sini bukan hanya bentuk responsnya, melainkan bahwa jalur API
 * memakai service domain yang sama dengan panel: seluruh invarian keuangan
 * harus tetap berlaku tanpa satu pun aturan ditulis ulang di controller.
 */
class FinanceApiTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected User $bendaharaA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create();
        $this->schoolB = School::factory()->create();

        $this->bendaharaA = User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::Bendahara)->create();
    }

    protected function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    /**
     * Guard yang sudah teresolusi dilupakan lebih dulu: di produksi setiap
     * request adalah proses baru, sedangkan dalam satu test container-nya
     * dipakai bersama dan AuthManager menyimpan pengguna request sebelumnya.
     */
    protected function asUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->tokenFor($user));
    }

    protected function userIn(School $school, RoleName $role): User
    {
        return User::factory()->forSchool($school)->withRole($role)->create();
    }

    protected function feeTypeIn(School $school, array $overrides = []): FeeType
    {
        return FeeType::factory()->create(['school_id' => $school->id, ...$overrides]);
    }

    protected function studentFeeIn(School $school, array $overrides = []): StudentFee
    {
        return StudentFee::factory()->create([
            'school_id' => $school->id,
            'student_id' => Student::factory()->create(['school_id' => $school->id])->id,
            'fee_type_id' => $this->feeTypeIn($school)->id,
            'amount' => '1000000',
            'period' => '2026-08',
            ...$overrides,
        ]);
    }

    protected function transactionIn(School $school, array $overrides = []): Transaction
    {
        return Transaction::factory()->create([
            'school_id' => $school->id,
            'created_by' => $this->userIn($school, RoleName::Bendahara)->id,
            'transaction_date' => '2026-08-05',
            ...$overrides,
        ]);
    }

    // -------------------------------------------------------------- fee types

    public function test_fee_types_are_listed_for_the_callers_branch_only(): void
    {
        $mine = $this->feeTypeIn($this->schoolA, ['name' => 'SPP Alpha']);
        $this->feeTypeIn($this->schoolB, ['name' => 'SPP Beta']);

        $names = $this->asUser($this->bendaharaA)
            ->getJson('/api/v1/fee-types')
            ->assertOk()
            ->json('data.*.name');

        $this->assertSame(['SPP Alpha'], $names);
        $this->assertSame(
            $mine->id,
            $this->asUser($this->bendaharaA)->getJson('/api/v1/fee-types')->json('data.0.id'),
        );
    }

    public function test_a_fee_type_can_be_created_and_updated(): void
    {
        $created = $this->asUser($this->bendaharaA)->postJson('/api/v1/fee-types', [
            'name' => 'Uang Gedung',
            'amount' => 2_500_000,
            'frequency' => FeeFrequency::Once->value,
        ])->assertStatus(201);

        $created->assertJsonPath('data.name', 'Uang Gedung');
        $created->assertJsonPath('data.amount', '2500000.00');

        $id = $created->json('data.id');

        $this->assertDatabaseHas('fee_types', ['id' => $id, 'school_id' => $this->schoolA->id]);

        $this->asUser($this->bendaharaA)->putJson("/api/v1/fee-types/{$id}", [
            'name' => 'Uang Gedung 2027',
            'amount' => 3_000_000,
            'frequency' => FeeFrequency::Once->value,
        ])->assertOk()->assertJsonPath('data.name', 'Uang Gedung 2027');
    }

    public function test_a_crafted_branch_cannot_move_a_fee_type(): void
    {
        $this->asUser($this->bendaharaA)->postJson('/api/v1/fee-types', [
            'name' => 'Selundupan',
            'amount' => 100_000,
            'frequency' => FeeFrequency::Monthly->value,
            'school_id' => $this->schoolB->id,
        ])->assertStatus(201);

        $this->assertDatabaseHas('fee_types', [
            'name' => 'Selundupan',
            'school_id' => $this->schoolA->id,
        ]);
    }

    public function test_a_fee_type_from_another_branch_is_not_found(): void
    {
        $foreign = $this->feeTypeIn($this->schoolB);

        $this->asUser($this->bendaharaA)->putJson("/api/v1/fee-types/{$foreign->id}", [
            'name' => 'Diubah',
            'amount' => 1000,
            'frequency' => FeeFrequency::Monthly->value,
        ])->assertStatus(404);
    }

    /**
     * @return array<string, array{RoleName, bool}>
     */
    public static function feeTypeReaders(): array
    {
        return [
            'bendahara' => [RoleName::Bendahara, true],
            'admin sekolah' => [RoleName::SchoolAdmin, true],
            'kepala sekolah' => [RoleName::KepalaSekolah, true],
            'guru' => [RoleName::Guru, false],
            'wali kelas' => [RoleName::WaliKelas, false],
        ];
    }

    #[DataProvider('feeTypeReaders')]
    public function test_fee_type_read_access_follows_the_policy(RoleName $role, bool $allowed): void
    {
        $this->asUser($this->userIn($this->schoolA, $role))
            ->getJson('/api/v1/fee-types')
            ->assertStatus($allowed ? 200 : 403);
    }

    public function test_kepala_sekolah_cannot_create_a_fee_type(): void
    {
        $this->asUser($this->userIn($this->schoolA, RoleName::KepalaSekolah))
            ->postJson('/api/v1/fee-types', [
                'name' => 'Tidak boleh',
                'amount' => 1000,
                'frequency' => FeeFrequency::Monthly->value,
            ])->assertStatus(403);
    }

    // ------------------------------------------------------------ student fees

    public function test_student_fees_are_filtered_by_the_documented_parameters(): void
    {
        $feeType = $this->feeTypeIn($this->schoolA);
        $studentA = Student::factory()->create(['school_id' => $this->schoolA->id]);

        $wanted = StudentFee::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_id' => $studentA->id,
            'fee_type_id' => $feeType->id,
            'period' => '2026-08',
            'status' => StudentFeeStatus::Unpaid->value,
        ]);

        $this->studentFeeIn($this->schoolA, ['period' => '2026-09']);
        $this->studentFeeIn($this->schoolA, ['status' => StudentFeeStatus::Paid->value, 'amount_paid' => '1000000']);

        $ids = $this->asUser($this->bendaharaA)->getJson(
            '/api/v1/student-fees?'.http_build_query([
                'student_id' => $studentA->id,
                'period' => '2026-08',
                'status' => StudentFeeStatus::Unpaid->value,
                'fee_type_id' => $feeType->id,
            ]),
        )->assertOk()->json('data.*.id');

        $this->assertSame([$wanted->id], $ids);
    }

    public function test_student_fees_never_show_another_branch(): void
    {
        $this->studentFeeIn($this->schoolA);
        $this->studentFeeIn($this->schoolB);

        $this->asUser($this->bendaharaA)->getJson('/api/v1/student-fees')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_the_detail_endpoint_carries_the_payment_history(): void
    {
        $fee = $this->studentFeeIn($this->schoolA);

        app(PaymentRecorder::class)->record($fee->getKey(), [
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => '400000',
            'payment_date' => '2026-08-05',
        ], $this->bendaharaA);

        $body = $this->asUser($this->bendaharaA)
            ->getJson("/api/v1/student-fees/{$fee->id}")
            ->assertOk();

        $body->assertJsonPath('data.amount_paid', '400000.00');
        $body->assertJsonPath('data.remaining', '600000.00');
        $body->assertJsonPath('data.status', StudentFeeStatus::Partial->value);
        $body->assertJsonCount(1, 'data.payments');
        $body->assertJsonPath('data.payments.0.amount_paid', '400000.00');
    }

    public function test_a_student_fee_from_another_branch_is_hidden_as_not_found(): void
    {
        $foreign = $this->studentFeeIn($this->schoolB);

        $this->asUser($this->bendaharaA)
            ->getJson("/api/v1/student-fees/{$foreign->id}")
            ->assertStatus(404);
    }

    /**
     * SPP-02 poin 3 mewajibkan pratinjau. API map tidak memuat endpoint
     * pratinjau, jadi jaminannya dipenuhi dengan mengembalikan pratinjau itu
     * bersama respons — bukan dengan menghapusnya (butir 114).
     */
    public function test_generate_bulk_queues_the_job_and_returns_the_preview(): void
    {
        Queue::fake();

        Student::factory()->count(3)->create(['school_id' => $this->schoolA->id]);
        $feeType = $this->feeTypeIn($this->schoolA);

        $body = $this->asUser($this->userIn($this->schoolA, RoleName::SchoolAdmin))
            ->postJson('/api/v1/student-fees/generate-bulk', [
                'fee_type_id' => $feeType->id,
                'period' => '2026-08',
                'due_date' => '2026-08-10',
            ])->assertStatus(202);

        $body->assertJsonPath('data.queued', true);
        $body->assertJsonPath('data.preview.will_be_billed', 3);
        $body->assertJsonPath('data.preview.already_billed', 0);
        $body->assertJsonCount(3, 'data.preview.students');

        Queue::assertPushed(GenerateStudentFees::class);
    }

    public function test_generate_bulk_rejects_a_fee_type_from_another_branch(): void
    {
        Queue::fake();

        $foreign = $this->feeTypeIn($this->schoolB);

        $this->asUser($this->userIn($this->schoolA, RoleName::SchoolAdmin))
            ->postJson('/api/v1/student-fees/generate-bulk', [
                'fee_type_id' => $foreign->id,
                'period' => '2026-08',
                'due_date' => '2026-08-10',
            ])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    /**
     * Kewenangan waive tetap Super Admin + School Admin (butir 67); Bendahara
     * tidak, walaupun mereka memegang izin keuangan lain.
     */
    public function test_waive_uses_the_same_service_and_authority_as_the_panel(): void
    {
        $fee = $this->studentFeeIn($this->schoolA);

        $this->asUser($this->bendaharaA)
            ->patchJson("/api/v1/student-fees/{$fee->id}/waive", ['waive_reason' => 'Coba'])
            ->assertStatus(403);

        $this->asUser($this->userIn($this->schoolA, RoleName::SchoolAdmin))
            ->patchJson("/api/v1/student-fees/{$fee->id}/waive", ['waive_reason' => 'Beasiswa penuh'])
            ->assertOk()
            ->assertJsonPath('data.status', StudentFeeStatus::Waived->value)
            ->assertJsonPath('data.waive_reason', 'Beasiswa penuh');
    }

    public function test_a_paid_fee_still_cannot_be_waived_through_the_api(): void
    {
        $fee = $this->studentFeeIn($this->schoolA);

        app(PaymentRecorder::class)->record($fee->getKey(), [
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => '400000',
            'payment_date' => '2026-08-05',
        ], $this->bendaharaA);

        $this->asUser($this->userIn($this->schoolA, RoleName::SchoolAdmin))
            ->patchJson("/api/v1/student-fees/{$fee->id}/waive", ['waive_reason' => 'Beasiswa'])
            ->assertStatus(422);
    }

    // ---------------------------------------------------------------- payments

    public function test_a_payment_is_recorded_through_the_domain_service(): void
    {
        $fee = $this->studentFeeIn($this->schoolA);

        $body = $this->asUser($this->bendaharaA)->postJson('/api/v1/payments', [
            'student_fee_id' => $fee->id,
            'payment_method' => PaymentMethod::Transfer->value,
            'amount' => 400_000,
            'payment_date' => '2026-08-05',
            'reference_number' => 'TRF-1',
        ])->assertStatus(201);

        $body->assertJsonPath('data.amount_paid', '400000.00');
        $body->assertJsonPath('data.has_proof', false);

        $fee->refresh();

        $this->assertSame('400000.00', (string) $fee->amount_paid);
        $this->assertSame(StudentFeeStatus::Partial, $fee->status);
    }

    /**
     * Cabang, siswa, dan pencatat diturunkan server dari tagihannya.
     */
    public function test_smuggled_identity_fields_are_ignored_by_the_api(): void
    {
        $fee = $this->studentFeeIn($this->schoolA);
        $someoneElse = $this->userIn($this->schoolB, RoleName::Bendahara);

        $id = $this->asUser($this->bendaharaA)->postJson('/api/v1/payments', [
            'student_fee_id' => $fee->id,
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => 100_000,
            'payment_date' => '2026-08-05',
            'school_id' => $this->schoolB->id,
            'student_id' => 999_999,
            'received_by' => $someoneElse->id,
        ])->assertStatus(201)->json('data.id');

        $this->assertDatabaseHas('payments', [
            'id' => $id,
            'school_id' => $this->schoolA->id,
            'student_id' => $fee->student_id,
            'received_by' => $this->bendaharaA->id,
        ]);
    }

    public function test_payment_gateway_cannot_be_recorded_manually_through_the_api(): void
    {
        $fee = $this->studentFeeIn($this->schoolA);

        $this->asUser($this->bendaharaA)->postJson('/api/v1/payments', [
            'student_fee_id' => $fee->id,
            'payment_method' => PaymentMethod::PaymentGateway->value,
            'amount' => 100_000,
            'payment_date' => '2026-08-05',
        ])->assertStatus(422)->assertJsonPath('success', false);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_overpayment_is_still_rejected_through_the_api(): void
    {
        $fee = $this->studentFeeIn($this->schoolA, ['amount' => '1000000']);

        $this->asUser($this->bendaharaA)->postJson('/api/v1/payments', [
            'student_fee_id' => $fee->id,
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => 1_500_000,
            'payment_date' => '2026-08-05',
        ])->assertStatus(422);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_waived_fee_still_refuses_payment_through_the_api(): void
    {
        $fee = $this->studentFeeIn($this->schoolA, [
            'status' => StudentFeeStatus::Waived->value,
            'waive_reason' => 'Beasiswa',
        ]);

        $this->asUser($this->bendaharaA)->postJson('/api/v1/payments', [
            'student_fee_id' => $fee->id,
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => 100_000,
            'payment_date' => '2026-08-05',
        ])->assertStatus(422);
    }

    public function test_installments_accumulate_through_the_api(): void
    {
        $fee = $this->studentFeeIn($this->schoolA, ['amount' => '1000000']);

        foreach ([400_000, 600_000] as $amount) {
            $this->asUser($this->bendaharaA)->postJson('/api/v1/payments', [
                'student_fee_id' => $fee->id,
                'payment_method' => PaymentMethod::Cash->value,
                'amount' => $amount,
                'payment_date' => '2026-08-05',
            ])->assertStatus(201);
        }

        $fee->refresh();

        $this->assertSame('1000000.00', (string) $fee->amount_paid);
        $this->assertSame(StudentFeeStatus::Paid, $fee->status);
    }

    public function test_a_cross_tenant_student_fee_cannot_be_paid_through_the_api(): void
    {
        $foreign = $this->studentFeeIn($this->schoolB);

        $this->asUser($this->bendaharaA)->postJson('/api/v1/payments', [
            'student_fee_id' => $foreign->id,
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => 100_000,
            'payment_date' => '2026-08-05',
        ])->assertStatus(422);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_payments_are_filtered_by_student_period_and_method(): void
    {
        $augustFee = $this->studentFeeIn($this->schoolA, ['period' => '2026-08']);
        $septemberFee = $this->studentFeeIn($this->schoolA, ['period' => '2026-09']);

        app(PaymentRecorder::class)->record($augustFee->getKey(), [
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => '100000',
            'payment_date' => '2026-08-05',
        ], $this->bendaharaA);

        app(PaymentRecorder::class)->record($septemberFee->getKey(), [
            'payment_method' => PaymentMethod::Transfer->value,
            'amount' => '200000',
            'payment_date' => '2026-09-05',
        ], $this->bendaharaA);

        $token = $this->tokenFor($this->bendaharaA);

        $this->withToken($token)->getJson('/api/v1/payments')->assertOk()->assertJsonCount(2, 'data');

        // Periode disaring lewat student_fees.period — `payments` tidak punya
        // kolom periode (butir 122).
        $this->withToken($token)->getJson('/api/v1/payments?period=2026-08')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount_paid', '100000.00');

        $this->withToken($token)->getJson('/api/v1/payments?method='.PaymentMethod::Transfer->value)
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount_paid', '200000.00');

        $this->withToken($token)->getJson('/api/v1/payments?student_id='.$augustFee->student_id)
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_payments_never_expose_the_proof_storage_path(): void
    {
        $fee = $this->studentFeeIn($this->schoolA);

        $payment = app(PaymentRecorder::class)->record($fee->getKey(), [
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => '100000',
            'payment_date' => '2026-08-05',
        ], $this->bendaharaA);

        $payment->forceFill([
            'proof_url' => PaymentRecorder::proofDirectory((int) $this->schoolA->id).'/rahasia.pdf',
        ])->save();

        $body = $this->asUser($this->bendaharaA)->getJson('/api/v1/payments')->assertOk();

        $body->assertJsonPath('data.0.has_proof', true);
        $this->assertArrayNotHasKey('proof_url', $body->json('data.0'));
        $this->assertStringNotContainsString('payment-proofs', json_encode($body->json()));
        $this->assertStringNotContainsString('rahasia.pdf', json_encode($body->json()));
    }

    // ------------------------------------------------------------ transactions

    /**
     * API 4.9.2 memakai `date_to`; `date_until` internal exporter bukan
     * pengganti publiknya (butir 123).
     */
    public function test_transactions_are_filtered_by_the_documented_parameters(): void
    {
        $this->transactionIn($this->schoolA, [
            'type' => TransactionType::Income->value,
            'category' => 'Dana BOS',
            'transaction_date' => '2026-08-05',
        ]);
        $this->transactionIn($this->schoolA, [
            'type' => TransactionType::Expense->value,
            'category' => 'Gaji',
            'transaction_date' => '2026-08-20',
        ]);
        $this->transactionIn($this->schoolA, [
            'type' => TransactionType::Income->value,
            'category' => 'Dana BOS',
            'transaction_date' => '2026-09-05',
        ]);

        $token = $this->tokenFor($this->bendaharaA);

        $this->withToken($token)->getJson('/api/v1/transactions')->assertOk()->assertJsonCount(3, 'data');

        $this->withToken($token)->getJson('/api/v1/transactions?type='.TransactionType::Expense->value)
            ->assertOk()->assertJsonCount(1, 'data');

        $this->withToken($token)->getJson('/api/v1/transactions?category=Dana+BOS')
            ->assertOk()->assertJsonCount(2, 'data');

        $this->withToken($token)->getJson('/api/v1/transactions?date_from=2026-08-01&date_to=2026-08-31')
            ->assertOk()->assertJsonCount(2, 'data');

        $this->withToken($token)->getJson('/api/v1/transactions?date_from=2026-08-21')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_an_inverted_date_range_is_rejected(): void
    {
        $this->asUser($this->bendaharaA)
            ->getJson('/api/v1/transactions?date_from=2026-08-31&date_to=2026-08-01')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /**
     * `date_until` bukan nama publik: mengirimnya tidak menyaring apa pun.
     */
    public function test_date_until_is_not_a_public_parameter(): void
    {
        $this->transactionIn($this->schoolA, ['transaction_date' => '2026-08-05']);
        $this->transactionIn($this->schoolA, ['transaction_date' => '2026-12-31']);

        $this->asUser($this->bendaharaA)
            ->getJson('/api/v1/transactions?date_from=2026-08-01&date_until=2026-08-31')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_transaction_is_created_and_updated_through_the_service(): void
    {
        $payload = [
            'type' => TransactionType::Expense->value,
            'category' => 'Pembelian Alat',
            'amount' => 750_000,
            'transaction_date' => '2026-08-05',
            'description' => 'Proyektor ruang guru',
            'reference_number' => 'NOTA-11',
        ];

        $created = $this->asUser($this->bendaharaA)
            ->postJson('/api/v1/transactions', $payload)
            ->assertStatus(201);

        $created->assertJsonPath('data.amount', '750000.00');
        $created->assertJsonPath('data.type', TransactionType::Expense->value);

        $id = $created->json('data.id');

        $this->assertDatabaseHas('transactions', [
            'id' => $id,
            'school_id' => $this->schoolA->id,
            'created_by' => $this->bendaharaA->id,
        ]);

        $admin = $this->userIn($this->schoolA, RoleName::SchoolAdmin);

        $this->asUser($admin)->putJson("/api/v1/transactions/{$id}", [
            ...$payload,
            'category' => 'Koreksi Kategori',
        ])->assertOk()->assertJsonPath('data.category', 'Koreksi Kategori');

        // Pencatat aslinya tidak ditimpa editor (butir 79).
        $this->assertDatabaseHas('transactions', ['id' => $id, 'created_by' => $this->bendaharaA->id]);
    }

    public function test_the_kas_01_required_fields_are_enforced_through_the_api(): void
    {
        $this->asUser($this->bendaharaA)->postJson('/api/v1/transactions', [
            'type' => TransactionType::Income->value,
            'category' => 'Dana BOS',
            'amount' => 100_000,
            'transaction_date' => '2026-08-05',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description', 'reference_number']);
    }

    public function test_kepala_sekolah_may_read_but_not_write_transactions(): void
    {
        $this->transactionIn($this->schoolA);

        $kepala = $this->userIn($this->schoolA, RoleName::KepalaSekolah);

        $this->asUser($kepala)->getJson('/api/v1/transactions')->assertOk();

        $this->asUser($kepala)->postJson('/api/v1/transactions', [
            'type' => TransactionType::Income->value,
            'category' => 'Dana BOS',
            'amount' => 100_000,
            'transaction_date' => '2026-08-05',
            'description' => 'Coba',
            'reference_number' => 'X-1',
        ])->assertStatus(403);
    }

    public function test_transactions_never_show_another_branch(): void
    {
        $this->transactionIn($this->schoolA);
        $this->transactionIn($this->schoolB);

        $this->asUser($this->bendaharaA)->getJson('/api/v1/transactions')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_transaction_from_another_branch_cannot_be_updated(): void
    {
        $foreign = $this->transactionIn($this->schoolB);

        $this->asUser($this->bendaharaA)->putJson("/api/v1/transactions/{$foreign->id}", [
            'type' => TransactionType::Income->value,
            'category' => 'Diubah',
            'amount' => 1000,
            'transaction_date' => '2026-08-05',
            'description' => 'Coba',
            'reference_number' => 'X-1',
        ])->assertStatus(422);
    }

    /**
     * Rutenya ada sejak Batch 6.7, dan yang berwenang memakainya lebih sempit
     * daripada yang berwenang mencatat: Bendahara memegang `accounting.manage`
     * tetapi tidak menghapus (butir 129).
     */
    public function test_the_delete_route_exists_but_not_for_bendahara(): void
    {
        $transaction = $this->transactionIn($this->schoolA);

        $this->asUser($this->bendaharaA)
            ->deleteJson("/api/v1/transactions/{$transaction->id}")
            ->assertStatus(403);

        $this->assertNull($transaction->fresh()->deleted_at);
    }

    public function test_a_school_admin_soft_deletes_through_the_api(): void
    {
        $transaction = $this->transactionIn($this->schoolA);

        $this->asUser($this->userIn($this->schoolA, RoleName::SchoolAdmin))
            ->deleteJson("/api/v1/transactions/{$transaction->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $transaction->id);

        // Barisnya tetap ada; yang berubah hanya `deleted_at`.
        $this->assertDatabaseCount('transactions', 1);
        $this->assertNotNull(Transaction::withTrashed()->find($transaction->id)?->deleted_at);
        $this->assertNull(Transaction::withoutGlobalScope(SchoolScope::class)->find($transaction->id));
    }

    public function test_a_deleted_transaction_disappears_from_the_api_listing(): void
    {
        $kept = $this->transactionIn($this->schoolA, ['category' => 'Tetap']);
        $removed = $this->transactionIn($this->schoolA, ['category' => 'Dihapus']);

        $this->asUser($this->userIn($this->schoolA, RoleName::SchoolAdmin))
            ->deleteJson("/api/v1/transactions/{$removed->id}")
            ->assertOk();

        $body = $this->asUser($this->bendaharaA)->getJson('/api/v1/transactions')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame($kept->id, $body->json('data.0.id'));
    }

    public function test_a_transaction_from_another_branch_cannot_be_deleted(): void
    {
        $foreign = $this->transactionIn($this->schoolB);

        // Konvensi yang sama dengan PUT pada rute yang sama: keberadaannya
        // tidak dikonfirmasi, dan pesannya tidak membedakan "bukan milik Anda"
        // dari "tidak ada" (butir 130).
        $this->asUser($this->userIn($this->schoolA, RoleName::SchoolAdmin))
            ->deleteJson("/api/v1/transactions/{$foreign->id}")
            ->assertStatus(422);

        $this->assertNull($foreign->fresh()->deleted_at);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function deferredEndpoints(): array
    {
        return [
            'finance summary' => ['api/v1/finance/summary'],
            'spp report' => ['api/v1/finance/spp-report'],
            'admin dashboard' => ['api/v1/admin/dashboard'],
            'school stats' => ['api/v1/admin/schools/{id}/stats'],
        ];
    }

    #[DataProvider('deferredEndpoints')]
    public function test_deferred_endpoints_have_no_placeholder_route(string $uri): void
    {
        $registered = collect(Route::getRoutes())->map(fn ($route) => $route->uri())->all();

        $this->assertNotContains($uri, $registered);
    }

    // ----------------------------------------------------------------- exports

    public function test_the_student_fee_export_returns_a_real_xlsx(): void
    {
        $this->studentFeeIn($this->schoolA, ['period' => '2026-08']);

        $response = $this->asUser($this->bendaharaA)
            ->get('/api/v1/student-fees/export?period=2026-08');

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            $response->headers->get('content-type') ?? '',
        );
        $this->assertStringContainsString('tagihan_', $response->headers->get('content-disposition') ?? '');
    }

    public function test_the_cash_ledger_export_returns_a_real_xlsx(): void
    {
        $this->transactionIn($this->schoolA);

        $response = $this->asUser($this->bendaharaA)
            ->get('/api/v1/finance/export?date_from=2026-08-01&date_to=2026-08-31');

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            $response->headers->get('content-type') ?? '',
        );
        $this->assertStringContainsString('buku-kas_', $response->headers->get('content-disposition') ?? '');
    }

    public function test_kepala_sekolah_cannot_export_through_the_api(): void
    {
        $kepala = $this->userIn($this->schoolA, RoleName::KepalaSekolah);

        $this->asUser($kepala)->getJson('/api/v1/student-fees/export?period=2026-08')->assertStatus(403);

        $this->asUser($kepala)
            ->getJson('/api/v1/finance/export?date_from=2026-08-01&date_to=2026-08-31')
            ->assertStatus(403);
    }

    public function test_the_export_period_is_required(): void
    {
        $this->asUser($this->bendaharaA)
            ->getJson('/api/v1/student-fees/export')
            ->assertStatus(422)
            ->assertJsonValidationErrors('period');
    }

    public function test_a_super_admin_must_choose_a_branch_to_export(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->asUser($superAdmin)
            ->getJson('/api/v1/student-fees/export?period=2026-08')
            ->assertStatus(422)
            ->assertJsonValidationErrors('school_id');
    }

    // --------------------------------------------------- late payment proof

    /**
     * API 4.9.1 — POST /payments/{id}/proof. Melengkapi pembayaran yang
     * buktinya belum ada (butir 119).
     */
    public function test_proof_can_be_attached_through_the_api(): void
    {
        Storage::fake(PaymentRecorder::PROOF_DISK);

        $fee = $this->studentFeeIn($this->schoolA);
        $payment = app(PaymentRecorder::class)->record($fee->getKey(), [
            'payment_method' => PaymentMethod::Transfer->value,
            'amount' => '400000',
            'payment_date' => '2026-08-05',
        ], $this->bendaharaA);

        $this->assertNull($payment->proof_url);

        $body = $this->asUser($this->bendaharaA)->post(
            "/api/v1/payments/{$payment->id}/proof",
            ['proof' => UploadedFile::fake()->create('bukti.pdf', 200, 'application/pdf')],
        )->assertOk();

        $body->assertJsonPath('data.has_proof', true);
        // Jalur penyimpanannya tidak pernah keluar (butir 118).
        $this->assertArrayNotHasKey('proof_url', $body->json('data'));
        $this->assertStringNotContainsString('payment-proofs', json_encode($body->json()));

        $this->assertNotNull($payment->fresh()->proof_url);
    }

    public function test_the_api_refuses_to_overwrite_an_existing_proof(): void
    {
        Storage::fake(PaymentRecorder::PROOF_DISK);

        $fee = $this->studentFeeIn($this->schoolA);
        $payment = app(PaymentRecorder::class)->record($fee->getKey(), [
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => '400000',
            'payment_date' => '2026-08-05',
        ], $this->bendaharaA);

        $this->asUser($this->bendaharaA)->post(
            "/api/v1/payments/{$payment->id}/proof",
            ['proof' => UploadedFile::fake()->create('bukti.pdf', 200, 'application/pdf')],
        )->assertOk();

        $original = $payment->fresh()->proof_url;

        $this->asUser($this->bendaharaA)->post(
            "/api/v1/payments/{$payment->id}/proof",
            ['proof' => UploadedFile::fake()->create('lain.pdf', 200, 'application/pdf')],
        )->assertStatus(422)->assertJsonValidationErrors('proof');

        $this->assertSame($original, $payment->fresh()->proof_url);
    }

    public function test_the_api_rejects_an_oversized_proof(): void
    {
        Storage::fake(PaymentRecorder::PROOF_DISK);

        $fee = $this->studentFeeIn($this->schoolA);
        $payment = app(PaymentRecorder::class)->record($fee->getKey(), [
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => '400000',
            'payment_date' => '2026-08-05',
        ], $this->bendaharaA);

        $this->asUser($this->bendaharaA)->post(
            "/api/v1/payments/{$payment->id}/proof",
            ['proof' => UploadedFile::fake()->create('besar.pdf', 6 * 1024, 'application/pdf')],
        )->assertStatus(422);

        $this->assertNull($payment->fresh()->proof_url);
    }

    public function test_a_payment_from_another_branch_cannot_receive_a_proof(): void
    {
        Storage::fake(PaymentRecorder::PROOF_DISK);

        $foreignFee = $this->studentFeeIn($this->schoolB);
        $foreignPayment = app(PaymentRecorder::class)->record($foreignFee->getKey(), [
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => '400000',
            'payment_date' => '2026-08-05',
        ], $this->userIn($this->schoolB, RoleName::Bendahara));

        $this->asUser($this->bendaharaA)->post(
            "/api/v1/payments/{$foreignPayment->id}/proof",
            ['proof' => UploadedFile::fake()->create('bukti.pdf', 200, 'application/pdf')],
        )->assertStatus(422);

        $this->assertNull($foreignPayment->fresh()->proof_url);
    }

    public function test_kepala_sekolah_cannot_attach_a_proof_through_the_api(): void
    {
        Storage::fake(PaymentRecorder::PROOF_DISK);

        $fee = $this->studentFeeIn($this->schoolA);
        $payment = app(PaymentRecorder::class)->record($fee->getKey(), [
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => '400000',
            'payment_date' => '2026-08-05',
        ], $this->bendaharaA);

        $this->asUser($this->userIn($this->schoolA, RoleName::KepalaSekolah))->post(
            "/api/v1/payments/{$payment->id}/proof",
            ['proof' => UploadedFile::fake()->create('bukti.pdf', 200, 'application/pdf')],
        )->assertStatus(403);

        $this->assertNull($payment->fresh()->proof_url);
    }

    // ------------------------------------------------------------ super admin

    public function test_a_super_admin_reads_across_branches(): void
    {
        $this->transactionIn($this->schoolA);
        $this->transactionIn($this->schoolB);

        $this->asUser(User::factory()->superAdmin()->create())
            ->getJson('/api/v1/transactions')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /**
     * Akun School Level tanpa cabang tidak boleh berubah menjadi lintas cabang
     * hanya karena `school_id`-nya NULL seperti Super Admin.
     */
    public function test_a_school_less_regular_user_sees_nothing(): void
    {
        $this->transactionIn($this->schoolA);

        $orphan = User::factory()->withRole(RoleName::Bendahara)->create(['school_id' => null]);

        $this->asUser($orphan)->getJson('/api/v1/transactions')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ------------------------------------------- generate-bulk end to end

    /**
     * Pratinjau saja tidak membuktikan apa pun: yang harus terbukti adalah
     * bahwa penerbitan sungguhan benar-benar tercapai. Job yang didorong
     * endpoint diambil lalu dijalankan seperti worker menjalankannya — tanpa
     * sesi sama sekali — dan hasilnya dibandingkan dengan pratinjau yang
     * sudah diterima pemanggil.
     */
    public function test_generate_bulk_reaches_actual_generation_when_the_worker_runs(): void
    {
        Queue::fake();

        AcademicYear::factory()->create([
            'school_id' => $this->schoolA->id,
            'is_active' => true,
        ]);

        Student::factory()->count(3)->create(['school_id' => $this->schoolA->id]);
        Student::factory()->count(2)->create(['school_id' => $this->schoolB->id]);

        $feeType = $this->feeTypeIn($this->schoolA, ['amount' => '150000']);

        $body = $this->asUser($this->userIn($this->schoolA, RoleName::SchoolAdmin))
            ->postJson('/api/v1/student-fees/generate-bulk', [
                'fee_type_id' => $feeType->id,
                'period' => '2026-08',
                'due_date' => '2026-08-10',
            ])->assertStatus(202);

        $body->assertJsonPath('data.preview.will_be_billed', 3);

        $queued = null;

        Queue::assertPushed(GenerateStudentFees::class, function (GenerateStudentFees $job) use (&$queued): bool {
            $queued = $job;

            return true;
        });

        // Cabang dan kombinasi dibawa job sebagai skalar, bukan diwarisi sesi.
        $this->assertSame($this->schoolA->id, $queued->schoolId);
        $this->assertSame($feeType->id, $queued->feeTypeId);
        $this->assertSame('2026-08', $queued->period);
        $this->assertSame('2026-08-10', $queued->dueDate);

        $this->becomeAWorker();

        $queued->handle(app(StudentFeeGenerator::class));

        $created = StudentFee::query()->withoutGlobalScopes()->get();

        // Persis sebanyak pratinjau, dan tidak satu pun milik cabang lain.
        $this->assertCount(3, $created);
        $this->assertSame([$this->schoolA->id], $created->pluck('school_id')->unique()->values()->all());
        $this->assertSame(['150000.00'], $created->pluck('amount')->unique()->values()->all());

        // Retry worker maupun permintaan kedua tidak menerbitkan baris kedua.
        $queued->handle(app(StudentFeeGenerator::class));

        $this->assertSame(3, StudentFee::query()->withoutGlobalScopes()->count());
    }

    /**
     * Pratinjau yang basi tidak mungkin terjadi di jalur API: pratinjau dan
     * antrean dihitung dari satu isian yang sama dalam satu request, sehingga
     * tidak ada jeda tempat isian bisa berubah — itulah yang dijaga
     * `previewSignature` pada halaman panel yang dua langkah.
     */
    public function test_the_preview_describes_exactly_the_run_that_was_queued(): void
    {
        Queue::fake();

        AcademicYear::factory()->create([
            'school_id' => $this->schoolA->id,
            'is_active' => true,
        ]);

        $feeType = $this->feeTypeIn($this->schoolA);

        Student::factory()->count(2)->create(['school_id' => $this->schoolA->id]);

        $admin = $this->userIn($this->schoolA, RoleName::SchoolAdmin);

        $first = $this->asUser($admin)->postJson('/api/v1/student-fees/generate-bulk', [
            'fee_type_id' => $feeType->id,
            'period' => '2026-08',
            'due_date' => '2026-08-10',
        ])->assertStatus(202);

        $first->assertJsonPath('data.preview.will_be_billed', 2);
        $first->assertJsonPath('data.preview.already_billed', 0);

        $queued = null;

        Queue::assertPushed(GenerateStudentFees::class, function (GenerateStudentFees $job) use (&$queued): bool {
            $queued = $job;

            return true;
        });

        $this->becomeAWorker();
        $queued->handle(app(StudentFeeGenerator::class));

        // Permintaan kedua melihat keadaan yang sudah berubah, dan pratinjau
        // keduanya melaporkannya apa adanya.
        $second = $this->asUser($admin)->postJson('/api/v1/student-fees/generate-bulk', [
            'fee_type_id' => $feeType->id,
            'period' => '2026-08',
            'due_date' => '2026-08-10',
        ])->assertStatus(202);

        $second->assertJsonPath('data.preview.will_be_billed', 0);
        $second->assertJsonPath('data.preview.already_billed', 2);
    }

    /**
     * Meninggalkan konteks request sepenuhnya.
     *
     * Melupakan guard saja tidak cukup: header Authorization request terakhir
     * masih menempel pada container, sehingga Sanctum akan me-resolve ulang
     * pengguna yang sama. Worker sungguhan tidak punya request itu.
     */
    protected function becomeAWorker(): void
    {
        $this->app->instance('request', Request::create('/', 'GET'));
        $this->app['auth']->forgetGuards();

        $this->assertFalse(Auth::check());
    }
}
