<?php

namespace Tests\Feature\Cbt;

use App\Livewire\Student\StudentExam;
use App\Models\Exam;
use App\Models\ExamOption;
use App\Models\ExamQuestion;
use App\Services\Cbt\ExamAttemptService;
use App\Services\Cbt\ExamPublisher;
use App\Services\Cbt\StudentExamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Cbt\Concerns\BuildsStudentExamFixture;
use Tests\TestCase;

/**
 * Kunci jawaban tidak boleh sampai ke peramban siswa.
 *
 * Ini satu-satunya cacat pada CBT yang **tidak meninggalkan jejak apa pun**.
 * Nilai yang salah akan diprotes; kunci yang bocor hanya membuat seluruh kelas
 * mendapat nilai sempurna, dan tidak ada satu baris log pun yang menyebutkannya.
 *
 * Yang diuji di sini bukan ketiadaan kata "correct" di dalam HTML — itu terlalu
 * mudah lulus. Yang diuji adalah bahwa pilihan yang benar **tidak dapat
 * dibedakan** dari pilihan yang salah: teksnya memang terlihat (siswa harus
 * dapat memilihnya), tetapi markup, atribut, dan payload di sekelilingnya sama
 * persis dengan pilihan lainnya (butir 310).
 */
class StudentExamKeyLeakTest extends TestCase
{
    use BuildsStudentExamFixture, RefreshDatabase;

    /** Penanda unik pada pilihan yang benar. */
    protected const CORRECT_MARKER = 'JAWABAN-BENAR-7Q4X';

    protected const WRONG_MARKERS = ['PENGECOH-A1', 'PENGECOH-B2', 'PENGECOH-C3'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStudentExamFixture();
    }

    // --------------------------------------------------- bentuk data layanan

    /**
     * Sumbernya: yang keluar dari layanan hanya `id` dan `option_text`. Kolom
     * `is_correct` tidak pernah ikut terambil dari database, bukan sekadar
     * tidak dicetak.
     */
    public function test_the_question_payload_carries_only_id_and_text(): void
    {
        [$exam] = $this->markedExam();

        $questions = app(StudentExamService::class)->questionsFor($exam);

        $this->assertNotEmpty($questions);

        foreach ($questions as $question) {
            $this->assertSame(
                ['id', 'number', 'question_text', 'points', 'options'],
                array_keys($question),
            );

            foreach ($question['options'] as $option) {
                $this->assertSame(['id', 'option_text'], array_keys($option));
            }
        }

        // Dan tidak ada satu pun nilai boolean yang tersisa di dalamnya.
        $this->assertStringNotContainsString('is_correct', json_encode($questions));
    }

    public function test_the_options_query_never_selects_the_answer_key(): void
    {
        [$exam] = $this->markedExam();

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(StudentExamService::class)->questionsFor($exam);

        $queries = collect(DB::getQueryLog())->pluck('query');

        DB::disableQueryLog();

        $optionQuery = $queries->first(fn (string $sql) => str_contains($sql, 'exam_options'));

        $this->assertNotNull($optionQuery, 'Pilihan jawaban tidak pernah diambil.');
        $this->assertStringNotContainsString('is_correct', $optionQuery);
        // Kolomnya disebut satu per satu, bukan `select *`.
        $this->assertStringNotContainsString('select * from "exam_options"', $optionQuery);
        $this->assertStringNotContainsString('select * from `exam_options`', $optionQuery);
    }

    // ------------------------------------------------------------ halaman

    public function test_the_taking_page_shows_every_option_text_including_the_correct_one(): void
    {
        [$exam] = $this->markedExam();

        $this->actingAs($this->studentUserA);

        $response = $this->get(route('student.exam', ['examId' => $exam->getKey()]))->assertOk();

        // Teks kuncinya memang harus terlihat — siswa perlu memilihnya.
        $response->assertSee(self::CORRECT_MARKER);

        foreach (self::WRONG_MARKERS as $marker) {
            $response->assertSee($marker);
        }
    }

    public function test_the_taking_page_never_contains_the_answer_key_flag(): void
    {
        [$exam] = $this->markedExam();

        $this->actingAs($this->studentUserA);

        $html = $this->get(route('student.exam', ['examId' => $exam->getKey()]))->assertOk()->getContent();

        foreach (['is_correct', 'isCorrect', 'correctOption', 'correct_option', 'answer_key', 'kunci'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $html,
                "Halaman pengerjaan memuat penanda kunci jawaban: {$needle}",
            );
        }
    }

    /**
     * Test terpenting dalam berkas ini.
     *
     * Setiap pilihan dirender sebagai tombol. Markup tombolnya dinormalisasi —
     * id dan teksnya diganti penanda — lalu dibandingkan satu sama lain. Bila
     * pilihan yang benar membawa **apa pun** yang tidak dibawa pilihan lain
     * (atribut tambahan, kelas berbeda, urutan berbeda, data-* apa pun), hasil
     * normalisasinya tidak akan sama dan test ini gagal.
     */
    public function test_the_correct_option_is_rendered_indistinguishably_from_the_wrong_ones(): void
    {
        [$exam, $question] = $this->markedExam();

        $this->actingAs($this->studentUserA);

        $html = $this->get(route('student.exam', ['examId' => $exam->getKey()]))->assertOk()->getContent();

        $buttons = $this->optionButtons($html, $question->getKey());

        $this->assertCount(4, $buttons, 'Keempat pilihan seharusnya dirender.');

        $normalised = [];

        foreach ($buttons as [$optionId, $markup]) {
            $normalised[] = $this->normalise($markup, $question->getKey(), $optionId);
        }

        $this->assertCount(
            1,
            array_unique($normalised),
            "Pilihan jawaban tidak dirender seragam:\n".implode("\n---\n", array_unique($normalised)),
        );
    }

    /**
     * Snapshot Livewire ikut bolak-balik ke peramban dan dapat dibaca siapa pun
     * yang membuka panel jaringan. Ia tidak boleh membawa kunci jawaban, dan
     * tidak boleh membawa satu pun model soal.
     */
    public function test_the_livewire_snapshot_carries_no_answer_key(): void
    {
        [$exam] = $this->markedExam();

        $this->actingAs($this->studentUserA);

        $html = $this->get(route('student.exam', ['examId' => $exam->getKey()]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/wire:snapshot=/', $html, 'Snapshot Livewire tidak ditemukan.');

        preg_match('/wire:snapshot="([^"]*)"/', $html, $matches);

        $snapshot = html_entity_decode($matches[1] ?? '', ENT_QUOTES);

        $this->assertNotSame('', $snapshot);
        $this->assertStringNotContainsString('is_correct', $snapshot);
        $this->assertStringNotContainsString('ExamOption', $snapshot);
        $this->assertStringNotContainsString('ExamQuestion', $snapshot);
        // Teks soal maupun pilihan pun tidak ikut ke dalam properti publik:
        // yang publik hanya angka (butir 310).
        $this->assertStringNotContainsString(self::CORRECT_MARKER, $snapshot);
    }

    public function test_the_livewire_component_holds_no_model_in_public_state(): void
    {
        [$exam] = $this->markedExam();

        $this->actingAs($this->studentUserA);

        $component = Livewire::test(StudentExam::class, ['examId' => $exam->getKey()])->assertOk();

        foreach (['examId', 'index', 'answers', 'notice'] as $property) {
            $value = $component->get($property);

            $this->assertTrue(
                $value === null || is_scalar($value) || is_array($value),
                "Properti publik {$property} bukan nilai sederhana.",
            );
        }

        // Peta jawaban hanya berisi angka.
        foreach ((array) $component->get('answers') as $questionId => $optionId) {
            $this->assertIsInt($questionId);
            $this->assertIsInt($optionId);
        }
    }

    /**
     * Memilih jawaban memicu perjalanan Livewire tersendiri. Balasannya pun
     * tidak boleh membawa kunci.
     */
    public function test_saving_an_answer_returns_no_answer_key(): void
    {
        [$exam, $question] = $this->markedExam();

        $this->actingAs($this->studentUserA);

        $correctId = $this->correctOptionOf($question)->getKey();

        $component = Livewire::test(StudentExam::class, ['examId' => $exam->getKey()])
            ->call('choose', $question->getKey(), $correctId);

        $html = $component->html();

        $this->assertStringNotContainsString('is_correct', $html);
        // Setelah dipilih, tampilannya memang berubah — tetapi karena **siswa
        // memilihnya**, bukan karena jawabannya benar. Pilihan salah yang
        // dipilih harus berubah dengan cara yang sama persis.
        $this->assertSame(
            [$question->getKey() => $correctId],
            $component->get('answers'),
        );
    }

    public function test_choosing_a_wrong_option_looks_exactly_like_choosing_the_right_one(): void
    {
        [$exam, $question] = $this->markedExam();

        $this->actingAs($this->studentUserA);

        $correct = $this->correctOptionOf($question);
        $wrong = $this->wrongOptionOf($question);

        $afterCorrect = $this->markupAfterChoosing($exam->getKey(), $question->getKey(), $correct->getKey(), $correct);
        $afterWrong = $this->markupAfterChoosing($exam->getKey(), $question->getKey(), $wrong->getKey(), $wrong);

        $this->assertSame(
            $afterCorrect,
            $afterWrong,
            'Memilih jawaban benar terlihat berbeda dari memilih jawaban salah.',
        );
    }

    public function test_the_result_page_reveals_no_per_question_key(): void
    {
        [$exam, $question] = $this->markedExam();

        app(ExamAttemptService::class)->startOrResume($this->studentUserA, $exam->getKey());
        app(ExamAttemptService::class)->saveAnswer(
            $this->studentUserA,
            $exam->getKey(),
            $question->getKey(),
            $this->wrongOptionOf($question)->getKey(),
        );
        app(ExamAttemptService::class)->submit($this->studentUserA, $exam->getKey());

        $this->actingAs($this->studentUserA);

        $html = $this->get(route('student.exam-result', ['examId' => $exam->getKey()]))->assertOk()->getContent();

        // Nilainya terlihat; kuncinya tidak — bahkan setelah dikumpulkan, karena
        // jendela ujian masih terbuka bagi teman sekelasnya (butir 318).
        $this->assertStringContainsString('0,00', $html);
        $this->assertStringNotContainsString(self::CORRECT_MARKER, $html);
        $this->assertStringNotContainsString('is_correct', $html);
    }

    /**
     * Batch S9.3 menambahkan bahasa Inggris ke halaman ini. Bahasa hanya
     * mengganti label; ia tidak boleh menambah satu pun kolom pada muatan yang
     * dikirim ke peramban. Karena itu keempat pemeriksaan kebocoran diulang
     * dalam bahasa Inggris — kalau tidak, cukup satu terjemahan yang kelewat
     * teliti untuk membocorkan kunci pada bahasa yang tidak pernah diuji.
     */
    public function test_the_answer_key_stays_hidden_when_the_page_is_in_english(): void
    {
        [$exam, $question] = $this->markedExam();

        $this->studentUserA->forceFill(['locale' => 'en'])->save();
        $this->actingAs($this->studentUserA->fresh());

        $html = $this->get(route('student.exam', ['examId' => $exam->getKey()]))->assertOk()->getContent();

        // Halamannya memang berbahasa Inggris.
        $this->assertStringContainsString('Submit Exam', $html);

        // Teks kuncinya terlihat, penandanya tidak.
        $this->assertStringContainsString(self::CORRECT_MARKER, $html);
        $this->assertStringNotContainsString('is_correct', $html);
        $this->assertStringNotContainsString('correct":true', $html);

        // Dan pilihan yang benar tetap tidak dapat dibedakan dari yang salah.
        $buttons = $this->optionButtons($html, $question->getKey());

        $this->assertCount(4, $buttons);

        $normalised = array_map(
            fn (array $button) => $this->normalise($button[1], $question->getKey(), $button[0]),
            $buttons,
        );

        $this->assertCount(1, array_unique($normalised), 'options must render identically in English too');
    }

    // ------------------------------------------------------------- penunjang

    /**
     * Ujian dengan satu soal berpilihan empat, kuncinya diberi penanda unik.
     *
     * @return array{0: Exam, 1: ExamQuestion}
     */
    protected function markedExam(): array
    {
        $exam = $this->examIn($this->classSubjectA, [
            'available_from' => now()->subHour(),
            'available_until' => now()->addHours(3),
            'duration_minutes' => 60,
        ]);

        $question = $this->questionOn($exam);

        // Kuncinya di tengah, bukan di ujung: urutan pun tidak boleh menjadi
        // petunjuk.
        $this->optionOn($question, self::WRONG_MARKERS[0]);
        $this->optionOn($question, self::CORRECT_MARKER, correct: true);
        $this->optionOn($question, self::WRONG_MARKERS[1]);
        $this->optionOn($question, self::WRONG_MARKERS[2]);

        return [
            app(ExamPublisher::class)->publish($exam->fresh(), $this->teacherA),
            $question->fresh(),
        ];
    }

    /**
     * Markup tombol pilihan pada satu soal, sebagai pasangan [id, markup].
     *
     * @return array<int, array{0: int, 1: string}>
     */
    protected function optionButtons(string $html, int $questionId): array
    {
        preg_match_all(
            '/<button[^>]*wire:click="choose\('.$questionId.', (\d+)\)".*?<\/button>/s',
            $html,
            $matches,
            PREG_SET_ORDER,
        );

        return array_map(fn (array $match) => [(int) $match[1], $match[0]], $matches);
    }

    /**
     * Markup satu tombol setelah pilihan itu dipilih, sudah dinormalisasi.
     */
    protected function markupAfterChoosing(int $examId, int $questionId, int $optionId, ExamOption $option): string
    {
        $html = Livewire::test(StudentExam::class, ['examId' => $examId])
            ->call('choose', $questionId, $optionId)
            ->html();

        $markup = collect($this->optionButtons($html, $questionId))
            ->first(fn (array $button) => $button[0] === $optionId)[1] ?? '';

        $this->assertNotSame('', $markup, 'Tombol pilihan tidak ditemukan setelah dipilih.');

        return $this->normalise($markup, $questionId, $optionId);
    }

    /**
     * Menghapus jejak identitas dari markup satu tombol.
     *
     * Penggantiannya menyasar pemanggilan `choose(...)` secara utuh, bukan
     * angkanya saja: mengganti "1" begitu saja akan ikut mengubah setiap digit
     * 1 di dalam markup — termasuk nomor soalnya — dan membuat perbandingannya
     * kehilangan arti.
     */
    protected function normalise(string $markup, int $questionId, int $optionId): string
    {
        $text = (string) ExamOption::query()->whereKey($optionId)->value('option_text');

        $markup = str_replace(
            'choose('.$questionId.', '.$optionId.')',
            'choose({Q}, {O})',
            $markup,
        );

        return str_replace($text, '{OPTION_TEXT}', $markup);
    }
}
