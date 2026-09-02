<?php

namespace App\Support;

use App\Enums\SiteBlockType;
use App\Models\SiteBlock;
use App\Models\SiteSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Pembaca tunggal isi halaman muka publik.
 *
 * Seluruh teks, tautan, dan gambar halaman `/` lewat sini — halaman Blade tidak
 * pernah memanggil model isi secara langsung. Gunanya satu: setiap nilai punya
 * bawaan yang masuk akal, sehingga basis data yang belum pernah disunting tetap
 * merender halaman yang utuh, dan admin yang mengosongkan satu field tidak
 * meninggalkan lubang di situs publik (butir 466).
 *
 * Alamat blog dan alamat PPDB **tidak pernah ditulis di dalam template**.
 * Keduanya berpindah domain — blog ke blog.smartsukses.sch.id, PPDB masih
 * Google Form dan suatu saat pindah ke Laravel — dan nama host yang tersebar di
 * belasan berkas Blade berarti perpindahan itu menjadi pekerjaan menyisir,
 * bukan menyunting satu field (butir 470).
 */
class PublicSite
{
    /**
     * Tagline resmi dari pemilik, kata demi kata.
     *
     * Konstanta, bukan pengaturan yang dapat disunting: ini identitas merek yang
     * diserahkan Pak Akbar, dan test menjaganya tetap utuh. Menyimpannya sebagai
     * baris basis data berarti satu salah ketik di panel admin cukup untuk
     * mengubah tagline sekolah tanpa siapa pun menyadarinya.
     */
    public const TAGLINE = 'Belajar dengan Hati, Tumbuh dengan Aksi, Sukses untuk Masa Depan.';

    /** Berkas merek gabungan yang diserahkan pemilik; bawaan bila belum diunggah sendiri. */
    public const DEFAULT_LOGO = 'images/brand/smart-sukses-school-zakat-sukses.webp';

    /**
     * Bawaan seluruh pengaturan teks.
     *
     * Seluruhnya naskah implementasi yang menjelaskan sekolah, dan **tanpa satu
     * angka pun**: jumlah siswa, jumlah alumni, dan angka sejenis dari materi
     * publik lama tidak diulang di sini karena belum ada sumber terkini yang
     * mengesahkannya (butir 468).
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'hero_heading' => 'Sekolah yang menumbuhkan, bukan sekadar mengajar',
        'hero_description' => 'Smart Sukses School adalah ekosistem pendidikan berbasis beasiswa yang '
            .'memadukan pembelajaran akademik dengan pembangunan karakter, life skills, dan kepemimpinan '
            .'— agar setiap anak tumbuh siap menghadapi masa depannya.',

        'about_heading' => 'Tentang Smart Sukses School',
        'about_body' => 'Smart Sukses School adalah program pendidikan di bawah ekosistem Zakat Sukses. '
            .'Kami membuka akses pendidikan bagi anak-anak yang berpotensi namun terkendala biaya, lalu '
            .'mendampingi mereka tidak hanya di ruang kelas. Pembelajaran akademik berjalan berdampingan '
            .'dengan character building, life skills, komunikasi, kepemimpinan, dan kemandirian, karena '
            .'nilai rapor saja tidak cukup untuk menyiapkan seseorang menghadapi hidupnya.',

        'activity_heading' => 'Kehidupan di Smart Sukses School',
        'activity_description' => 'Belajar di sini tidak berhenti di meja kelas. Kegiatan harian, '
            .'pembinaan karakter, dan pengembangan keterampilan berjalan sepanjang tahun.',

        'ppdb_heading' => 'Penerimaan Peserta Didik Baru',
        'ppdb_description' => 'Pendaftaran dibuka untuk jenjang SD (Smart Bee) dan SMA (Smart Building). '
            .'Isi formulir pendaftaran untuk memulai prosesnya.',

        'article_heading' => 'Kabar & Artikel',
        'article_description' => 'Cerita kegiatan, kabar sekolah, dan informasi terbaru.',
    ];

    /**
     * Alamat pendaftaran PPDB.
     *
     * Bawaannya halaman PPDB Laravel yang memang sudah berjalan, bukan Google
     * Form: menuliskan alamat formulir milik sekolah sebagai bawaan di dalam
     * kode berarti mengarang alamat yang belum tentu berlaku. Pemilik
     * menempelkan alamat Google Form yang sedang dipakai lewat panel admin, dan
     * fungsi PPDB Laravel yang sudah ada tetap utuh di belakangnya (butir 471).
     */
    public function ppdbUrl(): string
    {
        return SiteSetting::get('ppdb_url') ?? route('ppdb.schools');
    }

    /** Apakah CTA PPDB menunjuk ke luar aplikasi (Google Form). */
    public function ppdbIsExternal(): bool
    {
        return SiteSetting::get('ppdb_url') !== null;
    }

    /**
     * Alamat blog WordPress.
     *
     * NULL berarti belum disetel, dan bagian artikel menyembunyikan tautannya
     * alih-alih mengirim pengunjung ke alamat karangan.
     */
    public function blogUrl(): ?string
    {
        return SiteSetting::get('blog_url');
    }

    public function logoUrl(): string
    {
        $path = SiteSetting::get('logo_path');

        if ($path !== null) {
            return Storage::disk(SiteSetting::MEDIA_DISK)->url($path);
        }

        return asset(self::DEFAULT_LOGO);
    }

    public function heroImageUrl(): ?string
    {
        $path = SiteSetting::get('hero_image_path');

        return $path === null
            ? null
            : Storage::disk(SiteSetting::MEDIA_DISK)->url($path);
    }

    /**
     * Teks satu bagian: suntingan pemilik bila ada, bawaan bila belum.
     *
     * Keduanya diperlakukan berbeda soal bahasa, dan bedanya disengaja. Teks
     * bawaan adalah naskah yang dikirim bersama aplikasi — naskah antarmuka —
     * sehingga ia diterjemahkan seperti label lain. Teks yang ditulis pemilik
     * adalah isi, dan isi ditampilkan persis sebagaimana ia diketik: tidak ada
     * kamus yang tahu terjemahan kalimat yang baru saja diketik seseorang, dan
     * menerjemahkannya berarti mengubah tulisan sekolah tanpa sepengetahuannya
     * (butir 479).
     */
    public function text(string $key): string
    {
        $stored = SiteSetting::get($key);

        if ($stored !== null) {
            return $stored;
        }

        return __(self::DEFAULTS[$key] ?? '');
    }

    /** Data kontak; NULL bila belum diisi, sehingga barisnya tidak dirender kosong. */
    public function contact(string $key): ?string
    {
        return SiteSetting::get('contact_'.$key);
    }

    public function social(string $key): ?string
    {
        return SiteSetting::get('social_'.$key);
    }

    /**
     * Seluruh blok terbit, dikelompokkan menurut jenis.
     *
     * @var Collection<string, Collection<int, SiteBlock>>|null
     */
    protected ?Collection $blocks = null;

    /**
     * Blok terbit untuk satu bagian halaman.
     *
     * Satu query untuk keempat jenis sekaligus, lalu dikelompokkan di PHP.
     * Query per jenis akan membuat halaman muka membayar empat query untuk
     * mengambil belasan baris dari satu tabel yang sama — dan menambah jenis
     * kelima berarti menambah query kelima, pada halaman yang justru paling
     * sering dibuka orang asing (butir 478).
     *
     * @return Collection<int, SiteBlock>
     */
    public function blocks(SiteBlockType $type): Collection
    {
        $this->blocks ??= SiteBlock::query()
            ->published()
            ->ordered()
            ->get()
            ->groupBy(fn (SiteBlock $block): string => $block->type->value);

        return $this->blocks->get($type->value) ?? collect();
    }
}
