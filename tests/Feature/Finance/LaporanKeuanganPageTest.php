<?php

namespace Tests\Feature\Finance;

use App\Enums\PaymentMethod;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\TransactionType;
use App\Filament\Pages\LaporanKeuangan;
use App\Models\FeeType;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\FinanceSummaryService;
use App\Services\Finance\PaymentRecorder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * KAS-02 — dashboard keuangan **satu cabang**.
 *
 * PRD 1.1.2 modul "Laporan Keuangan": SUPER_ADMIN ✅, SCHOOL_ADMIN ✅,
 * KEPALA ⭕, GURU/WALI ❌, BENDAHARA ✅, SISWA ❌, ORTU ❌.
 */
class LaporanKeuanganPageTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create();
        $this->schoolB = School::factory()->create();
    }

    protected function transaction(School $school, TransactionType $type, string $amount, string $date): Transaction
    {
        return Transaction::factory()->create([
            'school_id' => $school->id,
            'created_by' => User::factory()->forSchool($school)->create()->id,
            'type' => $type->value,
            'amount' => $amount,
            'transaction_date' => $date,
        ]);
    }

    protected function payFor(School $school, string $amount, string $date): void
    {
        $bendahara = User::factory()->forSchool($school)->withRole(RoleName::Bendahara)->create();

        $fee = StudentFee::factory()->create([
            'school_id' => $school->id,
            'student_id' => Student::factory()->create(['school_id' => $school->id])->id,
            'fee_type_id' => FeeType::factory()->create(['school_id' => $school->id])->id,
            'amount' => '9000000',
        ]);

        app(PaymentRecorder::class)->record($fee->getKey(), [
            'payment_method' => PaymentMethod::Cash->value,
            'amount' => $amount,
            'payment_date' => $date,
        ], $bendahara);
    }

    // ----------------------------------------------------------------- RBAC

    /**
     * KAS-02 menyebut "Kepala Sekolah / Admin" sebagai penggunanya, dan
     * matriks "Laporan Keuangan" memberi Bendahara ✅ pula — dasar aksesnya
     * adalah matriks itu, bukan `accounting.*` milik KAS-01.
     *
     * @return array<string, array{RoleName}>
     */
    public static function rolesWithAccess(): array
    {
        return [
            'kepala sekolah' => [RoleName::KepalaSekolah],
            'admin sekolah' => [RoleName::SchoolAdmin],
            'bendahara' => [RoleName::Bendahara],
        ];
    }

    #[DataProvider('rolesWithAccess')]
    public function test_authorized_roles_open_the_dashboard(RoleName $role): void
    {
        $user = User::factory()->forSchool($this->schoolA)->withRole($role)->create();

        $this->actingAs($user);

        $this->assertTrue($user->can(PermissionName::FinancialReportView->value));
        $this->assertTrue(LaporanKeuangan::canAccess());
        $this->get(LaporanKeuangan::getUrl())->assertSuccessful();
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function rolesWithoutAccess(): array
    {
        return [
            'guru' => [RoleName::Guru],
            'wali kelas' => [RoleName::WaliKelas],
        ];
    }

    #[DataProvider('rolesWithoutAccess')]
    public function test_roles_outside_the_module_are_rejected(RoleName $role): void
    {
        $user = User::factory()->forSchool($this->schoolA)->withRole($role)->create();

        $this->actingAs($user);

        $this->assertFalse($user->can(PermissionName::FinancialReportView->value));
        $this->assertFalse(LaporanKeuangan::canAccess());
        $this->get(LaporanKeuangan::getUrl())->assertForbidden();
    }

    public function test_students_and_parents_never_reach_the_dashboard(): void
    {
        foreach ([RoleName::Siswa, RoleName::OrangTua] as $role) {
            $this->actingAs(User::factory()->forSchool($this->schoolA)->withRole($role)->create());

            $this->get(LaporanKeuangan::getUrl())->assertForbidden();
        }
    }

    /**
     * `canAccess()` hanya menyembunyikan menu dari navigasi; rutenya tetap ada
     * dan dapat diketik langsung. Yang diuji di sini adalah URL-nya, bukan
     * hilangnya tautan — itulah permukaan serangan yang sebenarnya.
     */
    public function test_the_route_itself_is_guarded_not_only_the_menu(): void
    {
        $guru = User::factory()->forSchool($this->schoolA)->withRole(RoleName::Guru)->create();

        $this->actingAs($guru);

        // Menu tersembunyi — Filament menyaring navigasinya lewat canAccess().
        $this->assertFalse(LaporanKeuangan::canAccess());

        // Dan URL-nya tetap ditolak walaupun diketik langsung.
        $this->get(LaporanKeuangan::getUrl())->assertForbidden();
        $this->get('/admin/laporan-keuangan')->assertForbidden();
    }

    // --------------------------------------------------------------- angka

    public function test_the_dashboard_shows_the_three_documented_metrics(): void
    {
        $this->transaction($this->schoolA, TransactionType::Income, '5000000', '2026-08-02');
        $this->transaction($this->schoolA, TransactionType::Expense, '1500000', '2026-08-10');
        $this->payFor($this->schoolA, '2250000', '2026-08-15');

        $this->actingAs(User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::KepalaSekolah)->create());

        $page = Livewire::test(LaporanKeuangan::class)
            ->set('data.period', '2026-08')
            ->assertSuccessful();

        $summary = $page->get('summary');

        $this->assertSame('3500000.00', $summary['cash_balance']);
        $this->assertSame('2250000.00', $summary['spp_received']);
        $this->assertSame('1500000.00', $summary['expenses']);
        $this->assertCount(FinanceSummaryService::TREND_MONTHS, $summary['trend']);
    }

    public function test_the_period_defaults_to_the_current_month(): void
    {
        $this->actingAs(User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::Bendahara)->create());

        Livewire::test(LaporanKeuangan::class)
            ->assertSet('data.period', now()->format('Y-m'));
    }

    public function test_changing_the_period_recalculates_the_numbers(): void
    {
        $this->transaction($this->schoolA, TransactionType::Expense, '400000', '2026-07-15');
        $this->transaction($this->schoolA, TransactionType::Expense, '900000', '2026-08-15');

        $this->actingAs(User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::Bendahara)->create());

        $page = Livewire::test(LaporanKeuangan::class)->set('data.period', '2026-07');
        $this->assertSame('400000.00', $page->get('summary')['expenses']);

        $page->set('data.period', '2026-08');
        $this->assertSame('900000.00', $page->get('summary')['expenses']);
    }

    public function test_an_invalid_period_leaves_the_summary_empty_instead_of_crashing(): void
    {
        $this->actingAs(User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::Bendahara)->create());

        Livewire::test(LaporanKeuangan::class)
            ->set('data.period', '2026-13')
            ->assertSuccessful()
            ->assertSet('summary', null);
    }

    // -------------------------------------------------------------- tenant

    public function test_a_school_level_user_only_ever_sees_its_own_branch(): void
    {
        $this->transaction($this->schoolA, TransactionType::Income, '1000000', '2026-08-05');
        $this->transaction($this->schoolB, TransactionType::Income, '9999999', '2026-08-05');
        $this->payFor($this->schoolB, '8888888', '2026-08-05');

        $this->actingAs(User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::Bendahara)->create());

        $summary = Livewire::test(LaporanKeuangan::class)
            ->set('data.period', '2026-08')
            ->get('summary');

        $this->assertSame('1000000.00', $summary['cash_balance']);
        $this->assertSame('0.00', $summary['spp_received']);
    }

    /**
     * Field cabang tidak pernah dirender untuk peran School Level; apa pun yang
     * muncul di state Livewire adalah selundupan dan diabaikan.
     */
    public function test_a_crafted_branch_cannot_move_a_school_level_user_across_tenants(): void
    {
        $this->transaction($this->schoolA, TransactionType::Income, '1000000', '2026-08-05');
        $this->transaction($this->schoolB, TransactionType::Income, '9999999', '2026-08-05');

        $this->actingAs(User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::Bendahara)->create());

        $summary = Livewire::test(LaporanKeuangan::class)
            ->set('data.period', '2026-08')
            ->set('data.school_id', $this->schoolB->id)
            ->get('summary');

        $this->assertSame('1000000.00', $summary['cash_balance']);
    }

    public function test_a_school_level_account_without_a_branch_sees_nothing(): void
    {
        $this->transaction($this->schoolA, TransactionType::Income, '1000000', '2026-08-05');

        $orphan = User::factory()->withRole(RoleName::Bendahara)->create(['school_id' => null]);

        $this->actingAs($orphan);

        Livewire::test(LaporanKeuangan::class)
            ->set('data.period', '2026-08')
            ->assertSet('summary', null);
    }

    // ---------------------------------------------------------- super admin

    /**
     * KAS-02 adalah dashboard satu cabang; penjumlahan lintas cabang adalah
     * KAS-03 dan tidak dilakukan diam-diam di sini.
     */
    public function test_super_admin_must_choose_a_branch_before_any_numbers_appear(): void
    {
        $this->transaction($this->schoolA, TransactionType::Income, '1000000', '2026-08-05');
        $this->transaction($this->schoolB, TransactionType::Income, '2000000', '2026-08-05');

        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(LaporanKeuangan::class)
            ->assertSuccessful()
            ->assertSet('summary', null);
    }

    public function test_super_admin_sees_one_branch_at_a_time_never_the_sum(): void
    {
        $this->transaction($this->schoolA, TransactionType::Income, '1000000', '2026-08-05');
        $this->transaction($this->schoolB, TransactionType::Income, '2000000', '2026-08-05');

        $this->actingAs(User::factory()->superAdmin()->create());

        $page = Livewire::test(LaporanKeuangan::class)
            ->set('data.period', '2026-08')
            ->set('data.school_id', $this->schoolA->id);

        $this->assertSame('1000000.00', $page->get('summary')['cash_balance']);

        $page->set('data.school_id', $this->schoolB->id);

        $this->assertSame('2000000.00', $page->get('summary')['cash_balance']);
        // Bukan 3000000 — tidak ada agregasi lintas cabang di halaman ini.
        $this->assertNotSame('3000000.00', $page->get('summary')['cash_balance']);
    }

    // ------------------------------------------------------------- tampilan

    public function test_the_page_renders_the_cards_and_the_trend(): void
    {
        $this->transaction($this->schoolA, TransactionType::Income, '1000000', '2026-08-05');

        $this->actingAs(User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::KepalaSekolah)->create());

        Livewire::test(LaporanKeuangan::class)
            ->set('data.period', '2026-08')
            ->assertSee('Saldo Kas')
            ->assertSee('Penerimaan SPP Bulan Ini')
            ->assertSee('Pengeluaran Bulan Ini')
            ->assertSee('Tren 6 Bulan Terakhir');
    }

    /**
     * KAS-03 belum ada, dan halaman ini tidak boleh mulai mengerjakannya.
     */
    public function test_the_page_shows_no_cross_branch_comparison(): void
    {
        $this->transaction($this->schoolA, TransactionType::Income, '1000000', '2026-08-05');

        $this->actingAs(User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::KepalaSekolah)->create());

        Livewire::test(LaporanKeuangan::class)
            ->set('data.period', '2026-08')
            ->assertDontSee($this->schoolB->name)
            ->assertDontSee('Persentase Lunas');
    }

    /**
     * Tinggi batang tren diskalakan terhadap nilai tertinggi, dan tidak pernah
     * membagi dengan nol saat belum ada data.
     */
    public function test_the_trend_scale_is_safe_when_there_is_no_data(): void
    {
        $this->actingAs(User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::Bendahara)->create());

        $page = Livewire::test(LaporanKeuangan::class)->set('data.period', '2026-08');

        $this->assertSame('0.00', $page->instance()->trendPeak());
        $this->assertSame(0.0, $page->instance()->trendBarHeight('0.00'));
    }

    public function test_the_trend_scale_tracks_the_tallest_bar(): void
    {
        $this->transaction($this->schoolA, TransactionType::Income, '1000000', '2026-08-05');
        $this->transaction($this->schoolA, TransactionType::Expense, '500000', '2026-08-06');

        $this->actingAs(User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::Bendahara)->create());

        $page = Livewire::test(LaporanKeuangan::class)->set('data.period', '2026-08');

        $this->assertSame('1000000.00', $page->instance()->trendPeak());
        $this->assertSame(100.0, $page->instance()->trendBarHeight('1000000.00'));
        $this->assertSame(50.0, $page->instance()->trendBarHeight('500000.00'));
    }
}
