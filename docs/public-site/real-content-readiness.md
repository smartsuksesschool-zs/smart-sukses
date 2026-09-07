# Kesiapan Isi Publik & Data Sekolah Sungguhan (M8)

Dokumen operasional. Ia mencatat **apa yang sudah terisi, apa yang menunggu
orang, dan apa yang sengaja dibiarkan kosong** — bukan cara kerja kodenya.
Alasan teknis per keputusan ada di
[`../implementation-notes.md`](../implementation-notes.md) butir 559–568.

Requirement aslinya tetap di `smartsukses-docs/`, yang tidak disunting.

---

## 1. Aturan yang berlaku untuk seluruh isi halaman ini

| Aturan | Alasannya |
| --- | --- |
| **Tidak ada fakta sekolah yang dikarang** | Halaman muka adalah pernyataan resmi sekolah. Angka yang salah di sana lebih buruk daripada angka yang tidak ada. |
| **Tidak ada statistik tanpa sumber** | Jumlah siswa aktif, jumlah alumni, persentase kelulusan, akreditasi, penghargaan — tidak satu pun ditulis kecuali sekolah menyatakannya. |
| **Tidak ada foto stok** | Foto anak yang bukan siswa Smart Sukses School, terpasang di halaman resmi Smart Sukses School, adalah klaim yang keliru sekalipun hanya sementara. |
| **Kolom yang belum terverifikasi dibiarkan kosong** | Kosong menyembunyikan barisnya; terisi keliru menyesatkan pembacanya. |

---

## 2. Kontak yang sudah terverifikasi

Seluruhnya disimpan di `site_settings` dan disunting dari **Pengaturan Situs
Publik**. Tidak satu pun ditulis di dalam kode.

| Kolom | Keadaan | Nilai |
| --- | --- | --- |
| `contact_email` | **terverifikasi** | `smartsuksesschool@zakatsukses.org` |
| `social_instagram` | **terverifikasi** | tautan profil `smart_sukses_school` |
| `ppdb_url` | **terverifikasi** | Google Form PPDB yang sedang dipakai |
| `contact_address` | terisi sebelumnya | alamat Depok |
| `contact_phone` | terisi sebelumnya | belum diverifikasi ulang pada M8 — lihat §3 |
| `blog_url` | terisi sebelumnya | situs sekolah |
| `contact_maps_url` | **kosong** | menunggu tautan peta dari sekolah |
| `social_facebook` | **kosong** | tidak diketahui; ikonnya tidak dirender |
| `social_youtube` | **kosong** | tidak diketahui; ikonnya tidak dirender |

`contact_email` sebelumnya berisi alamat lain. Alamat resmi yang dinyatakan
sekolah menggantikannya.

---

## 3. Yang sengaja **tidak** diisi

* **Nomor WhatsApp** — belum dinyatakan sekolah. Kolom `contact_phone` yang ada
  sekarang berisi nomor yang masuk sebelum M8 dan belum diverifikasi ulang;
  ia dibiarkan apa adanya, tidak dihapus dan tidak dijadikan tautan WhatsApp.
* **TikTok, YouTube, Facebook** — belum dinyatakan. Kolomnya kosong, ikonnya
  tidak muncul.
* **Tautan peta** — belum diserahkan. Alamatnya **tidak** dirangkai sendiri
  menjadi pencarian peta; lihat butir 564.
* **Statistik apa pun** — lihat §1.

Menambahkannya nanti tidak memerlukan satu baris kode pun: tempelkan nilainya di
Pengaturan Situs Publik, dan barisnya muncul sendiri.

---

## 4. Merek dan arah isi

Hierarki merek **tidak berubah**:

| Nama | Artinya |
| --- | --- |
| **Smart Sukses School** | merek payung / program |
| **Smart Building** | unit SMA |
| **Smart Bee** | unit SD |

Tagline resmi, kata demi kata, sebagai konstanta di `PublicSite::TAGLINE` dan
bukan sebagai pengaturan yang dapat disunting:

> Belajar dengan Hati, Tumbuh dengan Aksi, Sukses untuk Masa Depan.

Tema yang sudah disetujui dan boleh dipakai: SMA Terbuka, akses pendidikan,
beasiswa, TKB / pembelajaran berbasis kegiatan, life skills, character building,
soft skills, komunikasi, kepemimpinan, kemandirian.

Halaman muka tetap **sekolah lebih dulu**, bukan perangkat lunak lebih dulu:
pembacanya orang tua calon siswa, dan sistem informasinya turun menjadi satu
bagian "Akses Sistem" di dekat kaki halaman (butir 475).

---

## 5. Foto: siapa mengunggah apa

**Tidak ada berkas foto di dalam repositori ini, dan tidak akan ada.**

CMS-nya sudah siap sepenuhnya. Admin dapat mengunggah dan mengganti foto tanpa
satu baris kode pun berubah:

| Slot | Tempat mengunggah | Kolom |
| --- | --- | --- |
| Logo | Pengaturan Situs Publik | `logo_path` |
| Foto hero | Pengaturan Situs Publik | `hero_image_path` |
| Unit (Smart Building, Smart Bee) | Isi Situs Publik → blok jenis **unit** | `image_path` |
| Program | blok jenis **program** | `image_path` |
| Kegiatan / galeri | blok jenis **gallery** | `image_path` |

Ketentuan unggahan: JPEG, PNG, atau WebP, maksimum 4 MB, disimpan di disk
`public` direktori `site/`. Berkas lama terhapus sendiri ketika sebuah gambar
diganti, dan ikut terhapus ketika bloknya dihapus.

Slot yang belum berisi menampilkan bingkai kosong berukuran tetap — tata
letaknya tidak bergeser, dan tidak ada kalimat yang menjanjikan foto akan
menyusul (butir 565).

### Pemetaan foto yang disarankan

Sekolah sudah menyerahkan koleksinya. Saran penempatan, memakai **hanya** foto
yang disetujui untuk publikasi:

| Slot | Foto yang disarankan |
| --- | --- |
| **Hero** | drone/landmark Smart Building, sudut lebar |
| **Unit — Smart Building** | drone/landmark sudut kedua, berbeda dari hero |
| **Program / Kegiatan** | foto kegiatan dengan siswa yang terlihat **sedang ikut serta**; untuk MBG pilih yang konteks kegiatannya terlihat, bukan sekadar close-up makanan |
| **Galeri** | campuran: Pramuka, Clean Up, Ramadan, Pentas Akhir Tahun, pelatihan, MBG, dan landmark sekolah |

Galeri sebaiknya **tidak** diisi satu kategori kegiatan saja: enam foto Pramuka
menggambarkan satu hari, bukan satu tahun.

---

## 6. PPDB

Tidak berubah dari M7.2. Satu tujuan pendaftaran publik: `site_settings.ppdb_url`.

* Seluruh CTA pendaftaran — navigasi, hero, bagian PPDB, footer, dan tautan di
  halaman masuk — menunjuk alamat itu.
* `/ppdb` dan `/ppdb/{kode}` mengalihkan ke sana (302).
* `/ppdb/cek-status` tetap internal: ia memeriksa pendaftar yang sudah ada di
  basis data ini.
* **Klaim akun Google bukan PPDB.** Calon siswa mendaftar lewat formulir; orang
  yang sudah tercatat mengklaim akunnya lewat `/login`.

Tidak ada formulir penerimaan kedua yang dibuat.

---

## 7. Cabang Bandung

Tetap **nonaktif** (`schools.is_active = false`). Arsitektur multi-cabang utuh:
barisnya ada, datanya ada, `school_id` ada di seluruh model bisnis, dan tidak
ada satu pun logika yang menyebut nama cabang.

Isi publik tidak mengundang pendaftaran ke sana: ia tidak muncul di daftar
cabang PPDB, halaman pendaftarannya menjawab 404, dan ia tidak ditawarkan pada
permintaan akun staf.

Mengaktifkannya kembali adalah satu tombol di Manajemen Cabang.

---

## 8. Rekonsiliasi data siswa: 39 resmi, 1 dikecualikan

Keputusan operasional yang dinyatakan sekolah:

| | Jumlah |
| --- | --- |
| Baris di berkas sumber | 40 |
| Siswa terdaftar resmi | **39** |
| Baris yang **bukan** siswa terdaftar | **1** |
| Siswa resmi yang tertunda karena NIS belum terbit | **0** |

Baris yang satu itu tidak punya NIS dan memang tidak pernah terdaftar — ia ikut
kegiatan secara informal. Ia **tidak** diimpor ke data induk siswa, dan **tidak
ada NIS yang dikarang untuknya**.

### Cara menyatakannya

```bash
php artisan migrasi:dry-run <berkas.xlsx> --school=PUSAT --kecualikan-baris="Kelas 12:15"
```

Bentuknya **nama lembar + nomor baris**, bukan nama orang — keduanya bukan data
pribadi (butir 560). Bendera yang sama berlaku pada `migrasi:terapkan-uji` dan
`migrasi:terapkan-produksi`, dan beberapa baris dipisah koma.

> **Sebutkan lembarnya.** Berkas sekolah memakai satu lembar per tingkat, dan
> nomor baris dihitung ulang dari satu di setiap lembar — "baris 15" ada di
> Kelas 10, Kelas 11, dan Kelas 12. Bentuk singkat `--kecualikan-baris=15`
> tetap diterima, tetapi **hanya** bila ia menemukan tepat satu baris yang
> layak; bila cocok di lebih dari satu lembar ia ditolak sebagai *ambigu* dan
> tidak diterapkan ke satu pun (butir 566).

Nomor baris yang dipakai adalah nomor baris **di dalam lembarnya**, sama seperti
yang terlihat di Excel.

Hasilnya menjadi keadaan `EXCLUDED_NOT_REGISTERED`, ember keempat pada
rekonsiliasi:

```
baris sumber = siap + tertunda + ditolak + dikecualikan
```

### Pagar yang berlaku

* Pengecualian **hanya** dapat memindahkan baris yang tanpa NIS. Baris ber-NIS
  tidak pernah dapat dikecualikan, sehingga daftar ini tidak dapat menjadi cara
  menghapus siswa resmi dari impor (butir 561).
* Baris tanpa nama tetap **ditolak**, bukan dikecualikan.
* Nomor yang diminta tetapi tidak berlaku dilaporkan sebagai peringatan, tidak
  didiamkan.
* Penunjuk yang **ambigu** ditolak, bukan diterapkan ke seluruh lembar
  (butir 566).
* Penunjuk yang menunjuk baris yang justru **ditolak** karena data induknya
  tidak lengkap ikut dilaporkan sebagai tidak berlaku — ia tidak hilang diam-diam
  (butir 568).
* Daftar pengecualian **ikut menentukan sidik jari impor**, termasuk **baris
  mana** yang dikecualikan: rencana yang ditinjau dengan mengecualikan
  `Kelas 10:3` tidak dapat diterapkan dengan `Kelas 11:3` (butir 562, 567).

**Berkas sumber dan identitas siswa tidak pernah masuk repositori ini.** Seluruh
test memakai baris karangan.

---

## 9. Akun guru dan staf

**Tidak ada akun guru yang dibuatkan lebih dulu, dan daftar guru sungguhan tidak
masuk fixture maupun test.**

Alurnya sejak M7.1:

1. Guru menekan **Masuk dengan Google** di `/login`.
2. Ia memilih **"Saya Staf Sekolah"** dan cabangnya.
3. Permintaannya menunggu di **Manajemen Akses → Permintaan Akun**.
4. Admin menyetujui dan memilih perannya: `GURU`, `WALI_KELAS`, `BENDAHARA`,
   atau `KEPALA_SEKOLAH`.

Menyetujui sebagai `GURU` **sudah** membuat identitas gurunya — tidak ada tabel
guru terpisah di aplikasi ini; guru adalah `users` berperan tersebut. Akun itu
langsung muncul di kedua pemilih pada layar admin.

Penugasan tetap milik layar yang sudah ada:

| Penugasan | Layarnya |
| --- | --- |
| Mata pelajaran yang diampu | Kelas → relasi Mata Pelajaran |
| Kelas yang diajar | layar yang sama |
| Kelas perwalian | Kelas → field Wali Kelas |
| Jadwal | Jadwal |

Daftar guru sungguhan adalah data operasional yang dimasukkan admin, bukan isi
repositori.

---

## 10. Yang masih menunggu orang, bukan kode

| Menunggu | Dari siapa |
| --- | --- |
| Berkas foto asli, diunggah lewat CMS | sekolah / admin |
| Tautan peta lokasi | sekolah |
| Verifikasi nomor telepon/WhatsApp resmi | sekolah |
| Akun media sosial selain Instagram, bila ada | sekolah |
| Nomor baris yang dikecualikan pada berkas sumber | TU |
| Kredensial Google Cloud untuk staging | pemilik |

Tidak satu pun di antaranya memerlukan perubahan kode.
