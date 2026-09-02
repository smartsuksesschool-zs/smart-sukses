# Public Landing V2 — Situs Publik & Isi yang Dapat Disunting

Catatan implementasi untuk batch Public Landing V2, hasil umpan balik langsung
pemilik setelah simulasi bersama Pak Akbar.

Dokumen ini mencatat keputusan dan alasannya. Naskah pemasaran, kesepakatan
komersial, dan dokumen sumber sekolah tidak ada di sini.

---

## 1. Keputusan pemilik: publik dulu, sistem kemudian

Sampai V1, `/` menjelaskan **perangkat lunaknya** — delapan kartu fitur, daftar
modul, tiruan dasbor di hero, dan bagian "Akses Pengguna" tepat di bawah hero.
Susunan itu masuk akal selama pembacanya calon pengguna sistem.

Umpan balik pemilik menyatakan sebaliknya: pembaca `/` adalah **orang tua calon
siswa**, dan yang harus ia pahami dalam sepuluh detik pertama adalah sekolahnya.

V2 karena itu bukan penataan ulang gaya melainkan perubahan maksud halaman:

| | V1 | V2 |
| --- | --- | --- |
| Judul `<h1>` | "Belajar, mengelola, dan terhubung dalam satu sistem sekolah" | Judul sekolah yang dapat disunting pemilik |
| Kolom kanan hero | Tiruan antarmuka dasbor | Foto sekolah |
| Bagian pertama setelah hero | Akses Pengguna | Tentang Smart Sukses School |
| Daftar fitur perangkat lunak | Delapan kartu | Tidak ada |
| Daftar cabang | Ada | Tidak ada |
| Akses sistem | Tepat di bawah hero | Menjelang kaki halaman |

Sistem informasi tidak hilang — ia turun menjadi satu bagian "Akses Sistem".
Autentikasi sama sekali tidak disentuh.

---

## 2. Hierarki merek

```
SMART SUKSES SCHOOL
│  "Belajar dengan Hati, Tumbuh dengan Aksi, Sukses untuk Masa Depan."
│
├── Smart Bee       — unit SD
└── Smart Building  — unit SMA
```

Dua penegasan pemilik yang dijaga test:

1. **Smart Building bukan mitra.** Ia unit SMA *di bawah* Smart Sukses School.
   Materi lama sempat menampilkannya sejajar, dan halaman muka baru harus
   menutup salah paham itu.
2. **Tagline dipakai kata demi kata.** Ia konstanta
   `PublicSite::TAGLINE`, bukan baris basis data — satu salah ketik di panel
   admin tidak boleh cukup untuk mengubah tagline sekolah.

Zakat Sukses tampil hanya sebagai bagian berkas merek gabungan yang diserahkan
pemilik. Tidak ada klaim legal atau organisasi yang ditambahkan di luar itu.

---

## 3. Susunan halaman

`Navbar → Hero → Tentang → Unit Pendidikan → Program → Kegiatan → Kabar &
Artikel → PPDB → Akses Sistem → Kontak → Footer`

Navbar: Beranda · Tentang · Unit Pendidikan · Program · Kegiatan · Artikel ·
PPDB · Masuk.

Urutannya diuji, bukan sekadar disepakati — `test_the_school_story_comes_before_the_system_access`.

---

## 4. Arsitektur isi (CMS seminimal mungkin)

Dua tabel. Bukan satu tabel per field, dan bukan WordPress kedua.

### `site_settings` — nilai tunggal

`key` (unik) · `value` (text) · timestamps.

Belasan field halaman muka seluruhnya tunggal. Memberi masing-masing kolom
sendiri berarti migration baru setiap kali pemilik menambah satu baris teks.
Kunci yang sah ditentukan `PengaturanSitusPublik::KEYS` di kode — kunci di luar
daftar itu tidak pernah tersimpan, sehingga tabel tidak dapat tumbuh menjadi
tempat penyimpanan sembarang nilai.

Dibaca sekali per request dan di-cache (`Cache::rememberForever`), dibuang dari
event model sehingga setiap jalur tulis melewatinya.

### `site_blocks` — isi berulang

`type` · `title` · `subtitle` · `body` · `image_path` · `link_url` ·
`position` · `is_published` · timestamps.

`type` ∈ `unit` | `program` | `gallery` | `article` (`App\Enums\SiteBlockType`).

Keempatnya berbagi satu tabel karena bentuknya memang sama; memisahnya berarti
menyalin skema identik empat kali beserta empat resource Filament yang identik.
Enum aplikasi, bukan ENUM MySQL — jenis kelima tidak boleh menuntut ALTER TABLE
pada tabel yang sedang dibaca publik.

### Bawaan sebagai konstanta

Teks bawaan hidup di `PublicSite::DEFAULTS`, bukan sebagai baris basis data.
Akibatnya instalasi baru sudah merender halaman utuh sebelum seeder mana pun
dijalankan, dan admin yang mengosongkan satu field tidak meninggalkan lubang.

### Seeder isi publik: eksplisit, tidak otomatis

`PublicSiteSeeder` **tidak** didaftarkan di `DatabaseSeeder`.

Isinya aman — teks dan struktur, tanpa akun dan tanpa data pribadi — tetapi ia
*menerbitkan* ke halaman yang dilihat publik, termasuk enam baris galeri yang
belum berfoto. `php artisan db:seed` dijalankan sebagai bagian deployment, dan
deployment tidak boleh menerbitkan apa pun ke situs sekolah tanpa ada yang
memutuskan begitu.

| | |
| --- | --- |
| Produksi `db:seed` | peran, cabang, pengguna — **tidak ada isi situs publik** |
| Isi awal | lewat panel admin, atau `php artisan db:seed --class=PublicSiteSeeder` |
| Rendering tanpa seeder | tetap utuh: teks dari `PublicSite::DEFAULTS`, bagian kosong tidak dirender |

Seeder peran/izin (`RolePermissionSeeder`) tidak disentuh dan tetap otomatis.

Menjalankan ulang seeder tidak menimpa suntingan pemilik — sudah terbukti
terhadap basis data pengembangan yang berisi alamat PPDB, alamat blog, dan
kontak sungguhan.

### Bagian kosong tidak dirender

Karena seeder tidak berjalan otomatis, "belum ada isi" adalah keadaan awal
produksi yang normal. Bagian Unit Pendidikan, Program, Kegiatan, dan Artikel
hanya dirender bila isinya ada — judul bagian di atas ruang kosong terbaca
sebagai kerusakan, bukan sebagai halaman yang belum lengkap.

Menu utama dan daftar "Jelajahi" di kaki halaman menyusut bersamanya, sehingga
tidak pernah ada tautan menuju jangkar yang tidak dirender.

### Antarmuka admin

| Yang disunting | Tempat |
| --- | --- |
| Hero, tentang, kegiatan, PPDB, artikel, logo, kontak, media sosial, alamat PPDB & blog | **Situs Publik → Pengaturan Halaman Muka** |
| Unit pendidikan, program, galeri, pratinjau artikel (termasuk urutan, keterbitan, gambar) | **Situs Publik → Isi Halaman Muka** |

---

## 5. Global, bukan per cabang

**Keputusan: isi halaman muka bersifat global.**

Permintaan pemilik adalah situs publik utama Smart Sukses School. Halaman `/`
dibaca tamu yang belum login, dan tamu tidak punya `school_id`.

Karena itu `site_settings` dan `site_blocks` **tidak punya kolom `school_id`
sama sekali** — bukan kolom nullable yang kebetulan kosong. Kolom nullable
adalah undangan untuk diisi nanti, dan sekali terisi halaman publik berubah
menjadi milik satu cabang tanpa ada yang memutuskan begitu.

`SchoolScope` tidak dilemahkan di mana pun. Kedua model ini hanya tidak pernah
memakainya, dan test memastikan halaman muka tidak menyentuh tabel `schools`
sama sekali.

Konsekuensi izin: modul baru `public_content`, hanya untuk **Super Admin**.
Admin Sekolah tetap berhak penuh atas white-label cabangnya sendiri
(`white_label.*`) — batas ini tidak mengambil apa pun yang sudah dimilikinya —
tetapi tidak dapat menyunting situs payung.

`SiteBlockPolicy` sengaja tanpa `sharesTenant()`: recordnya tidak punya
`school_id`, dan pemeriksaan itu akan menolak semua orang termasuk yang berhak.
Penjagaannya berpindah ke kepemilikan izin, bukan hilang.

---

## 6. Penyimpanan media

**Bytes di storage, path di basis data.** Kolom `image_path` memuat kunci
penyimpanan dan tidak lebih.

| | |
| --- | --- |
| Disk | `public` (`SiteSetting::MEDIA_DISK`) |
| Direktori | `site/` (`SiteSetting::MEDIA_DIRECTORY`) |
| Format | JPG, PNG, WEBP |
| Ukuran | maksimal 4 MB |
| Nama berkas | ditamai ulang ULID oleh Filament; `preserveFilenames(false)` ditulis eksplisit dan dijaga test |

WEBP diizinkan di sini meski logo cabang hanya JPG/PNG. Alasannya bukan
pelonggaran asal: ini halaman publik yang memuat banyak foto sekaligus, WEBP
memangkas ukurannya secara berarti, dan berkas merek yang diserahkan pemilik
sendiri berformat WEBP. **SVG tetap tidak diizinkan** — SVG dapat memuat skrip.

Daur hidup berkas:

- gambar diganti → berkas lama dihapus;
- baris dihapus → berkasnya ikut;
- penghapusan **hanya** berlaku di dalam `site/`. Nilai seperti `../../.env`
  atau `schools/logos/logo.png` ditolak, bukan dituruti.

**Berkas privat tidak disentuh batch ini.** Dokumen PPDB, dokumen identitas,
bukti bayar, dan berkas impor tetap di disk privat dengan perlindungan yang
sudah ada.

---

## 7. Kebijakan foto sementara

Foto kegiatan Smart Sukses School yang sungguhan **belum diserahkan**.

Yang **tidak** dilakukan: mengunduh foto sekolah atau siswa lain untuk mengisi
sementara. Foto anak yang bukan siswa Smart Sukses School, terpasang di halaman
resmi Smart Sukses School, adalah klaim yang keliru sekalipun hanya dimaksudkan
sebagai contoh.

Yang dilakukan: komponen `<x-site-photo>` merender penanda bergaris berlabel
"Foto menyusul" ketika `image_path` kosong. Rasio bidangnya dikunci
(`aspect-ratio`), sehingga bidang fotonya sudah utuh sekarang dan tata letak
tidak bergeser saat foto aslinya menyusul.

Seeder membuat **enam baris galeri berjudul tanpa berkas gambar**. Ketika foto
tiba, pemilik mengunggahnya ke baris yang sudah ada dari panel admin — tanpa
satu baris kode pun berubah.

---

## 8. Logo

Berkas merek gabungan Smart Sukses School × Zakat Sukses yang diserahkan pemilik
disimpan di `public/images/brand/smart-sukses-school-zakat-sukses.webp`
(500×200 WEBP).

Ia **bawaan, bukan patrian**: halaman membaca `site_settings.logo_path` lebih
dulu dan hanya jatuh ke berkas bawaan bila pemilik belum mengunggah miliknya
sendiri. Tidak ada merek lain yang disubstitusikan diam-diam.

Audit aset sebelum batch ini: repositori tidak memuat satu pun berkas gambar,
dan navbar V1 memakai ikon garis sebagai pengganti logo.

---

## 9. Batas WordPress & topologi domain

Target yang disepakati pemilik, **belum dikerjakan batch ini**:

| Host | Isi |
| --- | --- |
| `smartsukses.sch.id` | situs publik Laravel + sistem informasi sekolah |
| `blog.smartsukses.sch.id` | WordPress yang sudah ada — artikel, kabar, lowongan |
| `staging.smartsukses.sch.id` | UAT guru/siswa sebelum go-live |

DNS dipegang Pak Akbar. **Tidak ada perubahan DNS, tidak ada migrasi WordPress,
tidak ada deployment** di batch ini.

Alamat blog adalah pengaturan (`blog_url`). Bila kosong, bagian artikel
menyembunyikan tautannya alih-alih mengirim pengunjung ke alamat karangan. Test
memastikan tidak ada satu nama host pun yang dipatri di template — perpindahan
domain harus menjadi satu suntingan field, bukan pekerjaan menyisir berkas
Blade.

---

## 10. PPDB

CTA utama halaman muka memakai `ppdb_url` bila disetel, dan **jatuh ke halaman
PPDB Laravel yang memang sudah berjalan** bila belum.

Bawaannya sengaja bukan alamat Google Form: menuliskan alamat formulir milik
sekolah sebagai bawaan di dalam kode berarti mengarang alamat yang belum tentu
berlaku. Pemilik menempelkan alamat formulir yang sedang dipakai dari panel
admin.

Fungsi PPDB Laravel yang sudah ada **tidak dihapus dan tidak dilemahkan**.
Keduanya berdampingan untuk sekarang. Tautan "Cek Status Pendaftaran" tetap
menunjuk rute aplikasi.

---

## 11. Dwibahasa

Arsitektur ID/EN yang ada dipertahankan: kunci JSON berbahasa Indonesia,
`lang/id.json` dan `lang/en.json`, bawaan Indonesia. Batch ini menambah 78 kunci
ke masing-masing.

Pembagiannya:

- **Naskah antarmuka** — label menu, judul bagian, teks tombol, dan **teks
  bawaan** `PublicSite::DEFAULTS` — diterjemahkan.
- **Isi yang ditulis pemilik** ditampilkan persis sebagaimana diketik. Tidak ada
  kamus yang tahu terjemahan kalimat yang baru saja diketik seseorang, dan
  menerjemahkannya berarti mengubah tulisan sekolah tanpa sepengetahuannya.
- **Nama merek tidak diterjemahkan**: Smart Sukses School, Smart Bee, Smart
  Building, Zakat Sukses, dan tagline resmi tetap utuh di mode EN.

---

## 12. Responsif

Satu kolom lebih dulu; kolom jamak selalu di balik media query, tidak pernah
menjadi bawaan. Token, jarak, radius, dan bayangan memakai sistem yang sudah ada
di `layouts/landing.blade.php` — tidak ada skala kedua yang diperkenalkan.

Titik yang diperiksa: 360 · 390 · 412 · 768 · 1024 · lebar penuh.

Yang dihindari secara khusus:

- **Dinding kartu di ponsel** — galeri satu kolom di bawah 40rem; kartu pertama
  baru melebar pada ≥64rem sehingga bagian itu punya titik berat di layar besar.
- **Luberan mendatar** — bidang foto memakai `aspect-ratio` dan `object-fit`,
  gambar dibatasi `max-width:100%`.
- **UI lengket ganda** — hanya navbar yang `sticky`; tombol Masuk tetap di bar,
  di luar `<details>`, sehingga tidak ada aksi utama yang muncul dua kali.
- **Judul kelewat besar** — `clamp()` pada seluruh judul.

---

## 13. Sumber inspirasi visual

Arsip template yang diserahkan pemilik berisi 12 berkas HTML prototipe React
dari produk yang **sama sekali berbeda** (sebuah *personal development & career
OS*). Ia dipakai sebagai rujukan visual saja.

Yang diambil: irama bagian (`max-w` + jarak konsisten), label eyebrow huruf
kapital berspasi lebar, judul `clamp()`, dan pola kartu program.

Yang **tidak** diambil: naskah produknya, mereknya, nama modulnya, warnanya,
tumpukan tekniknya (Tailwind Play CDN + Babel in-browser), maupun satu baris
markup-nya. Tidak ada residu template di repositori — halaman ditulis ulang di
atas arsitektur Blade dan token warna yang sudah dipakai project ini.

---

## 14. Di luar cakupan batch ini

Tidak dikerjakan, dan sengaja tidak dicampur ke diff ini: perubahan DNS, migrasi
WordPress, deployment staging, migrasi data siswa sungguhan, migrasi Gemini, dan
pekerjaan template impor Excel.
