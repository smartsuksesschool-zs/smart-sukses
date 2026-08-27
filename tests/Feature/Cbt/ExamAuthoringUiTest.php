<?php

namespace Tests\Feature\Cbt;

use App\Enums\ExamQuestionType;
use App\Enums\ExamStatus;
use App\Enums\RoleName;
use App\Filament\Resources\ExamResource;
use App\Filament\Resources\ExamResource\Pages\CreateExam;
use App\Filament\Resources\ExamResource\Pages\EditExam;
use App\Filament\Resources\ExamResource\Pages\ListExams;
use App\Filament\Resources\ExamResource\Pages\ViewExam;
use App\Filament\Resources\ExamResource\RelationManagers\QuestionsRelationManager;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamOption;
use App\Models\ExamQuestion;
use App\Models\User;
use App\Services\Cbt\ExamPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Cbt\Concerns\BuildsExamFixture;
use Tests\TestCase;

/**
 * Permukaan penulisan soal, diuji sebagai UI — bukan hanya sebagai policy.
 *
 * Policy yang benar tetapi tombol yang salah adalah cacat yang lolos dari test
 * kewenangan: menu yang terlihat lalu menolak dengan 403 adalah kebocoran
 * informasi tentang apa yang ada di sistem, dan tombol yang terlihat pada ujian
 * yang sudah dikerjakan adalah undangan menghancurkan pekerjaan siswa. Karena
 * itu yang diuji di sini adalah apa yang benar-benar dirender (butir 301).
 */
class ExamAuthoringUiTest extends TestCase
{
    use BuildsExamFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildExamFixture();
    }

    // ------------------------------------------------------------- navigasi

    /**
     * Butir 293 — Bendahara tidak melihat menunya sama sekali. Menerima 403
     * setelah mengklik sudah memberi tahu bahwa fiturnya ada.
     */
    public function test_the_menu_is_hidden_from_roles_without_grade_access(): void
    {
        foreach ([RoleName::Bendahara] as $role) {
            $this->actingAs($this->userIn($this->schoolA, $role));

            $this->assertFalse(ExamResource::canAccess(), $role->value.' masih melihat menu Ujian Online.');

            // Filament membangun navigasinya dari resource yang `canAccess()`,
            // jadi menu itu tidak dirender — dan alamatnya pun tertutup.
            $this->get(ExamResource::getUrl('index'))->assertForbidden();
        }
    }

    public function test_the_menu_is_visible_to_roles_with_grade_access(): void
    {
        foreach ([RoleName::SchoolAdmin, RoleName::KepalaSekolah, RoleName::Guru, RoleName::WaliKelas] as $role) {
            $this->actingAs($this->userIn($this->schoolA, $role));

            $this->assertTrue(ExamResource::canAccess(), $role->value.' tidak melihat menu Ujian Online.');
        }
    }

    public function test_the_menu_sits_in_the_existing_academic_group(): void
    {
        $this->assertSame('Akademik', ExamResource::getNavigationGroup());
        $this->assertSame('Ujian Online', ExamResource::getNavigationLabel());
    }

    public function test_a_branchless_school_level_account_may_not_reach_the_list(): void
    {
        $orphan = User::factory()->withRole(RoleName::SchoolAdmin)->create(['school_id' => null]);

        $this->actingAs($orphan);

        // Izinnya ada, tetapi tidak ada satu baris pun yang menjadi miliknya.
        Livewire::test(ListExams::class)->assertCanNotSeeTableRecords([$this->examIn($this->classSubjectA)]);
    }

    // ----------------------------------------------------------------- daftar

    public function test_the_list_shows_only_the_own_branch(): void
    {
        $mine = $this->examIn($this->classSubjectA, ['title' => 'UH Bab 1']);
        $theirs = $this->examIn($this->classSubjectB, ['title' => 'UH Seberang']);

        $this->actingAs($this->userIn($this->schoolA, RoleName::SchoolAdmin));

        Livewire::test(ListExams::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_the_list_never_shows_the_answer_key(): void
    {
        $exam = $this->readyExam();

        $this->actingAs($this->teacherA);

        Livewire::test(ListExams::class)
            ->assertCanSeeTableRecords([$exam])
            ->assertDontSee('is_correct');
    }

    // ---------------------------------------------------------------- membuat

    public function test_a_teacher_creates_an_exam_for_a_class_they_teach(): void
    {
        $this->actingAs($this->teacherA);

        Livewire::test(CreateExam::class)
            ->fillForm([
                'class_subject_id' => $this->classSubjectA->getKey(),
                'title' => 'Ulangan Harian Bab 2',
                'duration_minutes' => 45,
                'available_from' => now()->addDay()->format('Y-m-d H:i:s'),
                'available_until' => now()->addDays(2)->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $exam = Exam::query()->firstWhere('title', 'Ulangan Harian Bab 2');

        $this->assertNotNull($exam);
        // Lahir sebagai draf, dengan cabang dan tahun ajaran yang diturunkan —
        // tidak satu pun dari ketiganya berasal dari form (butir 295, 296).
        $this->assertSame(ExamStatus::Draft, $exam->status);
        $this->assertSame($this->schoolA->getKey(), (int) $exam->school_id);
        $this->assertSame($this->yearA->getKey(), (int) $exam->academic_year_id);
        $this->assertSame($this->teacherA->getKey(), (int) $exam->created_by);
    }

    public function test_a_school_admin_creates_for_any_class_subject_of_its_own_branch(): void
    {
        // Kelas-mapel yang diampu guru lain — Admin Sekolah tetap berwenang,
        // sesuai matriks 1.1.2 yang memberinya akses penuh Input Nilai.
        $taughtByAnother = $this->classSubjectIn(
            $this->schoolA,
            $this->classA,
            $this->userIn($this->schoolA, RoleName::Guru),
        );

        $admin = $this->userIn($this->schoolA, RoleName::SchoolAdmin);

        $this->actingAs($admin);

        Livewire::test(CreateExam::class)
            ->fillForm($this->validExamData([
                'class_subject_id' => $taughtByAnother->getKey(),
                'title' => 'Ujian dibuat admin',
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $exam = Exam::query()->firstWhere('title', 'Ujian dibuat admin');

        $this->assertNotNull($exam);
        $this->assertSame($this->schoolA->getKey(), (int) $exam->school_id);
        $this->assertSame($admin->getKey(), (int) $exam->created_by);
    }

    /**
     * Siswa dan orang tua ditolak seluruh panel (RoleName::canAccessAdminPanel),
     * sehingga penulisan soal tertutup bagi mereka satu tingkat lebih awal
     * daripada policy — mereka tidak pernah sampai ke resource-nya.
     */
    public function test_students_and_parents_cannot_reach_the_panel_at_all(): void
    {
        $panel = filament()->getPanel('admin');

        foreach ([RoleName::Siswa, RoleName::OrangTua] as $role) {
            $user = $this->userIn($this->schoolA, $role);

            $this->assertFalse($user->canAccessPanel($panel), $role->value.' dapat membuka panel.');
            $this->assertFalse($user->can('viewAny', Exam::class), $role->value.' dapat melihat daftar ujian.');
            $this->assertFalse($user->can('create', Exam::class), $role->value.' dapat membuat ujian.');
        }
    }

    /**
     * Butir 294 — daftar pilihan bukan pagar. Yang dikirim Livewire dapat
     * berupa apa saja, dan yang menolaknya adalah policy.
     */
    public function test_a_teacher_cannot_create_for_a_class_subject_they_do_not_teach(): void
    {
        $foreignToTeacher = $this->classSubjectIn(
            $this->schoolA,
            $this->classA,
            $this->userIn($this->schoolA, RoleName::Guru),
        );

        $this->actingAs($this->teacherA);

        Livewire::test(CreateExam::class)
            ->fillForm($this->validExamData(['class_subject_id' => $foreignToTeacher->getKey()]))
            ->call('create')
            ->assertHasFormErrors(['class_subject_id']);

        $this->assertDatabaseCount('exams', 0);
    }

    public function test_a_tampered_cross_school_class_subject_is_rejected(): void
    {
        $this->actingAs($this->userIn($this->schoolA, RoleName::SchoolAdmin));

        Livewire::test(CreateExam::class)
            ->fillForm($this->validExamData(['class_subject_id' => $this->classSubjectB->getKey()]))
            ->call('create')
            ->assertHasFormErrors(['class_subject_id']);

        $this->assertDatabaseCount('exams', 0);
    }

    public function test_the_class_subject_options_are_filtered_per_role(): void
    {
        $foreign = $this->classSubjectIn(
            $this->schoolA,
            $this->classA,
            $this->userIn($this->schoolA, RoleName::Guru),
        );

        $this->actingAs($this->teacherA);
        $teacherSees = array_keys(ExamResource::classSubjectOptions($this->schoolA->getKey()));

        $this->actingAs($this->userIn($this->schoolA, RoleName::SchoolAdmin));
        $adminSees = array_keys(ExamResource::classSubjectOptions($this->schoolA->getKey()));

        $this->assertSame([$this->classSubjectA->getKey()], $teacherSees);
        $this->assertEqualsCanonicalizing(
            [$this->classSubjectA->getKey(), $foreign->getKey()],
            $adminSees,
        );
        // Cabang seberang tidak pernah masuk daftar siapa pun.
        $this->assertNotContains($this->classSubjectB->getKey(), $adminSees);
    }

    public function test_a_head_teacher_cannot_open_the_create_page(): void
    {
        $this->actingAs($this->userIn($this->schoolA, RoleName::KepalaSekolah));

        $this->assertFalse(ExamResource::canCreate());
    }

    public function test_the_window_must_close_after_it_opens(): void
    {
        $this->actingAs($this->teacherA);

        Livewire::test(CreateExam::class)
            ->fillForm($this->validExamData([
                'available_from' => now()->addDays(2)->format('Y-m-d H:i:s'),
                'available_until' => now()->addDay()->format('Y-m-d H:i:s'),
            ]))
            ->call('create')
            ->assertHasFormErrors(['available_until']);
    }

    // ----------------------------------------------------------- mengedit

    public function test_a_draft_exam_can_be_edited(): void
    {
        $exam = $this->examIn($this->classSubjectA);

        $this->actingAs($this->teacherA);

        Livewire::test(EditExam::class, ['record' => $exam->getKey()])
            ->fillForm(['title' => 'Judul Baru'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Judul Baru', $exam->fresh()->title);
    }

    /**
     * Butir 290 — tombol yang tersembunyi bukan pagar; alamat halamannya tetap
     * dapat dibuka. Yang menutupnya adalah policy.
     */
    public function test_a_published_exam_cannot_be_edited_even_by_url(): void
    {
        $exam = $this->publishedExam();

        $this->actingAs($this->teacherA);

        Livewire::test(ListExams::class)->assertTableActionHidden('edit', $exam);

        $this->get(ExamResource::getUrl('edit', ['record' => $exam]))->assertForbidden();
    }

    public function test_the_view_page_stays_open_to_a_head_teacher(): void
    {
        $exam = $this->publishedExam();

        $this->actingAs($this->userIn($this->schoolA, RoleName::KepalaSekolah));

        Livewire::test(ViewExam::class, ['record' => $exam->getKey()])
            ->assertOk()
            ->assertSee($exam->title);
    }

    // ------------------------------------------------------- aksi siklus hidup

    public function test_the_publish_action_appears_only_on_drafts(): void
    {
        $draft = $this->readyExam();

        $this->actingAs($this->teacherA);

        Livewire::test(ListExams::class)
            ->assertTableActionVisible('publish', $draft)
            ->assertTableActionHidden('unpublish', $draft)
            ->assertTableActionHidden('close', $draft);
    }

    public function test_publishing_from_the_table_moves_the_exam(): void
    {
        $draft = $this->readyExam();

        $this->actingAs($this->teacherA);

        Livewire::test(ListExams::class)->callTableAction('publish', $draft);

        $this->assertSame(ExamStatus::Published, $draft->fresh()->status);
    }

    public function test_publishing_an_incomplete_exam_reports_the_reason_and_keeps_the_draft(): void
    {
        $incomplete = $this->examIn($this->classSubjectA);

        $this->actingAs($this->teacherA);

        Livewire::test(ListExams::class)->callTableAction('publish', $incomplete);

        $this->assertSame(ExamStatus::Draft, $incomplete->fresh()->status);
    }

    public function test_a_published_exam_offers_pull_back_and_close(): void
    {
        $exam = $this->publishedExam();

        $this->actingAs($this->teacherA);

        Livewire::test(ListExams::class)
            ->assertTableActionHidden('publish', $exam)
            ->assertTableActionVisible('unpublish', $exam)
            ->assertTableActionVisible('close', $exam);
    }

    /**
     * Butir 287 — begitu ada satu pengerjaan, tarik-kembali menghilang dari
     * layar, bukan hanya gagal ketika ditekan.
     */
    public function test_an_attempted_exam_no_longer_offers_pull_back(): void
    {
        $exam = $this->publishedExam();
        $this->attemptOn($exam);

        $this->actingAs($this->teacherA);

        Livewire::test(ListExams::class)
            ->assertTableActionHidden('unpublish', $exam->fresh())
            // Menutup tetap boleh: itu justru yang dilakukan setelah ujian usai.
            ->assertTableActionVisible('close', $exam->fresh());
    }

    public function test_a_closed_exam_offers_no_lifecycle_action_at_all(): void
    {
        $exam = $this->publishedExam();
        app(ExamPublisher::class)->close($exam, $this->teacherA);

        $this->actingAs($this->teacherA);

        Livewire::test(ListExams::class)
            ->assertTableActionHidden('publish', $exam->fresh())
            ->assertTableActionHidden('unpublish', $exam->fresh())
            ->assertTableActionHidden('close', $exam->fresh())
            ->assertTableActionHidden('edit', $exam->fresh())
            ->assertTableActionHidden('delete', $exam->fresh());
    }

    public function test_a_head_teacher_sees_no_lifecycle_action(): void
    {
        $exam = $this->publishedExam();

        $this->actingAs($this->userIn($this->schoolA, RoleName::KepalaSekolah));

        Livewire::test(ListExams::class)
            ->assertTableActionHidden('publish', $exam)
            ->assertTableActionHidden('unpublish', $exam)
            ->assertTableActionHidden('close', $exam)
            ->assertTableActionHidden('edit', $exam)
            ->assertTableActionHidden('delete', $exam)
            // Melihat tetap boleh — itu memang kewenangannya.
            ->assertTableActionVisible('view', $exam);
    }

    // --------------------------------------------------------------- hapus

    public function test_an_empty_draft_may_be_deleted(): void
    {
        $exam = $this->examIn($this->classSubjectA);

        $this->actingAs($this->teacherA);

        Livewire::test(ListExams::class)
            ->assertTableActionVisible('delete', $exam)
            ->callTableAction('delete', $exam);

        $this->assertDatabaseMissing('exams', ['id' => $exam->getKey()]);
    }

    /**
     * Butir 291 — skema memang meneruskan penghapusan ke pengerjaan dan
     * jawaban. Justru karena itu aplikasi tidak boleh menyediakan tombolnya.
     */
    public function test_an_attempted_exam_cannot_be_deleted_through_the_ui(): void
    {
        $exam = $this->publishedExam();
        $attempt = $this->attemptOn($exam);

        $this->actingAs($this->teacherA);

        Livewire::test(ListExams::class)->assertTableActionHidden('delete', $exam->fresh());

        $this->assertFalse($this->teacherA->can('delete', $exam->fresh()));
        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->getKey()]);
    }

    public function test_a_published_exam_cannot_be_deleted_through_the_ui(): void
    {
        $exam = $this->publishedExam();

        $this->actingAs($this->teacherA);

        Livewire::test(ListExams::class)->assertTableActionHidden('delete', $exam);
    }

    // -------------------------------------------------- soal & pilihan jawaban

    public function test_a_teacher_adds_a_question_with_its_options(): void
    {
        $exam = $this->examIn($this->classSubjectA);

        $this->actingAs($this->teacherA);

        $this->createQuestion($exam, [
            'question_type' => ExamQuestionType::MultipleChoice->value,
            'points' => 2.5,
            'position' => 1,
            'question_text' => 'Ibu kota Indonesia?',
        ], [
            ['position' => 1, 'option_text' => 'Jakarta', 'is_correct' => true],
            ['position' => 2, 'option_text' => 'Bandung', 'is_correct' => false],
            ['position' => 3, 'option_text' => 'Surabaya', 'is_correct' => false],
            ['position' => 4, 'option_text' => 'Medan', 'is_correct' => false],
        ])->assertHasNoTableActionErrors();

        $question = ExamQuestion::query()->firstWhere('exam_id', $exam->getKey());

        $this->assertNotNull($question);
        $this->assertSame('2.50', $question->points);
        // Cabangnya diturunkan dari ujian, tidak dari sesi (butir 300).
        $this->assertSame($this->schoolA->getKey(), (int) $question->school_id);
        $this->assertSame(4, $question->options()->count());
        $this->assertSame($this->schoolA->getKey(), (int) $question->options()->first()->school_id);
        $this->assertSame('Jakarta', $question->correctOption?->option_text);
    }

    /**
     * Menyunting soal memperbarui pilihan jawaban yang sudah ada, tidak
     * menghapus lalu membuatnya ulang: id yang sama harus bertahan, karena
     * jawaban siswa menunjuk id itu.
     */
    public function test_editing_a_question_updates_its_options_in_place(): void
    {
        $exam = $this->readyExam();
        $question = $exam->questions()->first();
        $optionIds = $question->options()->orderBy('position')->pluck('id')->all();

        $this->actingAs($this->teacherA);

        $component = $this->questions($exam)->mountTableAction('edit', $question);

        $keys = array_keys((array) $component->get('mountedTableActionsData.0.options'));

        $component->setTableActionData([
            'question_text' => 'Pertanyaan yang sudah diperbaiki',
            'options' => [
                $keys[0] => ['id' => $optionIds[0], 'position' => 1, 'option_text' => 'Benar sekali', 'is_correct' => true],
                $keys[1] => ['id' => $optionIds[1], 'position' => 2, 'option_text' => 'Salah', 'is_correct' => false],
            ],
        ])->callMountedTableAction()->assertHasNoTableActionErrors();

        $question->refresh();

        $this->assertSame('Pertanyaan yang sudah diperbaiki', $question->question_text);
        $this->assertSame(2, $question->options()->count());
        $this->assertEqualsCanonicalizing(
            $optionIds,
            $question->options()->pluck('id')->all(),
            'Pilihan jawaban dibuat ulang, bukan diperbarui.',
        );
        $this->assertSame('Benar sekali', $question->fresh()->correctOption?->option_text);
    }

    public function test_a_question_with_two_keys_is_refused_by_the_form(): void
    {
        $exam = $this->examIn($this->classSubjectA);

        $this->actingAs($this->teacherA);

        $this->createQuestion($exam, [
            'question_type' => ExamQuestionType::MultipleChoice->value,
            'points' => 1,
            'position' => 1,
            'question_text' => 'Dua kunci?',
        ], [
            ['position' => 1, 'option_text' => 'A', 'is_correct' => true],
            ['position' => 2, 'option_text' => 'B', 'is_correct' => true],
            ['position' => 3, 'option_text' => 'C', 'is_correct' => false],
            ['position' => 4, 'option_text' => 'D', 'is_correct' => false],
        ])->assertHasTableActionErrors(['options']);

        $this->assertDatabaseCount('exam_questions', 0);
    }

    public function test_a_question_without_a_key_is_refused_by_the_form(): void
    {
        $exam = $this->examIn($this->classSubjectA);

        $this->actingAs($this->teacherA);

        $this->createQuestion($exam, [
            'question_type' => ExamQuestionType::MultipleChoice->value,
            'points' => 1,
            'position' => 1,
            'question_text' => 'Tanpa kunci?',
        ], [
            ['position' => 1, 'option_text' => 'A', 'is_correct' => false],
            ['position' => 2, 'option_text' => 'B', 'is_correct' => false],
            ['position' => 3, 'option_text' => 'C', 'is_correct' => false],
            ['position' => 4, 'option_text' => 'D', 'is_correct' => false],
        ])->assertHasTableActionErrors(['options']);

        $this->assertDatabaseCount('exam_questions', 0);
    }

    public function test_an_empty_option_row_is_refused_by_the_form(): void
    {
        $exam = $this->examIn($this->classSubjectA);

        $this->actingAs($this->teacherA);

        $this->createQuestion($exam, [
            'question_type' => ExamQuestionType::MultipleChoice->value,
            'points' => 1,
            'position' => 1,
            'question_text' => 'Ada yang kosong?',
        ], [
            ['position' => 1, 'option_text' => 'A', 'is_correct' => true],
            ['position' => 2, 'option_text' => 'B', 'is_correct' => false],
            ['position' => 3, 'option_text' => '', 'is_correct' => false],
            ['position' => 4, 'option_text' => 'D', 'is_correct' => false],
        ])->assertHasTableActionErrors();

        $this->assertDatabaseCount('exam_questions', 0);
    }

    /**
     * Butir 266 — uraian tidak boleh dapat dipilih, walaupun skema menerimanya.
     */
    public function test_the_essay_type_is_not_offered(): void
    {
        $this->assertSame(
            [ExamQuestionType::MultipleChoice->value => 'Pilihan Ganda'],
            ExamQuestionType::supportedOptions(),
        );

        $this->assertArrayNotHasKey(ExamQuestionType::Essay->value, ExamQuestionType::supportedOptions());
    }

    public function test_the_question_list_never_reveals_which_option_is_correct(): void
    {
        $exam = $this->readyExam();

        $this->actingAs($this->userIn($this->schoolA, RoleName::KepalaSekolah));

        $this->questions($exam)
            ->assertSee('Pilihan')
            ->assertDontSee('is_correct')
            ->assertDontSee('Kunci');
    }

    /**
     * Butir 298 — kewenangan menulis soal menempel pada ujiannya. Kepala
     * Sekolah melihat daftarnya, tetapi tidak satu pun aksi tulis.
     */
    public function test_a_head_teacher_sees_the_questions_but_no_write_action(): void
    {
        $exam = $this->readyExam();
        $question = $exam->questions()->first();

        $this->actingAs($this->userIn($this->schoolA, RoleName::KepalaSekolah));

        $this->questions($exam)
            ->assertCanSeeTableRecords([$question])
            ->assertTableActionHidden('edit', $question)
            ->assertTableActionHidden('delete', $question)
            ->assertDontSee('Tambah Soal');
    }

    public function test_questions_become_read_only_once_the_exam_is_published(): void
    {
        $exam = $this->publishedExam();
        $question = $exam->questions()->first();

        $this->actingAs($this->teacherA);

        $this->questions($exam)
            ->assertTableActionHidden('edit', $question)
            ->assertTableActionHidden('delete', $question)
            ->assertDontSee('Tambah Soal');
    }

    public function test_questions_and_options_stay_frozen_after_the_first_attempt(): void
    {
        $exam = $this->publishedExam();
        $this->attemptOn($exam);
        $question = $exam->questions()->first();

        $this->actingAs($this->teacherA);

        $this->questions($exam->fresh())
            ->assertTableActionHidden('edit', $question)
            ->assertTableActionHidden('delete', $question)
            ->assertDontSee('Tambah Soal');

        // Dan bukan hanya tombolnya: kewenangannya sendiri yang tertutup.
        $this->assertFalse($this->teacherA->can('update', $exam->fresh()));
    }

    public function test_a_teacher_from_another_class_cannot_touch_the_questions(): void
    {
        $exam = $this->readyExam();
        $stranger = $this->userIn($this->schoolA, RoleName::Guru);
        $question = $exam->questions()->first();

        $this->actingAs($stranger);

        $this->questions($exam)
            ->assertTableActionHidden('edit', $question)
            ->assertDontSee('Tambah Soal');
    }

    public function test_a_teacher_from_another_school_cannot_open_the_questions(): void
    {
        $exam = $this->readyExam();

        $this->actingAs($this->teacherB);

        $this->assertFalse(QuestionsRelationManager::canViewForRecord($exam, ViewExam::class));
    }

    // ------------------------------------------------------------- penunjang

    protected function questions(Exam $exam): Testable
    {
        return Livewire::test(QuestionsRelationManager::class, [
            'ownerRecord' => $exam->fresh(),
            'pageClass' => EditExam::class,
        ]);
    }

    /**
     * Menulis satu soal lewat aksi "Tambah Soal", mengisi baris pilihan jawaban
     * yang **benar-benar dirender** form.
     *
     * `setTableActionData()` menyetel jalur bertitik satu per satu, bukan
     * mengganti array — sehingga baris berkunci angka hanya akan bertambah di
     * samping baris kosong bawaan repeater, dan yang gagal adalah baris kosong
     * itu, bukan aturan yang sedang diuji. Karena itu kuncinya dibaca dulu dari
     * form yang sudah termuat, persis seperti guru yang mengetik ke dalam kolom
     * yang terlihat di layarnya (butir 303).
     *
     * @param  array<string, mixed>  $question
     * @param  array<int, array<string, mixed>>  $optionRows
     */
    protected function createQuestion(Exam $exam, array $question, array $optionRows): Testable
    {
        $component = $this->questions($exam)->mountTableAction('create');

        $keys = array_keys((array) $component->get('mountedTableActionsData.0.options'));

        $options = [];

        foreach (array_values($optionRows) as $index => $row) {
            $options[$keys[$index] ?? $index] = $row;
        }

        return $component
            ->setTableActionData($question + ['options' => $options])
            ->callMountedTableAction();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validExamData(array $overrides = []): array
    {
        return $overrides + [
            'class_subject_id' => $this->classSubjectA->getKey(),
            'title' => 'Ulangan',
            'duration_minutes' => 60,
            'available_from' => now()->addDay()->format('Y-m-d H:i:s'),
            'available_until' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ];
    }

    protected function readyExam(): Exam
    {
        $exam = $this->examIn($this->classSubjectA);

        $question = ExamQuestion::factory()->create([
            'exam_id' => $exam->getKey(),
            'school_id' => $exam->school_id,
        ]);

        ExamOption::factory()->correct()->create([
            'exam_question_id' => $question->getKey(),
            'school_id' => $exam->school_id,
            'option_text' => 'Benar',
        ]);

        ExamOption::factory()->create([
            'exam_question_id' => $question->getKey(),
            'school_id' => $exam->school_id,
            'option_text' => 'Salah',
            'position' => 2,
        ]);

        return $exam->fresh();
    }

    protected function publishedExam(): Exam
    {
        return app(ExamPublisher::class)->publish($this->readyExam(), $this->teacherA);
    }

    protected function attemptOn(Exam $exam): ExamAttempt
    {
        return ExamAttempt::factory()->create([
            'exam_id' => $exam->getKey(),
            'school_id' => $exam->school_id,
            'student_id' => $this->studentA->getKey(),
        ]);
    }
}
