<?php

namespace Tests\Feature\Notification;

use App\Enums\NotificationTargetType;
use App\Enums\NotificationType;
use App\Enums\RoleName;
use App\Models\AcademicYear;
use App\Models\Notification;
use App\Models\NotificationRead;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Scopes\SchoolScope;
use App\Models\User;
use App\Services\Notification\NotificationCenter;
use App\Services\Notification\NotificationRetentionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * NOTIF-04 poin 3 — "Riwayat notifikasi tersimpan 90 hari."
 *
 * Yang diuji di sini bukan bahwa pemangkasan berjalan, melainkan bahwa ia
 * berhenti tepat di tempat yang benar. Pemangkasan adalah penghapusan permanen
 * yang berjalan sendiri tanpa siapa pun menekan tombol, jadi setiap kesalahan
 * batas menghapus data yang seharusnya masih ada — dan tidak ada yang akan
 * menyadarinya sampai seseorang mencarinya.
 *
 * Batasnya karena itu diuji dari kedua sisi, draf diuji tidak pernah ikut, dan
 * kedua cabang diuji ikut terbersihkan dalam satu pemanggilan.
 */
class NotificationRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected User $adminA;

    protected User $parentA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create(['name' => 'SMP Madani']);
        $this->schoolB = School::factory()->create(['name' => 'SMP Seberang']);

        $this->adminA = $this->userIn($this->schoolA, RoleName::SchoolAdmin);
        $this->parentA = $this->userIn($this->schoolA, RoleName::OrangTua);
    }

    protected function userIn(School $school, RoleName $role): User
    {
        return User::factory()->forSchool($school)->withRole($role)->create();
    }

    /**
     * Notifikasi ditulis langsung lewat factory, bukan lewat publisher.
     *
     * Retensi hanya membaca `is_draft` dan `sent_at`; membangunnya lewat
     * publisher akan menambah aturan yang tidak sedang diuji.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function notificationIn(School $school, array $overrides = []): Notification
    {
        return Notification::factory()->create([
            'school_id' => $school->id,
            'sender_id' => $this->adminA->getKey(),
            'title' => 'Pengumuman',
            'message' => 'Isi pengumuman.',
            'type' => NotificationType::Announcement->value,
            'target_type' => NotificationTargetType::All->value,
            'target_id' => null,
            'is_draft' => false,
            'sent_at' => now(),
            ...$overrides,
        ]);
    }

    protected function sentDaysAgo(int $days, ?School $school = null, array $overrides = []): Notification
    {
        return $this->notificationIn($school ?? $this->schoolA, [
            'sent_at' => now()->subDays($days),
            ...$overrides,
        ]);
    }

    protected function prune(): int
    {
        return app(NotificationRetentionService::class)->prune();
    }

    protected function remainingIds(): array
    {
        return Notification::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    // ------------------------------------------------------------ batas

    public function test_history_older_than_ninety_days_is_removed(): void
    {
        $old = $this->sentDaysAgo(120);

        $this->assertSame(1, $this->prune());
        $this->assertNotContains($old->getKey(), $this->remainingIds());
    }

    public function test_history_from_eighty_nine_days_ago_is_kept(): void
    {
        $recent = $this->sentDaysAgo(89);

        $this->assertSame(0, $this->prune());
        $this->assertContains($recent->getKey(), $this->remainingIds());
    }

    public function test_the_boundary_keeps_what_is_exactly_ninety_days_old(): void
    {
        // Aturannya ditetapkan sekali dan diuji dari kedua sisi: yang tepat di
        // titik batas masih berada di dalam hari ke-90 dan tetap ada; hanya
        // yang benar-benar lebih tua yang dihapus (butir 253).
        $this->freezeTime(function (): void {
            $exactly = $this->sentDaysAgo(90);
            $justInside = $this->notificationIn($this->schoolA, [
                'sent_at' => now()->subDays(90)->addSecond(),
            ]);
            $justOutside = $this->notificationIn($this->schoolA, [
                'sent_at' => now()->subDays(90)->subSecond(),
            ]);

            $this->assertSame(1, $this->prune());

            $remaining = $this->remainingIds();

            $this->assertContains($exactly->getKey(), $remaining);
            $this->assertContains($justInside->getKey(), $remaining);
            $this->assertNotContains($justOutside->getKey(), $remaining);
        });
    }

    public function test_the_cutoff_is_ninety_days_before_now(): void
    {
        $this->freezeTime(function (): void {
            $this->assertTrue(
                now()->subDays(NotificationRetentionService::RETENTION_DAYS)
                    ->equalTo(app(NotificationRetentionService::class)->cutoff()),
            );
        });
    }

    public function test_touching_a_row_later_does_not_extend_its_retention(): void
    {
        // `updated_at` sengaja tidak dipakai: menandai terbaca atau menyentuh
        // barisnya karena alasan teknis apa pun akan memperpanjang umurnya
        // (butir 253).
        $old = $this->sentDaysAgo(200);
        $old->touch();

        $this->assertSame(1, $this->prune());
        $this->assertDatabaseCount('notifications', 0);
    }

    // ------------------------------------------------------------- draf

    public function test_a_very_old_draft_is_never_removed(): void
    {
        // Draf belum pernah sampai kepada siapa pun, jadi tidak ada "riwayat"
        // yang sedang disimpan untuknya (butir 254).
        $draft = $this->notificationIn($this->schoolA, [
            'is_draft' => true,
            'sent_at' => null,
            'created_at' => now()->subDays(200),
            'updated_at' => now()->subDays(200),
        ]);

        $this->assertSame(0, $this->prune());
        $this->assertContains($draft->getKey(), $this->remainingIds());
    }

    public function test_a_row_without_a_sent_at_is_never_removed(): void
    {
        $unsent = $this->notificationIn($this->schoolA, [
            'is_draft' => false,
            'sent_at' => null,
            'created_at' => now()->subDays(400),
        ]);

        $this->assertSame(0, $this->prune());
        $this->assertContains($unsent->getKey(), $this->remainingIds());
    }

    public function test_an_old_draft_and_an_old_sent_row_are_told_apart(): void
    {
        $draft = $this->notificationIn($this->schoolA, [
            'is_draft' => true,
            'sent_at' => null,
            'created_at' => now()->subDays(300),
        ]);
        $sent = $this->sentDaysAgo(300);

        $this->assertSame(1, $this->prune());

        $remaining = $this->remainingIds();
        $this->assertContains($draft->getKey(), $remaining);
        $this->assertNotContains($sent->getKey(), $remaining);
    }

    // ------------------------------------------------ manual & otomatis

    public function test_manual_and_automatic_history_expire_alike(): void
    {
        $manual = $this->sentDaysAgo(120);
        // Notifikasi sistem (NOTIF-03): tanpa pengirim.
        $automatic = $this->sentDaysAgo(120, null, [
            'sender_id' => null,
            'type' => NotificationType::Billing->value,
            'target_type' => NotificationTargetType::Individual->value,
            'target_id' => $this->parentA->getKey(),
        ]);

        $this->assertSame(2, $this->prune());

        $remaining = $this->remainingIds();
        $this->assertNotContains($manual->getKey(), $remaining);
        $this->assertNotContains($automatic->getKey(), $remaining);
    }

    // -------------------------------------------------- keadaan terbaca

    public function test_an_old_unread_notification_is_removed(): void
    {
        $this->sentDaysAgo(120);

        $this->assertSame(1, $this->prune());
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_an_old_read_notification_takes_its_read_row_with_it(): void
    {
        $old = $this->sentDaysAgo(120);

        NotificationRead::factory()->create([
            'notification_id' => $old->getKey(),
            'user_id' => $this->parentA->getKey(),
            'read_at' => now()->subDays(119),
        ]);

        $this->assertDatabaseCount('notification_reads', 1);

        $this->prune();

        // Tidak ada baris baca yatim: FK-nya sudah cascadeOnDelete sejak
        // Batch 8.1 (butir 258).
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('notification_reads', 0);
    }

    public function test_a_recent_read_notification_and_its_read_row_both_stay(): void
    {
        $recent = $this->sentDaysAgo(10);

        NotificationRead::factory()->create([
            'notification_id' => $recent->getKey(),
            'user_id' => $this->parentA->getKey(),
            'read_at' => now(),
        ]);

        $this->assertSame(0, $this->prune());
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('notification_reads', 1);
    }

    public function test_retention_does_not_depend_on_whether_it_was_read(): void
    {
        $readOld = $this->sentDaysAgo(120);
        $unreadOld = $this->sentDaysAgo(120);

        NotificationRead::factory()->create([
            'notification_id' => $readOld->getKey(),
            'user_id' => $this->parentA->getKey(),
            'read_at' => now()->subDays(100),
        ]);

        $this->assertSame(2, $this->prune());
        $this->assertDatabaseCount('notifications', 0);
    }

    // ------------------------------------------------------------ target

    public function test_every_target_type_expires_the_same_way(): void
    {
        $class = SchoolClass::factory()->create([
            'school_id' => $this->schoolA->id,
            'academic_year_id' => AcademicYear::factory()->create([
                'school_id' => $this->schoolA->id,
            ])->id,
        ]);

        $this->sentDaysAgo(120);
        $this->sentDaysAgo(120, null, [
            'target_type' => NotificationTargetType::SchoolClass->value,
            'target_id' => $class->getKey(),
        ]);
        $this->sentDaysAgo(120, null, [
            'target_type' => NotificationTargetType::Individual->value,
            'target_id' => $this->parentA->getKey(),
        ]);

        // Tidak ada penerima yang dihitung ulang untuk memutuskan retensi:
        // yang menentukan hanya umurnya (butir 255).
        $this->assertSame(3, $this->prune());
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_a_class_notification_expires_even_if_its_class_is_gone(): void
    {
        $old = $this->sentDaysAgo(120, null, [
            'target_type' => NotificationTargetType::SchoolClass->value,
            // Kelas yang tidak pernah ada — `target_id` memang tanpa FK.
            'target_id' => 999999,
        ]);

        $this->assertSame(1, $this->prune());
        $this->assertNotContains($old->getKey(), $this->remainingIds());
    }

    // ------------------------------------------------------- lintas cabang

    public function test_one_run_cleans_every_branch(): void
    {
        $oldA = $this->sentDaysAgo(120, $this->schoolA);
        $oldB = $this->sentDaysAgo(120, $this->schoolB);

        $this->assertSame(2, $this->prune());

        $remaining = $this->remainingIds();
        $this->assertNotContains($oldA->getKey(), $remaining);
        $this->assertNotContains($oldB->getKey(), $remaining);
    }

    public function test_recent_history_of_every_branch_is_left_alone(): void
    {
        $recentA = $this->sentDaysAgo(5, $this->schoolA);
        $recentB = $this->sentDaysAgo(5, $this->schoolB);
        $this->sentDaysAgo(120, $this->schoolA);

        $this->assertSame(1, $this->prune());

        $remaining = $this->remainingIds();
        $this->assertContains($recentA->getKey(), $remaining);
        $this->assertContains($recentB->getKey(), $remaining);
    }

    public function test_an_authenticated_branch_user_does_not_narrow_the_sweep(): void
    {
        // Kalau SchoolScope ikut berlaku, pemangkasan yang kebetulan berjalan
        // dalam konteks seorang pengguna hanya akan membersihkan satu cabang —
        // dan cabang lain diam-diam tidak pernah terpangkas (butir 256).
        $oldA = $this->sentDaysAgo(120, $this->schoolA);
        $oldB = $this->sentDaysAgo(120, $this->schoolB);

        $this->actingAs($this->adminA);

        $this->assertSame(2, $this->prune());

        $remaining = $this->remainingIds();
        $this->assertNotContains($oldA->getKey(), $remaining);
        $this->assertNotContains($oldB->getKey(), $remaining);
    }

    public function test_normal_tenant_isolation_is_untouched_by_the_sweep(): void
    {
        $this->sentDaysAgo(5, $this->schoolA);
        $this->sentDaysAgo(5, $this->schoolB);

        $this->prune();

        $this->actingAs($this->adminA);

        // Pelonggaran scope hanya berlaku di dalam service retensi; permintaan
        // biasa tetap terkurung cabangnya.
        $this->assertSame(1, Notification::query()->count());
    }

    // ------------------------------------------------------- idempotensi

    public function test_a_second_run_deletes_nothing_and_still_succeeds(): void
    {
        $this->sentDaysAgo(120);
        $this->sentDaysAgo(120);
        $kept = $this->sentDaysAgo(10);

        $this->assertSame(2, $this->prune());
        $this->assertSame(0, $this->prune());
        $this->assertSame([$kept->getKey()], $this->remainingIds());
    }

    public function test_pruning_an_empty_history_succeeds(): void
    {
        $this->assertSame(0, $this->prune());
    }

    public function test_pruning_touches_nothing_but_notifications(): void
    {
        $this->sentDaysAgo(120);

        $users = User::query()->withoutGlobalScope(SchoolScope::class)->count();
        $schools = School::query()->withoutGlobalScope(SchoolScope::class)->count();

        $this->prune();

        $this->assertSame($users, User::query()->withoutGlobalScope(SchoolScope::class)->count());
        $this->assertSame($schools, School::query()->withoutGlobalScope(SchoolScope::class)->count());
    }

    // ---------------------------------------------------------- perintah

    public function test_the_command_reports_what_it_removed(): void
    {
        $this->sentDaysAgo(120);
        $this->sentDaysAgo(120);

        $this->artisan('notifications:prune')
            ->expectsOutputToContain('2 notifikasi dihapus')
            ->assertSuccessful();

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_the_command_succeeds_when_there_is_nothing_to_remove(): void
    {
        $this->sentDaysAgo(10);

        $this->artisan('notifications:prune')
            ->expectsOutputToContain('0 notifikasi dihapus')
            ->assertSuccessful();

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_the_command_is_safe_to_run_twice(): void
    {
        $this->sentDaysAgo(120);

        $this->artisan('notifications:prune')->assertSuccessful();
        $this->artisan('notifications:prune')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_the_command_needs_neither_a_user_nor_a_tenant(): void
    {
        $this->sentDaysAgo(120, $this->schoolA);
        $this->sentDaysAgo(120, $this->schoolB);

        $this->assertFalse(auth()->check());

        $this->artisan('notifications:prune')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 0);
    }

    // --------------------------------------------------------- penjadwal

    public function test_the_prune_is_scheduled_exactly_once_a_day(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'notifications:prune'));

        $this->assertCount(1, $events, 'Pemangkasan harus terdaftar tepat sekali di penjadwal.');

        // Cadence harian diperiksa lewat bentuk ekspresi cron-nya — menit dan
        // jam tetap, hari/bulan/hari-pekan bebas — bukan lewat jam tertentu,
        // karena jamnya detail implementasi dan bukan kebutuhan blueprint
        // (butir 259).
        $parts = preg_split('/\s+/', trim((string) $events->first()->expression));

        $this->assertCount(5, $parts);
        $this->assertSame(['*', '*', '*'], array_slice($parts, 2));
        $this->assertMatchesRegularExpression('/^\d+$/', $parts[0]);
        $this->assertMatchesRegularExpression('/^\d+$/', $parts[1]);
    }

    // ---------------------------------------------- akibat di permukaan

    public function test_a_pruned_notification_leaves_the_recipient_feed(): void
    {
        $old = $this->sentDaysAgo(120, null, [
            'target_type' => NotificationTargetType::Individual->value,
            'target_id' => $this->parentA->getKey(),
        ]);
        $recent = $this->sentDaysAgo(5, null, [
            'target_type' => NotificationTargetType::Individual->value,
            'target_id' => $this->parentA->getKey(),
        ]);

        $center = app(NotificationCenter::class);

        $this->assertCount(2, $center->feed($this->parentA));
        $this->assertSame(2, $center->unreadCount($this->parentA));

        $this->prune();

        $feed = $center->feed($this->parentA);

        $this->assertCount(1, $feed);
        $this->assertSame($recent->getKey(), $feed->first()->getKey());
        $this->assertSame(1, $center->unreadCount($this->parentA));
        $this->assertNotContains($old->getKey(), $this->remainingIds());
    }

    public function test_the_api_no_longer_serves_a_pruned_notification(): void
    {
        $old = $this->sentDaysAgo(120, null, [
            'target_type' => NotificationTargetType::Individual->value,
            'target_id' => $this->parentA->getKey(),
        ]);

        $this->prune();

        $this->actingAs($this->parentA, 'sanctum')
            ->getJson('/api/v1/notifications/'.$old->getKey())
            ->assertNotFound();

        $this->actingAs($this->parentA, 'sanctum')
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_pruned_notification_exposes_no_stale_phone_data(): void
    {
        $this->parentA->update(['phone' => '081234567890']);

        $old = $this->sentDaysAgo(120, null, [
            'target_type' => NotificationTargetType::Individual->value,
            'target_id' => $this->parentA->getKey(),
        ]);

        $this->prune();

        $response = $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/v1/notifications/'.$old->getKey().'/wa-links');

        // Perilaku record-tidak-ada yang biasa; tidak ada artefak tautan yang
        // disimpan terpisah (butir 260).
        $response->assertNotFound();
        $response->assertDontSee('081234567890');
        $response->assertDontSee('6281234567890');
        $response->assertDontSee('https://wa.me/');
    }

    public function test_no_per_request_cutoff_filter_was_added_anywhere(): void
    {
        // Pemangkasan fisik sudah cukup, jadi ambang 90 hari hidup di satu
        // tempat saja. Notifikasi tua yang **belum** dipangkas karena itu tetap
        // terlihat — bukti bahwa tidak ada penyaring kedua yang diam-diam
        // menyembunyikannya (butir 255).
        $old = $this->sentDaysAgo(400, null, [
            'target_type' => NotificationTargetType::Individual->value,
            'target_id' => $this->parentA->getKey(),
        ]);

        $this->assertCount(1, app(NotificationCenter::class)->feed($this->parentA));

        $this->actingAs($this->parentA, 'sanctum')
            ->getJson('/api/v1/notifications/'.$old->getKey())
            ->assertOk();
    }

    // ------------------------------------------------------------ audit

    public function test_a_pruned_row_is_recorded_without_inventing_an_actor(): void
    {
        $old = $this->sentDaysAgo(120);

        $this->artisan('notifications:prune')->assertSuccessful();

        $audit = DB::table('audit_logs')
            ->where('auditable_type', Notification::class)
            ->where('auditable_id', $old->getKey())
            ->where('action', 'DELETED')
            ->first();

        $this->assertNotNull($audit, 'Penghapusan CUD harus tercatat (Security 3.4).');
        // Tidak ada aktor karangan: perintah CLI memang berjalan tanpa
        // pengguna, dan skema audit sudah menyediakan NULL untuk itu
        // (butir 258).
        $this->assertNull($audit->user_id);
        $this->assertNull($audit->ip_address);
        $this->assertSame($this->schoolA->id, (int) $audit->school_id);
    }

    // -------------------------------------------------------- performa

    public function test_the_sweep_reads_the_database_rather_than_php(): void
    {
        foreach ([3, 30] as $count) {
            Notification::query()->withoutGlobalScope(SchoolScope::class)->delete();

            for ($i = 0; $i < $count; $i++) {
                $this->sentDaysAgo(120);
            }

            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->prune();

            $selects = collect(DB::getQueryLog())
                ->filter(fn (array $entry) => str_starts_with(strtolower(ltrim((string) $entry['query'])), 'select'))
                ->count();

            DB::disableQueryLog();

            // Pembacaannya dua kali per putaran — daftar id dan barisnya — dan
            // satu lagi untuk membuktikan sudah habis. Jumlah baris tidak
            // mengubahnya selama masih satu putaran (butir 257).
            $this->assertLessThanOrEqual(4, $selects, "Terlalu banyak SELECT untuk {$count} baris.");
        }
    }

    public function test_more_rows_than_one_chunk_are_all_removed(): void
    {
        // Pemangkasan berulang sampai habis, bukan sekali seukuran chunk.
        $service = new class extends NotificationRetentionService
        {
            protected const CHUNK = 3;
        };

        for ($i = 0; $i < 10; $i++) {
            $this->sentDaysAgo(120);
        }

        $this->assertSame(10, $service->prune());
        $this->assertDatabaseCount('notifications', 0);
    }
}
