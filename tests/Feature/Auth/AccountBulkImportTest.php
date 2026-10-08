<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Exports\AccountTemplateExport;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Imports\UserAccountsImport;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * PORTAL-04 AC-1 / AC-M0-12 — pembuatan akun guru dan siswa massal via Excel.
 *
 * Seluruh berkas uji **dibangun saat tes berjalan** dari data karangan, lalu
 * dihapus. Surelnya memakai domain `.test` yang dicadangkan untuk contoh, dan NIS
 * karangan memakai awalan `T` yang tidak dipakai sekolah — tidak ada identitas
 * sungguhan di berkas ini (butir 594).
 */
class AccountBulkImportTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected School $cabangLain;

    protected User $admin;

    protected string $path = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create(['code' => 'PUSAT']);
        $this->cabangLain = School::factory()->create(['code' => 'CABANG2']);

        $this->admin = User::factory()
            ->forSchool($this->school)
            ->withRole(RoleName::SchoolAdmin)
            ->create();

        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        if ($this->path !== '' && is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    // ================================================== perkakas

    /**
     * Membangun berkas .xlsx dengan lembar dan baris yang diminta.
     *
     * @param  array<string, array<int, array<int, string>>>  $sheets
     */
    protected function workbook(array $sheets): string
    {
        $book = new Spreadsheet;
        $first = true;

        foreach ($sheets as $title => $rows) {
            $sheet = $first ? $book->getActiveSheet() : $book->createSheet();
            $first = false;
            $sheet->setTitle($title);

            foreach ($rows as $i => $row) {
                $sheet->fromArray($row, null, 'A'.($i + 1));
            }
        }

        $this->path = tempnam(sys_get_temp_dir(), 'akun').'.xlsx';
        (new Xlsx($book))->save($this->path);

        $stored = 'imports/akun.xlsx';
        Storage::disk('local')->put($stored, (string) file_get_contents($this->path));

        return $stored;
    }

    /**
     * @return array<int, string>
     */
    protected function teacherHeadings(): array
    {
        return array_keys(UserAccountsImport::TEACHER_COLUMNS);
    }

    /**
     * @return array<int, string>
     */
    protected function studentHeadings(): array
    {
        return array_keys(UserAccountsImport::STUDENT_COLUMNS);
    }

    protected function student(string $nis, array $attributes = []): Student
    {
        return Student::factory()->create($attributes + [
            'school_id' => $this->school->id,
            'nis' => $nis,
            'full_name' => 'Siswa Karangan '.$nis,
        ]);
    }

    /**
     * Menjalankan aksi impor dan mengembalikan komponennya.
     */
    protected function import(string $file, ?User $actor = null, ?int $schoolId = null): Testable
    {
        $this->actingAs($actor ?? $this->admin);

        $data = ['file' => [$file]];

        if ($schoolId !== null) {
            $data['school_id'] = $schoolId;
        }

        return Livewire::test(ListUsers::class)->callAction('importAccounts', $data);
    }

    // ================================================== A. guru sah

    public function test_impor_guru_yang_sah_membuat_akun(): void
    {
        $file = $this->workbook([
            UserAccountsImport::TEACHER_SHEET => [
                $this->teacherHeadings(),
                ['Guru Karangan Satu', 'guru.satu@example.test', 'GURU', '081200000001', 'id'],
                ['Wali Karangan Dua', 'wali.dua@example.test', 'WALI_KELAS', '', ''],
            ],
        ]);

        $this->import($file);

        $guru = User::query()->where('email', 'guru.satu@example.test')->first();
        $wali = User::query()->where('email', 'wali.dua@example.test')->first();

        $this->assertNotNull($guru);
        $this->assertNotNull($wali);
        $this->assertSame($this->school->id, $guru->school_id);
        $this->assertSame(RoleName::Guru->value, $guru->primaryRole()?->value);
        $this->assertSame(RoleName::WaliKelas->value, $wali->primaryRole()?->value);
        $this->assertTrue($guru->is_active);
        $this->assertSame('id', $wali->locale);
    }

    // ================================================== B. siswa sah

    public function test_impor_siswa_yang_sah_membuat_akun_dan_menautkannya(): void
    {
        $siswa = $this->student('T000000001');

        $file = $this->workbook([
            UserAccountsImport::STUDENT_SHEET => [
                $this->studentHeadings(),
                ['T000000001', 'siswa.satu@example.test', '', ''],
            ],
        ]);

        $this->import($file);

        $akun = User::query()->where('email', 'siswa.satu@example.test')->first();

        $this->assertNotNull($akun);
        $this->assertSame(RoleName::Siswa->value, $akun->primaryRole()?->value);
        // Nama akun mengikuti data induk, bukan kolom berkas.
        $this->assertSame($siswa->full_name, $akun->name);
        $this->assertSame($akun->id, $siswa->fresh()->user_id);
    }

    // ================================================== C. surel ganda

    public function test_surel_yang_sudah_dipakai_menolak_seluruh_impor(): void
    {
        User::factory()->forSchool($this->school)->withRole(RoleName::Guru)->create([
            'email' => 'sudah.ada@example.test',
        ]);

        $file = $this->workbook([
            UserAccountsImport::TEACHER_SHEET => [
                $this->teacherHeadings(),
                ['Guru Baru', 'baru@example.test', 'GURU', '', ''],
                ['Guru Bentrok', 'sudah.ada@example.test', 'GURU', '', ''],
            ],
        ]);

        $this->import($file);

        $this->assertFalse(User::query()->where('email', 'baru@example.test')->exists());
        $this->assertSame(1, User::query()->where('email', 'sudah.ada@example.test')->count());
    }

    public function test_surel_ganda_di_dalam_berkas_menolak_impor(): void
    {
        $file = $this->workbook([
            UserAccountsImport::TEACHER_SHEET => [
                $this->teacherHeadings(),
                ['Guru A', 'kembar@example.test', 'GURU', '', ''],
                ['Guru B', 'kembar@example.test', 'WALI_KELAS', '', ''],
            ],
        ]);

        $this->import($file);

        $this->assertFalse(User::query()->where('email', 'kembar@example.test')->exists());
    }

    // ================================================== D. NIS

    public function test_nis_yang_tidak_ada_menolak_impor(): void
    {
        $file = $this->workbook([
            UserAccountsImport::STUDENT_SHEET => [
                $this->studentHeadings(),
                ['T999999999', 'hantu@example.test', '', ''],
            ],
        ]);

        $this->import($file);

        $this->assertFalse(User::query()->where('email', 'hantu@example.test')->exists());
    }

    public function test_nis_ganda_di_dalam_berkas_menolak_impor(): void
    {
        $this->student('T000000002');

        $file = $this->workbook([
            UserAccountsImport::STUDENT_SHEET => [
                $this->studentHeadings(),
                ['T000000002', 'satu@example.test', '', ''],
                ['T000000002', 'dua@example.test', '', ''],
            ],
        ]);

        $this->import($file);

        $this->assertSame(0, User::query()->whereIn('email', ['satu@example.test', 'dua@example.test'])->count());
    }

    public function test_siswa_terarsip_menolak_impor(): void
    {
        $siswa = $this->student('T000000003');
        $siswa->delete();

        $file = $this->workbook([
            UserAccountsImport::STUDENT_SHEET => [
                $this->studentHeadings(),
                ['T000000003', 'terarsip@example.test', '', ''],
            ],
        ]);

        $this->import($file);

        $this->assertFalse(User::query()->where('email', 'terarsip@example.test')->exists());
    }

    // ================================================== E. lintas cabang

    public function test_nis_cabang_lain_tidak_dapat_ditautkan(): void
    {
        $asing = Student::factory()->create([
            'school_id' => $this->cabangLain->id,
            'nis' => 'T000000004',
        ]);

        $file = $this->workbook([
            UserAccountsImport::STUDENT_SHEET => [
                $this->studentHeadings(),
                ['T000000004', 'seberang@example.test', '', ''],
            ],
        ]);

        $this->import($file);

        $this->assertFalse(User::query()->where('email', 'seberang@example.test')->exists());
        $this->assertNull($asing->fresh()->user_id);
    }

    /**
     * Pagar cabang pada pencarian NIS, diuji di lapisnya sendiri.
     *
     * Lewat Admin Sekolah, `SchoolScope` sudah menyaring cabangnya — sehingga
     * `->where('school_id', …)` tidak terbukti apa pun. Yang membuktikannya
     * adalah Super Admin, yang tidak dibatasi scope: tanpa filter eksplisit itu,
     * NIS cabang lain akan tertaut (butir 594).
     */
    public function test_super_admin_tidak_dapat_menautkan_nis_cabang_lain(): void
    {
        Student::factory()->create([
            'school_id' => $this->cabangLain->id,
            'nis' => 'T000000011',
        ]);

        $super = User::factory()->withRole(RoleName::SuperAdmin)->create(['school_id' => null]);
        $this->actingAs($super);

        // Cabang tujuan PUSAT, tetapi NIS-nya milik CABANG2.
        $import = new UserAccountsImport($this->school->id);
        $file = $this->workbook([
            UserAccountsImport::STUDENT_SHEET => [
                $this->studentHeadings(),
                ['T000000011', 'silang@example.test', '', ''],
            ],
        ]);

        Excel::import($import, Storage::disk('local')->path($file));

        $this->assertTrue($import->hasErrors());
        $this->assertSame([], $import->planned);
        $this->assertStringContainsString('tidak ditemukan di cabang ini', implode(' ', $import->errors));
    }

    /**
     * Validator — bukan penulis — yang menolak siswa yang sudah berakun.
     *
     * `AccountProvisioner` memang membatalkan seluruh transaksi bila tautannya
     * sudah terisi, sehingga hasil akhirnya benar bagaimanapun. Tetapi penolakan
     * yang tiba pada fase tulis muncul sebagai "impor dibatalkan" tanpa menyebut
     * baris mana — dan admin dengan 200 baris tidak dapat mencarinya.
     */
    public function test_validator_menolak_siswa_yang_sudah_berakun_sebelum_menulis(): void
    {
        $akunLama = User::factory()->forSchool($this->school)->withRole(RoleName::Siswa)->create();
        $this->student('T000000012', ['user_id' => $akunLama->id]);

        $this->actingAs($this->admin);

        $import = new UserAccountsImport($this->school->id);
        $file = $this->workbook([
            UserAccountsImport::STUDENT_SHEET => [
                $this->studentHeadings(),
                ['T000000012', 'kedua@example.test', '', ''],
            ],
        ]);

        Excel::import($import, Storage::disk('local')->path($file));

        $this->assertTrue($import->hasErrors());
        $this->assertSame([], $import->planned);
        $this->assertStringContainsString('sudah memiliki akun portal', implode(' ', $import->errors));
    }

    /**
     * Super Admin wajib memilih cabang, dan form yang menolaknya.
     *
     * Yang menjaga di sini `->required()` pada Select-nya — bukan pemeriksaan
     * null di dalam `runImport()`, yang tidak akan pernah tercapai karena validasi
     * form berjalan lebih dulu. Testnya menyasar mekanisme yang sesungguhnya
     * berlaku; pemeriksaan di dalam `runImport()` tetap ada sebagai lapis kedua
     * bila penyetelan field berubah (butir 594).
     */
    public function test_super_admin_wajib_memilih_cabang(): void
    {
        $super = User::factory()->withRole(RoleName::SuperAdmin)->create(['school_id' => null]);

        $file = $this->workbook([
            UserAccountsImport::TEACHER_SHEET => [
                $this->teacherHeadings(),
                ['Guru Tanpa Cabang', 'tanpa.cabang@example.test', 'GURU', '', ''],
            ],
        ]);

        $this->actingAs($super);

        Livewire::test(ListUsers::class)
            ->callAction('importAccounts', ['file' => [$file]])
            ->assertHasActionErrors(['school_id']);

        $this->assertFalse(
            User::query()->withoutGlobalScopes()->where('email', 'tanpa.cabang@example.test')->exists()
        );
    }

    public function test_admin_sekolah_selalu_mengimpor_ke_cabangnya_sendiri(): void
    {
        $file = $this->workbook([
            UserAccountsImport::TEACHER_SHEET => [
                $this->teacherHeadings(),
                ['Guru Titipan', 'titipan@example.test', 'GURU', '', ''],
            ],
        ]);

        // Cabang lain dikirim langsung; field-nya tidak tampil bagi Admin Sekolah.
        $this->import($file, schoolId: $this->cabangLain->id);

        $this->assertSame(
            $this->school->id,
            User::query()->where('email', 'titipan@example.test')->value('school_id'),
        );
    }

    public function test_super_admin_mengimpor_ke_cabang_yang_dipilih(): void
    {
        $super = User::factory()->withRole(RoleName::SuperAdmin)->create(['school_id' => null]);

        $file = $this->workbook([
            UserAccountsImport::TEACHER_SHEET => [
                $this->teacherHeadings(),
                ['Guru Cabang2', 'cabang2@example.test', 'GURU', '', ''],
            ],
        ]);

        $this->import($file, $super, $this->cabangLain->id);

        $this->assertSame(
            $this->cabangLain->id,
            User::query()->withoutGlobalScopes()->where('email', 'cabang2@example.test')->value('school_id'),
        );
    }

    // ================================================== F. peran keliru

    public function test_peran_di_luar_guru_dan_wali_kelas_ditolak(): void
    {
        $file = $this->workbook([
            UserAccountsImport::TEACHER_SHEET => [
                $this->teacherHeadings(),
                ['Calon Admin', 'calon.admin@example.test', 'SCHOOL_ADMIN', '', ''],
            ],
        ]);

        $this->import($file);

        $this->assertFalse(User::query()->where('email', 'calon.admin@example.test')->exists());
    }

    // ================================================== G/O. akun & tautan ada

    public function test_siswa_yang_sudah_punya_akun_ditolak(): void
    {
        $akunLama = User::factory()->forSchool($this->school)->withRole(RoleName::Siswa)->create();
        $siswa = $this->student('T000000005', ['user_id' => $akunLama->id]);

        $file = $this->workbook([
            UserAccountsImport::STUDENT_SHEET => [
                $this->studentHeadings(),
                ['T000000005', 'akun.kedua@example.test', '', ''],
            ],
        ]);

        $this->import($file);

        $this->assertFalse(User::query()->where('email', 'akun.kedua@example.test')->exists());
        $this->assertSame($akunLama->id, $siswa->fresh()->user_id);
    }

    // ================================================== I. sandi sementara

    public function test_akun_staf_wajib_ganti_sandi_dan_akun_siswa_tidak(): void
    {
        $this->student('T000000006');

        $file = $this->workbook([
            UserAccountsImport::TEACHER_SHEET => [
                $this->teacherHeadings(),
                ['Guru Sandi', 'guru.sandi@example.test', 'GURU', '', ''],
            ],
            UserAccountsImport::STUDENT_SHEET => [
                $this->studentHeadings(),
                ['T000000006', 'siswa.sandi@example.test', '', ''],
            ],
        ]);

        $this->import($file);

        $guru = User::query()->where('email', 'guru.sandi@example.test')->first();
        $siswa = User::query()->where('email', 'siswa.sandi@example.test')->first();

        $this->assertNotNull($guru);
        $this->assertNotNull($siswa);

        // Staf punya halaman ganti sandi di panel; peran portal tidak (butir 593).
        $this->assertTrue($guru->must_change_password);
        $this->assertFalse($siswa->must_change_password);

        // Sandinya ter-hash, bukan tersimpan apa adanya.
        $this->assertNotSame('', $guru->password);
        $this->assertStringStartsWith('$', $guru->password);
    }

    // ================================================== J. sandi tidak ke log

    public function test_sandi_sementara_tidak_pernah_masuk_log(): void
    {
        $tercatat = [];
        Log::listen(function ($message) use (&$tercatat): void {
            $tercatat[] = $message->message.' '.json_encode($message->context);
        });

        $file = $this->workbook([
            UserAccountsImport::TEACHER_SHEET => [
                $this->teacherHeadings(),
                ['Guru Rahasia', 'guru.rahasia@example.test', 'GURU', '', ''],
            ],
        ]);

        $this->import($file);

        $akun = User::query()->where('email', 'guru.rahasia@example.test')->first();
        $this->assertNotNull($akun);

        foreach ($tercatat as $line) {
            $this->assertStringNotContainsString('password', strtolower($line));
            $this->assertStringNotContainsString($akun->password, $line);
        }
    }

    // ================================================== K. semua atau tidak ada

    public function test_satu_baris_keliru_membatalkan_seluruh_impor(): void
    {
        $this->student('T000000007');

        $file = $this->workbook([
            UserAccountsImport::TEACHER_SHEET => [
                $this->teacherHeadings(),
                ['Guru Sah', 'guru.sah@example.test', 'GURU', '', ''],
            ],
            UserAccountsImport::STUDENT_SHEET => [
                $this->studentHeadings(),
                ['T000000007', 'siswa.sah@example.test', '', ''],
                ['T999999999', 'siswa.hantu@example.test', '', ''],
            ],
        ]);

        $this->import($file);

        // Tidak satu pun dibuat, termasuk dua baris yang sah.
        $this->assertSame(0, User::query()->whereIn('email', [
            'guru.sah@example.test',
            'siswa.sah@example.test',
            'siswa.hantu@example.test',
        ])->count());

        $this->assertNull(Student::query()->where('nis', 'T000000007')->value('user_id'));
    }

    // ================================================== L/M. berkas rusak

    public function test_lembar_yang_tidak_dikenali_tidak_membuat_apa_pun(): void
    {
        $file = $this->workbook([
            'Sheet1' => [
                ['nama', 'email'],
                ['Siapa Saja', 'siapa@example.test'],
            ],
        ]);

        $this->import($file);

        $this->assertFalse(User::query()->where('email', 'siapa@example.test')->exists());
    }

    public function test_kolom_wajib_yang_hilang_menolak_impor(): void
    {
        $file = $this->workbook([
            UserAccountsImport::TEACHER_SHEET => [
                ['nama', 'hp'],
                ['Tanpa Surel', '081200000000'],
            ],
        ]);

        $this->import($file);

        $this->assertSame(0, User::query()->where('name', 'Tanpa Surel')->count());
    }

    // ================================================== template konsisten

    /**
     * Judul kolom template harus sama persis dengan kontrak importer.
     *
     * Berkas contoh yang disalin tangan akan menyimpang begitu importer berubah,
     * dan berkas contoh yang menyimpang lebih buruk daripada tidak ada berkas
     * contoh sama sekali (butir 497).
     */
    public function test_template_memakai_judul_kolom_dari_kontrak_importer(): void
    {
        $sheets = (new AccountTemplateExport)->sheets();

        $this->assertSame(UserAccountsImport::TEACHER_SHEET, $sheets[0]->title());
        $this->assertSame(array_keys(UserAccountsImport::TEACHER_COLUMNS), $sheets[0]->headings());

        $this->assertSame(UserAccountsImport::STUDENT_SHEET, $sheets[1]->title());
        $this->assertSame(array_keys(UserAccountsImport::STUDENT_COLUMNS), $sheets[1]->headings());

        // Tidak ada kolom kata sandi di template mana pun.
        foreach ([UserAccountsImport::TEACHER_COLUMNS, UserAccountsImport::STUDENT_COLUMNS] as $columns) {
            foreach (array_keys($columns) as $column) {
                $this->assertStringNotContainsString('sandi', $column);
                $this->assertStringNotContainsString('password', $column);
            }
        }
    }

    public function test_template_tidak_memuat_baris_data(): void
    {
        $sheets = (new AccountTemplateExport)->sheets();

        $this->assertSame([], $sheets[0]->array());
        $this->assertSame([], $sheets[1]->array());
    }

    // ================================================== N. isolasi tenant

    public function test_guru_tidak_dapat_mengimpor_akun(): void
    {
        $guru = User::factory()->forSchool($this->school)->withRole(RoleName::Guru)->create();

        $this->assertFalse($guru->can('import', User::class));
    }

    public function test_admin_sekolah_dan_super_admin_boleh_mengimpor(): void
    {
        $super = User::factory()->withRole(RoleName::SuperAdmin)->create(['school_id' => null]);

        $this->assertTrue($this->admin->can('import', User::class));
        $this->assertTrue($super->can('import', User::class));
    }

    public function test_unduhan_template_menuntut_wewenang(): void
    {
        $guru = User::factory()->forSchool($this->school)->withRole(RoleName::Guru)->create();

        $this->actingAs($guru)
            ->get(route('filament.admin.users.import-template'))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get(route('filament.admin.users.import-template'))
            ->assertSuccessful();
    }
}
