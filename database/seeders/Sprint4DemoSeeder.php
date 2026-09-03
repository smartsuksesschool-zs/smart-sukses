<?php

namespace Database\Seeders;

use App\Enums\AssessmentType;
use App\Enums\Gender;
use App\Enums\GradeConfigStatus;
use App\Enums\GradeType;
use App\Enums\RoleName;
use App\Enums\StudentClassStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Grade;
use App\Models\GradeConfig;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\Grading\GradeConfigVersionManager;
use App\Support\SeedPassword;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Data demo untuk menguji Sprint 4 (Akademik — Penilaian & E-Rapor) langsung
 * dari UI.
 *
 * SEEDER KHUSUS DEV. Sengaja TIDAK didaftarkan di DatabaseSeeder supaya tidak
 * ikut berjalan saat deploy produksi. Jalankan manual:
 *
 *   php artisan db:seed --class=Sprint4DemoSeeder
 *
 * Aman dijalankan berulang: setiap baris dibuat lewat updateOrCreate pada
 * natural key-nya, dan tidak ada satu pun data yang dihapus.
 *
 * Tiga mata pelajaran sengaja dibuat dengan kondisi berbeda agar setiap jalur
 * Sprint 4 punya data untuk diuji:
 *
 *   MTK — konfigurasi ACTIVE, nilai lengkap, plus satu nilai FORMATIVE dan
 *         satu ATTITUDE. Menguji rata-rata harian (keputusan butir 1),
 *         pengecualian formatif, dan predikat sikap.
 *   BIN — konfigurasi ACTIVE, nilai lengkap, plus satu SKILL sumatif yang
 *         TIDAK ada di konfigurasi. Menguji peringatan C-6.
 *   IPA — tanpa konfigurasi sama sekali. Nilainya tidak mendapat snapshot
 *         bobot, sehingga rapor tampil "belum lengkap" beserta alasannya —
 *         sekaligus membuktikan bahwa "belum dikonfigurasi" tidak dianggap
 *         sama dengan "sudah LOCKED".
 *
 * Rapor sengaja TIDAK dibuat: menekan Generate Rapor Kelas → Terbitkan →
 * Generate PDF Kelas dari UI justru inti pengujian manualnya.
 */
class Sprint4DemoSeeder extends Seeder
{
    protected School $school;

    protected AcademicYear $year;

    protected User $teacher;

    protected User $homeroom;

    public function run(): void
    {
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

        $school = School::query()->where('code', 'PUSAT')->first();

        if ($school === null) {
            $this->command?->error('Cabang PUSAT belum ada. Jalankan SchoolSeeder lebih dulu.');

            return;
        }

        $this->school = $school;

        $this->seedUsers();
        $this->year = $this->seedAcademicYear();
        $class = $this->seedClass();
        $students = $this->seedStudents($class);

        $subjects = [
            'MTK' => $this->seedSubject('MTK', 'Matematika'),
            'BIN' => $this->seedSubject('BIN', 'Bahasa Indonesia'),
            'IPA' => $this->seedSubject('IPA', 'Ilmu Pengetahuan Alam'),
        ];

        $classSubjects = [];

        foreach ($subjects as $code => $subject) {
            $classSubjects[$code] = $this->seedClassSubject($class, $subject);
        }

        // Konfigurasi HARUS aktif sebelum nilai dibuat: hook Grade::creating
        // mengambil snapshot bobot dari konfigurasi yang berstatus ACTIVE saat
        // itu juga. Nilai yang lahir lebih dulu akan ber-weight NULL dan
        // raporya tidak akan pernah bisa lengkap.
        $this->seedGradeConfig($subjects['MTK']);
        $this->seedGradeConfig($subjects['BIN']);
        // IPA sengaja dilewati.

        foreach ($students as $index => $student) {
            $this->seedMathGrades($student, $classSubjects['MTK'], $index);
            $this->seedIndonesianGrades($student, $classSubjects['BIN'], $index);
            $this->seedScienceGrades($student, $classSubjects['IPA'], $index);
        }

        $this->report();
    }

    protected function seedUsers(): void
    {
        $password = SeedPassword::resolve();

        $this->teacher = $this->seedUser(
            'guru.pusat@smartsukses.sch.id',
            'Guru Mata Pelajaran',
            RoleName::Guru,
            $password,
        );

        $this->homeroom = $this->seedUser(
            'walikelas.pusat@smartsukses.sch.id',
            'Wali Kelas X-A',
            RoleName::WaliKelas,
            $password,
        );
    }

    protected function seedUser(string $email, string $name, RoleName $role, string $password): User
    {
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'school_id' => $this->school->id,
                'name' => $name,
                'password' => Hash::make($password),
                'locale' => 'id',
                'is_active' => true,
                // Akun demo langsung dapat dipakai tanpa layar ganti sandi.
                'must_change_password' => false,
                'email_verified_at' => now(),
            ],
        );

        $user->syncRoles([$role->value]);

        return $user;
    }

    /**
     * `academic_years.name` hanya varchar(20), jadi namanya sengaja pendek.
     */
    protected function seedAcademicYear(): AcademicYear
    {
        return AcademicYear::updateOrCreate(
            ['school_id' => $this->school->id, 'name' => '2026/2027 Ganjil'],
            [
                'start_date' => '2026-07-13',
                'end_date' => '2026-12-19',
                'semester' => 1,
                'is_active' => true,
            ],
        );
    }

    protected function seedClass(): SchoolClass
    {
        return SchoolClass::updateOrCreate(
            [
                'school_id' => $this->school->id,
                'academic_year_id' => $this->year->id,
                'name' => 'X-A',
            ],
            [
                'grade_level' => 10,
                'room' => 'R101',
                'capacity' => 30,
                'homeroom_teacher_id' => $this->homeroom->id,
            ],
        );
    }

    /**
     * @return array<int, Student>
     */
    protected function seedStudents(SchoolClass $class): array
    {
        $rows = [
            ['nis' => '240001', 'name' => 'Aisyah Nur Fadhilah', 'gender' => Gender::Female],
            ['nis' => '240002', 'name' => 'Bagas Prasetyo', 'gender' => Gender::Male],
            ['nis' => '240003', 'name' => 'Citra Ayu Lestari', 'gender' => Gender::Female],
        ];

        $students = [];

        foreach ($rows as $row) {
            $student = Student::updateOrCreate(
                ['school_id' => $this->school->id, 'nis' => $row['nis']],
                [
                    'full_name' => $row['name'],
                    'gender' => $row['gender']->value,
                    'birth_place' => 'Surabaya',
                    'birth_date' => '2010-05-17',
                    'religion' => 'Islam',
                    'parent_name' => 'Orang Tua '.$row['name'],
                    'parent_phone' => '0812'.$row['nis'].'00',
                    'entry_year' => 2026,
                    'status' => StudentStatus::Active->value,
                ],
            );

            StudentClass::updateOrCreate(
                [
                    'student_id' => $student->id,
                    'class_id' => $class->id,
                    'academic_year_id' => $this->year->id,
                ],
                [
                    'school_id' => $this->school->id,
                    'status' => StudentClassStatus::Active->value,
                ],
            );

            $students[] = $student;
        }

        return $students;
    }

    protected function seedSubject(string $code, string $name): Subject
    {
        return Subject::updateOrCreate(
            ['school_id' => $this->school->id, 'code' => $code],
            ['name' => $name, 'credit_hours' => 4, 'is_active' => true],
        );
    }

    protected function seedClassSubject(SchoolClass $class, Subject $subject): ClassSubject
    {
        return ClassSubject::updateOrCreate(
            [
                'class_id' => $class->id,
                'subject_id' => $subject->id,
                'academic_year_id' => $this->year->id,
            ],
            [
                'school_id' => $this->school->id,
                'teacher_id' => $this->teacher->id,
            ],
        );
    }

    /**
     * Konfigurasi bobot standar NILAI-02: Harian 40%, UTS 30%, UAS 30%.
     *
     * Susunannya divalidasi lebih dulu lewat GradeConfigVersionManager agar
     * data demo yang keliru ketahuan saat seeding, bukan saat rapor dihitung.
     */
    protected function seedGradeConfig(Subject $subject): GradeConfig
    {
        $components = [
            ['type' => GradeType::Daily->value, 'weight' => 0.40],
            ['type' => GradeType::Midterm->value, 'weight' => 0.30],
            ['type' => GradeType::Final->value, 'weight' => 0.30],
        ];

        app(GradeConfigVersionManager::class)->validateComponents($components);

        return GradeConfig::updateOrCreate(
            [
                'school_id' => $this->school->id,
                'subject_id' => $subject->id,
                'academic_year_id' => $this->year->id,
                'version' => 1,
            ],
            [
                'components' => $components,
                'created_by' => $this->adminId(),
                'status' => GradeConfigStatus::Active->value,
                'activated_at' => now(),
            ],
        );
    }

    /**
     * MTK — jalur bahagia sekaligus dua pembuktian keputusan butir 1 dan 3.
     *
     * Nilai harian siswa pertama sengaja 80, 90, 70 supaya rata-ratanya tepat
     * 80, persis contoh pada keputusan Pak Akbar. Satu nilai harian FORMATIVE
     * bernilai rendah ikut disisipkan untuk membuktikan bahwa nilai formatif
     * tidak menggeser hasil apa pun.
     */
    protected function seedMathGrades(Student $student, ClassSubject $classSubject, int $index): void
    {
        $dailies = [
            [80, 90, 70],
            [75, 85, 80],
            [90, 95, 85],
        ][$index];

        foreach ($dailies as $sequence => $score) {
            $this->seedGrade($student, $classSubject, GradeType::Daily, $score, 'Ulangan Harian '.($sequence + 1));
        }

        $this->seedGrade($student, $classSubject, GradeType::Midterm, [75, 80, 88][$index], 'UTS Ganjil');
        $this->seedGrade($student, $classSubject, GradeType::Final, [85, 78, 92][$index], 'UAS Ganjil');

        // Formatif — tidak boleh ikut menghitung nilai akhir.
        $this->seedGrade(
            $student,
            $classSubject,
            GradeType::Daily,
            30,
            'Latihan harian (formatif, tidak dihitung)',
            AssessmentType::Formative,
        );

        // Sikap — dilaporkan terpisah sebagai predikat, bukan nilai akademik.
        $this->seedGrade($student, $classSubject, GradeType::Attitude, [90, 80, 70][$index], 'Observasi sikap semester');
    }

    /**
     * BIN — nilai lengkap, ditambah satu komponen SKILL sumatif yang tidak
     * tercantum di Grade Config. Inilah data uji untuk C-6: nilainya tersimpan
     * tetapi tidak pernah ikut menghitung, dan sekarang dilaporkan.
     */
    protected function seedIndonesianGrades(Student $student, ClassSubject $classSubject, int $index): void
    {
        $this->seedGrade($student, $classSubject, GradeType::Daily, [82, 78, 88][$index], 'Ulangan Harian 1');
        $this->seedGrade($student, $classSubject, GradeType::Midterm, [80, 76, 90][$index], 'UTS Ganjil');
        $this->seedGrade($student, $classSubject, GradeType::Final, [85, 82, 87][$index], 'UAS Ganjil');

        $this->seedGrade(
            $student,
            $classSubject,
            GradeType::Skill,
            [95, 90, 93][$index],
            'Praktik membaca puisi (di luar Grade Config)',
        );
    }

    /**
     * IPA — tanpa Grade Config. Nilainya tersimpan dengan weight NULL,
     * sehingga rapor melaporkannya sebagai belum lengkap beserta alasannya.
     */
    protected function seedScienceGrades(Student $student, ClassSubject $classSubject, int $index): void
    {
        $this->seedGrade($student, $classSubject, GradeType::Daily, [70, 75, 80][$index], 'Ulangan Harian 1');
        $this->seedGrade($student, $classSubject, GradeType::Midterm, [72, 77, 82][$index], 'UTS Ganjil');
    }

    /**
     * Satu entri nilai, idempoten.
     *
     * `grades` tidak punya unique index, jadi `description` dipakai sebagai
     * pelengkap natural key — tanpa itu, menjalankan seeder dua kali akan
     * menggandakan seluruh nilai. Deskripsinya sekaligus terbaca sebagai
     * keterangan yang wajar di UI.
     *
     * `graded_by` diisi eksplisit karena hook Grade::creating mengandalkan
     * Auth::id(), yang selalu NULL saat seeder berjalan di konsol.
     */
    protected function seedGrade(
        Student $student,
        ClassSubject $classSubject,
        GradeType $type,
        float $score,
        string $description,
        ?AssessmentType $assessment = null,
    ): Grade {
        return Grade::updateOrCreate(
            [
                'student_id' => $student->id,
                'class_subject_id' => $classSubject->id,
                'grade_type' => $type->value,
                'description' => $description,
            ],
            [
                'school_id' => $this->school->id,
                'academic_year_id' => $this->year->id,
                'assessment_type' => ($assessment ?? AssessmentType::Summative)->value,
                'score' => $score,
                'graded_by' => $this->teacher->id,
                'graded_at' => now(),
            ],
        );
    }

    protected function adminId(): int
    {
        return User::query()
            ->where('school_id', $this->school->id)
            ->where('email', 'admin.pusat@smartsukses.sch.id')
            ->value('id') ?? $this->homeroom->id;
    }

    protected function report(): void
    {
        $this->command?->info('Data demo Sprint 4 siap:');
        $this->command?->line("  Cabang          : {$this->school->name} (ID {$this->school->id})");
        $this->command?->line("  Tahun ajaran    : {$this->year->name}");
        $this->command?->line('  Guru mapel      : guru.pusat@smartsukses.sch.id');
        $this->command?->line('  Wali kelas      : walikelas.pusat@smartsukses.sch.id');
        $this->command?->line('  MTK             : config ACTIVE, nilai lengkap + formatif + sikap');
        $this->command?->line('  BIN             : config ACTIVE, nilai lengkap + SKILL di luar config (C-6)');
        $this->command?->line('  IPA             : tanpa config — rapor akan tampil belum lengkap');
        $this->command?->line('  Rapor           : sengaja belum dibuat, silakan Generate dari UI');
    }
}
