<?php

namespace Tests\Feature\MasterData;

use App\Enums\RoleName;
use App\Enums\StudentClassStatus;
use App\Enums\StudentStatus;
use App\Filament\Resources\StudentResource\Widgets\StudentRosterOverview;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Services\Admin\StudentRosterSummary;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ringkasan jumlah siswa di halaman Data Siswa (M9.2).
 *
 * Seluruh data karangan. Yang dijaga bukan tampilannya melainkan angkanya —
 * tiga aturan yang paling mudah dilanggar diam-diam: tingkat dibaca dari
 * `grade_level` dan bukan dari teks nama kelas, satu siswa dihitung sekali
 * walaupun pernah pindah kelas, dan cabang lain tidak pernah ikut terhitung
 * (butir 578, 579).
 */
class StudentRosterSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create(['code' => 'PUSAT', 'is_active' => true]);
        $this->year = AcademicYear::factory()->create([
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
    }

    protected function classFor(int $grade, string $name, ?School $school = null, ?AcademicYear $year = null): SchoolClass
    {
        return SchoolClass::factory()->create([
            'school_id' => ($school ?? $this->school)->id,
            'academic_year_id' => ($year ?? $this->year)->id,
            'name' => $name,
            'grade_level' => $grade,
        ]);
    }

    protected function studentIn(?SchoolClass $class, ?School $school = null, string $status = 'ACTIVE'): Student
    {
        $school ??= $this->school;

        $student = Student::factory()->create([
            'school_id' => $school->id,
            'status' => $status,
        ]);

        if ($class !== null) {
            StudentClass::create([
                'school_id' => $school->id,
                'student_id' => $student->id,
                'class_id' => $class->id,
                'academic_year_id' => $class->academic_year_id,
                'status' => StudentClassStatus::Active->value,
            ]);
        }

        return $student;
    }

    protected function admin(?School $school = null): User
    {
        return User::factory()
            ->forSchool($school ?? $this->school)
            ->withRole(RoleName::SchoolAdmin)
            ->create();
    }

    protected function summaryFor(User $user): array
    {
        return app(StudentRosterSummary::class)->for($user);
    }

    // ------------------------------------------------------------ hitungan

    public function test_total_dan_tingkat_dihitung_benar(): void
    {
        $x = $this->classFor(10, 'X Terbuka - 2');
        $xi = $this->classFor(11, 'XI Terbuka - 1');
        $xii = $this->classFor(12, 'XII Terbuka - 2');

        foreach (range(1, 4) as $i) {
            $this->studentIn($x);
        }
        foreach (range(1, 3) as $i) {
            $this->studentIn($xi);
        }
        foreach (range(1, 2) as $i) {
            $this->studentIn($xii);
        }

        $summary = $this->summaryFor($this->admin());

        $this->assertSame(9, $summary['total']);
        $this->assertSame(9, $summary['placed']);
        $this->assertSame(0, $summary['unplaced']);
        $this->assertSame(4, $summary['by_grade'][10]);
        $this->assertSame(3, $summary['by_grade'][11]);
        $this->assertSame(2, $summary['by_grade'][12]);
    }

    public function test_siswa_tanpa_penempatan_terhitung_belum_ada_kelas(): void
    {
        $x = $this->classFor(10, 'X Terbuka - 2');

        $this->studentIn($x);
        $this->studentIn(null);
        $this->studentIn(null);

        $summary = $this->summaryFor($this->admin());

        $this->assertSame(3, $summary['total']);
        $this->assertSame(1, $summary['placed']);
        $this->assertSame(2, $summary['unplaced']);
    }

    public function test_riwayat_pindah_kelas_tidak_menghitung_ganda(): void
    {
        /*
         * Baris lama tetap tinggal dengan status MOVED ketika siswa pindah
         * (KELAS-02). Menghitung barisnya, bukan siswanya, akan membuat setiap
         * anak yang pernah pindah tampil dua kali.
         */
        $lama = $this->classFor(10, 'X Terbuka - 2');
        $baru = $this->classFor(11, 'XI Terbuka - 1');

        $student = $this->studentIn($lama);

        StudentClass::query()
            ->where('student_id', $student->id)
            ->update(['status' => StudentClassStatus::Moved->value]);

        StudentClass::create([
            'school_id' => $this->school->id,
            'student_id' => $student->id,
            'class_id' => $baru->id,
            'academic_year_id' => $this->year->id,
            'status' => StudentClassStatus::Active->value,
        ]);

        $summary = $this->summaryFor($this->admin());

        $this->assertSame(1, $summary['total']);
        $this->assertSame(1, $summary['placed']);
        $this->assertSame(0, $summary['unplaced']);
        $this->assertArrayNotHasKey(10, $summary['by_grade']);
        $this->assertSame(1, $summary['by_grade'][11]);
    }

    public function test_tingkat_dibaca_dari_grade_level_bukan_nama_kelas(): void
    {
        // Nama yang menyesatkan dengan sengaja: teksnya "XII", tingkatnya 10.
        $menyesatkan = $this->classFor(10, 'XII Rasa Sepuluh');

        $this->studentIn($menyesatkan);

        $summary = $this->summaryFor($this->admin());

        $this->assertSame(1, $summary['by_grade'][10]);
        $this->assertArrayNotHasKey(12, $summary['by_grade']);
    }

    public function test_tahun_ajaran_lama_tidak_ikut_dihitung(): void
    {
        $lama = AcademicYear::factory()->create([
            'school_id' => $this->school->id,
            'is_active' => false,
        ]);

        $kelasLama = $this->classFor(12, 'XII Tahun Lalu', year: $lama);
        $this->studentIn($kelasLama);

        $summary = $this->summaryFor($this->admin());

        $this->assertSame(1, $summary['total']);
        $this->assertSame(0, $summary['placed']);
        $this->assertSame(1, $summary['unplaced']);
        $this->assertSame([], $summary['by_grade']);
    }

    public function test_siswa_tidak_aktif_tidak_ikut_dihitung(): void
    {
        $x = $this->classFor(10, 'X Terbuka - 2');

        $this->studentIn($x);
        $this->studentIn($x, status: StudentStatus::Graduated->value);

        $summary = $this->summaryFor($this->admin());

        $this->assertSame(1, $summary['total']);
        $this->assertSame(1, $summary['by_grade'][10]);
    }

    // --------------------------------------------------------- batas cabang

    public function test_admin_sekolah_hanya_menghitung_cabangnya_sendiri(): void
    {
        $x = $this->classFor(10, 'X Terbuka - 2');
        $this->studentIn($x);

        $lain = School::factory()->create(['code' => 'CABANG2']);
        $tahunLain = AcademicYear::factory()->create(['school_id' => $lain->id, 'is_active' => true]);
        $kelasLain = $this->classFor(11, 'XI Cabang Lain', school: $lain, year: $tahunLain);

        $this->studentIn($kelasLain, school: $lain);
        $this->studentIn(null, school: $lain);

        $summary = $this->summaryFor($this->admin());

        $this->assertSame(1, $summary['total']);
        $this->assertSame(1, $summary['placed']);
        $this->assertSame(0, $summary['unplaced']);
        $this->assertArrayNotHasKey(11, $summary['by_grade']);
        $this->assertSame('school', $summary['scope']);
    }

    public function test_super_admin_menghitung_lintas_cabang_dan_menyatakannya(): void
    {
        /*
         * Super Admin memang melihat seluruh cabang pada tabel di bawah, jadi
         * ringkasannya lintas cabang pula — dan `scope` menyatakannya, supaya
         * angkanya tidak terbaca sebagai angka satu sekolah (butir 579).
         */
        $x = $this->classFor(10, 'X Terbuka - 2');
        $this->studentIn($x);

        $lain = School::factory()->create(['code' => 'CABANG2']);
        $tahunLain = AcademicYear::factory()->create(['school_id' => $lain->id, 'is_active' => true]);
        $kelasLain = $this->classFor(11, 'XI Cabang Lain', school: $lain, year: $tahunLain);
        $this->studentIn($kelasLain, school: $lain);

        $summary = $this->summaryFor(User::factory()->superAdmin()->create());

        $this->assertSame(2, $summary['total']);
        $this->assertSame(1, $summary['by_grade'][10]);
        $this->assertSame(1, $summary['by_grade'][11]);
        $this->assertSame('all', $summary['scope']);
        $this->assertSame(
            ['CABANG2 · XI Cabang Lain' => 1, 'PUSAT · X Terbuka - 2' => 1],
            $summary['by_class'],
        );
    }

    public function test_rombel_bernama_sama_di_dua_cabang_tidak_melebur(): void
    {
        /*
         * Nama rombel hanya unik di dalam satu cabang. Tanpa pemisahan, Super
         * Admin akan membaca "X Terbuka - 2: 2" untuk dua rombel berisi satu —
         * angka yang tidak dimiliki cabang mana pun (butir 579).
         */
        $this->studentIn($this->classFor(10, 'X Terbuka - 2'));

        $lain = School::factory()->create(['code' => 'CABANG2']);
        $tahunLain = AcademicYear::factory()->create(['school_id' => $lain->id, 'is_active' => true]);
        $this->studentIn(
            $this->classFor(10, 'X Terbuka - 2', school: $lain, year: $tahunLain),
            school: $lain,
        );

        $summary = $this->summaryFor(User::factory()->superAdmin()->create());

        $this->assertSame(2, $summary['total']);
        $this->assertSame(2, $summary['by_grade'][10]);

        // Dua baris terpisah, masing-masing berlabel cabangnya.
        $this->assertSame(
            ['CABANG2 · X Terbuka - 2' => 1, 'PUSAT · X Terbuka - 2' => 1],
            $summary['by_class'],
        );
    }

    // ------------------------------------------------------------- tampilan

    public function test_widget_menampilkan_angka_yang_sama(): void
    {
        $x = $this->classFor(10, 'X Terbuka - 2');
        $xii = $this->classFor(12, 'XII Terbuka - 2');

        foreach (range(1, 3) as $i) {
            $this->studentIn($x);
        }
        $this->studentIn($xii);
        $this->studentIn(null);

        Livewire::actingAs($this->admin())
            ->test(StudentRosterOverview::class)
            ->assertOk()
            ->assertSee('Total Siswa')
            ->assertSee('Kelas X')
            ->assertSee('Belum Ada Kelas')
            // 5 aktif, 3 di tingkat 10, 1 tanpa kelas.
            ->assertSee('X Terbuka - 2: 3')
            ->assertSee('XII Terbuka - 2: 1');
    }

    /**
     * Halaman memasang widgetnya; isinya diuji pada widget itu sendiri.
     *
     * Widget Filament lazy secara bawaan — muatan pertama berisi kerangka, dan
     * angkanya menyusul pada permintaan berikutnya. Menegaskan teks kartu di
     * sini karena itu menguji waktu pemuatan Filament, bukan kode ini; pola
     * yang sudah dipakai SchoolStatsOverview adalah menegaskan bahwa
     * komponennya terpasang.
     */
    public function test_halaman_daftar_siswa_memasang_widget_ringkasan(): void
    {
        $x = $this->classFor(10, 'X Terbuka - 2');
        $this->studentIn($x);

        $this->actingAs($this->admin())
            ->get('/admin/students')
            ->assertOk()
            ->assertSeeLivewire(StudentRosterOverview::class);
    }

    /**
     * Kartu ini melaporkan keadaan, bukan kontrak.
     *
     * Roster resmi hari ini 12/13/14, tetapi angka itu berlaku pada satu hari
     * tertentu di satu cabang. Ringkasan yang mengetahui angka harapan akan
     * menyembunyikan justru hal yang perlu dilihat: rombel yang belum terisi.
     */
    public function test_ringkasan_melaporkan_keadaan_dan_bukan_kontrak_roster(): void
    {
        $this->studentIn($this->classFor(10, 'X Terbuka - 2'));
        $this->studentIn($this->classFor(11, 'XI Terbuka - 1'));

        // Tingkat XII punya rombelnya, tetapi belum satu pun siswa masuk.
        $this->classFor(12, 'XII Terbuka - 1');

        $summary = $this->summaryFor($this->admin());

        $this->assertSame(2, $summary['total']);
        $this->assertSame(1, $summary['by_grade'][10]);
        $this->assertSame(1, $summary['by_grade'][11]);
        $this->assertArrayNotHasKey(12, $summary['by_grade']);

        // Rombel kosong tidak muncul sebagai baris nol yang mengaburkan.
        $this->assertArrayNotHasKey('XII Terbuka - 1', $summary['by_class']);
        $this->assertSame(['X Terbuka - 2' => 1, 'XI Terbuka - 1' => 1], $summary['by_class']);
    }
}
