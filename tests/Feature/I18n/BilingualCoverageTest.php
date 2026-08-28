<?php

namespace Tests\Feature\I18n;

use App\Enums\AssessmentType;
use App\Enums\ExamStatus;
use App\Enums\GradeType;
use App\Enums\NotificationType;
use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Enums\StudentClassStatus;
use App\Enums\StudentFeeStatus;
use App\Enums\StudentStatus;
use App\Filament\Pages\InputNilai;
use App\Filament\Pages\LaporanKeuangan;
use App\Filament\Resources\ExamResource;
use App\Filament\Resources\GradeResource;
use App\Filament\Resources\NotificationResource;
use App\Filament\Resources\StudentFeeResource;
use App\Filament\Resources\StudentResource;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentFee;
use App\Models\User;
use App\Support\Locale;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * NFR 1.4 — cakupan ID/EN pada alur kerja utama.
 *
 * Yang diuji: label yang benar-benar dilihat pengguna berubah bahasa, dan
 * **hanya** itu yang berubah. Nilai yang tersimpan di database — nilai enum,
 * angka keuangan, kunci jawaban — tidak boleh ikut bergerak hanya karena
 * antarmukanya berbahasa Inggris.
 *
 * Setiap uji memeriksa elemen atau label semantik yang memang diterjemahkan,
 * bukan membandingkan seluruh dokumen. Perbandingan seluruh dokumen sudah
 * terbukti rapuh terhadap urutan tes pada suite ini (butir 366).
 */
class BilingualCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected AcademicYear $year;

    protected SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create(['code' => 'MADANI', 'is_active' => true]);
        $this->year = AcademicYear::factory()->create([
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $this->class = SchoolClass::factory()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->year->id,
            'name' => '7A',
        ]);
    }

    // ================================================== A. halaman muka publik

    public function test_the_public_landing_page_reads_in_both_languages(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Platform Manajemen Sekolah Terintegrasi', escape: false)
            ->assertSee('Akses Pengguna', escape: false);

        $this->inEnglish()
            ->get('/')
            ->assertOk()
            ->assertSee('An Integrated School Management Platform', escape: false)
            ->assertSee('User Access', escape: false)
            ->assertDontSee('Platform Manajemen Sekolah Terintegrasi', escape: false);
    }

    public function test_the_landing_feature_list_reads_in_both_languages(): void
    {
        $this->get('/')->assertOk()->assertSee('Ujian Online', escape: false);

        $this->inEnglish()
            ->get('/')
            ->assertOk()
            ->assertSee('Online Exams', escape: false)
            ->assertSee('Online Admissions', escape: false);
    }

    // ============================================== B. halaman publik PPDB

    public function test_the_public_ppdb_branch_list_reads_in_both_languages(): void
    {
        $this->get(route('ppdb.schools'))
            ->assertOk()
            ->assertSee('Pilih Cabang', escape: false);

        $this->inEnglish()
            ->get(route('ppdb.schools'))
            ->assertOk()
            ->assertSee('Select Branch', escape: false);
    }

    public function test_the_public_ppdb_form_reads_in_both_languages(): void
    {
        $this->get(route('ppdb.register', ['schoolCode' => 'madani']))
            ->assertOk()
            ->assertSee('Formulir Pendaftaran', escape: false);

        $this->inEnglish()
            ->get(route('ppdb.register', ['schoolCode' => 'madani']))
            ->assertOk()
            ->assertSee('Application Form', escape: false);
    }

    public function test_the_public_ppdb_status_check_reads_in_both_languages(): void
    {
        $this->get(route('ppdb.check-status'))
            ->assertOk()
            ->assertSee('Cek Status Pendaftaran', escape: false);

        $this->inEnglish()
            ->get(route('ppdb.check-status'))
            ->assertOk()
            ->assertSee('Check Admission Status', escape: false);
    }

    // ================================================ C/F. halaman masuk

    public function test_the_student_login_page_reads_in_both_languages(): void
    {
        $this->get(route('student.login'))
            ->assertOk()
            ->assertSee('Kata Sandi', escape: false)
            ->assertSee('Ingat saya di perangkat ini', escape: false);

        $this->inEnglish()
            ->get(route('student.login'))
            ->assertOk()
            ->assertSee('Password', escape: false)
            ->assertSee('Remember me on this device', escape: false);
    }

    public function test_the_parent_login_page_reads_in_both_languages(): void
    {
        $this->get(route('portal.login'))
            ->assertOk()
            ->assertSee('Portal Orang Tua', escape: false);

        $this->inEnglish()
            ->get(route('portal.login'))
            ->assertOk()
            ->assertSee('Parent Portal', escape: false);
    }

    // ================================================== C. portal siswa

    public function test_the_student_portal_navigation_reads_in_both_languages(): void
    {
        $student = $this->student();

        $this->actingAs($student->user)->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Jadwal', escape: false)
            ->assertSee('Keluar', escape: false);

        $this->englishUser($student->user);

        $this->actingAs($student->user->fresh())->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Schedule', escape: false)
            ->assertSee('Sign out', escape: false);
    }

    public function test_the_student_grades_page_reads_in_both_languages(): void
    {
        $student = $this->student();

        $this->actingAs($student->user)->get(route('student.grades'))
            ->assertOk()
            ->assertSee('Belum ada nilai pada tahun ajaran yang sedang berjalan.', escape: false);

        $this->englishUser($student->user);

        $this->actingAs($student->user->fresh())->get(route('student.grades'))
            ->assertOk()
            ->assertSee('No grades have been entered for the current academic year.', escape: false)
            ->assertDontSee('Belum ada nilai pada tahun ajaran yang sedang berjalan.', escape: false);
    }

    // =========================================== I. CBT (label utama saja)

    public function test_the_student_exam_list_reads_in_both_languages(): void
    {
        $student = $this->student();

        $this->actingAs($student->user)->get(route('student.exams'))
            ->assertOk()
            ->assertSee('Ujian Online', escape: false)
            ->assertSee('Belum ada ujian online untuk kelas Anda.', escape: false);

        $this->englishUser($student->user);

        $this->actingAs($student->user->fresh())->get(route('student.exams'))
            ->assertOk()
            ->assertSee('Online Exams', escape: false)
            ->assertSee('There are no online exams for your class yet.', escape: false);
    }

    // ================================================ D. portal orang tua

    public function test_the_parent_portal_reads_in_both_languages(): void
    {
        $parent = $this->userWith(RoleName::OrangTua);
        $this->student($parent);

        $this->actingAs($parent)->get(route('portal.dashboard'))
            ->assertOk()
            ->assertSee('Tagihan Belum Lunas', escape: false)
            ->assertSee('Nilai Terbaru', escape: false);

        $this->englishUser($parent);

        $this->actingAs($parent->fresh())->get(route('portal.dashboard'))
            ->assertOk()
            ->assertSee('Outstanding Fees', escape: false)
            ->assertSee('Latest Grades', escape: false);
    }

    public function test_the_parent_fees_page_reads_in_both_languages(): void
    {
        $parent = $this->userWith(RoleName::OrangTua);
        $this->student($parent);

        $this->actingAs($parent)->get(route('portal.fees'))
            ->assertOk()
            ->assertSee('Belum ada tagihan untuk anak ini.', escape: false);

        $this->englishUser($parent);

        $this->actingAs($parent->fresh())->get(route('portal.fees'))
            ->assertOk()
            ->assertSee('There are no fees for this child yet.', escape: false);
    }

    // ==================================================== E. portal guru

    public function test_the_teacher_portal_reads_in_both_languages(): void
    {
        $teacher = $this->userWith(RoleName::Guru);

        $this->actingAs($teacher)->get(route('teacher.dashboard'))
            ->assertOk()
            ->assertSee('Dasbor Kerja', escape: false)
            ->assertSee('Kelas Aktif', escape: false);

        $this->englishUser($teacher);

        $this->actingAs($teacher->fresh())->get(route('teacher.dashboard'))
            ->assertOk()
            ->assertSee('Work Dashboard', escape: false)
            ->assertSee('Current Class', escape: false);
    }

    public function test_the_teacher_schedule_page_reads_in_both_languages(): void
    {
        $teacher = $this->userWith(RoleName::Guru);

        $this->actingAs($teacher)->get(route('teacher.schedule'))
            ->assertOk()
            ->assertSee('Belum ada jadwal mengajar untuk Anda.', escape: false);

        $this->englishUser($teacher);

        $this->actingAs($teacher->fresh())->get(route('teacher.schedule'))
            ->assertOk()
            ->assertSee('You have no teaching schedule yet.', escape: false);
    }

    // =========================================== L. notifikasi (portal)

    public function test_the_notification_inbox_reads_in_both_languages(): void
    {
        $teacher = $this->userWith(RoleName::Guru);

        $this->actingAs($teacher)->get(route('teacher.notifications'))
            ->assertOk()
            ->assertSee('Notifikasi', escape: false)
            ->assertSee('Pengumuman dari sekolah akan muncul di halaman ini.', escape: false);

        $this->englishUser($teacher);

        $this->actingAs($teacher->fresh())->get(route('teacher.notifications'))
            ->assertOk()
            ->assertSee('Notifications', escape: false)
            ->assertSee('Announcements from the school will appear on this page.', escape: false);
    }

    // ================================ G. navigasi, resource & page Filament

    public function test_filament_navigation_and_resource_labels_read_in_both_languages(): void
    {
        $expected = [
            'id' => [
                [StudentResource::class, 'Data Siswa', 'Master Data'],
                [StudentFeeResource::class, 'Tagihan Siswa', 'Keuangan'],
                [GradeResource::class, 'Daftar Nilai', 'Akademik'],
                [ExamResource::class, 'Ujian Online', 'Akademik'],
                [NotificationResource::class, 'Pengumuman', 'Komunikasi'],
            ],
            'en' => [
                [StudentResource::class, 'Student Data', 'Master Data'],
                [StudentFeeResource::class, 'Student Fees', 'Finance'],
                [GradeResource::class, 'Grade List', 'Academic'],
                [ExamResource::class, 'Online Exams', 'Academic'],
                [NotificationResource::class, 'Announcement', 'Communication'],
            ],
        ];

        foreach ($expected as $locale => $rows) {
            app()->setLocale($locale);

            foreach ($rows as [$resource, $label, $group]) {
                $this->assertSame($label, $resource::getNavigationLabel(), $resource.' @ '.$locale);
                $this->assertSame($group, $resource::getNavigationGroup(), $resource.' group @ '.$locale);
            }
        }
    }

    public function test_filament_custom_page_titles_read_in_both_languages(): void
    {
        app()->setLocale('id');
        $this->assertSame('Input Nilai', InputNilai::getNavigationLabel());
        $this->assertSame('Laporan Keuangan', LaporanKeuangan::getNavigationLabel());

        app()->setLocale('en');
        $this->assertSame('Grade Entry', InputNilai::getNavigationLabel());
        $this->assertSame('Financial Reports', LaporanKeuangan::getNavigationLabel());
    }

    /**
     * Panel itu sendiri harus terbuka dalam bahasa Inggris bagi peran yang
     * memakainya. Kelima peran panel diuji, bukan hanya Admin Sekolah.
     */
    public function test_the_admin_panel_opens_in_english_for_every_panel_role(): void
    {
        foreach ([
            RoleName::SchoolAdmin,
            RoleName::KepalaSekolah,
            RoleName::Guru,
            RoleName::WaliKelas,
            RoleName::Bendahara,
        ] as $role) {
            $user = $this->userWith($role);
            $this->englishUser($user);

            $this->actingAs($user->fresh())
                ->get(route('filament.admin.pages.dashboard'))
                ->assertOk()
                ->assertSee(route('locale.switch', ['locale' => 'id'], absolute: false), escape: false);
        }
    }

    // ================================================= H. pesan validasi

    public function test_validation_messages_read_in_both_languages(): void
    {
        app()->setLocale('id');
        $indonesian = Validator::make([], ['email' => 'required|email'])->errors()->first('email');

        app()->setLocale('en');
        $english = Validator::make([], ['email' => 'required|email'])->errors()->first('email');

        $this->assertSame('Kolom surel wajib diisi.', $indonesian);
        $this->assertSame('The email field is required.', $english);
        $this->assertNotSame($indonesian, $english);
    }

    public function test_a_login_validation_error_reads_in_both_languages(): void
    {
        $this->get(route('student.login'))->assertOk();

        app()->setLocale('id');
        $this->assertSame(
            'Kolom kata sandi wajib diisi.',
            Validator::make([], ['password' => 'required'])->errors()->first('password'),
        );

        app()->setLocale('en');
        $this->assertSame(
            'The password field is required.',
            Validator::make([], ['password' => 'required'])->errors()->first('password'),
        );
    }

    // ============================ I/J/K/L. enum: label ikut, nilai tidak

    public function test_enum_labels_translate_but_stored_values_never_change(): void
    {
        $cases = [
            [StudentFeeStatus::Unpaid, 'UNPAID', 'Belum Bayar', 'Unpaid'],
            [StudentFeeStatus::Paid, 'PAID', 'Lunas', 'Paid in Full'],
            [StudentFeeStatus::Waived, 'WAIVED', 'Dibebaskan', 'Waived'],
            [AssessmentType::Formative, 'FORMATIVE', 'Formatif (tidak dihitung)', 'Formative (not counted)'],
            [AssessmentType::Summative, 'SUMMATIVE', 'Sumatif (dihitung)', 'Summative (counted)'],
            [PaymentMethod::Cash, 'CASH', 'Tunai', 'Cash'],
            [StudentStatus::Active, 'ACTIVE', 'Aktif', 'Active'],
        ];

        foreach ($cases as [$enum, $stored, $indonesian, $english]) {
            $this->assertSame($stored, $enum->value, 'the persisted value must not change');

            app()->setLocale('id');
            $this->assertSame($indonesian, $enum->label());

            app()->setLocale('en');
            $this->assertSame($english, $enum->label());

            // Nilai tersimpan tetap sama setelah bahasanya berganti.
            $this->assertSame($stored, $enum->value);
        }
    }

    /**
     * Contoh yang disebut spesifik dalam spesifikasi: FORMATIVE tetap
     * FORMATIVE di dalam sistem, apa pun bahasa antarmukanya.
     */
    public function test_the_formative_value_stays_formative_in_english(): void
    {
        app()->setLocale('en');

        $this->assertSame('FORMATIVE', AssessmentType::Formative->value);
        $this->assertSame('FORMATIVE', AssessmentType::from('FORMATIVE')->value);
        $this->assertNull(AssessmentType::tryFrom('Formative (not counted)'));
    }

    public function test_every_enum_case_value_is_untouched_by_locale(): void
    {
        $enums = [
            AssessmentType::class,
            ExamStatus::class,
            GradeType::class,
            NotificationType::class,
            PaymentMethod::class,
            RoleName::class,
            StudentClassStatus::class,
            StudentFeeStatus::class,
            StudentStatus::class,
        ];

        app()->setLocale('id');
        $indonesian = $this->enumValues($enums);

        app()->setLocale('en');
        $english = $this->enumValues($enums);

        $this->assertSame($indonesian, $english, 'enum values must be locale-independent');
    }

    // =========================================== K. keuangan: angka tetap

    /**
     * Bahasa mengubah label, tidak pernah perhitungan. Angka rupiah dan status
     * tagihan harus identik pada kedua bahasa.
     */
    public function test_financial_figures_are_identical_in_both_languages(): void
    {
        $parent = $this->userWith(RoleName::OrangTua);
        $student = $this->student($parent);

        $fee = StudentFee::factory()->create([
            'school_id' => $this->school->id,
            'student_id' => $student->id,
            'amount' => 750000,
            'amount_paid' => 250000,
            'status' => StudentFeeStatus::Partial->value,
        ]);

        $indonesian = $this->actingAs($parent)->get(route('portal.fees'))->assertOk()->getContent();

        $this->englishUser($parent);
        $english = $this->actingAs($parent->fresh())->get(route('portal.fees'))->assertOk()->getContent();

        foreach (['750.000', '250.000', '500.000'] as $amount) {
            $this->assertStringContainsString($amount, $indonesian, $amount.' missing in ID');
            $this->assertStringContainsString($amount, $english, $amount.' missing in EN');
        }

        // Baris database tidak tersentuh oleh pergantian bahasa.
        $fee->refresh();
        $this->assertSame('750000.00', (string) $fee->amount);
        $this->assertSame('250000.00', (string) $fee->amount_paid);
        $this->assertSame(StudentFeeStatus::Partial, $fee->status);
    }

    // ================================================== cakupan terukur

    /**
     * Setiap kunci `__()` yang benar-benar tertulis di kode ada di kedua berkas
     * terjemahan. Ini yang membuat klaim cakupan dapat diperiksa, bukan
     * disebut-sebut saja.
     */
    public function test_every_translation_key_in_the_code_exists_in_both_files(): void
    {
        $keys = $this->translationKeysInSource();

        $this->assertGreaterThan(500, count($keys), 'the key scanner found suspiciously few keys');

        $en = json_decode(file_get_contents(lang_path('en.json')), true);
        $id = json_decode(file_get_contents(lang_path('id.json')), true);

        $missingEn = array_values(array_diff($keys, array_keys($en)));
        $missingId = array_values(array_diff($keys, array_keys($id)));

        $this->assertSame([], $missingEn, 'keys missing from lang/en.json');
        $this->assertSame([], $missingId, 'keys missing from lang/id.json');
    }

    /**
     * Terjemahan Inggris tidak boleh sekadar menyalin kuncinya. Beberapa kunci
     * memang identik dalam kedua bahasa (nama diri, singkatan), jadi yang
     * diperiksa proporsinya, bukan setiap barisnya.
     */
    public function test_the_english_file_is_actually_translated(): void
    {
        $en = json_decode(file_get_contents(lang_path('en.json')), true);

        $identical = array_filter($en, fn ($value, $key) => $value === $key, ARRAY_FILTER_USE_BOTH);

        $this->assertLessThan(
            count($en) * 0.2,
            count($identical),
            'too many English entries are identical to their Indonesian key',
        );
    }

    public function test_the_indonesian_file_covers_the_same_keys_as_the_english_one(): void
    {
        $en = array_keys(json_decode(file_get_contents(lang_path('en.json')), true));
        $id = array_keys(json_decode(file_get_contents(lang_path('id.json')), true));

        sort($en);
        sort($id);

        $this->assertSame($en, $id);
    }

    /**
     * Berkas terjemahannya JSON biasa yang dibaca Laravel sendiri — tidak ada
     * framework terjemahan tambahan dan tidak ada paket locale pihak ketiga
     * yang dipasang untuk ini.
     */
    public function test_no_third_party_locale_package_was_added(): void
    {
        $composer = json_decode(file_get_contents(base_path('composer.json')), true);
        $required = array_keys($composer['require'] ?? []) + array_keys($composer['require-dev'] ?? []);

        foreach ($required as $package) {
            $this->assertStringNotContainsString('laravel-lang', strtolower((string) $package));
            $this->assertStringNotContainsString('translation-manager', strtolower((string) $package));
        }
    }

    public function test_no_new_migration_was_needed_for_the_locale_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('users', 'locale'),
            'users.locale must already exist',
        );

        $added = glob(database_path('migrations/*locale*.php'));

        $this->assertSame([], $added, 'S9.3 must not add a locale migration');
    }

    // --------------------------------------------------------------- bantu

    protected function inEnglish(): static
    {
        $this->get(route('locale.switch', ['locale' => 'en']));

        return $this;
    }

    protected function englishUser(User $user): void
    {
        $user->forceFill(['locale' => 'en'])->save();
    }

    protected function userWith(RoleName $role): User
    {
        return User::factory()->forSchool($this->school)->withRole($role)->create(['locale' => 'id']);
    }

    protected function student(?User $parent = null): Student
    {
        $account = $this->userWith(RoleName::Siswa);

        $student = Student::factory()->create([
            'school_id' => $this->school->id,
            'user_id' => $account->id,
            'parent_user_id' => $parent?->getKey(),
            'status' => StudentStatus::Active->value,
        ]);

        StudentClass::factory()->create([
            'school_id' => $this->school->id,
            'student_id' => $student->id,
            'class_id' => $this->class->id,
            'academic_year_id' => $this->year->id,
            'status' => StudentClassStatus::Active->value,
        ]);

        $student->setRelation('user', $account);

        return $student;
    }

    /**
     * @param  array<int, class-string>  $enums
     * @return array<string, array<int, string>>
     */
    protected function enumValues(array $enums): array
    {
        $out = [];

        foreach ($enums as $enum) {
            $out[$enum] = array_map(fn ($case) => (string) $case->value, $enum::cases());
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    protected function translationKeysInSource(): array
    {
        $keys = [];

        foreach ([app_path(), resource_path('views'), base_path('routes')] as $root) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());

                preg_match_all(
                    '/(?:__|trans_choice)\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/',
                    $contents,
                    $matches,
                );

                foreach ($matches[1] as $raw) {
                    $keys[] = str_replace(["\\'", '\\\\'], ["'", '\\'], $raw);
                }
            }
        }

        return array_values(array_unique($keys));
    }
}
