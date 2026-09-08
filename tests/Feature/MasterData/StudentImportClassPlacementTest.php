<?php

namespace Tests\Feature\MasterData;

use App\Enums\StudentClassStatus;
use App\Enums\StudentStatus;
use App\Imports\StudentsImport;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Impor siswa menempatkan rombelnya sekaligus (M9.1).
 *
 * Temuan uji coba yang memicunya: tiga belas siswa masuk lewat /admin/students
 * dan **seluruhnya** "Belum ada kelas", karena berkasnya memang tidak punya
 * kolom kelas dan importer tidak pernah menyentuh `student_classes`. Impor yang
 * menuntut penempatan manual satu per satu sesudahnya bukan impor (butir 572).
 *
 * Seluruh baris pada berkas ini karangan: NIS berpola nol, NISN bukan milik
 * siapa pun, dan namanya tidak menunjuk siapa pun.
 */
class StudentImportClassPlacementTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected AcademicYear $year;

    protected SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create(['code' => 'PUSAT', 'is_active' => true]);

        $this->year = AcademicYear::factory()->create([
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);

        $this->class = SchoolClass::factory()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->year->id,
            'name' => 'X Terbuka - 2',
            'capacity' => 35,
        ]);
    }

    /**
     * Menjalankan importer atas baris-baris yang sudah berbentuk seperti hasil
     * baca Excel (judul kolom sebagai kunci).
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function import(array $rows, ?int $schoolId = null): StudentsImport
    {
        $import = new StudentsImport($schoolId ?? $this->school->id);

        $import->collection(new Collection(array_map(
            fn (array $row) => new Collection($row),
            $rows,
        )));

        return $import;
    }

    /**
     * Satu baris lengkap; kolom apa pun dapat ditimpa.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function row(array $overrides = []): array
    {
        return array_merge([
            'nis' => 'T000000001',
            'nisn' => '0012345678',
            'nama_lengkap' => 'Siswa Karangan Satu',
            'jenis_kelamin' => 'L',
            'kelas' => 'X Terbuka - 2',
            'tempat_lahir' => null,
            'tanggal_lahir' => null,
            'agama' => null,
            'alamat' => null,
            'nama_orang_tua' => null,
            'hp_orang_tua' => null,
            'email_orang_tua' => null,
            'tahun_masuk' => null,
            'status' => null,
        ], $overrides);
    }

    // ------------------------------------------------------------ jalur benar

    public function test_siswa_dan_penempatannya_dibuat_bersama(): void
    {
        $import = $this->import([$this->row()]);

        $this->assertSame(1, $import->imported);
        $this->assertSame(1, $import->placed);
        $this->assertSame([], $import->errors);

        $student = Student::query()->sole();

        $placement = StudentClass::query()->sole();

        $this->assertSame($student->id, $placement->student_id);
        $this->assertSame($this->class->id, $placement->class_id);
        $this->assertSame($this->year->id, $placement->academic_year_id);
        $this->assertSame($this->school->id, $placement->school_id);
        $this->assertSame(StudentClassStatus::Active, $placement->status);
    }

    public function test_kelas_langsung_terbaca_pada_tahun_ajaran_aktif(): void
    {
        $this->import([$this->row()]);

        $student = Student::query()->sole();

        // Relasi yang sama yang dipakai kolom "Kelas" pada daftar siswa.
        $this->assertSame(
            'X Terbuka - 2',
            $student->activeStudentClass?->schoolClass?->name,
        );
    }

    public function test_nama_rombel_tidak_peka_huruf_besar_kecil_dan_spasi_ganda(): void
    {
        // Normalisasi yang sengaja sempit: spasi dirapikan, huruf disamakan.
        $import = $this->import([$this->row(['kelas' => '  x   terbuka - 2 '])]);

        $this->assertSame(1, $import->placed);
        $this->assertSame([], $import->errors);
    }

    public function test_kolom_kelas_kosong_tetap_membuat_siswa_tanpa_penempatan(): void
    {
        // Berkas lama tanpa kolom kelas harus tetap terbaca, dan siswa yang
        // rombelnya belum ditentukan harus tetap dapat dimasukkan.
        $import = $this->import([$this->row(['kelas' => null])]);

        $this->assertSame(1, $import->imported);
        $this->assertSame(0, $import->placed);
        $this->assertSame(1, Student::query()->count());
        $this->assertSame(0, StudentClass::query()->count());
    }

    // ------------------------------------------------------------- penolakan

    public function test_kelas_tidak_dikenal_menolak_baris_tanpa_menyisakan_siswa(): void
    {
        $import = $this->import([$this->row(['kelas' => 'X Terbuka - 3'])]);

        $this->assertSame(0, $import->imported);
        $this->assertSame(1, $import->rejected);

        // Inilah pagarnya: tidak ada siswa yatim tanpa rombel.
        $this->assertSame(0, Student::query()->count());
        $this->assertSame(0, StudentClass::query()->count());

        $this->assertStringContainsString('X Terbuka - 3', $import->errors[0]);
        // Ejaan yang benar disebutkan, sehingga tidak perlu ditebak.
        $this->assertStringContainsString('X Terbuka - 2', $import->errors[0]);
    }

    public function test_kelas_milik_cabang_lain_ditolak(): void
    {
        $lain = School::factory()->create(['code' => 'CABANG2']);
        $tahunLain = AcademicYear::factory()->create(['school_id' => $lain->id, 'is_active' => true]);

        SchoolClass::factory()->create([
            'school_id' => $lain->id,
            'academic_year_id' => $tahunLain->id,
            'name' => 'XII Terbuka - 9',
        ]);

        $import = $this->import([$this->row(['kelas' => 'XII Terbuka - 9'])]);

        $this->assertSame(0, $import->imported);
        $this->assertSame(1, $import->rejected);
        $this->assertSame(0, StudentClass::query()->count());
    }

    public function test_kelas_tahun_ajaran_tidak_aktif_ditolak(): void
    {
        $lama = AcademicYear::factory()->create([
            'school_id' => $this->school->id,
            'is_active' => false,
        ]);

        SchoolClass::factory()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $lama->id,
            'name' => 'IX Terbuka - 1',
        ]);

        $import = $this->import([$this->row(['kelas' => 'IX Terbuka - 1'])]);

        $this->assertSame(0, $import->imported);
        $this->assertSame(1, $import->rejected);
    }

    public function test_tanpa_tahun_ajaran_aktif_kolom_kelas_ditolak_dengan_sebabnya(): void
    {
        $this->year->forceFill(['is_active' => false])->save();

        $import = $this->import([$this->row()]);

        $this->assertSame(0, $import->imported);
        $this->assertStringContainsString('tahun ajaran aktif', $import->errors[0]);
    }

    public function test_kelas_yang_sudah_penuh_ditolak(): void
    {
        // Kapasitas ditegakkan layar Kelas; impor tidak boleh menjadi pintu
        // belakang yang melewatinya (butir 575).
        $this->class->forceFill(['capacity' => 1])->save();

        $import = $this->import([
            $this->row(['nis' => 'T000000001']),
            $this->row(['nis' => 'T000000002', 'nisn' => '0012345679']),
        ]);

        $this->assertSame(1, $import->imported);
        $this->assertSame(1, $import->placed);
        $this->assertSame(1, $import->rejected);
        $this->assertStringContainsString('penuh', $import->errors[0]);
    }

    public function test_rombel_bernama_ganda_ditolak_bukan_ditebak(): void
    {
        SchoolClass::factory()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->year->id,
            'name' => 'X TERBUKA - 2',
        ]);

        $import = $this->import([$this->row()]);

        $this->assertSame(0, $import->imported);
        $this->assertStringContainsString('lebih dari satu', $import->errors[0]);
    }

    // -------------------------------------------------------------- duplikat

    public function test_nis_yang_sudah_ada_di_basis_data_ditolak(): void
    {
        Student::factory()->create([
            'school_id' => $this->school->id,
            'nis' => 'T000000001',
        ]);

        $import = $this->import([$this->row()]);

        $this->assertSame(0, $import->imported);
        $this->assertSame(1, $import->rejected);
    }

    public function test_nis_kembar_di_dalam_berkas_dilaporkan_berbeda(): void
    {
        /*
         * Dua kegagalan yang pesannya dulu sama persis. Yang satu menuntut tata
         * usaha memeriksa data siswa, yang lain memeriksa berkasnya sendiri
         * (butir 574).
         */
        $import = $this->import([
            $this->row(['nis' => 'T000000001']),
            $this->row(['nis' => 'T000000001', 'nisn' => '0012345679']),
        ]);

        $this->assertSame(1, $import->imported);
        $this->assertSame(1, $import->rejected);
        $this->assertStringContainsString('berkas yang sama', $import->errors[0]);
        $this->assertStringContainsString('baris 2', $import->errors[0]);
    }

    // ---------------------------------------------------------------- status

    public function test_status_kosong_menjadi_active(): void
    {
        $this->import([$this->row(['status' => null])]);

        $this->assertSame(StudentStatus::Active, Student::query()->sole()->status);
    }

    public function test_status_tidak_sah_ditolak_dan_menyebut_nilai_yang_diterima(): void
    {
        // Nilai keliru yang paling sering: angka tingkat kelas.
        $import = $this->import([$this->row(['status' => '10'])]);

        $this->assertSame(0, $import->imported);
        $this->assertStringContainsString('ACTIVE', $import->errors[0]);
        $this->assertStringContainsString('kosongkan', $import->errors[0]);

        // Angka itu tidak pernah ditafsirkan ulang sebagai penempatan kelas.
        $this->assertSame(0, StudentClass::query()->count());
    }

    // ------------------------------------------------------------- arsitektur

    public function test_kolom_kelas_terdaftar_dan_tetap_opsional(): void
    {
        // Kontrak kolom hidup di satu tempat; template dan modal membacanya
        // dari sini (butir 497).
        $this->assertArrayHasKey('kelas', StudentsImport::COLUMNS);
        $this->assertNotContains('kelas', StudentsImport::REQUIRED_COLUMNS);
    }

    public function test_importer_tidak_pernah_membuat_rombel_baru(): void
    {
        $sebelum = SchoolClass::query()->count();

        $this->import([$this->row(['kelas' => 'Kelas Karangan Yang Tidak Ada'])]);

        $this->assertSame($sebelum, SchoolClass::query()->count());
    }

    public function test_alias_legacy_tidak_diwarisi_importer_normal(): void
    {
        /*
         * `CanonicalRombel::ALIASES` mengoreksi salah ketik pada satu berkas
         * sumber tertentu, bukan aturan umum tentang label rombel. Importer
         * normal karena itu tidak mewarisinya: yang mengetik di sini tata usaha,
         * dan pesan penolakan menyebutkan ejaan yang benar (butir 576).
         */
        SchoolClass::factory()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->year->id,
            'name' => 'XII Terbuka - 1',
        ]);

        $import = $this->import([$this->row(['kelas' => 'XII Terbuka - I'])]);

        $this->assertSame(0, $import->imported);
        $this->assertStringContainsString('tidak ditemukan', $import->errors[0]);
    }
}
