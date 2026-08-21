<?php

namespace Tests\Feature\Api;

use App\Enums\PaymentMethod;
use App\Enums\PpdbStatus;
use App\Enums\RoleName;
use App\Enums\StudentFeeStatus;
use App\Enums\StudentStatus;
use App\Enums\TransactionType;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\PpdbRegistration;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Admin\SchoolStatisticsService;
use App\Services\Admin\SuperAdminDashboardService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * API 4.3 — GET /admin/dashboard dan GET /admin/schools/{id}/stats, keduanya
 * Auth Level **Super**.
 *
 * Angka-angkanya tidak didefinisikan di dokumen mana pun; yang diuji di sini
 * adalah bahwa definisi yang dipilih konsisten dengan bagian sistem yang sudah
 * ada — dan bahwa kedua endpoint tidak pernah menghitung "jumlah siswa" dengan
 * dua cara berbeda.
 */
class AdminDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create();
        $this->schoolB = School::factory()->create();

        $this->superAdmin = User::factory()->withRole(RoleName::SuperAdmin)
            ->create(['school_id' => null]);
    }

    protected function asUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }

    protected function userIn(School $school, RoleName $role, array $overrides = []): User
    {
        return User::factory()->forSchool($school)->withRole($role)->create($overrides);
    }

    protected function studentIn(School $school, StudentStatus $status = StudentStatus::Active): Student
    {
        return Student::factory()->create([
            'school_id' => $school->id,
            'status' => $status->value,
        ]);
    }

    protected function feeIn(School $school, array $overrides = []): StudentFee
    {
        return StudentFee::factory()->create([
            'school_id' => $school->id,
            'student_id' => $this->studentIn($school)->id,
            'fee_type_id' => FeeType::factory()->create(['school_id' => $school->id])->id,
            'amount' => '1000000',
            'amount_paid' => '0',
            'period' => '2026-08',
            'status' => StudentFeeStatus::Unpaid->value,
            ...$overrides,
        ]);
    }

    /**
     * Pembayaran dibuat langsung, bukan lewat PaymentRecorder: yang diuji di
     * sini adalah pembacaan statistik, dan tanggalnya perlu bebas ditentukan
     * termasuk di luar bulan berjalan.
     */
    protected function paymentIn(School $school, string $amount, string $date): Payment
    {
        $fee = $this->feeIn($school);

        return Payment::factory()->create([
            'school_id' => $school->id,
            'student_fee_id' => $fee->id,
            'student_id' => $fee->student_id,
            'amount_paid' => $amount,
            'payment_date' => $date,
            'payment_method' => PaymentMethod::Cash->value,
            'received_by' => $this->userIn($school, RoleName::Bendahara)->id,
        ]);
    }

    protected function registrationIn(School $school, PpdbStatus $status): PpdbRegistration
    {
        return PpdbRegistration::factory()->create([
            'school_id' => $school->id,
            'status' => $status->value,
        ]);
    }

    // ------------------------------------------------------------ akses

    /**
     * @return array<string, array{RoleName}>
     */
    public static function nonSuperRoles(): array
    {
        return [
            'school admin' => [RoleName::SchoolAdmin],
            'kepala sekolah' => [RoleName::KepalaSekolah],
            'bendahara' => [RoleName::Bendahara],
            'guru' => [RoleName::Guru],
            'wali kelas' => [RoleName::WaliKelas],
            'siswa' => [RoleName::Siswa],
            'orang tua' => [RoleName::OrangTua],
        ];
    }

    public function test_a_super_admin_reads_the_platform_dashboard(): void
    {
        $this->asUser($this->superAdmin)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonStructure(['data' => ['total_students', 'total_spp_collected', 'active_ppdb']]);
    }

    /**
     * Auth Level Super, dan tidak ada user story yang menimpanya. Bendahara
     * memegang `financial_report.view` tetapi itu bukan izin yang dipakai di
     * sini — endpoint ini eksklusif platform (butir 145).
     */
    #[DataProvider('nonSuperRoles')]
    public function test_no_other_role_may_read_the_platform_dashboard(RoleName $role): void
    {
        $this->asUser($this->userIn($this->schoolA, $role))
            ->getJson('/api/v1/admin/dashboard')
            ->assertStatus(403);
    }

    #[DataProvider('nonSuperRoles')]
    public function test_no_other_role_may_read_school_statistics(RoleName $role): void
    {
        $this->asUser($this->userIn($this->schoolA, $role))
            ->getJson("/api/v1/admin/schools/{$this->schoolA->id}/stats")
            ->assertStatus(403);
    }

    public function test_both_endpoints_require_a_token(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertStatus(401);
        $this->getJson("/api/v1/admin/schools/{$this->schoolA->id}/stats")->assertStatus(401);
    }

    public function test_a_super_admin_reads_one_schools_statistics(): void
    {
        $this->asUser($this->superAdmin)
            ->getJson("/api/v1/admin/schools/{$this->schoolA->id}/stats")
            ->assertOk()
            ->assertJsonPath('data.school.id', $this->schoolA->id)
            ->assertJsonPath('data.school.code', $this->schoolA->code)
            ->assertJsonStructure([
                'data' => ['school', 'period', 'student_count', 'teacher_count', 'collected_this_month', 'arrears'],
            ]);
    }

    public function test_an_unknown_school_is_a_404(): void
    {
        $this->asUser($this->superAdmin)
            ->getJson('/api/v1/admin/schools/999999/stats')
            ->assertStatus(404);
    }

    /**
     * Cabang yang ditutup tetap punya riwayat, dan itulah yang justru dicari
     * sesudahnya (butir 144).
     */
    public function test_an_inactive_school_still_reports_its_statistics(): void
    {
        $closed = School::factory()->create(['is_active' => false]);

        // `feeIn()` sudah membuat siswanya sendiri.
        $this->feeIn($closed, ['amount' => '500000']);

        $this->asUser($this->superAdmin)
            ->getJson("/api/v1/admin/schools/{$closed->id}/stats")
            ->assertOk()
            ->assertJsonPath('data.school.is_active', false)
            ->assertJsonPath('data.student_count', 1)
            ->assertJsonPath('data.arrears', '500000.00');
    }

    /**
     * Statistik tidak ada urusannya dengan konfigurasi cabang.
     */
    public function test_the_resource_never_leaks_school_configuration(): void
    {
        $body = $this->asUser($this->superAdmin)
            ->getJson("/api/v1/admin/schools/{$this->schoolA->id}/stats")
            ->assertOk();

        $this->assertSame(
            ['id', 'code', 'name', 'is_active'],
            array_keys($body->json('data.school')),
        );

        $encoded = json_encode($body->json());

        foreach (['wa_template', 'primary_color', 'logo', 'settings', 'secondary_color'] as $leak) {
            $this->assertStringNotContainsString($leak, $encoded);
        }
    }

    // --------------------------------------------------------- jumlah siswa

    public function test_total_students_counts_active_students_across_every_branch(): void
    {
        $this->studentIn($this->schoolA);
        $this->studentIn($this->schoolA);
        $this->studentIn($this->schoolB);

        $this->asUser($this->superAdmin)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.total_students', 3);
    }

    /**
     * @return array<string, array{StudentStatus}>
     */
    public static function inactiveStudentStatuses(): array
    {
        return [
            'lulus' => [StudentStatus::Graduated],
            'keluar' => [StudentStatus::DroppedOut],
            'pindah' => [StudentStatus::Transferred],
        ];
    }

    #[DataProvider('inactiveStudentStatuses')]
    public function test_students_who_have_left_are_not_counted(StudentStatus $status): void
    {
        $this->studentIn($this->schoolA);
        $this->studentIn($this->schoolA, $status);

        $this->asUser($this->superAdmin)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.total_students', 1);
    }

    /**
     * Satu definisi, dua endpoint: menjumlahkan `student_count` seluruh cabang
     * harus menghasilkan `total_students` (butir 140).
     */
    public function test_the_two_endpoints_agree_on_what_a_student_is(): void
    {
        $this->studentIn($this->schoolA);
        $this->studentIn($this->schoolA);
        $this->studentIn($this->schoolA, StudentStatus::Graduated);
        $this->studentIn($this->schoolB);
        $this->studentIn($this->schoolB, StudentStatus::Transferred);

        $dashboard = $this->asUser($this->superAdmin)
            ->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.total_students');

        $a = $this->asUser($this->superAdmin)
            ->getJson("/api/v1/admin/schools/{$this->schoolA->id}/stats")->assertOk()->json('data.student_count');

        $b = $this->asUser($this->superAdmin)
            ->getJson("/api/v1/admin/schools/{$this->schoolB->id}/stats")->assertOk()->json('data.student_count');

        $this->assertSame(3, $dashboard);
        $this->assertSame($dashboard, $a + $b);
    }

    // ---------------------------------------------------------- jumlah guru

    public function test_teachers_and_homeroom_teachers_are_both_counted(): void
    {
        $this->userIn($this->schoolA, RoleName::Guru);
        $this->userIn($this->schoolA, RoleName::WaliKelas);

        $this->asUser($this->superAdmin)
            ->getJson("/api/v1/admin/schools/{$this->schoolA->id}/stats")
            ->assertOk()
            ->assertJsonPath('data.teacher_count', 2);
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function nonTeachingRoles(): array
    {
        return [
            'school admin' => [RoleName::SchoolAdmin],
            'kepala sekolah' => [RoleName::KepalaSekolah],
            'bendahara' => [RoleName::Bendahara],
            'siswa' => [RoleName::Siswa],
            'orang tua' => [RoleName::OrangTua],
        ];
    }

    #[DataProvider('nonTeachingRoles')]
    public function test_non_teaching_roles_are_not_teachers(RoleName $role): void
    {
        $this->userIn($this->schoolA, $role);

        $this->asUser($this->superAdmin)
            ->getJson("/api/v1/admin/schools/{$this->schoolA->id}/stats")
            ->assertOk()
            ->assertJsonPath('data.teacher_count', 0);
    }

    public function test_a_deactivated_teacher_account_is_not_counted(): void
    {
        $this->userIn($this->schoolA, RoleName::Guru);
        $this->userIn($this->schoolA, RoleName::Guru, ['is_active' => false]);

        $this->asUser($this->superAdmin)
            ->getJson("/api/v1/admin/schools/{$this->schoolA->id}/stats")
            ->assertOk()
            ->assertJsonPath('data.teacher_count', 1);
    }

    public function test_a_teacher_holding_two_roles_is_counted_once(): void
    {
        $teacher = $this->userIn($this->schoolA, RoleName::Guru);
        $teacher->assignRole(RoleName::WaliKelas->value);

        $this->asUser($this->superAdmin)
            ->getJson("/api/v1/admin/schools/{$this->schoolA->id}/stats")
            ->assertOk()
            ->assertJsonPath('data.teacher_count', 1);
    }

    public function test_teachers_of_another_branch_are_not_counted(): void
    {
        $this->userIn($this->schoolA, RoleName::Guru);
        $this->userIn($this->schoolB, RoleName::Guru);
        $this->userIn($this->schoolB, RoleName::WaliKelas);

        $this->asUser($this->superAdmin)
            ->getJson("/api/v1/admin/schools/{$this->schoolA->id}/stats")
            ->assertOk()
            ->assertJsonPath('data.teacher_count', 1);
    }

    // ------------------------------------------------- terkumpul bulan ini

    public function test_collected_this_month_reads_actual_receipts(): void
    {
        $now = CarbonImmutable::now();

        $this->paymentIn($this->schoolA, '400000', $now->toDateString());
        $this->paymentIn($this->schoolA, '100000', $now->toDateString());

        $this->asUser($this->superAdmin)
            ->getJson("/api/v1/admin/schools/{$this->schoolA->id}/stats")
            ->assertOk()
            ->assertJsonPath('data.collected_this_month', '500000.00')
            ->assertJsonPath('data.period', $now->format('Y-m'));
    }

    /**
     * Batch 6.7 memperbaiki hilangnya hari terakhir bulan (butir 139); kedua
     * ujung bulan diuji di sini supaya tidak diam-diam kembali.
     */
    public function test_the_first_and_last_day_of_the_month_are_both_counted(): void
    {
        $now = CarbonImmutable::now();

        $this->paymentIn($this->schoolA, '100000', $now->startOfMonth()->toDateString());
        $this->paymentIn($this->schoolA, '250000', $now->endOfMonth()->toDateString());

        $this->asUser($this->superAdmin)
            ->getJson("/api/v1/admin/schools/{$this->schoolA->id}/stats")
            ->assertOk()
            ->assertJsonPath('data.collected_this_month', '350000.00');
    }

    public function test_neighbouring_months_stay_out(): void
    {
        $now = CarbonImmutable::now();

        $this->paymentIn($this->schoolA, '900000', $now->subMonth()->endOfMonth()->toDateString());
        $this->paymentIn($this->schoolA, '800000', $now->addMonth()->startOfMonth()->toDateString());
        $this->paymentIn($this->schoolA, '100000', $now->toDateString());

        $this->asUser($this->superAdmin)
            ->getJson("/api/v1/admin/schools/{$this->schoolA->id}/stats")
            ->assertOk()
            ->assertJsonPath('data.collected_this_month', '100000.00');
    }

    public function test_payments_of_another_branch_stay_out(): void
    {
        $now = CarbonImmutable::now();

        $this->paymentIn($this->schoolA, '100000', $now->toDateString());
        $this->paymentIn($this->schoolB, '900000', $now->toDateString());

        $this->asUser($this->superAdmin)
            ->getJson("/api/v1/admin/schools/{$this->schoolA->id}/stats")
            ->assertOk()
            ->assertJsonPath('data.collected_this_month', '100000.00');
    }

    // -------------------------------------------------------------- tunggakan

    public function test_arrears_follow_the_spp_report_rule(): void
    {
        $this->feeIn($this->schoolA, [
            'amount' => '1000000', 'amount_paid' => '0',
            'status' => StudentFeeStatus::Unpaid->value,
        ]);
        $this->feeIn($this->schoolA, [
            'amount' => '1000000', 'amount_paid' => '400000',
            'status' => StudentFeeStatus::Partial->value,
        ]);
        $this->feeIn($this->schoolA, [
            'amount' => '1000000', 'amount_paid' => '1000000',
            'status' => StudentFeeStatus::Paid->value,
        ]);
        $this->feeIn($this->schoolA, [
            'amount' => '1000000', 'amount_paid' => '0',
            'status' => StudentFeeStatus::Waived->value, 'waive_reason' => 'Beasiswa',
        ]);

        // UNPAID 1.000.000 + PARTIAL 600.000. PAID nol, WAIVED tidak ikut.
        $this->asUser($this->superAdmin)
            ->getJson("/api/v1/admin/schools/{$this->schoolA->id}/stats")
            ->assertOk()
            ->assertJsonPath('data.arrears', '1600000.00');
    }

    /**
     * "Tunggakan" pada endpoint ini tidak diberi keterangan periode, jadi yang
     * dilaporkan seluruh periode — termasuk yang jauh lebih lama daripada batas
     * daftar laporan SPP (butir 143).
     */
    public function test_arrears_span_every_period(): void
    {
        foreach (['2024-01', '2025-06', '2026-08'] as $period) {
            $this->feeIn($this->schoolA, ['period' => $period, 'amount' => '100000']);
        }

        $this->asUser($this->superAdmin)
            ->getJson("/api/v1/admin/schools/{$this->schoolA->id}/stats")
            ->assertOk()
            ->assertJsonPath('data.arrears', '300000.00');
    }

    public function test_cash_transactions_never_touch_school_statistics(): void
    {
        Transaction::factory()->create([
            'school_id' => $this->schoolA->id,
            'created_by' => $this->userIn($this->schoolA, RoleName::Bendahara)->id,
            'type' => TransactionType::Income->value,
            'amount' => '5000000',
            'transaction_date' => CarbonImmutable::now()->toDateString(),
        ]);

        $this->asUser($this->superAdmin)
            ->getJson("/api/v1/admin/schools/{$this->schoolA->id}/stats")
            ->assertOk()
            ->assertJsonPath('data.collected_this_month', '0.00')
            ->assertJsonPath('data.arrears', '0.00');
    }

    // ------------------------------------------------ total SPP terkumpul

    public function test_total_spp_collected_spans_every_branch(): void
    {
        $now = CarbonImmutable::now();

        $this->paymentIn($this->schoolA, '400000', $now->toDateString());
        $this->paymentIn($this->schoolB, '600000', $now->toDateString());

        $this->asUser($this->superAdmin)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.total_spp_collected', '1000000.00');
    }

    /**
     * Kalimatnya menyebut "total" tanpa keterangan waktu, sedangkan statistik
     * cabang menulis "bulan ini" secara eksplisit. Perbedaan itu dibaca apa
     * adanya (butir 143).
     */
    public function test_total_spp_collected_is_not_limited_to_the_current_month(): void
    {
        $now = CarbonImmutable::now();

        $this->paymentIn($this->schoolA, '300000', $now->subMonths(8)->toDateString());
        $this->paymentIn($this->schoolA, '200000', $now->toDateString());

        $this->asUser($this->superAdmin)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.total_spp_collected', '500000.00');
    }

    public function test_money_is_a_two_decimal_string(): void
    {
        $this->paymentIn($this->schoolA, '1234567.89', CarbonImmutable::now()->toDateString());

        $dashboard = $this->asUser($this->superAdmin)
            ->getJson('/api/v1/admin/dashboard')->assertOk();

        $this->assertSame('1234567.89', $dashboard->json('data.total_spp_collected'));
        $this->assertIsString($dashboard->json('data.total_spp_collected'));

        $stats = $this->asUser($this->superAdmin)
            ->getJson("/api/v1/admin/schools/{$this->schoolA->id}/stats")->assertOk();

        $this->assertIsString($stats->json('data.collected_this_month'));
        $this->assertIsString($stats->json('data.arrears'));
    }

    // ------------------------------------------------------------ PPDB aktif

    /**
     * @return array<string, array{PpdbStatus, int}>
     */
    public static function ppdbStatuses(): array
    {
        return [
            'terdaftar' => [PpdbStatus::Registered, 1],
            'verifikasi berkas' => [PpdbStatus::DocumentReview, 1],
            'lulus' => [PpdbStatus::Passed, 1],
            'tidak lulus' => [PpdbStatus::Failed, 0],
            'sudah menjadi siswa' => [PpdbStatus::Enrolled, 0],
        ];
    }

    #[DataProvider('ppdbStatuses')]
    public function test_active_ppdb_counts_registrations_still_in_the_workflow(
        PpdbStatus $status,
        int $expected,
    ): void {
        $this->registrationIn($this->schoolA, $status);

        $this->asUser($this->superAdmin)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.active_ppdb', $expected);
    }

    public function test_active_ppdb_adds_up_across_branches(): void
    {
        $this->registrationIn($this->schoolA, PpdbStatus::Registered);
        $this->registrationIn($this->schoolA, PpdbStatus::Enrolled);
        $this->registrationIn($this->schoolB, PpdbStatus::DocumentReview);
        $this->registrationIn($this->schoolB, PpdbStatus::Passed);
        $this->registrationIn($this->schoolB, PpdbStatus::Failed);

        $this->asUser($this->superAdmin)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.active_ppdb', 3);
    }

    // ------------------------------------------------------------- keamanan

    public function test_the_services_refuse_a_non_super_actor_directly(): void
    {
        $bendahara = $this->userIn($this->schoolA, RoleName::Bendahara);

        $refused = 0;

        try {
            app(SuperAdminDashboardService::class)->summarize($bendahara);
            $this->fail('Dashboard platform seharusnya menolak peran non-Super.');
        } catch (AuthorizationException) {
            $refused++;
        }

        try {
            app(SchoolStatisticsService::class)->forSchool($this->schoolA->id, $bendahara);
            $this->fail('Statistik cabang seharusnya menolak peran non-Super.');
        } catch (AuthorizationException) {
            $refused++;
        }

        // Pagarnya ada di service, bukan hanya di middleware rute: panel
        // memanggil service yang sama tanpa melewati middleware itu.
        $this->assertSame(2, $refused);
    }

    /**
     * Akun School Level tanpa cabang tidak boleh berubah menjadi lintas cabang
     * hanya karena `school_id`-nya NULL seperti Super Admin (butir 127).
     */
    public function test_a_school_less_account_is_still_refused(): void
    {
        $orphan = User::factory()->withRole(RoleName::Bendahara)->create(['school_id' => null]);

        $this->asUser($orphan)->getJson('/api/v1/admin/dashboard')->assertStatus(403);
        $this->asUser($orphan)
            ->getJson("/api/v1/admin/schools/{$this->schoolA->id}/stats")
            ->assertStatus(403);
    }

    /**
     * Statistik terkunci pada id di path, bukan pada cabang sesi yang sedang
     * berjalan — Super Admin memang tidak punya cabang, dan hasilnya harus
     * tetap berbeda antar sekolah.
     */
    public function test_statistics_follow_the_path_id_not_the_session(): void
    {
        $this->studentIn($this->schoolA);
        $this->studentIn($this->schoolB);
        $this->studentIn($this->schoolB);

        $this->assertSame(1, $this->asUser($this->superAdmin)
            ->getJson("/api/v1/admin/schools/{$this->schoolA->id}/stats")->json('data.student_count'));

        $this->assertSame(2, $this->asUser($this->superAdmin)
            ->getJson("/api/v1/admin/schools/{$this->schoolB->id}/stats")->json('data.student_count'));
    }

    // ------------------------------------------------------------- performa

    /**
     * Tidak ada perulangan per cabang: jumlah query harus sama untuk dua
     * cabang maupun dua puluh.
     */
    public function test_the_dashboard_query_count_does_not_follow_the_number_of_branches(): void
    {
        $this->studentIn($this->schoolA);

        $this->asUser($this->superAdmin)->getJson('/api/v1/admin/dashboard');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->asUser($this->superAdmin)->getJson('/api/v1/admin/dashboard')->assertOk();

        $withTwo = count(DB::getQueryLog());

        for ($i = 0; $i < 18; $i++) {
            $school = School::factory()->create();
            $this->studentIn($school);
            $this->registrationIn($school, PpdbStatus::Registered);
            $this->paymentIn($school, '100000', CarbonImmutable::now()->toDateString());
        }

        DB::flushQueryLog();

        $this->asUser($this->superAdmin)->getJson('/api/v1/admin/dashboard')->assertOk();

        $withTwenty = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertSame($withTwo, $withTwenty);
    }

    public function test_the_school_statistics_query_count_does_not_follow_the_data(): void
    {
        $this->studentIn($this->schoolA);
        $this->userIn($this->schoolA, RoleName::Guru);
        $this->feeIn($this->schoolA);

        $this->asUser($this->superAdmin)->getJson("/api/v1/admin/schools/{$this->schoolA->id}/stats");

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->asUser($this->superAdmin)
            ->getJson("/api/v1/admin/schools/{$this->schoolA->id}/stats")->assertOk();

        $small = count(DB::getQueryLog());

        for ($i = 0; $i < 15; $i++) {
            $this->studentIn($this->schoolA);
            $this->userIn($this->schoolA, RoleName::Guru);
            $this->feeIn($this->schoolA);
            $this->paymentIn($this->schoolA, '10000', CarbonImmutable::now()->toDateString());
        }

        DB::flushQueryLog();

        $this->asUser($this->superAdmin)
            ->getJson("/api/v1/admin/schools/{$this->schoolA->id}/stats")->assertOk();

        $large = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertSame($small, $large);
    }
}
