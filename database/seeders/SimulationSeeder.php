<?php

namespace Database\Seeders;

use App\Enums\ExamQuestionType;
use App\Enums\ExamStatus;
use App\Enums\RoleName;
use App\Models\ClassSubject;
use App\Models\Exam;
use App\Models\ExamOption;
use App\Models\ExamQuestion;
use App\Models\Schedule;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
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
 *   4. satu ujian CBT terbit beserta soal dan kuncinya.
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

    protected School $school;

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'SimulationSeeder membuat akun yang dapat login dan tidak boleh berjalan di produksi.'
            );
        }

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
                'password' => Hash::make(env('SEED_ADMIN_PASSWORD', 'Password123')),
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

        $this->command?->table(
            ['Peran', 'Surel'],
            [
                ['SUPER_ADMIN', 'superadmin@smartsukses.sch.id'],
                ['SCHOOL_ADMIN', 'admin.pusat@smartsukses.sch.id'],
                ['KEPALA_SEKOLAH', 'kepsek.pusat@smartsukses.sch.id'],
                ['BENDAHARA', 'bendahara.pusat@smartsukses.sch.id'],
                ['GURU', 'guru.pusat@smartsukses.sch.id'],
                ['WALI_KELAS', 'walikelas.pusat@smartsukses.sch.id'],
                ['SISWA', 'siswa.pusat@smartsukses.sch.id'],
                ['ORANG_TUA', 'ortu.pusat@smartsukses.sch.id'],
            ],
        );
    }
}
