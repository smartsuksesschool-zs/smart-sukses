# M1 + M2 — Jalur Cepat Menuju Simulasi

Status: **audit selesai, importer kering selesai, impor uji belum boleh jalan.**

Dokumen ini menutup M1 (audit berkas sungguhan) dan M2 (importer dry-run) dalam
satu berkas karena keduanya dikerjakan pada batch yang sama menjelang simulasi.
Prioritasnya kesiapan simulasi, bukan kelengkapan migrasi.

Seluruh angka di bawah bersifat agregat. **Tidak ada satu pun data per orang**
di dokumen ini, di kode, maupun di berkas tes — tidak ada nama, alamat, NIS,
NISN, nomor telepon, atau surel yang berasal dari berkas sekolah.

---

## 1. Berkas sumber

Dua berkas privat milik sekolah, keduanya **di luar repositori** dan tidak
pernah disalin ke dalamnya:

| Berkas | Isi | Peran di batch ini |
| --- | --- | --- |
| `Data Siswa dan Guru S3 01092026.xlsx` | lembar `Data Siswa`, lembar `Data Guru` | sumber utama M1/M2 |
| `Progres Survei Tracer Study Zakat Sukses.xlsx` | lembar `Rekapan`, `Visit`, `Sheet4` | **alumni** — hanya diklasifikasikan, tidak diimpor |

Jalur berkas sengaja tidak dicatat di sini. Letak yang dianjurkan tetap seperti
M0: satu direktori privat di luar repositori, misalnya `C:\migration-private\`.
Perintah dry-run menerima jalurnya sebagai argumen, tidak pernah menebaknya, dan
tidak mencetaknya kembali ke layar.

`.gitignore` tidak perlu diubah: tidak ada berkas sekolah yang masuk ke pohon
kerja sama sekali.

---

## 2. Angka sumber (M1)

### 2.1 Siswa aktif

| | Jumlah |
| --- | --- |
| Baris data terbaca | **40** |
| Kelas 10 | 13 |
| Kelas 11 | 14 |
| Kelas 12 | 13 |
| Nama kembar | 0 |
| Baris ditolak parser sebagai non-data | 2 (baris judul dokumen) |

Lembar `Data Siswa` tersusun **per angkatan**: baris judul, lalu tiga seksi yang
masing-masing punya baris heading sendiri dan ditutup baris rekap
`Jumlah Siswa Kelas N: N siswa`.

Rekap yang ditulis sekolah sendiri (13 + 14 + 13 = 40) **cocok persis** dengan
hasil parsing (40). Kecocokan ini diperiksa ulang setiap kali perintah dijalankan
dan dilaporkan sebagai baris tersendiri; bila suatu saat tidak cocok, itu berarti
parser atau berkasnya berubah, dan harus dilihat sebelum apa pun ditulis.

### 2.2 Guru / staf

| | Jumlah |
| --- | --- |
| Baris data terbaca | **10** |
| Orang berbeda | 10 |
| Berperan Kepala Sekolah | 1 |
| Berperan Guru | 9 |

### 2.3 Alumni (hanya diklasifikasikan)

Lembar `Rekapan` adalah daftar induknya; `Sheet4` subhimpunannya; `Visit` catatan
kunjungan lapangan.

| | Jumlah |
| --- | --- |
| Baris orang di `Rekapan` | 84 |
| Di antaranya bernomor urut (alumni sesungguhnya) | **80** |
| Ditandai sekolah sendiri sebagai bukan lulusan | 4 |
| Punya nomor telepon | 66 |
| Punya alamat | 55 |

Sebaran tahun lulus: 2020 = 18, 2021 = 3, 2022 = 5, 2023 = 11, 2024 = 14,
2025 = 17, 2026 = 16.

Sebaran status survei: sudah submit 55, belum dikontak 19, data invalid 4,
alamat invalid 4, belum respon 1, belum submit 1.

Berbeda dari lembar siswa aktif, berkas alumni **punya** kolom jenis kelamin dan
nomor telepon. Ia tetap tidak diimpor — lihat §8.

---

## 3. Pemetaan kolom → basis data

### 3.1 Lembar `Data Siswa`

| Kolom sumber | Kolom tujuan | Status |
| --- | --- | --- |
| `No.` | — | nomor urut per seksi, tidak dipetakan |
| `Nama` | `students.full_name` | **ada** |
| `Kelas` | `student_classes` lewat `classes.name` + `classes.grade_level` | **ada**, butuh prasyarat §6 |
| `Alamat` | `students.address` | **ada** (nullable) |

Kolom `students` yang **wajib menurut skema tetapi tidak ada di sumber**:

| Kolom | Sifat kolom | Akibat |
| --- | --- | --- |
| `nis` | `NOT NULL`, unik per cabang | **penghambat keras** |
| `gender` | `enum('L','P') NOT NULL` | **penghambat keras** |

Kolom opsional yang juga tidak ada, dan karenanya akan kosong: `nisn`,
`birth_place`, `birth_date`, `religion`, `parent_name`, `parent_phone`,
`parent_email`, `entry_year`. Semuanya nullable, jadi tidak menghalangi impor.

`status` tidak ada di sumber dan tidak perlu: seluruh berkas ini memang siswa
aktif, dan kolomnya berdefault `ACTIVE`.

### 3.2 Lembar `Data Guru`

| Kolom sumber | Kolom tujuan | Status |
| --- | --- | --- |
| `No.` | — | tidak dipetakan |
| `Nama Guru` | `users.name` | **ada** |
| `Pelajaran` | tiga tujuan berbeda sekaligus — lihat §5 | **ada, perlu dipilah** |

Kolom `users` yang wajib tetapi tidak ada: `email` (`NOT NULL`, **unik global**)
dan `password`. Keduanya menghalangi pembuatan akun, bukan pendataan orangnya.

### 3.3 Kolom yang boleh ditambahkan sekolah kapan saja

Pembaca berkas mengenali judul kolom berdasarkan **teksnya**, bukan posisinya.
Begitu sekolah menambahkan kolom `NIS` dan `Jenis Kelamin` ke berkas yang sama,
kolom itu langsung terbaca tanpa satu baris kode pun berubah. Judul yang
dikenali: `NIS`, `NISN`, `Jenis Kelamin` / `L/P`, `Tempat Lahir`,
`Tanggal Lahir`, `Agama`, `Nama Orang Tua`, `HP Orang Tua`, `Email Orang Tua`,
`Tahun Masuk`, `Email Siswa`.

Judul asing tidak menggagalkan apa pun; ia dilaporkan sebagai "tidak dipetakan"
dan diabaikan.

---

## 4. Aturan normalisasi

1. **Spasi dirapikan, isi tidak diubah.** Setiap sel di-`trim` dan spasi
   gandanya dipadatkan. Tidak ada penggantian isi.
2. **Baris data = nomor urut berupa angka DAN nama terisi.** Masing-masing
   syarat sendirian juga cocok dengan baris judul atau baris rekap, jadi
   keduanya harus terpenuhi bersama.
3. **Heading boleh berulang.** Setiap baris yang memuat ≥ 2 judul kolom yang
   dikenali dibaca sebagai heading baru dan memperbarui peta kolom.
4. **Baris `Jumlah ...` bukan data.** Angkanya dipakai sebagai pemeriksaan
   silang, tidak pernah sebagai sumber.
5. **Jenis kelamin** menerima `L`/`P`, `Laki-laki`, `Perempuan`, `Pria`,
   `Wanita`. Selain itu dianggap **kosong**, bukan ditebak.
6. **Label kelas** `10`/`X`, `11`/`XI`, `12`/`XII` (boleh berawalan `Kelas`)
   menjadi `grade_level` 10/11/12. Label lain → `tingkat_kelas_tidak_terbaca`.
   Tidak ada tebakan.
7. **Tanggal** serial Excel dikonversi ke `YYYY-MM-DD`; teks dibiarkan apa
   adanya untuk divalidasi belakangan.

Yang **tidak** dilakukan, dan tidak akan: mengarang NIS, NISN, surel, nomor
telepon, jenis kelamin, atau identitas akun. Tidak ada satu pun nilai identitas
yang diturunkan dari nama.

---

## 5. Guru: memilah orang, jabatan, mapel, dan yang ambigu

Kolom `Pelajaran` adalah satu sel teks bebas yang di berkas nyata memuat tiga
hal berbeda dipisah koma. Contoh bentuknya (bukan salinan barisnya):

```
"Kepala Sekolah, PAI Akhlakul Karimah"   -> jabatan + mata pelajaran
"BK, PJOK, Mentoring, Pramuka"           -> layanan + mapel + dua ekskul
```

Menyalin sel itu bulat-bulat ke `subjects.name` akan melahirkan mata pelajaran
bernama **"Kepala Sekolah"**, yang lalu muncul di rapor. Karena itu setiap token
diklasifikasikan lebih dulu, dan **apa pun yang tidak dikenali menjadi
`AMBIGUOUS`, tidak pernah menjadi mata pelajaran.**

### 5.1 Hasil klasifikasi

**Mata pelajaran (5)** — dengan kode yang **diusulkan**, belum disahkan:

| Mata pelajaran | Pengampu | Kode usulan |
| --- | --- | --- |
| Al-Qur'an | 4 | `AQ` |
| Praktek Ibadah | 3 | `PI` |
| PAI Akhlakul Karimah | 1 | `PAK` |
| English | 1 | `ENGL` |
| PJOK | 1 | `PJOK` |

**Jabatan (1)** — `Kepala Sekolah` → peran `KEPALA_SEKOLAH`. Bukan mapel.

**Bukan mata pelajaran (3)** — nyata, tetapi bukan mapel ber-rapor. Menunggu
keputusan sekolah apakah perlu diwakili di sistem sama sekali:

| Token | Pengampu |
| --- | --- |
| Mentoring | 4 |
| Bimbingan Konseling (`BK`) | 2 |
| Pramuka | 1 |

**Ambigu: 0.** Seluruh token pada berkas hari ini terklasifikasi. Token baru
yang muncul kemudian otomatis jatuh ke `AMBIGUOUS` dan menggagalkan gerbang
dry-run sampai sekolah menjelaskannya.

### 5.2 Tentang kode mata pelajaran

`subjects.code` wajib dan unik per cabang, sementara berkas sekolah tidak
memuatnya. Kode diturunkan dari nama secara deterministik (satu kata → 4 huruf
pertama; banyak kata → inisial) dan dilaporkan sebagai **usulan**. Kode bukan
identitas orang, jadi menurunkannya tidak melanggar larangan mengarang
identitas — tetapi yang mengesahkan tetap sekolah.

### 5.3 Penugasan mengajar

**Tidak ada** di berkas. Kolom `Pelajaran` menyebut apa yang diampu seseorang,
bukan **di kelas mana**. `class_subjects` menuntut (`class_id`, `subject_id`,
`teacher_id`, `academic_year_id`) — tiga dari empat belum diketahui. Penugasan
per kelas harus datang dari sekolah; menebaknya berarti mengarang jadwal.

---

## 6. Kelas

Sumber hanya mengenal tiga label: `10`, `11`, `12`. Cukup untuk mengisi
`classes.grade_level`, dan **tidak ada nama rombel di dalamnya**.

Karena itu satu label = satu kelas. Memecahnya menjadi `10-A`/`10-B` akan
mengarang rombel yang tidak pernah dinyatakan sekolah, jadi tidak dilakukan.

Prasyarat yang mengikat, langsung dari skema:

1. `classes.academic_year_id` **`NOT NULL`**. Satu baris `academic_years` untuk
   tahun ajaran tujuan harus ada lebih dulu — beserta semesternya, karena satu
   baris `academic_years` mewakili satu semester dan namanya memuatnya.
2. `classes.name` + `grade_level` + `school_id` harus ada sebelum satu siswa pun
   dapat ditempatkan lewat `student_classes`.
3. `classes.homeroom_teacher_id` nullable — wali kelas boleh menyusul, dan
   memang belum ada di sumber.

Dry-run melaporkan tiap label kelas beserta penghambatnya, dan **tidak membuat**
satu kelas pun.

---

## 7. Akun: master data terpisah dari akun

Ini jawaban atas aturan akun siswa yang diminta batch ini.

`users.email` `NOT NULL` dan unik global; tidak ada surel siswa di berkas, dan
Google Form-nya masih berjalan. Jadi akun siswa **memang belum bisa dibuat.**

Yang menentukan: **`students.user_id` nullable.** Master data siswa karenanya
**tidak** bergantung pada akun, dan impor siswa tidak boleh ditahan hanya karena
penyediaan akun belum siap. Keduanya dipisah, dan dry-run melaporkannya sebagai
tiga keadaan berbeda:

| Keadaan | Arti |
| --- | --- |
| `STUDENT_MASTER_READY` | baris memenuhi seluruh kolom wajib `students` |
| `ACCOUNT_READY` | surel ada, sah, dan tidak dipakai cabang lain |
| `ACCOUNT_BLOCKED` | selain itu, dengan sebabnya disebut |

Sebab yang dilaporkan: `surel_tidak_ada_di_sumber`,
`format_surel_tidak_valid`, `surel_dipakai_cabang_lain`,
`master_data_belum_lengkap`.

Keadaan hari ini: siswa `ACCOUNT_BLOCKED` 40/40, guru `ACCOUNT_BLOCKED` 10/10,
seluruhnya karena `surel_tidak_ada_di_sumber`.

Kata sandi tidak pernah ikut berkas. Akun yang kelak dibuat memakai
`must_change_password = true`, dan kata sandi awal disalurkan di luar sistem.

---

## 8. Alumni — diklasifikasikan, tidak diimpor

Ditunda ke pasca-simulasi. Tidak ada importer alumni yang dibangun, dan aplikasi
hari ini tidak menuntutnya.

Alasan teknis, bukan sekadar prioritas: alumni akan menjadi baris `students`
berstatus `GRADUATED`, dan `students.nis` tetap `NOT NULL` unik per cabang.
Berkas tracer study tidak punya NIS. Penghambatnya **sama persis** dengan siswa
aktif, dan menyelesaikannya untuk 40 siswa aktif lebih dulu adalah urutan yang
benar.

Catatan tambahan bila kelak dikerjakan: 4 dari 84 baris ditandai sekolah sendiri
sebagai bukan lulusan; keempatnya harus dikeluarkan, bukan ikut terbawa. Berkas
ini juga memuat nomor telepon dan alamat — data pribadi yang tidak dibutuhkan
untuk keperluan sekolah dan sebaiknya tidak ikut dimigrasikan tanpa alasan.

---

## 9. PPDB — risiko duplikasi, tanpa migrasi

Tidak ada migrasi PPDB di batch ini. Yang didokumentasikan hanya risikonya.

13 siswa Kelas 10 pada berkas ini adalah angkatan masuk terbaru. Bila
`ppdb_registrations` juga memuat orang yang sama, mengimpor mereka sebagai
`students` akan menghasilkan **dua representasi untuk satu orang** —
satu baris pendaftaran dan satu baris siswa — tanpa apa pun yang menghubungkan
keduanya.

Skema sudah menyediakan penghubungnya: `ppdb_registrations.converted_student_id`.
Yang tidak tersedia adalah **kuncinya**. Berkas siswa tidak memuat `NIS` maupun
`reg_number`, dan mencocokkan berdasarkan nama saja dilarang — dua orang boleh
bernama sama, dan satu orang boleh ditulis berbeda di dua berkas.

Karena itu risiko ini **tidak dapat diselesaikan otomatis**. Ia hanya hilang bila
sekolah menyediakan salah satu dari:

- `NIS` setiap siswa Kelas 10 **beserta** `reg_number` PPDB-nya, atau
- pernyataan tegas bahwa angkatan ini tidak ada di `ppdb_registrations`.

Sampai salah satunya ada, impor Kelas 10 sebaiknya dijalankan **paling akhir**,
setelah Kelas 11 dan 12.

---

## 10. Perintah dry-run (M2)

```
php artisan migrasi:dry-run "<jalur berkas .xlsx>" --school=PUSAT
        [--tahun-ajaran="2026/2027 Ganjil"]
        [--sheet-siswa="Data Siswa"] [--sheet-guru="Data Guru"]
```

Ketentuan yang mengikat:

- **Tidak ada mode terap.** Bukan "bawaannya dry-run" — memang tidak ada jalan
  menuju satu pun penulisan di kode ini. Menulis siswa menuntut NIS, dan NIS
  belum diputuskan sekolah; menyediakan tombol terap yang pasti menolak setiap
  baris hanya akan menyesatkan.
- `--school` menerima **kode cabang**, tidak pernah id numerik. Kode tidak
  ditemukan → berhenti, kode keluar 2.
- Tanpa sesi login `SchoolScope` tidak aktif, jadi setiap query membawa
  `school_id`-nya sendiri secara eksplisit.
- Nama tahun ajaran yang ganda → berhenti, bukan diambil `first()`.
- Keluarannya **tidak pernah mencetak baris data**. Yang tampil hanya jumlah,
  label kelas, nama mata pelajaran, dan **nomor baris** — tidak ada nama orang.
- Hasilnya deterministik: berkas yang sama menghasilkan laporan yang sama.

Kode keluar: `0` bersih, `1` ada penghambat, `2` gagal dijalankan.

### 10.1 Ringkasan dry-run hari ini

| Siswa | |
| --- | --- |
| Baris sumber | 40 |
| Baris valid | 0 |
| Kandidat buat baru | 0 |
| Kandidat cocok | 0 |
| Baris ditolak | **40** |
| Sebab | `nis` kosong 40, `gender` kosong 40 |
| Duplikat NIS dalam berkas | 0 |
| Nama kembar | 0 |

| Guru | |
| --- | --- |
| Baris sumber | 10 |
| Orang berbeda | 10 |
| Kandidat buat baru | 10 |
| Kandidat cocok | 0 |
| Mapel kandidat | 5 |
| Token ambigu | 0 |

Ke-40 baris ditolak adalah **hasil yang benar**, bukan kegagalan: berkas hari ini
memang belum memuat dua kolom yang wajib menurut skema. Importer yang tetap
meloloskannya harus mengarang identitas.

### 10.2 Idempotensi

Belum ada penulisan, jadi belum ada yang perlu diulang. Strateginya tetap
seperti M0 §6 dan sudah ditegakkan pada sisi pembacaan dry-run:

| Tabel | Kunci pencocokan |
| --- | --- |
| `students` | (`school_id`, `nis`) — dijamin unik oleh basis data |
| `users` | `email` — unik global |
| `subjects` | (`school_id`, `code`) — dijamin unik |
| `classes` | (`school_id`, `academic_year_id`, `name`) — **tidak** dijamin DB |

Aturannya: cocokkan dulu, jangan buat buta; nol atau satu hasil, selain itu
berhenti; **NIS adalah identitas, nama tidak.**

---

## 11. Penghambat sebelum impor uji

Persis yang dilaporkan perintah, urut dari yang paling mengikat:

| # | Penghambat | Siapa yang menyelesaikan |
| --- | --- | --- |
| B-1 | `students.nis` kosong pada 40/40 baris (`NOT NULL`, unik per cabang) | **sekolah** — keputusan penomoran M-3 |
| B-2 | `students.gender` kosong pada 40/40 baris (`enum NOT NULL`) | **sekolah** — satu kolom di berkas yang sama |
| B-3 | `classes` untuk label 10/11/12 belum ada di tahun ajaran tujuan | pengembang, setelah B-5 |
| B-4 | `users.email` tidak ada untuk 10/10 guru (`NOT NULL`, unik global) | **sekolah** |
| B-5 | Tahun ajaran tujuan harus dipastikan namanya, beserta semester | **sekolah** |
| B-6 | Wali kelas per rombel belum ada di sumber | **sekolah** (nullable, boleh menyusul) |
| B-7 | Penugasan mengajar per kelas belum ada di sumber | **sekolah** (menghalangi `class_subjects`, bukan impor siswa) |
| B-8 | Status `BK`/`Mentoring`/`Pramuka` — mapel, ekskul, atau tidak diwakili | **sekolah** |
| B-9 | Kunci penghubung PPDB ↔ siswa Kelas 10 (§9) | **sekolah** |

B-1 dan B-2 sendirian sudah menghentikan seluruh impor siswa. Keduanya
diselesaikan dengan **menambahkan dua kolom pada berkas yang sudah ada** —
importer akan langsung membacanya tanpa perubahan kode.

---

## 12. Prasyarat impor uji M3

Berurutan; masing-masing menuntut yang sebelumnya.

1. Sekolah menambahkan kolom **`NIS`** dan **`Jenis Kelamin`** pada lembar
   `Data Siswa` di berkas yang sama. (B-1, B-2)
2. Sekolah memastikan **nama tahun ajaran tujuan beserta semesternya**. (B-5)
3. Jalankan ulang `migrasi:dry-run` dan pastikan
   `STUDENT_MASTER_READY = 40` serta `baris ditolak = 0`.
4. Siapkan `academic_years` dan `classes` untuk tahun ajaran tujuan di basis
   data **uji**, bukan produksi.
5. Jalankan ulang dry-run; pastikan tiga label kelas berstatus `siap`.
6. Baru setelah 1–5 bersih, mode terap dibangun — bersama tesnya, dalam satu
   `DB::transaction`, dan pertama-tama dijalankan terhadap basis data uji.

Perintah untuk langkah 3 dan 5, terhadap basis data uji:

```
DB_CONNECTION=mysql DB_DATABASE=smartsukses_test \
  php artisan migrasi:dry-run "<jalur berkas .xlsx>" --school=PUSAT
```

Akun guru dan siswa (B-4) **tidak** menjadi prasyarat langkah 1–6. Ia berjalan
di jalur terpisah, sesuai §7.

---

## 13. Lingkup yang ditunda

| Perkara | Alasan |
| --- | --- |
| Importer alumni | §8 — penghambatnya sama dengan siswa aktif, dan aplikasi belum menuntutnya |
| Rekonstruksi PPDB historis | §9 — tidak ada kunci penghubung yang sah |
| Mode terap (penulisan) | §10 — menuntut B-1/B-2 selesai lebih dulu |
| Penugasan mengajar per kelas | §5.3 — tidak ada di sumber |
| Riwayat akademik (nilai, tagihan, rapor) | di luar lingkup, menuntut keputusan tersendiri |
| Pembersihan legacy non-kritis | bukan kesiapan simulasi |

---

## 14. Yang **tidak** dikerjakan batch ini

- Tidak ada migrasi skema. Tidak ada kolom yang ditambah, diubah, atau dihapus.
- Tidak ada penulisan ke basis data mana pun, termasuk basis data uji.
- Tidak ada berkas sekolah yang masuk repositori.
- Tidak ada data pribadi di kode, tes, maupun dokumen ini.
- `smartsukses-docs/` tidak disentuh.
- Tidak ada push, tidak ada deploy.
