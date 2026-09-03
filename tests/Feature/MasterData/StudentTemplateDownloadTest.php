<?php

namespace Tests\Feature\MasterData;

use App\Enums\RoleName;
use App\Enums\StudentStatus;
use App\Exports\StudentTemplateExport;
use App\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Imports\StudentsImport;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Berkas contoh import siswa — umpan balik pemilik: admin tidak boleh menebak
 * format berkasnya (butir 496).
 *
 * Berkas yang dibangkitkan tidak boleh memuat satu pun identitas sungguhan.
 * Contoh pengisiannya karangan, dan tesnya memeriksa hal itu, bukan
 * mempercayainya.
 */
class StudentTemplateDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected User $admin;

    /** @var array<int, string> */
    protected array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create(['code' => 'PUSAT']);
        $this->admin = User::factory()->forSchool($this->school)->withRole(RoleName::SchoolAdmin)->create();
    }

    protected function url(): string
    {
        return route('filament.admin.students.import-template');
    }

    /**
     * Berkas hasil unduhan, dibuka kembali sebagai spreadsheet.
     *
     * @return array<string, array<int, array<int, mixed>>>
     */
    protected function downloadSheets(): array
    {
        $response = $this->actingAs($this->admin)->get($this->url());
        $response->assertOk();

        // Excel::download mengembalikan BinaryFileResponse: berkasnya sudah ada
        // di disk sementara, jadi dibaca dari sana apa adanya.
        $path = $response->baseResponse->getFile()->getPathname();

        $reader = IOFactory::createReaderForFile($path);
        $book = $reader->load($path);

        $sheets = [];

        foreach ($book->getSheetNames() as $name) {
            $sheet = $book->getSheetByName($name);
            $sheets[$name] = $sheet->rangeToArray(
                'A1:'.$sheet->getHighestDataColumn().$sheet->getHighestDataRow(),
                null,
                false,
                false,
            );
        }

        return $sheets;
    }

    // ================================================== kewenangan

    public function test_admin_sekolah_dapat_mengunduh_berkas_contoh(): void
    {
        $this->actingAs($this->admin)->get($this->url())->assertOk();
    }

    public function test_tamu_tidak_dapat_mengunduh_berkas_contoh(): void
    {
        $this->get($this->url())->assertRedirect();
    }

    /**
     * Peran yang tidak boleh mengimpor juga tidak boleh mengunduh berkas
     * contohnya: kewenangannya satu, menumpang StudentPolicy::import.
     */
    public function test_peran_tanpa_hak_import_ditolak(): void
    {
        foreach ([RoleName::Guru, RoleName::Siswa, RoleName::OrangTua, RoleName::Bendahara] as $role) {
            $user = User::factory()->forSchool($this->school)->withRole($role)->create();

            $this->actingAs($user)->get($this->url())->assertForbidden();
        }
    }

    // ================================================== isi berkas

    public function test_nama_berkasnya_jelas_dan_berformat_xlsx(): void
    {
        $response = $this->actingAs($this->admin)->get($this->url());

        $this->assertSame('template_import_siswa.xlsx', StudentTemplateExport::filename());
        $this->assertStringContainsString(
            'template_import_siswa.xlsx',
            (string) $response->headers->get('content-disposition'),
        );
        $this->assertStringContainsString(
            'spreadsheetml',
            (string) $response->headers->get('content-type'),
        );
    }

    public function test_berkasnya_memuat_lembar_data_dan_lembar_petunjuk(): void
    {
        $sheets = $this->downloadSheets();

        $this->assertArrayHasKey('Data Siswa', $sheets);
        $this->assertArrayHasKey('Petunjuk', $sheets);
    }

    /**
     * Judul kolom di berkas contoh **persis** yang dibaca importer. Inilah
     * jaminan yang membuat berkas contoh berguna: kalau boleh menyimpang, ia
     * hanya memindahkan tebakan (butir 497).
     */
    public function test_judul_kolomnya_persis_kontrak_importer(): void
    {
        $sheets = $this->downloadSheets();
        $headings = array_values(array_filter($sheets['Data Siswa'][0], fn ($c): bool => $c !== null && $c !== ''));

        $this->assertSame(array_keys(StudentsImport::COLUMNS), $headings);
        $this->assertContains('nis', $headings);
        $this->assertContains('nisn', $headings);
        $this->assertContains('nama_lengkap', $headings);
    }

    public function test_lembar_data_tidak_memuat_baris_contoh(): void
    {
        $sheets = $this->downloadSheets();

        // Hanya baris judul. Baris contoh di lembar ini akan ikut terimpor
        // sebagai siswa begitu berkasnya diunggah kembali (butir 498).
        $this->assertCount(1, $sheets['Data Siswa']);
    }

    public function test_lembar_petunjuk_menjelaskan_setiap_kolom(): void
    {
        $sheets = $this->downloadSheets();
        $text = json_encode($sheets['Petunjuk'], JSON_UNESCAPED_UNICODE);

        foreach (array_keys(StudentsImport::COLUMNS) as $column) {
            $this->assertStringContainsString($column, $text, "kolom {$column} tidak dijelaskan");
        }

        foreach (['Wajib', 'Opsional', 'YYYY-MM-DD', 'ACTIVE', 'merged cells'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
    }

    public function test_contoh_pengisian_ada_di_lembar_petunjuk_bukan_di_lembar_data(): void
    {
        $sheets = $this->downloadSheets();

        $this->assertStringContainsString('Budi Contoh', json_encode($sheets['Petunjuk']));
        $this->assertStringNotContainsString('Budi Contoh', json_encode($sheets['Data Siswa']));
    }

    /**
     * Berkas contoh dibangkitkan dari konstanta, bukan dari basis data. Siswa
     * yang ada di sistem tidak boleh bocor lewat berkas yang dibagikan.
     */
    public function test_berkas_contoh_tidak_memuat_data_siswa_yang_ada(): void
    {
        Student::factory()->create([
            'school_id' => $this->school->id,
            'nis' => '9911223344',
            'full_name' => 'Siswa Nyata Dalam Basis Data',
        ]);

        $text = json_encode($this->downloadSheets(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('9911223344', $text);
        $this->assertStringNotContainsString('Siswa Nyata Dalam Basis Data', $text);
    }

    public function test_surel_contoh_memakai_domain_yang_dicadangkan(): void
    {
        $text = json_encode($this->downloadSheets(), JSON_UNESCAPED_UNICODE);

        // RFC 2606 — `.test` memang dicadangkan untuk contoh, jadi tidak ada
        // kotak surat orang lain yang bisa tersurati karenanya.
        $this->assertStringContainsString('@example.test', $text);
        $this->assertStringNotContainsString('@gmail.com', $text);
    }

    public function test_mengunduh_berkas_contoh_tidak_mengubah_basis_data(): void
    {
        $before = [
            Student::query()->count(),
            User::query()->count(),
            School::query()->count(),
        ];

        $this->actingAs($this->admin)->get($this->url())->assertOk();

        $this->assertSame($before, [
            Student::query()->count(),
            User::query()->count(),
            School::query()->count(),
        ]);
    }

    // ================================================== modal import

    public function test_modal_import_menawarkan_unduhan_berkas_contoh(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ListStudents::class)
            ->assertActionExists('import')
            ->mountAction('import')
            ->assertSee($this->url(), false)
            ->assertSee('Download Template Excel');
    }

    public function test_judul_kolom_yang_salah_menghasilkan_sebab_bukan_nol_diam_diam(): void
    {
        $path = $this->sheetFile([
            ['nomor_induk', 'nama', 'kelamin'],
            ['1', 'Siswa Karangan', 'L'],
        ]);

        $import = new StudentsImport($this->school->id);
        Excel::import($import, $path);

        $this->assertTrue($import->headerMismatch());
        $this->assertSame(['nis', 'nama_lengkap', 'jenis_kelamin'], $import->missingColumns());
        $this->assertSame(0, $import->imported);
    }

    public function test_berkas_tanpa_baris_data_dibedakan_dari_judul_kolom_yang_salah(): void
    {
        $path = $this->sheetFile([array_keys(StudentsImport::COLUMNS)]);

        $import = new StudentsImport($this->school->id);
        Excel::import($import, $path);

        $this->assertFalse($import->headerMismatch());
        $this->assertFalse($import->sawRows);
    }

    // ================================================== regresi bolak-balik

    /**
     * Cacat nyata yang ditemukan uji manual: berkas contoh resmi yang diisi
     * tanpa mengubah satu judul kolom pun ditolak dengan "Judul kolom tidak
     * dikenali".
     *
     * Sebabnya Maatwebsite memanggil `collection()` **sekali per lembar**, dan
     * lembar "Petunjuk" — yang judul kolomnya memang berbeda — menimpa
     * kesimpulan lembar "Data Siswa" yang sudah benar. Barisnya tetap masuk
     * basis data sementara antarmuka melaporkan kegagalan (butir 504).
     *
     * Tes ini menempuh jalur yang sama dengan aksi import Filament: berkasnya
     * dibangkitkan StudentTemplateExport, diisi, lalu dibaca lewat
     * Excel::import.
     */
    public function test_berkas_contoh_resmi_yang_diisi_diterima_apa_adanya(): void
    {
        $path = $this->filledTemplate([
            'nis' => 'Z0001',
            'nisn' => '0088888888',
            'nama_lengkap' => 'Siswa Karangan Satu',
            'jenis_kelamin' => 'L',
            'status' => 'ACTIVE',
        ]);

        $import = new StudentsImport($this->school->id);
        Excel::import($import, $path);

        $this->assertFalse($import->headerMismatch(), 'template resmi ditolak sebagai judul kolom tak dikenali');
        $this->assertSame([], $import->missingColumns());
        $this->assertSame([], $import->errors);
        $this->assertSame(1, $import->imported);

        $this->assertDatabaseHas('students', [
            'school_id' => $this->school->id,
            'nis' => 'Z0001',
            'nisn' => '0088888888',
        ]);
    }

    /**
     * Lembar "Petunjuk" dilewati, bukan diimpor. Isinya penjelasan, dan satu
     * baris contoh karangan yang tidak boleh pernah menjadi siswa.
     */
    public function test_lembar_petunjuk_tidak_pernah_menjadi_siswa(): void
    {
        $path = $this->filledTemplate([
            'nis' => 'Z0001',
            'nama_lengkap' => 'Siswa Karangan Satu',
            'jenis_kelamin' => 'L',
        ]);

        $import = new StudentsImport($this->school->id);
        Excel::import($import, $path);

        $this->assertSame(1, Student::query()->where('school_id', $this->school->id)->count());
        $this->assertDatabaseMissing('students', ['full_name' => 'Budi Contoh Pratama']);

        $sheets = collect($import->sheets)->keyBy('name');
        $this->assertTrue($sheets[StudentsImport::SHEET]['matched']);
        $this->assertFalse($sheets['Petunjuk']['matched']);
    }

    /**
     * Berkas contoh diunduh lalu diunggah tanpa diisi. Lembar datanya ada tetapi
     * kosong, jadi jawabannya "tidak ada baris data" — bukan penolakan judul
     * kolom, yang akan menjadi penolakan keliru yang sama sekali lagi
     * (butir 505).
     */
    public function test_template_kosong_dilaporkan_tanpa_baris_bukan_judul_salah(): void
    {
        $path = $this->templateFile();

        $import = new StudentsImport($this->school->id);
        Excel::import($import, $path);

        $this->assertFalse($import->headerMismatch());
        $this->assertFalse($import->sawRows);
        $this->assertSame(0, $import->imported);
    }

    public function test_judul_kolom_tahan_bom_dan_spasi(): void
    {
        $headings = array_keys(StudentsImport::COLUMNS);
        $headings[0] = "\u{FEFF}NIS ";

        $path = $this->sheetFile([
            $headings,
            ['Z0001', '0088888888', 'Siswa Karangan Satu', 'L', '', '', '', '', '', '', '', '', 'ACTIVE'],
        ]);

        $import = new StudentsImport($this->school->id);
        Excel::import($import, $path);

        $this->assertFalse($import->headerMismatch());
        $this->assertSame(1, $import->imported);
    }

    public function test_kolom_yang_benar_benar_diganti_nama_tetap_ditolak(): void
    {
        $headings = array_keys(StudentsImport::COLUMNS);
        $headings[2] = 'nama_siswa';

        $path = $this->sheetFile([
            $headings,
            ['Z0001', '0088888888', 'Siswa Karangan Satu', 'L', '', '', '', '', '', '', '', '', 'ACTIVE'],
        ]);

        $import = new StudentsImport($this->school->id);
        Excel::import($import, $path);

        $this->assertTrue($import->headerMismatch());
        $this->assertSame(['nama_lengkap'], $import->missingColumns());
        $this->assertSame(0, $import->imported);
    }

    public function test_setiap_kolom_wajib_yang_hilang_disebut_namanya(): void
    {
        foreach (StudentsImport::REQUIRED_COLUMNS as $dropped) {
            $headings = array_values(array_diff(array_keys(StudentsImport::COLUMNS), [$dropped]));

            $path = $this->sheetFile([$headings, array_fill(0, count($headings), 'x')]);

            $import = new StudentsImport($this->school->id);
            Excel::import($import, $path);

            $this->assertTrue($import->headerMismatch(), "kolom {$dropped} hilang seharusnya ditolak");
            $this->assertSame([$dropped], $import->missingColumns());
        }
    }

    // ================================================== kolom status

    /**
     * Angka tingkat kelas yang keliru ditulis di kolom status tidak boleh
     * ditafsirkan ulang menjadi apa pun — bukan status, dan bukan penempatan
     * kelas. Penempatan kelas tidak diatur lewat berkas ini (butir 507).
     */
    public function test_status_berisi_angka_tingkat_ditolak_dengan_sebab(): void
    {
        $path = $this->filledTemplate([
            'nis' => 'Z0001',
            'nama_lengkap' => 'Siswa Karangan Satu',
            'jenis_kelamin' => 'L',
            'status' => '10',
        ]);

        $import = new StudentsImport($this->school->id);
        Excel::import($import, $path);

        $this->assertFalse($import->headerMismatch());
        $this->assertSame(0, $import->imported);
        $this->assertSame(1, $import->rejected);
        $this->assertCount(1, $import->errors);
        $this->assertStringStartsWith('Baris 2:', $import->errors[0]);

        // Pesannya menyebut nilai yang diterima, bukan sekadar "tidak sah".
        foreach (StudentStatus::cases() as $status) {
            $this->assertStringContainsString($status->value, $import->errors[0]);
        }

        $this->assertDatabaseMissing('students', ['nis' => 'Z0001']);
    }

    public function test_status_kosong_dianggap_active(): void
    {
        $path = $this->filledTemplate([
            'nis' => 'Z0002',
            'nama_lengkap' => 'Siswa Karangan Dua',
            'jenis_kelamin' => 'P',
            'status' => '',
        ]);

        $import = new StudentsImport($this->school->id);
        Excel::import($import, $path);

        $this->assertSame(1, $import->imported);
        $this->assertDatabaseHas('students', ['nis' => 'Z0002', 'status' => 'ACTIVE']);
    }

    public function test_setiap_status_yang_didokumentasikan_diterima(): void
    {
        foreach (StudentStatus::cases() as $i => $status) {
            $path = $this->filledTemplate([
                'nis' => 'Z10'.$i,
                'nama_lengkap' => 'Siswa Karangan '.$i,
                'jenis_kelamin' => 'L',
                'status' => $status->value,
            ]);

            $import = new StudentsImport($this->school->id);
            Excel::import($import, $path);

            $this->assertSame(1, $import->imported, "status {$status->value} ditolak");
        }
    }

    // ================================================== notifikasi vs basis data

    /**
     * Cacat butir 504 membiarkan keadaan terburuk: **barisnya tertulis ke basis
     * data sementara antarmuka melaporkan kegagalan**. Orang yang membaca layar
     * akan mencoba lagi, atau menyerah, tanpa tahu datanya sudah masuk.
     *
     * Kelompok tes ini menempuh aksi import Filament yang sungguhan, lalu
     * membandingkan apa yang dikatakan notifikasi dengan apa yang benar-benar
     * ada di basis data. Keduanya tidak boleh berbeda pendapat (butir 508).
     *
     * @return array{0: string, 1: int}
     */
    protected function importThroughAction(string $sourcePath): array
    {
        Storage::fake('local');

        $stored = 'imports/siswa.xlsx';
        Storage::disk('local')->put($stored, file_get_contents($sourcePath));

        Livewire::actingAs($this->admin)
            ->test(ListStudents::class)
            ->callAction('import', ['file' => [$stored]]);

        return [$stored, Student::query()->where('school_id', $this->school->id)->count()];
    }

    public function test_berkas_sah_menulis_barisnya_dan_tidak_melaporkan_galat_judul(): void
    {
        $path = $this->filledTemplate([
            'nis' => 'Z0001',
            'nisn' => '0088888888',
            'nama_lengkap' => 'Siswa Karangan Satu',
            'jenis_kelamin' => 'L',
            'status' => 'ACTIVE',
        ]);

        [, $written] = $this->importThroughAction($path);

        $this->assertSame(1, $written);
        $this->assertDatabaseHas('students', ['nis' => 'Z0001', 'school_id' => $this->school->id]);

        // Diperiksa ulang lewat importer yang sama: tidak ada galat judul kolom
        // yang bisa muncul untuk berkas ini.
        $probe = new StudentsImport($this->school->id);
        Excel::import($probe, $path);
        $this->assertFalse($probe->headerMismatch());
    }

    public function test_berkas_tak_kompatibel_tidak_menulis_apa_pun_dan_melaporkan_galat_judul(): void
    {
        $path = $this->sheetFile([
            ['nomor_induk', 'nama', 'kelamin'],
            ['1', 'Siswa Karangan', 'L'],
        ]);

        [, $written] = $this->importThroughAction($path);

        $this->assertSame(0, $written);

        $probe = new StudentsImport($this->school->id);
        Excel::import($probe, $path);
        $this->assertTrue($probe->headerMismatch());
        $this->assertSame(0, $probe->imported);
    }

    /**
     * Campuran baris sah dan baris ditolak. Yang tertulis harus sama persis
     * dengan yang dilaporkan — tidak boleh mengaku gagal total setelah ada yang
     * masuk, dan tidak boleh mengaku berhasil ketika tidak ada yang masuk.
     */
    public function test_campuran_baris_sah_dan_ditolak_dilaporkan_apa_adanya(): void
    {
        $headings = array_keys(StudentsImport::COLUMNS);

        $path = $this->sheetFile([
            $headings,
            ['Z0001', '0088888888', 'Siswa Karangan Satu', 'L', '', '', '', '', '', '', '', '', 'ACTIVE'],
            ['Z0002', '0088888881', 'Siswa Karangan Dua', 'X', '', '', '', '', '', '', '', '', 'ACTIVE'],
            ['Z0003', '0088888882', 'Siswa Karangan Tiga', 'P', '', '', '', '', '', '', '', '', '10'],
            ['Z0004', '0088888883', 'Siswa Karangan Empat', 'L', '', '', '', '', '', '', '', '', ''],
        ]);

        $probe = new StudentsImport($this->school->id);
        Excel::import($probe, $path);

        // Dua sah, dua ditolak — dan tidak ada galat judul kolom sama sekali.
        $this->assertFalse($probe->headerMismatch());
        $this->assertSame(2, $probe->imported);
        $this->assertSame(2, $probe->rejected);
        $this->assertCount(2, $probe->errors);

        [, $written] = $this->importThroughAction($path);

        // Yang tertulis = yang dilaporkan.
        $this->assertSame($probe->imported, $written);
        $this->assertDatabaseHas('students', ['nis' => 'Z0001']);
        $this->assertDatabaseHas('students', ['nis' => 'Z0004']);
        $this->assertDatabaseMissing('students', ['nis' => 'Z0002']);
        $this->assertDatabaseMissing('students', ['nis' => 'Z0003']);
    }

    /**
     * Tidak mungkin melaporkan "judul kolom tidak dikenali" ketika ada baris yang
     * benar-benar masuk. Inilah kombinasi yang dulu terjadi.
     */
    public function test_galat_judul_kolom_tidak_pernah_menyertai_baris_yang_masuk(): void
    {
        $cases = [
            $this->filledTemplate([
                'nis' => 'Z0001',
                'nama_lengkap' => 'Siswa Karangan Satu',
                'jenis_kelamin' => 'L',
            ]),
            $this->sheetFile([
                array_keys(StudentsImport::COLUMNS),
                ['Z0002', '0088888881', 'Siswa Karangan Dua', 'P', '', '', '', '', '', '', '', '', 'ACTIVE'],
            ]),
            $this->sheetFile([
                ['nomor_induk', 'nama', 'kelamin'],
                ['1', 'Siswa Karangan', 'L'],
            ]),
            $this->templateFile(),
        ];

        foreach ($cases as $i => $path) {
            $import = new StudentsImport($this->school->id);
            Excel::import($import, $path);

            if ($import->imported > 0) {
                $this->assertFalse(
                    $import->headerMismatch(),
                    "berkas #{$i} menulis {$import->imported} baris tetapi melaporkan judul kolom tidak dikenali",
                );
            }

            if ($import->headerMismatch()) {
                $this->assertSame(0, $import->imported, "berkas #{$i} melaporkan galat judul tetapi tetap menulis");
            }
        }
    }

    public function test_berkas_tanpa_baris_sah_tidak_pernah_mengaku_berhasil(): void
    {
        $path = $this->sheetFile([
            array_keys(StudentsImport::COLUMNS),
            ['Z0001', '0088888888', 'Siswa Karangan Satu', 'X', '', '', '', '', '', '', '', '', 'ACTIVE'],
        ]);

        [, $written] = $this->importThroughAction($path);

        $this->assertSame(0, $written);

        $probe = new StudentsImport($this->school->id);
        Excel::import($probe, $path);

        $this->assertSame(0, $probe->imported);
        $this->assertSame(1, $probe->rejected);
        $this->assertNotSame([], $probe->errors);
    }

    /**
     * Lembar "Petunjuk" tidak pernah menyumbang satu baris siswa pun, bahkan
     * ketika ia satu-satunya lembar berisi di dalam berkasnya.
     */
    public function test_lembar_petunjuk_sendirian_tidak_menulis_siswa(): void
    {
        $path = $this->templateFile();

        $book = IOFactory::createReaderForFile($path)->load($path);
        $book->removeSheetByIndex($book->getIndex($book->getSheetByName(StudentsImport::SHEET)));

        $only = tempnam(sys_get_temp_dir(), 'pet').'.xlsx';
        $this->paths[] = $only;
        (new Xlsx($book))->save($only);

        [, $written] = $this->importThroughAction($only);

        $this->assertSame(0, $written);
        $this->assertDatabaseMissing('students', ['full_name' => 'Budi Contoh Pratama']);
    }

    // ================================================== perkakas berkas

    protected function templateFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        $this->paths[] = $path;

        file_put_contents($path, Excel::raw(new StudentTemplateExport, ExcelFormat::XLSX));

        return $path;
    }

    /**
     * Berkas contoh resmi yang diisi satu baris sintetis, tanpa menyentuh baris
     * judul kolomnya.
     *
     * @param  array<string, string>  $values
     */
    protected function filledTemplate(array $values): string
    {
        $path = $this->templateFile();

        $book = IOFactory::createReaderForFile($path)->load($path);
        $sheet = $book->getSheetByName(StudentsImport::SHEET);

        $row = [];

        foreach (array_keys(StudentsImport::COLUMNS) as $column) {
            $row[] = $values[$column] ?? '';
        }

        $sheet->fromArray($row, null, 'A2');

        $filled = tempnam(sys_get_temp_dir(), 'fil').'.xlsx';
        $this->paths[] = $filled;
        (new Xlsx($book))->save($filled);

        return $filled;
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    protected function sheetFile(array $rows): string
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray($rows, null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'sht').'.xlsx';
        $this->paths[] = $path;
        (new Xlsx($book))->save($path);

        return $path;
    }
}
