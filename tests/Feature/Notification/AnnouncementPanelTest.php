<?php

namespace Tests\Feature\Notification;

use App\Enums\NotificationTargetType;
use App\Enums\NotificationType;
use App\Enums\RoleName;
use App\Filament\Resources\NotificationResource;
use App\Filament\Resources\NotificationResource\Pages\CreateNotification;
use App\Filament\Resources\NotificationResource\Pages\EditNotification;
use App\Filament\Resources\NotificationResource\Pages\ListNotifications;
use App\Models\AcademicYear;
use App\Models\Notification;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Notification\AnnouncementPublisher;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * NOTIF-01 — pembuatan pengumuman manual dari panel admin.
 *
 * Panel dan API menulis lewat AnnouncementPublisher yang sama, jadi yang perlu
 * diuji di sini adalah hal-hal yang hanya ada di panel: siapa yang melihat
 * menunya, apa yang muncul di tabel, dan apakah tombol "Kirim" benar-benar
 * menerbitkan.
 */
class AnnouncementPanelTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected SchoolClass $classA;

    protected User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create();
        $this->schoolB = School::factory()->create();

        $year = AcademicYear::factory()->create([
            'school_id' => $this->schoolA->id,
            'is_active' => true,
        ]);

        $this->classA = SchoolClass::factory()->create([
            'school_id' => $this->schoolA->id,
            'academic_year_id' => $year->id,
            'name' => '7A',
        ]);

        $this->adminA = $this->userIn($this->schoolA, RoleName::SchoolAdmin);
    }

    protected function userIn(School $school, RoleName $role): User
    {
        return User::factory()->forSchool($school)->withRole($role)->create();
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

    // ------------------------------------------------------------ akses

    /**
     * @return array<string, array{RoleName}>
     */
    public static function managingRoles(): array
    {
        return [
            'admin sekolah' => [RoleName::SchoolAdmin],
            'kepala sekolah' => [RoleName::KepalaSekolah],
        ];
    }

    #[DataProvider('managingRoles')]
    public function test_authorized_roles_reach_the_announcement_pages(RoleName $role): void
    {
        $user = $this->userIn($this->schoolA, $role);
        $draft = $this->announce(send: false);

        $this->actingAs($user);

        $this->get(NotificationResource::getUrl('index'))->assertSuccessful();
        $this->get(NotificationResource::getUrl('create'))->assertSuccessful();
        $this->get(NotificationResource::getUrl('edit', ['record' => $draft]))->assertSuccessful();

        $this->assertTrue($user->can('create', Notification::class));
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function deniedRoles(): array
    {
        return [
            'guru' => [RoleName::Guru],
            'wali kelas' => [RoleName::WaliKelas],
            'bendahara' => [RoleName::Bendahara],
        ];
    }

    /**
     * PORTAL-02 sempat meminta pintasan Buat Pengumuman bagi guru; konfliknya
     * dicatat, bukan diselesaikan dengan memberi akses (butir 201).
     */
    #[DataProvider('deniedRoles')]
    public function test_other_panel_roles_do_not_see_announcements(RoleName $role): void
    {
        $user = $this->userIn($this->schoolA, $role);

        $this->actingAs($user);

        $this->assertFalse(NotificationResource::canViewAny());
        $this->get(NotificationResource::getUrl('index'))->assertForbidden();
        $this->get(NotificationResource::getUrl('create'))->assertForbidden();
    }

    public function test_the_table_shows_only_the_own_branch(): void
    {
        $foreignAdmin = $this->userIn($this->schoolB, RoleName::SchoolAdmin);

        $mine = $this->announce(['title' => 'Milik Cabang A']);
        $draft = $this->announce(['title' => 'Draf Cabang A'], send: false);
        $foreign = $this->announce(['title' => 'Milik Cabang B'], $foreignAdmin);

        Livewire::actingAs($this->adminA)
            ->test(ListNotifications::class)
            // Riwayat admin memang menyertakan draf — itu gunanya.
            ->assertCanSeeTableRecords([$mine, $draft])
            ->assertCanNotSeeTableRecords([$foreign]);
    }

    // ---------------------------------------------------------- membuat

    public function test_the_create_form_sends_through_the_publisher(): void
    {
        Livewire::actingAs($this->adminA)
            ->test(CreateNotification::class)
            ->fillForm([
                'title' => 'Rapat Wali Murid',
                'message' => 'Mohon hadir pukul 08.00.',
                'type' => NotificationType::Academic->value,
                'target_type' => NotificationTargetType::SchoolClass->value,
                'target_id' => $this->classA->id,
            ])
            ->callAction('send')
            ->assertHasNoFormErrors();

        $notification = Notification::query()->withoutGlobalScopes()->sole();

        $this->assertSame('Rapat Wali Murid', $notification->title);
        $this->assertSame($this->schoolA->id, $notification->school_id);
        $this->assertSame($this->adminA->getKey(), $notification->sender_id);
        $this->assertFalse($notification->is_draft);
        $this->assertNotNull($notification->sent_at);
    }

    public function test_the_create_form_can_stop_at_a_draft(): void
    {
        Livewire::actingAs($this->adminA)
            ->test(CreateNotification::class)
            ->fillForm([
                'title' => 'Rancangan',
                'message' => 'Belum final.',
                'type' => NotificationType::Announcement->value,
                'target_type' => NotificationTargetType::All->value,
            ])
            ->callAction('saveDraft')
            ->assertHasNoFormErrors();

        $notification = Notification::query()->withoutGlobalScopes()->sole();

        $this->assertTrue($notification->is_draft);
        $this->assertNull($notification->sent_at);
    }

    /**
     * SYSTEM hanya untuk notifikasi otomatis (NOTIF-03); ia tidak ditawarkan
     * sebagai kategori pengumuman manual (butir 191).
     */
    public function test_system_is_not_offered_as_a_manual_category(): void
    {
        $options = NotificationType::manualOptions();

        $this->assertArrayNotHasKey(NotificationType::System->value, $options);
        $this->assertArrayHasKey(NotificationType::Announcement->value, $options);
        $this->assertArrayHasKey(NotificationType::Academic->value, $options);
        $this->assertArrayHasKey(NotificationType::Billing->value, $options);
        $this->assertArrayHasKey(NotificationType::Emergency->value, $options);
    }

    /**
     * Peran cabang tidak melihat pilihan cabang sama sekali; Super Admin wajib
     * memilihnya (butir 202).
     */
    public function test_only_a_super_admin_chooses_a_branch(): void
    {
        Livewire::actingAs($this->adminA)
            ->test(CreateNotification::class)
            ->assertFormFieldIsHidden('school_id');

        $superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create(['school_id' => null]);

        Livewire::actingAs($superAdmin)
            ->test(CreateNotification::class)
            ->assertFormFieldExists('school_id');
    }

    // ----------------------------------------------- draf dan penerbitan

    public function test_the_send_action_publishes_a_saved_draft(): void
    {
        $draft = $this->announce(['title' => 'Draf'], send: false);

        Livewire::actingAs($this->adminA)
            ->test(ListNotifications::class)
            ->callTableAction('send', $draft);

        $draft->refresh();

        $this->assertFalse($draft->is_draft);
        $this->assertNotNull($draft->sent_at);
    }

    public function test_a_sent_announcement_offers_neither_edit_nor_send(): void
    {
        $sent = $this->announce();

        Livewire::actingAs($this->adminA)
            ->test(ListNotifications::class)
            ->assertTableActionHidden('edit', $sent)
            ->assertTableActionHidden('send', $sent);
    }

    public function test_a_draft_offers_both_edit_and_send(): void
    {
        $draft = $this->announce(send: false);

        Livewire::actingAs($this->adminA)
            ->test(ListNotifications::class)
            ->assertTableActionVisible('edit', $draft)
            ->assertTableActionVisible('send', $draft);
    }

    public function test_the_edit_page_refuses_a_sent_announcement(): void
    {
        $sent = $this->announce();

        $this->actingAs($this->adminA)
            ->get(NotificationResource::getUrl('edit', ['record' => $sent]))
            ->assertForbidden();
    }

    public function test_editing_a_draft_then_sending_publishes_the_new_text(): void
    {
        $draft = $this->announce(['title' => 'Draf Awal'], send: false);

        Livewire::actingAs($this->adminA)
            ->test(EditNotification::class, ['record' => $draft->getRouteKey()])
            ->fillForm([
                'title' => 'Judul Final',
                'message' => 'Isi final.',
                'type' => NotificationType::Emergency->value,
                'target_type' => NotificationTargetType::All->value,
            ])
            ->callAction('send')
            ->assertHasNoFormErrors();

        $draft->refresh();

        $this->assertSame('Judul Final', $draft->title);
        $this->assertSame(NotificationType::Emergency, $draft->type);
        $this->assertTrue($draft->isSent());
    }

    /**
     * API 4.10 tidak menyediakan DELETE, dan riwayatnya justru diminta disimpan
     * (NOTIF-04 poin 3) — jadi panel pun tidak menawarkan penghapusan, termasuk
     * secara massal.
     */
    public function test_the_panel_offers_no_deletion(): void
    {
        $sent = $this->announce();

        Livewire::actingAs($this->adminA)
            ->test(ListNotifications::class)
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');

        $this->assertFalse($this->adminA->can('delete', $sent));
    }
}
