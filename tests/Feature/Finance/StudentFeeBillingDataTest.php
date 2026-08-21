<?php

namespace Tests\Feature\Finance;

use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Enums\StudentFeeStatus;
use App\Models\FeeType;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use App\Services\Finance\PaymentRecorder;
use App\Services\Finance\StudentFeeWaiver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kelengkapan data tagihan pada lapisan domain.
 *
 * SPP-04 ("Sebagai Orang Tua, saya dapat melihat daftar tagihan anak, status
 * pembayaran, dan riwayat pembayaran") belum dibangun dan **tidak** dibangun di
 * batch ini. Yang diuji di sini adalah bahwa seluruh datanya sudah dapat dibaca
 * dari model yang ada — tanpa tabel baru, tanpa snapshot duplikat, dan tanpa
 * satu pun aturan bisnis tambahan.
 */
class StudentFeeBillingDataTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected Student $student;

    protected User $bendahara;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create();
        $this->student = Student::factory()->create([
            'school_id' => $this->school->id,
            'full_name' => 'Ahmad Fauzi',
        ]);
        $this->bendahara = User::factory()->forSchool($this->school)
            ->withRole(RoleName::Bendahara)->create();
    }

    protected function makeFee(array $overrides = []): StudentFee
    {
        return StudentFee::factory()->create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'fee_type_id' => FeeType::factory()->create([
                'school_id' => $this->school->id,
                'name' => 'SPP',
            ])->id,
            'amount' => 1_000_000,
            ...$overrides,
        ]);
    }

    /**
     * Semua kolom yang dibutuhkan daftar tagihan anak sudah tersedia dari satu
     * tagihan beserta relasinya.
     */
    public function test_every_field_the_parent_portal_needs_is_reachable(): void
    {
        $fee = $this->makeFee();

        app(PaymentRecorder::class)->record($fee->getKey(), [
            'payment_method' => PaymentMethod::Transfer->value,
            'amount' => 400_000,
            'payment_date' => '2026-08-05',
            'reference_number' => 'TRF-001',
        ], $this->bendahara);

        $fee = StudentFee::query()->withBillingDetail()->findOrFail($fee->getKey());

        $this->assertSame('Ahmad Fauzi', $fee->student->full_name);
        $this->assertSame('SPP', $fee->feeType->name);
        $this->assertSame('2026-08', $fee->period);
        $this->assertSame('1000000.00', (string) $fee->amount);
        $this->assertSame('400000.00', (string) $fee->amount_paid);
        $this->assertSame('600000.00', $fee->remaining());
        $this->assertSame('2026-08-10', $fee->due_date->toDateString());
        $this->assertSame(StudentFeeStatus::Partial, $fee->status);

        $payment = $fee->payments->first();

        $this->assertSame('2026-08-05', $payment->payment_date->toDateString());
        $this->assertSame(PaymentMethod::Transfer, $payment->payment_method);
        $this->assertSame('400000.00', (string) $payment->amount_paid);
        $this->assertSame('TRF-001', $payment->reference_number);
        $this->assertSame($this->bendahara->id, $payment->receivedBy->id);
    }

    public function test_a_waived_fee_exposes_its_reason(): void
    {
        $fee = $this->makeFee();

        app(StudentFeeWaiver::class)->waive(
            $fee->getKey(),
            'Beasiswa penuh yayasan',
            User::factory()->forSchool($this->school)->withRole(RoleName::SchoolAdmin)->create(),
        );

        $fee->refresh();

        $this->assertTrue($fee->isWaived());
        $this->assertSame('Beasiswa penuh yayasan', $fee->waive_reason);
        // Tagihan yang dibebaskan tetap menampilkan nominalnya apa adanya;
        // sisanya bukan nol karena tidak ada uang yang masuk.
        $this->assertSame('1000000.00', $fee->remaining());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function remainingCases(): array
    {
        return [
            'belum bayar' => ['1000000.00', '0.00', '1000000.00'],
            'sebagian' => ['1000000.00', '400000.00', '600000.00'],
            'lunas' => ['1000000.00', '1000000.00', '0.00'],
            // Data yang menyimpang tidak boleh menghasilkan sisa negatif.
            'lebih bayar dari data lama' => ['1000000.00', '1200000.00', '0.00'],
            'pecahan' => ['0.30', '0.10', '0.20'],
        ];
    }

    #[DataProvider('remainingCases')]
    public function test_remaining_is_derived_safely(string $amount, string $paid, string $expected): void
    {
        $fee = new StudentFee(['amount' => $amount, 'amount_paid' => $paid]);

        $this->assertSame($expected, $fee->remaining());
    }

    /**
     * Satu-satunya rumus sisa tagihan: pembungkus lama pada PaymentRecorder
     * meneruskan ke model, bukan menghitung ulang dengan caranya sendiri.
     */
    public function test_the_service_helper_and_the_model_agree(): void
    {
        $fee = $this->makeFee(['amount_paid' => 250_000]);

        $this->assertSame($fee->remaining(), PaymentRecorder::remainingFor($fee));
    }

    /**
     * Portal orang tua membaca tagihan per anak; scope-nya tersedia tanpa
     * melonggarkan isolasi tenant.
     */
    public function test_fees_can_be_scoped_to_a_single_student(): void
    {
        $mine = $this->makeFee();
        $otherStudent = Student::factory()->create(['school_id' => $this->school->id]);
        $theirs = $this->makeFee(['student_id' => $otherStudent->id]);

        $this->actingAs($this->bendahara);

        $ids = StudentFee::query()->forStudent($this->student->id)->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    /**
     * `withBillingDetail()` memuat seluruh relasinya sekaligus — bukan satu
     * query per tagihan saat riwayatnya dibaca.
     */
    public function test_the_billing_detail_scope_avoids_per_row_queries(): void
    {
        $fees = collect(range(1, 5))->map(fn () => $this->makeFee());

        foreach ($fees as $fee) {
            app(PaymentRecorder::class)->record($fee->getKey(), [
                'payment_method' => PaymentMethod::Cash->value,
                'amount' => 100_000,
                'payment_date' => '2026-08-05',
            ], $this->bendahara);
        }

        DB::enableQueryLog();

        StudentFee::query()->withBillingDetail()->get()->each(function (StudentFee $fee): void {
            $fee->student->full_name;
            $fee->feeType->name;
            $fee->payments->each(fn ($payment) => $payment->receivedBy?->name);
        });

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Satu query tagihan + satu per relasi yang dimuat (student, feeType,
        // payments, payments.receivedBy).
        $this->assertLessThanOrEqual(5, $queries);
    }

    /**
     * Batch ini tidak membuat tabel, kolom, maupun rute baru untuk portal.
     */
    public function test_no_new_billing_tables_were_introduced(): void
    {
        foreach (['student_fee_snapshots', 'billing_summaries', 'refunds', 'student_credits'] as $table) {
            $this->assertFalse(
                Schema::hasTable($table),
                "Tabel {$table} seharusnya tidak dibuat pada batch ini.",
            );
        }

        // Portal orang tua akhirnya dibuat pada Sprint 7, dan rutenya memang
        // ada sekarang. Yang dijaga karena itu bergeser: portal hanya membaca
        // tagihan, dan tidak boleh membawa satu pun jalur tulis keuangan
        // sendiri (butir 154).
        $writeMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

        $billingWriteRoutes = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'portal'))
            ->filter(fn ($route) => array_intersect($writeMethods, $route->methods()) !== [])
            // Keluar dari portal memang POST, dan tidak menyentuh keuangan.
            ->reject(fn ($route) => $route->getName() === 'portal.logout')
            ->map(fn ($route) => $route->uri())
            ->all();

        $this->assertSame(
            [],
            $billingWriteRoutes,
            'Portal orang tua tidak boleh punya jalur tulis apa pun.',
        );
    }
}
