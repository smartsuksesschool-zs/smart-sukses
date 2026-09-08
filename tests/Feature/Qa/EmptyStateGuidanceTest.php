<?php

namespace Tests\Feature\Qa;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;
use Database\Seeders\SimulationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Layar kosong harus menerangkan langkah berikutnya, bukan sekadar melapor kosong.
 *
 * Yang diuji di sini **kalimat yang dibaca pengguna**, bukan cara Filament
 * merendernya. Sebuah daftar kosong adalah keadaan paling mudah disalahpahami
 * sebagai kerusakan — dan pada UAT, penguji yang mengira sistemnya rusak akan
 * berhenti menguji, atau melaporkan cacat yang tidak ada (butir 569).
 *
 * Setiap peran diuji pada method-nya sendiri. Menumpuk beberapa peran dalam satu
 * method membuat sesi peran sebelumnya terbawa: `AuthenticateSession` menyimpan
 * hash pengguna di sesi, sehingga `actingAs()` yang berpindah pengguna
 * mengeluarkan yang berikutnya dan menghasilkan pengalihan yang terbaca seolah
 * cacat aplikasi (butir 571).
 */
class EmptyStateGuidanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SchoolSeeder::class);
        $this->seed(SimulationSeeder::class);
    }

    /**
     * Membuka satu halaman sebagai satu peran, dengan sesi yang bersih.
     *
     * Sesi dibuang lebih dulu supaya setiap pemanggilan setara dengan orang
     * yang baru saja masuk. Tanpa itu, `AuthenticateSession` masih memegang
     * hash pengguna sebelumnya dan mengeluarkan pengguna berikutnya — 302 yang
     * terbaca seolah cacat aplikasi padahal murni keadaan test (butir 571).
     */
    protected function pageAs(string $email, string $path): string
    {
        $user = User::query()->where('email', $email)->firstOrFail();

        auth()->logout();
        $this->flushSession();

        return $this->actingAs($user)->get($path)->assertOk()->getContent();
    }

    public function test_daftar_tagihan_kosong_menunjuk_generate_tagihan(): void
    {
        /*
         * Bekal UAT sengaja tidak menerbitkan tagihan periode berjalan supaya
         * bendahara benar-benar menjalankan Generate Tagihan (butir 528). Tanpa
         * kalimat ini, yang ia lihat hanyalah daftar kosong.
         */
        $html = $this->pageAs('bendahara.pusat@smartsukses.sch.id', '/admin/student-fees?tableFilters[period][value]=2099-01');

        $this->assertStringContainsString('Belum ada tagihan pada tampilan ini', $html);
        $this->assertStringContainsString('Generate Tagihan', $html);
        $this->assertStringNotContainsString('Tidak ada data yang ditemukan', $html);
    }

    public function test_rapor_kosong_menyebut_dua_langkahnya(): void
    {
        // Dibuat dan diterbitkan adalah dua langkah, dan wali kelas yang
        // berhenti di langkah pertama akan mengira rapornya sudah sampai.
        $html = $this->pageAs('walikelas.pusat@smartsukses.sch.id', '/admin/report-cards');

        $this->assertStringContainsString('Belum ada rapor', $html);
        $this->assertStringContainsString('Generate Rapor Kelas', $html);
        $this->assertStringContainsString('diterbitkan', $html);
    }

    public function test_permintaan_akun_kosong_menerangkan_asal_permintaan(): void
    {
        // Kosong di sini berarti belum ada yang mendaftar — bukan ada yang
        // belum disiapkan admin.
        $html = $this->pageAs('admin.pusat@smartsukses.sch.id', '/admin/account-claims');

        $this->assertStringContainsString('Belum ada permintaan akun', $html);
        $this->assertStringContainsString('Masuk dengan Google', $html);
        $this->assertStringContainsString('Tidak ada yang perlu dibuat', $html);
    }

    public function test_pendaftar_ppdb_kosong_menjelaskan_pendaftaran_pindah(): void
    {
        /*
         * Sejak M7.2 pendaftaran diarahkan ke formulir Google, sehingga daftar
         * ini memang tidak akan bertambah lagi. Tanpa keterangan itu, admin
         * membaca daftar kosong sebagai kerusakan (butir 570).
         */
        $html = $this->pageAs('admin.pusat@smartsukses.sch.id', '/admin/ppdb-registrations');

        $this->assertStringContainsString('Belum ada pendaftar pada daftar ini', $html);
        $this->assertStringContainsString('formulir Google', $html);
    }

    public function test_tidak_ada_layar_kosong_yang_hanya_berbunyi_tidak_ada_data(): void
    {
        // Pagar menyeluruh: halaman yang paling sering dibuka penguji tidak
        // boleh jatuh ke kalimat bawaan Filament.
        $pages = [
            'admin.pusat@smartsukses.sch.id' => [
                '/admin/account-claims',
                '/admin/ppdb-registrations',
                '/admin/notifications',
                '/admin/schedules',
            ],
            'walikelas.pusat@smartsukses.sch.id' => [
                '/admin/report-cards',
            ],
        ];

        foreach ($pages as $email => $paths) {
            foreach ($paths as $path) {
                $html = $this->pageAs($email, $path);

                $this->assertStringNotContainsString(
                    'Tidak ada data yang ditemukan',
                    $html,
                    "{$path} masih memakai kalimat kosong bawaan",
                );
            }
        }
    }
}
