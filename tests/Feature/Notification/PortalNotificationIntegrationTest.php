<?php

namespace Tests\Feature\Notification;

use App\Livewire\Portal\NotificationInbox;
use App\Models\NotificationRead;
use App\Services\Notification\NotificationCenter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Portal\Concerns\BuildsNotificationFixture;
use Tests\TestCase;

/**
 * Hal-hal yang hanya terlihat ketika ketiga portal dan API dilihat bersama:
 * matriks target yang lengkap, keadaan terbaca yang harus satu, batas query,
 * dan perenderan pesan.
 *
 * Suite per portal memeriksa portalnya masing-masing; yang di sini memeriksa
 * bahwa ketiganya dan API tidak menjawab pertanyaan yang sama dengan tiga
 * jawaban berbeda (butir 215).
 */
class PortalNotificationIntegrationTest extends TestCase
{
    use BuildsNotificationFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->buildNotificationFixture();
    }

    // ------------------------------------------------- matriks target penuh

    /**
     * Tujuh notifikasi, empat penerima, satu tabel jawaban.
     *
     * Ditulis sebagai matriks utuh alih-alih tujuh test terpisah karena yang
     * diperiksa justru **kombinasinya**: yang berbahaya bukan satu target yang
     * salah, melainkan satu peran yang kebetulan lolos pada target yang tidak
     * diujikan padanya (butir 215).
     */
    public function test_every_target_reaches_exactly_the_right_portal_users(): void
    {
        $all = $this->announceToAll();
        $class = $this->announceToClass($this->classA);
        $otherClass = $this->announceToClass($this->otherClassA);
        $toParent = $this->announceTo($this->parentA);
        $toTeacher = $this->announceTo($this->teacherA);
        $toStudent = $this->announceTo($this->studentUserA);
        $draft = $this->draftAnnouncement();
        $otherSchool = $this->announceToOtherSchool();

        $center = app(NotificationCenter::class);

        $expected = [
            // Target ALL menjangkau seluruh pengguna aktif cabang itu.
            'parent' => [$all->id, $class->id, $toParent->id],
            // Guru: ALL dan yang ditujukan kepadanya. Tidak pernah CLASS.
            'teacher' => [$all->id, $toTeacher->id],
            // Siswa: ALL dan yang ditujukan kepadanya. Tidak pernah CLASS.
            'student' => [$all->id, $toStudent->id],
            // Admin cabang juga pengguna cabang itu: ia menerima ALL.
            'admin' => [$all->id],
        ];

        $actual = [
            'parent' => $center->feed($this->parentA)->pluck('id')->all(),
            'teacher' => $center->feed($this->teacherA)->pluck('id')->all(),
            'student' => $center->feed($this->studentUserA)->pluck('id')->all(),
            'admin' => $center->feed($this->adminA)->pluck('id')->all(),
        ];

        foreach ($expected as $who => $ids) {
            $this->assertEqualsCanonicalizing($ids, $actual[$who], "Umpan {$who} tidak sesuai matriks.");

            // Tiga hal tidak boleh muncul pada siapa pun di cabang ini.
            $this->assertNotContains($otherClass->id, $actual[$who], "Kelas lain terlihat oleh {$who}.");
            $this->assertNotContains($draft->id, $actual[$who], "Draf terlihat oleh {$who}.");
            $this->assertNotContains($otherSchool->id, $actual[$who], "Cabang lain terlihat oleh {$who}.");
        }

        // Dan dari sisi cabang seberang, tidak satu pun notifikasi cabang A.
        $this->assertSame(
            [$otherSchool->id],
            $center->feed($this->parentB)->pluck('id')->all(),
        );
    }

    /** Orang tua kelas lain menerima notifikasi kelasnya, bukan kelas 7A. */
    public function test_a_class_announcement_reaches_only_the_parents_of_that_class(): void
    {
        $classA = $this->announceToClass($this->classA);
        $classB = $this->announceToClass($this->otherClassA);

        $center = app(NotificationCenter::class);

        $this->assertContains($classA->id, $center->feed($this->parentA)->pluck('id')->all());
        $this->assertNotContains($classB->id, $center->feed($this->parentA)->pluck('id')->all());

        $this->assertContains($classB->id, $center->feed($this->otherParentA)->pluck('id')->all());
        $this->assertNotContains($classA->id, $center->feed($this->otherParentA)->pluck('id')->all());
    }

    // ------------------------------------------------------- lintas portal

    /**
     * Setiap portal ditutup untuk peran yang bukan miliknya, oleh middleware —
     * bukan dengan menyembunyikan menunya (butir 208).
     */
    public function test_no_role_can_reach_another_portals_inbox(): void
    {
        $matrix = [
            'portal.notifications' => [$this->teacherA, $this->waliA, $this->studentUserA, $this->adminA],
            'teacher.notifications' => [$this->parentA, $this->studentUserA, $this->adminA],
            'student.notifications' => [$this->parentA, $this->teacherA, $this->waliA, $this->adminA],
        ];

        foreach ($matrix as $route => $outsiders) {
            foreach ($outsiders as $outsider) {
                $this->actingAs($outsider)
                    ->get(route($route))
                    ->assertForbidden();
            }
        }
    }

    /** Dan pengguna cabang seberang tidak dapat memakai portal cabang ini. */
    public function test_a_user_of_another_branch_still_sees_only_their_own_branch(): void
    {
        $this->announceToAll();
        $otherSchool = $this->announceToOtherSchool();

        $this->actingAs($this->parentB)
            ->get(route('portal.notifications'))
            ->assertOk()
            ->assertDontSee('Untuk Semua Cabang A')
            ->assertSee($otherSchool->title);
    }

    // ------------------------------------------ keadaan terbaca hanya satu

    /**
     * API dan portal berbagi satu keadaan terbaca, karena keduanya memakai
     * `notification_reads` yang sama — tidak ada tabel atau kolom khusus portal.
     */
    public function test_marking_read_through_the_api_shows_as_read_in_the_portal(): void
    {
        $notification = $this->announceToAll();

        $this->asUser($this->parentA)
            ->patchJson('/api/v1/notifications/'.$notification->id.'/read')
            ->assertOk();

        Livewire::actingAs($this->parentA)
            ->test(NotificationInbox::class)
            ->assertViewHas('unreadCount', 0)
            ->assertSee('Sudah dibaca');
    }

    public function test_marking_read_in_the_portal_shows_as_read_through_the_api(): void
    {
        $notification = $this->announceToAll();

        Livewire::actingAs($this->parentA)
            ->test(NotificationInbox::class)
            ->call('open', $notification->id);

        $this->asUser($this->parentA)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.is_read', true);

        $this->asUser($this->parentA)
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_mark_all_read_in_the_portal_updates_the_api_unread_count(): void
    {
        $this->announceToAll();
        $this->announceToClass($this->classA);

        $this->asUser($this->parentA)
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2);

        Livewire::actingAs($this->parentA)
            ->test(NotificationInbox::class)
            ->call('markAllRead');

        $this->asUser($this->parentA)
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
    }

    /** Tidak ada tabel keadaan terbaca kedua yang dibuat untuk portal. */
    public function test_the_portal_introduced_no_second_read_state_table(): void
    {
        $notification = $this->announceToAll();

        Livewire::actingAs($this->parentA)
            ->test(NotificationInbox::class)
            ->call('open', $notification->id);

        $this->assertDatabaseCount('notification_reads', 1);

        $this->assertEqualsCanonicalizing(
            ['id', 'notification_id', 'user_id', 'read_at'],
            array_keys(NotificationRead::query()->firstOrFail()->getAttributes()),
        );
    }

    // -------------------------------------------------------- idempotensi

    /**
     * Beberapa permintaan penandaan yang berurutan — dua tab yang sama-sama
     * mengklik — tetap menyisakan satu baris, dan waktu baca pertama tidak
     * bergeser (butir 192).
     */
    public function test_repeated_mark_read_requests_leave_one_row_with_the_first_time(): void
    {
        $notification = $this->announceToAll();

        Livewire::actingAs($this->parentA)
            ->test(NotificationInbox::class)
            ->call('open', $notification->id);

        $first = NotificationRead::query()->firstOrFail()->read_at->toDateTimeString();

        $this->travel(2)->minutes();

        // Lewat portal lagi, lewat API, dan lewat tandai-semua: tiga jalur, satu
        // baris.
        Livewire::actingAs($this->parentA)
            ->test(NotificationInbox::class)
            ->call('open', $notification->id)
            ->call('open', $notification->id)
            ->call('markAllRead');

        $this->asUser($this->parentA)
            ->patchJson('/api/v1/notifications/'.$notification->id.'/read')
            ->assertOk();

        $this->asUser($this->parentA)
            ->postJson('/api/v1/notifications/mark-all-read')
            ->assertOk();

        $this->assertDatabaseCount('notification_reads', 1);
        $this->assertSame($first, NotificationRead::query()->firstOrFail()->read_at->toDateTimeString());

        $this->travelBack();
    }

    // ---------------------------------------------------------- batas query

    /**
     * Umpan disaring predikat, bukan perulangan: jumlah query untuk lima
     * notifikasi harus sama dengan untuk lima puluh (butir 197).
     */
    public function test_the_inbox_query_count_does_not_follow_the_number_of_notifications(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->announce(['title' => 'Awal '.$i]);
        }

        // Sekali dulu supaya biaya sekali-jalan tidak ikut terhitung.
        $this->actingAs($this->parentA)->get(route('portal.notifications'))->assertOk();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($this->parentA)->get(route('portal.notifications'))->assertOk();

        $withFive = count(DB::getQueryLog());

        for ($i = 0; $i < 45; $i++) {
            $this->announce(['title' => 'Tambahan '.$i]);
        }

        DB::flushQueryLog();

        $this->actingAs($this->parentA)->get(route('portal.notifications'))->assertOk();

        $withFifty = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertSame($withFive, $withFifty);
    }

    /**
     * Lencana pada tata letak dihitung sekali per halaman, dan tidak sekali per
     * anak: orang tua dengan satu anak dan dengan empat anak membayar sama
     * (butir 211).
     */
    public function test_the_badge_costs_the_same_for_a_parent_with_many_children(): void
    {
        $this->announceToAll();

        $this->actingAs($this->parentA)->get(route('portal.dashboard'))->assertOk();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($this->parentA)->get(route('portal.dashboard'))->assertOk();

        $withOneChild = count(DB::getQueryLog());

        for ($i = 2; $i <= 4; $i++) {
            $this->studentFor($this->schoolA, $this->classA, 'Anak '.$i, null, $this->parentA);
        }

        DB::flushQueryLog();

        $this->actingAs($this->parentA)->get(route('portal.dashboard'))->assertOk();

        $withFourChildren = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertSame($withOneChild, $withFourChildren);
    }

    /**
     * Ringkasan notifikasi dasbor tidak menambah satu query per notifikasi:
     * satu notifikasi dan lima notifikasi berbiaya sama.
     */
    public function test_the_dashboard_notification_items_cause_no_query_per_row(): void
    {
        $this->announce(['title' => 'Satu']);

        $this->asUser($this->teacherA)->getJson('/api/v1/teacher/dashboard')->assertOk();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->asUser($this->teacherA)->getJson('/api/v1/teacher/dashboard')->assertOk();

        $withOne = count(DB::getQueryLog());

        for ($i = 2; $i <= 6; $i++) {
            $this->announce(['title' => 'Pengumuman '.$i]);
        }

        DB::flushQueryLog();

        $this->asUser($this->teacherA)->getJson('/api/v1/teacher/dashboard')->assertOk();

        $withSix = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertSame($withOne, $withSix);
    }

    // ------------------------------------------------- perenderan & tampilan

    /**
     * Judul dan isi pesan ditulis manusia, dan tetap data tampilan yang tidak
     * dipercaya: keduanya harus keluar ter-escape, bukan sebagai markup
     * (butir 210).
     */
    public function test_a_script_like_message_is_rendered_escaped(): void
    {
        $notification = $this->announce([
            'title' => 'Judul <b>tebal</b>',
            'message' => '<script>alert("xss")</script> Halo',
        ]);

        $response = Livewire::actingAs($this->parentA)
            ->test(NotificationInbox::class)
            ->call('open', $notification->id);

        $html = $response->html();

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<b>tebal</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;tebal&lt;/b&gt;', $html);
    }

    /**
     * Baris baru pesan dihormati lewat CSS, bukan dengan menyisipkan markup ke
     * dalam isi yang ditulis pengguna.
     */
    public function test_line_breaks_are_handled_by_css_rather_than_markup(): void
    {
        $notification = $this->announce(['message' => "Baris satu\nBaris dua"]);

        $html = Livewire::actingAs($this->parentA)
            ->test(NotificationInbox::class)
            ->call('open', $notification->id)
            ->html();

        $this->assertStringContainsString('notif__message', $html);
        $this->assertStringNotContainsString('<br', $html);

        $this->actingAs($this->parentA)
            ->get(route('portal.notifications'))
            ->assertOk()
            ->assertSee('white-space: pre-line', false);
    }

    /**
     * Keadaan belum dibaca tidak pernah hanya warna, dan kartunya punya sasaran
     * sentuh serta keadaan bentang yang dapat dibaca teknologi bantu.
     */
    public function test_the_unread_state_is_conveyed_by_more_than_colour(): void
    {
        $notification = $this->announceToAll();

        $unread = Livewire::actingAs($this->parentA)->test(NotificationInbox::class);

        $unread->assertSee('Belum dibaca');
        $unread->assertSee('aria-expanded="false"', false);

        $read = $unread->call('open', $notification->id);

        $read->assertSee('Sudah dibaca');
        $read->assertSee('aria-expanded="true"', false);

        // Dan halaman utuhnya membawa aturan sasaran sentuh serta pengumuman
        // perubahan angka bagi pembaca layar.
        $page = $this->actingAs($this->parentA)->get(route('portal.notifications'))->assertOk();

        $page->assertSee('min-height: 2.75rem', false);
        $page->assertSee('aria-live="polite"', false);
    }

    /**
     * Lencana lonceng sengaja tidak tampil di halaman Notifikasi itu sendiri.
     *
     * Tata letak tidak ikut dirender ulang ketika aksi Livewire berjalan, jadi
     * lencana di sana akan tertinggal basi tepat di halaman tempat pengguna
     * menandai bacaannya. Yang hidup adalah hitungan pada judul halaman
     * (butir 211).
     */
    public function test_the_bell_badge_is_absent_on_the_inbox_page_itself(): void
    {
        $this->announceToAll();
        $this->announceToClass($this->classA);

        // Di halaman lain lencananya ada, dengan angka yang benar.
        $this->actingAs($this->parentA)
            ->get(route('portal.dashboard'))
            ->assertOk()
            ->assertSee('<span class="portal-bell__count">2</span>', false);

        // Di halaman Notifikasi tidak ada lencana sama sekali; jumlahnya
        // disebut judulnya, dan itu yang ikut berubah setelah ditandai.
        $inbox = $this->actingAs($this->parentA)
            ->get(route('portal.notifications'))
            ->assertOk();

        $inbox->assertDontSee('<span class="portal-bell__count">', false);
        $inbox->assertDontSee('<span class="portal-nav__badge">', false);
        $inbox->assertSee('2 belum dibaca');

        Livewire::actingAs($this->parentA)
            ->test(NotificationInbox::class)
            ->call('markAllRead')
            ->assertSee('Semua notifikasi sudah dibaca');
    }

    /**
     * Permukaan API 4.10 kini lengkap.
     *
     * Batch 8.2 menjaga angka ini di 40 dan menuntut wa-links **tidak** ada,
     * karena saat itu memang belum dikerjakan. Batch 8.4 mengerjakannya, jadi
     * yang dijaga sekarang kebalikannya: tepat satu endpoint tambahan, dan
     * endpoint itu memang wa-links — bukan sesuatu yang lain ikut terbawa.
     */
    public function test_the_api_surface_gained_exactly_the_wa_links_endpoint(): void
    {
        $uris = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
            ->map(fn ($route) => $route->uri());

        $this->assertSame(41, $uris->count());

        $this->assertSame(
            ['api/v1/notifications/{id}/wa-links'],
            $uris->filter(fn (string $uri) => str_contains($uri, 'wa-links'))->values()->all(),
        );
    }
}
