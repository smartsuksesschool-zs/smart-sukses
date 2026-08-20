<?php

namespace Tests\Feature\Finance;

use App\Enums\AuditAction;
use App\Enums\RoleName;
use App\Enums\StudentFeeStatus;
use App\Enums\StudentStatus;
use App\Jobs\GenerateStudentFees;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\FeeType;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use App\Services\Finance\StudentFeeGenerator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SPP-02 — penerbitan tagihan massal oleh worker antrean.
 *
 * Job dijalankan langsung (bukan lewat connection `sync`) supaya yang diuji
 * adalah perilaku `handle()` di dalam worker: tanpa request, tanpa pengguna
 * terautentikasi, dan tanpa bantuan SchoolScope.
 */
class GenerateStudentFeeJobTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected AcademicYear $year;

    protected FeeType $feeType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create();

        $this->year = AcademicYear::factory()->create([
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);

        $this->feeType = FeeType::factory()->create([
            'school_id' => $this->school->id,
            'name' => 'SPP',
            'amount' => 150000,
            'academic_year_id' => null,
        ]);
    }

    protected function student(string $name, StudentStatus $status = StudentStatus::Active, ?School $school = null): Student
    {
        return Student::factory()->create([
            'school_id' => ($school ?? $this->school)->id,
            'full_name' => $name,
            'status' => $status->value,
        ]);
    }

    protected function runJob(?int $schoolId = null, ?int $feeTypeId = null, string $period = '2026-08', string $dueDate = '2026-08-10'): void
    {
        $job = new GenerateStudentFees(
            $schoolId ?? $this->school->id,
            $feeTypeId ?? $this->feeType->id,
            $period,
            $dueDate,
        );

        $job->handle(app(StudentFeeGenerator::class));
    }

    /**
     * Worker antrean tidak punya request maupun pengguna terautentikasi.
     */
    public function test_the_job_runs_without_an_authenticated_user(): void
    {
        $this->student('Ahmad');

        $this->assertFalse(Auth::check());

        $this->runJob();

        $this->assertSame(1, StudentFee::query()->withoutGlobalScopes()->count());
    }

    public function test_every_active_student_receives_a_bill(): void
    {
        $this->student('Ahmad');
        $this->student('Budi');
        $this->student('Lulus', StudentStatus::Graduated);
        $this->student('Pindah', StudentStatus::Transferred);
        $this->student('Keluar', StudentStatus::DroppedOut);

        $this->runJob();

        $fees = StudentFee::query()->withoutGlobalScopes()->get();

        $this->assertCount(2, $fees);
        $this->assertEqualsCanonicalizing(
            ['Ahmad', 'Budi'],
            $fees->map(fn (StudentFee $fee) => Student::withoutGlobalScopes()->find($fee->student_id)->full_name)->all(),
        );
    }

    public function test_each_bill_snapshots_the_fee_type_amount_and_starts_unpaid(): void
    {
        $this->student('Ahmad');

        $this->runJob();

        $fee = StudentFee::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame('150000.00', $fee->amount);
        $this->assertSame('0.00', $fee->amount_paid);
        $this->assertSame(StudentFeeStatus::Unpaid, $fee->status);
        $this->assertSame('2026-08', $fee->period);
        $this->assertSame('2026-08-10', $fee->due_date->toDateString());
        $this->assertSame($this->school->id, $fee->school_id);
        $this->assertSame($this->feeType->id, $fee->fee_type_id);
    }

    /**
     * Nominal adalah salinan saat penerbitan: mengubah jenis tagihan sesudahnya
     * tidak boleh menggeser tagihan yang sudah terbit.
     */
    public function test_the_snapshot_does_not_follow_later_fee_type_changes(): void
    {
        $this->student('Ahmad');

        $this->runJob();

        $this->feeType->forceFill(['amount' => 999000])->save();

        $this->assertSame('150000.00', StudentFee::query()->withoutGlobalScopes()->firstOrFail()->amount);
    }

    /**
     * Tahun ajaran mengikuti jenis tagihannya bila terikat; bila tidak, tahun
     * ajaran aktif cabang (butir 53).
     */
    public function test_academic_year_follows_the_fee_type_then_the_active_year(): void
    {
        $this->student('Ahmad');

        $this->runJob();

        $this->assertSame(
            $this->year->id,
            StudentFee::query()->withoutGlobalScopes()->firstOrFail()->academic_year_id,
        );

        $otherYear = AcademicYear::factory()->create(['school_id' => $this->school->id]);

        $boundFeeType = FeeType::factory()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $otherYear->id,
        ]);

        $this->runJob(feeTypeId: $boundFeeType->id, period: '2026-09');

        $this->assertSame(
            $otherYear->id,
            StudentFee::query()->withoutGlobalScopes()
                ->where('fee_type_id', $boundFeeType->id)
                ->firstOrFail()
                ->academic_year_id,
        );
    }

    /**
     * Idempotency teknis: retry job tidak boleh menghasilkan baris kedua.
     */
    public function test_retrying_the_job_creates_no_duplicates(): void
    {
        $this->student('Ahmad');
        $this->student('Budi');

        $this->runJob();
        $this->runJob();
        $this->runJob();

        $this->assertSame(2, StudentFee::query()->withoutGlobalScopes()->count());
    }

    /**
     * Penerbitan kedua hanya melengkapi yang belum ada — siswa yang baru masuk
     * ikut tertagih, siswa lama tidak tertagih dua kali.
     */
    public function test_a_second_run_only_fills_the_gap(): void
    {
        $this->student('Ahmad');

        $this->runJob();

        $this->student('Budi');

        $this->runJob();

        $this->assertSame(2, StudentFee::query()->withoutGlobalScopes()->count());
        $this->assertSame(
            1,
            StudentFee::query()->withoutGlobalScopes()->where('student_id', $this->studentIdNamed('Ahmad'))->count(),
        );
    }

    protected function studentIdNamed(string $name): int
    {
        return Student::withoutGlobalScopes()->where('full_name', $name)->value('id');
    }

    /**
     * Periode berbeda adalah tagihan berbeda — bukan duplikat.
     */
    public function test_a_different_period_is_not_a_duplicate(): void
    {
        $this->student('Ahmad');

        $this->runJob(period: '2026-08');
        $this->runJob(period: '2026-09');

        $this->assertSame(2, StudentFee::query()->withoutGlobalScopes()->count());
        $this->assertEqualsCanonicalizing(
            ['2026-08', '2026-09'],
            StudentFee::query()->withoutGlobalScopes()->pluck('period')->all(),
        );
    }

    public function test_a_different_fee_type_is_not_a_duplicate(): void
    {
        $this->student('Ahmad');

        $other = FeeType::factory()->create(['school_id' => $this->school->id, 'name' => 'Uang Gedung']);

        $this->runJob();
        $this->runJob(feeTypeId: $other->id);

        $this->assertSame(2, StudentFee::query()->withoutGlobalScopes()->count());
    }

    /**
     * Isolasi tenant: tagihan cabang lain tidak boleh ikut terbaca sebagai
     * "sudah ada", dan siswa cabang lain tidak boleh ikut tertagih.
     */
    public function test_another_tenant_is_neither_billed_nor_counted(): void
    {
        $otherSchool = School::factory()->create();
        $otherFeeType = FeeType::factory()->create(['school_id' => $otherSchool->id]);
        $otherStudent = $this->student('Siswa Cabang Lain', school: $otherSchool);

        // Tagihan milik cabang lain dengan periode yang sama.
        StudentFee::factory()->create([
            'school_id' => $otherSchool->id,
            'student_id' => $otherStudent->id,
            'fee_type_id' => $otherFeeType->id,
            'period' => '2026-08',
        ]);

        $this->student('Ahmad');

        $this->runJob();

        $mine = StudentFee::query()->withoutGlobalScopes()->where('school_id', $this->school->id)->get();

        $this->assertCount(1, $mine);
        $this->assertSame($this->studentIdNamed('Ahmad'), (int) $mine->first()->student_id);

        // Cabang lain tidak bertambah.
        $this->assertSame(
            1,
            StudentFee::query()->withoutGlobalScopes()->where('school_id', $otherSchool->id)->count(),
        );
    }

    /**
     * Jenis tagihan milik cabang lain tidak pernah dieksekusi, sekalipun
     * id-nya dipaksakan ke dalam job.
     */
    public function test_a_fee_type_from_another_tenant_is_refused(): void
    {
        $otherSchool = School::factory()->create();
        $foreignFeeType = FeeType::factory()->create(['school_id' => $otherSchool->id]);

        $this->student('Ahmad');

        $this->runJob(feeTypeId: $foreignFeeType->id);

        $this->assertSame(0, StudentFee::query()->withoutGlobalScopes()->count());
    }

    public function test_a_deleted_or_unknown_fee_type_is_a_quiet_no_op(): void
    {
        $this->student('Ahmad');

        $this->runJob(feeTypeId: 999999);

        $this->assertSame(0, StudentFee::query()->withoutGlobalScopes()->count());
    }

    /**
     * NFR 1.4 / Security 3.4 — setiap tagihan yang benar-benar dibuat masuk
     * jejak audit, lewat listener existing. Job tidak punya pengguna, sehingga
     * `user_id` NULL adalah nilai yang benar, bukan kekosongan data.
     */
    public function test_every_created_bill_is_audited_once(): void
    {
        $this->student('Ahmad');
        $this->student('Budi');

        $this->runJob();

        $audits = AuditLog::query()->withoutGlobalScopes()
            ->where('auditable_type', StudentFee::class)
            ->get();

        $this->assertCount(2, $audits);

        foreach ($audits as $audit) {
            $this->assertSame(AuditAction::Created, $audit->action);
            $this->assertSame($this->school->id, $audit->school_id);
            $this->assertNull($audit->user_id);
        }
    }

    /**
     * Retry yang tidak membuat apa pun juga tidak boleh menambah jejak audit
     * CREATED — kalau tidak, audit akan melaporkan penerbitan yang tak pernah
     * terjadi.
     */
    public function test_a_skipped_retry_adds_no_audit_rows(): void
    {
        $this->student('Ahmad');

        $this->runJob();

        $before = AuditLog::query()->withoutGlobalScopes()
            ->where('auditable_type', StudentFee::class)
            ->count();

        $this->runJob();
        $this->runJob();

        $this->assertSame($before, AuditLog::query()->withoutGlobalScopes()
            ->where('auditable_type', StudentFee::class)
            ->count());
        $this->assertSame(1, $before);
    }

    /**
     * Sisi baca tidak boleh tumbuh mengikuti jumlah siswa: siswa dan tagihan
     * yang sudah ada masing-masing diambil satu kali (N+1 guard).
     */
    public function test_reads_do_not_grow_with_the_number_of_students(): void
    {
        $small = $this->countSelectsForStudents(3, '2026-08');
        $large = $this->countSelectsForStudents(12, '2026-09');

        $this->assertSame(
            $small,
            $large,
            'Jumlah query SELECT berubah saat jumlah siswa bertambah — kemungkinan N+1.',
        );
    }

    protected function countSelectsForStudents(int $studentCount, string $period): int
    {
        Student::withoutGlobalScopes()->where('school_id', $this->school->id)->delete();

        for ($i = 0; $i < $studentCount; $i++) {
            $this->student("Siswa {$period} {$i}");
        }

        $selects = 0;

        DB::listen(function ($query) use (&$selects): void {
            if (stripos(ltrim($query->sql), 'select') === 0) {
                $selects++;
            }
        });

        $this->runJob(period: $period);

        // Listener tidak dapat dilepas satu per satu; dinolkan lewat variabel
        // lokal saja karena tiap pemanggilan memakai penghitungnya sendiri.
        $counted = $selects;
        $selects = 0;

        return $counted;
    }

    public function test_students_of_another_school_are_never_targeted_even_with_a_forged_school_id(): void
    {
        $otherSchool = School::factory()->create();
        $this->student('Siswa Cabang Lain', school: $otherSchool);
        $this->student('Ahmad');

        // school_id dipalsukan ke cabang lain, tetapi fee_type tetap milik
        // cabang asal: kombinasi itu tidak resolve, sehingga tidak ada yang
        // diterbitkan.
        $this->runJob(schoolId: $otherSchool->id);

        $this->assertSame(0, StudentFee::query()->withoutGlobalScopes()->count());
    }

    public function test_role_matrix_decides_who_may_issue_bills(): void
    {
        $bendahara = User::factory()->forSchool($this->school)->withRole(RoleName::Bendahara)->create();
        $admin = User::factory()->forSchool($this->school)->withRole(RoleName::SchoolAdmin)->create();
        $kepala = User::factory()->forSchool($this->school)->withRole(RoleName::KepalaSekolah)->create();
        $guru = User::factory()->forSchool($this->school)->withRole(RoleName::Guru)->create();

        $this->assertTrue($bendahara->can('create', StudentFee::class));
        $this->assertTrue($admin->can('create', StudentFee::class));
        $this->assertFalse($kepala->can('create', StudentFee::class));
        $this->assertFalse($guru->can('create', StudentFee::class));

        // Tagihan tidak dihapus; yang salah terbit dibebaskan (WAIVED).
        $fee = StudentFee::factory()->create([
            'school_id' => $this->school->id,
            'student_id' => $this->student('Ahmad')->id,
            'fee_type_id' => $this->feeType->id,
        ]);

        $this->assertFalse($bendahara->can('delete', $fee));
    }
}
