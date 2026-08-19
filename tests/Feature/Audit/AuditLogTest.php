<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Enums\RoleName;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * NFR 1.4 — *"Semua aksi CRUD dicatat di tabel audit_logs dengan user &
 * timestamp"*; `03-architecture/04-security.md` — *"Semua aksi CUD (Create,
 * Update, Delete) dicatat: user, action, table, id, timestamp, IP"*.
 *
 * Security 3.4 lebih spesifik — ia menyebut CUD **dan** merinci field-nya —
 * sehingga aksi baca tidak dicatat (butir 45).
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create();
        $this->admin = User::factory()->forSchool($this->school)->withRole(RoleName::SchoolAdmin)->create();

        // Baris audit dari penyiapan di atas dibersihkan supaya tiap test hanya
        // melihat jejak aksinya sendiri.
        AuditLog::query()->withoutGlobalScopes()->delete();
    }

    /**
     * Baris audit terakhir untuk satu model, apa pun cabangnya.
     */
    protected function lastLogFor(string $class, int|string $id): ?AuditLog
    {
        return AuditLog::query()
            ->withoutGlobalScopes()
            ->where('auditable_type', $class)
            ->where('auditable_id', $id)
            ->latest('id')
            ->first();
    }

    public function test_creating_a_student_is_recorded(): void
    {
        $this->actingAs($this->admin);

        $student = Student::factory()->create(['school_id' => $this->school->id]);

        $log = $this->lastLogFor(Student::class, $student->getKey());

        $this->assertNotNull($log);
        $this->assertSame(AuditAction::Created, $log->action);
        $this->assertSame(Student::class, $log->auditable_type);
        $this->assertSame($student->getKey(), (int) $log->auditable_id);
        $this->assertSame('students', $log->tableName());
    }

    public function test_updating_a_student_is_recorded(): void
    {
        $this->actingAs($this->admin);

        $student = Student::factory()->create(['school_id' => $this->school->id]);

        AuditLog::query()->withoutGlobalScopes()->delete();

        $student->update(['full_name' => 'Nama Diperbarui']);

        $log = $this->lastLogFor(Student::class, $student->getKey());

        $this->assertNotNull($log);
        $this->assertSame(AuditAction::Updated, $log->action);
    }

    public function test_deleting_a_record_is_recorded(): void
    {
        // Memakai model yang memang punya jalur hapus di UI (GradeResource /
        // ScheduleResource memakai DeleteAction); tidak ada perilaku hapus baru
        // yang dibuat hanya demi test ini.
        $this->actingAs($this->admin);

        $student = Student::factory()->create(['school_id' => $this->school->id]);
        $id = $student->getKey();

        AuditLog::query()->withoutGlobalScopes()->delete();

        $student->delete();

        $log = $this->lastLogFor(Student::class, $id);

        $this->assertNotNull($log);
        $this->assertSame(AuditAction::Deleted, $log->action);
    }

    public function test_a_tenant_action_stores_the_school_of_the_record(): void
    {
        $this->actingAs($this->admin);

        $student = Student::factory()->create(['school_id' => $this->school->id]);

        $this->assertSame(
            $this->school->id,
            $this->lastLogFor(Student::class, $student->getKey())?->school_id,
        );
    }

    public function test_a_platform_action_stores_a_null_school(): void
    {
        // Super Admin membuat cabang: aksi itu tidak berada di dalam cabang mana
        // pun, dan `schools` sendiri tidak punya kolom school_id.
        auth()->logout();

        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin);

        AuditLog::query()->withoutGlobalScopes()->delete();

        $school = School::factory()->create();

        $log = $this->lastLogFor(School::class, $school->getKey());

        $this->assertNotNull($log);
        $this->assertNull($log->school_id);
        $this->assertSame($superAdmin->getKey(), $log->user_id);
    }

    public function test_the_acting_user_is_recorded(): void
    {
        $this->actingAs($this->admin);

        $student = Student::factory()->create(['school_id' => $this->school->id]);

        $this->assertSame(
            $this->admin->getKey(),
            $this->lastLogFor(Student::class, $student->getKey())?->user_id,
        );
    }

    public function test_a_system_action_without_a_user_is_still_recorded(): void
    {
        // Job antrean dan perintah CLI berjalan tanpa pengguna — barisnya tetap
        // ditulis dengan user_id NULL, bukan dilewati.
        $student = Student::factory()->create(['school_id' => $this->school->id]);

        $log = $this->lastLogFor(Student::class, $student->getKey());

        $this->assertNotNull($log);
        $this->assertNull($log->user_id);
    }

    public function test_the_request_ip_is_recorded_on_a_web_request(): void
    {
        // IP hanya bermakna di dalam request HTTP; di CLI `request()->ip()`
        // hanya menghasilkan nilai palsu, jadi jalur ini diuji lewat request
        // sungguhan.
        Route::post('/__audit-ip-probe__', function () {
            Student::factory()->create(['school_id' => (int) request()->input('school_id')]);

            return response()->noContent();
        })->middleware('web');

        $this->actingAs($this->admin);

        AuditLog::query()->withoutGlobalScopes()->delete();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->post('/__audit-ip-probe__', ['school_id' => $this->school->id])
            ->assertNoContent();

        $log = AuditLog::query()->withoutGlobalScopes()->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame(Student::class, $log->auditable_type);
        $this->assertSame('203.0.113.9', $log->ip_address);
    }

    public function test_a_console_action_records_no_ip(): void
    {
        // Seeder, perintah artisan, dan worker antrean tidak punya request.
        $this->actingAs($this->admin);

        $student = Student::factory()->create(['school_id' => $this->school->id]);

        $this->assertNull($this->lastLogFor(Student::class, $student->getKey())?->ip_address);
    }

    public function test_the_audit_log_does_not_audit_itself(): void
    {
        $this->actingAs($this->admin);

        Student::factory()->create(['school_id' => $this->school->id]);

        $this->assertSame(
            0,
            AuditLog::query()
                ->withoutGlobalScopes()
                ->where('auditable_type', AuditLog::class)
                ->count(),
            'AuditLog tidak boleh mengaudit dirinya sendiri — itu rekursi tak terbatas.',
        );
    }

    public function test_reading_records_produces_no_audit_rows(): void
    {
        $this->actingAs($this->admin);

        Student::factory()->count(3)->create(['school_id' => $this->school->id]);

        AuditLog::query()->withoutGlobalScopes()->delete();

        // Security 3.4 menyebut CUD, bukan CRUD.
        Student::query()->get();
        Student::query()->first();
        Student::query()->count();

        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->count());
    }

    public function test_roles_and_permissions_are_not_audited(): void
    {
        // Definisi peran bersifat platform-wide dan hanya berubah lewat seeder;
        // pivotnya pun tidak memicu model event (butir 45).
        $this->actingAs($this->admin);

        AuditLog::query()->withoutGlobalScopes()->delete();

        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->count());
    }

    public function test_a_mass_update_that_skips_model_events_is_still_recorded(): void
    {
        // AcademicYear::activate() menonaktifkan tahun ajaran lain lewat query
        // builder — jalur yang tidak memicu model event sama sekali (butir 46).
        $this->actingAs($this->admin);

        $old = AcademicYear::factory()->create([
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);

        $new = AcademicYear::factory()->create([
            'school_id' => $this->school->id,
            'is_active' => false,
        ]);

        AuditLog::query()->withoutGlobalScopes()->delete();

        $new->activate();

        $this->assertFalse($old->fresh()->is_active);

        $deactivation = $this->lastLogFor(AcademicYear::class, $old->getKey());

        $this->assertNotNull(
            $deactivation,
            'Penonaktifan lewat mass update harus tetap tercatat.',
        );
        $this->assertSame(AuditAction::Updated, $deactivation->action);
        $this->assertSame($this->school->id, $deactivation->school_id);
        $this->assertSame($this->admin->getKey(), $deactivation->user_id);

        // Tahun ajaran yang diaktifkan disimpan lewat model, jadi tercatat oleh
        // listener seperti biasa.
        $this->assertNotNull($this->lastLogFor(AcademicYear::class, $new->getKey()));
    }

    public function test_each_write_adds_at_most_one_audit_row(): void
    {
        $this->actingAs($this->admin);

        AuditLog::query()->withoutGlobalScopes()->delete();

        $student = Student::factory()->create(['school_id' => $this->school->id]);
        $student->update(['full_name' => 'Sekali Lagi']);

        $this->assertSame(2, AuditLog::query()->withoutGlobalScopes()->count());
    }
}
