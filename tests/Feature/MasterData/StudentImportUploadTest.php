<?php

namespace Tests\Feature\MasterData;

use App\Enums\RoleName;
use App\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Unggahan berkas impor siswa — jalur yang dilihat peramban (M9.3).
 *
 * Yang diuji di sini bukan impornya melainkan satu permintaan yang selama ini
 * tidak pernah diuji: setelah berkas tersimpan, FilePond memuat ulang berkas
 * itu dari URL yang diberikan Filament. Bila URL itu tidak dapat diambil,
 * pengguna melihat "Kesalahan saat memuat" walaupun berkasnya sudah aman di
 * disk (butir 581).
 */
class StudentImportUploadTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $school = School::factory()->create(['code' => 'PUSAT']);
        $this->admin = User::factory()->forSchool($school)->withRole(RoleName::SchoolAdmin)->create();

        $this->actingAs($this->admin);
    }

    /**
     * Menaruh berkas sintetis di tempat yang sama seperti hasil unggahan, lalu
     * mengembalikan lintasannya.
     */
    protected function storedImportFile(): string
    {
        $path = 'imports/'.Str::uuid()->toString().'.xlsx';

        Storage::disk('local')->put($path, 'berkas sintetis, bukan xlsx sungguhan');

        return $path;
    }

    /**
     * URL yang diserahkan Filament ke peramban untuk berkas yang sudah
     * tersimpan — persis yang dipanggil `$wire.getFormUploadedFiles`.
     */
    protected function uploadedFileUrl(string $stored): string
    {
        $url = null;

        Livewire::test(ListStudents::class)
            ->mountAction('import')
            ->set('mountedActionsData.0.file', [Str::uuid()->toString() => $stored])
            ->call('getFormUploadedFiles', 'mountedActionsData.0.file')
            ->assertReturned(function (?array $files) use (&$url): bool {
                $url = collect($files ?? [])->filter()->first()['url'] ?? null;

                return true;
            });

        $this->assertIsString($url, 'Filament tidak memberi URL untuk berkas yang tersimpan.');

        return $url;
    }

    public function test_url_berkas_terunggah_dapat_diambil(): void
    {
        $url = $this->uploadedFileUrl($this->storedImportFile());

        $this->get($url)->assertOk();
    }

    public function test_url_berkas_terunggah_dapat_diambil_dari_peramban_ponsel(): void
    {
        /*
         * Keluhan datang dari tampilan ponsel. Permintaannya sama persis —
         * yang membedakan hanya perambannya — sehingga bila jalur ini benar,
         * ia benar untuk keduanya.
         */
        $url = $this->uploadedFileUrl($this->storedImportFile());

        $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) '
                .'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
        ])->get($url)->assertOk();
    }

    public function test_berkas_impor_tidak_dapat_diambil_tanpa_izin(): void
    {
        /*
         * Berkas ini memuat data siswa sungguhan. URL-nya boleh dapat diambil
         * oleh yang mengunggahnya, tidak oleh siapa pun yang menebak lintasan.
         */
        $url = $this->uploadedFileUrl($this->storedImportFile());

        $telanjang = parse_url($url, PHP_URL_PATH);

        $this->get($telanjang)->assertForbidden();
    }

    public function test_tanda_tangan_yang_dirusak_ditolak(): void
    {
        $url = $this->uploadedFileUrl($this->storedImportFile());

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertArrayHasKey('signature', $query, 'URL-nya seharusnya bertanda tangan.');

        /*
         * Tanda tangannya diganti seluruhnya, bukan diubah satu huruf.
         *
         * Versi pertama test ini mengganti huruf pertama menjadi "0" — yang
         * tidak mengubah apa pun ketika huruf pertamanya memang sudah "0".
         * Test yang gagal sekali per enam belas jalan lebih buruk daripada
         * tidak ada test: ia mengajari orang untuk mengulang jalannya.
         */
        $rusak = str_replace(
            'signature='.$query['signature'],
            'signature='.str_repeat('0', strlen($query['signature'])),
            $url,
        );

        $this->assertNotSame($url, $rusak);

        $this->get($rusak)->assertForbidden();
    }

    public function test_pengetatan_tipe_berkas_tidak_ikut_longgar(): void
    {
        /*
         * Jalan pintas yang menggoda untuk memperbaiki unggahan adalah
         * melonggarkan tipe yang diterima sampai apa pun lewat. Kolom ini
         * membaca data siswa, dan berkas yang salah bentuk baru ketahuan
         * setelah diurai.
         */
        $field = Livewire::test(ListStudents::class)
            ->mountAction('import')
            ->instance()
            ->getMountedActionForm()
            ->getFlatFields()['file'];

        $this->assertSame(
            [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-excel',
            ],
            $field->getAcceptedFileTypes(),
        );

        $this->assertSame(5120, $field->getMaxSize());
        $this->assertTrue($field->isRequired());
        $this->assertSame('private', $field->getVisibility());
    }
}
