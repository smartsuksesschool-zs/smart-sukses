<?php

namespace Database\Seeders;

use App\Enums\ExamQuestionType;
use App\Enums\ExamStatus;
use App\Enums\FeeFrequency;
use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Enums\StudentStatus;
use App\Enums\TransactionType;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Exam;
use App\Models\ExamOption;
use App\Models\ExamQuestion;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\PaymentRecorder;
use App\Support\SeedPassword;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Data simulasi untuk demonstrasi bersama sekolah.
 *
 * SEEDER KHUSUS LOKAL/UJI. Sengaja **tidak** didaftarkan di DatabaseSeeder, dan
 * menolak berjalan di produksi — ia membuat akun yang dapat login, dan akun
 * demo yang lahir di produksi adalah pintu masuk, bukan data contoh
 * (butir 459).
 *
 *   php artisan db:seed --class=SimulationSeeder
 *
 * Melanjutkan Sprint4DemoSeeder, tidak menggantikannya: seluruh cabang, tahun
 * ajaran, kelas, siswa, mata pelajaran, konfigurasi, dan nilai berasal dari
 * sana. Yang ditambahkan di sini hanya yang belum pernah ada dan tanpanya
 * simulasi tidak dapat berjalan:
 *
 *   1. akun SISWA dan ORANG_TUA — tanpa ini portal siswa dan portal orang tua
 *      tidak dapat didemokan sama sekali, karena students.user_id masih NULL
 *      untuk seluruh siswa demo (butir 460);
 *   2. akun KEPALA_SEKOLAH dan BENDAHARA — dua peran yang belum pernah punya
 *      akun mana pun;
 *   3. jadwal pelajaran — tabelnya kosong;
 *   4. satu ujian CBT terbit beserta soal dan kuncinya;
 *   5. bekal keuangan seperlunya — dua jenis tagihan, satu angkatan tagihan
 *      dengan tiga keadaan berbeda, pembayarannya, dan dua baris buku kas —
 *      supaya halaman Bendahara tidak terbuka dalam keadaan kosong
 *      (butir 527).
 *
 * Aman diulang: setiap baris lewat updateOrCreate pada kunci alaminya, dan
 * tidak ada yang dihapus. Jendela ujian sengaja dihitung ulang setiap kali
 * dijalankan, sehingga menjalankannya sesaat sebelum rapat selalu menghasilkan
 * ujian yang sedang terbuka (butir 461).
 */
class SimulationSeeder extends Seeder
{
    /** Kunci alami ujian simulasi; dirujuk test perjalanan simulasi. */
    public const EXAM_TITLE = 'Ulangan Harian Simulasi';

    /** Kunci alami jenis tagihan simulasi; dirujuk test kesiapan UAT. */
    public const FEE_TYPE_MONTHLY = 'SPP Bulanan Simulasi';

    public const FEE_TYPE_ONCE = 'Uang Kegiatan Simulasi';

    /**
     * Surel seluruh akun penguji beserta perannya.
     *
     * Akunnya sendiri lahir di tiga tempat berbeda — UserSeeder,
     * Sprint4DemoSeeder, dan seeder ini — karena masing-masing perlu
     * penyambungan yang berbeda; daftar ini tidak dapat menggantikannya.
     * Yang digantikannya adalah tiga salinan **daftar**: tabel yang dicetak
     * setelah seeding, test kesiapan UAT, dan dokumen penguji. Ada test yang
     * memastikan setiap peran pada RoleName punya satu barisnya di sini,
     * sehingga peran baru tidak dapat lolos tanpa akun penguji (butir 526).
     *
     * @var array<string, string>
     */
    public const UAT_ACCOUNTS = [
        'superadmin@smartsukses.sch.id' => RoleName::SuperAdmin->value,
        'admin.pusat@smartsukses.sch.id' => RoleName::SchoolAdmin->value,
        'kepsek.pusat@smartsukses.sch.id' => RoleName::KepalaSekolah->value,
        'guru.pusat@smartsukses.sch.id' => RoleName::Guru->value,
        'walikelas.pusat@smartsukses.sch.id' => RoleName::WaliKelas->value,
        'bendahara.pusat@smartsukses.sch.id' => RoleName::Bendahara->value,
        'siswa.pusat@smartsukses.sch.id' => RoleName::Siswa->value,
        'ortu.pusat@smartsukses.sch.id' => RoleName::OrangTua->value,
    ];

    protected School $school;

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'SimulationSeeder membuat akun yang dapat login dan tidak boleh berjalan di produksi.'
            );
        }

        /*
         * Kata sandi dipastikan **sebelum** satu baris pun ditulis.
         *
         * Sebelumnya ia diselesaikan di tengah jalan, saat akun pertama hendak
         * dibuat. Di lingkungan yang menolak kata sandi bawaan, seeder karena
         * itu berhenti setelah sebagian tabel terisi — meninggalkan jadwal,
         * ujian, atau kelas tanpa akun yang memilikinya, dan operator harus
         * menebak seberapa jauh ia sempat berjalan.
         *
         * Gagal di langkah pertama jauh lebih mudah dipahami daripada gagal di
         * tengah (butir 512).
         */
        SeedPassword::resolve();

        $this->call(UserSeeder::class);
        $this->call(Sprint4DemoSeeder::class);

        $school = School::query()->where('code', 'PUSAT')->first();

        if ($school === null) {
            $this->command?->error('Cabang PUSAT belum ada. Jalankan SchoolSeeder lebih dulu.');

            return;
        }

        $this->school = $school;

        $this->clearTemporaryPasswordFlags();
        $this->seedStaff();
        $student = $this->seedStudentAccount();
        $this->seedParentAccount($student);
        $this->seedSchedules();
        $this->seedExam();
        $this->seedFinance();

        $this->report();
    }

    /**
     * UserSeeder membuat Super Admin dan Admin Sekolah dengan
     * `must_change_password = true` — pagar produksi yang benar, dan justru
     * itulah yang akan menghentikan demonstrasi di layar ganti kata sandi
     * sebelum satu menu pun terbuka.
     *
     * Penandanya dilepas **hanya di sini**, di seeder yang sudah menolak
     * berjalan di produksi. Pagar UserSeeder sendiri tidak disentuh
     * (butir 463).
     */
    protected function clearTemporaryPasswordFlags(): void
    {
        User::query()
            ->whereIn('email', [
                'superadmin@smartsukses.sch.id',
                'admin.pusat@smartsukses.sch.id',
            ])
            ->update(['must_change_password' => false]);
    }

    /**
     * Dua peran yang belum pernah punya akun: Kepala Sekolah dan Bendahara.
     */
    protected function seedStaff(): void
    {
        $this->account(
            'kepsek.pusat@smartsukses.sch.id',
            'Kepala Sekolah Simulasi',
            RoleName::KepalaSekolah,
        );

        $this->account(
            'bendahara.pusat@smartsukses.sch.id',
            'Bendahara Simulasi',
            RoleName::Bendahara,
        );
    }

    /**
     * Akun siswa untuk siswa demo pertama.
     *
     * `students.user_id` nullable, jadi data induk siswa memang boleh hidup
     * tanpa akun — dan itulah keadaan seluruh siswa demo sebelum seeder ini.
     * Konsekuensinya portal siswa dan CBT tidak punya satu pun pintu masuk.
     */
    protected function seedStudentAccount(): ?Student
    {
        $student = Student::query()
            ->where('school_id', $this->school->id)
            ->where('nis', '240001')
            ->first();

        if ($student === null) {
            $this->command?->warn('Siswa demo 240001 tidak ada; akun siswa dilewati.');

            return null;
        }

        $user = $this->account(
            'siswa.pusat@smartsukses.sch.id',
            $student->full_name,
            RoleName::Siswa,
        );

        $student->forceFill(['user_id' => $user->getKey()])->save();

        return $student;
    }

    protected function seedParentAccount(?Student $student): void
    {
        if ($student === null) {
            return;
        }

        $user = $this->account(
            'ortu.pusat@smartsukses.sch.id',
            'Orang Tua Simulasi',
            RoleName::OrangTua,
        );

        $student->forceFill(['parent_user_id' => $user->getKey()])->save();
    }

    /**
     * Satu jam pelajaran per mata pelajaran, Senin–Rabu.
     */
    protected function seedSchedules(): void
    {
        $classSubjects = ClassSubject::query()
            ->where('school_id', $this->school->id)
            ->orderBy('id')
            ->get();

        foreach ($classSubjects as $index => $classSubject) {
            Schedule::updateOrCreate(
                [
                    'class_subject_id' => $classSubject->getKey(),
                    'day_of_week' => $index + 1,
                ],
                [
                    'school_id' => $this->school->id,
                    'start_time' => sprintf('%02d:00:00', 7 + $index),
                    'end_time' => sprintf('%02d:30:00', 8 + $index),
                    'room' => 'R10'.($index + 1),
                ],
            );
        }
    }

    /**
     * Satu ujian pilihan ganda terbit, tiga soal, kuncinya pasti.
     *
     * Jendelanya dihitung ulang setiap kali seeder dijalankan: mundur satu jam
     * supaya sudah terbuka, dan maju tujuh hari supaya tidak keburu tutup di
     * tengah rapat.
     */
    protected function seedExam(): void
    {
        $classSubject = ClassSubject::query()
            ->where('school_id', $this->school->id)
            ->orderBy('id')
            ->first();

        if ($classSubject === null) {
            $this->command?->warn('Belum ada kelas-mapel; ujian simulasi dilewati.');

            return;
        }

        $exam = Exam::updateOrCreate(
            [
                'school_id' => $this->school->id,
                'class_subject_id' => $classSubject->getKey(),
                'title' => self::EXAM_TITLE,
            ],
            [
                'academic_year_id' => $classSubject->academic_year_id,
                'description' => 'Ujian contoh untuk simulasi. Tiga soal pilihan ganda.',
                'duration_minutes' => 30,
                'available_from' => now()->subHour(),
                'available_until' => now()->addDays(7),
                'status' => ExamStatus::Published->value,
                'created_by' => $classSubject->teacher_id,
            ],
        );

        $questions = [
            ['Ibu kota Indonesia adalah?', ['Jakarta' => true, 'Bandung' => false, 'Surabaya' => false]],
            ['Hasil dari 7 x 8 adalah?', ['54' => false, '56' => true, '58' => false]],
            ['Lambang unsur besi adalah?', ['Fe' => true, 'Be' => false, 'Ba' => false]],
        ];

        foreach ($questions as $position => [$text, $options]) {
            $question = ExamQuestion::updateOrCreate(
                [
                    'exam_id' => $exam->getKey(),
                    'position' => $position + 1,
                ],
                [
                    'school_id' => $this->school->id,
                    'question_type' => ExamQuestionType::MultipleChoice->value,
                    'question_text' => $text,
                    'points' => 1.00,
                ],
            );

            $optionPosition = 1;

            foreach ($options as $optionText => $isCorrect) {
                ExamOption::updateOrCreate(
                    [
                        'exam_question_id' => $question->getKey(),
                        'position' => $optionPosition,
                    ],
                    [
                        'school_id' => $this->school->id,
                        'option_text' => $optionText,
                        'is_correct' => $isCorrect,
                    ],
                );

                $optionPosition++;
            }
        }
    }

    /**
     * Bekal keuangan minimum agar Bendahara punya sesuatu untuk diuji.
     *
     * Sebelum ini seluruh halaman keuangan terbuka dalam keadaan kosong: daftar
     * tagihan, riwayat pembayaran, buku kas, dan laporan SPP sama-sama tidak
     * punya satu baris pun. Halaman kosong tidak membuktikan apa-apa — ia tidak
     * dapat dibedakan dari halaman yang rusak, dan penguji yang melihatnya tidak
     * punya cara tahu mana di antara keduanya yang sedang ia lihat (butir 527).
     *
     * Barisnya ditulis langsung, **bukan** lewat `StudentFeeGenerator`. Generator
     * itu mengirim pemberitahuan "tagihan baru terbit" ke orang tua dan menyentuh
     * antrean; seeder yang memanggilnya akan menerbitkan pemberitahuan setiap kali
     * dijalankan. Menerbitkan tagihan justru salah satu yang harus **dicoba
     * penguji sendiri** lewat halaman Generate Tagihan — jadi periode berjalan
     * sengaja dibiarkan kosong, dan yang diisi di sini hanya periode pertama
     * tahun ajaran.
     *
     * Tiga siswa mendapat tiga keadaan berbeda — lunas, sebagian, belum bayar —
     * supaya daftar tagihan tidak seragam dan filter statusnya benar-benar ada
     * yang disaring.
     */
    protected function seedFinance(): void
    {
        $year = AcademicYear::query()
            ->where('school_id', $this->school->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        $bendahara = User::query()
            ->where('email', 'bendahara.pusat@smartsukses.sch.id')
            ->first();

        if ($year === null || $bendahara === null) {
            $this->command?->warn('Tahun ajaran aktif atau akun bendahara belum ada; bekal keuangan dilewati.');

            return;
        }

        $monthly = FeeType::updateOrCreate(
            ['school_id' => $this->school->id, 'name' => self::FEE_TYPE_MONTHLY],
            [
                // NULL, mengikuti ERD 2.2: tagihan berulang tidak terikat satu
                // tahun ajaran, dan StudentFeeGenerator memakai tahun ajaran
                // aktif cabang saat menerbitkannya (butir 53).
                'academic_year_id' => null,
                'amount' => 350000,
                'frequency' => FeeFrequency::Monthly->value,
                'description' => 'Iuran bulanan untuk simulasi UAT.',
                'is_active' => true,
            ],
        );

        FeeType::updateOrCreate(
            ['school_id' => $this->school->id, 'name' => self::FEE_TYPE_ONCE],
            [
                'academic_year_id' => $year->getKey(),
                'amount' => 750000,
                'frequency' => FeeFrequency::Once->value,
                'description' => 'Iuran kegiatan sekali bayar untuk simulasi UAT.',
                'is_active' => true,
            ],
        );

        /*
         * Periode diambil dari bulan mulai tahun ajaran, bukan dari bulan
         * berjalan. Bulan berjalan berubah sendiri, sehingga seeder yang
         * dijalankan Agustus lalu September akan meninggalkan dua angkatan
         * tagihan yang keduanya "hasil seeding" — idempoten pada hari yang sama
         * saja bukan idempoten.
         */
        $period = $year->start_date?->format('Y-m') ?? now()->format('Y-m');

        $students = Student::query()
            ->where('school_id', $this->school->id)
            ->where('status', StudentStatus::Active->value)
            ->orderBy('nis')
            ->take(3)
            ->get();

        $amount = '350000.00';
        $paidPlan = ['350000.00', '150000.00', '0.00'];

        foreach ($students as $index => $student) {
            $paid = $paidPlan[$index] ?? '0.00';

            $fee = StudentFee::updateOrCreate(
                [
                    'school_id' => $this->school->id,
                    'student_id' => $student->getKey(),
                    'fee_type_id' => $monthly->getKey(),
                    'period' => $period,
                ],
                [
                    'academic_year_id' => $year->getKey(),
                    'amount' => $amount,
                    'amount_paid' => $paid,
                    // SPP-02 poin 2 — tanggal 10 pada periode tagihan.
                    'due_date' => $period.'-10',
                    // Statusnya diturunkan lewat rumus yang dipakai pencatat
                    // pembayaran sungguhan, bukan ditulis tangan: data demo yang
                    // statusnya tidak konsisten dengan angkanya akan membuat
                    // penguji melaporkan cacat yang tidak ada.
                    'status' => PaymentRecorder::statusFor($paid, $amount)->value,
                ],
            );

            if ($paid === '0.00') {
                continue;
            }

            Payment::updateOrCreate(
                [
                    'student_fee_id' => $fee->getKey(),
                    'reference_number' => 'SIM-'.$period.'-'.$student->nis,
                ],
                [
                    'school_id' => $this->school->id,
                    'student_id' => $student->getKey(),
                    'payment_method' => PaymentMethod::Cash->value,
                    'amount_paid' => $paid,
                    'payment_date' => $period.'-05',
                    'received_by' => $bendahara->getKey(),
                    'notes' => 'Pembayaran simulasi UAT.',
                ],
            );
        }

        // Buku kas: satu masuk, satu keluar. Cukup agar halaman transaksi dan
        // laporan keuangan punya bentuk, tidak lebih.
        Transaction::updateOrCreate(
            ['school_id' => $this->school->id, 'reference_number' => 'SIM-KAS-MASUK'],
            [
                'type' => TransactionType::Income->value,
                'category' => 'SPP',
                'amount' => 500000,
                'description' => 'Setoran SPP simulasi.',
                'transaction_date' => $period.'-05',
                'created_by' => $bendahara->getKey(),
            ],
        );

        Transaction::updateOrCreate(
            ['school_id' => $this->school->id, 'reference_number' => 'SIM-KAS-KELUAR'],
            [
                'type' => TransactionType::Expense->value,
                'category' => 'Operasional',
                'amount' => 125000,
                'description' => 'Belanja alat tulis simulasi.',
                'transaction_date' => $period.'-07',
                'created_by' => $bendahara->getKey(),
            ],
        );
    }

    /**
     * Kata sandi memakai jalur yang sama dengan seeder demo yang sudah ada, dan
     * **tidak pernah** dicetak maupun ditulis ke dokumen.
     */
    protected function account(string $email, string $name, RoleName $role): User
    {
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'school_id' => $this->school->id,
                'name' => $name,
                'password' => Hash::make(SeedPassword::resolve()),
                'locale' => 'id',
                'is_active' => true,
                // Akun demo langsung dapat dipakai tanpa layar ganti sandi —
                // preseden Sprint4DemoSeeder.
                'must_change_password' => false,
                'email_verified_at' => now(),
            ],
        );

        $user->syncRoles([$role->value]);

        return $user;
    }

    protected function report(): void
    {
        $this->command?->info('Data simulasi siap. Akun (kata sandi tidak dicetak):');

        $rows = [];

        foreach (self::UAT_ACCOUNTS as $email => $role) {
            $rows[] = [$role, $email];
        }

        $this->command?->table(['Peran', 'Surel'], $rows);

        $this->command?->line('  Panduan penguji : docs/uat/role-testing.md');
    }
}
