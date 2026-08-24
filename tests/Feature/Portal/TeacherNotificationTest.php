<?php

namespace Tests\Feature\Portal;

use App\Livewire\Portal\NotificationInbox;
use App\Models\NotificationRead;
use App\Services\Notification\NotificationCenter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Portal\Concerns\BuildsNotificationFixture;
use Tests\TestCase;

/**
 * NOTIF-04 di Teacher Portal, dan dasbor guru API 4.11.
 *
 * Yang paling penting di sini: guru menerima notifikasi, dan itu tidak membuatnya
 * boleh membuat notifikasi. Matriks 1.1.2 menandai GURU/WALI ❌ pada "Notifikasi
 * (buat)", jadi hadirnya modul notifikasi tidak menggeser satu pun kewenangan
 * (butir 213).
 */
class TeacherNotificationTest extends TestCase
{
    use BuildsNotificationFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->buildNotificationFixture();
    }

    // ------------------------------------------------------------ akses rute

    public function test_the_teacher_may_open_the_inbox(): void
    {
        $this->actingAs($this->teacherA)
            ->get(route('teacher.notifications'))
            ->assertOk()
            ->assertSee('Notifikasi');
    }

    /** Wali kelas memegang seluruh akses guru, termasuk kotak masuk ini. */
    public function test_the_homeroom_teacher_may_open_the_inbox(): void
    {
        $this->actingAs($this->waliA)
            ->get(route('teacher.notifications'))
            ->assertOk();
    }

    public function test_non_teacher_roles_are_refused(): void
    {
        foreach ([$this->parentA, $this->studentUserA, $this->adminA] as $user) {
            $this->actingAs($user)
                ->get(route('teacher.notifications'))
                ->assertForbidden();
        }
    }

    /**
     * Guru berbagi sesi dengan panel, jadi tamu diarahkan ke halaman masuk
     * panel — perilaku yang sudah berlaku di halaman portal guru lainnya.
     */
    public function test_a_guest_is_sent_to_the_panel_login(): void
    {
        $this->get(route('teacher.notifications'))
            ->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_a_teacher_who_must_change_their_password_is_refused(): void
    {
        $this->teacherA->forceFill(['must_change_password' => true])->save();

        $this->actingAs($this->teacherA)
            ->get(route('teacher.notifications'))
            ->assertForbidden();
    }

    // ------------------------------------------------------- apa yang terlihat

    public function test_a_branch_wide_announcement_is_visible(): void
    {
        $this->announceToAll();

        $this->actingAs($this->teacherA)
            ->get(route('teacher.notifications'))
            ->assertOk()
            ->assertSee('Untuk Semua Cabang A');
    }

    public function test_an_individual_announcement_addressed_to_the_teacher_is_visible(): void
    {
        $this->announceTo($this->teacherA);

        $this->actingAs($this->teacherA)
            ->get(route('teacher.notifications'))
            ->assertOk()
            ->assertSee('Untuk Pak Rudi');
    }

    public function test_an_unrelated_individual_announcement_is_not_visible(): void
    {
        $this->announceTo($this->parentA);
        $this->announceTo($this->waliA);

        $response = $this->actingAs($this->teacherA)
            ->get(route('teacher.notifications'))
            ->assertOk();

        $response->assertDontSee('Untuk Bapak Ahmad');
        $response->assertDontSee('Untuk Bu Sari');
    }

    /**
     * NOTIF-01 poin 3 — target kelas "hanya untuk orang tua siswa kelas
     * tersebut". Bukan gurunya, dan bukan wali kelasnya (butir 199).
     */
    public function test_a_class_announcement_never_reaches_a_teacher(): void
    {
        $this->announceToClass($this->classA);

        $this->actingAs($this->teacherA)
            ->get(route('teacher.notifications'))
            ->assertOk()
            ->assertDontSee('Untuk Kelas 7A');

        $this->actingAs($this->waliA)
            ->get(route('teacher.notifications'))
            ->assertOk()
            ->assertDontSee('Untuk Kelas 7A');
    }

    public function test_drafts_and_other_branches_are_not_visible(): void
    {
        $this->draftAnnouncement();
        $this->announceToOtherSchool();

        $response = $this->actingAs($this->teacherA)
            ->get(route('teacher.notifications'))
            ->assertOk();

        $response->assertDontSee('Draf Belum Terkirim');
        $response->assertDontSee('Untuk Semua Cabang B');
    }

    // -------------------------------------------------- lencana & penandaan

    public function test_the_unread_badge_counts_only_what_is_addressed_to_the_teacher(): void
    {
        $this->announceToAll();
        $this->announceTo($this->teacherA);
        $this->announceToClass($this->classA);
        $this->announceTo($this->parentA);
        $this->draftAnnouncement();

        Livewire::actingAs($this->teacherA)
            ->test(NotificationInbox::class)
            ->assertViewHas('unreadCount', 2);
    }

    public function test_clicking_a_notification_marks_it_read(): void
    {
        $notification = $this->announceToAll();

        Livewire::actingAs($this->teacherA)
            ->test(NotificationInbox::class)
            ->call('open', $notification->id)
            ->assertViewHas('unreadCount', 0);

        $this->assertDatabaseHas('notification_reads', [
            'notification_id' => $notification->id,
            'user_id' => $this->teacherA->id,
        ]);
    }

    public function test_mark_all_read_touches_only_the_teachers_own_notifications(): void
    {
        $mine = $this->announceToAll();
        $classAnnouncement = $this->announceToClass($this->classA);
        $draft = $this->draftAnnouncement();

        Livewire::actingAs($this->teacherA)
            ->test(NotificationInbox::class)
            ->call('markAllRead')
            ->assertViewHas('unreadCount', 0);

        $read = NotificationRead::query()->pluck('notification_id')->all();

        $this->assertSame([$mine->id], $read);
        $this->assertNotContains($classAnnouncement->id, $read);
        $this->assertNotContains($draft->id, $read);
    }

    // ------------------------------------------------------------- dasbor API

    public function test_the_dashboard_reports_notifications_as_available(): void
    {
        $this->announceToAll();

        $body = $this->asUser($this->teacherA)
            ->getJson('/api/v1/teacher/dashboard')
            ->assertOk();

        $body->assertJsonPath('data.notifications.available', true);
        $body->assertJsonPath('data.notifications.unread_count', 1);
        $body->assertJsonPath('data.notifications.items.0.title', 'Untuk Semua Cabang A');
        $body->assertJsonPath('data.notifications.items.0.is_read', false);
    }

    public function test_the_dashboard_unread_count_is_real_and_follows_reading(): void
    {
        $notification = $this->announceToAll();
        $this->announceTo($this->teacherA);

        $this->asUser($this->teacherA)
            ->getJson('/api/v1/teacher/dashboard')
            ->assertOk()
            ->assertJsonPath('data.notifications.unread_count', 2);

        app(NotificationCenter::class)->markRead($this->teacherA, (int) $notification->id);

        $this->asUser($this->teacherA)
            ->getJson('/api/v1/teacher/dashboard')
            ->assertOk()
            ->assertJsonPath('data.notifications.unread_count', 1);
    }

    /**
     * Batas tampilan lima terbaru (butir 206): angkanya bukan aturan bisnis,
     * tetapi harus benar-benar berlaku — dan lencananya tetap menghitung
     * seluruhnya, bukan hanya yang ikut tampil.
     */
    public function test_the_dashboard_items_are_bounded_while_the_count_is_not(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->announce(['title' => 'Pengumuman '.$i]);
        }

        $body = $this->asUser($this->teacherA)
            ->getJson('/api/v1/teacher/dashboard')
            ->assertOk();

        $body->assertJsonCount(NotificationCenter::DASHBOARD_LIMIT, 'data.notifications.items');
        $body->assertJsonPath('data.notifications.unread_count', 7);

        // Yang tampil memang yang terbaru.
        $body->assertJsonPath('data.notifications.items.0.title', 'Pengumuman 7');
    }

    /** Dasbor tidak pernah menyertakan draf, dan tidak pernah isi pesannya. */
    public function test_the_dashboard_carries_no_draft_and_no_internal_columns(): void
    {
        $this->draftAnnouncement();
        $this->announceToAll();

        $body = $this->asUser($this->teacherA)
            ->getJson('/api/v1/teacher/dashboard')
            ->assertOk();

        $body->assertJsonCount(1, 'data.notifications.items');
        $body->assertJsonMissing(['title' => 'Draf Belum Terkirim']);

        $item = $body->json('data.notifications.items.0');

        $this->assertEqualsCanonicalizing(
            ['id', 'title', 'type', 'type_label', 'sent_at', 'is_read'],
            array_keys($item),
        );
    }

    // ------------------------------------------- kewenangan tidak bergeser

    /**
     * PORTAL-02 poin 2 meminta pintasan "Buat Pengumuman"; konfliknya tetap
     * terbuka dan pintasannya tetap tanpa tautan (butir 213).
     */
    public function test_the_announcement_shortcut_stays_disabled(): void
    {
        $response = $this->actingAs($this->teacherA)
            ->get(route('teacher.dashboard'))
            ->assertOk();

        $response->assertSee('Buat Pengumuman');
        $response->assertSee('aria-disabled="true"', false);
        $response->assertSee('kewenangan Admin Sekolah');
    }

    public function test_the_teacher_still_holds_no_notification_create_permission(): void
    {
        foreach ([$this->teacherA, $this->waliA] as $teacher) {
            $this->assertFalse($teacher->can('notification.manage'));
            $this->assertFalse($teacher->can('notification.view'));
        }

        // Dan jalur pembuatannya benar-benar tertutup, bukan hanya tombolnya.
        // Muatannya sengaja lengkap dan sah: yang harus menolaknya izin, bukan
        // validasi.
        $this->asUser($this->teacherA)->postJson('/api/v1/notifications', [
            'title' => 'Percobaan',
            'message' => 'Isi',
            'type' => 'ANNOUNCEMENT',
            'target_type' => 'ALL',
            'action' => 'send',
        ])->assertForbidden();

        $this->assertDatabaseMissing('notifications', ['title' => 'Percobaan']);
    }

    /** Portal guru tidak punya satu pun rute pembuatan pengumuman. */
    public function test_the_teacher_portal_exposes_no_announcement_route(): void
    {
        $teacherUris = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'teacher.'))
            ->map(fn ($route) => $route->uri());

        foreach ($teacherUris as $uri) {
            $this->assertStringNotContainsString('pengumuman', $uri);
        }

        // Satu-satunya rute notifikasi portal guru adalah kotak masuknya.
        $this->assertSame(
            1,
            $teacherUris->filter(fn ($uri) => str_contains($uri, 'notifikasi'))->count(),
        );
    }
}
