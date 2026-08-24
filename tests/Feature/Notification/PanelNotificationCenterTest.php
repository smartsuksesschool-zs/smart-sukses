<?php

namespace Tests\Feature\Notification;

use App\Filament\Pages\NotifikasiSaya;
use App\Filament\Resources\NotificationResource;
use App\Models\NotificationRead;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Portal\Concerns\BuildsNotificationFixture;
use Tests\TestCase;

/**
 * NOTIF-04 untuk pengguna panel — kotak masuk "Notifikasi Saya".
 *
 * Yang paling penting di sini ada dua. Pertama: membaca notifikasi sendiri bukan
 * kewenangan yang sama dengan membuat pengumuman, sehingga Bendahara — yang
 * ditolak seluruh modul notifikasi pada matriks "Notifikasi (buat)" — tetap
 * berhak membaca yang ditujukan kepadanya (butir 218). Kedua: Super Admin tidak
 * mendapat kotak masuk lintas cabang hanya karena ia melewati SchoolScope
 * (butir 220).
 */
class PanelNotificationCenterTest extends TestCase
{
    use BuildsNotificationFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->buildNotificationFixture();
    }

    /**
     * Ketiga peran panel yang sampai Batch 8.2 hanya dapat membaca notifikasinya
     * lewat API.
     *
     * @return array<string, array<int, string>>
     */
    public static function panelRecipients(): array
    {
        return [
            'school admin' => ['adminA'],
            'kepala sekolah' => ['kepalaA'],
            'bendahara' => ['bendaharaA'],
        ];
    }

    // ---------------------------------------------------------- akses halaman

    #[DataProvider('panelRecipients')]
    public function test_a_panel_user_may_open_their_own_inbox(string $who): void
    {
        $this->actingAs($this->{$who})
            ->get(NotifikasiSaya::getUrl())
            ->assertOk()
            ->assertSee('Notifikasi Saya');
    }

    /** Guru dan wali kelas juga pengguna panel, dan juga penerima. */
    public function test_teachers_may_open_the_panel_inbox_too(): void
    {
        foreach ([$this->teacherA, $this->waliA] as $teacher) {
            $this->actingAs($teacher)
                ->get(NotifikasiSaya::getUrl())
                ->assertOk();
        }
    }

    /**
     * Siswa dan orang tua ditolak seluruh panel (User::canAccessPanel), jadi juga
     * halaman ini — dengan 403, bukan pengalihan: mereka sudah masuk, hanya bukan
     * ke sini. Kotak masuk mereka ada di portalnya masing-masing.
     */
    public function test_portal_only_roles_cannot_reach_the_panel_inbox(): void
    {
        foreach ([$this->studentUserA, $this->parentA] as $user) {
            $this->actingAs($user)
                ->get(NotifikasiSaya::getUrl())
                ->assertForbidden();
        }
    }

    public function test_a_guest_cannot_reach_the_panel_inbox(): void
    {
        $this->get(NotifikasiSaya::getUrl())->assertRedirect();
    }

    // ------------------------------------------------------- apa yang terlihat

    #[DataProvider('panelRecipients')]
    public function test_a_branch_wide_announcement_reaches_every_panel_role(string $who): void
    {
        $this->announceToAll();

        Livewire::actingAs($this->{$who})
            ->test(NotifikasiSaya::class)
            ->assertSee('Untuk Semua Cabang A');
    }

    #[DataProvider('panelRecipients')]
    public function test_an_individual_announcement_addressed_to_them_is_visible(string $who): void
    {
        $user = $this->{$who};

        $this->announceTo($user);

        Livewire::actingAs($user)
            ->test(NotifikasiSaya::class)
            ->assertSee('Untuk '.$user->name);
    }

    #[DataProvider('panelRecipients')]
    public function test_an_individual_announcement_for_someone_else_is_absent(string $who): void
    {
        $this->announceTo($this->teacherA);
        $this->announceTo($this->parentA);

        Livewire::actingAs($this->{$who})
            ->test(NotifikasiSaya::class)
            ->assertDontSee('Untuk Pak Rudi')
            ->assertDontSee('Untuk Bapak Ahmad');
    }

    #[DataProvider('panelRecipients')]
    public function test_drafts_and_other_branches_are_absent(string $who): void
    {
        $this->draftAnnouncement();
        $this->announceToOtherSchool();

        Livewire::actingAs($this->{$who})
            ->test(NotifikasiSaya::class)
            ->assertDontSee('Draf Belum Terkirim')
            ->assertDontSee('Untuk Semua Cabang B');
    }

    /**
     * Notifikasi bertarget kelas hanya untuk orang tua siswa kelas itu — bukan
     * untuk peran panel mana pun (butir 199).
     */
    #[DataProvider('panelRecipients')]
    public function test_a_class_announcement_never_reaches_a_panel_role(string $who): void
    {
        $this->announceToClass($this->classA);

        Livewire::actingAs($this->{$who})
            ->test(NotifikasiSaya::class)
            ->assertDontSee('Untuk Kelas 7A');
    }

    // -------------------------------------------------------------- Super Admin

    /**
     * Super Admin melewati SchoolScope, dan itu **tidak** membuatnya penerima.
     *
     * `school_id`-nya NULL, jadi ia bukan pengguna cabang mana pun; resolver
     * menutup umpannya sepenuhnya (butir 198). Kotak masuk lintas cabang akan
     * berarti mengarang penerima yang tidak pernah ditargetkan siapa pun
     * (butir 220).
     */
    public function test_super_admin_does_not_receive_every_branchs_announcements(): void
    {
        $this->announceToAll();
        $this->announceToOtherSchool();
        $this->announceTo($this->adminA);

        Livewire::actingAs($this->superAdmin)
            ->test(NotifikasiSaya::class)
            ->assertSee('Belum ada notifikasi')
            ->assertDontSee('Untuk Semua Cabang A')
            ->assertDontSee('Untuk Semua Cabang B')
            ->assertDontSee('Untuk Admin Madani');
    }

    public function test_super_admin_carries_no_unread_badge(): void
    {
        $this->announceToAll();
        $this->announceToOtherSchool();

        $this->actingAs($this->superAdmin);

        $this->assertNull(NotifikasiSaya::getNavigationBadge());
    }

    /** Halaman itu sendiri tetap terbuka untuknya, dan isinya kosong. */
    public function test_super_admin_may_open_the_page_and_finds_it_empty(): void
    {
        $this->announceToAll();

        $this->actingAs($this->superAdmin)
            ->get(NotifikasiSaya::getUrl())
            ->assertOk()
            ->assertSee('Belum ada notifikasi');
    }

    // -------------------------------------------------------------- lencana

    #[DataProvider('panelRecipients')]
    public function test_the_unread_badge_counts_only_what_is_addressed_to_them(string $who): void
    {
        $user = $this->{$who};

        $this->announceToAll();
        $this->announceTo($user);
        $this->announceTo($this->parentA);
        $this->announceToClass($this->classA);
        $this->draftAnnouncement();
        $this->announceToOtherSchool();

        $this->actingAs($user);

        $this->assertSame('2', NotifikasiSaya::getNavigationBadge());
    }

    public function test_the_badge_is_absent_when_nothing_is_unread(): void
    {
        $this->actingAs($this->adminA);

        $this->assertNull(NotifikasiSaya::getNavigationBadge());

        $this->announceToAll();

        $this->assertSame('1', NotifikasiSaya::getNavigationBadge());

        Livewire::actingAs($this->adminA)
            ->test(NotifikasiSaya::class)
            ->call('markAllRead');

        $this->actingAs($this->adminA);

        $this->assertNull(NotifikasiSaya::getNavigationBadge());
    }

    /**
     * Lencana sengaja tidak dihitung di halaman kotak masuk itu sendiri.
     *
     * Filament merender navigasi sebagai bagian dari tata letak, yang tidak ikut
     * dirender ulang ketika aksi Livewire berjalan — angkanya akan tertinggal
     * basi tepat di halaman tempat pengguna menandai bacaannya. Yang hidup adalah
     * hitungan di kepala halaman (butir 219).
     */
    public function test_the_badge_is_suppressed_on_the_inbox_page_itself(): void
    {
        $this->announceToAll();

        $this->actingAs($this->adminA);

        // Di halaman panel lain lencananya ada.
        $this->get(NotificationResource::getUrl('index'))->assertOk();
        $this->assertSame('1', NotifikasiSaya::getNavigationBadge());

        // Di halaman kotak masuk tidak dihitung sama sekali.
        $this->get(NotifikasiSaya::getUrl())->assertOk();
        $this->assertNull(NotifikasiSaya::getNavigationBadge());
    }

    // ------------------------------------------------------ klik menandai baca

    #[DataProvider('panelRecipients')]
    public function test_clicking_marks_read_and_reveals_the_message(string $who): void
    {
        $user = $this->{$who};
        $notification = $this->announceToAll();

        Livewire::actingAs($user)
            ->test(NotifikasiSaya::class)
            ->assertDontSee('Isi pengumuman.')
            ->call('open', $notification->id)
            ->assertSee('Isi pengumuman.')
            ->assertSee('Semua notifikasi sudah dibaca');

        $this->assertDatabaseHas('notification_reads', [
            'notification_id' => $notification->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_merely_opening_the_page_marks_nothing_read(): void
    {
        $this->announceToAll();

        $this->actingAs($this->adminA)->get(NotifikasiSaya::getUrl())->assertOk();

        $this->assertDatabaseCount('notification_reads', 0);
    }

    public function test_a_second_click_preserves_the_first_read_time(): void
    {
        $notification = $this->announceToAll();

        $component = Livewire::actingAs($this->adminA)
            ->test(NotifikasiSaya::class)
            ->call('open', $notification->id);

        $first = NotificationRead::query()->firstOrFail()->read_at->toDateTimeString();

        $this->travel(3)->minutes();

        $component->call('open', $notification->id)->call('open', $notification->id);

        $this->assertDatabaseCount('notification_reads', 1);
        $this->assertSame($first, NotificationRead::query()->firstOrFail()->read_at->toDateTimeString());

        $this->travelBack();
    }

    public function test_a_notification_that_is_not_theirs_cannot_be_opened(): void
    {
        $foreign = $this->announceTo($this->parentA);

        Livewire::actingAs($this->bendaharaA)
            ->test(NotifikasiSaya::class)
            ->call('open', $foreign->id)
            ->assertStatus(404);

        $this->assertDatabaseCount('notification_reads', 0);
    }

    // --------------------------------------------------- tandai semua dibaca

    #[DataProvider('panelRecipients')]
    public function test_mark_all_read_touches_only_their_own_notifications(string $who): void
    {
        $user = $this->{$who};

        $mine = $this->announceToAll();
        $foreign = $this->announceTo($this->parentA);
        $classAnnouncement = $this->announceToClass($this->classA);
        $draft = $this->draftAnnouncement();
        $otherSchool = $this->announceToOtherSchool();

        Livewire::actingAs($user)
            ->test(NotifikasiSaya::class)
            ->call('markAllRead');

        $read = NotificationRead::query()->pluck('notification_id')->all();

        $this->assertSame([$mine->id], $read);

        foreach ([$foreign, $classAnnouncement, $draft, $otherSchool] as $untouched) {
            $this->assertNotContains($untouched->id, $read);
        }
    }

    public function test_mark_all_read_is_a_safe_no_op_when_there_is_nothing(): void
    {
        Livewire::actingAs($this->superAdmin)
            ->test(NotifikasiSaya::class)
            ->call('markAllRead');

        $this->assertDatabaseCount('notification_reads', 0);
    }

    // ------------------------------- kotak masuk vs manajemen pengumuman

    /**
     * Membaca notifikasi sendiri dan membuat pengumuman adalah dua kewenangan
     * yang berbeda, dan Batch 8.3 tidak menyatukannya (butir 218).
     */
    public function test_the_recipient_inbox_does_not_widen_announcement_management(): void
    {
        // Bendahara: penerima sah, tetapi bukan pembuat.
        $this->assertFalse($this->bendaharaA->can('notification.manage'));

        $this->actingAs($this->bendaharaA)
            ->get(NotifikasiSaya::getUrl())
            ->assertOk();

        $this->actingAs($this->bendaharaA)
            ->get(NotificationResource::getUrl('index'))
            ->assertForbidden();

        // Guru dan wali kelas: sama, dan pembuatannya tetap tertutup.
        foreach ([$this->teacherA, $this->waliA] as $teacher) {
            $this->assertFalse($teacher->can('notification.manage'));

            $this->actingAs($teacher)
                ->get(NotificationResource::getUrl('index'))
                ->assertForbidden();
        }
    }

    /** Yang memang berwenang membuat tetap berwenang — tidak ada yang dicabut. */
    public function test_announcement_management_stays_open_to_the_three_authorised_roles(): void
    {
        foreach ([$this->adminA, $this->kepalaA] as $creator) {
            $this->assertTrue($creator->can('notification.manage'));

            $this->actingAs($creator)
                ->get(NotificationResource::getUrl('index'))
                ->assertOk();
        }

        $this->assertTrue($this->superAdmin->can('notification.manage'));
    }

    /**
     * Dua konsep, dua tempat: riwayat cabang termasuk draf hanya ada di
     * Pengumuman, dan kotak masuk tidak pernah menampilkan draf siapa pun.
     */
    public function test_the_two_pages_show_different_things(): void
    {
        $draft = $this->draftAnnouncement();
        $sent = $this->announceToAll();

        // Pengumuman: pembuat melihat draf dan yang terkirim.
        $this->actingAs($this->adminA)
            ->get(NotificationResource::getUrl('index'))
            ->assertOk()
            ->assertSee($draft->title)
            ->assertSee($sent->title);

        // Notifikasi Saya: hanya yang terkirim dan ditujukan kepadanya.
        Livewire::actingAs($this->adminA)
            ->test(NotifikasiSaya::class)
            ->assertSee($sent->title)
            ->assertDontSee($draft->title);
    }

    // ---------------------------------------------- keadaan terbaca satu saja

    public function test_marking_read_in_the_panel_shows_through_the_api(): void
    {
        $notification = $this->announceToAll();

        Livewire::actingAs($this->kepalaA)
            ->test(NotifikasiSaya::class)
            ->call('open', $notification->id);

        $this->asUser($this->kepalaA)
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->asUser($this->kepalaA)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.is_read', true);
    }

    public function test_marking_read_through_the_api_shows_in_the_panel(): void
    {
        $notification = $this->announceToAll();

        $this->asUser($this->bendaharaA)
            ->patchJson('/api/v1/notifications/'.$notification->id.'/read')
            ->assertOk();

        Livewire::actingAs($this->bendaharaA)
            ->test(NotifikasiSaya::class)
            ->assertSee('Sudah dibaca')
            ->assertSee('Semua notifikasi sudah dibaca');
    }

    // ----------------------------------------------- perenderan & batas query

    public function test_a_script_like_message_is_rendered_escaped(): void
    {
        $notification = $this->announce([
            'title' => 'Judul <b>tebal</b>',
            'message' => '<script>alert("xss")</script> Halo',
        ]);

        $html = Livewire::actingAs($this->adminA)
            ->test(NotifikasiSaya::class)
            ->call('open', $notification->id)
            ->html();

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<b>tebal</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;tebal&lt;/b&gt;', $html);
    }

    public function test_line_breaks_are_handled_by_css_rather_than_markup(): void
    {
        $notification = $this->announce(['message' => "Baris satu\nBaris dua"]);

        $html = Livewire::actingAs($this->adminA)
            ->test(NotifikasiSaya::class)
            ->call('open', $notification->id)
            ->html();

        $this->assertStringContainsString('whitespace-pre-line', $html);
        $this->assertStringNotContainsString('<br', $html);
    }

    /**
     * Umpan disaring predikat, bukan perulangan: biaya lima notifikasi sama
     * dengan biaya lima puluh (butir 197).
     */
    public function test_the_inbox_query_count_does_not_follow_the_number_of_notifications(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->announce(['title' => 'Awal '.$i]);
        }

        Livewire::actingAs($this->adminA)->test(NotifikasiSaya::class);

        DB::enableQueryLog();
        DB::flushQueryLog();

        Livewire::actingAs($this->adminA)->test(NotifikasiSaya::class);

        $withFive = count(DB::getQueryLog());

        for ($i = 0; $i < 45; $i++) {
            $this->announce(['title' => 'Tambahan '.$i]);
        }

        DB::flushQueryLog();

        Livewire::actingAs($this->adminA)->test(NotifikasiSaya::class);

        $withFifty = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertSame($withFive, $withFifty);
    }

    /** Kolom internal tidak pernah sampai ke halaman. */
    public function test_the_inbox_leaks_no_internal_columns(): void
    {
        $notification = $this->announceToClass($this->classA);
        $mine = $this->announceToAll();

        $html = Livewire::actingAs($this->adminA)
            ->test(NotifikasiSaya::class)
            ->call('open', $mine->id)
            ->html();

        foreach (['wa_template', 'school_id', 'target_id', 'is_draft', 'sender_id'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }

        $this->assertStringNotContainsString('"'.$notification->target_id.'"', $html);
    }
}
