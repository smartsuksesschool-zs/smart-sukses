<?php

namespace Tests\Feature\Portal;

use App\Enums\RoleName;
use App\Enums\StudentFeeStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\ReportCard;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pagar tingkat-baris untuk peran yang hanya berhak atas satu siswa.
 *
 * `SchoolScope` menjawab "cabang mana", dan itu memadai untuk peran yang memang
 * melihat seluruh siswa cabangnya. ORANG_TUA dan SISWA berbeda: keduanya
 * memegang izin baca modul menurut matriks PRD 1.1.2, sehingga pemeriksaan
 * izin + cabang saja meloloskan mereka ke seluruh record cabang itu.
 *
 * Yang diuji di sini adalah jalur **generik** — bukan Parent Portal — karena
 * di situlah pagar barunya paling mudah terlewat (butir 170).
 */
class RowLevelAccessTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected AcademicYear $yearA;

    protected User $parentA;

    protected User $parentB;

    protected Student $childA;

    protected Student $childB;

    protected Student $childC;

    protected StudentFee $feeA;

    protected StudentFee $feeB;

    protected StudentFee $feeC;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create();
        $this->schoolB = School::factory()->create();

        $this->yearA = AcademicYear::factory()->create([
            'school_id' => $this->schoolA->id,
            'is_active' => true,
        ]);

        $this->parentA = $this->userIn($this->schoolA, RoleName::OrangTua);
        $this->parentB = $this->userIn($this->schoolA, RoleName::OrangTua);

        $this->childA = $this->childOf($this->parentA, $this->schoolA, 'Anak A');
        $this->childB = $this->childOf($this->parentB, $this->schoolA, 'Anak B');
        $this->childC = $this->childOf(
            $this->userIn($this->schoolB, RoleName::OrangTua),
            $this->schoolB,
            'Anak C',
        );

        $this->feeA = $this->feeFor($this->childA);
        $this->feeB = $this->feeFor($this->childB);
        $this->feeC = $this->feeFor($this->childC);
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

    protected function childOf(?User $parent, School $school, string $name, array $overrides = []): Student
    {
        return Student::factory()->create([
            'school_id' => $school->id,
            'parent_user_id' => $parent?->getKey(),
            'full_name' => $name,
            'status' => StudentStatus::Active->value,
            ...$overrides,
        ]);
    }

    protected function feeFor(Student $student): StudentFee
    {
        return StudentFee::factory()->create([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'fee_type_id' => FeeType::factory()->create(['school_id' => $student->school_id])->id,
            'amount' => '1000000',
            'amount_paid' => '0',
            'period' => '2026-08',
            'status' => StudentFeeStatus::Unpaid->value,
        ]);
    }

    protected function reportCardFor(Student $student, bool $published = true): ReportCard
    {
        $class = SchoolClass::factory()->create([
            'school_id' => $student->school_id,
            'academic_year_id' => $this->yearA->id,
        ]);

        return ReportCard::factory()->create([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'class_id' => $class->id,
            'academic_year_id' => $this->yearA->id,
            'is_published' => $published,
            'published_at' => $published ? CarbonImmutable::now() : null,
        ]);
    }

    // --------------------------------------------- tagihan: jalur generik

    /**
     * Sebelum perbaikan ini, daftar generik mengembalikan seluruh tagihan
     * cabang kepada orang tua mana pun — termasuk tagihan anak orang lain.
     */
    public function test_a_parent_only_sees_their_own_childs_fees_in_the_generic_list(): void
    {
        $body = $this->asUser($this->parentA)
            ->getJson('/api/v1/student-fees')
            ->assertOk();

        $this->assertCount(1, $body->json('data'));
        $this->assertSame($this->feeA->id, $body->json('data.0.id'));
    }

    public function test_a_student_id_filter_cannot_reach_another_childs_fees(): void
    {
        $this->asUser($this->parentA)
            ->getJson('/api/v1/student-fees?student_id='.$this->childB->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_another_childs_fee_is_a_404_for_a_parent(): void
    {
        $this->asUser($this->parentA)
            ->getJson('/api/v1/student-fees/'.$this->feeB->id)
            ->assertStatus(404);
    }

    public function test_a_cross_school_fee_stays_a_404(): void
    {
        $this->asUser($this->parentA)
            ->getJson('/api/v1/student-fees/'.$this->feeC->id)
            ->assertStatus(404);
    }

    public function test_a_parent_can_still_read_their_own_childs_fee(): void
    {
        $this->asUser($this->parentA)
            ->getJson('/api/v1/student-fees/'.$this->feeA->id)
            ->assertOk()
            ->assertJsonPath('data.id', $this->feeA->id);
    }

    public function test_a_parent_with_two_children_sees_both(): void
    {
        $second = $this->childOf($this->parentA, $this->schoolA, 'Anak A2');
        $secondFee = $this->feeFor($second);

        $ids = array_column(
            $this->asUser($this->parentA)->getJson('/api/v1/student-fees')->assertOk()->json('data'),
            'id',
        );

        $this->assertEqualsCanonicalizing([$this->feeA->id, $secondFee->id], $ids);
    }

    /**
     * Peran yang memang melihat seluruh siswa cabangnya tidak boleh ikut
     * terbatasi.
     *
     * @return array<string, array{RoleName, int}>
     */
    public static function unrestrictedRoles(): array
    {
        return [
            'school admin' => [RoleName::SchoolAdmin, 2],
            'bendahara' => [RoleName::Bendahara, 2],
            'kepala sekolah' => [RoleName::KepalaSekolah, 2],
        ];
    }

    #[DataProvider('unrestrictedRoles')]
    public function test_staff_roles_keep_seeing_every_fee_in_their_branch(RoleName $role, int $expected): void
    {
        $this->asUser($this->userIn($this->schoolA, $role))
            ->getJson('/api/v1/student-fees')
            ->assertOk()
            ->assertJsonCount($expected, 'data');
    }

    #[DataProvider('unrestrictedRoles')]
    public function test_staff_roles_can_still_open_any_fee_in_their_branch(RoleName $role): void
    {
        $this->asUser($this->userIn($this->schoolA, $role))
            ->getJson('/api/v1/student-fees/'.$this->feeB->id)
            ->assertOk();
    }

    public function test_a_super_admin_still_reads_across_branches(): void
    {
        $superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create(['school_id' => null]);

        $this->asUser($superAdmin)
            ->getJson('/api/v1/student-fees')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    /**
     * SISWA tidak memegang `fee.view` sama sekali (matriks PRD 1.1.2 — Tagihan
     * SPP: SISWA ❌), jadi jalur ini tertutup sebelum pagar baris berlaku.
     */
    public function test_a_student_account_cannot_use_the_fee_api_at_all(): void
    {
        $studentUser = $this->userIn($this->schoolA, RoleName::Siswa);

        // Daftar ditolak izinnya (403); satu record disaring lebih dulu
        // sehingga menjadi 404 dan keberadaannya pun tidak terkonfirmasi.
        $this->asUser($studentUser)->getJson('/api/v1/student-fees')->assertStatus(403);
        $this->asUser($studentUser)
            ->getJson('/api/v1/student-fees/'.$this->feeA->id)
            ->assertStatus(404);
    }

    // ---------------------------------------------- pembayaran: jalur generik

    /**
     * ORANG_TUA tidak memegang izin pembayaran sama sekali, jadi endpoint ini
     * memang tertutup bagi mereka. Dikunci dengan test supaya tidak diam-diam
     * terbuka tanpa pagar barisnya ikut dipikirkan.
     */
    public function test_a_parent_cannot_use_the_generic_payments_api(): void
    {
        Payment::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_fee_id' => $this->feeB->id,
            'student_id' => $this->childB->id,
            'amount_paid' => '100000',
            'payment_date' => '2026-08-01',
            'received_by' => $this->userIn($this->schoolA, RoleName::Bendahara)->id,
        ]);

        $this->asUser($this->parentA)->getJson('/api/v1/payments')->assertStatus(403);
    }

    public function test_a_student_cannot_use_the_generic_payments_api(): void
    {
        $this->asUser($this->userIn($this->schoolA, RoleName::Siswa))
            ->getJson('/api/v1/payments')
            ->assertStatus(403);
    }

    public function test_a_bendahara_keeps_full_payment_access(): void
    {
        Payment::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_fee_id' => $this->feeB->id,
            'student_id' => $this->childB->id,
            'amount_paid' => '100000',
            'payment_date' => '2026-08-01',
            'received_by' => $this->userIn($this->schoolA, RoleName::Bendahara)->id,
        ]);

        $this->asUser($this->userIn($this->schoolA, RoleName::Bendahara))
            ->getJson('/api/v1/payments')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ------------------------------------------------------ rapor: policy

    /**
     * Tidak ada rute `/report-cards/{id}/pdf` di aplikasi ini — satu-satunya
     * jalur non-panel adalah rute portal. Yang dijaga di sini policy-nya
     * sendiri, supaya rute pertama yang membukanya kelak tidak menemukan pagar
     * yang bolong (butir 170).
     */
    public function test_the_only_non_panel_report_card_route_is_the_portal_one(): void
    {
        $reachable = collect(app('router')->getRoutes())
            ->map(fn ($route) => $route->uri())
            // Rute panel memang ada (admin/report-cards), tetapi ORANG_TUA dan
            // SISWA tidak dapat memasuki panel sama sekali — canAccessPanel
            // menolak keduanya (butir 147).
            ->reject(fn (string $uri) => str_starts_with($uri, 'admin/'))
            ->filter(fn (string $uri) => str_contains($uri, 'report-card') || str_contains($uri, 'rapor'))
            ->values()
            ->all();

        // Dua jalur portal, masing-masing dengan pagar kepemilikannya sendiri:
        // orang tua meresolusi anaknya lebih dulu (butir 162), siswa
        // meresolusi dirinya sendiri (butir 185). Keduanya hanya melayani
        // rapor yang sudah terbit.
        $this->assertEqualsCanonicalizing(
            [
                'portal/nilai/{studentId}/rapor/{reportCardId}',
                'siswa/nilai/rapor/{reportCardId}',
            ],
            $reachable,
        );
    }

    public function test_a_parent_may_only_view_their_own_childs_report_card(): void
    {
        $own = $this->reportCardFor($this->childA);
        $foreign = $this->reportCardFor($this->childB);

        $this->assertTrue($this->parentA->can('view', $own));
        $this->assertTrue($this->parentA->can('downloadPdf', $own));

        $this->assertFalse($this->parentA->can('view', $foreign));
        $this->assertFalse($this->parentA->can('downloadPdf', $foreign));
    }

    public function test_a_parent_may_not_view_a_report_card_from_another_branch(): void
    {
        $foreign = $this->reportCardFor($this->childC);

        $this->assertFalse($this->parentA->can('view', $foreign));
    }

    /**
     * SISWA memegang `report_card.view` (matriks PRD 1.1.2 — Generate Rapor:
     * SISWA ⭕), jadi batasan barisnya berlaku untuk mereka juga.
     */
    public function test_a_student_may_only_view_their_own_report_card(): void
    {
        $studentUser = $this->userIn($this->schoolA, RoleName::Siswa);
        $self = $this->childOf(null, $this->schoolA, 'Siswa Sendiri', [
            'user_id' => $studentUser->getKey(),
        ]);

        $own = $this->reportCardFor($self);
        $foreign = $this->reportCardFor($this->childB);

        $this->assertTrue($studentUser->can('view', $own));
        $this->assertFalse($studentUser->can('view', $foreign));
    }

    /**
     * Peran akademik dan administrasi tidak boleh ikut terbatasi.
     *
     * @return array<string, array{RoleName}>
     */
    public static function staffReportRoles(): array
    {
        return [
            'school admin' => [RoleName::SchoolAdmin],
            'kepala sekolah' => [RoleName::KepalaSekolah],
            'guru' => [RoleName::Guru],
            'wali kelas' => [RoleName::WaliKelas],
        ];
    }

    #[DataProvider('staffReportRoles')]
    public function test_staff_roles_keep_their_existing_report_card_access(RoleName $role): void
    {
        $staff = $this->userIn($this->schoolA, $role);
        $reportCard = $this->reportCardFor($this->childB);

        // Persis seperti sebelum perbaikan: izin modul + cabang yang sama.
        $this->assertSame(
            $staff->can('report_card.view') && $staff->school_id === $reportCard->school_id,
            $staff->can('view', $reportCard),
        );
    }

    public function test_a_super_admin_still_views_any_report_card(): void
    {
        $superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create(['school_id' => null]);

        $this->assertTrue($superAdmin->can('view', $this->reportCardFor($this->childC)));
    }

    /**
     * Rapor draf tetap mengikuti aturan lama: yang menahannya adalah jalur
     * portal, dan pagar baris tidak melonggarkannya.
     */
    public function test_a_draft_report_card_is_still_refused_through_the_portal(): void
    {
        $draft = $this->reportCardFor($this->childA, published: false);

        $this->actingAs($this->parentA)
            ->get(route('portal.report-card', [
                'studentId' => $this->childA->id,
                'reportCardId' => $draft->id,
            ]))
            ->assertNotFound();
    }
}
