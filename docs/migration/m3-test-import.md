# M3 — Impor uji siswa

Status: **selesai untuk basis data uji.** Belum ada impor produksi, dan belum
boleh ada sebelum blokir di §10 dijawab pemilik/tata usaha.

Dokumen ini mencatat keputusan, bukan data. Tidak ada satu pun NIS, NISN, nama,
atau alamat siswa sungguhan di sini — hanya jumlah agregat. Berkas sumber tetap
berada **di luar repositori** dan tidak pernah disalin ke dalamnya.

---

## 1. Sumber

Berkas: `Data Siswa NIS NISN 2026 2027.xlsx` (privat, di luar repositori).

Bentuknya berbeda dari berkas M1/M2. Yang lama satu lembar `Data Siswa`
berseksi per angkatan; yang baru **satu lembar per tingkat** dengan satu baris
heading yang rapi:

| Lembar | Baris data |
| --- | --- |
| Kelas 12 | 14 |
| Kelas 11 | 13 |
| Kelas 10 | 13 |
| **Total** | **40** |

Kolomnya: `No`, `NIS`, `NISN`, `Nama`, `Jenis Kelamin`, `TKB`,
`Kelas di SMAN 11`.

`TKB` (tempat kegiatan belajar) tidak dipetakan ke kolom mana pun dan dilaporkan
sebagai kolom sumber yang diabaikan dengan aman. Ia tidak dibuang diam-diam:
namanya muncul di laporan analisis kering setiap kali dijalankan.

### Audit agregat berkas

| | |
| --- | --- |
| Siswa bermakna | 40 |
| NIS terisi / kosong | 39 / 1 |
| NISN terisi / kosong | 39 / 1 |
| Jenis kelamin terisi / kosong | 40 / 0 |
| Label kelas terisi / kosong | 39 / 1 |
| Duplikat NIS | 0 |
| Duplikat NISN setelah normalisasi | 0 |
| NISN tidak berbentuk angka | 0 |

Panjang NISN mentah: 25 baris 8 digit, 12 baris 9 digit, 2 baris 10 digit,
1 baris kosong.

Satu siswa yang sama-lah yang kosong NIS, NISN, dan kelasnya — ia ada di lembar
`Kelas 10`.

---

## 2. Kontrak normalisasi NISN

NISN diperlakukan sebagai **string identitas**, tidak pernah sebagai bilangan.
Kelasnya: `App\Support\Migration\NisnNormalizer`.

| Masukan | Hasil | Keadaan |
| --- | --- | --- |
| kosong, `-`, `null` | `NULL` | `NISN_BLANK` |
| 10 digit | disalin apa adanya | `NISN_VALID` |
| 1–9 digit | diberi nol di depan hingga 10 | `NISN_NORMALIZED` |
| mengandung bukan digit | ditolak | `NISN_INVALID` |
| lebih dari 10 digit | ditolak | `NISN_INVALID` |

Contoh (karangan): `88888888` → `0088888888`.

**Tidak pernah memotong.** NISN 11 digit bukan NISN 10 digit yang kelebihan satu
karakter; ia data yang salah, dan memotongnya menghasilkan identitas yang tampak
sah padahal milik orang lain.

Sebab masalahnya: Excel menyimpan kolom identitas sebagai bilangan lalu
mencetaknya tanpa nol pembuka. Keputusan pemilik/pengembang adalah memperbaiki
di sisi migrasi, bukan meminta tata usaha mengirim ulang seluruh berkas — setiap
penyalinan manual adalah kesempatan salah ketik baru pada data identitas.

Berkas sumber **tidak pernah diubah**; ada tesnya (ukuran dan md5 berkas
dibandingkan sebelum dan sesudah).

### Akibat yang perlu diketahui

Aturan "1–9 digit diberi nol di depan" berlaku juga pada NISN yang salah ketik
pendek: `123` menjadi `0000000123`, bukan galat. Itu konsekuensi langsung dari
aturan yang diputuskan pemilik. Yang menjaganya tetap terlihat adalah laporan:
`NISN_NORMALIZED` selalu dicetak dengan jumlahnya, sehingga berapa banyak nilai
yang berubah selalu diketahui sebelum menulis.

### Tabrakan setelah normalisasi

Normalisasi dapat mempertemukan dua nilai yang semula berbeda (`12345678` dan
`0012345678`). Baris keduanya ditahan sebagai `PENDING_DUPLICATE_NISN`, tidak
pernah digabung. Pada berkas 2026/2027: **0 tabrakan**.

---

## 3. Aturan NIS dan kontrak impor sebagian

NIS adalah identitas migrasi dan `students.nis` NOT NULL.

- NIS resmi ada → baris boleh diproses.
- NIS kosong atau `-` → `PENDING_MISSING_NIS`.

Baris tertunda **tidak** diimpor, **tidak** diberi identitas sementara, dan
**tidak** menghalangi baris lain. Ia tetap muncul di rekonsiliasi.

Alasan tidak memberi NIS sementara: identitas sementara akan menjadi identitas
tetap begitu ada nilai, tagihan, dan rapor yang menggantung padanya. Yang mahal
bukan membuatnya, melainkan menukarnya kemudian.

Analisis M2 menolak seluruh berkas begitu ada satu penghambat. Untuk 40 siswa
yang 39-nya siap, sikap itu memaksa memilih antara menunda semuanya atau
mengarang satu NIS. Sejak M3 setiap baris dinilai sendiri-sendiri.

### Hasil per baris

Ditentukan sepenuhnya di `App\Support\Migration\StudentImportPlan`, satu-satunya
tempat baris sumber dinilai. Analisis kering dan mode terap membaca rencana yang
sama, jadi yang diperagakan persis yang dikerjakan.

| Hasil | Arti |
| --- | --- |
| `READY_CREATE` | siap ditulis sebagai siswa baru |
| `READY_MATCH` | NIS-nya sudah ada di cabang ini; dicocokkan, bukan diduplikasi |
| `PENDING_MISSING_NIS` | menunggu NIS resmi terbit |
| `PENDING_INVALID_NISN` | NISN perlu diperiksa manusia |
| `PENDING_DUPLICATE_NIS` | NIS yang sama muncul dua kali di berkas |
| `PENDING_DUPLICATE_NISN` | NISN sama setelah normalisasi |
| `PPDB_RECONCILIATION_REQUIRED` | lihat §6 |
| `REJECTED_MASTER_INCOMPLETE` | nama atau jenis kelamin kosong |

Rekonsiliasi selalu tertutup dan diperiksa setiap kali:

```
baris sumber = siap + tertunda + ditolak
```

Hasil pada berkas 2026/2027: **40 = 39 + 1 + 0**.

---

## 4. Penempatan kelas

Dinilai **terpisah** dari data induk. Induk siswa yang sudah benar tidak ditahan
hanya karena rombelnya belum dibuat.

| Keadaan | Arti |
| --- | --- |
| `PLACEMENT_READY` | rombelnya ada; penempatan akan dibuat |
| `PLACEMENT_EXISTS` | siswa sudah punya penempatan aktif di tahun ajaran itu |
| `CLASS_NOT_FOUND` | rombelnya belum ada |
| `CLASS_LABEL_MISSING` | label kelas kosong di sumber |
| `ACADEMIC_YEAR_MISSING` | tahun ajaran tujuan belum ada |

Pencocokan ke `classes.name` **persis**, tanpa penyederhanaan. Importer tidak
pernah membuat rombel.

Label yang ditemukan di berkas:

| Label sumber | Label kanonis | Tingkat | Siswa |
| --- | --- | --- | --- |
| `X Terbuka - 2` | `X Terbuka - 2` | 10 | 12 |
| `XI Terbuka - 1` | `XI Terbuka - 1` | 11 | 13 |
| `XII Terbuka - I` | **`XII Terbuka - 1`** (koreksi) | 12 | 5 |
| `XII Terbuka - 2` | `XII Terbuka - 2` | 12 | 9 |
| `-` | — | — | 1 |

### Koreksi data terkonfirmasi

`XII Terbuka - I` di berkas sumber adalah **salah ketik**; nilai yang dimaksud
`XII Terbuka - 1`. Ini koreksi data yang sudah dikonfirmasi terhadap sumbernya,
bukan kesimpulan yang ditarik importer sendiri.

Koreksinya ditulis sebagai alias satu per satu di
`CanonicalRombel::ALIASES`, bukan sebagai aturan umum yang
mengubah angka Romawi di mana pun ia muncul. Aturan umum semacam itu akan
mengubah label yang belum pernah ditinjau siapa pun — termasuk label di berkas
yang belum ada. `XI Terbuka - I` dan `X Terbuka - II` karena itu **tidak**
ikut berubah, dan ada tesnya.

Pencocokan aliasnya adalah label penuh yang persis (tanpa memandang besar-kecil
huruf), bukan pola.

Laporan selalu menampilkan keduanya —
`label "XII Terbuka - I" → "XII Terbuka - 1" (koreksi data terkonfirmasi)` —
supaya yang membacanya tahu nilai mana yang diubah dan menjadi apa.

Empat label kanonis: `X Terbuka - 2`, `XI Terbuka - 1`, `XII Terbuka - 1`,
`XII Terbuka - 2`.

### Yang masih menunggu keputusan

Judul kolomnya `Kelas di SMAN 11` — label ini menyebut **kelas di sekolah
mitra**, bukan nama rombel Smart Sukses School. Apakah rombel di sistem harus
memakai nama yang sama belum diputuskan.

Tidak satu pun dari empat rombel itu ada di basis data. Seluruh 39 baris siap
berhenti di `CLASS_NOT_FOUND`, dan itu benar.

Koreksi salah ketik di atas **tidak** dengan sendirinya memberi izin membuat
rombel. Ia hanya menentukan label mana yang dicari, dan importer tetap tidak
pernah membuat kelas.

Penyiapannya kini ada sebagai perintah tersendiri — lihat
[`m4-rombel-readiness.md`](m4-rombel-readiness.md). Sesudah dijalankan,
`CLASS_NOT_FOUND` pada berkas 2026/2027 menjadi **0**.

---

## 5. Tahun ajaran

Tahun ajaran tujuan: **2026/2027**.

Semester tidak diasumsikan. Yang dipakai adalah tahun ajaran yang sudah ada dan
aktif di sistem — `2026/2027 Ganjil`, `semester = 1` — yang dimasukkan lewat
panel admin, bukan oleh seeder mana pun. Basis data uji disiapkan menyalin
persis itu.

Importer **tidak pernah membuat tahun ajaran**. Bila tidak ada, penempatan
dilaporkan `ACADEMIC_YEAR_MISSING` dan impor induk tetap jalan. Invarian satu
tahun ajaran aktif tidak disentuh.

---

## 6. Batas PPDB

`ppdb_registrations` **tidak menyimpan NIS maupun NISN**. Kolom identitasnya
hanya nama, jenis kelamin, tanggal lahir, dan data orang tua — sementara berkas
siswa tidak memuat tanggal lahir. Tidak ada satu pun kunci rekonsiliasi yang
aman.

Karena itu nama **tidak pernah dipakai untuk mencocokkan**. Yang dilakukan
justru kebalikannya: untuk baris tingkat X, nama yang sama dengan pendaftar PPDB
yang belum dikonversi **menahan** barisnya sebagai
`PPDB_RECONCILIATION_REQUIRED` agar diperiksa manusia. Nama hanya boleh
menimbulkan pertanyaan, tidak pernah menjawabnya.

Pendaftar yang sudah dikonversi (`converted_student_id` terisi) tidak menahan
apa pun: siswanya sudah ada dengan NIS, dan pencocokan NIS yang menanganinya.

Rekonstruksi riwayat PPDB berada di luar batch ini.

---

## 7. Pagar tulis

`App\Support\Migration\MigrationWriteGuard`. Menulis hanya terjadi bila ketiga
pagar terbuka bersamaan:

1. lingkungan bukan `production`;
2. nama basis data berpola basis data uji — berakhiran `_test`, bernama
   `testing`, atau `:memory:`;
3. `--konfirmasi` ditulis di baris perintah.

Pagar kedua yang paling menentukan. `APP_ENV=local` di mesin pengembang tetap
menunjuk `smartsukses`, basis data kerja yang berisi data pemilik sungguhan.
Selama fase ini satu-satunya sasaran yang sah adalah basis data yang namanya
sendiri menyatakan ia basis data uji.

Perintahnya bernama `migrasi:terapkan-uji`, bukan `migrasi:terapkan`. Perintah
yang namanya tidak menyebut batasnya cepat atau lambat akan dipakai di luar
batasnya.

Mode terap **tidak** dipasang sebagai opsi pada `migrasi:dry-run`: satu salah
ketik tidak boleh memisahkan "melihat" dari "menulis".

Tanpa `--konfirmasi` perintah mencetak rencananya lalu berhenti. Mode kering
adalah bawaan.

Terverifikasi langsung: dijalankan terhadap `smartsukses`, perintah menolak
dengan kode keluar 2 dan jumlah siswa di sana tidak berubah.

---

## 8. Perubahan yang boleh ditulis

Sempit dengan sengaja.

| Keadaan | Yang ditulis |
| --- | --- |
| baris baru | `school_id`, `nis`, `nisn`, `full_name`, `gender`, `status=ACTIVE` |
| baris cocok | **hanya** `nisn`, dan hanya bila kolomnya masih kosong |

Nama dan jenis kelamin siswa yang sudah ada tidak pernah ditimpa dari berkas.
Data yang sudah masuk sistem sudah melewati mata manusia; berkas lama bukan
sumber yang lebih benar, dan menimpanya akan menghapus perbaikan tata usaha
tanpa jejak.

NISN tersimpan yang berbeda dari NISN sumber dilaporkan sebagai konflik dan
dibiarkan apa adanya.

Setiap siswa ditulis di dalam satu transaksi bersama penempatan kelasnya,
sehingga tidak ada siswa yang separuh jadi.

**Idempotent.** Terverifikasi: jalan kedua atas berkas yang sama menghasilkan
0 dibuat / 39 dicocokkan, dan jumlah baris tidak bertambah.

---

## 9. Akun tidak ikut

Data induk siswa dan penyediaan akun adalah dua pekerjaan terpisah.

- Tidak ada `users` yang dibuat.
- Tidak ada alamat surel yang dikarang.
- `students.user_id` tetap null.
- Login terpadu tidak disentuh.

Laporan memisahkan `MASTER_IMPORTED`, `ACCOUNT_READY`, dan `ACCOUNT_PENDING`.
Setelah impor uji: 39 / 0 / 39. Data akun dari Google Form tetap aliran masukan
tersendiri.

---

## 10. Hasil impor uji dan blokir yang tersisa

Basis data `smartsukses_test`, dua kali jalan:

| | Jalan 1 | Jalan 2 |
| --- | --- | --- |
| siswa dibuat | 39 | 0 |
| siswa dicocokkan | 0 | 39 |
| penempatan kelas dibuat | 0 | 0 |
| baris dilewati | 1 | 1 |

Rekonsiliasi sesudahnya: 39 baris `students`, seluruhnya cabang PUSAT, 39 NIS
unik tanpa yang kosong, 39 NISN masing-masing tepat 10 karakter dan unik (37 di
antaranya kini berawalan nol), 0 `student_classes`, 0 `users`, 0 `user_id`
terisi.

### Blokir sebelum impor produksi

1. **NIS satu siswa belum terbit.** Ia tetap tertunda sampai sekolah
   menerbitkannya. Tidak ada jalan pintas.
2. ~~**Empat rombel tujuan belum ada.**~~ **Selesai di M4.** Keempat rombel kini
   disiapkan lewat `php artisan migrasi:siapkan-rombel`, dan pertanyaan tentang
   nama rombel sudah dijawab: label `Kelas di SMAN 11` dipakai apa adanya
   sebagai nama rombel di sistem. Lihat
   [`m4-rombel-readiness.md`](m4-rombel-readiness.md).
3. **Belum ada keputusan impor produksi.** Yang sudah dibuktikan hanya bahwa
   impor uji berjalan benar dan berulang. Pagar `_test` harus dilonggarkan
   secara sadar, bukan dengan menambah opsi.

---

## 11. Berkas contoh import Excel

Umpan balik pemilik: modal import menuntut admin menebak format berkasnya, dan
tebakan yang salah baru ketahuan setelah unggahan menghasilkan "0 siswa
diimport" tanpa keterangan.

`Admin → Data Siswa → Import Excel` kini menuntun berurutan:

1. **Langkah 1 — Unduh template** (tombol unduh, di atas kolom unggah)
2. **Langkah 2 — Berkas Excel (.xlsx)**

Berkasnya `template_import_siswa.xlsx`, dua lembar:

- **Data Siswa** — judul kolom saja, **tanpa baris contoh**. Baris contoh di
  lembar ini akan ikut terbaca sebagai data begitu berkasnya diunggah kembali,
  dan yang lahir adalah seorang siswa bernama contoh lengkap dengan NIS
  karangan. Kolom `nis` dan `nisn` diformat sebagai teks supaya nol pembuka
  tidak hilang lagi.
- **Petunjuk** — wajib/opsional per kolom, nilai yang diterima, format tanggal,
  aturan umum, dan satu baris contoh karangan. Importer tidak pernah membaca
  lembar ini.

Judul kolomnya **dibangkitkan dari** `StudentsImport::COLUMNS`, tidak disalin.
Berkas contoh yang disalin tangan akan menyimpang begitu importer berubah, dan
berkas contoh yang menyimpang lebih buruk daripada tidak ada.

Kewenangannya menumpang `StudentPolicy::import`: yang boleh mengunduh adalah
yang memang sudah boleh mengimpor. Rutenya lewat `authenticatedRoutes()` panel,
sehingga melewati tumpukan middleware yang sama dengan halaman panel lain.
Berkasnya dibangkitkan dari konstanta dan **tidak menyentuh basis data**.

### Cacat yang ditemukan uji manual, dan perbaikannya

Berkas contoh resmi yang diisi tanpa mengubah satu judul kolom pun **ditolak**
dengan "Judul kolom tidak dikenali — kolom wajib yang tidak ditemukan: nis,
nama_lengkap, jenis_kelamin".

Sebabnya: Maatwebsite memanggil `ToCollection::collection()` **sekali untuk
setiap lembar**, bukan sekali untuk setiap berkas. Pemeriksaan judul kolom
menyimpan kesimpulannya di satu properti, sehingga lembar kedua menimpa lembar
pertama:

| Panggilan | Lembar | Kunci heading | Hasil |
| --- | --- | --- | --- |
| #1 | `Data Siswa` | 13 kolom yang benar | cocok — barisnya diimpor |
| #2 | `Petunjuk` | `kolom, wajib, keterangan, 3…12` | tidak cocok — **menimpa** |

Akibatnya lebih buruk daripada penolakan keliru: barisnya **tetap masuk basis
data** sementara antarmuka melaporkan kegagalan. Uji manual pemilik memang
menghasilkan satu baris siswa yang tersimpan di basis data pengembangan
sekalipun layarnya menampilkan galat.

Perbaikannya: penilaian dilakukan **per lembar**, bukan per berkas.

- Setiap lembar dicatat sendiri: nama, jumlah baris, cocok/tidak, kolom hilang.
- Lembar yang judul kolomnya tidak cocok **dilewati**, bukan menggagalkan
  berkasnya. Lembar `Petunjuk` masuk ke sini dan tidak pernah menjadi siswa.
- Berkas ditolak hanya bila **tidak ada satu lembar pun** yang cocok.
- Bila tidak ada yang cocok, yang dinilai adalah lembar bernama `Data Siswa`
  bila ada, selebihnya lembar pertama. Ini yang membedakan "template diunggah
  tanpa diisi" (lembar datanya ada tetapi kosong → "tidak ada baris data") dari
  "judul kolomnya salah".

Nama lembar didapat dari event `BeforeSheet`; `collection()` sendiri tidak
menerimanya.

Nama lembar data adalah satu konstanta, `StudentsImport::SHEET`, dan berkas
contoh membacanya dari sana — kontrak berkas dimiliki importer, berkas contoh
yang mengikuti.

Normalisasi judul kolom sengaja sempit dan dipakai untuk **mendeteksi sekaligus
membaca** barisnya, sehingga keduanya tidak mungkin berbeda pendapat: buang BOM
UTF-8, rapikan spasi, huruf kecilkan. Kolom yang benar-benar diganti namanya
(`nama_siswa` alih-alih `nama_lengkap`) tetap ditolak.

Regresinya diuji bolak-balik: berkas dibangkitkan `StudentTemplateExport`,
diisi satu baris sintetis tanpa menyentuh baris judulnya, lalu dibaca lewat
`Excel::import` — jalur yang sama dengan aksi import Filament.

### Notifikasi dan basis data harus sepakat

Cacat di atas bukan sekadar "gagal", melainkan **dua jawaban berbeda dari satu
peristiwa**: barisnya tertulis, layarnya bilang gagal.

Tesnya sendiri yang membuatnya lolos — ada tes yang memeriksa barisnya masuk,
ada tes yang memeriksa pesan galat muncul untuk berkas yang salah, dan tidak
ada satu pun yang memeriksa keduanya atas berkas yang sama.

Sejak sekarang keduanya diperiksa berpasangan lewat aksi import Filament yang
sungguhan (butir 508):

| Berkas | Yang tertulis | Yang dilaporkan |
| --- | --- | --- |
| sah | barisnya ada, jumlah benar | tanpa galat judul kolom |
| tak kompatibel | nol | galat judul kolom |
| campuran sah/ditolak | = jumlah yang dilaporkan masuk | jumlah ditolak = baris ditolak |
| tanpa baris sah | nol | tidak pernah mengaku berhasil |
| hanya lembar `Petunjuk` | nol | — |

Galat judul kolom tidak pernah menyertai baris yang masuk, dan sebaliknya.

### Kolom status

Nilai yang keliru di kolom status paling sering angka tingkat kelas (`10`).
Angka itu **tidak pernah** ditafsirkan ulang — bukan sebagai status, dan bukan
sebagai penempatan kelas. Penempatan kelas tidak diatur lewat berkas ini.

Barisnya ditolak dengan pesan yang menyebut nilai yang diterima:

```
Baris 2: kolom status harus salah satu dari ACTIVE, GRADUATED, DROPPED_OUT,
TRANSFERRED (kosongkan untuk ACTIVE).
```

"Pilihan status tidak sah" saja tidak memberi tahu apa pun tentang apa yang
harus ditulis. Kosong tetap dianggap `ACTIVE`.

### Kegagalan import yang bersebab

"0 siswa berhasil diimport" adalah jawaban yang benar untuk tiga keadaan yang
sangat berbeda. Kini dibedakan:

| Keadaan | Yang ditampilkan |
| --- | --- |
| judul kolom tidak dikenali | kolom wajib yang hilang, dan saran mengunduh template |
| berkas tanpa baris data | keterangan bahwa lembar pertama hanya berisi judul |
| ada baris ditolak | jumlah ditolak + sebab per baris |
| berhasil | jumlah yang masuk |

NISN pendek di jalur import admin kini ikut dinormalisasi, mengikuti kontrak
yang sama dengan migrasi. NISN yang bukan angka atau lebih dari 10 digit tetap
ditolak dengan sebabnya.

---

## 12. Privasi

- Berkas sumber tetap di luar repositori; tidak disalin, tidak di-commit.
- Tidak ada ekstrak per baris di dalam repositori.
- Seluruh berkas uji dibangun saat tes berjalan dari data karangan lalu dihapus.
  NIS karangan berawalan `Z`, dan surel contoh memakai domain `.test` (RFC
  2606).
- Laporan perintah hanya mencetak agregat dan nomor baris; tidak pernah isi
  barisnya.
- Pesan pengecualian boleh menyebut nama lembar yang hilang, tidak pernah isinya.
- Ada tes yang memeriksa berkas contoh tidak memuat data siswa yang ada di basis
  data.

---

## 13. Perintah

```bash
# Analisis kering — tidak menulis apa pun.
php artisan migrasi:dry-run "<jalur berkas .xlsx>" --school=PUSAT

# Rencana terap, masih tanpa menulis.
php artisan migrasi:terapkan-uji "<jalur berkas .xlsx>" --school=PUSAT

# Menulis — HANYA basis data uji.
DB_CONNECTION=mysql DB_DATABASE=smartsukses_test \
  php artisan migrasi:terapkan-uji "<jalur berkas .xlsx>" --school=PUSAT --konfirmasi
```

Lembar siswa dideteksi otomatis bila `Data Siswa` tidak ada. `--sheet-siswa`
menerima beberapa nama dipisah koma, dan nama yang salah ketik tetap melempar
galat — "lembarnya tidak ada" tidak boleh menyamar sebagai "isinya nol".
