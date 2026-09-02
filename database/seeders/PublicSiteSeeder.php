<?php

namespace Database\Seeders;

use App\Enums\SiteBlockType;
use App\Models\SiteBlock;
use Illuminate\Database\Seeder;

/**
 * Isi bawaan halaman muka publik.
 *
 * Isinya aman — teks dan struktur, tanpa akun, tanpa kata sandi, dan tanpa data
 * pribadi siapa pun. Berbeda dengan SimulationSeeder, ia tidak perlu menolak
 * berjalan di produksi.
 *
 * Tetap **tidak** didaftarkan di DatabaseSeeder. Bukan karena isinya berbahaya,
 * melainkan karena akibatnya: ia menerbitkan ke halaman yang dilihat publik,
 * termasuk enam baris galeri yang belum berfoto. `php artisan db:seed`
 * dijalankan sebagai bagian deployment, dan deployment tidak boleh menerbitkan
 * apa pun ke situs sekolah tanpa ada yang memutuskan begitu (butir 481).
 *
 * Dipanggil dengan sengaja:
 *
 *     php artisan db:seed --class=PublicSiteSeeder
 *
 * Idempotent lewat updateOrCreate pada kunci alaminya, dan ia **tidak pernah
 * menyentuh `site_settings`**: teks, alamat PPDB, alamat blog, dan kontak yang
 * sudah diisi pemilik lewat panel admin tidak boleh dikembalikan ke bawaan oleh
 * seeder yang dijalankan ulang (butir 472).
 *
 * Tidak ada satu angka pun di seluruh isi bawaan — tidak jumlah siswa, tidak
 * jumlah alumni, tidak tahun berdiri. Klaim publik lama belum disahkan sumber
 * terkini mana pun, dan halaman resmi sekolah bukan tempat menebaknya
 * (butir 468).
 */
class PublicSiteSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedUnits();
        $this->seedPrograms();
        $this->seedGallery();
    }

    /**
     * Dua unit pendidikan di bawah payung Smart Sukses School.
     *
     * Penegasan pemilik: Smart Building adalah unit SMA dan Smart Bee adalah
     * unit SD — keduanya **bagian dari** Smart Sukses School, bukan mitra dan
     * bukan lembaga terpisah. Materi lama sempat menampilkannya seolah sejajar,
     * dan halaman muka baru harus menutup salah paham itu (butir 473).
     */
    protected function seedUnits(): void
    {
        $units = [
            [
                'title' => 'Smart Building',
                'subtitle' => 'Jenjang SMA',
                'body' => 'Unit sekolah menengah atas Smart Sukses School. Pembelajaran akademik '
                    .'dipadukan dengan kepemimpinan, komunikasi, dan kemandirian untuk menyiapkan '
                    .'siswa menghadapi jenjang berikutnya.',
                'position' => 1,
            ],
            [
                'title' => 'Smart Bee',
                'subtitle' => 'Jenjang SD',
                'body' => 'Unit sekolah dasar Smart Sukses School. Fondasi akademik dibangun '
                    .'bersamaan dengan pembiasaan karakter dan keterampilan dasar, lewat kegiatan '
                    .'yang sesuai usia anak.',
                'position' => 2,
            ],
        ];

        foreach ($units as $unit) {
            $this->block(SiteBlockType::Unit, $unit);
        }
    }

    /**
     * Program dan pendekatan.
     *
     * Hanya konsep yang memang didukung materi publik yang ada: beasiswa,
     * pembelajaran akademik, character building, life skills, serta soft skills
     * dan kepemimpinan. Tidak ada program karangan.
     */
    protected function seedPrograms(): void
    {
        $programs = [
            [
                'title' => 'Beasiswa Pendidikan',
                'body' => 'Akses pendidikan bagi anak berpotensi yang terkendala biaya.',
                'position' => 1,
            ],
            [
                'title' => 'Pembelajaran Akademik',
                'body' => 'Kegiatan belajar terstruktur dengan pendampingan guru dan penilaian berkala.',
                'position' => 2,
            ],
            [
                'title' => 'Character Building',
                'body' => 'Pembinaan akhlak dan kebiasaan baik sebagai bagian tetap keseharian sekolah.',
                'position' => 3,
            ],
            [
                'title' => 'Life Skills',
                'body' => 'Keterampilan hidup praktis yang menumbuhkan kemandirian siswa.',
                'position' => 4,
            ],
            [
                'title' => 'Soft Skills & Kepemimpinan',
                'body' => 'Komunikasi, kerja sama, dan kepemimpinan dilatih lewat kegiatan nyata.',
                'position' => 5,
            ],
        ];

        foreach ($programs as $program) {
            $this->block(SiteBlockType::Program, $program);
        }
    }

    /**
     * Kerangka galeri kegiatan — **judul saja, tanpa berkas gambar**.
     *
     * Foto kegiatan Smart Sukses School yang sungguhan belum diserahkan. Yang
     * dibuat di sini hanya tempatnya, sehingga bagian galeri tampil utuh dan
     * pemilik tinggal mengunggah foto ke baris yang sudah ada — tanpa satu baris
     * kode pun berubah saat fotonya tiba (butir 467).
     *
     * Tidak ada gambar sekolah lain yang diunduh untuk mengisi sementara:
     * foto anak-anak yang bukan siswa Smart Sukses School, dipasang di halaman
     * resmi Smart Sukses School, adalah klaim yang salah — sekalipun hanya
     * dimaksudkan sebagai contoh.
     */
    protected function seedGallery(): void
    {
        $activities = [
            ['title' => 'Kegiatan Belajar', 'position' => 1],
            ['title' => 'Pembinaan Karakter', 'position' => 2],
            ['title' => 'Life Skills', 'position' => 3],
            ['title' => 'Kegiatan Kebersamaan', 'position' => 4],
            ['title' => 'Prestasi Siswa', 'position' => 5],
            ['title' => 'Kegiatan Luar Kelas', 'position' => 6],
        ];

        foreach ($activities as $activity) {
            $this->block(SiteBlockType::Gallery, $activity);
        }
    }

    /**
     * `image_path` sengaja tidak pernah ikut ditulis: seeder yang dijalankan
     * ulang tidak boleh melepas foto yang sudah diunggah pemilik.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function block(SiteBlockType $type, array $attributes): void
    {
        SiteBlock::updateOrCreate(
            [
                'type' => $type->value,
                'title' => $attributes['title'],
            ],
            [
                'subtitle' => $attributes['subtitle'] ?? null,
                'body' => $attributes['body'] ?? null,
                'position' => $attributes['position'] ?? 0,
                'is_published' => true,
            ],
        );
    }
}
