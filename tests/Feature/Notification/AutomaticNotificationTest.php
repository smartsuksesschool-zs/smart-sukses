<?php

namespace Tests\Feature\Notification;

use App\Enums\NotificationTargetType;
use App\Enums\NotificationType;
use App\Enums\PpdbStatus;
use App\Enums\RoleName;
use App\Enums\StudentClassStatus;
use App\Enums\StudentStatus;
use App\Filament\Resources\PpdbRegistrationResource\Pages\ListPpdbRegistrations;
use App\Jobs\GenerateStudentFees;
use App\Models\AcademicYear;
use App\Models\FeeType;
use App\Models\Notification;
use App\Models\PpdbRegistration;
use App\Models\ReportCard;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentFee;
use App\Models\User;
use App\Services\Finance\StudentFeeGenerator;
use App\Services\Grading\ReportCardGenerator;
use App\Services\Notification\NotificationCenter;
use App\Services\Ppdb\PpdbStatusUpdater;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * NOTIF-03 poin 1 — "Trigger tersedia untuk: PPDB status berubah, tagihan baru
 * terbit, rapor diterbitkan."
 *
 * Yang paling penting di sini bukan bahwa notifikasinya muncul, melainkan
 * **kapan ia tidak muncul**. Notifikasi otomatis ditulis tanpa ada manusia yang
 * menekan tombol kirim, jadi setiap jalur yang salah akan mengirimkan pesan
 * kepada orang tua tanpa seorang pun sempat membacanya lebih dulu: penerbitan
 * yang gagal, transaksi yang batal, permintaan kedua yang seharusnya ditolak,
 * dan — yang paling merusak — penerima yang tidak pernah ditargetkan siapa pun.
 *
 * Karena itu ketiga jalur diuji lewat jalur bisnisnya yang sebenarnya: job
 * penerbitan tagihan, service penerbitan rapor, dan aksi panel PPDB. Memanggil
 * publisher-nya langsung hanya akan membuktikan publisher-nya bekerja.
 */
class AutomaticNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected AcademicYear $yearA;

    protected SchoolClass $classA;

    protected User $adminA;

    protected User $waliA;

    protected User $parentA;

    protected Student $childA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create(['name' => 'SMP Madani']);
        $this->schoolB = School::factory()->create(['name' => 'SMP Seberang']);

        $this->yearA = AcademicYear::factory()->create([
            'school_id' => $this->schoolA->id,
            'is_active' => true,
            'name' => '2026/2027',
            'semester' => 1,
        ]);

        $this->classA = SchoolClass::factory()->create([
            'school_id' => $this->schoolA->id,
            'academic_year_id' => $this->yearA->id,
            'name' => '7A',
        ]);

        $this->adminA = $this->userIn($this->schoolA, RoleName::SchoolAdmin, ['name' => 'Admin Madani']);
        $this->waliA = $this->userIn($this->schoolA, RoleName::WaliKelas, ['name' => 'Bu Sari']);
        $this->parentA = $this->userIn($this->schoolA, RoleName::OrangTua, ['name' => 'Bapak Ahmad']);

        $this->childA = $this->studentIn($this->schoolA, 'Ahmad Fauzi', $this->parentA);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function userIn(School $school, RoleName $role, array $overrides = []): User
    {
        return User::factory()->forSchool($school)->withRole($role)->create($overrides);
    }

    protected function studentIn(School $school, string $name, ?User $parent = null): Student
    {
        $student = Student::factory()->create([
            'school_id' => $school->id,
            'parent_user_id' => $parent?->getKey(),
            'full_name' => $name,
            'status' => StudentStatus::Active->value,
        ]);

        $class = $school->is($this->schoolA)
            ? $this->classA
            : SchoolClass::factory()->create([
                'school_id' => $school->id,
                'academic_year_id' => AcademicYear::factory()->create(['school_id' => $school->id])->id,
            ]);

        StudentClass::factory()->create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'class_id' => $class->id,
            'academic_year_id' => $class->academic_year_id,
            'status' => StudentClassStatus::Active->value,
        ]);

        return $student;
    }

    protected function feeTypeIn(School $school, string $name = 'SPP Bulanan', int $amount = 150000): FeeType
    {
        return FeeType::factory()->create([
            'school_id' => $school->id,
            'name' => $name,
            'amount' => $amount,
            'academic_year_id' => null,
            'is_active' => true,
        ]);
    }

    protected function issueFees(FeeType $feeType, string $period = '2026-08'): void
    {
        // Jalur bisnis sebenarnya: job antrean yang dipakai produksi, bukan
        // pemanggilan publisher notifikasi.
        (new GenerateStudentFees(
            (int) $feeType->school_id,
            (int) $feeType->getKey(),
            $period,
            '2026-08-10',
        ))->handle(app(StudentFeeGenerator::class));
    }

    protected function reportCardFor(Student $student, bool $published = false): ReportCard
    {
        return ReportCard::factory()->create([
            'school_id' => $student->school_id,
            'student_id' => $student->getKey(),
            'class_id' => $this->classA->id,
            'academic_year_id' => $this->classA->academic_year_id,
            'final_scores' => ['MTK' => 85.0],
            'is_published' => $published,
        ]);
    }

    /**
     * @return Collection<int, Notification>
     */
    protected function systemNotifications(): Collection
    {
        return Notification::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->whereNull('sender_id')
            ->orderBy('id')
            ->get();
    }

    // ------------------------------------------------------------ 25. PPDB

    protected function registrationIn(School $school, array $overrides = []): PpdbRegistration
    {
        return PpdbRegistration::factory()->create([
            'school_id' => $school->id,
            'full_name' => 'Calon Siswa',
            'parent_name' => 'Bapak Calon',
            'parent_phone' => '081234567890',
            'status' => PpdbStatus::Registered,
            ...$overrides,
        ]);
    }

    public function test_a_real_ppdb_status_transition_runs_the_trigger_path(): void
    {
        $registration = $this->registrationIn($this->schoolA);

        $changed = app(PpdbStatusUpdater::class)->update(
            $registration,
            PpdbStatus::DocumentReview,
            'Berkas sedang diperiksa.',
        );

        $this->assertTrue($changed);
        $this->assertSame(PpdbStatus::DocumentReview, $registration->refresh()->status);
    }

    public function test_a_pre_enrolment_applicant_never_produces_a_forged_notification_target(): void
    {
        // Kasus yang paling lazim: pendaftaran ada, nomor HP ada, tetapi belum
        // ada akun apa pun. `ppdb_registrations` memang tidak menyimpan satu
        // rujukan pun ke `users` (butir 240).
        $registration = $this->registrationIn($this->schoolA);

        app(PpdbStatusUpdater::class)->update($registration, PpdbStatus::Passed, 'Lulus seleksi.');

        $this->assertSame(PpdbStatus::Passed, $registration->refresh()->status);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_the_applicant_keeps_the_manual_wa_link_when_no_account_exists(): void
    {
        $registration = $this->registrationIn($this->schoolA);

        app(PpdbStatusUpdater::class)->update($registration, PpdbStatus::Passed, 'Lulus seleksi.');

        $link = $registration->refresh()->waLink();

        $this->assertNotNull($link);
        $this->assertStringStartsWith('https://wa.me/6281234567890?text=', $link);
        $this->assertStringContainsString(rawurlencode('Calon Siswa'), $link);
    }

    public function test_an_enrolled_applicant_with_a_parent_account_receives_the_notification(): void
    {
        // Satu-satunya tautan pengguna yang benar-benar ada di repositori:
        // pendaftaran yang sudah menjadi siswa, dan siswa itu punya orang tua
        // berakun.
        $registration = $this->registrationIn($this->schoolA, [
            'status' => PpdbStatus::Passed,
            'converted_student_id' => $this->childA->getKey(),
        ]);

        app(PpdbStatusUpdater::class)->update($registration, PpdbStatus::Enrolled, 'Sudah daftar ulang.');

        $notification = $this->systemNotifications()->sole();

        $this->assertNull($notification->sender_id);
        $this->assertSame(NotificationType::System, $notification->type);
        $this->assertSame(NotificationTargetType::Individual, $notification->target_type);
        $this->assertSame($this->parentA->getKey(), (int) $notification->target_id);
        $this->assertSame($this->schoolA->id, (int) $notification->school_id);
        $this->assertFalse($notification->is_draft);
        $this->assertNotNull($notification->sent_at);
    }

    public function test_saving_the_same_status_again_creates_no_second_notification(): void
    {
        $registration = $this->registrationIn($this->schoolA, [
            'status' => PpdbStatus::Passed,
            'converted_student_id' => $this->childA->getKey(),
        ]);

        $updater = app(PpdbStatusUpdater::class);

        $this->assertTrue($updater->update($registration, PpdbStatus::Enrolled, 'Sudah daftar ulang.'));
        $this->assertSame(1, $this->systemNotifications()->count());

        // Catatan baru pada status yang sama tetap tersimpan, tetapi bukan
        // perubahan status (butir 248).
        $this->assertFalse($updater->update($registration, PpdbStatus::Enrolled, 'Catatan diperbarui.'));

        $this->assertSame(1, $this->systemNotifications()->count());
        $this->assertSame('Catatan diperbarui.', $registration->refresh()->status_notes);
    }

    public function test_each_genuine_transition_produces_its_own_notification(): void
    {
        $registration = $this->registrationIn($this->schoolA, [
            'converted_student_id' => $this->childA->getKey(),
        ]);

        $updater = app(PpdbStatusUpdater::class);
        $updater->update($registration, PpdbStatus::DocumentReview, 'Diperiksa.');
        $updater->update($registration, PpdbStatus::Passed, 'Lulus.');

        $this->assertSame(2, $this->systemNotifications()->count());
    }

    public function test_a_rolled_back_status_update_leaves_no_notification(): void
    {
        $registration = $this->registrationIn($this->schoolA, [
            'converted_student_id' => $this->childA->getKey(),
        ]);

        try {
            DB::transaction(function () use ($registration): void {
                app(PpdbStatusUpdater::class)->update($registration, PpdbStatus::Passed, 'Lulus.');

                throw new \RuntimeException('Gagal setelah perubahan status.');
            });
        } catch (\RuntimeException) {
            // Sengaja ditelan: yang diuji keadaan sesudahnya.
        }

        $this->assertSame(PpdbStatus::Registered, $registration->refresh()->status);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_a_parent_account_of_another_branch_is_never_chosen(): void
    {
        $foreignParent = $this->userIn($this->schoolB, RoleName::OrangTua);

        // Data rusak yang sengaja dibuat: siswa cabang A menunjuk akun cabang B.
        $this->childA->forceFill(['parent_user_id' => $foreignParent->getKey()])->save();

        $registration = $this->registrationIn($this->schoolA, [
            'converted_student_id' => $this->childA->getKey(),
        ]);

        app(PpdbStatusUpdater::class)->update($registration, PpdbStatus::Passed, 'Lulus.');

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_the_ppdb_panel_action_drives_the_same_path(): void
    {
        $registration = $this->registrationIn($this->schoolA, [
            'converted_student_id' => $this->childA->getKey(),
        ]);

        Livewire::actingAs($this->adminA)
            ->test(ListPpdbRegistrations::class)
            ->callTableAction('changeStatus', $registration, [
                'status' => PpdbStatus::Passed->value,
                'status_notes' => 'Lulus seleksi.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(PpdbStatus::Passed, $registration->refresh()->status);
        $this->assertSame(1, $this->systemNotifications()->count());
    }

    public function test_the_ppdb_snapshot_survives_a_later_template_edit(): void
    {
        $registration = $this->registrationIn($this->schoolA, [
            'converted_student_id' => $this->childA->getKey(),
        ]);

        app(PpdbStatusUpdater::class)->update($registration, PpdbStatus::Passed, 'Lulus.');

        $before = $this->systemNotifications()->sole()->wa_template;

        $this->schoolA->update(['wa_template_ppdb' => 'Template baru untuk [nama].']);

        $this->assertSame($before, $this->systemNotifications()->sole()->refresh()->wa_template);
    }

    // ------------------------------------------------------------- 26. Fee

    public function test_a_newly_issued_fee_notifies_the_linked_parent(): void
    {
        $feeType = $this->feeTypeIn($this->schoolA);

        $this->issueFees($feeType);

        $notification = $this->systemNotifications()->sole();

        $this->assertNull($notification->sender_id);
        $this->assertSame(NotificationType::Billing, $notification->type);
        $this->assertSame(NotificationTargetType::Individual, $notification->target_type);
        $this->assertSame($this->parentA->getKey(), (int) $notification->target_id);
        $this->assertSame($this->schoolA->id, (int) $notification->school_id);
        $this->assertSame('Tagihan baru terbit', $notification->title);
        $this->assertStringContainsString('Ahmad Fauzi', $notification->message);
        $this->assertStringContainsString('SPP Bulanan', $notification->message);
    }

    public function test_a_student_without_a_parent_account_produces_no_notification(): void
    {
        $this->childA->forceFill(['parent_user_id' => null])->save();

        $feeType = $this->feeTypeIn($this->schoolA);
        $this->issueFees($feeType);

        // Tagihannya tetap terbit — kejadian bisnisnya yang berwenang.
        $this->assertDatabaseCount('student_fees', 1);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_the_student_account_is_never_used_instead_of_the_parent(): void
    {
        $studentAccount = $this->userIn($this->schoolA, RoleName::Siswa, ['name' => 'Ahmad Fauzi']);
        $this->childA->forceFill([
            'parent_user_id' => null,
            'user_id' => $studentAccount->getKey(),
        ])->save();

        $this->issueFees($this->feeTypeIn($this->schoolA));

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_rerunning_the_issuance_creates_neither_a_second_fee_nor_a_second_notification(): void
    {
        $feeType = $this->feeTypeIn($this->schoolA);

        $this->issueFees($feeType);
        $this->issueFees($feeType);

        $this->assertDatabaseCount('student_fees', 1);
        $this->assertSame(1, $this->systemNotifications()->count());
    }

    public function test_a_fee_that_already_exists_is_skipped_without_notifying(): void
    {
        $feeType = $this->feeTypeIn($this->schoolA);

        StudentFee::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_id' => $this->childA->getKey(),
            'fee_type_id' => $feeType->getKey(),
            'period' => '2026-08',
        ]);

        $this->issueFees($feeType);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_a_rolled_back_issuance_leaves_neither_fee_nor_notification(): void
    {
        $feeType = $this->feeTypeIn($this->schoolA);

        try {
            DB::transaction(function () use ($feeType): void {
                $this->issueFees($feeType);

                throw new \RuntimeException('Batch dibatalkan.');
            });
        } catch (\RuntimeException) {
            // Sengaja ditelan.
        }

        $this->assertDatabaseCount('student_fees', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_a_preview_writes_nothing_at_all(): void
    {
        $feeType = $this->feeTypeIn($this->schoolA);

        app(StudentFeeGenerator::class)->preview($feeType, '2026-08');

        $this->assertDatabaseCount('student_fees', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_a_bulk_issuance_notifies_once_per_linked_student(): void
    {
        $secondParent = $this->userIn($this->schoolA, RoleName::OrangTua, ['name' => 'Ibu Siti']);
        $this->studentIn($this->schoolA, 'Siswa Kedua', $secondParent);
        // Siswa ketiga tanpa akun orang tua: ikut ditagih, tidak ikut
        // dinotifikasi.
        $this->studentIn($this->schoolA, 'Siswa Ketiga');

        $this->issueFees($this->feeTypeIn($this->schoolA));

        $this->assertDatabaseCount('student_fees', 3);
        $this->assertSame(2, $this->systemNotifications()->count());
    }

    public function test_two_children_of_one_parent_produce_two_fee_notifications(): void
    {
        // Dua tagihan adalah dua kejadian bisnis, jadi dua notifikasi — bukan
        // satu yang digabungkan (butir 245).
        $sibling = $this->studentIn($this->schoolA, 'Adik Ahmad', $this->parentA);

        $this->issueFees($this->feeTypeIn($this->schoolA));

        $notifications = $this->systemNotifications();

        $this->assertSame(2, $notifications->count());
        $this->assertSame(
            [$this->parentA->getKey(), $this->parentA->getKey()],
            $notifications->map(fn (Notification $n) => (int) $n->target_id)->all(),
        );

        $messages = $notifications->map(fn (Notification $n) => $n->message)->implode(' | ');
        $this->assertStringContainsString($this->childA->full_name, $messages);
        $this->assertStringContainsString($sibling->full_name, $messages);
    }

    public function test_the_fee_snapshot_survives_a_later_template_edit(): void
    {
        $this->schoolA->update(['wa_template_spp' => 'Halo [ortu], tagihan [nama] terbit.']);

        $this->issueFees($this->feeTypeIn($this->schoolA));

        $notification = $this->systemNotifications()->sole();
        $this->assertSame('Halo Bapak Ahmad, tagihan Ahmad Fauzi terbit.', $notification->wa_template);

        $this->schoolA->update(['wa_template_spp' => 'Template lain sama sekali.']);

        $this->assertSame(
            'Halo Bapak Ahmad, tagihan Ahmad Fauzi terbit.',
            $notification->refresh()->wa_template,
        );
    }

    public function test_the_default_fee_template_is_used_when_the_school_left_it_empty(): void
    {
        $this->assertNull($this->schoolA->wa_template_spp);

        $this->issueFees($this->feeTypeIn($this->schoolA));

        $waText = $this->systemNotifications()->sole()->wa_template;

        $this->assertNotEmpty($waText);
        $this->assertStringContainsString('Ahmad Fauzi', $waText);
        $this->assertStringContainsString('SMP Madani', $waText);
        $this->assertStringNotContainsString('[nama]', $waText);
        $this->assertStringNotContainsString('[ortu]', $waText);
        $this->assertStringNotContainsString('[sekolah]', $waText);
    }

    public function test_the_generated_fee_notification_yields_a_working_wa_link(): void
    {
        $this->parentA->update(['phone' => '081234567890']);

        $this->issueFees($this->feeTypeIn($this->schoolA));

        $notification = $this->systemNotifications()->sole();

        $response = $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/v1/notifications/'.$notification->getKey().'/wa-links')
            ->assertOk();

        $row = $response->json('data.recipients.0');

        $this->assertSame($this->parentA->name, $row['name']);
        $this->assertTrue($row['wa_available']);

        parse_str((string) parse_url((string) $row['wa_url'], PHP_URL_QUERY), $query);

        // Tautannya memakai snapshot wa_template, bukan template cabang yang
        // dibaca ulang saat permintaan.
        $this->assertSame($notification->wa_template, $query['text']);
    }

    public function test_the_parent_sees_the_automatic_fee_notification_in_their_feed(): void
    {
        $this->issueFees($this->feeTypeIn($this->schoolA));

        $feed = app(NotificationCenter::class)->feed($this->parentA);

        $this->assertCount(1, $feed);
        $this->assertSame('Tagihan baru terbit', $feed->first()->title);
        $this->assertSame(1, app(NotificationCenter::class)->unreadCount($this->parentA));
    }

    // ---------------------------------------------------------- 27. Rapor

    public function test_publishing_a_report_card_notifies_the_parent(): void
    {
        $reportCard = $this->reportCardFor($this->childA);

        app(ReportCardGenerator::class)->publish($reportCard, $this->waliA);

        $notification = $this->systemNotifications()->sole();

        $this->assertNull($notification->sender_id);
        $this->assertSame(NotificationType::Academic, $notification->type);
        $this->assertSame(NotificationTargetType::Individual, $notification->target_type);
        $this->assertSame($this->parentA->getKey(), (int) $notification->target_id);
        $this->assertSame($this->schoolA->id, (int) $notification->school_id);
        $this->assertSame('Rapor diterbitkan', $notification->title);
        $this->assertStringContainsString('Ahmad Fauzi', $notification->message);
    }

    public function test_generating_a_draft_report_card_notifies_nobody(): void
    {
        $this->reportCardFor($this->childA);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_a_second_publish_attempt_creates_no_second_notification(): void
    {
        $reportCard = $this->reportCardFor($this->childA);
        $generator = app(ReportCardGenerator::class);

        $generator->publish($reportCard, $this->waliA);
        $this->assertSame(1, $this->systemNotifications()->count());

        try {
            $generator->publish($reportCard->refresh(), $this->waliA);
            $this->fail('Rapor yang sudah terbit seharusnya ditolak.');
        } catch (ValidationException) {
            // Penolakan itulah pagarnya (butir 246).
        }

        $this->assertSame(1, $this->systemNotifications()->count());
    }

    public function test_a_report_card_without_a_parent_account_produces_no_notification(): void
    {
        $orphan = $this->studentIn($this->schoolA, 'Tanpa Akun Ortu');
        $reportCard = $this->reportCardFor($orphan);

        app(ReportCardGenerator::class)->publish($reportCard, $this->waliA);

        // Rapornya tetap terbit.
        $this->assertTrue($reportCard->refresh()->is_published);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_the_student_account_is_not_substituted_for_the_report_parent(): void
    {
        $studentAccount = $this->userIn($this->schoolA, RoleName::Siswa);
        $orphan = $this->studentIn($this->schoolA, 'Punya Akun Sendiri');
        $orphan->forceFill(['user_id' => $studentAccount->getKey()])->save();

        app(ReportCardGenerator::class)->publish($this->reportCardFor($orphan), $this->waliA);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_a_failed_publish_creates_no_notification(): void
    {
        $reportCard = $this->reportCardFor($this->childA, published: true);

        try {
            app(ReportCardGenerator::class)->publish($reportCard, $this->waliA);
            $this->fail('Rapor yang sudah terbit seharusnya ditolak.');
        } catch (ValidationException) {
            // Diharapkan.
        }

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_the_report_snapshot_survives_a_later_template_edit(): void
    {
        $this->schoolA->update(['wa_template_rapor' => 'Rapor [nama] di [sekolah] terbit.']);

        app(ReportCardGenerator::class)->publish($this->reportCardFor($this->childA), $this->waliA);

        $notification = $this->systemNotifications()->sole();
        $this->assertSame('Rapor Ahmad Fauzi di SMP Madani terbit.', $notification->wa_template);

        $this->schoolA->update(['wa_template_rapor' => 'Template lain.']);

        $this->assertSame('Rapor Ahmad Fauzi di SMP Madani terbit.', $notification->refresh()->wa_template);
    }

    public function test_the_generated_report_notification_yields_a_working_wa_link(): void
    {
        $this->parentA->update(['phone' => '081234567890']);

        app(ReportCardGenerator::class)->publish($this->reportCardFor($this->childA), $this->waliA);

        $notification = $this->systemNotifications()->sole();

        $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/v1/notifications/'.$notification->getKey().'/wa-links')
            ->assertOk()
            ->assertJsonPath('data.recipients.0.wa_available', true)
            ->assertJsonPath('data.summary.recipient_count', 1);
    }

    public function test_publishing_a_class_notifies_each_linked_parent_once(): void
    {
        $secondParent = $this->userIn($this->schoolA, RoleName::OrangTua, ['name' => 'Ibu Siti']);
        $second = $this->studentIn($this->schoolA, 'Siswa Kedua', $secondParent);

        $this->reportCardFor($this->childA);
        $this->reportCardFor($second);

        app(ReportCardGenerator::class)->publishClass($this->classA, $this->waliA);

        $this->assertSame(2, $this->systemNotifications()->count());
    }

    // ---------------------------------------------------- 29. Performa

    /**
     * @param  callable(): mixed  $work
     */
    protected function selectCountOf(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $work();

        $selects = collect(DB::getQueryLog())
            ->filter(fn (array $entry) => str_starts_with(strtolower(ltrim((string) $entry['query'])), 'select'))
            ->count();

        DB::disableQueryLog();

        return $selects;
    }

    public function test_bulk_issuance_does_not_read_once_per_student(): void
    {
        // Tulisan memang tumbuh bersama jumlah tagihan — itu memang jumlah
        // kejadiannya. Yang tidak boleh tumbuh adalah pembacaannya (butir 245).
        for ($i = 0; $i < 4; $i++) {
            $this->studentIn($this->schoolA, 'Siswa Kecil '.$i, $this->userIn($this->schoolA, RoleName::OrangTua));
        }

        $small = $this->selectCountOf(fn () => $this->issueFees($this->feeTypeIn($this->schoolA, 'SPP Kecil'), '2026-08'));

        for ($i = 0; $i < 25; $i++) {
            $this->studentIn($this->schoolA, 'Siswa Besar '.$i, $this->userIn($this->schoolA, RoleName::OrangTua));
        }

        $large = $this->selectCountOf(fn () => $this->issueFees($this->feeTypeIn($this->schoolA, 'SPP Besar'), '2026-09'));

        $this->assertSame($small, $large);
    }

    public function test_the_issuance_reads_the_school_template_once_for_the_whole_batch(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->studentIn($this->schoolA, 'Siswa '.$i, $this->userIn($this->schoolA, RoleName::OrangTua));
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->issueFees($this->feeTypeIn($this->schoolA));

        $schoolReads = collect(DB::getQueryLog())
            ->filter(fn (array $entry) => str_contains(strtolower((string) $entry['query']), 'from "schools"')
                || str_contains(strtolower((string) $entry['query']), 'from `schools`'))
            ->count();

        DB::disableQueryLog();

        $this->assertLessThanOrEqual(1, $schoolReads);
    }

    // ------------------------------------------------- fence: tanpa kanal lain

    public function test_no_automatic_notification_is_ever_a_draft_or_a_broadcast(): void
    {
        $this->issueFees($this->feeTypeIn($this->schoolA));
        app(ReportCardGenerator::class)->publish($this->reportCardFor($this->childA), $this->waliA);

        foreach ($this->systemNotifications() as $notification) {
            $this->assertFalse($notification->is_draft);
            $this->assertNotNull($notification->sent_at);
            $this->assertSame(NotificationTargetType::Individual, $notification->target_type);
            $this->assertNotNull($notification->target_id);
            $this->assertNull($notification->sender_id);
        }
    }

    public function test_every_automatic_target_id_points_at_a_real_user_of_the_same_branch(): void
    {
        // Pagar terhadap satu-satunya kerusakan yang paling sulit terlihat:
        // `target_id` yang berisi id entitas lain (butir 240).
        $registration = $this->registrationIn($this->schoolA, [
            'converted_student_id' => $this->childA->getKey(),
        ]);
        app(PpdbStatusUpdater::class)->update($registration, PpdbStatus::Passed, 'Lulus.');
        $this->issueFees($this->feeTypeIn($this->schoolA));
        app(ReportCardGenerator::class)->publish($this->reportCardFor($this->childA), $this->waliA);

        $notifications = $this->systemNotifications();

        $this->assertSame(3, $notifications->count());

        foreach ($notifications as $notification) {
            $recipient = User::query()
                ->withoutGlobalScope(SchoolScope::class)
                ->find((int) $notification->target_id);

            $this->assertNotNull($recipient, 'target_id harus menunjuk users.id yang benar-benar ada.');
            $this->assertSame((int) $notification->school_id, (int) $recipient->school_id);
        }
    }

    public function test_the_three_triggers_are_the_only_ones_that_exist(): void
    {
        // Tidak ada pengingat jatuh tempo, pengingat pembayaran, maupun
        // penjadwal apa pun yang ikut menulis notifikasi.
        $feeType = $this->feeTypeIn($this->schoolA);
        $this->issueFees($feeType);

        $before = $this->systemNotifications()->count();

        // Melewati tanggal jatuh tempo tidak menghasilkan apa pun: memang tidak
        // ada yang mendengarkannya.
        $this->travel(60)->days();

        $this->assertSame($before, $this->systemNotifications()->count());

        $this->travelBack();
    }
}
