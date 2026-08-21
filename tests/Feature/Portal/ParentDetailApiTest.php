<?php

namespace Tests\Feature\Portal;

use App\Enums\AssessmentType;
use App\Enums\DayOfWeek;
use App\Enums\GradeType;
use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Enums\StudentClassStatus;
use App\Enums\StudentFeeStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\ClassSubject;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\Payment;
use App\Models\ReportCard;
use App\Models\Schedule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentFee;
use App\Models\Subject;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * API 4.11 — nilai, tagihan, dan jadwal anak (NILAI-04, SPP-04).
 *
 * Pagar kepemilikannya sama persis dengan Batch 7.1, dan diuji ulang pada
 * ketiga endpoint: yang dijaga bukan hanya bentuk responsnya, melainkan bahwa
 * tidak satu pun dari ketiganya menjadi celah baru ke data anak orang lain.
 */
class ParentDetailApiTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected AcademicYear $yearA;

    protected User $parentA;

    protected Student $child;

    protected SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create();
        $this->schoolB = School::factory()->create();

        $this->yearA = AcademicYear::factory()->create([
            'school_id' => $this->schoolA->id,
            'is_active' => true,
            'name' => '2026/2027',
        ]);

        $this->parentA = $this->userIn($this->schoolA, RoleName::OrangTua);
        $this->child = $this->childOf($this->parentA, $this->schoolA, 'Ahmad Fauzi');
        $this->class = $this->placeInClass($this->child, $this->yearA, '7A');
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

    protected function placeInClass(Student $student, AcademicYear $year, string $name): SchoolClass
    {
        $class = SchoolClass::factory()->create([
            'school_id' => $student->school_id,
            'academic_year_id' => $year->id,
            'name' => $name,
        ]);

        StudentClass::factory()->create([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'class_id' => $class->id,
            'academic_year_id' => $year->id,
            'status' => StudentClassStatus::Active->value,
        ]);

        return $class;
    }

    protected function classSubject(SchoolClass $class, string $subjectName, ?User $teacher = null): ClassSubject
    {
        $subject = Subject::factory()->create([
            'school_id' => $class->school_id,
            'name' => $subjectName,
        ]);

        return ClassSubject::factory()->create([
            'school_id' => $class->school_id,
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $class->academic_year_id,
            'teacher_id' => $teacher?->getKey()
                ?? $this->userIn(School::find($class->school_id), RoleName::Guru)->id,
        ]);
    }

    protected function gradeOn(ClassSubject $classSubject, float $score, GradeType $type = GradeType::Daily, ?AssessmentType $assessment = null): Grade
    {
        return Grade::factory()->create([
            'school_id' => $this->child->school_id,
            'student_id' => $this->child->id,
            'class_subject_id' => $classSubject->id,
            'academic_year_id' => $classSubject->academic_year_id,
            'grade_type' => $type->value,
            'assessment_type' => $assessment?->value ?? AssessmentType::Formative->value,
            'score' => $score,
        ]);
    }

    protected function feeFor(Student $student, array $overrides = []): StudentFee
    {
        return StudentFee::factory()->create([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'fee_type_id' => FeeType::factory()->create([
                'school_id' => $student->school_id,
                'name' => 'SPP',
            ])->id,
            'amount' => '1000000',
            'amount_paid' => '0',
            'period' => '2026-08',
            'status' => StudentFeeStatus::Unpaid->value,
            ...$overrides,
        ]);
    }

    protected function paymentOn(StudentFee $fee, string $amount, string $date, PaymentMethod $method = PaymentMethod::Cash): Payment
    {
        return Payment::factory()->create([
            'school_id' => $fee->school_id,
            'student_fee_id' => $fee->id,
            'student_id' => $fee->student_id,
            'amount_paid' => $amount,
            'payment_date' => $date,
            'payment_method' => $method->value,
            'received_by' => $this->userIn(School::find($fee->school_id), RoleName::Bendahara)->id,
            'proof_url' => 'payment-proofs/'.$fee->school_id.'/rahasia.pdf',
            'reference_number' => 'TRX-001',
        ]);
    }

    protected function scheduleOn(ClassSubject $classSubject, DayOfWeek $day, string $start, string $end, ?string $room = null): Schedule
    {
        return Schedule::factory()->create([
            'school_id' => $classSubject->school_id,
            'class_subject_id' => $classSubject->id,
            'day_of_week' => $day->value,
            'start_time' => $start,
            'end_time' => $end,
            'room' => $room,
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function detailEndpoints(): array
    {
        return [
            'nilai' => ['grades'],
            'tagihan' => ['fees'],
            'jadwal' => ['schedule'],
        ];
    }

    // -------------------------------------------------------------- akses

    #[DataProvider('detailEndpoints')]
    public function test_each_endpoint_requires_a_token(string $endpoint): void
    {
        $this->getJson("/api/v1/parent/children/{$this->child->id}/{$endpoint}")
            ->assertStatus(401);
    }

    #[DataProvider('detailEndpoints')]
    public function test_a_non_parent_is_refused(string $endpoint): void
    {
        $this->asUser($this->userIn($this->schoolA, RoleName::SchoolAdmin))
            ->getJson("/api/v1/parent/children/{$this->child->id}/{$endpoint}")
            ->assertStatus(403);
    }

    #[DataProvider('detailEndpoints')]
    public function test_a_parent_reads_their_own_child(string $endpoint): void
    {
        $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/{$endpoint}")
            ->assertOk()
            ->assertJsonPath('data.child.id', $this->child->id);
    }

    #[DataProvider('detailEndpoints')]
    public function test_another_parents_child_in_the_same_branch_is_a_404(string $endpoint): void
    {
        $foreign = $this->childOf(
            $this->userIn($this->schoolA, RoleName::OrangTua),
            $this->schoolA,
            'Anak Orang Lain',
        );

        $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$foreign->id}/{$endpoint}")
            ->assertStatus(404);
    }

    #[DataProvider('detailEndpoints')]
    public function test_a_child_from_another_branch_is_a_404(string $endpoint): void
    {
        $foreign = $this->childOf(
            $this->userIn($this->schoolB, RoleName::OrangTua),
            $this->schoolB,
            'Anak Cabang B',
        );

        $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$foreign->id}/{$endpoint}?school_id={$this->schoolB->id}")
            ->assertStatus(404);
    }

    #[DataProvider('detailEndpoints')]
    public function test_an_unknown_child_is_a_404(string $endpoint): void
    {
        $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/999999/{$endpoint}")
            ->assertStatus(404);
    }

    #[DataProvider('detailEndpoints')]
    public function test_a_school_less_parent_is_refused(string $endpoint): void
    {
        $orphan = User::factory()->withRole(RoleName::OrangTua)->create(['school_id' => null]);

        $this->asUser($orphan)
            ->getJson("/api/v1/parent/children/{$this->child->id}/{$endpoint}")
            ->assertStatus(403);
    }

    // --------------------------------------------------------------- nilai

    public function test_grades_are_grouped_per_subject(): void
    {
        $math = $this->classSubject($this->class, 'Matematika');
        $this->gradeOn($math, 80);
        $this->gradeOn($math, 90, GradeType::Midterm, AssessmentType::Summative);
        $this->gradeOn($this->classSubject($this->class, 'IPA'), 75);

        $body = $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/grades")
            ->assertOk();

        $subjects = collect($body->json('data.subjects'))->keyBy('subject_name');

        $this->assertCount(2, $subjects);
        // Dua penilaian pada mapel yang sama tetap satu mapel, dengan dua
        // komponen di dalamnya.
        $this->assertCount(2, $subjects['Matematika']['components']);
        $this->assertCount(1, $subjects['IPA']['components']);
    }

    /**
     * Perbedaan jenis penilaian dan sifat formatif/sumatif dipertahankan —
     * meringkasnya menjadi satu angka akan menghilangkan alasan angka itu
     * terbentuk (butir 161).
     */
    public function test_assessment_distinctions_are_preserved(): void
    {
        $math = $this->classSubject($this->class, 'Matematika');
        $this->gradeOn($math, 80, GradeType::Daily, AssessmentType::Formative);
        $this->gradeOn($math, 95, GradeType::Final, AssessmentType::Summative);

        $components = $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/grades")
            ->assertOk()
            ->json('data.subjects.0.components');

        $this->assertEqualsCanonicalizing(
            [GradeType::Daily->value, GradeType::Final->value],
            array_column($components, 'grade_type'),
        );
        $this->assertEqualsCanonicalizing(
            [AssessmentType::Formative->value, AssessmentType::Summative->value],
            array_column($components, 'assessment_type'),
        );

        foreach ($components as $component) {
            $this->assertNotNull($component['grade_type_label']);
            $this->assertNotNull($component['assessment_type_label']);
        }
    }

    public function test_the_active_academic_year_is_used(): void
    {
        $this->gradeOn($this->classSubject($this->class, 'Matematika'), 80);

        $oldYear = AcademicYear::factory()->create([
            'school_id' => $this->schoolA->id,
            'is_active' => false,
        ]);
        $oldClass = SchoolClass::factory()->create([
            'school_id' => $this->schoolA->id,
            'academic_year_id' => $oldYear->id,
        ]);
        $this->gradeOn($this->classSubject($oldClass, 'Sejarah Lama'), 60);

        $body = $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/grades")
            ->assertOk();

        $body->assertJsonPath('data.academic_year.name', '2026/2027');
        $this->assertSame(
            ['Matematika'],
            array_column($body->json('data.subjects'), 'subject_name'),
        );
    }

    public function test_a_branch_without_an_active_year_answers_with_empty_data(): void
    {
        $this->yearA->forceFill(['is_active' => false])->save();

        $body = $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/grades")
            ->assertOk();

        $body->assertJsonPath('data.academic_year', null);
        $body->assertJsonPath('data.subjects', []);
        $body->assertJsonPath('data.report_card', null);
    }

    public function test_grades_of_another_child_never_appear(): void
    {
        $sibling = $this->childOf($this->parentA, $this->schoolA, 'Budi');
        $siblingClass = $this->placeInClass($sibling, $this->yearA, '7B');

        Grade::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_id' => $sibling->id,
            'class_subject_id' => $this->classSubject($siblingClass, 'Milik Budi')->id,
            'academic_year_id' => $this->yearA->id,
            'grade_type' => GradeType::Daily->value,
            'score' => 99,
        ]);

        $this->gradeOn($this->classSubject($this->class, 'Matematika'), 80);

        $body = $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/grades")
            ->assertOk();

        $this->assertSame(
            ['Matematika'],
            array_column($body->json('data.subjects'), 'subject_name'),
        );
    }

    public function test_reading_grades_mutates_nothing(): void
    {
        $grade = $this->gradeOn($this->classSubject($this->class, 'Matematika'), 80);
        $before = $grade->fresh()->getAttributes();

        AuditLog::query()->withoutGlobalScope(SchoolScope::class)->delete();

        $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/grades")
            ->assertOk();

        $this->assertSame($before, $grade->fresh()->getAttributes());
        $this->assertSame(0, AuditLog::query()->withoutGlobalScope(SchoolScope::class)->count());
    }

    public function test_internal_grade_fields_are_not_exposed(): void
    {
        $this->gradeOn($this->classSubject($this->class, 'Matematika'), 80);

        $body = $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/grades")
            ->assertOk();

        $encoded = json_encode($body->json());

        foreach (['grade_config_id', 'graded_by', 'school_id', 'class_subject_id', 'weight'] as $field) {
            $this->assertStringNotContainsString($field, $encoded);
        }
    }

    // ----------------------------------------------------------- rapor PDF

    protected function reportCard(bool $published): ReportCard
    {
        return ReportCard::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_id' => $this->child->id,
            'class_id' => $this->class->id,
            'academic_year_id' => $this->yearA->id,
            'final_scores' => ['MTK' => 85],
            'is_published' => $published,
            'published_at' => $published ? CarbonImmutable::now() : null,
        ]);
    }

    public function test_a_published_report_card_appears_and_can_be_downloaded(): void
    {
        $reportCard = $this->reportCard(published: true);

        $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/grades")
            ->assertOk()
            ->assertJsonPath('data.report_card.id', $reportCard->id)
            ->assertJsonPath('data.report_card.is_downloadable', true);

        $this->actingAs($this->parentA)
            ->get(route('portal.report-card', [
                'studentId' => $this->child->id,
                'reportCardId' => $reportCard->id,
            ]))
            ->assertOk()
            ->assertDownload();
    }

    /**
     * NILAI-04 poin 2 — rapor draf tidak pernah keluar lewat portal.
     */
    public function test_a_draft_report_card_is_neither_shown_nor_downloadable(): void
    {
        $draft = $this->reportCard(published: false);

        $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/grades")
            ->assertOk()
            ->assertJsonPath('data.report_card', null);

        $this->actingAs($this->parentA)
            ->get(route('portal.report-card', [
                'studentId' => $this->child->id,
                'reportCardId' => $draft->id,
            ]))
            ->assertNotFound();
    }

    /**
     * ReportCardPolicy memberi ORANG_TUA `report_card.view` untuk seluruh
     * cabang, jadi pagar kepemilikannya harus datang dari portal — bukan dari
     * policy (butir 162).
     */
    public function test_a_parent_cannot_download_another_childs_report_card(): void
    {
        $otherParent = $this->userIn($this->schoolA, RoleName::OrangTua);
        $foreign = $this->childOf($otherParent, $this->schoolA, 'Anak Orang Lain');
        $foreignClass = $this->placeInClass($foreign, $this->yearA, '7C');

        $foreignReport = ReportCard::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_id' => $foreign->id,
            'class_id' => $foreignClass->id,
            'academic_year_id' => $this->yearA->id,
            'is_published' => true,
            'published_at' => CarbonImmutable::now(),
        ]);

        // Lewat id anaknya sendiri maupun id anak orang lain: dua-duanya 404.
        $this->actingAs($this->parentA)
            ->get(route('portal.report-card', [
                'studentId' => $this->child->id,
                'reportCardId' => $foreignReport->id,
            ]))
            ->assertNotFound();

        $this->actingAs($this->parentA)
            ->get(route('portal.report-card', [
                'studentId' => $foreign->id,
                'reportCardId' => $foreignReport->id,
            ]))
            ->assertNotFound();
    }

    // -------------------------------------------------------------- tagihan

    public function test_every_fee_status_is_visible_with_its_remaining(): void
    {
        $this->feeFor($this->child, ['period' => '2026-05', 'status' => StudentFeeStatus::Unpaid->value]);
        $this->feeFor($this->child, [
            'period' => '2026-06', 'amount_paid' => '400000',
            'status' => StudentFeeStatus::Partial->value,
        ]);
        $this->feeFor($this->child, [
            'period' => '2026-07', 'amount_paid' => '1000000',
            'status' => StudentFeeStatus::Paid->value,
        ]);
        $this->feeFor($this->child, [
            'period' => '2026-08', 'status' => StudentFeeStatus::Waived->value,
            'waive_reason' => 'Beasiswa penuh',
        ]);

        $fees = collect($this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/fees")
            ->assertOk()
            ->json('data.fees'))->keyBy('period');

        $this->assertCount(4, $fees);
        $this->assertSame('1000000.00', $fees['2026-05']['remaining']);
        $this->assertSame('600000.00', $fees['2026-06']['remaining']);
        $this->assertSame('0.00', $fees['2026-07']['remaining']);
        // Dibebaskan tampil sebagai status tersendiri, bukan sebagai lunas.
        $this->assertSame(StudentFeeStatus::Waived->value, $fees['2026-08']['status']);
        $this->assertSame('Beasiswa penuh', $fees['2026-08']['waive_reason']);
    }

    public function test_the_payment_history_lists_every_instalment(): void
    {
        $fee = $this->feeFor($this->child, [
            'amount_paid' => '700000',
            'status' => StudentFeeStatus::Partial->value,
        ]);

        $this->paymentOn($fee, '300000', '2026-08-01');
        $this->paymentOn($fee, '400000', '2026-08-15', PaymentMethod::Transfer);

        $payments = $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/fees")
            ->assertOk()
            ->json('data.fees.0.payments');

        $this->assertCount(2, $payments);
        // Terbaru lebih dulu.
        $this->assertSame('2026-08-15', $payments[0]['payment_date']);
        $this->assertSame('400000.00', $payments[0]['amount_paid']);
        $this->assertSame(PaymentMethod::Transfer->value, $payments[0]['method']);
        $this->assertNotNull($payments[0]['method_label']);
    }

    public function test_the_fee_response_never_leaks_proof_paths_or_internal_ids(): void
    {
        $fee = $this->feeFor($this->child);
        $this->paymentOn($fee, '100000', '2026-08-01');

        $body = $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/fees")
            ->assertOk();

        $encoded = json_encode($body->json());

        foreach (['proof_url', 'payment-proofs', 'rahasia', 'received_by', 'school_id', 'notes'] as $field) {
            $this->assertStringNotContainsString($field, $encoded);
        }
    }

    public function test_fees_are_ordered_newest_period_first(): void
    {
        foreach (['2026-06', '2026-08', '2026-07'] as $period) {
            $this->feeFor($this->child, ['period' => $period]);
        }

        $periods = array_column(
            $this->asUser($this->parentA)
                ->getJson("/api/v1/parent/children/{$this->child->id}/fees")
                ->assertOk()
                ->json('data.fees'),
            'period',
        );

        $this->assertSame(['2026-08', '2026-07', '2026-06'], $periods);
    }

    public function test_a_siblings_fees_never_appear(): void
    {
        $sibling = $this->childOf($this->parentA, $this->schoolA, 'Budi');

        $this->feeFor($this->child, ['amount' => '100000']);
        $this->feeFor($sibling, ['amount' => '900000']);

        $fees = $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/fees")
            ->assertOk()
            ->json('data.fees');

        $this->assertCount(1, $fees);
        $this->assertSame('100000.00', $fees[0]['amount']);
    }

    public function test_fee_amounts_keep_two_decimals(): void
    {
        $this->feeFor($this->child, ['amount' => '1234567.89', 'amount_paid' => '0.11']);

        $fee = $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/fees")
            ->assertOk()
            ->json('data.fees.0');

        $this->assertSame('1234567.89', $fee['amount']);
        $this->assertSame('1234567.78', $fee['remaining']);
        $this->assertIsString($fee['remaining']);
    }

    // --------------------------------------------------------------- jadwal

    public function test_the_week_is_returned_in_weekday_then_time_order(): void
    {
        $math = $this->classSubject($this->class, 'Matematika');
        $science = $this->classSubject($this->class, 'IPA');

        $this->scheduleOn($science, DayOfWeek::Tuesday, '07:00:00', '08:30:00');
        $this->scheduleOn($math, DayOfWeek::Monday, '09:00:00', '10:30:00', 'R2');
        $this->scheduleOn($math, DayOfWeek::Monday, '07:00:00', '08:30:00', 'R1');

        $week = $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/schedule")
            ->assertOk()
            ->json('data.week');

        $this->assertSame(
            [
                [DayOfWeek::Monday->value, '07:00'],
                [DayOfWeek::Monday->value, '09:00'],
                [DayOfWeek::Tuesday->value, '07:00'],
            ],
            array_map(fn ($lesson) => [$lesson['day_of_week'], $lesson['start_time']], $week),
        );

        $this->assertSame('R1', $week[0]['room']);
        $this->assertSame('Matematika', $week[0]['subject_name']);
    }

    public function test_today_is_the_subset_of_the_week_matching_the_current_day(): void
    {
        $today = CarbonImmutable::now();
        $todayNumber = (int) $today->dayOfWeek === 0 ? 7 : (int) $today->dayOfWeek;
        $otherDay = $todayNumber === 1 ? 2 : 1;

        $math = $this->classSubject($this->class, 'Matematika');

        $this->scheduleOn($math, DayOfWeek::from($todayNumber), '07:00:00', '08:30:00');
        $this->scheduleOn($math, DayOfWeek::from($otherDay), '10:00:00', '11:30:00');

        $body = $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/schedule")
            ->assertOk();

        $this->assertCount(2, $body->json('data.week'));
        $this->assertCount(1, $body->json('data.today'));
        $this->assertSame($todayNumber, $body->json('data.today.0.day_of_week'));
    }

    public function test_the_teacher_name_is_shown_but_not_their_contact_details(): void
    {
        $teacher = $this->userIn($this->schoolA, RoleName::Guru, [
            'name' => 'Pak Rudi',
            'email' => 'rudi@example.test',
            'phone' => '081234567890',
        ]);

        $this->scheduleOn(
            $this->classSubject($this->class, 'Matematika', $teacher),
            DayOfWeek::Monday,
            '07:00:00',
            '08:30:00',
        );

        $body = $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/schedule")
            ->assertOk();

        $body->assertJsonPath('data.week.0.teacher_name', 'Pak Rudi');

        $encoded = json_encode($body->json());

        $this->assertStringNotContainsString('rudi@example.test', $encoded);
        $this->assertStringNotContainsString('081234567890', $encoded);
        $this->assertStringNotContainsString('teacher_id', $encoded);
    }

    /**
     * Kelasnya kelas pada tahun ajaran aktif, bukan baris StudentClass terakhir
     * (butir 164).
     */
    public function test_a_class_from_a_finished_year_is_not_used(): void
    {
        $oldYear = AcademicYear::factory()->create([
            'school_id' => $this->schoolA->id,
            'is_active' => false,
        ]);
        // Penempatan lama dibuat belakangan, jadi id-nya lebih besar.
        $oldClass = $this->placeInClass($this->child, $oldYear, '6A');

        $this->scheduleOn(
            $this->classSubject($oldClass, 'Pelajaran Lama'),
            DayOfWeek::Monday,
            '07:00:00',
            '08:30:00',
        );

        $body = $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/schedule")
            ->assertOk();

        $body->assertJsonPath('data.current_class.name', '7A');
        $body->assertJsonPath('data.week', []);
    }

    public function test_a_child_without_a_class_gets_a_valid_empty_schedule(): void
    {
        $unplaced = $this->childOf($this->parentA, $this->schoolA, 'Belum Berkelas');

        $body = $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$unplaced->id}/schedule")
            ->assertOk();

        $body->assertJsonPath('data.current_class', null);
        $body->assertJsonPath('data.today', []);
        $body->assertJsonPath('data.week', []);
    }

    public function test_a_branch_without_an_active_year_gets_a_valid_empty_schedule(): void
    {
        $this->yearA->forceFill(['is_active' => false])->save();

        $body = $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/schedule")
            ->assertOk();

        $body->assertJsonPath('data.academic_year', null);
        $body->assertJsonPath('data.current_class', null);
        $body->assertJsonPath('data.week', []);
    }

    public function test_a_class_with_no_schedule_is_not_an_error(): void
    {
        $this->classSubject($this->class, 'Matematika');

        $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/schedule")
            ->assertOk()
            ->assertJsonPath('data.week', []);
    }

    // ------------------------------------------------------------- performa

    public function test_the_grades_query_count_does_not_follow_the_number_of_subjects(): void
    {
        $this->gradeOn($this->classSubject($this->class, 'Matematika'), 80);

        $this->asUser($this->parentA)->getJson("/api/v1/parent/children/{$this->child->id}/grades");

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/grades")->assertOk();

        $small = count(DB::getQueryLog());

        foreach (['IPA', 'IPS', 'Bahasa', 'Seni', 'Olahraga'] as $name) {
            $subject = $this->classSubject($this->class, $name);
            $this->gradeOn($subject, 85);
            $this->gradeOn($subject, 75, GradeType::Midterm);
        }

        DB::flushQueryLog();

        $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/grades")->assertOk();

        DB::disableQueryLog();

        $this->assertSame($small, count(DB::getQueryLog()));
    }

    public function test_the_fees_query_count_does_not_follow_the_payment_history(): void
    {
        $fee = $this->feeFor($this->child);
        $this->paymentOn($fee, '100000', '2026-08-01');

        $this->asUser($this->parentA)->getJson("/api/v1/parent/children/{$this->child->id}/fees");

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/fees")->assertOk();

        $small = count(DB::getQueryLog());

        for ($i = 0; $i < 6; $i++) {
            $another = $this->feeFor($this->child, ['period' => '2026-0'.($i + 1)]);
            $this->paymentOn($another, '50000', '2026-08-02');
            $this->paymentOn($another, '50000', '2026-08-03');
        }

        DB::flushQueryLog();

        $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/fees")->assertOk();

        DB::disableQueryLog();

        $this->assertSame($small, count(DB::getQueryLog()));
    }

    public function test_the_schedule_query_count_does_not_follow_the_week(): void
    {
        $math = $this->classSubject($this->class, 'Matematika');
        $this->scheduleOn($math, DayOfWeek::Monday, '07:00:00', '08:30:00');

        $this->asUser($this->parentA)->getJson("/api/v1/parent/children/{$this->child->id}/schedule");

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/schedule")->assertOk();

        $small = count(DB::getQueryLog());

        foreach ([DayOfWeek::Tuesday, DayOfWeek::Wednesday, DayOfWeek::Thursday, DayOfWeek::Friday] as $day) {
            $subject = $this->classSubject($this->class, 'Mapel '.$day->value);
            $this->scheduleOn($subject, $day, '07:00:00', '08:30:00');
            $this->scheduleOn($subject, $day, '09:00:00', '10:30:00');
        }

        DB::flushQueryLog();

        $this->asUser($this->parentA)
            ->getJson("/api/v1/parent/children/{$this->child->id}/schedule")->assertOk();

        DB::disableQueryLog();

        $this->assertSame($small, count(DB::getQueryLog()));
    }
}
