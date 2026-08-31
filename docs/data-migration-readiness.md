# Kesiapan Migrasi Data Sekolah Berjalan (M0)

Audit kesiapan, **bukan** pelaksanaan migrasi. Tidak ada satu baris data
sungguhan yang disentuh, diminta, atau disimpan pada batch ini. Berkas data
nyata belum ada, dan memang belum boleh ada di dalam repositori.

Sasarannya satu: ketika berkas Excel/Google Sheet dari sekolah akhirnya datang,
sudah jelas kolom apa yang dibutuhkan, apa yang otomatis, apa yang menuntut
keputusan manusia, dan urutan mana yang tidak boleh dibalik.

---

## 1. Inventaris kemampuan yang sudah ada

### 1.1 Yang sudah ada dan dapat dipakai apa adanya

| Kemampuan | Letak | Catatan |
| --- | --- | --- |
| Import siswa dari .xlsx | `app/Imports/StudentsImport.php` | 13 kolom, laporan galat per baris |
| Aksi import di panel | `ListStudents::runImport()` | Modal unggah, maks 5 MB, berkas dihapus di `finally` |
| Export siswa ke .xlsx | `app/Exports/StudentsExport.php` | 15 kolom |
| Import nilai per kelas-mapel | `app/Imports/GradesImport.php` | Memakai `nis` sebagai kunci alami |
| Enroll pendaftar PPDB → siswa | `PpdbRegistrationResource::enrollAction()` | Satu per satu, dalam transaksi |
| Cabang idempoten lewat `code` | `database/seeders/SchoolSeeder.php` | `updateOrCreate(['code' => 'PUSAT'])` |

**Temuan yang menyenangkan: berkas hasil export dapat langsung diimport
kembali.** `StudentsExport` menulis judul berhuruf besar ("Nama Lengkap"),
sementara `StudentsImport` membaca kunci ber-garis-bawah (`nama_lengkap`) —
tampak tidak cocok, tetapi maatwebsite/excel memformat baris judul dengan
`Str::slug($value, '_')` (`vendor/.../HeadingRowFormatter.php`, formatter
bawaan `slug`, dan project ini tidak menimpanya karena `config/excel.php` tidak
ada). "Nama Lengkap" menjadi `nama_lengkap`. Kolom "Kelas" dan "Catatan" pada
export tidak dibaca importer dan diabaikan tanpa galat.

Artinya sekolah dapat diberi berkas contoh berupa hasil export cabang kosong,
dan tidak ada format kedua yang perlu diperkenalkan.

### 1.2 Yang ada tetapi perlu transformasi

| Hal | Keadaan sekarang | Yang dibutuhkan migrasi |
| --- | --- | --- |
| Cabang tujuan import | Diambil dari `Auth::user()->school_id` | Perlu ditentukan eksplisit; Super Admin justru **ditolak** |
| Enroll PPDB | Aksi baris, satu pendaftar | Perlu jalur massal untuk angkatan yang sudah diterima |
| NIS duplikat | Dilaporkan sebagai galat baris | Perlu dibedakan: "sudah pernah diimport" vs "bentrok sungguhan" |
| Tanggal | Serial Excel dikonversi otomatis | Google Sheets mengekspor teks; format harus disepakati |

### 1.3 Yang belum ada sama sekali

| Yang hilang | Akibatnya pada migrasi |
| --- | --- |
| **Import akun pengguna massal** | Tidak ada `UserImport`, tidak ada aksi import di `UserResource`. Akun siswa dan orang tua dibuat satu per satu di panel |
| **Penempatan kelas lewat import** | `StudentsImport` hanya menulis `students`. Tidak ada baris `student_classes` yang dibuat — siswa hasil import tidak berada di kelas mana pun |
| **Penautan akun otomatis** | `students.user_id` dan `students.parent_user_id` hanya dapat diisi lewat `Select` di form, dari akun yang **sudah** ada |
| **Dry-run** | Tidak ada preseden di seluruh project. Hanya ada dua perintah artisan (`ProductionCheck`, `PruneNotifications`), keduanya tanpa mode simulasi |
| **Transaksi seluruh berkas** | `StudentsImport::collection()` menulis per baris di luar transaksi. Berkas yang gagal di tengah meninggalkan sebagian data |
| **Jejak audit import** | Import tidak menulis `audit_logs`. Tidak ada catatan siapa mengimport berkas apa, kapan |
| **Endpoint API import** | `POST /students/import` disebut di blueprint API 4.5 tetapi tidak pernah didaftarkan sebagai rute. Jalurnya hanya panel |
| **Bulk enroll PPDB** | Tidak ada |

Tidak satu pun dari daftar 1.3 dibuat pada batch ini. Semuanya adalah pekerjaan
implementasi yang menunggu keputusan dan menunggu bentuk data sungguhan.

---

## 2. Aturan skema yang mengikat migrasi

Diambil dari migrasi yang benar-benar ada, bukan dari asumsi.

| Tabel | Kunci unik di basis data | Ditegakkan aplikasi saja |
| --- | --- | --- |
| `schools` | `code`, `slug` | — |
| `users` | `email` — **global, lintas cabang** | — |
| `students` | (`school_id`, `nis`) | — |
| `subjects` | (`school_id`, `code`) | — |
| `class_subjects` | (`class_id`, `subject_id`, `academic_year_id`) | — |
| `classes` | (`academic_year_id`, `homeroom_teacher_id`) | Nama kelas **tidak** unik |
| `academic_years` | — | Satu `is_active` per cabang (`AcademicYear::activate`) |
| `student_classes` | — | Satu baris `ACTIVE` per siswa per tahun ajaran; baris `MOVED` sengaja disimpan sebagai histori |

Empat konsekuensi yang harus diingat sebelum berkas pertama masuk:

1. **`users.email` unik secara global.** Satu alamat surel tidak dapat dipakai
   di dua cabang, dan satu orang tua dengan anak di dua cabang **tidak dapat**
   menjadi satu baris `users` — `users.school_id` hanya satu kolom. Ini
   keputusan skema yang sudah ada, bukan sesuatu yang boleh diakali importer.
2. **`nisn` tidak unik di basis data.** Hanya `index`, dan validasinya sebatas
   `digits:10` ketika terisi. NISN cocok sebagai kunci pencocokan lintas
   sumber, tetapi keunikannya harus diperiksa importer sendiri.
3. **Nama kelas tidak unik.** "X IPA 1" pada satu tahun ajaran dapat muncul dua
   kali tanpa dicegah basis data. Pencarian kelas harus menolak hasil ganda,
   bukan mengambil yang pertama.
4. **Tidak ada kolom NIK di mana pun.** Tidak di `students`, tidak di `users`,
   tidak di `ppdb_registrations`. Migrasi **tidak boleh** memperkenalkannya
   tanpa keputusan pemilik: menyimpan NIK adalah keputusan kepatuhan data
   pribadi, bukan keputusan teknis.

---

## 3. Resolusi tenant dan identifikasi Building Pusat

`SchoolScope` menyimpulkan cabang dari **sesi yang sedang login**. Baris
pertamanya berbunyi: tanpa `Auth::hasUser()`, scope tidak dipasang sama sekali.

Akibatnya untuk migrasi:

- Perintah artisan, seeder, dan worker antrean berjalan **tanpa pagar tenant**.
  Importer berbasis CLI karena itu wajib membawa `school_id` sebagai argumen
  eksplisit dan memakainya di setiap `where` — persis pola `StudentFeeGenerator`.
- Import lewat panel sudah aman secara tenant, tetapi Super Admin **ditolak**
  (`ListStudents::runImport()` berhenti ketika `school_id` null). Migrasi lewat
  panel karena itu harus dijalankan sebagai Admin Sekolah cabang tujuan.

**Building Pusat diresolusi lewat `schools.code`.** Kolom itu `unique`, dan
`SchoolSeeder` sudah memakai `updateOrCreate(['code' => 'PUSAT'])` — jadi
identifikatornya sudah ada dan sudah stabil, bukan sesuatu yang diciptakan
sekarang.

Aturan yang mengikat:

- **Tidak pernah** menuliskan `school_id` numerik di berkas, skrip, atau
  dokumen. Id numerik berbeda antar mesin.
- **Tidak pernah** mencocokkan cabang dari `name`. Nama dapat berubah dan dapat
  ambigu.
- Pencarian menghasilkan tepat satu baris atau **berhenti**. Nol hasil =
  cabangnya belum dibuat. Lebih dari satu hasil mustahil pada `code`, tetapi
  pemeriksaannya tetap ditulis karena kegagalan yang senyap di sini berarti data
  satu sekolah masuk ke cabang lain.

---

## 4. Template import siswa aktif

Berkas: **`docs/templates/import-siswa-aktif.csv`** — hanya baris judul.

Tidak ada baris contoh **di dalam berkasnya**, mengikuti alasan yang sudah
dipakai `GradeTemplateExport` (butir 38): baris contoh ikut terbaca sebagai data
begitu berkasnya diunggah kembali, dan sekolah yang lupa menghapusnya akan
mendapat satu siswa fiktif. Contoh nilainya ada di tabel §5 di bawah, tempat ia
tidak dapat ikut terkirim.

Judul kolomnya sengaja sudah dalam bentuk ber-garis-bawah, sama persis dengan
yang dibaca `StudentsImport`, sehingga berkas ini dapat diisi lalu diunggah
tanpa penyesuaian judul.

---

## 5. Spesifikasi pemetaan kolom

`students` = ditulis langsung ke tabel siswa. Kategori mengikuti permintaan
batch: REQUIRED / OPTIONAL / DERIVED / LOOKUP / IGNORED / OWNER-ADMIN.

| Kolom berkas | Kategori | Kolom tujuan | Aturan | Contoh |
| --- | --- | --- | --- | --- |
| `nis` | **REQUIRED** | `students.nis` | maks 20, unik dalam cabang | `2024001` |
| `nisn` | OPTIONAL | `students.nisn` | tepat 10 digit bila diisi | `0011223344` |
| `nama_lengkap` | **REQUIRED** | `students.full_name` | maks 150 | `Siti Placeholder` |
| `jenis_kelamin` | **REQUIRED** | `students.gender` | `L` atau `P`, diubah ke huruf besar | `P` |
| `tempat_lahir` | OPTIONAL | `students.birth_place` | maks 100 | `Bandung` |
| `tanggal_lahir` | OPTIONAL | `students.birth_date` | `YYYY-MM-DD`; serial Excel dikonversi otomatis | `2008-05-17` |
| `agama` | OPTIONAL | `students.religion` | maks 30, teks bebas | `Islam` |
| `alamat` | OPTIONAL | `students.address` | teks | `Jl. Contoh No. 1` |
| `nama_orang_tua` | OPTIONAL | `students.parent_name` | maks 150 | `Bapak Placeholder` |
| `hp_orang_tua` | OPTIONAL | `students.parent_phone` | maks 20, **tanpa validasi format** | `081200000000` |
| `email_orang_tua` | OPTIONAL | `students.parent_email` | rule `email`, maks 150 | `ortu@example.test` |
| `tahun_masuk` | OPTIONAL | `students.entry_year` | 1900–2200 | `2022` |
| `status` | **REQUIRED** | `students.status` | `ACTIVE`/`GRADUATED`/`DROPPED_OUT`/`TRANSFERRED`; kosong → `ACTIVE` | `ACTIVE` |
| `kelas` | **LOOKUP** — *belum terbaca* | `student_classes.class_id` | Dicari pada `classes.name` di tahun ajaran tujuan; wajib tepat satu hasil | `X IPA 1` |
| `tahun_ajaran` | **LOOKUP** — *belum terbaca* | `student_classes.academic_year_id` | Dicari pada `academic_years.name`; **memuat semesternya** | `2024/2025 Semester 1` |
| `email_akun_siswa` | **OWNER-ADMIN** — *belum terbaca* | `students.user_id` | Menuntut akun `users` yang sudah ada berperan Siswa | `siswa@example.test` |
| `email_akun_ortu` | **OWNER-ADMIN** — *belum terbaca* | `students.parent_user_id` | Menuntut akun `users` berperan Orang Tua | `ortu@example.test` |

**IGNORED** — kolom yang boleh ada di berkas sekolah dan sengaja tidak dipetakan:
nomor urut, foto, ranking, catatan wali kelas, dan seluruh kolom di luar daftar
di atas. Importer membacanya sebagai kunci array yang tidak dipakai dan tidak
menggagalkan baris.

**DERIVED** — tidak boleh ada di berkas, ditentukan sistem: `students.id`,
`students.school_id` (dari `--school`), `created_at`/`updated_at`, dan
`student_classes.status` (selalu `ACTIVE` untuk penempatan berjalan).

> Empat kolom terakhir (`kelas`, `tahun_ajaran`, `email_akun_siswa`,
> `email_akun_ortu`) **tidak dibaca oleh kode mana pun hari ini.** Keduanya ada
> di template karena sekolah sebaiknya mengumpulkannya sekali jalan, bukan dua
> kali. Selama importer belum diperluas, isinya hanya menjadi bahan langkah
> manual — dan itu harus dikatakan apa adanya kepada sekolah, bukan dibiarkan
> tampak otomatis.

Kolom `semester` sengaja **tidak** ada. Satu baris `academic_years` sudah
mewakili satu semester dan namanya memuatnya (butir 404); kolom terpisah akan
menjadi sumber kebenaran kedua yang dapat bertentangan.

---

## 6. Strategi duplikat dan idempotensi

Syaratnya: berkas yang sama boleh diunggah dua kali tanpa menghasilkan siswa,
akun, orang tua, atau penempatan kelas ganda.

### 6.1 Kunci alami per tabel

| Tabel | Kunci pencocokan | Ditegakkan basis data? |
| --- | --- | --- |
| `schools` | `code` | Ya |
| `academic_years` | (`school_id`, `name`) | **Tidak** |
| `subjects` | (`school_id`, `code`) | Ya |
| `classes` | (`school_id`, `academic_year_id`, `name`) | **Tidak** |
| `users` | `email` | Ya, global |
| `students` | (`school_id`, `nis`) | Ya |
| `student_classes` | (`student_id`, `academic_year_id`) dengan `status = ACTIVE` | **Tidak** |
| `class_subjects` | (`class_id`, `subject_id`, `academic_year_id`) | Ya |

### 6.2 Aturan

1. **Cocokkan, jangan buat buta.** Setiap penulisan didahului pencarian pada
   kunci alami di atas. Baris yang cocok diperbarui atau dilewati; tidak pernah
   digandakan.
2. **Nol atau satu, selain itu berhenti.** Untuk empat kunci yang *tidak*
   dijamin basis data (`academic_years`, `classes`, `student_classes`,
   dan pencocokan NISN), hasil ganda adalah galat baris — bukan alasan untuk
   mengambil `first()`. Data sekolah yang rapi tidak akan memicunya; data yang
   tidak rapi harus terlihat, bukan terserap diam-diam.
3. **NIS adalah identitas, nama tidak.** Dua siswa boleh bernama sama. Tidak ada
   pencocokan yang boleh berdasarkan `full_name`, sendirian maupun sebagai
   bagian kombinasi. Bila NIS belum dimiliki sekolah, NIS harus **diputuskan
   lebih dulu** oleh sekolah — bukan diarang importer.
4. **NISN sebagai pemeriksaan silang, bukan kunci utama.** NISN stabil secara
   nasional dan berguna untuk mendeteksi satu siswa yang terdaftar dengan dua
   NIS berbeda. Karena tidak unik di basis data, ia dipakai untuk **memperingatkan**,
   bukan untuk memutuskan.
5. **Perpindahan kelas bukan duplikasi.** Menempatkan siswa ke kelas baru berarti
   menandai baris lama `MOVED` lalu menulis baris `ACTIVE` baru — bukan menghapus,
   dan bukan menulis dua baris `ACTIVE`. Histori itu memang disengaja skemanya.
6. **Akun tidak pernah dibuat ulang.** Pencocokan pada `email`. Bila alamatnya
   sudah dipakai di cabang lain, itu **galat yang harus dilaporkan**, bukan
   sesuatu yang diselesaikan importer — lihat §2 butir 1.
7. **Kata sandi tidak pernah ikut berkas.** Akun baru dibuat dengan
   `must_change_password = true`; kata sandi awal disalurkan lewat jalur di luar
   sistem. Tidak ada kolom kata sandi di template, dan tidak boleh ditambahkan.

---

## 7. Strategi validasi dan pelaporan galat

Prinsipnya satu: **tidak ada baris yang ditulis sebelum seluruh berkas selesai
diperiksa.** Importer yang ada sekarang tidak begitu — ia menulis sambil jalan —
dan itulah alasan utama dry-run dibutuhkan sebelum data sungguhan masuk.

### 7.1 Dua fase

| Fase | Yang dikerjakan | Yang ditulis |
| --- | --- | --- |
| **Periksa** | Baca seluruh baris, normalisasi, validasi, resolusi seluruh LOOKUP, deteksi duplikat dalam-berkas dan terhadap basis data | Tidak ada |
| **Terap** | Hanya berjalan bila fase periksa nol galat, di dalam satu transaksi | Seluruhnya, atau tidak sama sekali |

### 7.2 Kategori temuan

| Tingkat | Arti | Contoh |
| --- | --- | --- |
| `GALAT` | Baris tidak dapat ditulis; seluruh berkas ditahan | NIS kosong, `jenis_kelamin` selain L/P, kelas tidak ditemukan, kelas ganda |
| `PERINGATAN` | Baris dapat ditulis, tetapi manusia perlu melihatnya | NISN kosong, NISN sama pada dua NIS berbeda, tahun masuk lebih baru dari tahun ajaran |
| `LEWAT` | Sudah ada dan identik; tidak ada yang perlu dikerjakan | NIS sudah terdaftar dengan data yang sama |
| `PERBARUI` | Sudah ada dengan isi berbeda | Alamat berubah |

Laporan menyebut **nomor baris berkas** (baris 1 adalah judul, jadi data mulai
di baris 2 — konvensi yang sudah dipakai `StudentsImport` dan `GradesImport`),
nama kolom, nilai yang ditolak, dan alasannya. Nilai yang dicetak ke layar
dipotong secukupnya: laporan galat adalah tempat PII paling mudah bocor ke
catatan terminal dan tiket.

---

## 8. Urutan import

Diminta batch ini: `school → academic year → class/rombel → students →
parents/accounts → class membership → subjects/teacher assignments`.

Urutan itu benar di garis besarnya, tetapi grafik foreign key menuntut dua
penyesuaian, dan keduanya bukan preferensi:

- `classes.homeroom_teacher_id` menunjuk `users`. Wali kelas karena itu harus
  ada **sebelum** kelasnya, bila kelas dibuat lengkap dengan wali kelasnya.
- `class_subjects.teacher_id` **tidak nullable**. Guru pengampu wajib ada
  sebelum baris kelas-mapel mana pun ditulis.

Urutan yang benar:

| # | Langkah | Bergantung pada | Idempoten lewat |
| --- | --- | --- | --- |
| 1 | Cabang | — | `schools.code` |
| 2 | Tahun ajaran (+ semester, tandai satu aktif) | 1 | (`school_id`, `name`) |
| 3 | Akun guru & staf | 1 | `users.email` |
| 4 | Mata pelajaran | 1 | (`school_id`, `code`) |
| 5 | Kelas / rombel (+ wali kelas) | 1, 2, 3 | (`school_id`, `academic_year_id`, `name`) |
| 6 | **Siswa** | 1 | (`school_id`, `nis`) |
| 7 | Akun siswa & orang tua | 1 | `users.email` |
| 8 | Penautan `students.user_id` / `parent_user_id` | 6, 7 | idempoten menurut sifatnya |
| 9 | Penempatan kelas (`student_classes`) | 2, 5, 6 | (`student_id`, `academic_year_id`, `ACTIVE`) |
| 10 | Kelas-mapel + guru pengampu | 2, 3, 4, 5 | (`class_id`, `subject_id`, `academic_year_id`) |

Langkah 1–5 adalah **penyiapan struktur** dan dikerjakan di panel oleh Admin
Sekolah; jumlahnya kecil dan keputusannya milik sekolah. Langkah 6 sudah dapat
dikerjakan importer yang ada hari ini. Langkah 7–10 belum punya jalur massal
(§1.3) dan hari ini berarti pekerjaan manual di panel.

Nilai, tagihan, pembayaran, dan rapor historis **tidak** ada dalam lingkup M0.
Memindahkan riwayat akademik adalah keputusan tersendiri dengan konsekuensinya
sendiri, dan tidak diminta.

---

## 9. Strategi PPDB legacy (Google Sheets + Google Drive)

### 9.1 Satu kali, terkendali — bukan sinkronisasi

Yang dipilih: **ekspor satu kali**, dipicu manusia, dengan hasil yang dapat
diperiksa sebelum diterapkan. Bukan integrasi Google API.

Alasannya bukan kemalasan:

- Sinkronisasi permanen menuntut kredensial OAuth Google yang hidup di server —
  satu rahasia baru untuk dijaga, dirotasi, dan dicabut, demi proses yang
  terjadi **sekali setahun**.
- Sinkronisasi dua arah memunculkan pertanyaan "sumber kebenaran mana yang
  menang" yang tidak dijawab dokumen sumber mana pun.
- Google Form dapat diubah kapan saja oleh siapa saja yang memegang berkasnya.
  Skema yang dapat berubah di luar kendali bukan dasar yang baik untuk impor
  otomatis.
- Aplikasinya sudah punya formulir PPDB sendiri (`/ppdb/{schoolCode}`). Google
  Form adalah warisan yang sedang ditinggalkan, bukan pasangan jangka panjang.

Karena itu: **tidak ada integrasi Google API yang ditulis**, dan tidak
direkomendasikan.

### 9.2 Dua jalur, dan cara memilih

Pendaftar PPDB yang sudah **diterima** dan akan langsung menjadi siswa aktif
tidak perlu melewati alur PPDB sama sekali. Ada dua jalur, dan pilihannya
keputusan pemilik:

| Jalur | Cara | Untung | Rugi |
| --- | --- | --- | --- |
| **A — langsung siswa** | Baris Sheet dipetakan ke template §4, masuk lewat import siswa | Sederhana; satu jalur yang sama dengan siswa X/XI/XII | Riwayat pendaftaran tidak tersimpan; `ppdb_registrations` kosong untuk angkatan itu |
| **B — lewat PPDB** | Baris ditulis sebagai `ppdb_registrations` berstatus `PASSED`, lalu di-enroll | Riwayat pendaftaran dan berkasnya tersimpan; statistik PPDB benar | Menuntut `reg_number` unik yang harus dibuat; enroll masih satu per satu |

Rekomendasi: **Jalur B untuk angkatan PPDB berjalan**, karena itulah satu-satunya
cara `documents`, `origin_school`, dan `registered_at` punya tempat — dan
statistik PPDB di dasbor menjadi masuk akal. Jalur A untuk siswa lama X/XI/XII,
yang memang tidak pernah mendaftar lewat sistem ini.

Keputusan ini **belum diambil**. Lihat §11.

### 9.3 Pemetaan kolom Google Sheet → `ppdb_registrations`

| Kolom Sheet (perkiraan) | Kolom tujuan | Kategori |
| --- | --- | --- |
| Nama lengkap | `full_name` | REQUIRED |
| Jenis kelamin | `gender` | REQUIRED, normalisasi ke `L`/`P` |
| Tanggal lahir | `birth_date` | OPTIONAL |
| Asal sekolah | `origin_school` | OPTIONAL |
| Nama orang tua | `parent_name` | OPTIONAL |
| No. HP | `parent_phone` | OPTIONAL |
| Email | `parent_email` | OPTIONAL |
| Timestamp Google Form | `registered_at` | REQUIRED |
| Tautan berkas Drive | `documents` | LOOKUP — lihat §9.4 |
| — | `reg_number` | DERIVED — `[KODE]-[TAHUN]-[SEQ]`, unik global |
| — | `school_id` | DERIVED — dari `schools.code` |
| — | `academic_year_id` | LOOKUP |
| — | `status` | DERIVED — `PASSED` untuk yang sudah diterima |

`reg_number` `unique()` **tanpa** batas cabang. Penomorannya sudah membawa kode
cabang di depan, jadi bentrok antar cabang tidak terjadi selama polanya
dipatuhi — tetapi migrasi tetap harus memeriksanya, bukan mengandalkannya.

### 9.4 Berkas Google Drive — dan satu peringatan privasi

`ppdb_registrations.documents` adalah JSON berisi **path**, bukan URL Drive.
Formulir yang ada menyimpannya lewat
`$document->store('ppdb/'.Str::lower($school->code), 'public')`.

Disk-nya dulu **`public`**: setelah `storage:link`, isinya dapat diakses siapa
pun yang mengetahui atau menebak path-nya, tanpa login. Hal itu berjalan begitu
sejak Sprint 3, dan menjadi temuan M-1 audit ini.

**Sudah ditutup pada Batch M0.1.** Unggahan PPDB kini mendarat di disk privat
`local` (`storage/app/private`) — disk yang sama dengan bukti pembayaran dan
bukti transaksi kas — dan satu-satunya jalan menuju berkasnya adalah rute
berwenang milik panel, `filament.admin.ppdb.document`. Bentuk nilai `documents`
tidak berubah sedikit pun, sehingga tidak ada migrasi basis data yang
dibutuhkan; berkas lama dipindahkan `php artisan ppdb:privatize-documents`.
Rinciannya di **`docs/ppdb-document-storage.md`**.

Konsekuensinya untuk migrasi legacy: berkas Drive **boleh** dimigrasikan, tetapi
lewat kontrak yang sudah ditetapkan — divalidasi dengan aturan yang sama
(JPG/PNG/PDF, maks 2 MB, maks 5 berkas), disimpan lewat
`PpdbDocument::directoryFor()` pada disk privat, dan jalurnya ditulis ke
`documents` dalam bentuk yang sama. Tautan Drive sendiri tidak pernah disimpan
di kolom itu. Lihat `docs/ppdb-document-storage.md` §7.

Sampai migrasi berkas benar-benar dijalankan, `documents` dibiarkan kosong untuk
baris hasil migrasi. Data pendaftarannya tetap masuk; berkasnya menyusul.

---

## 10. Rancangan perintah dry-run (belum diimplementasikan)

Rancangan, bukan janji. Ditulis supaya bentuknya sudah disepakati sebelum kolom
data sungguhan diketahui — dan **tidak** ditulis sebagai kode, karena
transformasi terhadap kolom yang belum pernah dilihat adalah tebakan.

```
php artisan migrasi:siswa {berkas}
        --school=PUSAT          # kode cabang, wajib; bukan id numerik
        --tahun-ajaran="2024/2025 Semester 1"
        --dry-run               # bawaan; --terapkan untuk benar-benar menulis
        --laporan=path.csv      # opsional
```

Ketentuan yang mengikat rancangan ini:

- `--dry-run` adalah **bawaan**. Menulis menuntut `--terapkan` yang diketik
  sadar. Pola pagar yang sama sudah dipakai `ops/restore-database.sh`.
- `--school` menerima **kode**, tidak pernah id. Kode tidak ditemukan → berhenti
  dengan exit code bukan nol, tanpa menulis apa pun.
- Tanpa sesi login, `SchoolScope` tidak aktif (§3). Setiap query di dalam
  perintah ini membawa `school_id`-nya sendiri secara eksplisit.
- Fase terap berjalan dalam satu `DB::transaction`.
- Ringkasannya menyebut jumlah per kategori §7.2 dan **tidak** mencetak baris
  data utuh ke layar.

Perintah ini dibuat ketika berkas sungguhan sudah dilihat, dan dites saat itu
juga — bukan sekarang.

---

## 11. Keputusan yang menunggu pemilik

Tidak satu pun diputuskan sendiri.

| | Perkara | Mengapa bukan keputusan pengembang |
| --- | --- | --- |
| ~~M-1~~ | ~~**Berkas PPDB di disk `public`**~~ — **SELESAI pada Batch M0.1.** Berkas PPDB adalah data aplikasi yang privat dan tidak dipaparkan sebagai URL penyimpanan publik. Tidak ada keputusan pemilik yang dibutuhkan: ini pengerasan keamanan, bukan perubahan produk | `docs/ppdb-document-storage.md` |
| M-2 | **Jalur A atau B** untuk angkatan PPDB berjalan (§9.2) | Menentukan apakah riwayat pendaftaran dan statistik PPDB tersimpan |
| M-3 | **Penomoran NIS** untuk siswa lama yang belum punya | Kewenangan sekolah; importer tidak boleh mengarang identitas |
| M-4 | **Akun portal siswa & orang tua**: dibuatkan untuk seluruh siswa sekaligus, atau bertahap | Menentukan besarnya langkah 7 §8, dan bagaimana kata sandi awal disalurkan |
| M-5 | **Orang tua dengan anak di lebih dari satu cabang** (§2 butir 1) | Skema hari ini tidak mengizinkannya; jalan keluarnya keputusan produk |
| M-6 | **NIK** — dikumpulkan atau tidak | Tidak ada kolomnya, dan menambahkannya adalah keputusan kepatuhan |
| M-7 | **Riwayat akademik** (nilai/tagihan/rapor tahun-tahun sebelumnya) — ikut dimigrasikan atau tidak | Di luar lingkup M0; menuntut keputusan tersendiri |

---

## 12. Yang masih dibutuhkan dari sekolah

Sebelum migrasi dapat dijalankan, bukan sebelum dapat direncanakan:

1. Daftar siswa aktif X, XI, XII dalam satu berkas mengikuti
   `docs/templates/import-siswa-aktif.csv`.
2. Kepastian **NIS setiap siswa** (M-3).
3. Daftar kelas/rombel berjalan beserta wali kelasnya.
4. Nama tahun ajaran berjalan **beserta semesternya**, persis seperti yang akan
   dipakai di sistem.
5. Daftar mata pelajaran + kode, dan guru pengampu per kelas.
6. Daftar guru dan staf beserta alamat surel — surel unik lintas cabang.
7. Ekspor Google Sheet PPDB berjalan, lengkap dengan kolom timestamp.
8. Jawaban atas M-1 sampai M-7.

---

## 13. Keamanan dan privasi

Aturan yang berlaku sejak sekarang, bukan setelah data datang:

- **Berkas data sungguhan tidak pernah masuk repositori.** Bukan di `docs/`,
  bukan di `storage/`, bukan sebagai lampiran commit. Tempat yang disarankan:
  `C:\migration-private\` — di luar `C:\magang\SmartSuksesSchool` sepenuhnya,
  sehingga tidak ada aturan `.gitignore` yang perlu diandalkan untuk melindunginya.
- **Tidak ada PII di test.** Diperiksa pada batch ini dan bersih: seluruh nomor
  telepon di test berbentuk `081234567890` dan sejenisnya, dan seluruh NISN
  berupa digit berulang (`1111111111`). Pola itu dipertahankan.
- **Tidak ada NIK, NISN, nomor telepon, atau alamat surel sungguhan** di test,
  factory, seeder, dokumentasi, pesan commit, maupun laporan galat yang
  ditempelkan ke tiket.
- **Tidak ada kredensial produksi** yang dipakai untuk menjalankan migrasi dari
  mesin pengembang. Migrasi produksi dijalankan di server, oleh yang berwenang.
- **Laporan dry-run juga berisi PII.** Berkas `--laporan` diperlakukan sama
  dengan berkas sumbernya: di luar repositori, dihapus setelah selesai.
- `.gitignore` diperkuat pada batch ini dengan pola spreadsheet di akar
  repositori. Itu **jaring pengaman, bukan izin**: tempat berkasnya tetap di
  luar repositori.

---

## 14. Yang **tidak** dikerjakan batch ini

- Tidak ada data sungguhan yang diminta, diterima, atau disimpan.
- Tidak ada migrasi basis data yang dibuat. Skema tidak berubah sama sekali.
- Tidak ada kolom baru, termasuk NIK dan `semester` pada `report_cards`.
- Tidak ada perintah artisan baru — §10 adalah rancangan.
- Tidak ada `UserImport`, tidak ada bulk enroll, tidak ada penempatan kelas
  otomatis.
- Tidak ada integrasi Google API.
- Tidak ada perubahan pada `StudentsImport`. (Disk penyimpanan berkas PPDB
  **kemudian** diubah pada Batch M0.1 — lihat `docs/ppdb-document-storage.md`.)
- Tidak ada test baru: tidak ada kode yang berubah, dan test yang hanya
  membuktikan keberadaan dokumen tidak membuktikan apa pun.
