<?php

namespace Tests\Feature\Grading;

use App\Enums\GradeType;
use App\Enums\RoleName;
use App\Filament\Pages\InputNilai;
use App\Filament\Resources\GradeConfigResource;
use App\Filament\Resources\GradeResource;
use App\Filament\Resources\ReportCardResource;
use App\Models\Grade;
use App\Models\GradeConfig;
use App\Models\ReportCard;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Grading\ReportCardGenerator;

/**
 * PRD 1.1.2 — baris "Input Nilai" dan "Generate Rapor".
 *
 * Sekaligus menguji hasil verifikasi K-2: kewenangan menulis grade_configs
 * adalah SCHOOL_ADMIN/SUPER_ADMIN, bukan pemegang `grade.manage`.
 */
class GradingRbacTest extends GradingTestCase
{
    protected function reportCard(): ReportCard
    {
        $this->actingAs($this->teacher);
        $this->activeConfig();
        $this->grade(GradeType::Daily, 80);
        $this->grade(GradeType::Midterm, 80);
        $this->grade(GradeType::Final, 80);

        $this->actingAs($this->homeroom);
        app(ReportCardGenerator::class)->generateForClass($this->class);

        return ReportCard::query()->firstOrFail();
    }

    public function test_school_admin_can_open_every_academic_page(): void
    {
        $this->actingAs($this->admin);

        $this->get(GradeConfigResource::getUrl('index'))->assertSuccessful();
        $this->get(GradeResource::getUrl('index'))->assertSuccessful();
        $this->get(ReportCardResource::getUrl('index'))->assertSuccessful();
        $this->assertTrue(InputNilai::canAccess());
    }

    public function test_teacher_may_input_grades_but_not_configure_weights(): void
    {
        // Matriks: GURU → Input Nilai ✅. Tetapi NILAI-05 & API 4.8 menetapkan
        // konfigurasi bobot sebagai kewenangan Admin Sekolah.
        $config = $this->activeConfig();

        $this->actingAs($this->teacher);

        $this->get(GradeResource::getUrl('index'))->assertSuccessful();
        $this->assertTrue(InputNilai::canAccess());

        $this->assertTrue($this->teacher->can('create', Grade::class));
        $this->assertFalse($this->teacher->can('create', GradeConfig::class));
        $this->assertFalse($this->teacher->can('update', $config));
        $this->assertFalse($this->teacher->can('activate', $config));
        $this->assertFalse($this->teacher->can('lock', $config));
    }

    public function test_teacher_can_read_but_not_write_grade_configs(): void
    {
        // API 4.8 — GET /grade-configs ber-Auth Level "Auth".
        $config = $this->activeConfig();

        $this->actingAs($this->teacher);

        $this->assertTrue($this->teacher->can('viewAny', GradeConfig::class));
        $this->assertTrue($this->teacher->can('view', $config));
        $this->get(GradeConfigResource::getUrl('index'))->assertSuccessful();
    }

    public function test_plain_teacher_has_no_access_to_report_cards(): void
    {
        // Matriks "Generate Rapor" → ✅(Wali); peran GURU tidak memegang
        // izin report_card.* sama sekali.
        $reportCard = $this->reportCard();

        $this->actingAs($this->teacher);

        $this->assertFalse($this->teacher->can('viewAny', ReportCard::class));
        $this->assertFalse($this->teacher->can('view', $reportCard));
        $this->assertFalse($this->teacher->can('publish', $reportCard));
        $this->get(ReportCardResource::getUrl('index'))->assertForbidden();
    }

    public function test_homeroom_teacher_can_generate_and_publish_its_own_class(): void
    {
        $reportCard = $this->reportCard();

        $this->actingAs($this->homeroom);

        $this->assertTrue($this->homeroom->can('generate', [ReportCard::class, $this->class]));
        $this->assertTrue($this->homeroom->can('publish', $reportCard));
        $this->get(ReportCardResource::getUrl('index'))->assertSuccessful();
    }

    public function test_homeroom_teacher_cannot_publish_another_class(): void
    {
        $reportCard = $this->reportCard();

        $stranger = User::factory()->forSchool($this->school)->withRole(RoleName::WaliKelas)->create();
        $otherClass = SchoolClass::factory()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->year->id,
            'homeroom_teacher_id' => $stranger->id,
        ]);

        $this->actingAs($stranger);

        $this->assertTrue($stranger->can('generate', [ReportCard::class, $otherClass]));
        $this->assertFalse($stranger->can('generate', [ReportCard::class, $this->class]));
        $this->assertFalse($stranger->can('publish', $reportCard));
    }

    public function test_kepala_sekolah_has_read_only_access(): void
    {
        $reportCard = $this->reportCard();
        $config = GradeConfig::query()->firstOrFail();

        $kepala = User::factory()->forSchool($this->school)->withRole(RoleName::KepalaSekolah)->create();

        $this->actingAs($kepala);

        $this->get(GradeResource::getUrl('index'))->assertSuccessful();
        $this->get(ReportCardResource::getUrl('index'))->assertSuccessful();

        $this->assertTrue($kepala->can('view', $reportCard));
        $this->assertFalse($kepala->can('publish', $reportCard));
        $this->assertFalse($kepala->can('update', $config));
        $this->assertFalse($kepala->can('create', Grade::class));
        $this->assertFalse(InputNilai::canAccess());
    }

    public function test_bendahara_has_no_academic_access(): void
    {
        $bendahara = User::factory()->forSchool($this->school)->withRole(RoleName::Bendahara)->create();

        $this->actingAs($bendahara);

        $this->get(GradeResource::getUrl('index'))->assertForbidden();
        $this->get(GradeConfigResource::getUrl('index'))->assertForbidden();
        $this->get(ReportCardResource::getUrl('index'))->assertForbidden();
        $this->assertFalse(InputNilai::canAccess());
    }

    /**
     * Matriks: SISWA ⭕ dan ORTU ⭕ pada "Generate Rapor"; keduanya ❌ pada
     * "Input Nilai".
     *
     * Izin modulnya memang ada, tetapi sejak butir 170 izin itu tidak lagi
     * berarti "seluruh rapor cabang": siswa hanya berhak atas rapornya
     * sendiri, dan orang tua hanya atas rapor anaknya. Yang diuji di sini
     * karena itu keduanya — izin modulnya tetap dipegang, dan barisnya
     * dibatasi.
     */
    public function test_student_and_parent_may_read_only_their_own_report_card(): void
    {
        $reportCard = $this->reportCard();
        $student = $reportCard->student;

        $studentUser = User::factory()->forSchool($this->school)->withRole(RoleName::Siswa)->create();
        $parentUser = User::factory()->forSchool($this->school)->withRole(RoleName::OrangTua)->create();

        // Sebelum ditautkan, keduanya memegang izin modulnya tetapi tidak
        // berhak atas baris ini.
        foreach ([$studentUser, $parentUser] as $user) {
            $this->assertTrue($user->can('viewAny', ReportCard::class));
            $this->assertFalse($user->can('view', $reportCard));
        }

        $student->forceFill([
            'user_id' => $studentUser->getKey(),
            'parent_user_id' => $parentUser->getKey(),
        ])->save();

        $reportCard->refresh();

        foreach ([$studentUser, $parentUser] as $user) {
            $this->assertTrue($user->can('view', $reportCard));
            // Membaca tetap tidak berarti boleh menerbitkan atau menilai.
            $this->assertFalse($user->can('publish', $reportCard));
            $this->assertFalse($user->can('create', Grade::class));
        }
    }

    public function test_super_admin_bypasses_every_academic_gate(): void
    {
        $reportCard = $this->reportCard();
        $config = GradeConfig::query()->firstOrFail();

        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin);

        $this->assertTrue($superAdmin->can('update', $config));
        $this->assertTrue($superAdmin->can('publish', $reportCard));
        $this->get(GradeConfigResource::getUrl('index'))->assertSuccessful();
    }
}
