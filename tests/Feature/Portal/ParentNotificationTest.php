<?php

namespace Tests\Feature\Portal;

use App\Livewire\Portal\NotificationInbox;
use App\Models\NotificationRead;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Portal\Concerns\BuildsNotificationFixture;
use Tests\TestCase;

/**
 * NOTIF-04 di Parent Portal.
 *
 * Yang paling penting di sini: kotak masuk ini milik **akun orang tua**, bukan
 * profil anak yang sedang dipilih. Portal orang tua adalah satu-satunya portal
 * dengan pemilih anak, jadi ia satu-satunya tempat di mana "notifikasi siapa"
 * dapat tertukar dengan "data anak yang mana" (butir 212).
 */
class ParentNotificationTest extends TestCase
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

    public function test_the_parent_may_open_the_inbox(): void
    {
        $this->actingAs($this->parentA)
            ->get(route('portal.notifications'))
            ->assertOk()
            ->assertSee('Notifikasi');
    }

    /**
     * Tamu mengikuti perilaku yang sudah berlaku di portal ini: diarahkan ke
     * halaman masuk portal, bukan ke halaman masuk panel.
     */
    public function test_a_guest_is_redirected_to_the_portal_login(): void
    {
        $this->get(route('portal.notifications'))
            ->assertRedirect(route('portal.login'));
    }

    /**
     * Peran lain ditolak oleh middleware yang sama dengan halaman portal
     * lainnya — bukan oleh halaman ini, dan bukan hanya dengan menyembunyikan
     * menunya.
     */
    public function test_non_parent_roles_are_refused(): void
    {
        foreach ([$this->teacherA, $this->studentUserA, $this->adminA] as $user) {
            $this->actingAs($user)
                ->get(route('portal.notifications'))
                ->assertForbidden();
        }
    }

    public function test_a_parent_who_must_change_their_password_is_refused(): void
    {
        $this->parentA->forceFill(['must_change_password' => true])->save();

        $this->actingAs($this->parentA)
            ->get(route('portal.notifications'))
            ->assertForbidden();
    }

    public function test_an_inactive_parent_is_refused(): void
    {
        $this->parentA->forceFill(['is_active' => false])->save();

        $this->actingAs($this->parentA)
            ->get(route('portal.notifications'))
            ->assertForbidden();
    }

    // ------------------------------------------------------- apa yang terlihat

    public function test_a_branch_wide_announcement_is_visible(): void
    {
        $this->announceToAll();

        $this->actingAs($this->parentA)
            ->get(route('portal.notifications'))
            ->assertOk()
            ->assertSee('Untuk Semua Cabang A');
    }

    /**
     * NOTIF-01 poin 3 — target kelas hanya untuk orang tua siswa kelas itu.
     * Orang tua yang anaknya di kelas itu melihatnya.
     */
    public function test_a_class_announcement_for_the_own_childs_class_is_visible(): void
    {
        $this->announceToClass($this->classA);

        $this->actingAs($this->parentA)
            ->get(route('portal.notifications'))
            ->assertOk()
            ->assertSee('Untuk Kelas 7A');
    }

    public function test_a_class_announcement_for_another_class_is_not_visible(): void
    {
        $this->announceToClass($this->otherClassA);

        $this->actingAs($this->parentA)
            ->get(route('portal.notifications'))
            ->assertOk()
            ->assertDontSee('Untuk Kelas 7B');
    }

    public function test_an_individual_announcement_addressed_to_the_parent_is_visible(): void
    {
        $this->announceTo($this->parentA);

        $this->actingAs($this->parentA)
            ->get(route('portal.notifications'))
            ->assertOk()
            ->assertSee('Untuk Bapak Ahmad');
    }

    public function test_an_individual_announcement_for_someone_else_is_not_visible(): void
    {
        $this->announceTo($this->otherParentA);
        $this->announceTo($this->teacherA);

        $response = $this->actingAs($this->parentA)
            ->get(route('portal.notifications'))
            ->assertOk();

        $response->assertDontSee('Untuk Ibu Lain');
        $response->assertDontSee('Untuk Pak Rudi');
    }

    public function test_a_draft_is_never_visible(): void
    {
        $this->draftAnnouncement();

        $this->actingAs($this->parentA)
            ->get(route('portal.notifications'))
            ->assertOk()
            ->assertDontSee('Draf Belum Terkirim');
    }

    public function test_another_branchs_announcement_is_not_visible(): void
    {
        $this->announceToOtherSchool();

        $this->actingAs($this->parentA)
            ->get(route('portal.notifications'))
            ->assertOk()
            ->assertDontSee('Untuk Semua Cabang B');
    }

    // ------------------------------------------------------------- lencana

    /**
     * NOTIF-04 poin 1 — lencana memakai hitungan kanonik, dan angkanya adalah
     * yang benar-benar ditujukan kepadanya: dua dari lima notifikasi yang ada.
     */
    public function test_the_unread_badge_counts_only_what_is_addressed_to_the_parent(): void
    {
        $this->announceToAll();
        $this->announceToClass($this->classA);
        $this->announceToClass($this->otherClassA);
        $this->announceTo($this->teacherA);
        $this->draftAnnouncement();
        $this->announceToOtherSchool();

        Livewire::actingAs($this->parentA)
            ->test(NotificationInbox::class)
            ->assertViewHas('unreadCount', 2);
    }

    public function test_the_badge_disappears_when_nothing_is_unread(): void
    {
        $this->announceToAll();

        Livewire::actingAs($this->parentA)
            ->test(NotificationInbox::class)
            ->call('markAllRead')
            ->assertViewHas('unreadCount', 0);

        // Nol tidak dicetak sebagai lencana di navigasi. Yang diperiksa
        // elemennya, bukan nama kelasnya — nama itu selalu ada di stylesheet.
        $this->actingAs($this->parentA)
            ->get(route('portal.dashboard'))
            ->assertOk()
            ->assertDontSee('<span class="portal-nav__badge">', false)
            ->assertDontSee('<span class="portal-bell__count">', false);
    }

    // ---------------------------------------------------- klik menandai baca

    /**
     * NOTIF-04 poin 2 — klik menandai terbaca, dan sekaligus membentangkan
     * pesannya.
     */
    public function test_clicking_a_notification_marks_it_read_and_reveals_the_message(): void
    {
        $notification = $this->announceToAll();

        Livewire::actingAs($this->parentA)
            ->test(NotificationInbox::class)
            ->assertViewHas('unreadCount', 1)
            ->assertDontSee('Isi pengumuman.')
            ->call('open', $notification->id)
            ->assertSee('Isi pengumuman.')
            ->assertViewHas('unreadCount', 0);

        $this->assertDatabaseHas('notification_reads', [
            'notification_id' => $notification->id,
            'user_id' => $this->parentA->id,
        ]);
    }

    /**
     * Memuat halaman bukan tindakan membaca: hanya klik yang menandai
     * (butir 209).
     */
    public function test_merely_opening_the_page_marks_nothing_read(): void
    {
        $this->announceToAll();

        $this->actingAs($this->parentA)->get(route('portal.notifications'))->assertOk();

        $this->assertDatabaseCount('notification_reads', 0);
    }

    /**
     * Klik kedua idempoten: tidak ada baris kedua, dan waktu baca pertama tidak
     * berubah (butir 192).
     */
    public function test_a_second_click_is_idempotent(): void
    {
        $notification = $this->announceToAll();

        $component = Livewire::actingAs($this->parentA)
            ->test(NotificationInbox::class)
            ->call('open', $notification->id);

        $first = NotificationRead::query()
            ->where('notification_id', $notification->id)
            ->where('user_id', $this->parentA->id)
            ->firstOrFail();

        // Klik kedua menutup, klik ketiga membuka lagi — keduanya lewat jalur
        // penandaan yang sama.
        $component->call('open', $notification->id)->call('open', $notification->id);

        $this->assertDatabaseCount('notification_reads', 1);

        $this->assertSame(
            $first->read_at->toDateTimeString(),
            $first->fresh()->read_at->toDateTimeString(),
        );
    }

    public function test_a_notification_that_is_not_theirs_cannot_be_opened(): void
    {
        $foreign = $this->announceTo($this->teacherA);

        Livewire::actingAs($this->parentA)
            ->test(NotificationInbox::class)
            ->call('open', $foreign->id)
            ->assertStatus(404);

        $this->assertDatabaseCount('notification_reads', 0);
    }

    // -------------------------------------------------- tandai semua dibaca

    public function test_mark_all_read_marks_only_what_is_addressed_to_the_parent(): void
    {
        $mine = $this->announceToAll();
        $classMine = $this->announceToClass($this->classA);
        $foreign = $this->announceTo($this->teacherA);
        $draft = $this->draftAnnouncement();
        $otherSchool = $this->announceToOtherSchool();

        Livewire::actingAs($this->parentA)
            ->test(NotificationInbox::class)
            ->call('markAllRead')
            ->assertViewHas('unreadCount', 0);

        $read = NotificationRead::query()->pluck('notification_id')->all();

        $this->assertEqualsCanonicalizing([$mine->id, $classMine->id], $read);
        $this->assertNotContains($foreign->id, $read);
        $this->assertNotContains($draft->id, $read);
        $this->assertNotContains($otherSchool->id, $read);
    }

    public function test_mark_all_read_is_a_safe_no_op_without_notifications(): void
    {
        Livewire::actingAs($this->parentA)
            ->test(NotificationInbox::class)
            ->call('markAllRead')
            ->assertViewHas('unreadCount', 0);

        $this->assertDatabaseCount('notification_reads', 0);
    }

    // ------------------------------------------------ kotak masuk milik akun

    /**
     * Berpindah anak tidak mengubah kotak masuk: penerimanya akun orang tua,
     * bukan profil anak (butir 212).
     *
     * Diuji lewat notifikasi kelas — satu-satunya jenis yang memang bergantung
     * pada anak — dengan orang tua yang punya anak di dua kelas sekaligus.
     * Keduanya harus terlihat bersama, apa pun anak yang sedang dipilih.
     */
    public function test_switching_child_does_not_change_the_personal_inbox(): void
    {
        $this->studentFor($this->schoolA, $this->otherClassA, 'Anak Kedua', null, $this->parentA);

        $this->announceToClass($this->classA);
        $this->announceToClass($this->otherClassA);

        $response = $this->actingAs($this->parentA)
            ->get(route('portal.notifications'))
            ->assertOk();

        $response->assertSee('Untuk Kelas 7A');
        $response->assertSee('Untuk Kelas 7B');

        // Halaman ini tidak menerima parameter anak sama sekali.
        $this->actingAs($this->parentA)
            ->get(route('portal.notifications').'?child='.$this->childA->id)
            ->assertOk()
            ->assertSee('Untuk Kelas 7B');
    }

    // --------------------------------------------------- navigasi & kebocoran

    public function test_the_navigation_link_is_present_and_marked_current(): void
    {
        $response = $this->actingAs($this->parentA)
            ->get(route('portal.notifications'))
            ->assertOk();

        $response->assertSee('href="'.route('portal.notifications').'"', false);
        $response->assertSee('aria-current="page"', false);
    }

    /**
     * Halaman ini tidak membawa apa pun dari modul lain: tidak ada nominal
     * keuangan, dan tidak ada kolom internal notifikasi.
     *
     * Tautan "Tagihan" pada navigasi bukan kebocoran — ia menu portal yang
     * sudah ada sejak Sprint 7 dan memang milik orang tua; yang diperiksa di
     * sini datanya, bukan menunya.
     */
    public function test_the_inbox_leaks_neither_finance_figures_nor_internal_columns(): void
    {
        $notification = $this->announceToClass($this->classA);

        $response = $this->actingAs($this->parentA)
            ->get(route('portal.notifications'))
            ->assertOk();

        foreach (['Rp', 'wa_template', 'school_id', 'target_id', 'is_draft', 'sender_id'] as $forbidden) {
            $response->assertDontSee($forbidden);
        }

        // Id kelas yang menjadi target tidak pernah ikut ke halaman.
        $response->assertDontSee('"'.$notification->target_id.'"', false);
    }
}
