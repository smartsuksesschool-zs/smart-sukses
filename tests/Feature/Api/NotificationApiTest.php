<?php

namespace Tests\Feature\Api;

use App\Enums\NotificationTargetType;
use App\Enums\NotificationType;
use App\Enums\RoleName;
use App\Enums\StudentClassStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\Notification;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Services\Notification\AnnouncementPublisher;
use App\Services\Notification\NotificationCenter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * API 4.10 — endpoint notifikasi gelombang pertama.
 *
 * Dua pertanyaan yang dijaga di sini: apa yang boleh dilihat seorang penerima,
 * dan apa yang boleh dibuat seorang pengelola. Keduanya tidak boleh dijawab
 * oleh controller sendiri — jawabannya datang dari service domain yang sama
 * dengan panel.
 */
class NotificationApiTest extends TestCase
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

        $this->classA = SchoolClass::factory()->create([
            'school_id' => $this->schoolA->id,
            'academic_year_id' => $this->yearA->id,
            'name' => '7A',
        ]);

        $this->classB = SchoolClass::factory()->create([
            'school_id' => $this->schoolA->id,
            'academic_year_id' => $this->yearA->id,
            'name' => '7B',
        ]);

        $this->adminA = $this->userIn($this->schoolA, RoleName::SchoolAdmin);
    }

    /**
     * Guard yang sudah teresolusi dilupakan lebih dulu: dalam satu test
     * container-nya dipakai bersama dan AuthManager menyimpan pengguna request
     * sebelumnya.
     */
    protected function asUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }

    protected function userIn(School $school, RoleName $role, array $overrides = []): User
    {
        return User::factory()->forSchool($school)->withRole($role)->create($overrides);
    }

    protected function parentOf(SchoolClass $class, string $childName = 'Anak'): User
    {
        $parent = $this->userIn($this->schoolA, RoleName::OrangTua);

        $student = Student::factory()->create([
            'school_id' => $class->school_id,
            'parent_user_id' => $parent->getKey(),
            'full_name' => $childName,
            'status' => StudentStatus::Active->value,
        ]);

        StudentClass::factory()->create([
            'school_id' => $class->school_id,
            'student_id' => $student->id,
            'class_id' => $class->id,
            'academic_year_id' => $class->academic_year_id,
            'status' => StudentClassStatus::Active->value,
        ]);

        return $parent;
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

    // ----------------------------------------------------- authentication

    /**
     * @return array<string, array{string, string}>
     */
    public static function notificationEndpoints(): array
    {
        return [
            'daftar' => ['getJson', '/api/v1/notifications'],
            'lencana' => ['getJson', '/api/v1/notifications/unread-count'],
            'detail' => ['getJson', '/api/v1/notifications/1'],
            'buat' => ['postJson', '/api/v1/notifications'],
            'tandai' => ['patchJson', '/api/v1/notifications/1/read'],
            'tandai semua' => ['postJson', '/api/v1/notifications/mark-all-read'],
            'riwayat admin' => ['getJson', '/api/v1/admin/notifications'],
        ];
    }

    #[DataProvider('notificationEndpoints')]
    public function test_every_notification_endpoint_requires_a_token(string $method, string $uri): void
    {
        $this->{$method}($uri)->assertStatus(401);
    }

    // ------------------------------------------------------- umpan penerima

    public function test_the_feed_returns_what_is_addressed_to_the_user(): void
    {
        $parent = $this->parentOf($this->classA);
        $otherParent = $this->parentOf($this->classB, 'Anak Lain');

        $all = $this->announce(['title' => 'Untuk Semua']);
        $forClass = $this->announce([
            'title' => 'Untuk 7A',
            'target_type' => NotificationTargetType::SchoolClass->value,
            'target_id' => $this->classA->id,
        ]);
        $forHim = $this->announce([
            'title' => 'Untuk Dia',
            'target_type' => NotificationTargetType::Individual->value,
            'target_id' => $parent->getKey(),
        ]);
        $forOther = $this->announce([
            'title' => 'Untuk Orang Lain',
            'target_type' => NotificationTargetType::Individual->value,
            'target_id' => $otherParent->getKey(),
        ]);

        $titles = $this->asUser($parent)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data.*.title');

        $this->assertEqualsCanonicalizing(
            [$all->title, $forClass->title, $forHim->title],
            $titles,
        );
        $this->assertNotContains($forOther->title, $titles);
    }

    public function test_the_feed_never_shows_drafts(): void
    {
        $parent = $this->parentOf($this->classA);

        $this->announce(['title' => 'Draf Belum Terkirim'], send: false);
        $this->announce(['title' => 'Sudah Terkirim']);

        $this->asUser($parent)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Sudah Terkirim');
    }

    public function test_the_feed_stops_at_the_branch_boundary(): void
    {
        $foreignAdmin = $this->userIn($this->schoolB, RoleName::SchoolAdmin);
        $this->announce(['title' => 'Cabang A']);
        $this->announce(['title' => 'Cabang B'], $foreignAdmin);

        $this->asUser($this->adminA)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Cabang A');
    }

    public function test_the_feed_is_newest_first(): void
    {
        $parent = $this->parentOf($this->classA);

        $first = $this->announce(['title' => 'Pertama']);
        $second = $this->announce(['title' => 'Kedua']);
        $third = $this->announce(['title' => 'Ketiga']);

        // Ketiganya terbit pada detik yang sama; `id` yang memutuskan.
        $ids = $this->asUser($parent)->getJson('/api/v1/notifications')
            ->assertOk()->json('data.*.id');

        $this->assertSame([$third->id, $second->id, $first->id], $ids);
    }

    public function test_the_feed_is_capped_at_fifty(): void
    {
        $parent = $this->parentOf($this->classA);

        Notification::factory()->count(55)->sent()->create([
            'school_id' => $this->schoolA->id,
            'sender_id' => $this->adminA->getKey(),
            'target_type' => NotificationTargetType::All->value,
            'target_id' => null,
        ]);

        $this->asUser($parent)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(NotificationCenter::FEED_LIMIT, 'data');
    }

    public function test_the_feed_can_be_filtered_by_type(): void
    {
        $parent = $this->parentOf($this->classA);

        $this->announce(['title' => 'Akademik', 'type' => NotificationType::Academic->value]);
        $this->announce(['title' => 'Tagihan', 'type' => NotificationType::Billing->value]);

        $this->asUser($parent)
            ->getJson('/api/v1/notifications?type='.NotificationType::Academic->value)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Akademik');
    }

    public function test_an_unknown_type_filter_is_rejected(): void
    {
        $this->asUser($this->adminA)
            ->getJson('/api/v1/notifications?type=GENERAL')
            ->assertStatus(422);
    }

    public function test_the_feed_can_be_filtered_by_read_state(): void
    {
        $parent = $this->parentOf($this->classA);
        $read = $this->announce(['title' => 'Sudah Dibaca']);
        $unread = $this->announce(['title' => 'Belum Dibaca']);

        $this->asUser($parent)->patchJson("/api/v1/notifications/{$read->id}/read")->assertOk();

        $this->asUser($parent)->getJson('/api/v1/notifications?is_read=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $read->id);

        $this->asUser($parent)->getJson('/api/v1/notifications?is_read=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $unread->id);
    }

    /**
     * Bentuk daftar-izin: id kelas atau pengguna lain, cabang, dan template WA
     * tidak pernah ikut (butir 203).
     */
    public function test_the_recipient_shape_hides_administrative_fields(): void
    {
        $parent = $this->parentOf($this->classA);
        $this->announce([
            'target_type' => NotificationTargetType::SchoolClass->value,
            'target_id' => $this->classA->id,
        ]);

        $row = $this->asUser($parent)->getJson('/api/v1/notifications')
            ->assertOk()->json('data.0');

        $this->assertEqualsCanonicalizing([
            'id', 'title', 'message', 'type', 'type_label',
            'sender_name', 'sent_at', 'is_read', 'read_at',
        ], array_keys($row));

        $this->assertSame($this->adminA->name, $row['sender_name']);
    }

    public function test_a_system_notification_shows_no_sender_name(): void
    {
        $parent = $this->parentOf($this->classA);

        Notification::factory()->sent()->create([
            'school_id' => $this->schoolA->id,
            'sender_id' => null,
            'type' => NotificationType::System->value,
            'target_type' => NotificationTargetType::All->value,
            'target_id' => null,
        ]);

        $this->asUser($parent)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.sender_name', null);
    }

    // ------------------------------------------------------------ detail

    public function test_a_recipient_can_read_one_notification(): void
    {
        $parent = $this->parentOf($this->classA);
        $notification = $this->announce(['title' => 'Rapat Wali Murid']);

        $this->asUser($parent)->getJson("/api/v1/notifications/{$notification->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $notification->id)
            ->assertJsonPath('data.title', 'Rapat Wali Murid')
            ->assertJsonPath('data.is_read', false);
    }

    /**
     * Bukan 403: menjawab "terlarang" akan mengonfirmasi bahwa notifikasi itu
     * ada (butir 204).
     */
    public function test_a_non_recipient_gets_a_not_found(): void
    {
        $parent = $this->parentOf($this->classA);
        $forOtherClass = $this->announce([
            'target_type' => NotificationTargetType::SchoolClass->value,
            'target_id' => $this->classB->id,
        ]);

        $this->asUser($parent)->getJson("/api/v1/notifications/{$forOtherClass->id}")
            ->assertStatus(404);
    }

    public function test_a_draft_is_not_readable_even_by_its_author(): void
    {
        $draft = $this->announce(send: false);

        $this->asUser($this->adminA)->getJson("/api/v1/notifications/{$draft->id}")
            ->assertStatus(404);
    }

    public function test_a_notification_from_another_branch_is_not_found(): void
    {
        $foreignAdmin = $this->userIn($this->schoolB, RoleName::SchoolAdmin);
        $foreign = $this->announce([], $foreignAdmin);

        $this->asUser($this->adminA)->getJson("/api/v1/notifications/{$foreign->id}")
            ->assertStatus(404);
    }

    /**
     * Membaca daftar bukan tindakan membaca notifikasi; hanya PATCH yang
     * menandai.
     */
    public function test_reading_the_feed_does_not_mark_anything_read(): void
    {
        $parent = $this->parentOf($this->classA);
        $notification = $this->announce();

        $this->asUser($parent)->getJson('/api/v1/notifications')->assertOk();
        $this->asUser($parent)->getJson("/api/v1/notifications/{$notification->id}")->assertOk();

        $this->assertDatabaseCount('notification_reads', 0);
        $this->asUser($parent)->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);
    }

    // ---------------------------------------------------- keadaan terbaca

    public function test_the_badge_counts_only_what_is_addressed_and_unread(): void
    {
        $parent = $this->parentOf($this->classA);

        $this->announce();
        $this->announce([
            'target_type' => NotificationTargetType::SchoolClass->value,
            'target_id' => $this->classA->id,
        ]);
        // Bukan untuknya, dan sebuah draf: keduanya tidak ikut dihitung.
        $this->announce([
            'target_type' => NotificationTargetType::SchoolClass->value,
            'target_id' => $this->classB->id,
        ]);
        $this->announce(send: false);

        $this->asUser($parent)->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2);
    }

    public function test_marking_read_lowers_the_badge(): void
    {
        $parent = $this->parentOf($this->classA);
        $notification = $this->announce();

        $this->asUser($parent)->patchJson("/api/v1/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.is_read', true);

        $this->assertDatabaseHas('notification_reads', [
            'notification_id' => $notification->id,
            'user_id' => $parent->getKey(),
        ]);

        $this->asUser($parent)->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
    }

    /**
     * `read_at` berarti "waktu **pertama kali** dibaca" (butir 192).
     */
    public function test_marking_read_twice_keeps_the_first_time(): void
    {
        $parent = $this->parentOf($this->classA);
        $notification = $this->announce();

        $first = $this->asUser($parent)
            ->patchJson("/api/v1/notifications/{$notification->id}/read")
            ->assertOk()->json('data.read_at');

        $this->travel(5)->minutes();

        $second = $this->asUser($parent)
            ->patchJson("/api/v1/notifications/{$notification->id}/read")
            ->assertOk()->json('data.read_at');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('notification_reads', 1);

        $this->travelBack();
    }

    public function test_a_non_recipient_cannot_mark_a_notification_read(): void
    {
        $parent = $this->parentOf($this->classA);
        $forOtherClass = $this->announce([
            'target_type' => NotificationTargetType::SchoolClass->value,
            'target_id' => $this->classB->id,
        ]);

        $this->asUser($parent)->patchJson("/api/v1/notifications/{$forOtherClass->id}/read")
            ->assertStatus(404);

        $this->assertDatabaseCount('notification_reads', 0);
    }

    public function test_a_draft_cannot_be_marked_read(): void
    {
        $draft = $this->announce(send: false);

        $this->asUser($this->adminA)->patchJson("/api/v1/notifications/{$draft->id}/read")
            ->assertStatus(404);

        $this->assertDatabaseCount('notification_reads', 0);
    }

    public function test_read_state_belongs_to_one_user_only(): void
    {
        $first = $this->parentOf($this->classA, 'Anak Satu');
        $second = $this->parentOf($this->classA, 'Anak Dua');
        $notification = $this->announce();

        $this->asUser($first)->patchJson("/api/v1/notifications/{$notification->id}/read")->assertOk();

        $this->asUser($second)->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);

        $this->asUser($second)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.is_read', false);
    }

    public function test_mark_all_read_covers_exactly_the_users_own_feed(): void
    {
        $parent = $this->parentOf($this->classA);
        $otherParent = $this->parentOf($this->classB, 'Anak Lain');

        $this->announce();
        $this->announce([
            'target_type' => NotificationTargetType::SchoolClass->value,
            'target_id' => $this->classA->id,
        ]);
        $notForHim = $this->announce([
            'target_type' => NotificationTargetType::SchoolClass->value,
            'target_id' => $this->classB->id,
        ]);

        $this->asUser($parent)->postJson('/api/v1/notifications/mark-all-read')
            ->assertOk()
            ->assertJsonPath('data.marked', 2)
            ->assertJsonPath('data.unread_count', 0);

        $this->assertDatabaseMissing('notification_reads', [
            'notification_id' => $notForHim->id,
            'user_id' => $parent->getKey(),
        ]);

        // Orang tua kelas B tetap punya miliknya sendiri yang belum dibaca.
        $this->asUser($otherParent)->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2);
    }

    public function test_mark_all_read_is_idempotent(): void
    {
        $parent = $this->parentOf($this->classA);
        $this->announce();
        $this->announce();

        $this->asUser($parent)->postJson('/api/v1/notifications/mark-all-read')
            ->assertOk()->assertJsonPath('data.marked', 2);

        $this->asUser($parent)->postJson('/api/v1/notifications/mark-all-read')
            ->assertOk()->assertJsonPath('data.marked', 0);

        $this->assertDatabaseCount('notification_reads', 2);
    }

    /**
     * Indeks unik adalah pagar terakhirnya, bukan pemeriksaan di aplikasi
     * (butir 192).
     */
    public function test_the_database_refuses_a_duplicate_read_row(): void
    {
        $parent = $this->parentOf($this->classA);
        $notification = $this->announce();

        $this->asUser($parent)->patchJson("/api/v1/notifications/{$notification->id}/read")->assertOk();

        $this->expectException(QueryException::class);

        DB::table('notification_reads')->insert([
            'notification_id' => $notification->id,
            'user_id' => $parent->getKey(),
            'read_at' => now(),
        ]);
    }

    // ------------------------------------------------------- pembuatan

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
    public function test_the_roles_named_by_notif_01_may_post(RoleName $role): void
    {
        $actor = $this->userIn($this->schoolA, $role);

        $this->asUser($actor)->postJson('/api/v1/notifications', [
            'title' => 'Libur Semester',
            'message' => 'Sekolah libur mulai pekan depan.',
            'type' => NotificationType::Announcement->value,
            'target_type' => NotificationTargetType::All->value,
            'action' => 'send',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'SENT')
            ->assertJsonPath('data.sender_name', $actor->name);

        $this->assertDatabaseHas('notifications', [
            'title' => 'Libur Semester',
            'school_id' => $this->schoolA->id,
            'sender_id' => $actor->getKey(),
            'is_draft' => false,
        ]);
    }

    /**
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
    public function test_no_other_role_may_post(RoleName $role): void
    {
        $this->asUser($this->userIn($this->schoolA, $role))
            ->postJson('/api/v1/notifications', [
                'title' => 'Pengumuman',
                'message' => 'Isi',
                'type' => NotificationType::Announcement->value,
                'target_type' => NotificationTargetType::All->value,
                'action' => 'send',
            ])
            ->assertStatus(403);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_a_draft_can_be_saved_without_sending(): void
    {
        $this->asUser($this->adminA)->postJson('/api/v1/notifications', [
            'title' => 'Rancangan',
            'message' => 'Belum final.',
            'type' => NotificationType::Announcement->value,
            'target_type' => NotificationTargetType::All->value,
            'action' => 'draft',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.sent_at', null);

        $this->assertDatabaseHas('notifications', [
            'title' => 'Rancangan',
            'is_draft' => true,
            'sent_at' => null,
        ]);
    }

    public function test_the_action_must_be_named(): void
    {
        $this->asUser($this->adminA)->postJson('/api/v1/notifications', [
            'title' => 'Pengumuman',
            'message' => 'Isi',
            'type' => NotificationType::Announcement->value,
            'target_type' => NotificationTargetType::All->value,
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['action']]);
    }

    /**
     * Keduanya tidak pernah diterima dari klien; yang menentukan status adalah
     * `action` (butir 195, 200).
     */
    public function test_is_draft_and_sent_at_from_the_client_are_ignored(): void
    {
        $this->asUser($this->adminA)->postJson('/api/v1/notifications', [
            'title' => 'Pengumuman',
            'message' => 'Isi',
            'type' => NotificationType::Announcement->value,
            'target_type' => NotificationTargetType::All->value,
            'action' => 'draft',
            'is_draft' => false,
            'sent_at' => '2020-01-01 00:00:00',
            'sender_id' => $this->userIn($this->schoolA, RoleName::KepalaSekolah)->getKey(),
        ])->assertStatus(201);

        $notification = Notification::query()->withoutGlobalScopes()->sole();

        $this->assertTrue($notification->is_draft);
        $this->assertNull($notification->sent_at);
        $this->assertSame($this->adminA->getKey(), $notification->sender_id);
    }

    public function test_general_is_accepted_and_stored_as_announcement(): void
    {
        $this->asUser($this->adminA)->postJson('/api/v1/notifications', [
            'title' => 'Pengumuman Umum',
            'message' => 'Isi',
            'type' => 'GENERAL',
            'target_type' => NotificationTargetType::All->value,
            'action' => 'send',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.type', NotificationType::Announcement->value);

        $this->assertDatabaseHas('notifications', [
            'title' => 'Pengumuman Umum',
            'type' => NotificationType::Announcement->value,
        ]);
    }

    public function test_a_class_target_from_another_branch_is_refused(): void
    {
        $foreignYear = AcademicYear::factory()->create([
            'school_id' => $this->schoolB->id, 'is_active' => true,
        ]);
        $foreignClass = SchoolClass::factory()->create([
            'school_id' => $this->schoolB->id,
            'academic_year_id' => $foreignYear->id,
        ]);

        $this->asUser($this->adminA)->postJson('/api/v1/notifications', [
            'title' => 'Pengumuman',
            'message' => 'Isi',
            'type' => NotificationType::Announcement->value,
            'target_type' => NotificationTargetType::SchoolClass->value,
            'target_id' => $foreignClass->id,
            'action' => 'send',
        ])->assertStatus(422);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_a_class_target_without_an_id_is_refused(): void
    {
        $this->asUser($this->adminA)->postJson('/api/v1/notifications', [
            'title' => 'Pengumuman',
            'message' => 'Isi',
            'type' => NotificationType::Announcement->value,
            'target_type' => NotificationTargetType::SchoolClass->value,
            'action' => 'send',
        ])->assertStatus(422);
    }

    public function test_a_sent_announcement_reaches_its_recipients_feed(): void
    {
        $parent = $this->parentOf($this->classA);

        $this->asUser($this->adminA)->postJson('/api/v1/notifications', [
            'title' => 'Rapat Kelas 7A',
            'message' => 'Mohon hadir.',
            'type' => NotificationType::Academic->value,
            'target_type' => NotificationTargetType::SchoolClass->value,
            'target_id' => $this->classA->id,
            'action' => 'send',
        ])->assertStatus(201);

        $this->asUser($parent)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Rapat Kelas 7A');
    }

    // --------------------------------------------------- riwayat admin

    public function test_the_admin_history_shows_sent_and_draft_of_one_branch(): void
    {
        $foreignAdmin = $this->userIn($this->schoolB, RoleName::SchoolAdmin);

        $sent = $this->announce(['title' => 'Terkirim']);
        $draft = $this->announce(['title' => 'Draf'], send: false);
        $this->announce(['title' => 'Cabang Lain'], $foreignAdmin);

        $body = $this->asUser($this->adminA)->getJson('/api/v1/admin/notifications')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->json();

        $this->assertEqualsCanonicalizing(
            [$sent->id, $draft->id],
            array_column($body['data'], 'id'),
        );
        $this->assertEqualsCanonicalizing(
            ['SENT', 'DRAFT'],
            array_column($body['data'], 'status'),
        );
    }

    public function test_the_admin_history_can_be_filtered_by_status_and_type(): void
    {
        $this->announce(['title' => 'Terkirim', 'type' => NotificationType::Billing->value]);
        $this->announce(['title' => 'Draf'], send: false);

        $this->asUser($this->adminA)->getJson('/api/v1/admin/notifications?status=draft')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Draf');

        $this->asUser($this->adminA)
            ->getJson('/api/v1/admin/notifications?type='.NotificationType::Billing->value)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Terkirim');
    }

    public function test_the_admin_history_labels_its_target_in_words(): void
    {
        $target = $this->userIn($this->schoolA, RoleName::Guru);

        $this->announce([
            'target_type' => NotificationTargetType::SchoolClass->value,
            'target_id' => $this->classA->id,
        ]);
        $this->announce([
            'target_type' => NotificationTargetType::Individual->value,
            'target_id' => $target->getKey(),
        ]);

        $labels = $this->asUser($this->adminA)->getJson('/api/v1/admin/notifications')
            ->assertOk()->json('data.*.target_label');

        $this->assertEqualsCanonicalizing(['Kelas 7A', $target->name], $labels);
    }

    #[DataProvider('deniedCreators')]
    public function test_the_admin_history_is_closed_to_other_roles(RoleName $role): void
    {
        $this->announce();

        $this->asUser($this->userIn($this->schoolA, $role))
            ->getJson('/api/v1/admin/notifications')
            ->assertStatus(403);
    }

    public function test_a_super_admin_must_name_a_branch_for_the_history(): void
    {
        $superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create(['school_id' => null]);
        $this->announce(['title' => 'Cabang A']);

        $this->asUser($superAdmin)->getJson('/api/v1/admin/notifications')
            ->assertStatus(422);

        $this->asUser($superAdmin)
            ->getJson('/api/v1/admin/notifications?school_id='.$this->schoolA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Cabang A');
    }

    public function test_the_admin_history_is_paginated(): void
    {
        Notification::factory()->count(30)->sent()->create([
            'school_id' => $this->schoolA->id,
            'sender_id' => $this->adminA->getKey(),
            'target_type' => NotificationTargetType::All->value,
            'target_id' => null,
        ]);

        $this->asUser($this->adminA)->getJson('/api/v1/admin/notifications?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 30)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.last_page', 3);
    }

    // ------------------------------------------------------------ beban

    /**
     * Umpan penerima memuat sampai 50 baris; keadaan terbaca ditempelkan sebagai
     * subquery, bukan satu query per baris (butir 197).
     */
    public function test_the_feed_does_not_grow_a_query_per_notification(): void
    {
        $parent = $this->parentOf($this->classA);

        Notification::factory()->count(40)->sent()->create([
            'school_id' => $this->schoolA->id,
            'sender_id' => $this->adminA->getKey(),
            'target_type' => NotificationTargetType::All->value,
            'target_id' => null,
        ]);

        $this->asUser($parent);

        DB::enableQueryLog();
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(40, 'data');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Autentikasi token, pemuatan peran, umpan, dan pengirimnya — jumlahnya
        // tetap dan tidak bergantung pada banyaknya notifikasi.
        $this->assertLessThan(15, $queries, "Umpan menjalankan {$queries} query.");
    }

    public function test_the_badge_is_a_single_aggregate(): void
    {
        $parent = $this->parentOf($this->classA);

        Notification::factory()->count(30)->sent()->create([
            'school_id' => $this->schoolA->id,
            'sender_id' => $this->adminA->getKey(),
            'target_type' => NotificationTargetType::All->value,
            'target_id' => null,
        ]);

        $this->asUser($parent);

        DB::enableQueryLog();
        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 30);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(10, $queries, "Lencana menjalankan {$queries} query.");
    }
}
