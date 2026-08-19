<?php

namespace Tests\Feature\Grading;

use App\Enums\GradeConfigStatus;
use App\Enums\GradeType;
use App\Enums\StudentStatus;
use App\Filament\Resources\ReportCardResource\Pages\ListReportCards;
use App\Models\ClassSubject;
use App\Models\Grade;
use App\Models\GradeConfig;
use App\Models\ReportCard;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\Subject;
use App\Services\Grading\GradeConfigVersionManager;
use App\Services\Grading\ReportCardGenerator;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * NILAI-03 — generate & publish rapor; NILAI-01 poin 3 — nilai terkunci
 * setelah rapor diterbitkan.
 */
class ReportCardPublishTest extends GradingTestCase
{
    protected ReportCardGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = app(ReportCardGenerator::class);
    }

    protected function completeGrades(): void
    {
        $this->activeConfig();

        $this->grade(GradeType::Daily, 80);
        $this->grade(GradeType::Daily, 90);
        $this->grade(GradeType::Daily, 70);
        $this->grade(GradeType::Midterm, 75);
        $this->grade(GradeType::Final, 85);
    }

    public function test_generate_creates_a_draft_for_every_active_student(): void
    {
        $this->actingAs($this->teacher);
        $this->completeGrades();

        $this->actingAs($this->homeroom);
        $summary = $this->generator->generateForClass($this->class);

        $this->assertSame(1, $summary['created']);

        $reportCard = ReportCard::query()->firstOrFail();

        $this->assertFalse($reportCard->is_published);
        // JSON tidak membedakan 80 dan 80.0; akses lewat accessor yang meng-cast.
        $this->assertSame(['MTK'], array_keys($reportCard->final_scores));
        $this->assertSame(80.0, $reportCard->scoreFor('MTK'));
        $this->assertSame($this->student->id, $reportCard->student_id);
    }

    public function test_publish_is_blocked_while_a_subject_has_no_final_score(): void
    {
        // NILAI-03 poin 1 — validasi kelengkapan sebelum publish.
        $this->actingAs($this->teacher);
        $this->activeConfig();
        $this->grade(GradeType::Daily, 80); // UTS & UAS belum ada

        $this->actingAs($this->homeroom);
        $this->generator->generateForClass($this->class);

        $reportCard = ReportCard::query()->firstOrFail();

        $this->assertSame(['MTK'], $this->generator->missingSubjectsFor($reportCard));

        Livewire::test(ListReportCards::class)
            ->callTableAction('publish', $reportCard);

        $this->assertFalse($reportCard->fresh()->is_published);
    }

    public function test_publish_locks_the_report_card_and_records_the_publisher(): void
    {
        $this->actingAs($this->teacher);
        $this->completeGrades();

        $this->actingAs($this->homeroom);
        $this->generator->generateForClass($this->class);

        $reportCard = ReportCard::query()->firstOrFail();

        Livewire::test(ListReportCards::class)
            ->callTableAction('publish', $reportCard);

        $reportCard->refresh();

        $this->assertTrue($reportCard->is_published);
        $this->assertNotNull($reportCard->published_at);
        $this->assertSame($this->homeroom->id, $reportCard->published_by);
    }

    public function test_grades_are_locked_once_the_report_card_is_published(): void
    {
        // NILAI-01 poin 3 / NILAI-03 poin 2.
        $this->actingAs($this->teacher);
        $this->completeGrades();

        $grade = Grade::query()->firstOrFail();
        $this->assertFalse($grade->isLocked());
        $this->assertTrue($this->teacher->can('update', $grade));

        $this->actingAs($this->homeroom);
        $this->generator->generateForClass($this->class);
        $this->generator->publish(ReportCard::query()->firstOrFail(), $this->homeroom);

        $this->assertTrue($grade->fresh()->isLocked());

        $this->actingAs($this->teacher);
        $this->assertFalse($this->teacher->can('update', $grade->fresh()));
    }

    public function test_regenerating_skips_published_report_cards(): void
    {
        $this->actingAs($this->teacher);
        $this->completeGrades();

        $this->actingAs($this->homeroom);
        $this->generator->generateForClass($this->class);
        $this->generator->publish(ReportCard::query()->firstOrFail(), $this->homeroom);

        $summary = $this->generator->generateForClass($this->class);

        $this->assertSame(1, $summary['skipped']);
        $this->assertSame(0, $summary['updated']);
    }

    public function test_published_scores_survive_a_later_configuration_change(): void
    {
        // Prinsip keputusan: "Rapor = hasil kalkulasi dari konfigurasi yang
        // digunakan" — bukan dari konfigurasi terbaru.
        $this->actingAs($this->teacher);
        $this->completeGrades();

        $this->actingAs($this->homeroom);
        $this->generator->generateForClass($this->class);
        $this->generator->publish(ReportCard::query()->firstOrFail(), $this->homeroom);

        GradeConfig::query()->update(['components' => [
            ['type' => GradeType::Daily->value, 'weight' => 1.0],
        ]]);

        $this->generator->generateForClass($this->class);

        $this->assertSame(80.0, ReportCard::query()->firstOrFail()->scoreFor('MTK'));
    }

    public function test_publishing_a_whole_class_publishes_every_complete_draft(): void
    {
        $this->actingAs($this->teacher);
        $this->completeGrades();

        $this->actingAs($this->homeroom);
        $this->generator->generateForClass($this->class);

        $result = $this->generator->publishClass($this->class, $this->homeroom);

        $this->assertSame(1, $result['published']);
        $this->assertSame([], $result['blocked']);
        $this->assertTrue(ReportCard::query()->firstOrFail()->is_published);
    }

    public function test_incomplete_subject_is_reported_as_missing(): void
    {
        $this->actingAs($this->teacher);
        $this->completeGrades();

        // Mapel kedua tanpa nilai sama sekali.
        $second = Subject::factory()->create(['school_id' => $this->school->id, 'code' => 'BIN']);
        ClassSubject::factory()->create([
            'school_id' => $this->school->id,
            'class_id' => $this->class->id,
            'subject_id' => $second->id,
            'teacher_id' => $this->teacher->id,
            'academic_year_id' => $this->year->id,
        ]);

        $this->actingAs($this->homeroom);
        $summary = $this->generator->generateForClass($this->class);

        $this->assertSame([$this->student->full_name], array_keys($summary['incomplete']));
        $this->assertSame(['BIN'], array_keys($summary['incomplete'][$this->student->full_name]));
        $this->assertStringContainsString(
            'Belum ada nilai sumatif',
            $summary['incomplete'][$this->student->full_name]['BIN'],
        );

        $reportCard = ReportCard::query()->firstOrFail();
        $this->assertSame(['BIN'], $this->generator->missingSubjectsFor($reportCard));
    }

    public function test_a_report_card_cannot_be_published_twice(): void
    {
        $this->actingAs($this->teacher);
        $this->completeGrades();

        $this->actingAs($this->homeroom);
        $this->generator->generateForClass($this->class);

        $reportCard = ReportCard::query()->firstOrFail();
        $this->generator->publish($reportCard, $this->homeroom);

        $this->assertFalse($this->homeroom->can('publish', $reportCard->fresh()));
    }

    public function test_generate_skips_students_who_are_no_longer_active(): void
    {
        $this->actingAs($this->teacher);
        $this->completeGrades();

        $this->student->update(['status' => StudentStatus::Graduated]);

        $this->actingAs($this->homeroom);
        $summary = $this->generator->generateForClass($this->class);

        $this->assertSame(0, $summary['created']);
        $this->assertSame(0, ReportCard::query()->count());
    }

    public function test_publishing_the_last_report_card_locks_the_configuration(): void
    {
        // Keputusan butir 4 — "LOCKED setelah rapor/finalisasi semester."
        $this->actingAs($this->teacher);
        $this->completeGrades();

        $config = GradeConfig::query()->firstOrFail();
        $this->assertSame(GradeConfigStatus::Active, $config->status);

        $this->actingAs($this->homeroom);
        $this->generator->generateForClass($this->class);
        $this->generator->publish(ReportCard::query()->firstOrFail(), $this->homeroom);

        $config->refresh();

        $this->assertSame(GradeConfigStatus::Locked, $config->status);
        $this->assertNotNull($config->locked_at);
    }

    public function test_configuration_stays_active_while_a_classmate_is_unpublished(): void
    {
        // Mengunci pada rapor siswa pertama akan menghentikan penilaian siswa
        // lain di kelas yang sama — nilai baru tidak lagi mendapat snapshot.
        $classmate = Student::factory()->create(['school_id' => $this->school->id]);

        StudentClass::factory()->create([
            'school_id' => $this->school->id,
            'student_id' => $classmate->id,
            'class_id' => $this->class->id,
            'academic_year_id' => $this->year->id,
        ]);

        $this->actingAs($this->teacher);
        $this->completeGrades();

        $this->actingAs($this->homeroom);
        $this->generator->generateForClass($this->class);
        $this->generator->publish(
            ReportCard::query()->where('student_id', $this->student->id)->firstOrFail(),
            $this->homeroom,
        );

        $this->assertSame(GradeConfigStatus::Active, GradeConfig::query()->firstOrFail()->status);

        // Teman sekelas dinilai dan diterbitkan → kebijakan selesai dipakai.
        $this->actingAs($this->teacher);
        $this->grade(GradeType::Daily, 70, student: $classmate);
        $this->grade(GradeType::Midterm, 75, student: $classmate);
        $this->grade(GradeType::Final, 80, student: $classmate);

        $this->actingAs($this->homeroom);
        $this->generator->generateForClass($this->class);
        $this->generator->publish(
            ReportCard::query()->where('student_id', $classmate->id)->firstOrFail(),
            $this->homeroom,
        );

        $this->assertSame(GradeConfigStatus::Locked, GradeConfig::query()->firstOrFail()->status);
    }

    /**
     * Kebijakan bobot berganti versi setelah Harian dinilai, sehingga bobot
     * efektifnya menjadi 0.40 + 0.25 + 0.25 = 0.90.
     */
    protected function gradesSpanningTwoVersions(): void
    {
        $this->activeConfig();

        $this->grade(GradeType::Daily, 80);

        $manager = app(GradeConfigVersionManager::class);

        $next = $manager->createNextVersion(GradeConfig::query()->latest('version')->firstOrFail(), $this->admin);
        $next->forceFill(['components' => [
            ['type' => GradeType::Daily->value, 'weight' => 0.50],
            ['type' => GradeType::Midterm->value, 'weight' => 0.25],
            ['type' => GradeType::Final->value, 'weight' => 0.25],
        ]])->save();
        $manager->activate($next);

        $this->grade(GradeType::Midterm, 75);
        $this->grade(GradeType::Final, 85);
    }

    public function test_generate_summary_explains_an_inconsistent_weight_snapshot(): void
    {
        // Seluruh komponen sudah diinput, jadi "belum lengkap" saja menyesatkan —
        // wali kelas perlu tahu bahwa penyebabnya bobot lintas-versi.
        $this->actingAs($this->teacher);
        $this->gradesSpanningTwoVersions();

        $this->actingAs($this->homeroom);
        $summary = $this->generator->generateForClass($this->class);

        $reason = $summary['incomplete'][$this->student->full_name]['MTK'] ?? '';

        $this->assertStringContainsString('0.90', $reason);
        $this->assertStringContainsString('1.00', $reason);
    }

    public function test_generate_summary_names_the_component_that_is_still_missing(): void
    {
        $this->actingAs($this->teacher);
        $this->activeConfig();

        $this->grade(GradeType::Daily, 80);
        $this->grade(GradeType::Midterm, 75);
        // UAS belum diinput.

        $this->actingAs($this->homeroom);
        $summary = $this->generator->generateForClass($this->class);

        $this->assertStringContainsString(
            GradeType::Final->value,
            $summary['incomplete'][$this->student->full_name]['MTK'],
        );
    }

    public function test_generate_notification_carries_the_reason_to_the_homeroom_teacher(): void
    {
        $this->actingAs($this->teacher);
        $this->gradesSpanningTwoVersions();

        $this->actingAs($this->homeroom);

        Livewire::test(ListReportCards::class)
            ->callAction('generate', ['class_id' => $this->class->id]);

        $body = $this->lastNotificationBody();

        $this->assertStringContainsString($this->student->full_name, $body);
        $this->assertStringContainsString('MTK', $body);
        $this->assertStringContainsString('0.90', $body);
    }

    public function test_generate_summary_reports_a_component_the_configuration_ignores(): void
    {
        // C-6 — nilai SKILL sumatif tersimpan, tetapi konfigurasi hanya
        // mengenal Harian/UTS/UAS.
        $this->actingAs($this->teacher);
        $this->completeGrades();
        $this->grade(GradeType::Skill, 100);

        $this->actingAs($this->homeroom);
        $summary = $this->generator->generateForClass($this->class);

        $this->assertSame(['MTK' => [GradeType::Skill->value]], $summary['ignored']);

        // Rapornya sendiri tetap lengkap — C-6 hanya memberi tahu.
        $this->assertSame([], $summary['incomplete']);
        $this->assertSame(1, $summary['created']);
    }

    public function test_generate_summary_has_nothing_to_report_when_every_component_is_configured(): void
    {
        $this->actingAs($this->teacher);
        $this->completeGrades();

        $this->actingAs($this->homeroom);

        $this->assertSame([], $this->generator->generateForClass($this->class)['ignored']);
    }

    public function test_an_ignored_component_is_listed_once_for_the_whole_class(): void
    {
        // Grade Config berlaku per mapel, jadi komponen yang sama terabaikan
        // bagi semua siswa. Ringkasannya tidak boleh mengulang pesan itu
        // sebanyak jumlah siswa.
        $classmate = Student::factory()->create(['school_id' => $this->school->id]);

        StudentClass::factory()->create([
            'school_id' => $this->school->id,
            'student_id' => $classmate->id,
            'class_id' => $this->class->id,
            'academic_year_id' => $this->year->id,
        ]);

        $this->actingAs($this->teacher);
        $this->completeGrades();
        $this->grade(GradeType::Skill, 100);

        // Teman sekelas dinilai dengan konfigurasi yang sama.
        $this->grade(GradeType::Daily, 80, student: $classmate);
        $this->grade(GradeType::Midterm, 75, student: $classmate);
        $this->grade(GradeType::Final, 85, student: $classmate);
        $this->grade(GradeType::Skill, 95, student: $classmate);

        $this->actingAs($this->homeroom);
        $summary = $this->generator->generateForClass($this->class);

        $this->assertSame(['MTK' => [GradeType::Skill->value]], $summary['ignored']);
        $this->assertSame(2, $summary['created']);
    }

    public function test_generate_notification_names_the_ignored_component(): void
    {
        $this->actingAs($this->teacher);
        $this->completeGrades();
        $this->grade(GradeType::Skill, 100);

        $this->actingAs($this->homeroom);

        Livewire::test(ListReportCards::class)
            ->callAction('generate', ['class_id' => $this->class->id]);

        $body = $this->lastNotificationBody();

        $this->assertStringContainsString('MTK', $body);
        $this->assertStringContainsString(GradeType::Skill->label(), $body);
        $this->assertStringContainsString('tidak ada di Grade Config', $body);
    }

    /**
     * NFR 1.4 — "Response time API < 500ms untuk 95% request" pada VPS 2C/2GB.
     *
     * Yang dijaga di sini bukan angka mutlaknya, melainkan bentuknya: menambah
     * siswa tidak boleh menambah query secara sebanding. Yang tersisa per siswa
     * hanyalah penulisan rapornya sendiri, yang memang tidak terhindarkan.
     */
    public function test_generating_does_not_issue_queries_per_student(): void
    {
        $this->actingAs($this->teacher);
        $this->completeGrades();

        $this->actingAs($this->homeroom);

        DB::enableQueryLog();
        $this->generator->generateForClass($this->class);
        $withOneStudent = count(DB::getQueryLog());

        $this->actingAs($this->teacher);

        foreach (range(1, 4) as $ignored) {
            $classmate = Student::factory()->create(['school_id' => $this->school->id]);

            StudentClass::factory()->create([
                'school_id' => $this->school->id,
                'student_id' => $classmate->id,
                'class_id' => $this->class->id,
                'academic_year_id' => $this->year->id,
            ]);

            $this->grade(GradeType::Daily, 80, student: $classmate);
            $this->grade(GradeType::Midterm, 75, student: $classmate);
            $this->grade(GradeType::Final, 85, student: $classmate);
        }

        $this->actingAs($this->homeroom);

        DB::flushQueryLog();
        $this->generator->generateForClass($this->class);
        $withFiveStudents = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Empat siswa tambahan boleh menambah paling banyak satu query masing-
        // masing — penulisan rapornya sendiri. Sebelum nilai dan rapor dimuat
        // sekali per kelas, tiap siswa menambah empat pembacaan tersendiri.
        $this->assertLessThanOrEqual(
            $withOneStudent + 4,
            $withFiveStudents,
            "Query tumbuh dari {$withOneStudent} menjadi {$withFiveStudents} untuk 4 siswa tambahan.",
        );

        // Hasilnya tetap benar, bukan sekadar sedikit query.
        $this->assertSame(5, ReportCard::query()->count());
        $this->assertSame(80.0, ReportCard::query()->firstOrFail()->scoreFor('MTK'));
    }

    /**
     * Isi notifikasi Filament yang terakhir dikirim. Notification::send()
     * menumpuknya di sesi, dan membacanya lewat komponen Notifications akan
     * ikut menguras antrean — jadi sesinya dibaca langsung.
     */
    protected function lastNotificationBody(): string
    {
        return (string) (collect(session('filament.notifications', []))->last()['body'] ?? '');
    }
}
