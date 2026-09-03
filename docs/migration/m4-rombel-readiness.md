# M4 — Penyiapan rombel dan kesiapan impor siswa ujung ke ujung

Status: **terverifikasi di basis data uji.** Rombel resmi 2026/2027 dapat
disiapkan lewat satu perintah, dan sesudahnya seluruh 39 baris siswa dari berkas
sumber mendapat penempatan kelas. Penghambat kelas yang tersisa dari M3 sudah
tertutup.

Belum ada apa pun yang dijalankan di produksi.

Dokumen ini mencatat keputusan, bukan data. Tidak ada NIS, NISN, nama, atau
alamat siswa di sini — hanya jumlah agregat. Berkas sumber tetap di luar
repositori.

---

## 1. Rombel resmi 2026/2027

Dikonfirmasi pengembang/pemilik, dan kini tertulis di
`App\Support\Migration\CanonicalRombel`:

| Nama rombel | Tingkat |
| --- | --- |
| `X Terbuka - 2` | 10 |
| `XI Terbuka - 1` | 11 |
| `XII Terbuka - 1` | 12 |
| `XII Terbuka - 2` | 12 |

Nama-nama ini berasal dari kolom `Kelas di SMAN 11` pada berkas sumber dan
dipakai apa adanya sebagai nama rombel di sistem. Itu keputusan sadar, bukan
kebetulan — pertanyaan yang sempat terbuka di M3 §4 kini terjawab.

### Koreksi data terkonfirmasi

```
XII Terbuka - I   →   XII Terbuka - 1
```

Tetap satu alias penuh, dicocokkan persis (tanpa memandang besar-kecil huruf),
**bukan** aturan umum angka Romawi. `XI Terbuka - I`, `X Terbuka - II`, dan
`Kelas I` tidak ikut berubah, dan ada tesnya.

Laporan selalu menampilkan keduanya:

```
label "XII Terbuka - I" → "XII Terbuka - 1" (koreksi data terkonfirmasi)
```

### Satu tempat untuk dua hal

Daftar rombel dan daftar alias tinggal bersama di `CanonicalRombel`. Keduanya
selalu berubah bersama: menambah rombel tanpa memperbarui aliasnya, atau
sebaliknya, menghasilkan perintah penyiapan dan pembaca berkas yang berbeda
pendapat tanpa ada yang menyadarinya (butir 514).

`StudentImportPlan::canonicalClassLabel()` kini meneruskan ke sana.

---

## 2. Bentuk sebuah rombel

Hasil audit `classes` (ERD 2.2), bukan tebakan:

| Kolom | Sifat |
| --- | --- |
| `school_id` | wajib, FK `schools`, cascade |
| `academic_year_id` | wajib, FK `academic_years`, cascade |
| `name` | wajib, string 50 |
| `grade_level` | wajib, tinyint (10/11/12) |
| `homeroom_teacher_id` | opsional, FK `users` |
| `room` | opsional, string 50 |
| `capacity` | smallint, bawaan 35 |

Satu-satunya indeks unik adalah `(academic_year_id, homeroom_teacher_id)` —
aturan "satu guru satu rombel per tahun ajaran" (KELAS-01). MySQL mengizinkan
banyak NULL pada indeks unik, sehingga rombel tanpa wali kelas boleh lebih dari
satu.

### Celah yang ditemukan saat audit

**Tidak ada indeks unik pada `(school_id, academic_year_id, name)`**, dan
formnya pun tidak memeriksanya. Dua rombel bernama sama pada satu tahun ajaran
karena itu mungkin dibuat.

Akibatnya tidak terlihat di layar kelas, tetapi nyata di sini: pencocokan kelas
saat impor siswa memakai `->value('id')`, yang memilih salah satu di antaranya
tanpa memberi tahu siapa pun. Siswa dapat mendarat di rombel yang bukan
tujuannya (butir 516).

Yang dilakukan, dua lapis:

**1. Form.** Pagar ditambahkan di form Filament, mengikuti pola yang sudah
dipakai kolom wali kelas. Ini menutup jalur pembuatan yang normal.

**2. Pencocokan kelas.** Form saja tidak cukup — baris ganda masih dapat berasal
dari data lama atau dari dua permintaan yang bersamaan. Pencocokan kelas karena
itu kini membedakan tiga keadaan, bukan dua:

| Jumlah rombel yang cocok persis | Hasil |
| --- | --- |
| 0 | `CLASS_NOT_FOUND` |
| 1 | dipakai |
| >1 | **`CLASS_AMBIGUOUS`** |

Batas pencariannya bertiga: cabang + tahun ajaran + nama kanonis yang persis.
Nama yang sama di cabang lain atau tahun ajaran lain **bukan** ambiguitas, dan
ada tesnya.

Pada keadaan ambigu importer **tidak** memilih salah satu, **tidak**
menggabungkan, **tidak** menghapus, **tidak** mengganti nama, dan **tidak**
menempatkan siswa ke keduanya. Data induk siswa tetap masuk — ambiguitas rombel
bukan alasan menahan data induk yang sudah benar — hanya penempatannya yang
tidak terjadi. Satu baris ambigu tidak menahan baris lain yang sehat.

`resolveClassId()` sengaja mengembalikan null pada nol maupun lebih dari satu
kecocokan, sehingga tidak ada pemanggil yang dapat tanpa sengaja memperlakukan
"ganda" seperti "ada". Yang membedakan keduanya untuk laporan adalah
`placement()`.

### Celah yang tersisa di basis data, dan jalan amannya

Indeks unik di basis data **tidak** ditambahkan di batch ini. Migrasi semacam
itu akan gagal pada pemasangan yang sudah terlanjur punya duplikat, dan tidak
ada bukti bahwa keadaan itu tidak ada di mana pun — kegagalan migrasi di tengah
deployment jauh lebih mahal daripada celah yang sudah ditutup di dua lapis
aplikasi.

Jalan amannya kelak, berurutan:

1. **Audit duplikat** yang benar-benar ada di setiap pemasangan —
   `migrasi:siapkan-rombel` dalam mode kering sudah melaporkannya per rombel
   resmi, dan query manual untuk rombel di luar daftar.
2. **Rekonsiliasi manual** oleh manusia: rombel mana yang dipertahankan, ke mana
   siswa/nilai/rapor yang menggantung pada yang lain dipindahkan. Ini keputusan
   sekolah, bukan keputusan migrasi.
3. **Baru sesudah nol duplikat terbukti**, tambahkan indeks unik pada
   `(school_id, academic_year_id, name)`.

Sampai langkah 3 dilakukan, aplikasi tetap gagal dengan aman hari ini.

---

## 3. Penyiapan eksplisit, bukan efek samping importer

Importer siswa **tetap tidak pernah membuat rombel** (butir 490). Yang
membuatnya adalah perintah tersendiri:

```sh
# Mode kering — mencetak rencana, tidak menulis apa pun.
php artisan migrasi:siapkan-rombel --school=PUSAT

# Menulis rombel yang belum ada.
php artisan migrasi:siapkan-rombel --school=PUSAT --konfirmasi
```

Bentuknya mengikuti keluarga `migrasi:` yang sudah ada: `--school` dan
`--tahun-ajaran` yang sama, mode kering sebagai bawaan.

### Yang dijamin

- **Idempotent.** Jalan kedua membuat nol rombel; barisnya yang sama, bukan
  baris baru yang kebetulan berjumlah sama (id-nya diperiksa di tes).
- **Cabang wajib eksplisit.** Kode cabang yang tidak ada menghentikan perintah.
- **Tahun ajaran wajib ada.** Tidak pernah dibuat, tidak pernah ditebak. Tanpa
  tahun ajaran aktif perintah berhenti dan menyebut apa yang kurang.
- **Tepat empat rombel**, dengan tingkat menurut daftar resmi.
- **Nol tulisan ke siswa, penempatan, maupun akun** — dilaporkan di setiap
  jalannya, dan ada tesnya.
- **Tingkat yang tidak cocok dilaporkan, tidak diperbaiki diam-diam.** Rombel
  yang sudah dipakai mungkin punya sejarah yang tidak terlihat dari sini;
  perintah keluar dengan kode 1 dan menyebut mana yang berbeda.
- **Rombel ganda dilaporkan sebagai GANDA, bukan sebagai "sudah ada".**
  Melaporkannya sehat akan menyembunyikan justru masalah yang paling merugikan:
  impor siswa menolak menempatkan siapa pun ke label itu, dan operator tidak
  tahu sebabnya. Perintah keluar dengan kode 1, tidak membereskan duplikatnya
  sendiri, dan tetap membuat rombel lain yang memang belum ada.
- **Sasaran ditampilkan sebelum menulis:** lingkungan, basis data, cabang, tahun
  ajaran beserta semesternya, dan keempat nama rombel. Lingkungan di luar
  `local`/`testing` ditandai. Tidak ada kredensial maupun data pribadi yang ikut
  tercetak.
- **Tidak didaftarkan otomatis** di `DatabaseSeeder`. Harus dipanggil sengaja.

### Mengapa tidak dipagari basis data uji

Tidak seperti `migrasi:terapkan-uji`, perintah ini **tidak** memakai
`MigrationWriteGuard`. Yang ditulisnya bukan data pribadi siapa pun, melainkan
empat baris struktur yang memang harus ada di produksi juga. Memagarinya ke
basis data uji akan membuat sekolah tidak punya jalan resmi menyiapkan
rombelnya, dan jalan yang tidak resmi adalah mengedit baris basis data dengan
tangan (butir 515).

`MigrationWriteGuard` sendiri **tidak dilemahkan** dan tetap memagari impor
siswa.

---

## 4. Tahun ajaran

Yang dipakai: **2026/2027 Ganjil, semester 1**, yang sudah ada dan aktif di
sistem. Perintah tidak membuat tahun ajaran, dan invarian satu tahun ajaran
aktif tidak disentuh.

Basis data uji disiapkan menyalin persis itu, secara eksplisit — bukan oleh
importer, bukan oleh perintah rombel.

---

## 5. Hasil verifikasi di `smartsukses_test`

Seluruhnya agregat. Berkas sumber tidak diubah dan tidak disalin.

### A. Penyiapan rombel

| Jalan | Dibuat | Sudah ada | Tingkat tidak cocok |
| --- | --- | --- | --- |
| pertama (`--konfirmasi`) | 4 | 0 | 0 |
| kedua | **0** | 4 | 0 |

### B. Analisis kering M3

```
baris sumber 40 = siap 39 + tertunda 1 + ditolak 0     seimbang

NISN_VALID 2 · NISN_NORMALIZED 37 · NISN_BLANK 1 · NISN_INVALID 0

PLACEMENT_READY   39
CLASS_NOT_FOUND    0        ← penghambat M3 tertutup
CLASS_AMBIGUOUS    0
```

Keempat label cocok, termasuk `XII Terbuka - I` lewat koreksi terkonfirmasi.

### C–D. Terap uji pertama dan rekonsiliasi

| | |
| --- | --- |
| siswa dibuat | 39 |
| penempatan kelas dibuat | 39 |
| baris dilewati (tertunda) | 1 |

Sesudahnya: 39 siswa, 4 rombel, 39 `student_classes` seluruhnya `ACTIVE`,
**0 siswa tanpa penempatan**, **0 siswa dengan lebih dari satu penempatan per
tahun ajaran**, 39 NIS unik, 39 NISN masing-masing tepat 10 karakter, 0 akun.

Sebaran per rombel — 12 + 13 + 5 + 9 = 39, persis seperti berkas sumbernya.

### E–F. Terap kedua dan rekonsiliasi

| | |
| --- | --- |
| siswa baru | **0** |
| rombel baru | **0** |
| penempatan baru | **0** |
| siswa dicocokkan | 39 |

Jumlah baris tidak berubah.

---

## 6. Siswa yang tertunda

Satu siswa tetap `PENDING_MISSING_NIS`. Ia **tidak** diimpor, **tidak** diberi
NIS karangan, **tidak** mendapat penempatan, dan **tidak** mendapat akun. Ia
tetap terhitung di rekonsiliasi setiap kali, dan tidak menghalangi 39 yang lain.

Sikapnya tidak berubah dari M3 (butir 488): identitas sementara akan menjadi
identitas tetap begitu ada nilai, tagihan, dan rapor yang menggantung padanya.

---

## 7. Akun tetap terpisah

```
MASTER_READY      39
PLACEMENT_READY   39
ACCOUNT_PENDING   39
ACCOUNT_READY      0
```

Tidak ada `users` yang dibuat, tidak ada alamat surel yang dikarang, dan
`students.user_id` tetap NULL. Penyediaan akun menunggu aliran data
akun/kontak yang terpisah, dan login terpadu tidak disentuh.

---

## 8. Panel admin

Staf **sudah dapat** membuat dan mengelola rombel lewat
`Admin → Kelas`: nama, tingkat (10/11/12), tahun ajaran, wali kelas, ruang, dan
kapasitas. Fiturnya tidak dirancang ulang.

Satu cacat kecil diperbaiki: nama rombel kini tidak boleh kembar pada satu
cabang dan tahun ajaran (§2). Selain itu tidak ada perubahan.

Artinya sesudah deployment staging, sekolah dapat menambah rombel sendiri tanpa
pengembang menyentuh basis data.

---

## 9. Urutan produksi — **belum dijalankan**

Dirancang, tidak dieksekusi. Tidak ada perintah yang dijalankan terhadap basis
data produksi mana pun dalam batch ini.

1. Pastikan cabang tujuan benar (`--school`).
2. Pastikan tahun ajaran 2026/2027 ada dan aktif.
3. Siapkan rombel: mode kering lebih dulu, lalu `--konfirmasi`.
4. **Backup basis data** (`ops/backup-database.sh`).
5. Analisis kering berkas sungguhan; periksa `CLASS_NOT_FOUND` = 0.
6. Tinjau rekonsiliasi bersama tata usaha — terutama jumlah tertunda.
7. **Otorisasi eksplisit** untuk impor produksi. Belum ada.
8. Terap.
9. Rekonsiliasi ulang.
10. Belum ada penyediaan akun.

Langkah 8 **belum punya jalan**: `migrasi:terapkan-uji` dipagari basis data uji,
dan pagarnya sengaja tidak dilonggarkan di sini. Membukanya adalah keputusan
tersendiri dengan otorisasinya sendiri, bukan efek samping M4.

---

## 10. Yang tersisa sebelum "deploy → uji → pakai"

| | Status |
| --- | --- |
| Rombel 2026/2027 | **selesai** — satu perintah, idempotent |
| Pemetaan kelas berkas sumber | **selesai** — `CLASS_NOT_FOUND` 0 |
| Impor 39 siswa + penempatan | **terbukti di basis data uji** |
| Idempotensi | **terbukti** |
| NIS satu siswa | menunggu sekolah menerbitkannya |
| Otorisasi impor produksi | belum ada |
| Jalur terap produksi | sengaja belum dibuka |
| Akun siswa/orang tua | menunggu data kontak |
| Server staging | lihat `../deployment/staging-uat.md` |

UAT dapat berjalan lebih dulu dengan data sintetis; tidak satu pun butir di atas
menghalanginya.
