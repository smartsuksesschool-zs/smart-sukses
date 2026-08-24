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
 * NOTIF-04 dan PORTAL-03 di Student Portal.
 *
 * Dua hal yang paling mudah salah di sini: siswa **bukan** penerima notifikasi
 * bertarget kelas — NOTIF-01 poin 3 menyebut orang tua, dan hanya orang tua
 * (butir 199) — dan portal siswa tidak boleh membawa satu pun angka keuangan
 * (butir 189).
 */
class StudentNotificationTest extends TestCase
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

    public function test_the_student_may_open_the_inbox(): void
    {
        $this->actingAs($this->studentUserA)
            ->get(route('student.notifications'))
            ->assertOk()
            ->assertSee('Notifikasi');
    }

    public function test_a_guest_is_redirected_to_the_student_login(): void
    {
        $this->get(route('student.notifications'))
            ->assertRedirect(route('student.login'));
    }

    public function test_non_student_roles_are_refused(): void
    {
        foreach ([$this->parentA, $this->teacherA, $this->adminA] as $user) {
            $this->actingAs($user)
                ->get(route('student.notifications'))
                ->assertForbidden();
        }
    }

    public function test_a_student_who_must_change_their_password_is_refused(): void
    {
        $this->studentUserA->forceFill(['must_change_password' => true])->save();

        $this->actingAs($this->studentUserA)
            ->get(route('student.notifications'))
            ->assertForbidden();
    }

    // ------------------------------------------------------- apa yang terlihat

    public function test_a_branch_wide_announcement_is_visible(): void
    {
        $this->announceToAll();

        $this->actingAs($this->studentUserA)
            ->get(route('student.notifications'))
            ->assertOk()
            ->assertSee('Untuk Semua Cabang A');
    }

    public function test_an_individual_announcement_addressed_to_the_student_is_visible(): void
    {
        $this->announceTo($this->studentUserA);

        $this->actingAs($this->studentUserA)
            ->get(route('student.notifications'))
            ->assertOk()
            ->assertSee('Untuk Ahmad Fauzi');
    }

    public function test_an_unrelated_individual_announcement_is_not_visible(): void
    {
        $this->announceTo($this->parentA);
        $this->announceTo($this->teacherA);

        $response = $this->actingAs($this->studentUserA)
            ->get(route('student.notifications'))
            ->assertOk();

        $response->assertDontSee('Untuk Bapak Ahmad');
        $response->assertDontSee('Untuk Pak Rudi');
    }

    /**
     * Notifikasi kelas anaknya adalah milik orang tuanya, bukan miliknya —
     * meskipun ia siswa kelas itu (butir 199).
     */
    public function test_a_class_announcement_does_not_make_the_student_a_recipient(): void
    {
        $this->announceToClass($this->classA);

        $this->actingAs($this->studentUserA)
            ->get(route('student.notifications'))
            ->assertOk()
            ->assertDontSee('Untuk Kelas 7A');

        // Dan orang tuanya tetap menerimanya.
        $this->actingAs($this->parentA)
            ->get(route('portal.notifications'))
            ->assertOk()
            ->assertSee('Untuk Kelas 7A');
    }

    public function test_drafts_and_other_branches_are_not_visible(): void
    {
        $this->draftAnnouncement();
        $this->announceToOtherSchool();

        $response = $this->actingAs($this->studentUserA)
            ->get(route('student.notifications'))
            ->assertOk();

        $response->assertDontSee('Draf Belum Terkirim');
        $response->assertDontSee('Untuk Semua Cabang B');
    }

    // -------------------------------------------------- lencana & penandaan

    public function test_the_unread_badge_counts_only_what_is_addressed_to_the_student(): void
    {
        $this->announceToAll();
        $this->announceTo($this->studentUserA);
        $this->announceToClass($this->classA);
        $this->announceTo($this->parentA);
        $this->draftAnnouncement();

        Livewire::actingAs($this->studentUserA)
            ->test(NotificationInbox::class)
            ->assertViewHas('unreadCount', 2);
    }

    public function test_clicking_a_notification_marks_it_read(): void
    {
        $notification = $this->announceToAll();

        Livewire::actingAs($this->studentUserA)
            ->test(NotificationInbox::class)
            ->call('open', $notification->id)
            ->assertSee('Isi pengumuman.')
            ->assertViewHas('unreadCount', 0);

        $this->assertDatabaseHas('notification_reads', [
            'notification_id' => $notification->id,
            'user_id' => $this->studentUserA->id,
        ]);
    }

    public function test_mark_all_read_touches_only_the_students_own_notifications(): void
    {
        $mine = $this->announceToAll();
        $classAnnouncement = $this->announceToClass($this->classA);

        Livewire::actingAs($this->studentUserA)
            ->test(NotificationInbox::class)
            ->call('markAllRead')
            ->assertViewHas('unreadCount', 0);

        $read = NotificationRead::query()->pluck('notification_id')->all();

        $this->assertSame([$mine->id], $read);
        $this->assertNotContains($classAnnouncement->id, $read);
    }

    // ------------------------------------------------------------- dasbor API

    public function test_the_dashboard_reports_notifications_as_available(): void
    {
        $this->announceToAll();

        $body = $this->asUser($this->studentUserA)
            ->getJson('/api/v1/student/dashboard')
            ->assertOk();

        $body->assertJsonPath('data.notifications.available', true);
        $body->assertJsonPath('data.notifications.unread_count', 1);
        $body->assertJsonPath('data.notifications.items.0.title', 'Untuk Semua Cabang A');
    }

    public function test_the_dashboard_unread_count_is_real_and_follows_reading(): void
    {
        $notification = $this->announceToAll();
        $this->announceTo($this->studentUserA);

        $this->asUser($this->studentUserA)
            ->getJson('/api/v1/student/dashboard')
            ->assertOk()
            ->assertJsonPath('data.notifications.unread_count', 2);

        app(NotificationCenter::class)->markRead($this->studentUserA, (int) $notification->id);

        $this->asUser($this->studentUserA)
            ->getJson('/api/v1/student/dashboard')
            ->assertOk()
            ->assertJsonPath('data.notifications.unread_count', 1);
    }

    public function test_the_dashboard_items_are_bounded_while_the_count_is_not(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->announce(['title' => 'Pengumuman '.$i]);
        }

        $body = $this->asUser($this->studentUserA)
            ->getJson('/api/v1/student/dashboard')
            ->assertOk();

        $body->assertJsonCount(NotificationCenter::DASHBOARD_LIMIT, 'data.notifications.items');
        $body->assertJsonPath('data.notifications.unread_count', 8);
        $body->assertJsonPath('data.notifications.items.0.title', 'Pengumuman 8');
    }

    /** Bentuk itemnya daftar-izin: tidak ada isi pesan, tidak ada kolom internal. */
    public function test_the_dashboard_item_shape_is_an_allow_list(): void
    {
        $this->announceToAll();

        $item = $this->asUser($this->studentUserA)
            ->getJson('/api/v1/student/dashboard')
            ->assertOk()
            ->json('data.notifications.items.0');

        $this->assertEqualsCanonicalizing(
            ['id', 'title', 'type', 'type_label', 'sent_at', 'is_read'],
            array_keys($item),
        );
    }

    // ------------------------------------------------------ navigasi & batas

    /**
     * PORTAL-03 poin 1 — keempat menu, dan Notifikasi kini benar-benar dapat
     * diklik (butir 208).
     */
    public function test_the_notification_menu_is_active_and_clickable(): void
    {
        $response = $this->actingAs($this->studentUserA)
            ->get(route('student.dashboard'))
            ->assertOk();

        $response->assertSee('href="'.route('student.notifications').'"', false);
        $response->assertDontSee('Tersedia setelah modul notifikasi aktif');

        $this->actingAs($this->studentUserA)
            ->get(route('student.notifications'))
            ->assertOk()
            ->assertSee('aria-current="page"', false);
    }

    /**
     * Portal siswa tetap tanpa keuangan: matriks 1.1.2 menolak SISWA pada
     * Tagihan SPP dan Catat Pembayaran, dan halaman notifikasi tidak menjadi
     * pintu belakangnya (butir 189).
     */
    public function test_no_fee_or_payment_link_is_introduced(): void
    {
        $this->announceToAll();

        $response = $this->actingAs($this->studentUserA)
            ->get(route('student.notifications'))
            ->assertOk();

        foreach (['Tagihan', 'Pembayaran', 'Rp', 'SPP'] as $forbidden) {
            $response->assertDontSee($forbidden);
        }

        $response->assertDontSee(route('portal.fees'), false);
    }
}
