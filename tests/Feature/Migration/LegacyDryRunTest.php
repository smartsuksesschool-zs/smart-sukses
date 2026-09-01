<?php

namespace Tests\Feature\Migration;

use App\Enums\RoleName;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Support\Migration\AssignmentClassifier;
use App\Support\Migration\LegacyDryRun;
use App\Support\Migration\LegacyWorkbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * M1/M2 — analisis kering berkas sekolah.
 *
 * Seluruh berkas uji **dibangun saat tes berjalan** dari data karangan, lalu
 * dihapus. Tidak ada satu pun berkas sekolah sungguhan di repositori, dan tidak
 * ada NIS, NISN, surel, atau nomor telepon nyata di berkas ini (butir 458).
 */
class LegacyDryRunTest extends TestCase
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

    /**
     * Meniru bentuk berkas sekolah: baris judul, heading yang berulang di setiap
     * seksi, dan baris rekap "Jumlah Siswa".
     *
     * @param  array<int, array<int, string>>  $studentSections
     * @param  array<int, array<int, string>>  $teacherRows
     * @param  array<int, string>  $studentHeadings
     */
    protected function workbook(
        array $studentSections,
        array $teacherRows = [],
        array $studentHeadings = ['No.', 'Nama', 'Kelas', 'Alamat'],
    ): LegacyWorkbook {
        $book = new Spreadsheet;

        $siswa = $book->getActiveSheet();
        $siswa->setTitle('Data Siswa');
        $siswa->fromArray(['Data Siswa Sekolah Contoh'], null, 'A1');
        $siswa->fromArray(['Kota Contoh'], null, 'A2');

        $line = 4;

        foreach ($studentSections as $section) {
            $siswa->fromArray($studentHeadings, null, 'A'.$line);
            $line++;

            foreach ($section as $row) {
                $siswa->fromArray($row, null, 'A'.$line);
                $line++;
            }

            $siswa->fromArray(['Jumlah Siswa: '.count($section).' siswa'], null, 'A'.$line);
            $line += 2;
        }

        $guru = $book->createSheet();
        $guru->setTitle('Data Guru');
        $guru->fromArray(['No.', 'Nama Guru', 'Pelajaran'], null, 'A1');

        foreach ($teacherRows as $i => $row) {
            $guru->fromArray($row, null, 'A'.($i + 2));
        }

        $this->path = tempnam(sys_get_temp_dir(), 'm1').'.xlsx';
        (new Xlsx($book))->save($this->path);

        return new LegacyWorkbook($this->path);
    }

    protected function school(): School
    {
        return School::factory()->create(['code' => 'UJI']);
    }

    public function test_membaca_seksi_berulang_dan_melewati_judul_serta_rekap(): void
    {
        $workbook = $this->workbook([
            [['1', 'Siswa Satu', '10', 'Jl. Contoh 1'], ['2', 'Siswa Dua', '10', 'Jl. Contoh 2']],
            [['1', 'Siswa Tiga', '11', 'Jl. Contoh 3']],
        ]);

        $read = $workbook->students();

        $this->assertCount(3, $read['rows']);
        // Dua baris judul dokumen, bukan data.
        $this->assertSame(2, $read['ignored_rows']);
        // Heading dibaca ulang di setiap seksi, bukan sekali di awal.
        $this->assertSame(2, $read['heading_rows']);
        $this->assertSame([2, 1], array_column($read['declared_totals'], 'count'));
    }

    public function test_baris_tanpa_nis_dan_gender_ditolak_bukan_dikarang(): void
    {
        $run = new LegacyDryRun(
            $this->workbook([[['1', 'Siswa Satu', '10', 'Jl. Contoh 1']]]),
            $this->school(),
        );

        $report = $run->students();

        $this->assertSame(1, $report['source_rows']);
        $this->assertSame(0, $report['valid_rows']);
        $this->assertSame(1, $report['rejected_rows']);
        $this->assertSame(0, $report['create_candidates']);
        $this->assertSame(['nis', 'gender'], $report['rejected'][0]['reasons']);
        $this->assertSame(1, $report['missing_required']['nis']);
        $this->assertSame(1, $report['missing_required']['gender']);
        $this->assertSame(0, $report['readiness'][LegacyDryRun::MASTER_READY]);
    }

    public function test_kolom_tambahan_sekolah_langsung_terbaca_tanpa_ubah_kode(): void
    {
        $run = new LegacyDryRun(
            $this->workbook(
                [[['1', 'Siswa Satu', '10', 'Jl. Contoh 1', '2024001', 'P']]],
                [],
                ['No.', 'Nama', 'Kelas', 'Alamat', 'NIS', 'Jenis Kelamin'],
            ),
            $this->school(),
        );

        $report = $run->students();

        $this->assertSame(1, $report['valid_rows']);
        $this->assertSame(1, $report['create_candidates']);
        $this->assertSame([], $report['missing_required']);
        $this->assertSame(1, $report['readiness'][LegacyDryRun::MASTER_READY]);
    }

    public function test_siswa_yang_sudah_ada_menjadi_kandidat_cocok_bukan_buat_baru(): void
    {
        $school = $this->school();
        Student::factory()->for($school)->create(['nis' => '2024001']);

        $report = (new LegacyDryRun(
            $this->workbook(
                [[['1', 'Siswa Satu', '10', 'Jl. Contoh 1', '2024001', 'P']]],
                [],
                ['No.', 'Nama', 'Kelas', 'Alamat', 'NIS', 'Jenis Kelamin'],
            ),
            $school,
        ))->students();

        $this->assertSame(1, $report['match_candidates']);
        $this->assertSame(0, $report['create_candidates']);
    }

    public function test_nis_ganda_di_dalam_berkas_terdeteksi(): void
    {
        $report = (new LegacyDryRun(
            $this->workbook(
                [[
                    ['1', 'Siswa Satu', '10', 'Jl. Contoh 1', '2024001', 'P'],
                    ['2', 'Siswa Dua', '10', 'Jl. Contoh 2', '2024001', 'L'],
                ]],
                [],
                ['No.', 'Nama', 'Kelas', 'Alamat', 'NIS', 'Jenis Kelamin'],
            ),
            $this->school(),
        ))->students();

        $this->assertCount(1, $report['duplicates']);
        $this->assertSame(1, $report['valid_rows']);
    }

    public function test_nama_kembar_adalah_peringatan_bukan_identitas(): void
    {
        $report = (new LegacyDryRun(
            $this->workbook(
                [[
                    ['1', 'Siswa Kembar', '10', 'Jl. Contoh 1', '2024001', 'P'],
                    ['2', 'Siswa Kembar', '10', 'Jl. Contoh 2', '2024002', 'L'],
                ]],
                [],
                ['No.', 'Nama', 'Kelas', 'Alamat', 'NIS', 'Jenis Kelamin'],
            ),
            $this->school(),
        ))->students();

        // Dua orang berbeda yang kebetulan senama tetap dua baris valid.
        $this->assertSame(2, $report['valid_rows']);
        $this->assertSame(2, $report['create_candidates']);
        $this->assertCount(0, $report['duplicates']);
        $this->assertCount(1, $report['name_collisions']);
    }

    public function test_master_siswa_siap_meski_akun_terhambat(): void
    {
        $report = (new LegacyDryRun(
            $this->workbook(
                [[['1', 'Siswa Satu', '10', 'Jl. Contoh 1', '2024001', 'P']]],
                [],
                ['No.', 'Nama', 'Kelas', 'Alamat', 'NIS', 'Jenis Kelamin'],
            ),
            $this->school(),
        ))->students();

        // Inti aturan akun siswa: master data tidak ikut terhambat oleh surel
        // yang belum terkumpul, karena students.user_id nullable.
        $this->assertSame(1, $report['readiness'][LegacyDryRun::MASTER_READY]);
        $this->assertSame(0, $report['readiness'][LegacyDryRun::ACCOUNT_READY]);
        $this->assertSame(1, $report['readiness'][LegacyDryRun::ACCOUNT_BLOCKED]);
        $this->assertSame(1, $report['account_blockers']['surel_tidak_ada_di_sumber']);
    }

    public function test_surel_milik_cabang_lain_menghambat_akun(): void
    {
        $school = $this->school();
        User::factory()->create([
            'email' => 'sudah.dipakai@contoh.test',
            'school_id' => School::factory()->create(['code' => 'LAIN'])->id,
        ]);

        $report = (new LegacyDryRun(
            $this->workbook(
                [[['1', 'Siswa Satu', '10', 'Jl. 1', '2024001', 'P', 'sudah.dipakai@contoh.test']]],
                [],
                ['No.', 'Nama', 'Kelas', 'Alamat', 'NIS', 'Jenis Kelamin', 'Email Siswa'],
            ),
            $school,
        ))->students();

        $this->assertSame(1, $report['account_blockers']['surel_dipakai_cabang_lain']);
    }

    public function test_penempatan_kelas_menuntut_kelas_ada_di_tahun_ajaran_tujuan(): void
    {
        $school = $this->school();
        $year = AcademicYear::factory()->for($school)->create(['is_active' => true]);

        $report = (new LegacyDryRun(
            $this->workbook([[
                ['1', 'Siswa Satu', '10', 'Jl. 1'],
                ['2', 'Siswa Dua', '11', 'Jl. 2'],
            ]]),
            $school,
            $year,
        ))->students();

        // Label tetap string meski isinya angka — dibandingkan ke `classes.name`.
        $this->assertSame(['10', '11'], array_column($report['class_placement'], 'label'));
        $this->assertSame([10, 11], array_column($report['class_placement'], 'grade_level'));
        $this->assertSame(
            ['kelas_belum_ada_di_tahun_ajaran', 'kelas_belum_ada_di_tahun_ajaran'],
            array_column($report['class_placement'], 'blocker'),
        );
    }

    public function test_kelas_yang_sudah_ada_bukan_penghambat(): void
    {
        $school = $this->school();
        $year = AcademicYear::factory()->for($school)->create(['is_active' => true]);
        SchoolClass::factory()->for($school)->for($year)->create(['name' => '10', 'grade_level' => 10]);

        $report = (new LegacyDryRun(
            $this->workbook([[['1', 'Siswa Satu', '10', 'Jl. 1']]]),
            $school,
            $year,
        ))->students();

        $this->assertNull($report['class_placement'][0]['blocker']);
        $this->assertTrue($report['class_placement'][0]['exists_in_target_year']);
    }

    public function test_label_kelas_tak_dikenal_tidak_ditebak_menjadi_tingkat(): void
    {
        $school = $this->school();
        $year = AcademicYear::factory()->for($school)->create(['is_active' => true]);

        $report = (new LegacyDryRun(
            $this->workbook([[['1', 'Siswa Satu', 'Persiapan', 'Jl. 1']]]),
            $school,
            $year,
        ))->students();

        $this->assertNull($report['class_placement'][0]['grade_level']);
        $this->assertSame('tingkat_kelas_tidak_terbaca', $report['class_placement'][0]['blocker']);
    }

    public function test_jabatan_tidak_pernah_menjadi_mata_pelajaran(): void
    {
        $report = (new LegacyDryRun(
            $this->workbook(
                [[['1', 'Siswa Satu', '10', 'Jl. 1']]],
                [['1', 'Guru Satu', 'Kepala Sekolah, PAI Akhlakul Karimah']],
            ),
            $this->school(),
        ))->teachers();

        $names = array_column($report['subject_candidates'], 'name');

        $this->assertNotContains('Kepala Sekolah', $names);
        $this->assertSame(['PAI Akhlakul Karimah'], $names);
        $this->assertSame(1, $report['roles'][RoleName::KepalaSekolah->value]);
    }

    public function test_token_tak_dikenal_menjadi_ambigu_bukan_mata_pelajaran(): void
    {
        $report = (new LegacyDryRun(
            $this->workbook(
                [[['1', 'Siswa Satu', '10', 'Jl. 1']]],
                [['1', 'Guru Satu', 'Sesuatu Yang Belum Pernah Ada']],
            ),
            $this->school(),
        ))->teachers();

        $this->assertSame([], array_column($report['subject_candidates'], 'name'));
        $this->assertArrayHasKey('Sesuatu Yang Belum Pernah Ada', $report['ambiguous_tokens']);
        // Tanpa jabatan yang dikenali, perannya Guru — tidak ditebak dari mapel.
        $this->assertSame(1, $report['roles'][RoleName::Guru->value]);
    }

    public function test_kegiatan_non_akademik_dipisah_dari_mata_pelajaran(): void
    {
        $report = (new LegacyDryRun(
            $this->workbook(
                [[['1', 'Siswa Satu', '10', 'Jl. 1']]],
                [['1', 'Guru Satu', 'BK, PJOK, Mentoring, Pramuka']],
            ),
            $this->school(),
        ))->teachers();

        $this->assertSame(['PJOK'], array_column($report['subject_candidates'], 'name'));
        $this->assertSame(
            ['Bimbingan Konseling', 'Mentoring', 'Pramuka'],
            array_keys($report['non_academic_tokens']),
        );
        $this->assertSame([], $report['ambiguous_tokens']);
    }

    public function test_klasifikasi_penugasan_deterministik(): void
    {
        $classifier = new AssignmentClassifier;

        $first = $classifier->classify('Kepala Sekolah, Al-Qur\'an');
        $second = $classifier->classify('Kepala Sekolah, Al-Qur\'an');

        $this->assertSame($first, $second);
        $this->assertSame(AssignmentClassifier::ROLE, $first[0]['kind']);
        $this->assertSame(AssignmentClassifier::SUBJECT, $first[1]['kind']);
        // Ejaan berbeda untuk mapel yang sama tetap satu kanonis.
        $this->assertSame('Al-Qur\'an', $classifier->classify('Al Quran')[0]['canonical']);
        $this->assertSame('AQ', $classifier->proposeCode('Al-Qur\'an'));
    }

    public function test_perintah_tidak_menulis_apa_pun_dan_melaporkan_penghambat(): void
    {
        $school = $this->school();
        AcademicYear::factory()->for($school)->create(['is_active' => true]);

        $this->workbook(
            [[['1', 'Siswa Satu', '10', 'Jl. 1']]],
            [['1', 'Guru Satu', 'Kepala Sekolah']],
        );

        $before = [Student::count(), User::count(), SchoolClass::count()];

        $this->artisan('migrasi:dry-run', ['berkas' => $this->path, '--school' => 'UJI'])
            ->assertExitCode(1);

        $this->assertSame($before, [Student::count(), User::count(), SchoolClass::count()]);
    }

    public function test_perintah_berhenti_bila_kode_cabang_tidak_ada(): void
    {
        $this->workbook([[['1', 'Siswa Satu', '10', 'Jl. 1']]]);

        $this->artisan('migrasi:dry-run', ['berkas' => $this->path, '--school' => 'TIDAK-ADA'])
            ->assertExitCode(2);
    }

    public function test_perintah_berhenti_bila_berkas_tidak_ada(): void
    {
        $this->school();

        $this->artisan('migrasi:dry-run', [
            'berkas' => sys_get_temp_dir().'/tidak-ada-'.uniqid().'.xlsx',
            '--school' => 'UJI',
        ])->assertExitCode(2);
    }
}
