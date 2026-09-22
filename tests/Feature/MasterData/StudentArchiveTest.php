<?php

namespace Tests\Feature\MasterData;

use App\Enums\AccountClaimStatus;
use App\Enums\AccountClaimType;
use App\Enums\Gender;
use App\Enums\RoleName;
use App\Enums\StudentStatus;
use App\Filament\Resources\StudentResource\Pages\CreateStudent;
use App\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Models\AcademicYear;
use App\Models\AccountClaim;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Grade;
use App\Models\Payment;
use App\Models\PpdbRegistration;
use App\Models\ReportCard;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentFee;
use App\Models\User;
use App\Policies\StudentPolicy;
use App\Services\Admin\StudentRosterSummary;
use App\Support\StudentPhoto;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Arsip siswa: "Hapus" di layar berarti soft delete, tidak pernah DELETE.
 *
 * SIS-02 poin 2 menetapkan siswa tidak pernah hilang dari basis data. Tujuh FK
 * ke `students` memakai `cascadeOnDelete`, sehingga satu DELETE sungguhan akan
 * ikut menghapus nilai, rapor, tagihan, pembayaran, kelas, percobaan ujian, dan
 * permintaan akun — histori yang justru paling mahal. Yang dijaga di sini:
 * barisnya tetap ada, seluruh anaknya tetap ada, fotonya tetap ada, dan
 * pemulihannya mengembalikan keadaan semula (butir 589).
 *
 * Arsip juga bukan status akademik: `status` tidak ikut berubah.
 *
 * Seluruh data di sini sintetis.
 */
class StudentArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create();
        $this->schoolB = School::factory()->create();
        $this->adminA = User::factory()->forSchool($this->schoolA)->withRole(RoleName::SchoolAdmin)->create();
    }

    protected function student(?School $school = null, array $attributes = []): Student
    {
        return Student::factory()->create(array_merge([
            'school_id' => ($school ?? $this->schoolA)->id,
        ], $attributes));
    }

    /** Mengarsipkan lewat aksi tabel, bukan lewat model. */
    protected function archiveViaPanel(Student $student): Testable
    {
        return Livewire::test(ListStudents::class)
            ->callTableAction('archive', $student->getKey());
    }

    protected function yearFor(School $school): AcademicYear
    {
        return AcademicYear::factory()->create(['school_id' => $school->id, 'is_active' => true]);
    }

    // ------------------------------------------------- arsip, bukan hapus

    public function test_arsip_tidak_menghapus_baris_dari_basis_data(): void
    {
        $student = $this->student(attributes: ['nis' => 'ZZ-7001']);

        $this->actingAs($this->adminA);
        $this->archiveViaPanel($student)->assertHasNoTableActionErrors();

        // Barisnya masih ada, dan yang berubah hanya penanda arsipnya.
        $this->assertDatabaseHas('students', ['id' => $student->getKey(), 'nis' => 'ZZ-7001']);
        $this->assertSame(1, Student::withTrashed()->whereKey($student->getKey())->count());
        $this->assertNotNull(Student::withTrashed()->find($student->getKey())->deleted_at);
    }

    public function test_query_bawaan_tidak_lagi_menemukan_siswa_terarsip(): void
    {
        $student = $this->student();

        $student->delete();

        $this->assertNull(Student::find($student->getKey()));
        $this->assertSame(0, Student::query()->count());
        $this->assertSame(1, Student::withTrashed()->count());
        $this->assertSame(1, Student::onlyTrashed()->count());
    }

    public function test_arsip_tidak_mengubah_status_akademik(): void
    {
        $student = $this->student();
        $statusSebelum = $student->status;

        $student->delete();

        $this->assertSame($statusSebelum, Student::withTrashed()->find($student->getKey())->status);
    }

    public function test_pulihkan_mengembalikan_siswa_ke_daftar(): void
    {
        $student = $this->student(attributes: ['nis' => 'ZZ-7002']);
        $student->delete();

        $this->actingAs($this->adminA);

        Livewire::test(ListStudents::class)
            ->filterTable('trashed', false)
            ->callTableAction('restore', $student->getKey())
            ->assertHasNoTableActionErrors();

        $this->assertNotNull(Student::find($student->getKey()));
        $this->assertNull(Student::find($student->getKey())->deleted_at);
    }

    // ------------------------------------------------------ histori utuh

    public function test_seluruh_relasi_histori_tetap_ada_sesudah_arsip(): void
    {
        $year = $this->yearFor($this->schoolA);
        $class = SchoolClass::factory()->create([
            'school_id' => $this->schoolA->id,
            'academic_year_id' => $year->id,
        ]);
        $student = $this->student();

        StudentClass::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_id' => $student->id,
            'class_id' => $class->id,
            'academic_year_id' => $year->id,
        ]);
        Grade::factory()->create(['school_id' => $this->schoolA->id, 'student_id' => $student->id]);
        ReportCard::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_id' => $student->id,
            'class_id' => $class->id,
            'academic_year_id' => $year->id,
        ]);
        $fee = StudentFee::factory()->create(['school_id' => $this->schoolA->id, 'student_id' => $student->id]);
        Payment::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_id' => $student->id,
            'student_fee_id' => $fee->id,
        ]);
        $exam = Exam::factory()->create(['school_id' => $this->schoolA->id]);
        ExamAttempt::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_id' => $student->id,
            'exam_id' => $exam->id,
        ]);
        AccountClaim::create([
            'school_id' => $this->schoolA->id,
            'student_id' => $student->id,
            'provider' => 'google',
            'provider_subject' => 'zz-subject-sintetis',
            'email' => 'zz.arsip@example.test',
            'name' => 'Pemohon Sintetis',
            'requested_type' => AccountClaimType::Siswa->value,
            'status' => AccountClaimStatus::Pending->value,
            'requested_at' => now(),
        ]);
        $registration = PpdbRegistration::factory()->create([
            'school_id' => $this->schoolA->id,
            'converted_student_id' => $student->id,
        ]);

        $student->delete();

        // Tidak satu pun FK cascade berjalan: seluruh anaknya masih ada, dan
        // PPDB masih menunjuk siswa yang sama (bukan NULL).
        foreach ([
            'student_classes', 'grades', 'report_cards', 'student_fees',
            'payments', 'exam_attempts', 'account_claims',
        ] as $table) {
            $this->assertSame(
                1,
                DB::table($table)->where('student_id', $student->getKey())->count(),
                "Baris {$table} milik siswa terarsip tidak boleh ikut hilang.",
            );
        }

        $this->assertSame(
            $student->getKey(),
            (int) DB::table('ppdb_registrations')->where('id', $registration->id)->value('converted_student_id'),
        );
    }

    public function test_foto_privat_tidak_dihapus_saat_arsip_dan_ikut_kembali_saat_pulih(): void
    {
        Storage::fake(StudentPhoto::DISK);

        $path = StudentPhoto::DIRECTORY.'/arsip-sintetis.jpg';
        Storage::disk(StudentPhoto::DISK)->put($path, 'gambar-sintetis');

        $student = $this->student(attributes: ['photo_url' => $path]);

        $student->delete();

        // Arsip harus dapat dipulihkan utuh, jadi berkasnya tidak disentuh.
        Storage::disk(StudentPhoto::DISK)->assertExists($path);
        $this->assertSame($path, Student::withTrashed()->find($student->getKey())->photo_url);

        Student::withTrashed()->find($student->getKey())->restore();

        Storage::disk(StudentPhoto::DISK)->assertExists($path);
        $this->assertNotNull(StudentPhoto::url(Student::find($student->getKey())));
    }

    // ------------------------------------------------ query aktif vs histori

    public function test_siswa_terarsip_tidak_masuk_query_aktif(): void
    {
        $aktif = $this->student(attributes: ['nis' => 'ZZ-7010']);
        $terarsip = $this->student(attributes: ['nis' => 'ZZ-7011']);
        $terarsip->delete();

        $this->assertSame(1, Student::query()->active()->count());
        $this->assertSame([$aktif->getKey()], Student::query()->active()->pluck('id')->all());
    }

    public function test_where_has_student_tidak_diam_diam_memasukkan_arsip(): void
    {
        $year = $this->yearFor($this->schoolA);
        $class = SchoolClass::factory()->create([
            'school_id' => $this->schoolA->id,
            'academic_year_id' => $year->id,
        ]);
        $student = $this->student();
        StudentClass::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_id' => $student->id,
            'class_id' => $class->id,
            'academic_year_id' => $year->id,
        ]);
        Grade::factory()->create(['school_id' => $this->schoolA->id, 'student_id' => $student->id]);

        $this->assertSame(1, Grade::query()->whereHas('student')->count());

        $student->delete();

        // `student()` sendiri tidak diubah, sehingga query aktif tetap
        // mengecualikan arsip walau layar histori memuatnya withTrashed().
        $this->assertSame(0, Grade::query()->whereHas('student')->count());
    }

    public function test_layar_histori_tetap_menampilkan_identitas_siswa_terarsip(): void
    {
        $student = $this->student(attributes: ['full_name' => 'Siswa Arsip Sintetis', 'nis' => 'ZZ-7020']);
        $grade = Grade::factory()->create(['school_id' => $this->schoolA->id, 'student_id' => $student->id]);

        $student->delete();

        $dimuat = Grade::query()
            ->with(['student' => fn ($query) => $query->withTrashed()])
            ->find($grade->getKey());

        $this->assertNotNull($dimuat->student);
        $this->assertSame('Siswa Arsip Sintetis', $dimuat->student->full_name);
    }

    public function test_ringkasan_roster_tidak_menghitung_siswa_terarsip(): void
    {
        $year = $this->yearFor($this->schoolA);
        $class = SchoolClass::factory()->create([
            'school_id' => $this->schoolA->id,
            'academic_year_id' => $year->id,
        ]);

        foreach (['ZZ-7030', 'ZZ-7031'] as $nis) {
            $student = $this->student(attributes: ['nis' => $nis]);
            StudentClass::factory()->create([
                'school_id' => $this->schoolA->id,
                'student_id' => $student->id,
                'class_id' => $class->id,
                'academic_year_id' => $year->id,
            ]);
        }

        $sebelum = app(StudentRosterSummary::class)->for($this->adminA);

        Student::where('nis', 'ZZ-7031')->first()->delete();

        $sesudah = app(StudentRosterSummary::class)->for($this->adminA);

        $this->assertSame($sebelum['total'] - 1, $sesudah['total']);
        $this->assertSame($sebelum['placed'] - 1, $sesudah['placed']);
    }

    // ----------------------------------------------------------- NIS arsip

    public function test_nis_siswa_terarsip_tetap_dipesan(): void
    {
        $student = $this->student(attributes: ['nis' => 'ZZ-7040']);
        $student->delete();

        $this->actingAs($this->adminA);

        Livewire::test(CreateStudent::class)
            ->fillForm([
                'nis' => 'ZZ-7040',
                'full_name' => 'Siswa Baru Sintetis',
                'gender' => Gender::Male->value,
                'status' => StudentStatus::Active->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['nis']);

        $this->assertSame(1, Student::withTrashed()->where('nis', 'ZZ-7040')->count());
    }

    public function test_nis_yang_sama_tetap_boleh_di_cabang_lain(): void
    {
        $this->student(attributes: ['nis' => 'ZZ-7041'])->delete();

        $lain = Student::factory()->create(['school_id' => $this->schoolB->id, 'nis' => 'ZZ-7041']);

        $this->assertSame($this->schoolB->id, $lain->school_id);
    }

    // ------------------------------------------------------- otorisasi

    public function test_admin_cabang_lain_tidak_dapat_mengarsipkan(): void
    {
        $student = $this->student($this->schoolB);

        $this->assertFalse($this->adminA->can('delete', $student));
    }

    public function test_admin_cabang_lain_tidak_dapat_memulihkan(): void
    {
        $student = $this->student($this->schoolB);
        $student->delete();

        $this->assertFalse($this->adminA->can('restore', Student::withTrashed()->find($student->getKey())));
    }

    public function test_admin_cabangnya_sendiri_boleh_arsip_dan_pulihkan(): void
    {
        $student = $this->student();

        $this->assertTrue($this->adminA->can('delete', $student));
        $this->assertTrue($this->adminA->can('restore', $student));
    }

    public function test_penghapusan_permanen_tidak_pernah_diizinkan(): void
    {
        $student = $this->student();

        /*
         * Policy-nya menolak siapa pun. Super Admin tetap lolos lebih dulu
         * lewat `Gate::before` (Arsitektur 3.2.2) — itu perilaku platform yang
         * sudah ada dan tidak diubah di sini — sehingga yang menjaga FK cascade
         * tidak pernah terpicu adalah ketiadaan jalurnya: tidak ada
         * ForceDeleteAction di panel mana pun, dan tidak ada aksi massal.
         */
        $this->assertFalse($this->adminA->can('forceDelete', $student));
        $this->assertFalse(app(StudentPolicy::class)->forceDelete($this->adminA, $student));

        $superAdmin = User::factory()->withRole(RoleName::SuperAdmin)->create(['school_id' => null]);
        $this->assertFalse(app(StudentPolicy::class)->forceDelete($superAdmin, $student));
    }

    public function test_guru_tidak_dapat_mengarsipkan_siswa(): void
    {
        $guru = User::factory()->forSchool($this->schoolA)->withRole(RoleName::Guru)->create();
        $student = $this->student();

        $this->assertFalse($guru->can('delete', $student));
        $this->assertFalse($guru->can('restore', $student));
    }

    // --------------------------------------------------------- panel & UI

    public function test_tabel_siswa_tidak_menyediakan_aksi_massal(): void
    {
        $this->actingAs($this->adminA);
        $this->student();

        $bulkActions = Livewire::test(ListStudents::class)
            ->instance()->getTable()->getBulkActions();

        $this->assertSame([], $bulkActions);
    }

    public function test_tidak_ada_aksi_hapus_permanen_di_tabel(): void
    {
        $this->actingAs($this->adminA);
        $this->student();

        $actions = Livewire::test(ListStudents::class)->instance()->getTable()->getActions();
        $names = array_map(fn ($action) => $action->getName(), array_values($actions));

        $this->assertContains('archive', $names);
        $this->assertContains('restore', $names);
        $this->assertSame([], array_values(array_filter(
            $names,
            fn (string $name) => str_contains(strtolower($name), 'force'),
        )));
    }

    public function test_aksi_arsip_cabang_lain_ditolak_lewat_panel(): void
    {
        $student = $this->student($this->schoolB);

        $this->actingAs($this->adminA);

        // Record cabang lain tidak ada di query tabelnya sama sekali.
        Livewire::test(ListStudents::class)
            ->assertTableActionHidden('archive', $student->getKey());

        $this->assertNull(
            Student::withTrashed()->withoutGlobalScopes()->find($student->getKey())->deleted_at,
        );
    }
}
