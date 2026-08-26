<?php

namespace Tests\Feature\Notification;

use App\Enums\NotificationTargetType;
use App\Enums\RoleName;
use App\Enums\WhatsAppUnavailableReason;
use App\Filament\Pages\NotifikasiSaya;
use App\Filament\Resources\NotificationResource;
use App\Filament\Resources\NotificationResource\Pages\NotificationWaLinks;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\NotificationWaLinkService;
use App\Support\WhatsAppLink;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Portal\Concerns\BuildsNotificationFixture;
use Tests\TestCase;

/**
 * NOTIF-02 — "untuk setiap notifikasi saya mendapatkan daftar link wa.me siap
 * kirim per penerima"; API 4.10 — GET /notifications/{id}/wa-links.
 *
 * Tiga hal yang paling perlu dijaga di sini.
 *
 * Pertama, daftarnya memuat nomor telepon seluruh penerima — data pribadi orang
 * yang bahkan tidak sedang memakai aplikasi. Kewenangan karena itu diperiksa
 * sebelum satu pun nomor dibaca, dan cabang lain berakhir 404 tanpa pernah
 * mengonfirmasi bahwa notifikasinya ada.
 *
 * Kedua, penerimanya tidak boleh dihitung ulang di sini: yang dipakai resolver
 * kanonis yang sama dengan kotak masuk dan lencana, sehingga daftar kirim tidak
 * dapat berbeda dari daftar yang benar-benar menerima.
 *
 * Ketiga, penerima yang tidak punya nomor tidak dibuang diam-diam. Daftar yang
 * memendekkan dirinya sendiri membuat Admin mengira semua orang terjangkau,
 * padahal justru yang hilang itulah yang perlu dihubungi dengan cara lain.
 */
class NotificationWaLinkTest extends TestCase
{
    use BuildsNotificationFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->buildNotificationFixture();
    }

    protected function waLinksUrl(Notification $notification, array $query = []): string
    {
        $url = '/api/v1/notifications/'.$notification->getKey().'/wa-links';

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    // ---------------------------------------------------------------- 20. Auth

    public function test_a_guest_cannot_read_the_wa_link_list(): void
    {
        $notification = $this->announceToAll();

        $this->getJson($this->waLinksUrl($notification))->assertUnauthorized();
    }

    public function test_the_school_admin_reads_the_wa_links_of_their_own_branch(): void
    {
        $notification = $this->announceToAll();

        $this->asUser($this->adminA)
            ->getJson($this->waLinksUrl($notification))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.notification.id', $notification->getKey())
            ->assertJsonPath('data.notification.title', $notification->title);
    }

    public function test_a_school_admin_of_another_branch_is_answered_with_a_not_found(): void
    {
        // 404, bukan 403: 403 mengonfirmasi bahwa id itu ada di suatu cabang,
        // dan bagi endpoint yang memuat nomor telepon itu sendiri sudah bocor.
        $notification = $this->announceToAll();

        $this->asUser($this->adminB)
            ->getJson($this->waLinksUrl($notification))
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_the_cross_branch_answer_never_names_a_recipient(): void
    {
        $notification = $this->announceToAll();

        $response = $this->asUser($this->adminB)->getJson($this->waLinksUrl($notification));

        $response->assertNotFound();
        $response->assertDontSee($this->parentA->name);
        $response->assertDontSee((string) $this->parentA->phone);
        $response->assertDontSee($notification->title);
    }

    public function test_the_super_admin_reads_one_notification_and_only_its_own_branch(): void
    {
        // Melewati SchoolScope dan Gate::before bukan alasan untuk menggabungkan
        // cabang: yang diselesaikan tetap satu notifikasi, dan penerimanya tetap
        // penerima notifikasi itu.
        $notification = $this->announceToAll();
        $this->announceToOtherSchool();

        $response = $this->asUser($this->superAdmin)
            ->getJson($this->waLinksUrl($notification))
            ->assertOk();

        $names = array_column($response->json('data.recipients'), 'name');

        $this->assertContains($this->parentA->name, $names);
        $this->assertNotContains($this->parentB->name, $names);
        $this->assertNotContains($this->adminB->name, $names);
    }

    /**
     * Peran yang NOTIF-02 tidak petakan.
     *
     * Kepala Sekolah ada di daftar ini meskipun ia **boleh** membuat pengumuman
     * (NOTIF-01, butir 201). Kedua kewenangan itu berbeda dan dipagari terpisah:
     * NOTIF-02 memetakan daftar wa.me hanya kepada Admin Sekolah, dan API 4.1
     * mendefinisikan Auth Level "Admin" sebagai SCHOOL_ADMIN/SUPER_ADMIN
     * (butir 223).
     *
     * @return array<string, array<int, string>>
     */
    public static function deniedRoles(): array
    {
        return [
            'kepala sekolah' => ['kepalaA'],
            'bendahara' => ['bendaharaA'],
            'guru' => ['teacherA'],
            'wali kelas' => ['waliA'],
            'orang tua' => ['parentA'],
            'siswa' => ['studentUserA'],
        ];
    }

    public function test_the_kepala_sekolah_is_refused_the_wa_links_of_their_own_branch(): void
    {
        // Notifikasi cabangnya sendiri, sudah terkirim, dan dibuat olehnya —
        // sehingga yang menolak benar-benar aturan NOTIF-02, bukan cabang,
        // bukan keadaan draf, dan bukan kepemilikan.
        $notification = $this->announce(['title' => 'Dibuat Kepala'], $this->kepalaA);

        $this->asUser($this->kepalaA)
            ->getJson($this->waLinksUrl($notification))
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_the_kepala_sekolah_may_still_create_and_send_announcements(): void
    {
        // NOTIF-01 tidak ikut menyempit. Kalau baris ini gagal, koreksi
        // kewenangan NOTIF-02 sudah terlalu jauh.
        $this->asUser($this->kepalaA)->postJson('/api/v1/notifications', [
            'title' => 'Rapat Guru',
            'message' => 'Rapat guru pekan depan.',
            'type' => 'ANNOUNCEMENT',
            'target_type' => 'ALL',
            'action' => 'send',
        ])->assertStatus(201);

        $this->assertDatabaseHas('notifications', [
            'title' => 'Rapat Guru',
            'sender_id' => $this->kepalaA->getKey(),
            'is_draft' => false,
        ]);
    }

    public function test_the_refused_kepala_sekolah_reads_no_phone_data_and_changes_nothing(): void
    {
        $notification = $this->announceToAll();
        $before = $notification->only(['title', 'message', 'wa_template', 'is_draft', 'sent_at']);

        $response = $this->asUser($this->kepalaA)->getJson($this->waLinksUrl($notification));

        $response->assertForbidden();
        $response->assertDontSee((string) $this->parentA->phone);
        $response->assertDontSee((string) $this->childA->parent_phone);
        $response->assertDontSee('https://wa.me/');
        $response->assertDontSee('recipients');

        $this->assertDatabaseCount('notification_reads', 0);
        $this->assertEquals($before, $notification->refresh()->only(array_keys($before)));
        $this->assertSame($this->parentA->phone, $this->parentA->refresh()->phone);
    }

    public function test_only_the_school_admin_and_the_super_admin_hold_the_wa_link_ability(): void
    {
        // Matriks NOTIF-02 dibaca sekaligus, supaya perubahan pada salah satu
        // peran tidak lolos hanya karena testnya terpisah-pisah.
        $notification = $this->announceToAll();

        foreach ([$this->adminA, $this->superAdmin] as $allowed) {
            $this->assertTrue(
                $allowed->can('waLinks', $notification),
                $allowed->name.' seharusnya boleh membuka daftar link WhatsApp.',
            );
        }

        foreach ([$this->kepalaA, $this->bendaharaA, $this->teacherA, $this->waliA, $this->parentA, $this->studentUserA, $this->adminB] as $denied) {
            $this->assertFalse(
                $denied->can('waLinks', $notification),
                $denied->name.' seharusnya ditolak.',
            );
        }
    }

    #[DataProvider('deniedRoles')]
    public function test_roles_without_announcement_management_cannot_read_the_wa_links(string $who): void
    {
        $notification = $this->announceToAll();

        $this->asUser($this->{$who})
            ->getJson($this->waLinksUrl($notification))
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    #[DataProvider('deniedRoles')]
    public function test_a_denied_role_is_refused_before_any_phone_number_is_read(string $who): void
    {
        // Penerima adalah dirinya sendiri pada target ALL, jadi kalaupun ada
        // kebocoran ia akan terlihat sebagai nomornya sendiri di respons.
        $notification = $this->announceToAll();

        $response = $this->asUser($this->{$who})->getJson($this->waLinksUrl($notification));

        $response->assertForbidden();
        $response->assertDontSee((string) $this->parentA->phone);
        $response->assertDontSee('https://wa.me/');
        $response->assertDontSee('recipients');
    }

    public function test_a_draft_has_no_ready_to_send_links(): void
    {
        // 422, bukan 404: notifikasinya ada dan Admin memang boleh melihatnya —
        // yang belum ada adalah keadaan "siap kirim" (butir 224).
        $draft = $this->draftAnnouncement();

        $this->asUser($this->adminA)
            ->getJson($this->waLinksUrl($draft))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_a_draft_answer_carries_no_recipient_data(): void
    {
        $draft = $this->draftAnnouncement();

        $response = $this->asUser($this->adminA)->getJson($this->waLinksUrl($draft));

        $response->assertStatus(422);
        $response->assertDontSee('https://wa.me/');
        $response->assertDontSee((string) $this->parentA->phone);
    }

    public function test_an_unknown_notification_is_a_not_found(): void
    {
        $this->asUser($this->adminA)
            ->getJson('/api/v1/notifications/999999/wa-links')
            ->assertNotFound();
    }

    // ---------------------------------------------------------- 21. Penerima

    /**
     * @return array<int, string>
     */
    protected function recipientNames(Notification $notification, array $query = []): array
    {
        $response = $this->asUser($this->adminA)
            ->getJson($this->waLinksUrl($notification, $query))
            ->assertOk();

        return array_column($response->json('data.recipients'), 'name');
    }

    public function test_target_all_covers_exactly_the_active_users_of_the_branch(): void
    {
        $names = $this->recipientNames($this->announceToAll());

        sort($names);

        $expected = [
            $this->adminA->name,
            $this->bendaharaA->name,
            $this->kepalaA->name,
            $this->otherParentA->name,
            $this->parentA->name,
            $this->studentUserA->name,
            $this->teacherA->name,
            $this->waliA->name,
        ];

        sort($expected);

        $this->assertSame($expected, $names);
    }

    public function test_target_class_reaches_the_parents_of_that_class_only(): void
    {
        // NOTIF-01 poin 3 — "Target 'per kelas' hanya untuk orang tua siswa
        // kelas tersebut". Anak otherParentA duduk di kelas lain.
        $this->assertSame([$this->parentA->name], $this->recipientNames($this->announceToClass()));
    }

    public function test_target_class_excludes_the_student_the_teacher_and_the_homeroom_teacher(): void
    {
        $names = $this->recipientNames($this->announceToClass());

        $this->assertNotContains($this->studentUserA->name, $names);
        $this->assertNotContains($this->teacherA->name, $names);
        $this->assertNotContains($this->waliA->name, $names);
        $this->assertNotContains($this->adminA->name, $names);
    }

    public function test_target_individual_reaches_exactly_the_target_user(): void
    {
        $this->assertSame(
            [$this->parentA->name],
            $this->recipientNames($this->announceTo($this->parentA)),
        );
    }

    public function test_a_parent_of_two_children_in_the_class_appears_once(): void
    {
        $this->studentFor($this->schoolA, $this->classA, 'Adik Ahmad', null, $this->parentA);

        $this->assertSame([$this->parentA->name], $this->recipientNames($this->announceToClass()));
    }

    public function test_an_inactive_user_is_not_a_recipient(): void
    {
        // NOTIF-01 poin 2 — target "semua" menjangkau pengguna **aktif**.
        $this->teacherA->update(['is_active' => false]);

        $this->assertNotContains($this->teacherA->name, $this->recipientNames($this->announceToAll()));
    }

    public function test_users_of_another_branch_are_never_recipients(): void
    {
        $names = $this->recipientNames($this->announceToAll());

        $this->assertNotContains($this->adminB->name, $names);
        $this->assertNotContains($this->parentB->name, $names);
        $this->assertNotContains($this->studentUserB->name, $names);
    }

    public function test_a_same_branch_user_who_is_not_targeted_is_absent(): void
    {
        $names = $this->recipientNames($this->announceTo($this->parentA));

        $this->assertNotContains($this->adminA->name, $names);
        $this->assertNotContains($this->teacherA->name, $names);
    }

    public function test_the_platform_super_admin_is_not_a_recipient_of_any_branch(): void
    {
        $this->assertNotContains($this->superAdmin->name, $this->recipientNames($this->announceToAll()));
    }

    // ------------------------------------------------------------- 22. Nomor

    /**
     * Nomor yang benar-benar dipakai untuk satu penerima tunggal.
     *
     * @return array<string, mixed>
     */
    protected function rowForParent(?Notification $notification = null): array
    {
        $notification ??= $this->announceTo($this->parentA);

        $response = $this->asUser($this->adminA)
            ->getJson($this->waLinksUrl($notification))
            ->assertOk();

        return $response->json('data.recipients.0');
    }

    /**
     * @return array<string, array<int, string|null>>
     */
    public static function phoneProvider(): array
    {
        return [
            'nol di depan' => ['081234567890', '6281234567890'],
            'plus enam dua' => ['+6281234567890', '6281234567890'],
            'dengan spasi dan tanda hubung' => ['+62 812-3456-7890', '6281234567890'],
            'dengan tanda kurung' => ['(0812) 3456 7890', '6281234567890'],
            'sudah enam dua' => ['6281234567890', '6281234567890'],
            'tanpa awalan sama sekali' => ['81234567890', '6281234567890'],
        ];
    }

    #[DataProvider('phoneProvider')]
    public function test_a_usable_phone_number_becomes_a_wa_me_link(string $stored, string $normalised): void
    {
        $this->parentA->update(['phone' => $stored]);

        $row = $this->rowForParent();

        $this->assertTrue($row['wa_available']);
        $this->assertSame($normalised, $row['normalized_phone']);
        $this->assertNull($row['reason']);
        $this->assertStringStartsWith('https://wa.me/'.$normalised.'?text=', $row['wa_url']);
    }

    /**
     * @return array<string, array<int, string|null>>
     */
    public static function unusablePhoneProvider(): array
    {
        return [
            'tidak diisi' => [null, WhatsAppUnavailableReason::MissingPhone->value],
            'kosong' => ['', WhatsAppUnavailableReason::MissingPhone->value],
            'hanya spasi' => ['   ', WhatsAppUnavailableReason::MissingPhone->value],
            'tanpa angka' => ['tidak punya', WhatsAppUnavailableReason::InvalidPhone->value],
            'terlalu pendek' => ['0812', WhatsAppUnavailableReason::InvalidPhone->value],
            'kode negara lain' => ['+6591234567', WhatsAppUnavailableReason::InvalidPhone->value],
            'kode negara jauh' => ['+12025550123', WhatsAppUnavailableReason::InvalidPhone->value],
        ];
    }

    #[DataProvider('unusablePhoneProvider')]
    public function test_a_recipient_without_a_usable_number_is_listed_with_a_reason(?string $stored, string $reason): void
    {
        $this->parentA->update(['phone' => $stored]);
        // Tanpa ini nomor cadangan dari data anak justru menutupi kasus yang
        // sedang diuji — yang benar, tetapi bukan yang diuji di sini.
        $this->childA->update(['parent_phone' => null]);

        $row = $this->rowForParent();

        // Tetap ada di daftar: yang tidak terjangkau justru yang perlu dilihat.
        $this->assertSame($this->parentA->name, $row['name']);
        $this->assertFalse($row['wa_available']);
        $this->assertNull($row['wa_url']);
        $this->assertNull($row['normalized_phone']);
        $this->assertSame($reason, $row['reason']);
        $this->assertNotEmpty($row['reason_label']);
    }

    public function test_a_foreign_number_is_reported_unusable_rather_than_given_an_indonesian_prefix(): void
    {
        // Menambahkan 62 di depan nomor asing tidak menghasilkan nomor orang itu
        // melainkan nomor Indonesia milik orang lain, yang akan menerima
        // pengumuman sekolah tanpa pernah ada hubungannya (butir 222).
        $this->parentA->update(['phone' => '+6591234567']);

        $row = $this->rowForParent();

        $this->assertFalse($row['wa_available']);
        $this->assertStringNotContainsString('626591234567', (string) json_encode($row));
    }

    public function test_the_parent_phone_of_the_child_fills_in_when_the_account_has_none(): void
    {
        $this->parentA->update(['phone' => null]);
        $this->childA->update(['parent_phone' => '081298765432']);

        $row = $this->rowForParent();

        $this->assertTrue($row['wa_available']);
        $this->assertSame('6281298765432', $row['normalized_phone']);
    }

    public function test_the_account_phone_wins_over_the_child_record(): void
    {
        // Identitas penerima adalah akunnya; data anak hanya cadangan.
        $this->parentA->update(['phone' => '081211112222']);
        $this->childA->update(['parent_phone' => '081299998888']);

        $this->assertSame('6281211112222', $this->rowForParent()['normalized_phone']);
    }

    public function test_two_children_carrying_the_same_number_are_not_ambiguous(): void
    {
        $this->parentA->update(['phone' => null]);
        $this->childA->update(['parent_phone' => '081298765432']);
        $this->studentFor($this->schoolA, $this->classA, 'Adik Ahmad', null, $this->parentA)
            ->update(['parent_phone' => '081298765432']);

        $row = $this->rowForParent();

        $this->assertTrue($row['wa_available']);
        $this->assertSame('6281298765432', $row['normalized_phone']);
    }

    public function test_two_children_carrying_different_numbers_are_reported_ambiguous(): void
    {
        // Tidak ada dasar untuk memilih salah satunya, dan menebak berarti
        // mengirim ke nomor yang mungkin bukan miliknya (butir 225).
        $this->parentA->update(['phone' => null]);
        $this->childA->update(['parent_phone' => '081211112222']);
        $this->studentFor($this->schoolA, $this->classA, 'Adik Ahmad', null, $this->parentA)
            ->update(['parent_phone' => '081299998888']);

        $row = $this->rowForParent();

        $this->assertFalse($row['wa_available']);
        $this->assertSame(WhatsAppUnavailableReason::AmbiguousPhone->value, $row['reason']);
        $this->assertNull($row['phone']);
        $this->assertNull($row['wa_url']);
    }

    public function test_a_class_notification_only_borrows_numbers_from_children_of_that_class(): void
    {
        // Anak di kelas lain tidak membuat nomornya relevan bagi pengumuman ini.
        $this->parentA->update(['phone' => null]);
        $this->childA->update(['parent_phone' => null]);

        $this->studentFor($this->schoolA, $this->otherClassA, 'Adik Kelas Lain', null, $this->parentA)
            ->update(['parent_phone' => '081277776666']);

        $response = $this->asUser($this->adminA)
            ->getJson($this->waLinksUrl($this->announceToClass()))
            ->assertOk();

        $row = $response->json('data.recipients.0');

        $this->assertSame($this->parentA->name, $row['name']);
        $this->assertFalse($row['wa_available']);
        $this->assertSame(WhatsAppUnavailableReason::MissingPhone->value, $row['reason']);
    }

    public function test_a_student_own_number_is_never_used_as_their_parents(): void
    {
        $this->parentA->update(['phone' => null]);
        $this->childA->update(['parent_phone' => null]);
        $this->studentUserA->update(['phone' => '081255554444']);

        $row = $this->rowForParent();

        $this->assertFalse($row['wa_available']);
        $this->assertSame(WhatsAppUnavailableReason::MissingPhone->value, $row['reason']);
    }

    public function test_the_summary_counts_reachable_and_unreachable_recipients(): void
    {
        $this->parentA->update(['phone' => null]);
        $this->childA->update(['parent_phone' => null]);
        $this->teacherA->update(['phone' => '+6591234567']);

        $response = $this->asUser($this->adminA)
            ->getJson($this->waLinksUrl($this->announceToAll()))
            ->assertOk();

        $response->assertJsonPath('data.summary.recipient_count', 8);
        $response->assertJsonPath('data.summary.available_count', 6);
        $response->assertJsonPath('data.summary.unavailable_count', 2);
    }

    // ------------------------------------------------------------ 15. Filter

    public function test_the_list_can_be_searched_by_recipient_name(): void
    {
        $names = $this->recipientNames($this->announceToAll(), ['search' => 'Bapak Ahmad']);

        $this->assertSame([$this->parentA->name], $names);
    }

    public function test_the_search_ignores_letter_case(): void
    {
        $this->assertSame(
            [$this->parentA->name],
            $this->recipientNames($this->announceToAll(), ['search' => 'bapak ahmad']),
        );
    }

    public function test_the_list_can_be_searched_by_phone_number(): void
    {
        $this->parentA->update(['phone' => '081233334444']);

        $this->assertSame(
            [$this->parentA->name],
            $this->recipientNames($this->announceToAll(), ['search' => '81233334444']),
        );
    }

    public function test_the_list_can_be_filtered_to_reachable_recipients(): void
    {
        $this->parentA->update(['phone' => null]);
        $this->childA->update(['parent_phone' => null]);

        $names = $this->recipientNames($this->announceToAll(), ['availability' => 'available']);

        $this->assertNotContains($this->parentA->name, $names);
        $this->assertCount(7, $names);
    }

    public function test_the_list_can_be_filtered_to_unreachable_recipients(): void
    {
        $this->parentA->update(['phone' => null]);
        $this->childA->update(['parent_phone' => null]);

        $this->assertSame(
            [$this->parentA->name],
            $this->recipientNames($this->announceToAll(), ['availability' => 'unavailable']),
        );
    }

    public function test_the_summary_still_describes_every_recipient_while_filtered(): void
    {
        // Kalau ringkasannya ikut menyusut, menyaring ke "tidak terjangkau" akan
        // memberitahu Admin bahwa tidak ada seorang pun yang terjangkau
        // (butir 226).
        $this->parentA->update(['phone' => null]);
        $this->childA->update(['parent_phone' => null]);

        $this->asUser($this->adminA)
            ->getJson($this->waLinksUrl($this->announceToAll(), ['availability' => 'unavailable']))
            ->assertOk()
            ->assertJsonPath('data.summary.recipient_count', 8)
            ->assertJsonPath('data.summary.available_count', 7)
            ->assertJsonPath('data.summary.unavailable_count', 1)
            ->assertJsonCount(1, 'data.recipients');
    }

    public function test_an_unknown_availability_value_is_rejected(): void
    {
        $this->asUser($this->adminA)
            ->getJson($this->waLinksUrl($this->announceToAll(), ['availability' => 'semua-saja']))
            ->assertStatus(422);
    }

    // ---------------------------------------------------------- 23. Encoding

    /**
     * @return array<string, array<int, string>>
     */
    public static function messageProvider(): array
    {
        return [
            'biasa' => ['Halo Orang Tua'],
            'baris baru' => ["Halo Orang Tua,\nRapat pukul 09.00."],
            'ampersand' => ['Nilai & rapor sudah terbit'],
            'tanda tanya' => ['Sudah hadir?'],
            'sama dengan' => ['Total = Rp150.000'],
            'persen' => ['Kehadiran 95% bulan ini'],
            'garis miring' => ['Bapak/Ibu Wali Murid'],
            'kutip ganda' => ['Tema: "Merdeka Belajar"'],
            'kutip tunggal' => ["Ananda 'Ahmad' naik kelas"],
            'emoji' => ['Selamat! 🎉📚'],
            'aksen' => ['Café Sekolah — pukul 09.00'],
            'gabungan' => ["Halo Bapak/Ibu,\nNilai & absensi 95% — \"selesai\"? Total = Rp150.000 🎉"],
        ];
    }

    #[DataProvider('messageProvider')]
    public function test_the_message_survives_the_url_round_trip_exactly(string $message): void
    {
        $notification = $this->announce([
            'message' => $message,
            'target_type' => NotificationTargetType::Individual->value,
            'target_id' => $this->parentA->getKey(),
        ]);

        $url = $this->rowForParent($notification)['wa_url'];

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame($message, $query['text']);
    }

    public function test_the_url_keeps_the_shape_the_blueprint_names(): void
    {
        // NOTIF-02 poin 1 — "wa.me/62[nomorHP]?text=[pesan_ter-encode]".
        $this->parentA->update(['phone' => '081234567890']);

        $notification = $this->announce([
            'message' => 'Halo Orang Tua',
            'target_type' => NotificationTargetType::Individual->value,
            'target_id' => $this->parentA->getKey(),
        ]);

        $this->assertSame(
            'https://wa.me/6281234567890?text='.rawurlencode('Halo Orang Tua'),
            $this->rowForParent($notification)['wa_url'],
        );
    }

    public function test_the_message_is_not_encoded_twice(): void
    {
        $notification = $this->announce([
            'message' => 'Nilai & rapor',
            'target_type' => NotificationTargetType::Individual->value,
            'target_id' => $this->parentA->getKey(),
        ]);

        $url = $this->rowForParent($notification)['wa_url'];

        $this->assertStringContainsString('text=Nilai%20%26%20rapor', $url);
        $this->assertStringNotContainsString('%2526', $url);
    }

    public function test_the_wa_template_is_used_when_it_carries_the_intended_text(): void
    {
        $notification = $this->announceTo($this->parentA);
        $notification->forceFill(['wa_template' => 'Teks khusus WhatsApp'])->save();

        $url = $this->rowForParent($notification->refresh())['wa_url'];

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('Teks khusus WhatsApp', $query['text']);
    }

    public function test_the_notification_message_is_used_when_no_template_is_stored(): void
    {
        $notification = $this->announce([
            'message' => 'Isi pengumuman apa adanya',
            'target_type' => NotificationTargetType::Individual->value,
            'target_id' => $this->parentA->getKey(),
        ]);

        $this->assertNull($notification->wa_template);

        $url = $this->rowForParent($notification)['wa_url'];

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('Isi pengumuman apa adanya', $query['text']);
    }

    // ------------------------------------------------------------ 11. Privasi

    public function test_a_recipient_row_carries_only_the_agreed_fields(): void
    {
        $row = $this->rowForParent();

        $this->assertSame(
            ['name', 'phone', 'normalized_phone', 'wa_available', 'wa_url', 'reason', 'reason_label'],
            array_keys($row),
        );
    }

    public function test_the_response_never_carries_account_or_targeting_internals(): void
    {
        $response = $this->asUser($this->adminA)
            ->getJson($this->waLinksUrl($this->announceToAll()))
            ->assertOk();

        $response->assertDontSee($this->parentA->email);
        $response->assertDontSee($this->parentA->password, false);

        foreach (['school_id', 'target_id', 'target_type', 'is_draft', 'sender_id', 'password', 'read_at'] as $leak) {
            $response->assertDontSee($leak);
        }
    }

    public function test_the_response_carries_only_the_id_and_title_of_the_notification(): void
    {
        $notification = $this->announceToAll();

        $response = $this->asUser($this->adminA)
            ->getJson($this->waLinksUrl($notification))
            ->assertOk();

        $this->assertSame(['id', 'title'], array_keys($response->json('data.notification')));
        $this->assertSame(
            ['recipient_count', 'available_count', 'unavailable_count'],
            array_keys($response->json('data.summary')),
        );
    }

    public function test_the_list_never_names_children_of_other_recipients(): void
    {
        $this->parentA->update(['phone' => null]);
        // Nama anak sengaja dibuat berbeda dari nama akun siswanya, supaya
        // kebocoran nama anak tidak tersamar oleh penerima yang memang bernama
        // sama.
        $this->childA->update(['parent_phone' => '081298765432', 'full_name' => 'Anak Yang Tak Boleh Disebut']);

        $response = $this->asUser($this->adminA)
            ->getJson($this->waLinksUrl($this->announceToAll()))
            ->assertOk();

        $response->assertDontSee($this->childA->full_name);
        $response->assertDontSee($this->childA->nis);
    }

    // ---------------------------------------------------- 18. Tanpa efek samping

    public function test_reading_the_list_marks_nothing_as_read(): void
    {
        $notification = $this->announceToAll();

        $this->asUser($this->adminA)->getJson($this->waLinksUrl($notification))->assertOk();

        $this->assertDatabaseCount('notification_reads', 0);
    }

    public function test_reading_the_list_changes_nothing_about_the_notification(): void
    {
        $notification = $this->announceToAll();
        $before = $notification->only(['title', 'message', 'wa_template', 'is_draft', 'sent_at', 'target_type', 'target_id']);

        $this->asUser($this->adminA)->getJson($this->waLinksUrl($notification))->assertOk();

        $this->assertEquals($before, $notification->refresh()->only(array_keys($before)));
    }

    public function test_reading_the_list_never_writes_back_a_normalised_number(): void
    {
        // Nomor dinormalkan untuk membuat tautannya, bukan untuk memperbaiki
        // data yang disimpan sekolah.
        $this->parentA->update(['phone' => '+62 812-3456-7890']);
        $this->childA->update(['parent_phone' => '0812 3456 7890']);

        $this->asUser($this->adminA)->getJson($this->waLinksUrl($this->announceToAll()))->assertOk();

        $this->assertSame('+62 812-3456-7890', $this->parentA->refresh()->phone);
        $this->assertSame('0812 3456 7890', $this->childA->refresh()->parent_phone);
    }

    // ------------------------------------------------------------ 24. Performa

    /**
     * @param  callable(): mixed  $work
     */
    protected function queryCountOf(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $work();

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $count;
    }

    protected function fillBranchWithUsers(int $count): void
    {
        User::factory()
            ->count($count)
            ->forSchool($this->schoolA)
            ->withRole(RoleName::OrangTua)
            ->create();
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_recipients(): void
    {
        $service = app(NotificationWaLinkService::class);
        $notification = $this->announceToAll();

        $this->fillBranchWithUsers(5);
        $small = $this->queryCountOf(fn () => $service->linksFor($notification));

        $this->fillBranchWithUsers(45);
        $large = $this->queryCountOf(fn () => $service->linksFor($notification));

        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(2, $large);
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_students_in_a_class(): void
    {
        $service = app(NotificationWaLinkService::class);
        $notification = $this->announceToClass();

        // Kedua sisi perbandingan harus sama-sama membutuhkan nomor cadangan.
        // Kalau yang pertama tidak, selisihnya hanya menunjukkan munculnya satu
        // jenis pekerjaan baru — bukan biaya yang tumbuh bersama jumlah siswa,
        // yang justru sedang diuji.
        $this->parentA->update(['phone' => null]);
        $this->childA->update(['parent_phone' => '081298765432']);

        $few = $this->queryCountOf(fn () => $service->linksFor($notification));

        for ($i = 0; $i < 20; $i++) {
            $parent = $this->userFor($this->schoolA, RoleName::OrangTua, ['phone' => null]);
            $this->studentFor($this->schoolA, $this->classA, 'Siswa '.$i, null, $parent)
                ->update(['parent_phone' => '08123456'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)]);
        }

        $many = $this->queryCountOf(fn () => $service->linksFor($notification));

        $this->assertSame($few, $many);
    }

    public function test_a_parent_with_several_children_costs_no_extra_query(): void
    {
        $service = app(NotificationWaLinkService::class);
        $notification = $this->announceToClass();

        $this->parentA->update(['phone' => null]);
        $this->childA->update(['parent_phone' => '081298765432']);

        $one = $this->queryCountOf(fn () => $service->linksFor($notification));

        for ($i = 0; $i < 5; $i++) {
            $this->studentFor($this->schoolA, $this->classA, 'Adik '.$i, null, $this->parentA)
                ->update(['parent_phone' => '081298765432']);
        }

        $this->assertSame($one, $this->queryCountOf(fn () => $service->linksFor($notification)));
    }

    public function test_no_fallback_query_runs_when_every_recipient_has_their_own_number(): void
    {
        $service = app(NotificationWaLinkService::class);
        $notification = $this->announceToAll();

        $this->assertSame(1, $this->queryCountOf(fn () => $service->linksFor($notification)));
    }

    // ------------------------------------------------------- 16. Panel Filament

    public function test_the_admin_opens_the_wa_link_page_of_a_sent_announcement(): void
    {
        $notification = $this->announceToAll();

        $this->actingAs($this->adminA)
            ->get(NotificationResource::getUrl('wa-links', ['record' => $notification]))
            ->assertOk();
    }

    public function test_the_page_lists_recipients_with_their_links_and_counts(): void
    {
        $this->parentA->update(['phone' => '081234567890']);

        Livewire::actingAs($this->adminA)
            ->test(NotificationWaLinks::class, ['record' => $this->announceToAll()->getKey()])
            ->assertSee($this->parentA->name)
            ->assertSee('6281234567890')
            ->assertSee('Buka WA')
            ->assertSee('Salin link')
            ->assertSee('penerima');
    }

    public function test_the_page_marks_unreachable_recipients_with_their_reason(): void
    {
        $this->parentA->update(['phone' => null]);
        $this->childA->update(['parent_phone' => null]);

        Livewire::actingAs($this->adminA)
            ->test(NotificationWaLinks::class, ['record' => $this->announceToAll()->getKey()])
            ->assertSee($this->parentA->name)
            ->assertSee(WhatsAppUnavailableReason::MissingPhone->label());
    }

    public function test_the_page_search_narrows_the_list(): void
    {
        Livewire::actingAs($this->adminA)
            ->test(NotificationWaLinks::class, ['record' => $this->announceToAll()->getKey()])
            ->assertSee($this->teacherA->name)
            ->set('search', 'Bapak Ahmad')
            ->assertSee($this->parentA->name)
            ->assertDontSee($this->teacherA->name);
    }

    public function test_the_page_availability_filter_narrows_the_list(): void
    {
        $this->parentA->update(['phone' => null]);
        $this->childA->update(['parent_phone' => null]);

        Livewire::actingAs($this->adminA)
            ->test(NotificationWaLinks::class, ['record' => $this->announceToAll()->getKey()])
            ->set('availability', 'unavailable')
            ->assertSee($this->parentA->name)
            ->assertDontSee($this->teacherA->name);
    }

    public function test_the_page_opens_a_draft_without_reading_any_phone_number(): void
    {
        $draft = $this->draftAnnouncement();

        Livewire::actingAs($this->adminA)
            ->test(NotificationWaLinks::class, ['record' => $draft->getKey()])
            ->assertSee('masih draf')
            ->assertDontSee('https://wa.me/')
            ->assertDontSee((string) $this->parentA->phone);
    }

    public function test_the_action_appears_only_on_sent_announcements(): void
    {
        $sent = $this->announceToAll();
        $draft = $this->draftAnnouncement();

        Livewire::actingAs($this->adminA)
            ->test(NotificationResource\Pages\ListNotifications::class)
            ->assertTableActionVisible('waLinks', $sent)
            ->assertTableActionHidden('waLinks', $draft);
    }

    public function test_a_denied_role_cannot_open_the_wa_link_page(): void
    {
        $notification = $this->announceToAll();

        $this->actingAs($this->bendaharaA)
            ->get(NotificationResource::getUrl('wa-links', ['record' => $notification]))
            ->assertForbidden();
    }

    public function test_an_admin_of_another_branch_cannot_open_the_page(): void
    {
        $notification = $this->announceToAll();

        $this->actingAs($this->adminB)
            ->get(NotificationResource::getUrl('wa-links', ['record' => $notification]))
            ->assertNotFound();
    }

    public function test_the_kepala_sekolah_cannot_open_the_wa_link_page(): void
    {
        $this->actingAs($this->kepalaA)
            ->get(NotificationResource::getUrl('wa-links', ['record' => $this->announceToAll()]))
            ->assertForbidden();
    }

    public function test_the_wa_link_action_is_hidden_from_the_kepala_sekolah(): void
    {
        // Kepala tetap melihat Pengumuman dan tetap dapat mengirim drafnya;
        // yang hilang hanya jalan menuju daftar nomor penerima.
        $sent = $this->announceToAll();

        Livewire::actingAs($this->kepalaA)
            ->test(NotificationResource\Pages\ListNotifications::class)
            ->assertTableActionHidden('waLinks', $sent)
            ->assertSee($sent->title);
    }

    public function test_the_wa_link_action_stays_visible_for_the_school_admin(): void
    {
        $sent = $this->announceToAll();

        Livewire::actingAs($this->adminA)
            ->test(NotificationResource\Pages\ListNotifications::class)
            ->assertTableActionVisible('waLinks', $sent);
    }

    public function test_the_wa_link_page_belongs_to_the_management_side_only(): void
    {
        // Kotak masuk penerima memperlihatkan apa yang ditujukan kepada seseorang;
        // halaman ini memperlihatkan kepada siapa saja sesuatu ditujukan, lengkap
        // dengan nomor mereka. Dua pertanyaan berbeda, dan pemisahannya dijaga
        // sejak butir 218 (butir 231).
        $this->announceToAll();

        $this->actingAs($this->adminA)
            ->get(NotifikasiSaya::getUrl())
            ->assertOk()
            ->assertDontSee('https://wa.me/')
            ->assertDontSee('Buka WA');
    }

    public function test_the_page_never_opens_whatsapp_by_itself(): void
    {
        // Membuka puluhan tab sekaligus bukan bantuan (butir 232).
        $html = Livewire::actingAs($this->adminA)
            ->test(NotificationWaLinks::class, ['record' => $this->announceToAll()->getKey()])
            ->html();

        $this->assertStringNotContainsString('window.open', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
    }

    public function test_the_page_and_the_api_agree_on_the_recipient_list(): void
    {
        $notification = $this->announceToAll();

        $html = Livewire::actingAs($this->adminA)
            ->test(NotificationWaLinks::class, ['record' => $notification->getKey()])
            ->html();

        $fromApi = array_column(
            $this->asUser($this->adminA)->getJson($this->waLinksUrl($notification))->json('data.recipients'),
            'name',
        );

        foreach ($fromApi as $name) {
            $this->assertStringContainsString($name, $html);
        }
    }

    // ------------------------------------------------- Helper bersama PPDB

    public function test_ppdb_and_notifications_normalise_numbers_identically(): void
    {
        // Dua aturan yang perlahan berbeda akan membuat nomor yang sama
        // menghasilkan tautan berbeda tergantung modul mana yang membuatnya
        // (butir 222).
        $this->parentA->update(['phone' => '0812 3456-7890']);

        $this->assertSame(
            WhatsAppLink::normalizePhone('0812 3456-7890'),
            $this->rowForParent()['normalized_phone'],
        );
    }

    public function test_no_recipient_is_ever_marked_as_sent_to_whatsapp(): void
    {
        // Sistem hanya membuat tautan; ia tidak tahu apakah pesannya jadi
        // dikirim, dan mencatatnya akan mengarang fakta (butir 228).
        $row = $this->rowForParent();

        $this->assertArrayNotHasKey('sent', $row);
        $this->assertArrayNotHasKey('sent_at', $row);
        $this->assertArrayNotHasKey('delivered', $row);
    }
}
