<?php

namespace Tests\Feature\Notification;

use App\Enums\NotificationTargetType;
use App\Enums\NotificationType;
use App\Enums\RoleName;
use App\Enums\StudentClassStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\Notification;
use App\Models\NotificationRead;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Services\Notification\AnnouncementPublisher;
use App\Services\Notification\NotificationRecipientResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * NOTIF-01 — fondasi notifikasi: skema, kewenangan, validasi target, dan
 * penyelesaian penerima.
 *
 * Yang paling dijaga di sini adalah arti "penerima": satu definisi, dipakai
 * bersama umpan penerima dan daftar penerima admin.
 */
class NotificationFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected AcademicYear $yearA;

    protected SchoolClass $classA;

    protected SchoolClass $classB;

    protected User $adminA;

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

        $this->classA = $this->classIn('7A');
        $this->classB = $this->classIn('7B');

        $this->adminA = $this->userIn($this->schoolA, RoleName::SchoolAdmin);
    }

    protected function userIn(School $school, RoleName $role, array $overrides = []): User
    {
        return User::factory()->forSchool($school)->withRole($role)->create($overrides);
    }

    protected function classIn(string $name, ?School $school = null, ?AcademicYear $year = null): SchoolClass
    {
        $school ??= $this->schoolA;
        $year ??= $this->yearA;

        return SchoolClass::factory()->create([
            'school_id' => $school->id,
            'academic_year_id' => $year->id,
            'name' => $name,
        ]);
    }

    /**
     * Seorang anak milik $parent yang ditempatkan di $class.
     */
    protected function childOf(User $parent, SchoolClass $class, string $name, StudentClassStatus $status = StudentClassStatus::Active): Student
    {
        $student = Student::factory()->create([
            'school_id' => $class->school_id,
            'parent_user_id' => $parent->getKey(),
            'full_name' => $name,
            'status' => StudentStatus::Active->value,
        ]);

        StudentClass::factory()->create([
            'school_id' => $class->school_id,
            'student_id' => $student->id,
            'class_id' => $class->id,
            'academic_year_id' => $class->academic_year_id,
            'status' => $status->value,
        ]);

        return $student;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function announce(array $overrides = [], ?User $actor = null, bool $send = true): Notification
    {
        return app(AnnouncementPublisher::class)->create([
            'title' => 'Pengumuman',
            'message' => 'Isi pesan',
            'type' => NotificationType::Announcement->value,
            'target_type' => NotificationTargetType::All->value,
            ...$overrides,
        ], $actor ?? $this->adminA, $send);
    }

    protected function resolver(): NotificationRecipientResolver
    {
        return app(NotificationRecipientResolver::class);
    }

    /**
     * @return array<int, int>
     */
    protected function recipientIds(Notification $notification): array
    {
        return $this->resolver()->recipientsOf($notification)
            ->pluck('id')->sort()->values()->all();
    }

    // ------------------------------------------------------------- skema

    public function test_the_notifications_table_matches_the_erd(): void
    {
        $this->assertEqualsCanonicalizing([
            'id', 'school_id', 'sender_id', 'title', 'message', 'type',
            'target_type', 'target_id', 'wa_template', 'is_draft', 'sent_at',
            'created_at', 'updated_at',
        ], Schema::getColumnListing('notifications'));
    }

    /**
     * ERD memberi pivot ini empat kolom: tanpa `school_id`, dan tanpa
     * timestamps (butir 193).
     */
    public function test_the_notification_reads_table_matches_the_erd(): void
    {
        $this->assertEqualsCanonicalizing(
            ['id', 'notification_id', 'user_id', 'read_at'],
            Schema::getColumnListing('notification_reads'),
        );

        $this->assertFalse(Schema::hasColumn('notification_reads', 'school_id'));
        $this->assertFalse(Schema::hasColumn('notification_reads', 'created_at'));
        $this->assertFalse(Schema::hasColumn('notification_reads', 'updated_at'));
        $this->assertFalse((new NotificationRead)->usesTimestamps());
    }

    public function test_the_enums_carry_exactly_the_erd_values(): void
    {
        $this->assertSame(
            ['ANNOUNCEMENT', 'BILLING', 'ACADEMIC', 'EMERGENCY', 'SYSTEM'],
            NotificationType::values(),
        );

        $this->assertSame(
            ['ALL', 'CLASS', 'INDIVIDUAL'],
            NotificationTargetType::values(),
        );

        // GENERAL tidak pernah menjadi nilai yang tersimpan (butir 191).
        $this->assertNull(NotificationType::tryFrom('GENERAL'));
    }

    /**
     * NOTIF-01 menyebut kategori GENERAL yang tidak ada di ERD; ia diterima
     * sebagai alias dan dinormalkan ke ANNOUNCEMENT (butir 191).
     */
    public function test_general_is_accepted_as_an_alias_for_announcement(): void
    {
        $this->assertSame(NotificationType::Announcement, NotificationType::fromInput('GENERAL'));
        $this->assertSame(NotificationType::Announcement, NotificationType::fromInput('general'));

        $notification = $this->announce(['type' => 'GENERAL']);

        $this->assertSame(NotificationType::Announcement, $notification->type);
    }

    public function test_target_id_has_no_foreign_key(): void
    {
        $notification = Notification::factory()->create([
            'school_id' => $this->schoolA->id,
            'target_type' => NotificationTargetType::SchoolClass->value,
            // Id yang tidak menunjuk record mana pun tetap dapat tersimpan:
            // kolomnya memang tanpa FK, dan maknanya dijaga aplikasi
            // (butir 194).
            'target_id' => 999999,
        ]);

        $this->assertSame(999999, (int) $notification->fresh()->target_id);
    }

    public function test_a_system_notification_can_have_no_sender(): void
    {
        $notification = Notification::factory()->create([
            'school_id' => $this->schoolA->id,
            'sender_id' => null,
            'type' => NotificationType::System->value,
        ]);

        $this->assertNull($notification->fresh()->sender_id);
        $this->assertNull($notification->fresh()->sender);
    }

    public function test_the_school_scope_applies_to_notifications(): void
    {
        Notification::factory()->create(['school_id' => $this->schoolA->id]);
        Notification::factory()->create(['school_id' => $this->schoolB->id]);

        $this->actingAs($this->adminA);

        $this->assertSame(1, Notification::query()->count());
        $this->assertSame(2, Notification::query()->withoutGlobalScope(SchoolScope::class)->count());
    }

    // ------------------------------------------------------- kewenangan

    /**
     * @return array<string, array{RoleName}>
     */
    public static function allowedCreators(): array
    {
        return [
            'school admin' => [RoleName::SchoolAdmin],
            'kepala sekolah' => [RoleName::KepalaSekolah],
        ];
    }

    #[DataProvider('allowedCreators')]
    public function test_the_roles_named_by_notif_01_may_create(RoleName $role): void
    {
        $notification = $this->announce(actor: $this->userIn($this->schoolA, $role));

        $this->assertTrue($notification->exists);
    }

    public function test_a_super_admin_may_create_with_an_explicit_school(): void
    {
        $superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create(['school_id' => null]);

        $notification = $this->announce(['school_id' => $this->schoolB->id], $superAdmin);

        $this->assertSame($this->schoolB->id, $notification->school_id);
    }

    public function test_a_super_admin_must_choose_a_school(): void
    {
        $superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create(['school_id' => null]);

        $this->expectException(ValidationException::class);

        $this->announce([], $superAdmin);
    }

    public function test_a_super_admin_cannot_name_a_school_that_does_not_exist(): void
    {
        $superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create(['school_id' => null]);

        $this->expectException(ValidationException::class);

        $this->announce(['school_id' => 999999], $superAdmin);
    }

    /**
     * PORTAL-02 sempat meminta pintasan Buat Pengumuman untuk guru; konfliknya
     * tidak diselesaikan dengan memberi izin (butir 201).
     *
     * @return array<string, array{RoleName}>
     */
    public static function deniedCreators(): array
    {
        return [
            'guru' => [RoleName::Guru],
            'wali kelas' => [RoleName::WaliKelas],
            'bendahara' => [RoleName::Bendahara],
            'orang tua' => [RoleName::OrangTua],
            'siswa' => [RoleName::Siswa],
        ];
    }

    #[DataProvider('deniedCreators')]
    public function test_no_other_role_may_create(RoleName $role): void
    {
        $this->expectException(AuthorizationException::class);

        $this->announce(actor: $this->userIn($this->schoolA, $role));
    }

    public function test_a_school_level_creator_cannot_smuggle_another_school(): void
    {
        $notification = $this->announce(['school_id' => $this->schoolB->id]);

        // Payload-nya diabaikan sepenuhnya (butir 202).
        $this->assertSame($this->schoolA->id, $notification->school_id);
    }

    public function test_the_sender_always_comes_from_the_session(): void
    {
        $other = $this->userIn($this->schoolA, RoleName::KepalaSekolah);

        $notification = $this->announce(['sender_id' => $other->getKey()]);

        $this->assertSame($this->adminA->getKey(), $notification->sender_id);
    }

    public function test_the_sent_timestamp_never_comes_from_the_caller(): void
    {
        $notification = $this->announce(['sent_at' => '2000-01-01 00:00:00']);

        $this->assertTrue($notification->sent_at->isToday());
    }

    // -------------------------------------------------- validasi target

    public function test_target_all_must_not_carry_a_target_id(): void
    {
        $this->expectException(ValidationException::class);

        $this->announce([
            'target_type' => NotificationTargetType::All->value,
            'target_id' => $this->classA->id,
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function targetsNeedingId(): array
    {
        return [
            'kelas' => ['CLASS'],
            'individu' => ['INDIVIDUAL'],
        ];
    }

    #[DataProvider('targetsNeedingId')]
    public function test_class_and_individual_require_a_target_id(string $targetType): void
    {
        $this->expectException(ValidationException::class);

        $this->announce(['target_type' => $targetType, 'target_id' => null]);
    }

    public function test_a_class_from_another_branch_is_rejected(): void
    {
        $foreignYear = AcademicYear::factory()->create([
            'school_id' => $this->schoolB->id, 'is_active' => true,
        ]);
        $foreignClass = $this->classIn('7A', $this->schoolB, $foreignYear);

        $this->expectException(ValidationException::class);

        $this->announce([
            'target_type' => NotificationTargetType::SchoolClass->value,
            'target_id' => $foreignClass->id,
        ]);
    }

    public function test_a_user_from_another_branch_is_rejected(): void
    {
        $foreign = $this->userIn($this->schoolB, RoleName::OrangTua);

        $this->expectException(ValidationException::class);

        $this->announce([
            'target_type' => NotificationTargetType::Individual->value,
            'target_id' => $foreign->getKey(),
        ]);
    }

    public function test_an_inactive_individual_target_is_rejected(): void
    {
        $inactive = $this->userIn($this->schoolA, RoleName::OrangTua, ['is_active' => false]);

        $this->expectException(ValidationException::class);

        $this->announce([
            'target_type' => NotificationTargetType::Individual->value,
            'target_id' => $inactive->getKey(),
        ]);
    }

    public function test_an_unknown_target_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->announce([
            'target_type' => NotificationTargetType::Individual->value,
            'target_id' => 999999,
        ]);
    }

    // ------------------------------------------------ penerima: ALL

    public function test_all_reaches_every_active_user_of_the_branch(): void
    {
        $parent = $this->userIn($this->schoolA, RoleName::OrangTua);
        $teacher = $this->userIn($this->schoolA, RoleName::Guru);
        $inactive = $this->userIn($this->schoolA, RoleName::Guru, ['is_active' => false]);
        $foreign = $this->userIn($this->schoolB, RoleName::OrangTua);
        $superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create(['school_id' => null]);

        $recipients = $this->recipientIds($this->announce());

        $this->assertContains($this->adminA->getKey(), $recipients);
        $this->assertContains($parent->getKey(), $recipients);
        $this->assertContains($teacher->getKey(), $recipients);

        $this->assertNotContains($inactive->getKey(), $recipients);
        $this->assertNotContains($foreign->getKey(), $recipients);
        // Super Admin bukan pengguna cabang mana pun (butir 198).
        $this->assertNotContains($superAdmin->getKey(), $recipients);
    }

    // ---------------------------------------------- penerima: CLASS

    public function test_class_reaches_only_parents_of_that_class(): void
    {
        $parentInClass = $this->userIn($this->schoolA, RoleName::OrangTua);
        $this->childOf($parentInClass, $this->classA, 'Anak A');

        $parentOtherClass = $this->userIn($this->schoolA, RoleName::OrangTua);
        $this->childOf($parentOtherClass, $this->classB, 'Anak B');

        $studentUser = $this->userIn($this->schoolA, RoleName::Siswa);
        $teacher = $this->userIn($this->schoolA, RoleName::Guru);

        $recipients = $this->recipientIds($this->announce([
            'target_type' => NotificationTargetType::SchoolClass->value,
            'target_id' => $this->classA->id,
        ]));

        // NOTIF-01 poin 3: hanya orang tua siswa kelas tersebut (butir 199).
        $this->assertSame([$parentInClass->getKey()], $recipients);
        $this->assertNotContains($parentOtherClass->getKey(), $recipients);
        $this->assertNotContains($studentUser->getKey(), $recipients);
        $this->assertNotContains($teacher->getKey(), $recipients);
        $this->assertNotContains($this->adminA->getKey(), $recipients);
    }

    public function test_a_parent_with_two_children_in_one_class_appears_once(): void
    {
        $parent = $this->userIn($this->schoolA, RoleName::OrangTua);
        $this->childOf($parent, $this->classA, 'Anak Pertama');
        $this->childOf($parent, $this->classA, 'Anak Kedua');

        $recipients = $this->recipientIds($this->announce([
            'target_type' => NotificationTargetType::SchoolClass->value,
            'target_id' => $this->classA->id,
        ]));

        $this->assertSame([$parent->getKey()], $recipients);
    }

    /**
     * Penempatan yang sudah tidak berlaku (MOVED) bukan keanggotaan kelas
     * sekarang.
     */
    public function test_a_moved_placement_no_longer_counts_as_class_membership(): void
    {
        $parent = $this->userIn($this->schoolA, RoleName::OrangTua);
        $this->childOf($parent, $this->classA, 'Anak Pindah', StudentClassStatus::Moved);

        $recipients = $this->recipientIds($this->announce([
            'target_type' => NotificationTargetType::SchoolClass->value,
            'target_id' => $this->classA->id,
        ]));

        $this->assertSame([], $recipients);
    }

    public function test_an_inactive_parent_is_not_a_class_recipient(): void
    {
        $parent = $this->userIn($this->schoolA, RoleName::OrangTua, ['is_active' => false]);
        $this->childOf($parent, $this->classA, 'Anak A');

        $recipients = $this->recipientIds($this->announce([
            'target_type' => NotificationTargetType::SchoolClass->value,
            'target_id' => $this->classA->id,
        ]));

        $this->assertSame([], $recipients);
    }

    // ------------------------------------------ penerima: INDIVIDUAL

    public function test_individual_reaches_exactly_the_chosen_user(): void
    {
        $target = $this->userIn($this->schoolA, RoleName::OrangTua);
        $this->userIn($this->schoolA, RoleName::OrangTua);

        $recipients = $this->recipientIds($this->announce([
            'target_type' => NotificationTargetType::Individual->value,
            'target_id' => $target->getKey(),
        ]));

        $this->assertSame([$target->getKey()], $recipients);
    }

    // ------------------------------------------------- draf vs terkirim

    public function test_a_draft_records_no_sent_time(): void
    {
        $draft = $this->announce(send: false);

        $this->assertTrue($draft->is_draft);
        $this->assertNull($draft->sent_at);
        $this->assertFalse($draft->isSent());
    }

    public function test_sending_a_draft_stamps_the_time_server_side(): void
    {
        $draft = $this->announce(send: false);

        $sent = app(AnnouncementPublisher::class)->send($draft, $this->adminA);

        $this->assertFalse($sent->is_draft);
        $this->assertNotNull($sent->sent_at);
        $this->assertTrue($sent->isSent());
    }

    public function test_a_sent_announcement_cannot_be_sent_twice(): void
    {
        $sent = $this->announce();

        $this->expectException(ValidationException::class);

        app(AnnouncementPublisher::class)->send($sent, $this->adminA);
    }

    /**
     * Blueprint tidak menyebutkan pengeditan; yang dipilih adalah yang menjaga
     * jejak (butir 195).
     */
    public function test_a_sent_announcement_cannot_be_edited(): void
    {
        $sent = $this->announce();

        $this->expectException(ValidationException::class);

        app(AnnouncementPublisher::class)->update($sent, [
            'title' => 'Diubah',
            'message' => 'Diubah',
            'type' => NotificationType::Announcement->value,
            'target_type' => NotificationTargetType::All->value,
        ], $this->adminA, false);
    }

    public function test_a_draft_can_be_edited_and_then_sent(): void
    {
        $draft = $this->announce(send: false);

        $updated = app(AnnouncementPublisher::class)->update($draft, [
            'title' => 'Judul Baru',
            'message' => 'Isi baru',
            'type' => NotificationType::Academic->value,
            'target_type' => NotificationTargetType::All->value,
        ], $this->adminA, true);

        $this->assertSame('Judul Baru', $updated->title);
        $this->assertSame(NotificationType::Academic, $updated->type);
        $this->assertTrue($updated->isSent());
    }

    public function test_an_announcement_from_another_branch_cannot_be_touched(): void
    {
        $foreignAdmin = $this->userIn($this->schoolB, RoleName::SchoolAdmin);
        $draft = $this->announce(send: false);

        $this->expectException(AuthorizationException::class);

        app(AnnouncementPublisher::class)->send($draft, $foreignAdmin);
    }

    public function test_the_policy_refuses_editing_or_deleting_a_sent_announcement(): void
    {
        $sent = $this->announce();
        $draft = $this->announce(send: false);

        $this->assertFalse($this->adminA->can('update', $sent));
        $this->assertTrue($this->adminA->can('update', $draft));
        // API 4.10 tidak menyediakan DELETE, dan riwayatnya justru diminta
        // disimpan (NOTIF-04 poin 3).
        $this->assertFalse($this->adminA->can('delete', $sent));
        $this->assertFalse($this->adminA->can('delete', $draft));
    }

    // ------------------------------------------- konsistensi dua bentuk

    /**
     * `recipientsOf()` dan `visibleTo()` adalah dua bentuk dari aturan yang
     * sama; keduanya harus memutuskan hal yang sama (butir 196).
     */
    public function test_the_resolver_and_the_feed_predicate_agree(): void
    {
        $parentInClass = $this->userIn($this->schoolA, RoleName::OrangTua);
        $this->childOf($parentInClass, $this->classA, 'Anak A');

        $parentOtherClass = $this->userIn($this->schoolA, RoleName::OrangTua);
        $this->childOf($parentOtherClass, $this->classB, 'Anak B');

        $teacher = $this->userIn($this->schoolA, RoleName::Guru);
        $foreign = $this->userIn($this->schoolB, RoleName::OrangTua);

        $notifications = [
            $this->announce(),
            $this->announce([
                'target_type' => NotificationTargetType::SchoolClass->value,
                'target_id' => $this->classA->id,
            ]),
            $this->announce([
                'target_type' => NotificationTargetType::Individual->value,
                'target_id' => $teacher->getKey(),
            ]),
        ];

        $candidates = [$this->adminA, $parentInClass, $parentOtherClass, $teacher, $foreign];

        foreach ($notifications as $notification) {
            $fromResolver = $this->recipientIds($notification);

            foreach ($candidates as $candidate) {
                $seesIt = $this->resolver()
                    ->visibleTo(
                        Notification::query()->withoutGlobalScope(SchoolScope::class)->sent(),
                        $candidate,
                    )
                    ->whereKey($notification->getKey())
                    ->exists();

                $this->assertSame(
                    in_array($candidate->getKey(), $fromResolver, true),
                    $seesIt,
                    "Ketidakcocokan untuk notifikasi {$notification->id} dan pengguna {$candidate->id}",
                );
            }
        }
    }
}
