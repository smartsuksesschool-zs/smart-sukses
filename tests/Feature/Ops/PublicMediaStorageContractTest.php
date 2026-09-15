<?php

namespace Tests\Feature\Ops;

use App\Models\ReportCard;
use App\Models\School;
use App\Models\SiteBlock;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Finance\PaymentRecorder;
use App\Services\Finance\TransactionRecorder;
use App\Support\PpdbDocument;
use App\Support\PublicSite;
use App\Support\SchoolBranding;
use App\Support\StudentPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Kontrak disk publik yang menjadi dasar Railway Volume (M10B).
 *
 * Rencana persistensinya bertumpu pada tiga fakta, dan ketiganya tinggal di
 * repositori ini, bukan di Railway:
 *
 *  1. Seluruh media publik mendarat di satu direktori — akar disk `public` —
 *     sehingga satu Volume yang dipasang tepat di sana menangkap semuanya.
 *  2. `php artisan storage:link`, yang dijalankan Railpack setiap container
 *     start, menautkan `public/storage` ke direktori yang sama itu.
 *  3. Tidak ada job antrean yang menulis maupun membaca berkas publik, sehingga
 *     Volume cukup dipasang pada service web saja.
 *
 * Mengubah salah satunya — akar disk dipindah, tautan diarahkan ke tempat lain,
 * atau sebuah job mulai menyematkan logo — tidak akan menghasilkan satu pun
 * galat. Berkasnya hanya diam-diam berhenti bertahan, dan yang pertama menyadari
 * adalah penguji yang melihat gambar hilang sesudah redeploy (butir 586).
 *
 * Railway Volume sendiri tidak diuji di sini; yang dijaga adalah kontrak Laravel
 * yang diandalkannya.
 *
 * Isi Volume hanya media yang memang publik: gambar dan logo situs, logo
 * cabang, dan foto profil. Foto siswa sengaja **tidak** — ia data pribadi anak
 * dan tinggal di disk privat sejak butir 587.
 */
class PublicMediaStorageContractTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------- akar & tautan

    public function test_disk_publik_berakar_di_storage_app_public(): void
    {
        /*
         * Inilah target mount Volume. Akarnya relatif terhadap akar aplikasi,
         * sehingga di Railway (akar /app) ia menjadi /app/storage/app/public.
         */
        $disk = config('filesystems.disks.public');

        $this->assertSame('local', $disk['driver']);
        $this->assertSame(storage_path('app/public'), $disk['root']);
        $this->assertSame('public', $disk['visibility']);
    }

    public function test_tautan_storage_menunjuk_akar_disk_publik(): void
    {
        /*
         * Pemetaan ini yang dibuat `storage:link`. Kalau sasarannya berbeda
         * dari akar disk, berkas yang ditulis ke Volume tidak akan pernah dapat
         * diambil lewat /storage/… walau tersimpan dengan selamat.
         */
        $links = config('filesystems.links');

        $this->assertArrayHasKey(public_path('storage'), $links);
        $this->assertSame(storage_path('app/public'), $links[public_path('storage')]);
        $this->assertSame(config('filesystems.disks.public.root'), $links[public_path('storage')]);
    }

    public function test_media_publik_tidak_berbagi_akar_dengan_disk_privat(): void
    {
        /*
         * Volume dipasang sempit di akar disk publik, bukan di storage/. Kalau
         * akar disk `local` suatu hari berada di dalamnya, berkas privat —
         * temp impor, unggahan Livewire, PDF rapor bawaan — ikut persisten di
         * Volume publik dan ikut tersaji lewat tautan /storage/.
         */
        $public = rtrim(config('filesystems.disks.public.root'), '/\\');
        $local = rtrim(config('filesystems.disks.local.root'), '/\\');

        $this->assertNotSame($public, $local);
        $this->assertFalse(str_starts_with($local.DIRECTORY_SEPARATOR, $public.DIRECTORY_SEPARATOR));
        $this->assertFalse(str_starts_with($public.DIRECTORY_SEPARATOR, $local.DIRECTORY_SEPARATOR));
    }

    // -------------------------------------------------------- bentuk URL

    public function test_url_disk_publik_berbentuk_storage_tanpa_tanda_tangan(): void
    {
        /*
         * Media publik memang publik: dilayani langsung oleh server web lewat
         * tautan, tanpa melewati Laravel. URL bertanda tangan milik disk privat
         * (butir 581) tidak boleh ikut terbawa ke sini.
         */
        $url = Storage::disk('public')->url('site/contoh.jpg');

        $this->assertSame(rtrim((string) config('app.url'), '/').'/storage/site/contoh.jpg', $url);
        $this->assertStringNotContainsString('signature=', $url);
    }

    public function test_gambar_blok_situs_disajikan_lewat_storage(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('site/contoh-sintetis.jpg', 'gambar-sintetis');

        $block = new SiteBlock(['image_path' => SiteSetting::MEDIA_DIRECTORY.'/contoh-sintetis.jpg']);

        $this->assertStringEndsWith('/storage/site/contoh-sintetis.jpg', (string) $block->imageUrl());
    }

    public function test_logo_dan_gambar_utama_situs_disajikan_lewat_storage(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('site/logo-sintetis.png', 'logo-sintetis');
        Storage::disk('public')->put('site/utama-sintetis.jpg', 'gambar-sintetis');

        SiteSetting::set('logo_path', SiteSetting::MEDIA_DIRECTORY.'/logo-sintetis.png');
        SiteSetting::set('hero_image_path', SiteSetting::MEDIA_DIRECTORY.'/utama-sintetis.jpg');

        $site = app(PublicSite::class);

        $this->assertStringEndsWith('/storage/site/logo-sintetis.png', $site->logoUrl());
        $this->assertStringEndsWith('/storage/site/utama-sintetis.jpg', (string) $site->heroImageUrl());
    }

    public function test_logo_situs_jatuh_ke_bawaan_bila_belum_disetel(): void
    {
        // Bawaan dari public/, bukan dari disk publik — tidak bergantung Volume.
        $this->assertSame(asset(PublicSite::DEFAULT_LOGO), app(PublicSite::class)->logoUrl());
        $this->assertFileExists(public_path(PublicSite::DEFAULT_LOGO));
    }

    public function test_logo_cabang_disajikan_lewat_storage(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('schools/logos/logo-sintetis.png', 'logo-sintetis');

        $school = School::factory()->create(['logo_url' => 'schools/logos/logo-sintetis.png']);

        $this->assertStringEndsWith(
            '/storage/schools/logos/logo-sintetis.png',
            (string) app(SchoolBranding::class)->logoUrl($school),
        );
    }

    // ------------------------------------------ jaminan Volume khusus web

    public function test_tidak_ada_job_antrean_yang_menyentuh_berkas_publik(): void
    {
        /*
         * Dasar keputusan memasang Volume pada service web saja. Worker berjalan
         * di service terpisah dengan berkas sistemnya sendiri; job yang menulis
         * atau membaca media publik akan bekerja di lokal lalu gagal diam-diam
         * di Railway.
         */
        $pola = '/disk\(\s*[\'"]public[\'"]\s*\)|MEDIA_DISK|LOGO_DISK|photo_url|avatar_url|image_path|logo_url|public_path\(/';

        foreach (File::allFiles(app_path('Jobs')) as $file) {
            $this->assertDoesNotMatchRegularExpression(
                $pola,
                $file->getContents(),
                $file->getFilename().' menyentuh berkas publik; Volume khusus web tidak lagi cukup.',
            );
        }
    }

    public function test_templat_pdf_rapor_tidak_menyematkan_berkas_publik(): void
    {
        /*
         * PDF rapor dirender di worker. Templat yang kelak menyematkan logo
         * cabang dari disk publik akan membuat worker membutuhkan berkas yang
         * hanya ada di Volume web — dan PDF-nya terbit tanpa logo, tanpa galat.
         */
        $templat = (string) file_get_contents(resource_path('views/pdf/report-card.blade.php'));

        $this->assertDoesNotMatchRegularExpression(
            '/<img|public_path\(|storage_path\(|Storage::|logo_url|asset\(/',
            $templat,
        );
    }

    // --------------------------------------------- isi Volume publik

    public function test_kategori_volume_publik_tetap_di_disk_publik(): void
    {
        $this->assertSame('public', SiteSetting::MEDIA_DISK);
        $this->assertSame('public', School::LOGO_DISK);
        $this->assertSame('public', User::AVATAR_DISK);
    }

    public function test_foto_siswa_bukan_isi_volume_publik(): void
    {
        /*
         * Foto anak di Volume publik berarti foto yang menetap selamanya dan
         * dapat dibuka siapa pun yang memegang URL-nya (butir 587).
         */
        $this->assertNotSame('public', StudentPhoto::disk());
        $this->assertSame(StudentPhoto::DISK, config('storage.student_photo_disk'));
        $this->assertNull(config('filesystems.disks.'.StudentPhoto::disk().'.url'));

        $sumber = (string) file_get_contents(app_path('Filament/Resources/StudentResource.php'));

        $this->assertDoesNotMatchRegularExpression('/disk\(\s*[\'"]public[\'"]\s*\)/', $sumber);
        $this->assertStringNotContainsString("directory('students')", $sumber);
    }

    public function test_hanya_media_publik_yang_membangun_url_disk(): void
    {
        /*
         * URL disk (`Storage::url()`, `->url()` pada disk) dapat dibuka tanpa
         * login, dan URL bertanda tangan dapat dibuka siapa pun yang
         * memegangnya. Keduanya hanya boleh lahir di pembaca media publik.
         * Komentar diabaikan; yang diperiksa kode yang berjalan.
         */
        $diizinkan = [
            'app/Models/SiteBlock.php',
            'app/Models/User.php',
            'app/Support/PublicSite.php',
            'app/Support/SchoolBranding.php',
        ];

        $pola = '/Storage::url\(|temporaryUrl\(|(Storage::disk\([^)]*\)|\$disk)->url\(/';
        $ditemukan = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $kode = '';

            foreach (token_get_all($file->getContents()) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $kode .= is_array($token) ? $token[1] : $token;
            }

            if (preg_match($pola, $kode) === 1) {
                $ditemukan[] = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            }
        }

        sort($ditemukan);

        $this->assertSame($diizinkan, $ditemukan);
    }

    // --------------------------------------------- privat tetap privat

    public function test_kategori_privat_tidak_pernah_menunjuk_disk_publik(): void
    {
        /*
         * Volume publik akan membuat apa pun yang tersimpan di disk `public`
         * bertahan selamanya dan tersaji tanpa autentikasi. Tidak satu pun
         * kategori privat — keempat kategori M10A dan foto siswa — boleh jatuh
         * ke sana, termasuk pada bawaannya.
         */
        foreach ([
            ReportCard::pdfDisk(),
            PaymentRecorder::proofDisk(),
            TransactionRecorder::proofDisk(),
            PpdbDocument::disk(),
            StudentPhoto::disk(),
        ] as $disk) {
            $this->assertNotSame('public', $disk);
        }
    }
}
