<?php

namespace Tests\Feature\Simulation;

use App\Enums\ExamAttemptStatus;
use App\Enums\ExamStatus;
use App\Enums\RoleName;
use App\Livewire\Auth\Login;
use App\Livewire\Student\StudentExam;
use App\Livewire\Student\StudentExams;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;
use Database\Seeders\SimulationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Perjalanan simulasi ujung ke ujung, di atas data sinteris.
 *
 * Test CBT yang sudah ada (260 metode) menguji setiap potongan dengan teliti,
 * tetapi tidak satu pun menempuh seluruh rangkaiannya sekali jalan: dari data
 * yang disiapkan admin, lewat autentikasi siswa, sampai nilai yang muncul.
 * Yang gagal di simulasi biasanya bukan potongannya, melainkan sambungannya —
 * dan sambungan hanya terlihat kalau ditempuh (butir 462).
 *
 * Seluruh data berasal dari SimulationSeeder, yakni data yang sama persis yang
 * akan dipakai saat demonstrasi. Test ini karenanya juga menjaga seeder itu
 * sendiri: bila ia berhenti menghasilkan ujian yang terbuka, test ini gagal
 * sebelum rapatnya, bukan di tengah rapat.
 *
 * Tidak ada PII nyata: setiap nama dan surel adalah karangan seeder demo.
 */
class SimulationJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function seedSimulation(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SchoolSeeder::class);
        $this->seed(SimulationSeeder::class);
    }

    protected function studentUser(): User
    {
        return User::query()->where('email', 'siswa.pusat@smartsukses.sch.id')->firstOrFail();
    }

    protected function simulationExam(): Exam
    {
        return Exam::query()->where('title', SimulationSeeder::EXAM_TITLE)->firstOrFail();
    }

    public function test_seeder_menyiapkan_seluruh_peran_yang_dibutuhkan_simulasi(): void
    {
        $this->seedSimulation();

        $expected = [
            'superadmin@smartsukses.sch.id' => RoleName::SuperAdmin,
            'admin.pusat@smartsukses.sch.id' => RoleName::SchoolAdmin,
            'kepsek.pusat@smartsukses.sch.id' => RoleName::KepalaSekolah,
            'bendahara.pusat@smartsukses.sch.id' => RoleName::Bendahara,
            'guru.pusat@smartsukses.sch.id' => RoleName::Guru,
            'walikelas.pusat@smartsukses.sch.id' => RoleName::WaliKelas,
            'siswa.pusat@smartsukses.sch.id' => RoleName::Siswa,
            'ortu.pusat@smartsukses.sch.id' => RoleName::OrangTua,
        ];

        foreach ($expected as $email => $role) {
            $user = User::query()->where('email', $email)->first();

            $this->assertNotNull($user, "Akun {$email} tidak dibuat seeder simulasi.");
            $this->assertTrue($user->is_active);
            // Kata sandi sementara akan menghentikan demo di layar ganti sandi.
            $this->assertFalse($user->must_change_password);
            $this->assertSame([$role->value], $user->roles->pluck('name')->all());
        }
    }

    public function test_akun_siswa_terhubung_ke_data_siswa_dan_orang_tua(): void
    {
        $this->seedSimulation();

        $student = Student::query()->where('nis', '240001')->firstOrFail();

        // Tanpa keduanya, portal siswa dan portal orang tua tidak punya pintu
        // masuk sama sekali — keadaan sebelum seeder ini ada.
        $this->assertNotNull($student->user_id);
        $this->assertNotNull($student->parent_user_id);
        $this->assertSame($this->studentUser()->getKey(), $student->user_id);
    }

    public function test_ujian_simulasi_terbit_dan_jendelanya_terbuka(): void
    {
        $this->seedSimulation();

        $exam = $this->simulationExam();

        $this->assertSame(ExamStatus::Published, $exam->status);
        $this->assertTrue($exam->available_from->isPast());
        $this->assertTrue($exam->available_until->isFuture());
        $this->assertSame(3, $exam->questions()->count());

        // Tepat satu kunci per soal: ujian tanpa kunci tidak dapat dinilai, dan
        // ujian dengan dua kunci menilai secara diam-diam salah.
        foreach ($exam->questions as $question) {
            $this->assertSame(1, $question->options()->where('is_correct', true)->count());
        }
    }

    public function test_perjalanan_penuh_siswa_dari_masuk_sampai_nilai(): void
    {
        $this->seedSimulation();

        $user = $this->studentUser();
        $exam = $this->simulationExam();

        // 1. Masuk lewat pintu tunggal — halaman masuk adalah komponen Livewire,
        // dan perannya ditentukan akun, bukan kiriman.
        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'Password123')
            ->call('authenticate');

        $this->assertAuthenticatedAs($user);

        // 2. Portal siswa terbuka.
        $this->actingAs($user)->get(route('student.dashboard'))->assertOk();

        // 3. Ujian tampil pada daftar ujian siswa.
        Livewire::actingAs($user)
            ->test(StudentExams::class)
            ->assertOk()
            ->assertSee(SimulationSeeder::EXAM_TITLE);

        // 4. Membuka halaman ujian sudah memulai pengerjaan: attempt dibuat
        // lewat startOrResume saat render, bukan lewat tombol tersendiri.
        $component = Livewire::actingAs($user)->test(StudentExam::class, ['examId' => $exam->getKey()]);

        $this->assertSame(1, ExamAttempt::query()->where('exam_id', $exam->getKey())->count());

        foreach ($exam->questions()->with('options')->orderBy('position')->get() as $question) {
            $correct = $question->options->firstWhere('is_correct', true);
            $component->call('choose', $question->getKey(), $correct->getKey());
        }

        $component->call('submit');

        // 5. Hasilnya tersimpan dan bernilai penuh.
        $attempt = ExamAttempt::query()
            ->where('exam_id', $exam->getKey())
            ->firstOrFail();

        $this->assertSame(ExamAttemptStatus::Submitted, $attempt->status);
        $this->assertNotNull($attempt->submitted_at);
        $this->assertEqualsWithDelta(100.0, (float) $attempt->score, 0.01);

        // 6. Halaman hasil dapat dibuka siswa.
        $this->actingAs($user)
            ->get(route('student.exam-result', ['examId' => $exam->getKey()]))
            ->assertOk();

        // 7. Pengumpulan TIDAK membuat nilai akademik — keputusan pemilik R-1.
        // Angka berpindah hanya lewat tindakan guru "Masukkan ke Nilai".
        $this->assertNull($attempt->grade_id);
    }

    public function test_satu_percobaan_per_ujian(): void
    {
        $this->seedSimulation();

        $user = $this->studentUser();
        $exam = $this->simulationExam();

        // Membuka halaman ujian dua kali melanjutkan pengerjaan yang sama,
        // bukan memulai yang kedua.
        Livewire::actingAs($user)->test(StudentExam::class, ['examId' => $exam->getKey()]);
        Livewire::actingAs($user)->test(StudentExam::class, ['examId' => $exam->getKey()]);

        $this->assertSame(1, ExamAttempt::query()->where('exam_id', $exam->getKey())->count());
    }

    public function test_guru_tidak_dapat_memasuki_portal_siswa(): void
    {
        $this->seedSimulation();

        $teacher = User::query()->where('email', 'guru.pusat@smartsukses.sch.id')->firstOrFail();

        $this->actingAs($teacher)->get(route('student.dashboard'))->assertForbidden();
    }

    public function test_siswa_tidak_dapat_memasuki_panel_admin_maupun_portal_orang_tua(): void
    {
        $this->seedSimulation();

        $user = $this->studentUser();

        $this->actingAs($user)->get(route('portal.dashboard'))->assertForbidden();
        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_akun_nonaktif_ditolak_pada_pintu_masuk_tunggal(): void
    {
        $this->seedSimulation();

        $user = $this->studentUser();
        $user->forceFill(['is_active' => false])->save();

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'Password123')
            ->call('authenticate');

        $this->assertGuest();
    }

    public function test_seeder_simulasi_menolak_berjalan_di_produksi(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SchoolSeeder::class);

        // Seeder ini membuat akun yang dapat login untuk delapan peran; di
        // produksi itu pintu masuk, bukan data contoh (butir 459).
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);

        // Dipanggil langsung, bukan lewat `db:seed`: perintah artisan sudah
        // meminta konfirmasi lebih dulu di produksi, sehingga lewat jalur itu
        // pagar seeder-nya sendiri tidak pernah tersentuh.
        (new SimulationSeeder)->setContainer($this->app)->run();
    }

    public function test_ujian_cabang_lain_tidak_terlihat(): void
    {
        $this->seedSimulation();

        $other = School::factory()->create(['code' => 'LAIN']);
        $otherUser = User::factory()->forSchool($other)->withRole(RoleName::Siswa)->create([
            'must_change_password' => false,
        ]);

        // Siswa cabang lain tanpa penempatan kelas: gagal tertutup, bukan
        // terbuka — tidak satu ujian pun menjadi miliknya.
        Livewire::actingAs($otherUser)
            ->test(StudentExams::class)
            ->assertOk()
            ->assertDontSee(SimulationSeeder::EXAM_TITLE);
    }
}
