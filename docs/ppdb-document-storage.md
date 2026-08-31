# Penyimpanan Berkas PPDB

Batch M0.1.

Berkas pendukung PPDB memuat dokumen keluarga calon siswa: kartu keluarga, akta
kelahiran, dokumen identitas orang tua, rapor sekolah asal, surat keterangan
bantuan sosial, dan pas foto. Dokumen ini menyimpan bagaimana berkas itu
disimpan, siapa yang dapat mengunduhnya, dan bagaimana berkas lama dipindahkan.

---

## 1. Keadaan sebelumnya, dan mengapa ia berbahaya

Formulir publik menyimpan unggahan dengan:

```php
$document->store('ppdb/'.Str::lower($this->school->code), 'public');
```

Disk `public` berakar di `storage/app/public`, dan `php artisan storage:link`
menautkannya ke `public/storage`. Web server melayani direktori itu **langsung**,
tanpa melewati satu baris pun kode aplikasi — tanpa sesi, tanpa policy, tanpa
pemeriksaan cabang.

Yang memisahkan berkas itu dari publik hanyalah nama acak di jalurnya. Itu
bukan kendali akses; itu ketidaktahuan pihak lain, dan ia bocor lewat mana pun
jalur itu pernah tertulis — log, cadangan, riwayat peramban, tangkapan layar,
tiket.

Yang membuatnya lebih runcing lagi: panel **tidak menyediakan** cara membuka
berkasnya sama sekali. Entri "Dokumen" hanya mencetak `basename()` sebagai teks.
Jadi satu-satunya cara mengambil berkas adalah lewat URL publik — jalur yang
persis tidak berwenang.

Dua hal itu bersama-sama menjadi alasan batch ini: bukan hanya menutup pintu
yang salah, tetapi juga membuka pintu yang benar.

**Keputusan M-1 (`docs/data-migration-readiness.md` §11) karena itu terjawab
secara teknis:** berkas PPDB adalah data aplikasi yang privat dan tidak boleh
dipaparkan sebagai URL penyimpanan publik.

---

## 2. Penyimpanan sekarang

| | Sebelum | Sesudah |
| --- | --- | --- |
| Disk | `public` (`storage/app/public`) | **`local`** (`storage/app/private`) |
| Dilayani web server | Ya, lewat `storage:link` | **Tidak pernah** |
| Direktori | `ppdb/{kode-cabang}/` | `ppdb/{kode-cabang}/` — **tidak berubah** |
| Bentuk nilai di basis data | jalur relatif | jalur relatif — **tidak berubah** |
| Validasi | JPG/PNG/PDF, maks 2 MB, maks 5 berkas | **sama persis** |

Disknya sama dengan yang sudah dipakai bukti pembayaran (SPP-03,
`PaymentRecorder::PROOF_DISK`) dan bukti transaksi kas (KAS-01,
`TransactionRecorder::PROOF_DISK`). Tidak ada disk baru, tidak ada penyimpanan
awan, dan tidak ada ketergantungan pada `storage:link`.

`storage:link` **tidak** dimatikan: logo cabang, foto siswa, dan avatar pengguna
tetap memakai disk `public`, dan memang seharusnya begitu.

Semua ini terpusat di `app/Support/PpdbDocument.php` — disk, direktori, pagar
jalur, pemetaan kunci, dan nama unduhan. Satu tempat, karena dua salinan sebuah
pagar keamanan cepat atau lambat akan berbeda.

---

## 3. Alur unduhan berwenang

```
GET /admin/ppdb/{registration}/dokumen/{documentKey}
     ↳ nama rute: filament.admin.ppdb.document
```

Rutenya didaftarkan lewat `authenticatedRoutes()` milik panel di
`AdminPanelProvider`, bukan di `routes/web.php`. Rute panel tidak melewati grup
`web`, jadi mendaftarkannya di luar sana berarti merakit tumpukan autentikasi
kedua untuk satu rute. Lewat panel, ia mewarisi apa adanya: sesi, CSRF,
`Authenticate`, `SetUserLocale`, `EnsurePasswordIsChanged`, dan pencatatan
alamat IP audit.

Yang dilewati satu permintaan, berurutan:

| # | Lapisan | Yang terjadi bila gagal |
| --- | --- | --- |
| 1 | `Authenticate` panel | Tamu dialihkan ke halaman masuk panel |
| 2 | `EnsurePasswordIsChanged` | Pengguna berkata sandi bawaan dialihkan ke penggantian |
| 3 | Route model binding + `SchoolScope` | Pendaftaran cabang lain **404** — keberadaannya tidak dibocorkan |
| 4 | `Gate::authorize('view', $registration)` | **403** bagi peran tanpa modul PPDB |
| 5 | `PpdbDocument::pathFor()` | Kunci di luar daftar → **404** |
| 6 | `PpdbDocument::sanitise()` | Jalur di luar `ppdb/` atau memuat `..` → **404** |
| 7 | `PpdbDocument::diskFor()` | Berkas tercatat tetapi hilang → **404**, bukan 500 |

`documentKey` adalah **indeks** di dalam `documents` milik pendaftaran itu
sendiri, dan rutenya membatasi kedua parameter ke angka (`whereNumber`). Jalur
berkas karena itu tidak pernah dapat sampai ke aplikasi lewat URL: ia dibaca
dari basis data, tidak dirakit dari request.

Nama berkas unduhannya dibentuk dari nomor pendaftaran —
`madani-2026-0001-berkas-1.pdf` — mengikuti pola
`TransactionResource::proofFilenameFor()`. Nama unggahan asli tidak pernah
disimpan, dan nama acak di penyimpanan tidak ikut keluar.

### Kewenangan

Tidak ada matriks peran baru. Unduhan menumpang `PpdbRegistrationPolicy::view`
apa adanya:

| Peran | Boleh mengunduh | Dari mana aturannya |
| --- | --- | --- |
| Super Admin | Ya, seluruh cabang | `Gate::before` (Arsitektur 3.2.2) |
| Admin Sekolah | Ya, cabangnya sendiri | `ppdb.view` + `sharesTenant()` |
| Kepala Sekolah | Ya, cabangnya sendiri | `ppdb.view` (⭕ baca saja) + `sharesTenant()` |
| Guru / Wali Kelas / Bendahara / Siswa / Orang Tua | **Tidak** | Tidak punya modul PPDB pada matriks PRD 1.1.2 |

Tidak ada tautan bertanda tangan publik yang dibuat. Sumbernya tidak pernah
menuntut akses dokumen tanpa login, dan menambahkannya berarti mengembalikan
persis masalah yang batch ini tutup.

---

## 4. Kompatibilitas berkas lama

Kolom `documents` menyimpan **jalur relatif saja** — tanpa URL, tanpa keterangan
disk, tanpa metadata. Itulah yang membuat pengerasan ini tidak menuntut migrasi
basis data: berkas berpindah disk, nilai di basis data tetap sama.

`PpdbDocument::diskFor()` memeriksa disk privat lebih dulu, lalu disk publik.
Akibatnya baris lama tetap dapat diunduh lewat rute berwenang **sejak hari ini**,
bahkan sebelum berkasnya dipindahkan — pengerasan ini tidak mematahkan data yang
sudah ada. Ketika keduanya ada, yang dilayani salinan privatnya.

Yang **tidak** hilang tanpa pemindahan: salinan publik yang tertinggal tetap
dapat diunduh siapa pun yang mengetahui jalurnya. Rute berwenang menutup pintu
depan; pemindahan berkas menutup pintu belakang. Keduanya perlu.

---

## 5. Pemindahan berkas lama

```sh
# 1. Simulasi — bawaannya, dan tidak menulis apa pun.
php artisan ppdb:privatize-documents

# 2. Setelah laporan di atas diperiksa:
php artisan ppdb:privatize-documents --apply
```

### Yang dijamin

- Hanya jalur yang **benar-benar dirujuk** `ppdb_registrations.documents` yang
  disentuh. Perintah ini tidak pernah memindai direktori, sehingga berkas publik
  yang sah — logo cabang, foto siswa — tidak mungkin ikut terbawa.
- Jalur yang tidak lolos pagar (`..`, jalur absolut, di luar `ppdb/`) dilewati
  **tanpa disentuh sama sekali**: tidak dibaca, tidak disalin, tidak dihapus.
- **Salin dulu, buktikan, baru hapus.** Berkas publik hanya dihapus setelah
  salinan privatnya ada dan ukurannya cocok. Salinan yang gagal berarti berkas
  aslinya tetap utuh.
- Berkas privat berbeda di jalur yang sama **tidak pernah ditimpa** — dilaporkan
  sebagai dilewati, untuk dilihat manusia.
- Jalan yang terputus di tengah aman: berkas yang salinan privatnya sudah utuh
  hanya perlu salinan publiknya dibuang, bukan disalin ulang.
- Menjalankan ulang aman. Yang sudah privat dilaporkan "sudah privat" dan tidak
  disentuh lagi.
- **Berkas yang tercatat tetapi hilang dilaporkan HILANG**, bukan dianggap
  selesai. "Sudah dipindah" dan "hilang" bukan hal yang sama, dan menyamakannya
  menyembunyikan kehilangan data.
- Tidak ada satu baris basis data yang diubah.

### Laporan akhir

`found` · `migrated` · `already-private` · `missing` · `skipped` · `failed`.

Keluar dengan status gagal bila `failed` bukan nol.

### Catatan operasional

Perintah ini berjalan tanpa sesi, sehingga `SchoolScope` tidak memasang pagar
tenant apa pun (butir 407) — dan di sini itu memang yang dibutuhkan, karena
pemindahan berlaku untuk seluruh cabang. Lingkupnya ditulis eksplisit dengan
`withoutGlobalScopes()`, bukan diserahkan kepada kebetulan.

Jalankan **setelah** backup basis data dan berkas, dan sebaiknya saat tidak ada
pendaftaran masuk.

---

## 6. Pertimbangan rollback

Yang dapat dibalik dengan mudah, dan yang tidak:

| Perubahan | Cara membalik |
| --- | --- |
| Unggahan baru ke disk privat | Kembalikan `PpdbDocument::DISK` ke `public`. Berkas yang sudah privat tetap terlayani karena `diskFor()` memeriksa kedua disk |
| Rute unduhan | Menghapusnya mengembalikan keadaan lama: berkas tanpa cara membuka dari panel |
| **Pemindahan berkas** | **Tidak ada tombol balik.** Salinan publiknya sudah dihapus |

Karena itu urutannya penting: **backup lebih dulu, simulasi lebih dulu.**
Pemindahan adalah satu-satunya langkah yang menghapus sesuatu, dan ia sengaja
menuntut `--apply` yang diketik sadar.

Bila sesuatu terlihat salah setelah `--apply`, berkasnya dipulihkan dari backup
berkas — bukan dari basis data, yang memang tidak menyimpan isi berkas.

---

## 7. Kontrak untuk migrasi Google Drive nanti

Belum ada satu baris kode integrasi Google, dan memang tidak direkomendasikan
(`docs/data-migration-readiness.md` §9.1). Yang ditetapkan di sini hanya
kontraknya, supaya migrasi legacy nanti tidak menciptakan jalur penyimpanan
kedua:

```
berkas sumber (Drive, diunduh manusia ke folder privat di luar repositori)
  → divalidasi: JPG/PNG/PDF, maks 2 MB per berkas, maks 5 berkas per pendaftar
  → disimpan lewat PpdbDocument::directoryFor($school) pada PpdbDocument::DISK
  → jalurnya ditulis ke ppdb_registrations.documents (bentuk yang sama, jalur relatif)
  → diunduh lewat filament.admin.ppdb.document, dengan kewenangan yang sama
```

Ketentuan yang mengikat:

- Berkas sumber tidak pernah masuk repositori. Tempatnya `C:\migration-private\`
  atau setara, di luar direktori project.
- Aturan validasinya **tidak** dilonggarkan untuk data legacy. Berkas yang tidak
  memenuhi syarat dilaporkan, bukan diloloskan.
- Tidak ada berkas legacy sungguhan yang dipakai di test. Test memakai berkas
  palsu, selalu.
- Tautan Drive **tidak** disimpan di `documents`. Kolom itu berisi jalur
  penyimpanan, dan mencampurnya dengan URL luar akan mematahkan pagar jalur.
- Sampai migrasi berkas benar-benar dijalankan, `documents` dibiarkan kosong
  untuk baris hasil migrasi. Data pendaftarannya tetap masuk; berkasnya menyusul.

---

## 8. Yang **tidak** berubah pada batch ini

- Skema basis data. Tidak ada migrasi, tidak ada kolom baru, tidak ada
  penulisan ulang nilai `documents`.
- Aturan validasi unggahan — masih JPG/PNG/PDF, 2 MB, 5 berkas.
- Matriks peran PPDB.
- Alur enroll PPDB-05. Enroll tidak menyentuh `documents` sama sekali, dan tidak
  menghapus berkas apa pun.
- Perilaku hapus/ganti berkas — karena memang tidak ada. Pendaftaran hanya lahir
  dari formulir publik, panel tidak punya halaman ubah, dan
  `PpdbRegistrationPolicy::delete()` mengembalikan `false`. Tidak ada jalur yang
  dapat menimpa atau menghapus berkas, sehingga tidak ada kebocoran berkas yatim
  yang perlu ditutup di sini.
- Disk `public` untuk logo cabang, foto siswa, dan avatar pengguna.
- `storage:link`, yang tetap dibutuhkan modul-modul di atas.
