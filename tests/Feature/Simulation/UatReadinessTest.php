<?php

namespace Tests\Feature\Simulation;

use App\Enums\ExamStatus;
use App\Enums\GradeConfigStatus;
use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Enums\StudentFeeStatus;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Exam;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\GradeConfig;
use App\Models\Payment;
use App\Models\ReportCard;
use App\Models\Schedule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentFee;
use App\Models\Subject;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Portal\ParentPortalService;
use App\Support\LoginDestination;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;
use Database\Seeders\SimulationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * M6 — kesiapan UAT: apakah seorang penguji benar-benar dapat mulai bekerja.
 *
 * SimulationJourneyTest sudah menempuh satu perjalanan siswa dari masuk sampai
 * nilai. Yang belum dijaga siapa pun adalah pertanyaan yang lebih mendasar dan
 * lebih memalukan bila jawabannya ternyata tidak: **setiap peran punya akun,
 * dan setiap akun mendarat di tempat yang benar dengan halaman yang tidak
 * kosong**.
 *
 * Halaman kosong adalah kegagalan UAT yang paling mahal. Ia tidak dapat
 * dibedakan dari halaman yang rusak, sehingga penguji melaporkan cacat yang
 * tidak ada — atau, lebih buruk, berhenti melaporkan apa pun karena mengira
 * seluruh modul memang belum jadi (butir 527).
 *
 * Seluruh data di sini sintetis, dari seeder yang menolak berjalan di produksi.
 * Tidak ada nama, surel, NIS, maupun NISN sungguhan.
 */
class UatReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function seedUat(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SchoolSeeder::class);
        $this->seed(SimulationSeeder::class);
    }

    protected function school(): School
    {
        return School::query()->where('code', 'PUSAT')->firstOrFail();
    }

    protected function activeYear(): AcademicYear
    {
        return AcademicYear::query()
            ->where('school_id', $this->school()->id)
            ->where('is_active', true)
            ->firstOrFail();
    }

    protected function account(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    /**
     * Periode yang diisi seeder: bulan mulai tahun ajaran, bukan bulan berjalan.
     */
    protected function seededPeriod(): string
    {
        return $this->activeYear()->start_date->format('Y-m');
    }

    // ---------------------------------------------------------------- akun

    /**
     * Peran baru pada RoleName tidak boleh lolos tanpa akun penguji.
     *
     * Inilah yang membuat daftar UAT_ACCOUNTS tidak dapat perlahan tertinggal
     * dari produk: menambah satu case pada enum langsung menggagalkan test ini.
     */
    public function test_setiap_peran_punya_satu_akun_penguji(): void
    {
        $listed = array_values(SimulationSeeder::UAT_ACCOUNTS);

        foreach (RoleName::cases() as $role) {
            $this->assertContains(
                $role->value,
                $listed,
                "Peran {$role->value} tidak punya akun penguji di SimulationSeeder::UAT_ACCOUNTS.",
            );
        }

        // Satu akun per peran, bukan dua yang membingungkan penguji.
        $this->assertSame(count(RoleName::cases()), count(array_unique($listed)));
        $this->assertSame(count($listed), count(array_unique(array_keys(SimulationSeeder::UAT_ACCOUNTS))));
    }

    public function test_seluruh_akun_penguji_ada_aktif_dan_siap_dipakai(): void
    {
        $this->seedUat();

        foreach (SimulationSeeder::UAT_ACCOUNTS as $email => $role) {
            $user = User::query()->where('email', $email)->first();

            $this->assertNotNull($user, "Akun penguji {$email} tidak dibuat seeder.");
            $this->assertTrue($user->is_active, "Akun penguji {$email} tidak aktif.");

            // Layar ganti kata sandi menghentikan UAT sebelum satu menu pun
            // terbuka; pagar itu tetap utuh di UserSeeder untuk produksi.
            $this->assertFalse($user->must_change_password, "Akun {$email} masih wajib ganti sandi.");
            $this->assertSame([$role], $user->roles->pluck('name')->all());
        }
    }

    /**
     * Tujuan setiap peran sesudah masuk, dibaca dari akun yang benar-benar
     * dibuat seeder — bukan dari akun buatan factory yang kebetulan mirip.
     */
    public function test_setiap_akun_penguji_mendarat_di_tujuan_yang_benar(): void
    {
        $this->seedUat();

        $panel = [
            'superadmin@smartsukses.sch.id',
            'admin.pusat@smartsukses.sch.id',
            'kepsek.pusat@smartsukses.sch.id',
            'guru.pusat@smartsukses.sch.id',
            'walikelas.pusat@smartsukses.sch.id',
            'bendahara.pusat@smartsukses.sch.id',
        ];

        foreach ($panel as $email) {
            $user = $this->account($email);

            // Filament menyusun URL panel dari pengguna yang sedang aktif; bagi
            // tamu ia menjawab halaman masuk panel, bukan dasbornya. Yang diuji
            // di sini tujuan sesudah kredensial terbukti, jadi penggunanya
            // memang sudah aktif.
            $this->actingAs($user);

            $this->assertSame(
                route('filament.admin.pages.dashboard'),
                LoginDestination::urlFor($user),
                "Akun {$email} tidak diarahkan ke panel admin.",
            );
        }

        $this->assertSame(
            route('student.dashboard'),
            LoginDestination::urlFor($this->account('siswa.pusat@smartsukses.sch.id')),
        );

        $this->assertSame(
            route('portal.dashboard'),
            LoginDestination::urlFor($this->account('ortu.pusat@smartsukses.sch.id')),
        );
    }

    // ------------------------------------------------------- struktur sekolah

    public function test_struktur_sekolah_lengkap_untuk_diuji(): void
    {
        $this->seedUat();

        $school = $this->school();
        $year = $this->activeYear();

        $class = SchoolClass::query()
            ->where('school_id', $school->id)
            ->where('academic_year_id', $year->id)
            ->firstOrFail();

        // Wali kelas terpasang: tanpa itu seluruh alur rapor wali kelas tidak
        // punya kelas yang menjadi tanggung jawabnya.
        $this->assertNotNull($class->homeroom_teacher_id);
        $this->assertSame(
            $this->account('walikelas.pusat@smartsukses.sch.id')->getKey(),
            $class->homeroom_teacher_id,
        );

        $this->assertGreaterThanOrEqual(3, Subject::query()->where('school_id', $school->id)->count());

        $classSubjects = ClassSubject::query()->where('school_id', $school->id)->get();
        $this->assertGreaterThanOrEqual(3, $classSubjects->count());

        // Setiap mata pelajaran kelas punya guru pengampu.
        foreach ($classSubjects as $classSubject) {
            $this->assertNotNull($classSubject->teacher_id);
        }

        // Jadwal: tabelnya kosong sebelum SimulationSeeder ada.
        $this->assertGreaterThanOrEqual(1, Schedule::query()->where('school_id', $school->id)->count());
    }

    public function test_siswa_sintetis_ada_dan_ditempatkan_di_kelas(): void
    {
        $this->seedUat();

        $school = $this->school();
        $year = $this->activeYear();

        $students = Student::query()->where('school_id', $school->id)->get();

        $this->assertGreaterThanOrEqual(3, $students->count());

        foreach ($students as $student) {
            $this->assertSame(
                1,
                StudentClass::query()
                    ->where('student_id', $student->getKey())
                    ->where('academic_year_id', $year->id)
                    ->count(),
                "Siswa {$student->nis} tidak punya penempatan kelas tunggal.",
            );
        }
    }

    // ---------------------------------------------------------- orang tua

    public function test_satu_siswa_terhubung_ke_akun_siswa_dan_akun_orang_tua(): void
    {
        $this->seedUat();

        $parent = $this->account('ortu.pusat@smartsukses.sch.id');
        $studentUser = $this->account('siswa.pusat@smartsukses.sch.id');

        $children = Student::query()->where('parent_user_id', $parent->getKey())->get();

        $this->assertCount(1, $children);
        $this->assertSame($studentUser->getKey(), $children->first()->user_id);
    }

    /**
     * Portal orang tua menyajikan anaknya sendiri, dan hanya itu.
     */
    public function test_orang_tua_hanya_melihat_anak_yang_terhubung(): void
    {
        $this->seedUat();

        $parent = $this->account('ortu.pusat@smartsukses.sch.id');

        $children = app(ParentPortalService::class)->children($parent);

        $this->assertCount(1, $children);
        $this->assertSame(
            Student::query()->where('parent_user_id', $parent->getKey())->value('id'),
            $children->first()->getKey(),
        );

        // Siswa lain di kelas yang sama tetap bukan urusannya.
        $this->assertGreaterThan(1, Student::query()->count());
    }

    // ------------------------------------------------------------ penilaian

    public function test_bekal_penilaian_cukup_untuk_alur_guru_dan_rapor(): void
    {
        $this->seedUat();

        $school = $this->school();

        // Dua konfigurasi ACTIVE: tanpa konfigurasi aktif, nilai lahir tanpa
        // snapshot bobot dan rapor tidak akan pernah dapat lengkap.
        $this->assertSame(
            2,
            GradeConfig::query()
                ->where('school_id', $school->id)
                ->where('status', GradeConfigStatus::Active->value)
                ->count(),
        );

        // Nilai untuk ketiga siswa, di ketiga mata pelajaran.
        $this->assertGreaterThanOrEqual(3, Grade::query()->where('school_id', $school->id)
            ->distinct()->count('student_id'));

        $this->assertGreaterThanOrEqual(3, Grade::query()->where('school_id', $school->id)
            ->distinct()->count('class_subject_id'));

        // Rapor sengaja belum dibuat: menerbitkannya dari UI adalah inti
        // pengujian wali kelas, bukan sesuatu yang disiapkan seeder.
        $this->assertSame(0, ReportCard::query()->count());
    }

    // ------------------------------------------------------------------ CBT

    public function test_satu_ujian_terbit_dan_jendelanya_terbuka_saat_uat(): void
    {
        $this->seedUat();

        $exam = Exam::query()->where('title', SimulationSeeder::EXAM_TITLE)->firstOrFail();

        $this->assertSame(ExamStatus::Published, $exam->status);
        $this->assertTrue($exam->available_from->isPast());
        $this->assertTrue($exam->available_until->isFuture());
        $this->assertSame(3, $exam->questions()->count());
    }

    // -------------------------------------------------------------- keuangan

    public function test_bekal_keuangan_siap_untuk_bendahara(): void
    {
        $this->seedUat();

        $school = $this->school();
        $period = $this->seededPeriod();

        $feeTypes = FeeType::query()->where('school_id', $school->id)->pluck('name');

        $this->assertContains(SimulationSeeder::FEE_TYPE_MONTHLY, $feeTypes->all());
        $this->assertContains(SimulationSeeder::FEE_TYPE_ONCE, $feeTypes->all());

        $fees = StudentFee::query()
            ->where('school_id', $school->id)
            ->where('period', $period)
            ->get();

        $this->assertCount(3, $fees);

        // Tiga keadaan berbeda: daftar yang seragam tidak menguji satu pun
        // filter status, dan tidak memperlihatkan sisa tagihan yang berjalan.
        $this->assertEqualsCanonicalizing(
            [
                StudentFeeStatus::Paid->value,
                StudentFeeStatus::Partial->value,
                StudentFeeStatus::Unpaid->value,
            ],
            $fees->pluck('status')->map(fn ($status) => $status->value)->all(),
        );

        $this->assertSame(2, Payment::query()->where('school_id', $school->id)->count());
        $this->assertSame(2, Transaction::query()->where('school_id', $school->id)->count());
    }

    /**
     * Periode berjalan sengaja dibiarkan kosong.
     *
     * Menerbitkan tagihan adalah salah satu hal yang harus dicoba Bendahara
     * sendiri lewat halaman Generate Tagihan. Bila seeder sudah menerbitkannya,
     * halaman itu hanya akan melaporkan "semua sudah punya" dan alur intinya
     * tidak pernah teruji.
     */
    public function test_periode_berjalan_sengaja_belum_ditagihkan(): void
    {
        $this->seedUat();

        $current = now()->format('Y-m');

        if ($current === $this->seededPeriod()) {
            $this->markTestSkipped('Bulan berjalan kebetulan sama dengan periode yang diisi seeder.');
        }

        $this->assertSame(
            0,
            StudentFee::query()
                ->where('school_id', $this->school()->id)
                ->where('period', $current)
                ->count(),
        );
    }

    /**
     * Seeder tidak boleh menyentuh penyedia pembayaran mana pun.
     *
     * Pembayaran demo dicatat sebagai TUNAI. PAYMENT_GATEWAY hanya sebuah nilai
     * enum di project ini — tidak ada integrasi penyedia — tetapi data demo yang
     * memakainya akan membuat penguji mengira ada yang benar-benar dihubungi.
     */
    public function test_pembayaran_simulasi_tidak_memakai_jalur_penyedia(): void
    {
        $this->seedUat();

        foreach (Payment::query()->get() as $payment) {
            $this->assertNotSame(
                PaymentMethod::PaymentGateway,
                $payment->payment_method,
            );
        }
    }

    // ------------------------------------------------------- batas antarperan

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refusedAbilities(): array
    {
        return [
            // Situs payung bukan milik satu cabang (butir 469).
            'admin sekolah tidak mengelola isi situs payung' => [
                'admin.pusat@smartsukses.sch.id', 'public_content.manage',
            ],
            'bendahara tidak mengelola isi situs payung' => [
                'bendahara.pusat@smartsukses.sch.id', 'public_content.manage',
            ],
            // Bendahara: keuangan, dan tidak melebar ke luar itu.
            'bendahara tidak mengelola siswa' => [
                'bendahara.pusat@smartsukses.sch.id', 'student.manage',
            ],
            'bendahara tidak mengelola nilai' => [
                'bendahara.pusat@smartsukses.sch.id', 'grade.manage',
            ],
            'bendahara tidak mengelola akun' => [
                'bendahara.pusat@smartsukses.sch.id', 'user.manage',
            ],
            // Kepala sekolah: membaca, bukan menyunting.
            'kepala sekolah tidak mengelola siswa' => [
                'kepsek.pusat@smartsukses.sch.id', 'student.manage',
            ],
            'kepala sekolah tidak mengelola nilai' => [
                'kepsek.pusat@smartsukses.sch.id', 'grade.manage',
            ],
            'kepala sekolah tidak mengelola tagihan' => [
                'kepsek.pusat@smartsukses.sch.id', 'fee.manage',
            ],
            // Guru mapel: nilai ya, rapor kelas tidak — itu wali kelas.
            'guru mapel tidak mengelola rapor' => [
                'guru.pusat@smartsukses.sch.id', 'report_card.manage',
            ],
            'guru mapel tidak mengelola tagihan' => [
                'guru.pusat@smartsukses.sch.id', 'fee.manage',
            ],
        ];
    }

    #[DataProvider('refusedAbilities')]
    public function test_akun_penguji_tidak_mendapat_kuasa_di_luar_perannya(string $email, string $ability): void
    {
        $this->seedUat();

        $this->assertFalse(
            $this->account($email)->can($ability),
            "{$email} seharusnya tidak memiliki izin {$ability}.",
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function grantedAbilities(): array
    {
        return [
            'admin sekolah mengelola siswa' => ['admin.pusat@smartsukses.sch.id', 'student.manage'],
            'kepala sekolah membaca nilai' => ['kepsek.pusat@smartsukses.sch.id', 'grade.view'],
            'kepala sekolah membaca laporan keuangan' => [
                'kepsek.pusat@smartsukses.sch.id', 'financial_report.view',
            ],
            'guru mengelola nilai' => ['guru.pusat@smartsukses.sch.id', 'grade.manage'],
            'wali kelas mengelola rapor' => ['walikelas.pusat@smartsukses.sch.id', 'report_card.manage'],
            'bendahara mengelola tagihan' => ['bendahara.pusat@smartsukses.sch.id', 'fee.manage'],
            'bendahara mengelola pembayaran' => ['bendahara.pusat@smartsukses.sch.id', 'payment.manage'],
            'super admin mengelola situs payung' => ['superadmin@smartsukses.sch.id', 'public_content.manage'],
        ];
    }

    #[DataProvider('grantedAbilities')]
    public function test_akun_penguji_mendapat_kuasa_yang_memang_perlu(string $email, string $ability): void
    {
        $this->seedUat();

        $this->assertTrue(
            $this->account($email)->can($ability),
            "{$email} seharusnya memiliki izin {$ability} agar dapat diuji.",
        );
    }

    /**
     * Alamat langsung bukan jalan pintas: staf tidak masuk portal, dan portal
     * tidak masuk panel.
     */
    public function test_alamat_langsung_tidak_menembus_batas_peran(): void
    {
        $this->seedUat();

        $bendahara = $this->account('bendahara.pusat@smartsukses.sch.id');
        $this->actingAs($bendahara)->get(route('student.dashboard'))->assertForbidden();
        $this->actingAs($bendahara)->get(route('portal.dashboard'))->assertForbidden();

        $kepsek = $this->account('kepsek.pusat@smartsukses.sch.id');
        $this->actingAs($kepsek)->get(route('student.dashboard'))->assertForbidden();

        $parent = $this->account('ortu.pusat@smartsukses.sch.id');
        $this->actingAs($parent)->get('/admin')->assertForbidden();
        $this->actingAs($parent)->get(route('student.dashboard'))->assertForbidden();
    }

    // ---------------------------------------------------------- idempotensi

    /**
     * Menjalankan seeder dua kali tidak boleh menggandakan apa pun.
     *
     * Di staging ini bukan soal kerapian: angkatan tagihan kedua akan tampil
     * sebagai tunggakan ganda, dan penguji akan melaporkannya sebagai cacat
     * perhitungan yang sebenarnya tidak ada.
     */
    public function test_menjalankan_seeder_dua_kali_tidak_menggandakan_data(): void
    {
        $this->seedUat();

        $first = $this->aggregates();

        $this->seed(SimulationSeeder::class);

        $this->assertSame($first, $this->aggregates());
    }

    /**
     * @return array<string, int>
     */
    protected function aggregates(): array
    {
        return [
            'users' => User::query()->count(),
            'students' => Student::query()->count(),
            'student_classes' => StudentClass::query()->count(),
            'classes' => SchoolClass::query()->count(),
            'subjects' => Subject::query()->count(),
            'class_subjects' => ClassSubject::query()->count(),
            'schedules' => Schedule::query()->count(),
            'grades' => Grade::query()->count(),
            'grade_configs' => GradeConfig::query()->count(),
            'exams' => Exam::query()->count(),
            'fee_types' => FeeType::query()->count(),
            'student_fees' => StudentFee::query()->count(),
            'payments' => Payment::query()->count(),
            'transactions' => Transaction::query()->count(),
        ];
    }
}
