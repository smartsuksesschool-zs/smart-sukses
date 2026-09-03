<?php

namespace Tests\Feature\Migration;

use App\Models\AcademicYear;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Support\Migration\CanonicalRombel;
use App\Support\Migration\LegacyWorkbook;
use App\Support\Migration\StudentImportApply;
use App\Support\Migration\StudentImportPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * M4 — penyiapan rombel dan kesiapan impor siswa ujung ke ujung.
 *
 * Seluruh berkas uji dibangun saat tes berjalan dari data karangan lalu
 * dihapus. Tidak ada NIS, NISN, nama, atau alamat siswa sungguhan; NIS karangan
 * berawalan `Z`.
 *
 * Nama rombelnya sendiri **bukan** data pribadi — ia keputusan operasional yang
 * memang tertulis di repositori (CanonicalRombel), jadi memakainya di tes
 * adalah cara memeriksa kontraknya, bukan membocorkan apa pun.
 */
class RombelSetupTest extends TestCase
{
    use RefreshDatabase;

    protected string $path = '';

    protected function tearDown(): void
    {
        if ($this->path !== '' && is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    // ================================================== perkakas

    protected function school(string $code = 'PUSAT'): School
    {
        return School::factory()->create(['code' => $code]);
    }

    protected function year(School $school, string $name = '2026/2027 Ganjil'): AcademicYear
    {
        return AcademicYear::factory()->for($school)->create([
            'name' => $name,
            'semester' => 1,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    protected function workbook(array $rows): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Kelas 10');
        $sheet->fromArray(['No', 'NIS', 'NISN', 'Nama', 'Jenis Kelamin', 'TKB', 'Kelas di SMAN 11'], null, 'A1');

        foreach ($rows as $i => $row) {
            $sheet->fromArray($row, null, 'A'.($i + 2));
        }

        $this->path = tempnam(sys_get_temp_dir(), 'm4').'.xlsx';
        (new Xlsx($book))->save($this->path);

        return $this->path;
    }

    // ================================================== daftar kanonis

    public function test_empat_rombel_resmi_dan_tingkatnya(): void
    {
        $this->assertSame([
            'X Terbuka - 2' => 10,
            'XI Terbuka - 1' => 11,
            'XII Terbuka - 1' => 12,
            'XII Terbuka - 2' => 12,
        ], CanonicalRombel::CLASSES);
    }

    /**
     * Koreksi data terkonfirmasi: satu alias penuh, bukan aturan angka Romawi.
     */
    public function test_koreksi_label_hanya_satu_dan_bukan_aturan_umum(): void
    {
        $this->assertSame(['XII TERBUKA - I' => 'XII Terbuka - 1'], CanonicalRombel::ALIASES);

        $this->assertSame('XII Terbuka - 1', CanonicalRombel::canonicalLabel('XII Terbuka - I'));
        $this->assertSame('XII Terbuka - 1', CanonicalRombel::canonicalLabel('xii terbuka - i'));

        // Tidak ikut berubah.
        foreach (['XI Terbuka - I', 'X Terbuka - II', 'Kelas I', 'I'] as $untouched) {
            $this->assertSame($untouched, CanonicalRombel::canonicalLabel($untouched));
        }
    }

    public function test_rencana_impor_memakai_daftar_yang_sama(): void
    {
        $this->assertSame(
            CanonicalRombel::canonicalLabel('XII Terbuka - I'),
            StudentImportPlan::canonicalClassLabel('XII Terbuka - I'),
        );
    }

    // ================================================== perintah penyiapan

    public function test_membuat_tepat_empat_rombel_dengan_tingkat_yang_benar(): void
    {
        $school = $this->school();
        $year = $this->year($school);

        $this->artisan('migrasi:siapkan-rombel', ['--school' => 'PUSAT', '--konfirmasi' => true])
            ->assertExitCode(0);

        $classes = SchoolClass::query()
            ->where('school_id', $school->id)
            ->where('academic_year_id', $year->id)
            ->orderBy('name')
            ->get();

        $this->assertCount(4, $classes);

        foreach ($classes as $class) {
            $this->assertSame(
                CanonicalRombel::CLASSES[$class->name],
                $class->grade_level,
                "tingkat {$class->name} salah",
            );
        }

        $this->assertSame(CanonicalRombel::names(), $classes->pluck('name')->sort()->values()->all());
    }

    public function test_mode_kering_tidak_membuat_apa_pun(): void
    {
        $school = $this->school();
        $this->year($school);

        $this->artisan('migrasi:siapkan-rombel', ['--school' => 'PUSAT'])
            ->assertExitCode(0);

        $this->assertSame(0, SchoolClass::query()->count());
    }

    public function test_jalan_kedua_tidak_membuat_rombel_baru(): void
    {
        $school = $this->school();
        $this->year($school);

        $this->artisan('migrasi:siapkan-rombel', ['--school' => 'PUSAT', '--konfirmasi' => true]);
        $this->assertSame(4, SchoolClass::query()->count());

        $ids = SchoolClass::query()->orderBy('id')->pluck('id')->all();

        $this->artisan('migrasi:siapkan-rombel', ['--school' => 'PUSAT', '--konfirmasi' => true])
            ->assertExitCode(0);

        $this->assertSame(4, SchoolClass::query()->count());
        // Baris yang sama, bukan baris baru yang kebetulan berjumlah sama.
        $this->assertSame($ids, SchoolClass::query()->orderBy('id')->pluck('id')->all());
    }

    public function test_tanpa_tahun_ajaran_berhenti_dan_tidak_menebak(): void
    {
        $this->school();

        $this->artisan('migrasi:siapkan-rombel', ['--school' => 'PUSAT', '--konfirmasi' => true])
            ->assertExitCode(2);

        $this->assertSame(0, SchoolClass::query()->count());
        $this->assertSame(0, AcademicYear::query()->count());
    }

    public function test_tahun_ajaran_yang_disebut_tetapi_tidak_ada_ditolak(): void
    {
        $school = $this->school();
        $this->year($school);

        $this->artisan('migrasi:siapkan-rombel', [
            '--school' => 'PUSAT',
            '--tahun-ajaran' => '2030/2031 Ganjil',
            '--konfirmasi' => true,
        ])->assertExitCode(2);

        $this->assertSame(0, SchoolClass::query()->count());
    }

    public function test_cabang_yang_tidak_ada_ditolak(): void
    {
        $school = $this->school();
        $this->year($school);

        $this->artisan('migrasi:siapkan-rombel', ['--school' => 'TIDAK-ADA', '--konfirmasi' => true])
            ->assertExitCode(2);

        $this->assertSame(0, SchoolClass::query()->count());
    }

    public function test_rombel_dibuat_hanya_untuk_cabang_yang_diminta(): void
    {
        $pusat = $this->school();
        $this->year($pusat);

        $lain = $this->school('LAIN');
        $this->year($lain);

        $this->artisan('migrasi:siapkan-rombel', ['--school' => 'PUSAT', '--konfirmasi' => true]);

        $this->assertSame(4, SchoolClass::query()->where('school_id', $pusat->id)->count());
        $this->assertSame(0, SchoolClass::query()->where('school_id', $lain->id)->count());
    }

    public function test_tingkat_yang_tidak_cocok_dilaporkan_bukan_diubah(): void
    {
        $school = $this->school();
        $year = $this->year($school);

        SchoolClass::factory()->create([
            'school_id' => $school->id,
            'academic_year_id' => $year->id,
            'name' => 'X Terbuka - 2',
            'grade_level' => 12,
        ]);

        $this->artisan('migrasi:siapkan-rombel', ['--school' => 'PUSAT', '--konfirmasi' => true])
            ->assertExitCode(1);

        // Dilaporkan, tidak diperbaiki diam-diam.
        $this->assertSame(12, SchoolClass::query()->where('name', 'X Terbuka - 2')->value('grade_level'));
        $this->assertSame(4, SchoolClass::query()->count());
    }

    public function test_penyiapan_rombel_tidak_menyentuh_siswa_atau_akun(): void
    {
        $school = $this->school();
        $this->year($school);

        Student::factory()->count(3)->create(['school_id' => $school->id]);

        $before = [User::query()->count(), Student::query()->count(), StudentClass::query()->count()];

        $this->artisan('migrasi:siapkan-rombel', ['--school' => 'PUSAT', '--konfirmasi' => true]);

        $this->assertSame($before, [
            User::query()->count(),
            Student::query()->count(),
            StudentClass::query()->count(),
        ]);
    }

    public function test_tidak_didaftarkan_otomatis_di_databaseseeder(): void
    {
        $contents = File::get(database_path('seeders/DatabaseSeeder.php'));

        $this->assertStringNotContainsString('siapkan-rombel', $contents);
        $this->assertStringNotContainsString('CanonicalRombel', $contents);
    }

    // ================================================== integrasi M3

    public function test_seluruh_label_kanonis_cocok_sesudah_penyiapan(): void
    {
        $school = $this->school();
        $year = $this->year($school);

        $this->artisan('migrasi:siapkan-rombel', ['--school' => 'PUSAT', '--konfirmasi' => true]);

        $rows = [
            ['1', 'Z0001', '0088888881', 'Siswa Karangan Satu', 'L', 'SMART', 'X Terbuka - 2'],
            ['2', 'Z0002', '0088888882', 'Siswa Karangan Dua', 'P', 'SMART', 'XI Terbuka - 1'],
            // Label salah ketik dari sumber.
            ['3', 'Z0003', '0088888883', 'Siswa Karangan Tiga', 'L', 'SMART', 'XII Terbuka - I'],
            ['4', 'Z0004', '0088888884', 'Siswa Karangan Empat', 'P', 'SMART', 'XII Terbuka - 2'],
        ];

        $plan = $this->planFor($this->workbook($rows), $school, $year);

        $this->assertSame(4, $plan['placements'][StudentImportPlan::PLACEMENT_READY]);
        $this->assertArrayNotHasKey(StudentImportPlan::CLASS_NOT_FOUND, $plan['placements']);
    }

    public function test_label_di_luar_daftar_tetap_gagal_bukan_dibuat_otomatis(): void
    {
        $school = $this->school();
        $year = $this->year($school);

        $this->artisan('migrasi:siapkan-rombel', ['--school' => 'PUSAT', '--konfirmasi' => true]);

        $plan = $this->planFor($this->workbook([
            ['1', 'Z0001', '0088888881', 'Siswa Karangan Satu', 'L', 'SMART', 'X Terbuka - 9'],
        ]), $school, $year);

        $this->assertSame(1, $plan['placements'][StudentImportPlan::CLASS_NOT_FOUND]);

        (new StudentImportApply($school, $year))->run($plan);

        // Induknya tetap masuk; rombelnya tidak dikarang.
        $this->assertDatabaseHas('students', ['nis' => 'Z0001']);
        $this->assertSame(4, SchoolClass::query()->count());
        $this->assertSame(0, StudentClass::query()->count());
    }

    /**
     * Pola 39 siap + 1 tertunda: baris yang siap seluruhnya mendapat
     * penempatan, dan baris tertunda tidak mendapat apa pun.
     */
    public function test_pola_siap_dan_tertunda_menghasilkan_penempatan_penuh(): void
    {
        $school = $this->school();
        $year = $this->year($school);

        $this->artisan('migrasi:siapkan-rombel', ['--school' => 'PUSAT', '--konfirmasi' => true]);

        $rows = [];

        for ($i = 1; $i <= 39; $i++) {
            $rows[] = [
                (string) $i,
                'Z'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                '00'.str_pad((string) $i, 8, '8', STR_PAD_LEFT),
                "Siswa Karangan {$i}",
                $i % 2 === 0 ? 'P' : 'L',
                'SMART',
                'X Terbuka - 2',
            ];
        }

        $rows[] = ['40', '-', '-', 'Siswa Karangan Tanpa NIS', 'P', 'SMART', '-'];

        $plan = $this->planFor($this->workbook($rows), $school, $year);

        $this->assertSame(40, $plan['reconciliation']['source']);
        $this->assertSame(39, $plan['reconciliation']['ready']);
        $this->assertSame(1, $plan['reconciliation']['pending']);

        $result = (new StudentImportApply($school, $year))->run($plan);

        $this->assertSame(39, $result['created']);
        $this->assertSame(39, $result['placed']);
        $this->assertSame(1, $result['skipped']);

        $this->assertSame(39, Student::query()->count());
        $this->assertSame(39, StudentClass::query()->count());

        // Baris tertunda tidak menghasilkan siswa, penempatan, maupun akun.
        $this->assertDatabaseMissing('students', ['full_name' => 'Siswa Karangan Tanpa NIS']);
        $this->assertSame(0, Student::query()->whereNotNull('user_id')->count());
    }

    public function test_terap_kedua_tidak_menghasilkan_penempatan_ganda(): void
    {
        $school = $this->school();
        $year = $this->year($school);

        $this->artisan('migrasi:siapkan-rombel', ['--school' => 'PUSAT', '--konfirmasi' => true]);

        $path = $this->workbook([
            ['1', 'Z0001', '0088888881', 'Siswa Karangan Satu', 'L', 'SMART', 'X Terbuka - 2'],
            ['2', 'Z0002', '0088888882', 'Siswa Karangan Dua', 'P', 'SMART', 'XII Terbuka - I'],
        ]);

        $first = (new StudentImportApply($school, $year))->run($this->planFor($path, $school, $year));
        $this->assertSame(2, $first['placed']);

        $second = (new StudentImportApply($school, $year))->run($this->planFor($path, $school, $year));

        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['placed']);

        $this->assertSame(2, Student::query()->count());
        $this->assertSame(2, StudentClass::query()->count());
    }

    public function test_satu_siswa_tidak_pernah_punya_dua_penempatan_setahun(): void
    {
        $school = $this->school();
        $year = $this->year($school);

        $this->artisan('migrasi:siapkan-rombel', ['--school' => 'PUSAT', '--konfirmasi' => true]);

        $path = $this->workbook([
            ['1', 'Z0001', '0088888881', 'Siswa Karangan Satu', 'L', 'SMART', 'X Terbuka - 2'],
        ]);

        (new StudentImportApply($school, $year))->run($this->planFor($path, $school, $year));
        (new StudentImportApply($school, $year))->run($this->planFor($path, $school, $year));

        $duplicates = StudentClass::query()
            ->selectRaw('student_id, academic_year_id, COUNT(*) as total')
            ->groupBy('student_id', 'academic_year_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $this->assertCount(0, $duplicates);
    }

    public function test_impor_tidak_pernah_mengarang_akun_atau_surel(): void
    {
        $school = $this->school();
        $year = $this->year($school);

        $this->artisan('migrasi:siapkan-rombel', ['--school' => 'PUSAT', '--konfirmasi' => true]);

        $before = User::query()->count();

        $plan = $this->planFor($this->workbook([
            ['1', 'Z0001', '0088888881', 'Siswa Karangan Satu', 'L', 'SMART', 'X Terbuka - 2'],
        ]), $school, $year);

        (new StudentImportApply($school, $year))->run($plan);

        $this->assertSame($before, User::query()->count());
        $this->assertNull(Student::query()->where('nis', 'Z0001')->value('user_id'));
    }

    // ================================================== rombel ganda

    /**
     * Celah integritas yang nyata: `classes` tidak punya indeks unik pada
     * (school_id, academic_year_id, name), sehingga dua rombel bernama sama
     * dapat lolos lewat jalur selain form — data lama, atau dua permintaan yang
     * bersamaan.
     *
     * Pencocokan lama memakai `->value('id')`, yang memilih salah satu tanpa
     * memberi tahu siapa pun. Sekarang keadaan itu punya namanya sendiri
     * (butir 517).
     */
    public function test_dua_rombel_bernama_sama_menjadi_ambigu(): void
    {
        $school = $this->school();
        $year = $this->year($school);

        foreach ([1, 2] as $ignored) {
            SchoolClass::factory()->create([
                'school_id' => $school->id,
                'academic_year_id' => $year->id,
                'name' => 'X Terbuka - 2',
                'grade_level' => 10,
            ]);
        }

        $plan = $this->planFor($this->workbook([
            ['1', 'Z0001', '0088888881', 'Siswa Karangan Satu', 'L', 'SMART', 'X Terbuka - 2'],
        ]), $school, $year);

        $this->assertSame(1, $plan['placements'][StudentImportPlan::CLASS_AMBIGUOUS]);
        $this->assertArrayNotHasKey(StudentImportPlan::PLACEMENT_READY, $plan['placements']);
        $this->assertSame(2, $plan['classes'][0]['matches']);
        $this->assertNull($plan['classes'][0]['class_id']);
    }

    public function test_kelas_ambigu_tidak_menghasilkan_penempatan(): void
    {
        $school = $this->school();
        $year = $this->year($school);

        foreach ([1, 2] as $ignored) {
            SchoolClass::factory()->create([
                'school_id' => $school->id,
                'academic_year_id' => $year->id,
                'name' => 'X Terbuka - 2',
                'grade_level' => 10,
            ]);
        }

        $plan = $this->planFor($this->workbook([
            ['1', 'Z0001', '0088888881', 'Siswa Karangan Satu', 'L', 'SMART', 'X Terbuka - 2'],
        ]), $school, $year);

        (new StudentImportApply($school, $year))->run($plan);

        // Induk siswa tetap masuk — ambiguitas kelas bukan alasan menahan data
        // induk yang sudah benar. Yang tidak terjadi adalah penempatannya.
        $this->assertDatabaseHas('students', ['nis' => 'Z0001']);
        $this->assertSame(0, StudentClass::query()->count());
    }

    /**
     * Satu baris yang ambigu tidak boleh menulari baris lain, dan tidak boleh
     * diam-diam ikut tertulis.
     */
    public function test_satu_baris_ambigu_tidak_menahan_baris_yang_sehat(): void
    {
        $school = $this->school();
        $year = $this->year($school);

        $this->artisan('migrasi:siapkan-rombel', ['--school' => 'PUSAT', '--konfirmasi' => true]);

        // Duplikat ditambahkan sesudah penyiapan sehat.
        SchoolClass::factory()->create([
            'school_id' => $school->id,
            'academic_year_id' => $year->id,
            'name' => 'X Terbuka - 2',
            'grade_level' => 10,
        ]);

        $plan = $this->planFor($this->workbook([
            ['1', 'Z0001', '0088888881', 'Siswa Karangan Satu', 'L', 'SMART', 'X Terbuka - 2'],
            ['2', 'Z0002', '0088888882', 'Siswa Karangan Dua', 'P', 'SMART', 'XI Terbuka - 1'],
        ]), $school, $year);

        $this->assertSame(1, $plan['placements'][StudentImportPlan::CLASS_AMBIGUOUS]);
        $this->assertSame(1, $plan['placements'][StudentImportPlan::PLACEMENT_READY]);

        $result = (new StudentImportApply($school, $year))->run($plan);

        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['placed']);

        // Hanya siswa dengan rombel yang tidak ambigu yang ditempatkan.
        $healthy = SchoolClass::query()->where('name', 'XI Terbuka - 1')->value('id');
        $this->assertSame(1, StudentClass::query()->count());
        $this->assertSame($healthy, StudentClass::query()->value('class_id'));
    }

    public function test_nama_sama_di_cabang_lain_tidak_ambigu(): void
    {
        $pusat = $this->school();
        $year = $this->year($pusat);

        $lain = $this->school('LAIN');
        $yearLain = $this->year($lain);

        SchoolClass::factory()->create([
            'school_id' => $pusat->id,
            'academic_year_id' => $year->id,
            'name' => 'X Terbuka - 2',
            'grade_level' => 10,
        ]);

        SchoolClass::factory()->create([
            'school_id' => $lain->id,
            'academic_year_id' => $yearLain->id,
            'name' => 'X Terbuka - 2',
            'grade_level' => 10,
        ]);

        $plan = $this->planFor($this->workbook([
            ['1', 'Z0001', '0088888881', 'Siswa Karangan Satu', 'L', 'SMART', 'X Terbuka - 2'],
        ]), $pusat, $year);

        $this->assertSame(1, $plan['placements'][StudentImportPlan::PLACEMENT_READY]);
    }

    public function test_nama_sama_di_tahun_ajaran_lain_tidak_ambigu(): void
    {
        $school = $this->school();
        $year = $this->year($school);
        $next = AcademicYear::factory()->for($school)->create([
            'name' => '2027/2028 Ganjil',
            'semester' => 1,
            'is_active' => false,
        ]);

        foreach ([$year, $next] as $target) {
            SchoolClass::factory()->create([
                'school_id' => $school->id,
                'academic_year_id' => $target->id,
                'name' => 'X Terbuka - 2',
                'grade_level' => 10,
            ]);
        }

        $plan = $this->planFor($this->workbook([
            ['1', 'Z0001', '0088888881', 'Siswa Karangan Satu', 'L', 'SMART', 'X Terbuka - 2'],
        ]), $school, $year);

        $this->assertSame(1, $plan['placements'][StudentImportPlan::PLACEMENT_READY]);
    }

    // ================================================== perintah vs duplikat

    public function test_perintah_penyiapan_melaporkan_rombel_ganda(): void
    {
        $school = $this->school();
        $year = $this->year($school);

        foreach ([1, 2] as $ignored) {
            SchoolClass::factory()->create([
                'school_id' => $school->id,
                'academic_year_id' => $year->id,
                'name' => 'XII Terbuka - 1',
                'grade_level' => 12,
            ]);
        }

        // Kode keluar 1: ada yang perlu diputuskan manusia, bukan keadaan sehat.
        $this->artisan('migrasi:siapkan-rombel', ['--school' => 'PUSAT', '--konfirmasi' => true])
            ->assertExitCode(1);
    }

    public function test_perintah_penyiapan_tidak_membereskan_duplikat_sendiri(): void
    {
        $school = $this->school();
        $year = $this->year($school);

        foreach ([1, 2] as $ignored) {
            SchoolClass::factory()->create([
                'school_id' => $school->id,
                'academic_year_id' => $year->id,
                'name' => 'XII Terbuka - 1',
                'grade_level' => 12,
            ]);
        }

        $before = SchoolClass::query()->orderBy('id')->pluck('id')->all();

        $this->artisan('migrasi:siapkan-rombel', ['--school' => 'PUSAT', '--konfirmasi' => true]);

        // Duplikatnya masih dua: tidak digabung, tidak dihapus, tidak diganti
        // nama. Tiga rombel lain tetap dibuat.
        $this->assertSame(2, SchoolClass::query()->where('name', 'XII Terbuka - 1')->count());

        foreach ($before as $id) {
            $this->assertNotNull(SchoolClass::query()->find($id), 'baris duplikat dihapus');
        }

        $this->assertSame(5, SchoolClass::query()->count());
    }

    public function test_penyiapan_sehat_tetap_idempoten_sesudah_pengerasan(): void
    {
        $school = $this->school();
        $this->year($school);

        $this->artisan('migrasi:siapkan-rombel', ['--school' => 'PUSAT', '--konfirmasi' => true])
            ->assertExitCode(0);

        $this->artisan('migrasi:siapkan-rombel', ['--school' => 'PUSAT', '--konfirmasi' => true])
            ->assertExitCode(0);

        $this->assertSame(4, SchoolClass::query()->count());
    }

    public function test_perintah_menampilkan_sasaran_sebelum_menulis(): void
    {
        $school = $this->school();
        $this->year($school);

        $this->artisan('migrasi:siapkan-rombel', ['--school' => 'PUSAT'])
            ->expectsOutputToContain('PUSAT')
            ->expectsOutputToContain('2026/2027 Ganjil')
            ->assertExitCode(0);
    }

    /**
     * @return array<string, mixed>
     */
    protected function planFor(string $path, School $school, AcademicYear $year): array
    {
        $workbook = new LegacyWorkbook($path);

        return (new StudentImportPlan($school, $year))
            ->build($workbook->students($workbook->detectStudentSheets())['rows']);
    }
}
