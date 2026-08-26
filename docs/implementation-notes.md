# Catatan Implementasi

Catatan penyimpangan implementasi terhadap `smartsukses-docs/`. Folder
`smartsukses-docs/` adalah satu-satunya sumber requirement dan **tidak diubah**;
dokumen ini hanya mencatat titik-titik di mana kode sengaja berbeda dari ERD,
beserta alasan dan referensi dokumennya.

> Mencari cara **menjalankan demo**-nya, bukan alasannya? Lihat
> [`demo-sprint-4.md`](demo-sprint-4.md) — seeder, queue worker, akun demo, dan urutan
> peragaan Input Nilai → Import → Generate Rapor → Terbitkan → PDF.

## Phase 2 — Authentication & RBAC

### 1. Kolom `users.must_change_password`

| | |
| --- | --- |
| **Status** | Tambahan di luar ERD |
| **Referensi** | `03-architecture/04-security.md` — baris "Password" |
| **Bertentangan dengan** | `02-erd/02-tables-core.md` — Tabel: users |

Dokumen keamanan mensyaratkan: *"Argon2id (Laravel default) | Minimum 8 karakter;
password pertama wajib diganti saat login pertama."* ERD tidak menyediakan atribut
yang merepresentasikan status tersebut, sedangkan requirement-nya menuntut penanda
persisten per pengguna — bertahan lintas sesi dan lintas perangkat, sehingga tidak
bisa dititipkan ke session.

Kolom: `BOOLEAN NOT NULL DEFAULT 0`. Penegakan pada
`app/Http/Middleware/EnsurePasswordIsChanged.php` (mengunci seluruh halaman panel ke
halaman profil selama flag menyala) dan aksi "Reset Password" pada
`app/Filament/Resources/UserResource.php` (menyalakan flag saat admin membuat password
sementara). Flag padam otomatis begitu pengguna mengganti passwordnya sendiri
(`App\Models\User::booted()`).

**Jika ERD ingin dipertahankan literal:** hapus kolom dari migration, middleware dari
`AdminPanelProvider`, dan hook `booted()` di model User. Requirement 3.4 poin
"password pertama wajib diganti" otomatis tidak terpenuhi.

### 2. Tabel spatie lebih banyak daripada yang didaftar ERD

| | |
| --- | --- |
| **Status** | Konsekuensi paket, bukan keputusan desain |
| **Referensi** | `02-erd/02-tables-core.md` — *"mengikuti skema standar spatie/laravel-permission"* |

`02-erd/01-entity-list.md` hanya mendaftar `roles` dan `model_has_roles`. Skema standar
spatie membuat lima tabel: ditambah `permissions`, `model_has_permissions`, dan
`role_has_permissions`. Tiga tabel ekstra ini tidak dipakai untuk data bisnis dan tidak
memiliki `school_id` — definisi peran & izin bersifat platform-wide.

### 3. Tabel `personal_access_tokens`

| | |
| --- | --- |
| **Status** | Di luar 21 tabel ERD |
| **Referensi** | `03-architecture/01-tech-stack.md`; `04-api/01-conventions-and-auth.md` |

Dibawa oleh `laravel/sanctum` yang diwajibkan tech stack (`Authorization: Bearer {token}`).
Tabelnya sudah dimigrasikan sebagai fondasi; endpoint `/api/v1/auth/*` sendiri belum
diimplementasikan (modul terpisah).

### 4. DEFAULT pada `schools.primary_color` & `schools.secondary_color`

| | |
| --- | --- |
| **Status** | Penyimpangan kecil / kosmetik |
| **Referensi** | `02-erd/02-tables-core.md`; `03-architecture/02-multi-tenant.md` §3.2.3 |

ERD menandai kedua kolom NOT NULL tanpa default. Implementasi memberi default
`#1B3A6B` dan `#E07020` — nilai yang dicontohkan ERD dan bagian white-label theming —
supaya pembuatan cabang baru tanpa konfigurasi warna tidak gagal dan UI selalu punya
warna yang valid untuk di-inject sebagai CSS variable.

### 5. Konfigurasi di luar ERD

| Konfigurasi | Nilai | Referensi |
| --- | --- | --- |
| `SESSION_LIFETIME` | `480` (8 jam idle) | `01-prd/02-features-phase1.md` — AUTH-01 poin 4 |
| `HASH_DRIVER` | `argon2id` | `03-architecture/04-security.md` — baris "Password" |

Keduanya setelan `.env`, tidak menyentuh skema database.

## Catatan lain

- **Paket `spatie/laravel-multitenancy` tidak dipasang** meski tercantum di tech stack 3.1.
  Alur identifikasi tenant pada `03-architecture/02-multi-tenant.md` §3.2.2 berbasis
  `users.school_id` setelah login — bukan domain/subdomain — sehingga kebutuhannya
  terpenuhi oleh Eloquent Global Scope, persis seperti yang dicontohkan dokumen itu
  sendiri (`App\Models\Scopes\SchoolScope`). Paket tersebut tetap bisa ditambahkan
  belakangan bila dibutuhkan tenancy yang sadar queue/job.
- **Test suite berjalan di SQLite in-memory** (`phpunit.xml`), sedangkan runtime memakai
  MySQL 8. Verifikasi perilaku spesifik MySQL perlu smoke test terpisah — lihat
  bagian *Menjalankan test terhadap MySQL* di akhir dokumen ini.

## Sprint 2 — Master Data (Core SIS)

Cakupan mengikuti roadmap `05-roadmap/01-implementation-order.md` Sprint 2 "Core SIS":
Data siswa, Tahun Ajaran, Kelas & Jadwal, Mata Pelajaran, Import Excel. Tujuh tabel
kelompok Akademik pada ERD 2.2 diimplementasikan sesuai definisi kolomnya.

### 6. Unique index yang tidak tercantum di ERD

ERD 2.2 hanya menandai kolom dengan `IX` (index biasa) dan tidak mendefinisikan
unique constraint. Tiga unique index ditambahkan agar aturan pada PRD benar-benar
ditegakkan di level database:

| Tabel | Unique index | Dasar |
| --- | --- | --- |
| `students` | `(school_id, nis)` | ERD 2.2: NIS "lokal, **unik per sekolah**"; SIS-01 poin 3: "NIS unik dalam satu sekolah" |
| `classes` | `(academic_year_id, homeroom_teacher_id)` | KELAS-01 poin 3: "Satu guru hanya boleh menjadi wali kelas satu kelas per tahun ajaran" |
| `class_subjects` | `(class_id, subject_id, academic_year_id)` | **Tidak dinyatakan eksplisit di dokumen** — lihat catatan di bawah |

Dua yang pertama menegakkan kalimat yang tertulis apa adanya di dokumen. Yang ketiga
adalah **satu-satunya asumsi** di Sprint 2: ERD menyebut `class_subjects` sebagai
"Dasar untuk input nilai dan jadwal", dan satu mata pelajaran yang muncul dua kali di
kelas yang sama pada tahun ajaran yang sama akan membuat nilai akhir ambigu. Bila
kebijakan sekolah memang mengizinkan team teaching dengan dua baris terpisah,
constraint ini perlu dicabut dari
`database/migrations/2026_08_07_170500_create_class_subjects_table.php`.

### 7. Pemetaan izin: tidak ada izin baru yang dibuat

Matriks izin PRD 1.1.2 tidak memiliki baris tersendiri untuk Tahun Ajaran, Mata
Pelajaran, maupun penugasan guru. Kelimanya diperlakukan sebagai satu modul
**"Kelas & Jadwal"** dan memakai pasangan izin `class_schedule.view` /
`class_schedule.manage` yang sudah ada:

| Entitas | Izin |
| --- | --- |
| `students` | `student.view` / `student.manage` |
| `academic_years`, `subjects`, `classes`, `class_subjects`, `schedules` | `class_schedule.view` / `class_schedule.manage` |

Konsekuensi yang perlu diketahui: BENDAHARA (Kelas & Jadwal ❌) tidak dapat membuka
Tahun Ajaran dan Mata Pelajaran, sedangkan SISWA (⭕) dapat melihatnya. Ini konsisten
dengan matriks, tetapi bila nanti Tahun Ajaran/Mata Pelajaran dianggap modul
tersendiri, matriks PRD perlu diperbarui lebih dulu — bukan kodenya.

### 8. Tabel tanpa `updated_at`

ERD 2.2 hanya mencantumkan `created_at` untuk `subjects`, `student_classes`,
`class_subjects`, dan `schedules`. Migration mengikuti apa adanya, dan modelnya
menyetel `public const UPDATED_AT = null`. Akibatnya perubahan pada keempat tabel itu
tidak memiliki jejak waktu — relevan untuk requirement audit log NFR 1.4 yang belum
diimplementasikan (lihat butir 11).

### 9. Nama model `SchoolClass` untuk tabel `classes`

`Class` adalah reserved word PHP sehingga model diberi nama `SchoolClass` dengan
`protected $table = 'classes'`. Nama tabel tetap persis seperti ERD.

### 10. Keputusan implementasi yang mengikuti requirement, bukan ERD

- **Hapus siswa dari kelas** (API 4.6 `DELETE /classes/{id}/students/{studentId}`)
  diimplementasikan sebagai perubahan `status` menjadi `MOVED`, bukan penghapusan
  baris — supaya histori perpindahan tetap ada, sesuai keberadaan enum `MOVED` di ERD.
- **Siswa tidak pernah di-hard delete** (SIS-02 poin 2). `StudentPolicy::delete()`
  selalu mengembalikan `false`; penonaktifan lewat perubahan `status`.
- **Resize foto 400×400** (SIS-03) dilakukan di sisi browser oleh Filament
  (`imageResizeTargetWidth/Height`), sehingga berkas yang tersimpan sudah 400×400.
- **`grade_level` dibatasi 10/11/12** persis seperti ERD 2.2. Cabang jenjang SMP/SD
  akan membutuhkan perubahan dokumen lebih dulu.

### 11. Format berkas Import Excel tidak didefinisikan dokumen

`04-api/03-students-sis.md` menyebut `POST /students/import` tetapi tidak menentukan
nama kolomnya. Template yang dipakai `App\Imports\StudentsImport` — dan ditampilkan
di modal import — adalah: `nis`, `nisn`, `nama_lengkap`, `jenis_kelamin`,
`tempat_lahir`, `tanggal_lahir`, `agama`, `alamat`, `nama_orang_tua`, `hp_orang_tua`,
`email_orang_tua`, `tahun_masuk`, `status`. Baris yang gagal validasi dilaporkan per
nomor baris dan dilewati, sesuai pola "Return: sukses + daftar error baris" pada
API 4.4. Nama berkas export mengikuti SIS-05 poin 2:
`siswa_[kode_sekolah]_[tanggal].xlsx`.

### 12. Ekstensi PHP `zip` diaktifkan di instalasi Laragon

`maatwebsite/excel` (PhpSpreadsheet) mewajibkan `ext-gd` **dan** `ext-zip`. Pada
PHP Laragon 8.3.30 yang dipakai project ini `gd` sudah aktif, `zip` belum. Baris 832
`C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.ini` diubah dari `;extension=zip`
menjadi `extension=zip`; cadangan tersimpan sebagai `php.ini.bak-before-zip`.
Instalasi XAMPP tidak disentuh.

## Sprint 3 — PPDB

Cakupan mengikuti roadmap `05-roadmap/01-implementation-order.md` Sprint 3 "PPDB":
Form PPDB publik, Review Admin, Status update, wa.me link generator, Enroll siswa —
yaitu PRD 1.2.3 PPDB-01 s/d PPDB-05, ERD 2.2 kelompok PPDB, dan API 4.7.
Satu tabel (`ppdb_registrations`) diimplementasikan sesuai definisi kolomnya.

### 13. Kode cabang dipotong di dalam `reg_number`

| | |
| --- | --- |
| **Status** | Konsekuensi aritmetika ERD, bukan pilihan desain |
| **Referensi** | `02-erd/04-tables-ppdb.md` — reg_number; `02-erd/02-tables-core.md` — schools.code |

ERD memberi `reg_number` VARCHAR(20) dengan pola `[KODE_CABANG]-[TAHUN]-[SEQ]`,
sementara `schools.code` sendiri VARCHAR(20). Kedua batas itu tidak mungkin
dipenuhi bersamaan: `-[TAHUN]-[SEQ]` sudah memakan 10 karakter. Kode cabang karena
itu dipotong ke **10 karakter pertama** (`App\Models\PpdbRegistration::CODE_LENGTH`).
Cabang contoh pada dokumen (MADANI, PUSAT, CINANGKA — maksimal 8 karakter) tidak
terpengaruh sama sekali.

`[SEQ]` dipakai 4 digit dengan zero-padding, berjalan **per cabang per tahun**.
Nomor berikutnya dihitung dari nomor terbesar yang sudah ada; unique index
`reg_number` menolak tabrakan dan nomor dihitung ulang (maksimal 5 percobaan).
Pada skala dokumen (50–200 siswa per cabang, Ringkasan Eksekutif) 4 digit lebih
dari cukup; bila suatu saat lewat 9999, pengurutan berbasis string perlu diganti
kolom counter tersendiri.

### 14. "Cabang yang membuka PPDB" dipetakan ke `schools.is_active`

| | |
| --- | --- |
| **Status** | Asumsi — satu-satunya di Sprint 3 |
| **Referensi** | `04-api/05-ppdb.md` — GET /ppdb/schools |

API menyebut "Daftar cabang yang membuka PPDB", tetapi ERD tidak memiliki atribut
yang menyatakan periode PPDB dibuka/ditutup. Implementasi memakai `schools.is_active`
(ERD: "Status aktif tenant") sebagai penentu: cabang aktif muncul di `/ppdb` dan
menerima pendaftaran, cabang non-aktif menghasilkan 404. Bila sekolah nanti perlu
membuka/menutup PPDB terpisah dari status tenant, ERD perlu kolom baru lebih dulu.

### 15. Endpoint `/ppdb/{schoolCode}/info` baru terpenuhi sebagian

| | |
| --- | --- |
| **Status** | Belum selesai — menunggu keputusan dokumen |
| **Referensi** | `04-api/05-ppdb.md` — GET /ppdb/{schoolCode}/info |

API menjanjikan "Info PPDB satu cabang: **syarat, jadwal, kuota**". Tidak satu pun
dari ketiganya punya kolom di ERD 2.2 (baik di `schools` maupun `ppdb_registrations`),
dan roadmap Sprint 3 tidak menyebutnya. Halaman `/ppdb/[kode_sekolah]` karena itu
menampilkan informasi cabang yang **memang ada** di ERD — nama, kode, alamat,
telepon, email, dan tahun ajaran aktif — tanpa mengarang kolom baru. Syarat/jadwal/kuota
membutuhkan penambahan kolom (atau tabel) di ERD lebih dulu.

### 16. Field yang wajib di formulir publik meski nullable di ERD

| Field | Alasan wajib |
| --- | --- |
| `birth_date` | API 4.7 GET /ppdb/check-status: "berdasarkan nomor daftar **+ tanggal lahir**" — tanpa tanggal lahir, PPDB-02 tidak bisa dijalankan untuk pendaftar itu |
| `parent_name` | PPDB-01 poin 2 mencantumkan "nama ortu" sebagai field formulir |
| `parent_phone` | PPDB-01 poin 2 ("no. HP") dan PPDB-04 — tanpa nomor HP, link wa.me tidak dapat digenerate |

Kolomnya tetap NULLable persis ERD; yang diperketat hanya validasi formulir publik
(`App\Livewire\Ppdb\RegistrationForm::rules()`). Baris yang masuk lewat jalur lain
(mis. seed/import) tetap boleh kosong, dengan konsekuensi cek status dan link WA
tidak tersedia untuk baris tersebut.

`origin_school` dan `parent_email` dibiarkan opsional, sesuai NULL pada ERD.

### 17. Berkas PPDB disimpan di disk `public`

| | |
| --- | --- |
| **Status** | Penyimpangan yang diteruskan dari Sprint 2 |
| **Bertentangan dengan** | `03-architecture/04-security.md` — File Upload: "disimpan di storage/ (di luar web root)" |

`ppdb_registrations.documents` menyimpan path pada disk `public`
(`storage/app/public/ppdb/[kode_cabang]/…`), mengikuti pola yang sudah dipakai
`students.photo_url` di Sprint 2. Validasi MIME dan ukuran sudah sesuai dokumen
keamanan (hanya JPG/PNG/PDF, maksimal 2 MB, maksimal 5 berkas), tetapi berkasnya
dapat diakses siapa pun yang menebak URL-nya. Untuk mematuhi 3.4 secara penuh,
kedua modul perlu dipindahkan bersama ke disk privat plus route pengunduhan
ber-otorisasi — perubahan lintas-sprint yang sengaja tidak dilakukan di sini.

### 18. Pemetaan izin: tidak ada izin baru yang dibuat

`ppdb.view` / `ppdb.manage` sudah ada sejak Sprint 1 (`App\Enums\PermissionName`)
dan sudah terpetakan di `RolePermissionSeeder` persis seperti matriks PRD 1.1.2
baris "PPDB Online". Seeder tidak disentuh di sprint ini.

Satu tambahan di luar matriks: aksi **Enroll** (PPDB-05) menuntut `ppdb.manage`
**dan** `student.manage`, karena aksi itu membuat record `students`. Konsekuensinya
hanya SUPER_ADMIN dan SCHOOL_ADMIN yang dapat meng-enroll — KEPALA_SEKOLAH (PPDB ⭕,
Data Siswa ⭕) tidak. Ini konsisten dengan kedua baris matriks sekaligus.

### 19. Pendaftar tidak dapat dibuat atau dihapus dari panel

`PpdbRegistrationPolicy::create()` dan `::delete()` selalu `false`. API 4.7 hanya
menyediakan submit publik (`POST /ppdb/{schoolCode}/register`) dan tidak memiliki
endpoint hapus, sehingga jejak seluruh pendaftar dipertahankan. Resource Filament
karena itu hanya punya halaman **List** dan **View** — tidak ada Create/Edit.

Perubahan data pendaftar terjadi lewat aksi bernama yang memetakan endpoint API:
`changeStatus` (PATCH /admin/ppdb/{id}/status), `waLink` (GET …/wa-link), dan
`enroll` (POST …/enroll).

### 20. `academic_year_id` diisi dari tahun ajaran aktif cabang

ERD hanya menyebut "tahun ajaran yang didaftar" tanpa menentukan siapa yang mengisi.
Formulir publik tidak meminta pendaftar memilih tahun ajaran (PPDB-01 poin 2 tidak
mencantumkannya); nilainya diambil dari tahun ajaran yang `is_active` pada cabang
tersebut. Bila cabang belum punya tahun ajaran aktif, kolom dibiarkan NULL sesuai
ERD dan pendaftaran tetap diterima.

### 21. Halaman publik memakai Livewire tanpa build Vite

`03-architecture/01-tech-stack.md` menetapkan Livewire 3 untuk portal frontend.
Tiga halaman publik (`/ppdb`, `/ppdb/{kode}`, `/ppdb/cek-status`) adalah komponen
Livewire full-page dengan layout `resources/views/layouts/ppdb.blade.php`.

Layout itu memuat CSS-nya sendiri secara inline, bukan lewat `@vite`, karena
`public/build/` belum pernah di-build di lingkungan ini — halaman publik yang gagal
render karena manifest hilang akan mematikan PPDB-01 sepenuhnya. Warna diambil dari
`schools.primary_color`/`secondary_color` cabang yang sedang dibuka dan disuntikkan
sebagai CSS variable, persis alur Arsitektur 3.2.3 (bedanya: bukan dari sesi
pengguna, karena halaman ini publik).

### 22. Notifikasi in-app belum ada

NOTIF-03 poin 1 menyebut trigger otomatis untuk "PPDB status berubah" tampil di
**in-app notification center**. Tabel `notifications`/`notification_reads` adalah
Sprint 8 dan belum dibuat. Yang tersedia sekarang adalah bagian wa.me-nya: setelah
status diubah, panel menampilkan notifikasi Filament berisi tombol "Buka WhatsApp"
dengan link yang sudah terisi teks — sesuai PPDB-04 poin 3 ("Admin tinggal klik").

## Sprint 4 — Akademik (E-Rapor & Penilaian)

Cakupan mengikuti roadmap `05-roadmap/01-implementation-order.md` Sprint 4 "Akademik":
Input nilai, Grade config, Auto-hitung nilai akhir, Generate & publish rapor, PDF rapor
— yaitu PRD 1.2.5 NILAI-01…NILAI-05, ERD 2.2 kelompok Penilaian, dan API 4.8.

Sprint ini berjalan di atas **keputusan tertulis Pak Akbar** yang melengkapi titik-titik
yang tidak dijawab blueprint (lihat verifikasi requirement K-1…K-15). Keputusan itu
menjadi sumber kebenaran untuk butir 23–31 di bawah.

### 23. Versioning `grade_configs`: kolom `status`, `version`, `activated_at`, `locked_at`

| | |
| --- | --- |
| **Status** | Tambahan di luar ERD |
| **Dasar** | Keputusan Sprint 4 butir 4 — "DRAFT → ACTIVE → LOCKED … Perubahan kebijakan bobot harus membuat Grade Config versi baru, bukan overwrite konfigurasi lama." |
| **Bertentangan dengan** | `02-erd/05-tables-penilaian.md` — Tabel: grade_configs |

ERD hanya menyediakan tujuh kolom tanpa penanda siklus hidup, sehingga tidak ada cara
membedakan konfigurasi yang masih boleh disunting dari yang sudah dipakai menilai.
Empat kolom ditambahkan: `version` SMALLINT UNSIGNED DEFAULT 1, `status`
ENUM(DRAFT,ACTIVE,LOCKED) DEFAULT DRAFT ber-index, serta `activated_at` dan `locked_at`
sebagai jejak waktu transisi.

Aturan **"maksimal satu ACTIVE per (cabang, mapel, tahun ajaran)"** ditegakkan di
aplikasi (`App\Services\Grading\GradeConfigVersionManager::activate()`), bukan lewat
partial unique index — MySQL tidak memilikinya dan SQLite yang dipakai test suite juga
tidak. Polanya mengikuti preseden `AcademicYear::activate()` dari Sprint 2.

Nama unique index ditulis eksplisit sebagai `grade_configs_scope_version_unique`; nama
bawaan Laravel untuk empat kolom itu melewati batas 64 karakter identifier MySQL.

### 24. Kolom `updated_at` pada `grade_configs`

| | |
| --- | --- |
| **Status** | Tambahan di luar ERD |
| **Dasar** | Konsekuensi butir 23 — "DRAFT: bebas diedit" |

ERD hanya mencantumkan `created_at` (pola yang sama dengan `subjects`, `schedules`, dst.
pada Sprint 2 butir 8). Karena konfigurasi berstatus DRAFT memang dimaksudkan untuk
disunting berulang kali, tanpa `updated_at` perubahannya tidak meninggalkan jejak sama
sekali. Kolom ditambahkan; keempat tabel Sprint 2 tidak diubah.

### 25. Kolom `grades.assessment_type`

| | |
| --- | --- |
| **Status** | Tambahan di luar ERD |
| **Dasar** | Keputusan Sprint 4 butir 3 — "Data harus membedakan formative dan summative. Tidak semua Daily/formative otomatis menjadi komponen nilai rapor." |

ENUM(FORMATIVE, SUMMATIVE) NOT NULL DEFAULT **SUMMATIVE**, ber-index. Default sengaja
SUMMATIVE karena itulah alur utama guru; nilai latihan dipilih eksplisit.

Definisi "nilai valid" yang dipakai seluruh perhitungan menjadi: `assessment_type =
SUMMATIVE` **dan** `grade_type` termasuk komponen akademik. Keduanya diterapkan di
`App\Services\Grading\ComponentScoreAggregator::valid()`.

### 26. Kolom `grades.grade_config_id`

| | |
| --- | --- |
| **Status** | Tambahan di luar ERD |
| **Dasar** | Keputusan Sprint 4 butir 2 — "grades.weight = SNAPSHOT bobot saat grade dibuat/finalized" |

ERD sudah menyediakan `weight`, tetapi tidak menyimpan **dari konfigurasi mana** angka
itu berasal. Tanpa itu prinsip "Rapor = hasil kalkulasi dari konfigurasi yang digunakan"
tidak dapat diaudit. FK nullable ke `grade_configs.id`.

Snapshot diambil di hook `creating` pada `App\Models\Grade`, bukan di halaman Filament,
supaya input satuan, input massal, dan (nanti) import Excel sama-sama terlindungi.

### 27. Kolom `schools.attitude_scale`

| | |
| --- | --- |
| **Status** | Tambahan di luar ERD |
| **Dasar** | Keputusan Sprint 4 butir 3 — "Range attitude JANGAN hard-code. Simpan sebagai configuration agar dapat diubah Admin." |

JSON nullable berisi batas bawah per predikat, mis. `{"A":86,"B":76,"C":66,"D":0}`.
Ditempatkan di `schools` karena ERD mendeskripsikan tabel itu sebagai *"Data dan
konfigurasi setiap cabang sekolah (tenant)"* — alternatifnya membuat tabel ke-22 di luar
21 entitas ERD. NULL berarti cabang memakai rentang default pada
`App\Enums\AttitudePredicate::defaultScale()` (persis angka keputusan Pak Akbar).

Penyuntingannya lewat halaman **Akademik → Pengaturan Penilaian**, bukan lewat
SchoolResource — resource manajemen tenant (API 4.3) memang belum pernah dibuat.

### 28. Versioning menggantikan bunyi literal NILAI-05 poin 2

| | |
| --- | --- |
| **Status** | Penggantian aturan yang disengaja |
| **Bertentangan dengan** | `01-prd/02-features-phase1.md` — NILAI-05 poin 2 |

PRD berbunyi *"Perubahan konfigurasi hanya berlaku untuk **tahun ajaran baru**"*.
Keputusan Sprint 4 butir 4 mengizinkan perubahan kebijakan **dalam tahun ajaran yang
sama** selama dibuat sebagai versi baru, dan konfigurasi lama dikunci (LOCKED) alih-alih
ditimpa. Tujuan PRD — nilai yang sudah ada tidak berubah — tetap terpenuhi, bahkan lebih
kuat, karena dijaga oleh snapshot `grades.weight` (butir 26), bukan sekadar oleh batas
tahun ajaran.

**Jika PRD ingin dipertahankan literal:** cabut aksi "Buat Versi Baru" dan tolak
pembuatan konfigurasi kedua pada tahun ajaran yang sudah punya baris ACTIVE.

### 29. Unique index yang tidak tercantum ERD

Melanjutkan pola Sprint 2 butir 6 — ERD kelompok Penilaian tidak menandai satu pun
kolom dengan `UQ`:

| Tabel | Unique index | Dasar |
| --- | --- | --- |
| `grade_configs` | `(school_id, subject_id, academic_year_id, version)` | Keputusan butir 4: versi adalah identitas konfigurasi |
| `report_cards` | `(student_id, academic_year_id)` | ERD 2.2: "Rapor final per siswa per semester" |
| `subjects` | `(school_id, code)` | ERD 2.2 menetapkan `final_scores` = `{"MTK": 87.5}` — kunci JSON memakai kode mapel, sehingga kode yang tidak unik membuat dua mapel saling menimpa di dalam rapor |

Yang ketiga **mengubah tabel Sprint 2**; ini konsekuensi langsung dari bentuk
`final_scores` di ERD, bukan penambahan fitur.

### 30. Aturan turunan yang tidak dinyatakan dokumen maupun keputusan

Tiga hal berikut tidak diatur di mana pun, tetapi harus diputuskan agar perhitungan
dapat berjalan. Semuanya terisolasi di service dan mudah diubah:

| Hal | Keputusan implementasi | Berkas |
| --- | --- | --- |
| Bobot komponen bila satu tipe punya beberapa nilai dengan snapshot berbeda (kebijakan berganti versi di tengah semester) | Dipakai snapshot dari **entri terbaru**, yakni kebijakan terakhir yang berlaku untuk komponen itu | `FinalScoreCalculator::weightsFrom()` |
| Nilai akhir saat sebagian komponen konfigurasi belum ada nilainya | Nilai akhir dianggap **belum lengkap** (NULL), bukan dihitung sebagian — mencegah mapel dengan bobot terisi separuh tampak "sudah bernilai" dan lolos validasi publish NILAI-03 poin 1 | `FinalScoreCalculator::calculate()` |
| Komponen yang wajib menurut konfigurasi tetapi nilainya tidak punya snapshot bobot | Dianggap **belum lengkap**. Bobotnya sengaja **tidak** ditambal dari konfigurasi yang berlaku sekarang: menambalnya membuat rapor bergantung pada kebijakan terbaru, persis yang dicegah keputusan butir 2 | `FinalScoreCalculator::calculate()` |
| Total bobot snapshot yang tidak berjumlah 1.00 | Nilai akhir **tidak dihitung**. Lihat butir 34 | `FinalScoreCalculator::calculate()` |
| Predikat sikap dari beberapa entri ATTITUDE | Rata-rata entri **sumatif** saja, lalu dipetakan ke predikat. Entri sikap berjenis FORMATIVE adalah catatan proses dan tidak menggeser predikat di rapor — pembedaan formatif/sumatif pada keputusan butir 3 berlaku untuk seluruh isi rapor, bukan hanya nilai akademik | `ComponentScoreAggregator::attitudeAverage()` |
| Siswa yang dibuatkan rapor saat generate | Hanya siswa berstatus ACTIVE. `Student::inClass()` sendiri hanya memeriksa status pivot `student_classes`, sehingga siswa yang sudah lulus/pindah tetapi pivot-nya belum ditutup akan ikut terjaring bila statusnya tidak diperiksa | `ReportCardGenerator::studentsOf()` |
| Komponen wajib bila konfigurasi tidak dapat dilacak sama sekali (tidak ada satu pun nilai yang membawa `grade_config_id`) | Nilai akhir **tidak dihitung**, dan susunan komponennya **tidak ditebak** dari nilai yang kebetulan ada. Menebaknya membuat mapel tanpa Grade Config seolah punya susunan komponen, padahal keputusan butir 3 menyatakan komponen akademik hanya dihitung bila ditetapkan Grade Config. Rata-rata per komponen tetap dilaporkan agar nilainya tidak terkesan hilang | `FinalScoreCalculator::calculate()` |

### 31. Paket baru: `barryvdh/laravel-dompdf`

`03-architecture/01-tech-stack.md` menulis *"PDF Generation | DomPDF / Browsershot"*
tanpa memilih. Dipilih **DomPDF** karena murni PHP: Browsershot menuntut Node + Chromium
pada VPS 2 Core/2 GB (`01-prd/04-non-functional-requirements.md` menargetkan 200 user
konkuren di mesin itu). Template ada di `resources/views/pdf/report-card.blade.php`.

Tech stack menempatkan PDF rapor sebagai *background job (queue)*; saat ini PDF
di-stream sinkron saat tombol diklik. Untuk volume satu-kelas-sekaligus, pemindahan ke
queue perlu dilakukan sebelum go-live.

### 32. Yang sengaja dikosongkan

| Kolom / fitur | Alasan |
| --- | --- |
| `report_cards.attend_present` / `_sick` / `_permission` / `_absent` | Tidak ada tabel absensi di antara 21 entitas ERD; "Presensi Digital" adalah Phase 2. Kolomnya tetap dibuat sesuai ERD dan tampil di PDF sebagai "—" |
| `report_cards.rank_in_class` | Tidak ada requirement maupun rumus peringkat di seluruh blueprint |
| ~~Import Excel nilai (`POST /grades/import`)~~ | **Sudah dikerjakan** — lihat butir 38 |
| Penyajian nilai/rapor di portal siswa & ortu (NILAI-03 poin 3, NILAI-04 poin 1–2) | Sprint 7 — Portal |
| Notifikasi ke ortu saat rapor terbit (API 4.8) | Sprint 8 — Notifikasi |

### 33. Kewenangan `grade_configs` tidak memakai `grade.manage`

Matriks PRD 1.1.2 tidak punya baris "Grade config", tetapi kewenangannya dinyatakan
eksplisit di empat tempat: NILAI-05 (*"Sebagai **Admin Sekolah**"*), NILAI-02
(*"dikonfigurasi **Admin**"*), API 4.8 (`POST /grade-configs` → Auth Level **Admin**),
dan API 4.1 (*"Auth Level: Admin = Wajib token + role **SCHOOL_ADMIN / SUPER_ADMIN**"*).

Karena `grade.manage` juga dipegang GURU, penulisan konfigurasi **tidak** digantung pada
izin itu — `GradeConfigPolicy` memeriksa peran SCHOOL_ADMIN secara langsung (SUPER_ADMIN
lolos lebih dulu lewat `Gate::before`). Membaca tetap terbuka untuk seluruh pemegang
`grade.view`, sesuai `GET /grade-configs` yang ber-Auth Level "Auth".

Tidak ada izin baru yang dibuat dan `RolePermissionSeeder` tidak disentuh.

### 34. Bobot snapshot lintas-versi divalidasi saat menghitung nilai akhir

| | |
| --- | --- |
| **Status** | Pengaman turunan butir 4 |
| **Dasar** | Keputusan Sprint 4 butir 2 & 4 |

Butir 23 mengizinkan kebijakan bobot berganti versi **di tengah semester**, dan butir
26 menyimpan bobot sebagai snapshot per entri nilai. Kombinasi keduanya membuka kasus
yang tidak dijawab keputusan mana pun: nilai satu mata pelajaran bisa lahir dari dua
versi konfigurasi yang berbeda, sehingga **bobot efektifnya tidak lagi berjumlah 1.00**.

Contoh: v1 (Harian 0.40 · UTS 0.30 · UAS 0.30) dipakai menilai Harian, lalu v2
(Harian 0.50 · UTS 0.25 · UAS 0.25) diaktifkan sebelum UTS dan UAS diinput. Bobot
efektifnya 0.40 + 0.25 + 0.25 = **0.90**, dan nilai akhirnya turun sekitar 10% tanpa
seorang pun menyadarinya. `validateComponents()` tidak menangkap ini — kedua versi
sendiri sudah benar; yang cacat adalah campurannya.

`FinalScoreCalculator::calculate()` karena itu memeriksa total bobot yang benar-benar
dipakai dan menyatakan nilai **belum lengkap** bila melenceng dari 1.00, memakai
toleransi `GradeConfig::WEIGHT_EPSILON` yang sama dengan validasi konfigurasi. Rapor
tidak dapat diterbitkan sampai keadaan itu dibereskan, sesuai NILAI-03 poin 1.

Jalan keluarnya bagi guru: menilai ulang komponen yang bobotnya sudah tidak berlaku,
sehingga snapshot terbarunya mengikuti versi yang sedang aktif.

Alasan kegagalan ini diteruskan apa adanya dari `FinalScoreResult::$reason` ke ringkasan
**Generate Rapor Kelas**, sehingga wali kelas melihat penyebabnya per mata pelajaran —
bukan sekadar "belum lengkap".

### 35. Grade Config dikunci otomatis setelah rapor terbit

| | |
| --- | --- |
| **Status** | Melengkapi butir 4 keputusan |
| **Dasar** | Keputusan Sprint 4 butir 4 — "LOCKED setelah rapor/finalisasi semester" |

Transisi ACTIVE → LOCKED semula hanya tersedia sebagai tombol manual. Kini
`ReportCardGenerator::publish()` menguncinya sendiri lewat
`GradeConfigVersionManager::lockIfActive()`; tombol manual tetap ada untuk finalisasi
yang dilakukan lebih awal.

Yang perlu diketahui adalah **kapan** penguncian terjadi. Konfigurasi berlaku per
(mapel, tahun ajaran) — bukan per kelas, dan bukan per siswa. Mengunci pada rapor
siswa pertama akan menghentikan penilaian semua siswa lain yang memakai konfigurasi
itu: `GradeConfig::activeFor()` tidak lagi menemukan apa pun, nilai baru tersimpan
tanpa snapshot bobot, dan menurut butir 34 rapor mereka menjadi tidak lengkap
selamanya.

Karena itu penguncian baru berjalan ketika **seluruh siswa aktif di semua kelas yang
mengampu mata pelajaran tersebut sudah memegang rapor terbit**
(`ReportCardGenerator::isFullyReported()`) — definisi "finalisasi semester" yang
paling dekat dengan yang bisa ditegakkan data yang ada. Konsekuensinya: satu kelas
yang selesai lebih dulu tidak mengunci kelas lain yang masih menilai.

### 36. Formula NILAI-02 digeneralisasi ke n komponen

| | |
| --- | --- |
| **Status** | Perluasan aturan yang disengaja |
| **Dasar** | Keputusan Sprint 4 butir 3 — "Skill dapat menjadi komponen nilai akhir tersendiri dan bobotnya configurable di Grade Config." |
| **Bertentangan dengan** | `01-prd/02-features-phase1.md` — NILAI-02 poin 2 |

PRD menuliskan formulanya dengan **tiga komponen tetap**: *"Nilai Akhir = (Harian × bobot)
+ (UTS × bobot) + (UAS × bobot)"*. ERD pun hanya mencontohkan `DAILY` dan `MIDTERM` pada
`grade_configs.components`. Keduanya tidak pernah menyebut SKILL maupun ASSIGNMENT sebagai
bagian nilai akhir — SKILL hanya muncul sebagai salah satu nilai ENUM `grades.grade_type`.

Keputusan butir 3 menyatakan sebaliknya untuk SKILL, dan konsekuensi wajarnya berlaku juga
untuk ASSIGNMENT yang sama-sama terdaftar di ENUM itu. Formula karena itu diimplementasikan
sebagai penjumlahan atas **komponen apa pun yang tercantum di Grade Config**:

```
Nilai Akhir = Σ (rata-rata komponen × bobot snapshot komponen)
```

Yang tetap dijaga persis seperti PRD: hasil dibulatkan 2 desimal, dan total bobot wajib
1.00. Konfigurasi Harian 40 · UTS 30 · UAS 30 menghasilkan angka yang identik dengan
formula literal PRD — perluasan ini menambah kemungkinan, bukan mengubah perhitungan yang
sudah ada.

Batasnya tetap dua, dan keduanya ditegakkan:

- **ATTITUDE tidak boleh menjadi komponen berbobot.** `GradeType::isAcademic()`
  mengecualikannya, form Grade Config tidak menawarkannya (`GradeType::academicOptions()`),
  dan `GradeConfigVersionManager::validateComponents()` menolaknya secara eksplisit —
  sehingga tidak dapat dimasukkan sekalipun sengaja dicoba.
- **Komponen yang tidak ada di Grade Config tidak ikut menghitung**, termasuk SKILL.
  Nilainya tetap tersimpan dan dilaporkan lewat `FinalScoreResult::$ignoredComponents`
  (butir C-6), bukan dibuang.

**Jika PRD ingin dipertahankan literal:** batasi pilihan komponen pada form Grade Config
menjadi `DAILY`, `MIDTERM`, `FINAL` saja, dan tolak konfigurasi yang memuat `SKILL` atau
`ASSIGNMENT` di `validateComponents()`. `FinalScoreCalculator` tidak perlu diubah — ia
hanya mengikuti apa pun yang ditetapkan konfigurasi.

### 37. Pemilihan cabang pada Pengaturan Penilaian untuk Super Admin

| | |
| --- | --- |
| **Status** | Melengkapi butir 27 |
| **Dasar** | `03-architecture/02-multi-tenant.md` — "Super Admin (school_id = NULL) melewati Global Scope"; `02-erd/02-tables-core.md` — "Super Admin mengelola tabel ini" |

Butir 27 menempatkan `attitude_scale` sebagai kolom pada `schools`, yaitu konfigurasi
**per cabang**. Halaman **Akademik → Pengaturan Penilaian** semula mengambil cabangnya dari
`Auth::user()->school_id`, sehingga Super Admin — yang menurut Arsitektur 3.2 memang
ber-`school_id` NULL — dapat membuka halaman itu tetapi tidak pernah bisa menyimpan.
Padahal API 4.1 memasukkan SUPER_ADMIN ke dalam Auth Level "Admin" yang dimaksud NILAI-05.

Ditambahkan Select **Cabang Sekolah** yang hanya dirender untuk Super Admin, mengikuti pola
yang sudah dipakai `UserResource` dan `GradeConfigResource`. Admin Sekolah tidak melihat
field itu dan tetap terikat cabang akunnya.

`PengaturanPenilaian::resolveSchoolId()` **hanya mempercayai nilai form dari Super Admin**.
Bagi peran lain field tersebut tidak pernah ada di skema, sehingga apa pun yang muncul di
state Livewire diabaikan — inilah yang menjaga `attitude_scale` tidak tercampur antar
cabang. Daftar cabang dibatasi `is_active = true`.

Perhitungan predikat tidak tersentuh: `AttitudePredicateResolver`, rentang default, dan
validasi urutan A > B > C > D tetap sama persis.

### 38. Format template import nilai (`POST /grades/import`)

| | |
| --- | --- |
| **Status** | Detail yang tidak didefinisikan dokumen |
| **Dasar** | `01-prd/02-features-phase1.md` NILAI-01 poin 2 — "Input dapat dilakukan satu per satu **atau melalui import Excel**"; `04-api/06-grading-and-reports.md` — `POST /grades/import` |

Butir 32 semula mengosongkan fitur ini karena formatnya tidak ditentukan dokumen.
Formatnya kini diturunkan dari preseden yang sudah ada di project, bukan dari keputusan
baru:

| Yang kosong | Diturunkan dari |
| --- | --- |
| Nama kolom | Butir 11 (`StudentsImport`) — heading row, huruf kecil dengan garis bawah, berbahasa Indonesia |
| Kolom apa saja | API 4.8 `POST /grades/bulk` — *"untuk satu class_subject (array of {student_id, score})"* |
| Kunci siswa | `nis`, sama seperti `StudentsImport`; memang unik per cabang (Sprint 2 butir 6) |
| Pelaporan kegagalan | API 4.4 — *"Return: sukses + daftar error baris"* |
| Kewenangan | `GradePolicy::import()` yang sudah ada, ditambah `gradeClassSubject()` sesuai API 4.8 baris 8 |
| Rentang nilai | NILAI-01 poin 1 — `Grade::MIN_SCORE`…`MAX_SCORE` |

Berkasnya karena itu **hanya memuat dua kolom: `nis` dan `nilai`**. Kelas-mapel, komponen,
jenis penilaian, dan keterangan adalah konteks yang dipilih di form — mengikuti bentuk
`POST /grades/bulk` yang juga hanya membawa `{student_id, score}` per baris. Konsekuensi
yang disengaja: satu berkas tidak dapat diam-diam menulis nilai ke kelas atau komponen
lain, karena tidak ada kolom di berkas yang bisa menyatakannya.

Tiga hal yang berlaku sama dengan jalur input lain, tanpa kode baru:

- **Snapshot bobot.** Importer menulis lewat `Grade::query()->create()`, bukan query
  builder, sehingga hook `creating` pada `App\Models\Grade` (butir 26) berjalan apa adanya.
- **Isolasi cabang.** Baris hanya dicocokkan terhadap siswa **aktif di kelas itu** —
  daftar yang sama dengan yang ditampilkan halaman Input Nilai. NIS milik cabang atau
  kelas lain dilaporkan sebagai error baris, bukan diterima.
- **Peringatan C-6 & LOCKED.** Diekstrak dari `InputNilai` ke
  `App\Services\Grading\ConfigurationGapWarner` supaya kedua jalur input memberi
  peringatan yang sama. Pemindahan murni; perilakunya tidak berubah.

**Berkasnya disediakan, bukan hanya dijelaskan.** API 4.8 menyebut *"Import nilai dari
Excel **template**"* tetapi tidak menentukan isinya, sehingga templatenya dibuat sebagai
turunan langsung dari importer: `App\Exports\GradeTemplateExport` menuliskan persis baris
heading yang dibaca `GradesImport` — `nis`, `nilai` — dan tidak ada isi lain. Client
mengunduhnya lewat tombol **"Unduh Template"** di halaman Nilai, berdampingan dengan
tombol Import dan dengan kewenangan yang sama (`GradePolicy::import()`).

Dua hal yang sengaja **tidak** dimasukkan ke template, karena tidak ada requirement-nya
dan keduanya akan menjadi aturan bisnis baru:

- **Baris contoh.** Baris apa pun di bawah heading akan ikut terbaca sebagai data begitu
  berkasnya diunggah kembali.
- **Daftar siswa kelas terpilih.** Menarik NIS sekelas ke dalam template akan membuat
  berkasnya bergantung pada kelas — padahal justru pemisahan itu yang membuat satu berkas
  tidak dapat menulis ke kelas lain.

`Tests\Feature\Grading\GradeImportXlsxTest` menutup jalur berkas nyata: berkas `.xlsx`
dibuat sungguhan lalu dibaca lewat `Excel::import()` tanpa `Excel::fake()`, termasuk
round-trip template → isi → import. Yang hanya terbukti di sana: NIS yang seluruhnya angka
ditulis Excel sebagai sel *angka*, dan tetap cocok dengan `students.nis` yang bertipe
string.

Yang **tidak** ikut dibuat: export nilai ke Excel. `StudentResource` memilikinya karena
SIS-05 memintanya eksplisit; tidak ada requirement setara untuk nilai di seluruh blueprint.
Template di atas bukan export — isinya kosong dan tidak membawa satu pun data nilai.

### 39. Generate rapor sekelas dimuat sekali, bukan per siswa

| | |
| --- | --- |
| **Status** | Pemenuhan NFR, tanpa perubahan perilaku |
| **Dasar** | `01-prd/04-non-functional-requirements.md` — "Response time API < 500ms untuk 95% request"; "Minimal 200 user konkuren pada VPS 2C/2GB" |

`ReportCardGenerator::generateForClass()` semula mengambil nilai satu query per siswa
per mata pelajaran, rapor lama satu query per siswa, dan `FinalScoreCalculator::configUsedBy()`
mencari konfigurasi yang sama berulang kali. Diukur pada satu kelas 30 siswa × 8 mapel
× 5 nilai:

| | Query | Waktu (SQLite in-memory) |
| --- | --- | --- |
| Sebelum | 575 | 547 ms |
| Sesudah | 46 | 439 ms |

Angka waktunya sendiri masih optimistis — SQLite in-memory jauh lebih murah per query
daripada MySQL melalui TCP, sehingga penurunan jumlah query-lah yang menentukan di
produksi. 547 ms saja sudah melewati target 500 ms sebelum kelasnya penuh.

Tiga perubahan, semuanya tidak menyentuh perhitungan:

1. Seluruh nilai kelas diambil sekali (`gradesOf()`), dikelompokkan per siswa, lalu
   dibagikan ke `buildFor()`. Relasi `classSubject` ikut dimuat karena
   `GradeWeightSnapshotter` membacanya saat melengkapi snapshot kosong.
2. Rapor yang sudah ada diambil sekali (`reportCardsOf()`); unique index
   `(student_id, academic_year_id)` menjamin paling banyak satu per siswa.
3. `configUsedBy()` memoisasi hasil `find()` per id. **`weightsFrom()` tidak disentuh** —
   aturan "snapshot entri terbaru" (butir 30) masih menunggu keputusan client.

`buildFor()` tetap dapat dipanggil tanpa nilai yang sudah dimuat dan akan mengambilnya
sendiri, sehingga pemanggilan untuk satu siswa tidak bergantung pada pemanggil.

Dijaga `ReportCardPublishTest::test_generating_does_not_issue_queries_per_student()`, yang
menguji **bentuknya** — menambah siswa tidak boleh menambah query secara sebanding —
bukan angka mutlaknya.

**Yang belum dikerjakan:** bila nilai diinput sebelum ada Grade Config aktif,
`GradeWeightSnapshotter::backfill()` masih memanggil `GradeConfig::activeFor()` sekali per
baris nilai (2446 query pada kelas contoh di atas, turun dari 4175). Biayanya sekali
seumur nilai — setelah snapshot tersimpan, generate berikutnya kembali ke jalur 46 query.
Memoisasinya menunggu keputusan F-5, yang menyentuh berkas yang sama.

### 40. Facade autentikasi di halaman Filament: `Auth`, bukan `Filament::auth()`

| | |
| --- | --- |
| **Status** | Konsistensi internal, tanpa perubahan perilaku |
| **Dasar** | Tidak diatur blueprint — dipilih mengikuti pola yang sudah berjalan |

`CreateGradeConfig` sempat memakai `Filament::auth()->user()`, satu-satunya pemakaian
facade itu di seluruh `app/`. Sebelas berkas Filament lain — termasuk `CreateGrade` dan
`CreateUser` yang sejenis — memakai `Auth::user()` / `Auth::id()`.

Keduanya menghasilkan pengguna yang sama: `AdminPanelProvider` menyetel
`->authGuard('web')`, dan guard bawaan aplikasi juga `web` (`config/auth.php`). Jadi ini
murni soal konsistensi, bukan perilaku.

Yang membuatnya perlu dirapikan: `mutateFormDataBeforeCreate()` memanggil
`GradeConfigResource::resolveSchoolId()`, yang membaca `Auth::user()`. Satu alur yang
memakai dua facade berbeda akan menyimpang diam-diam bila suatu saat guard panel dipisah
dari guard bawaan. Disatukan ke `Auth::user()`, mengikuti mayoritas.

## Foundation Gap Batch 1 — Manajemen Tenant & White-Label

Menutup dua baris matriks PRD 1.1.2 yang belum punya UI sama sekali: "Manajemen
Tenant/Cabang" (hanya SUPER_ADMIN) dan "White-label Settings" (SUPER_ADMIN + SCHOOL_ADMIN),
beserta AUTH-03 dan API 4.3. Tidak ada kolom baru — seluruhnya memakai `schools` apa adanya.

### 41. White-label memakai CSS variable milik Filament, bukan `--color-primary`

| | |
| --- | --- |
| **Status** | Mekanisme setara, hasil akhir sesuai requirement |
| **Bertentangan dengan** | `03-architecture/02-multi-tenant.md` §3.2.3 — contoh `--color-primary` / `--color-secondary` |

Dokumen mencontohkan penyuntikan `:root { --color-primary: … }` lalu semua komponen UI
memakai `var(--color-primary)`. Itu berlaku untuk CSS yang ditulis sendiri, tetapi panel
ini dibangun di atas Filament, yang **menghasilkan paletnya sendiri**: setiap warna
diperluas menjadi sebelas gradasi dan ditulis sebagai `--primary-50 … --primary-950`
berisi triplet RGB (lihat `vendor/filament/support/resources/views/assets.blade.php`).
Menulis `--color-primary` tidak akan berpengaruh apa pun pada tombol, tab, atau badge
Filament — variabel itu tidak dibaca siapa pun.

Yang dilakukan karena itu adalah **mekanisme yang sama dengan nama variabel yang benar**:
`App\Support\SchoolBranding::cssVariables()` menghasilkan blok `<style>` berisi
`--primary-*` dan `--secondary-*` hasil `Color::hex()`, disuntikkan lewat render hook
`PanelsRenderHook::HEAD_END`. Karena blok itu dirender **setelah** blok milik Filament di
`<head>`, ia menang tanpa `!important` dan tanpa menyentuh satu pun berkas CSS terkompilasi.

Yang tetap dipenuhi persis seperti dokumen: sumbernya `schools.logo_url`,
`schools.primary_color`, `schools.secondary_color`; dibaca per request berdasarkan
`school_id` pengguna; dan berlaku seketika tanpa deployment ulang (AUTH-03 poin 3) — hal
terakhir inilah alasan `brandName()`, `brandLogo()`, dan render hook semuanya ditutup
`Closure`, bukan nilai statis yang dibekukan saat panel diregistrasi.

Nilai yang bukan hex 6 digit **tidak diteruskan** ke `Color::hex()` — fungsi itu melempar
exception untuk masukan tak dikenal, yang berarti satu sel database yang rusak akan
menggagalkan seluruh render panel. `SchoolBranding` menyaringnya lebih dulu dan jatuh ke
warna platform.

### 42. Logo cabang disimpan di disk `public`

| | |
| --- | --- |
| **Status** | Penyimpangan yang diteruskan dari Sprint 2 & 3 |
| **Bertentangan dengan** | `03-architecture/04-security.md` — File Upload: "disimpan di storage/ (di luar web root)" |

`schools.logo_url` menyimpan path pada disk `public` (`School::LOGO_DISK`), mengikuti pola
`students.photo_url` (Sprint 2) dan berkas PPDB (butir 17) — berkasnya dapat diakses siapa
pun yang menebak URL-nya.

Berbeda dari dua modul sebelumnya, di sini keterbukaan itu **memang dibutuhkan**: logo
cabang ikut tampil di halaman PPDB publik yang justru tidak boleh menuntut login. Yang
tersimpan bukan data pribadi. Pemindahan ke disk privat tetap relevan untuk foto siswa dan
dokumen PPDB, dan tetap ditunda bersama-sama.

**Format yang diterima: JPG dan PNG saja.** Tidak ada satu pun requirement di
`smartsukses-docs/` yang mengatur format logo — seluruh penyebutan `logo` / `logo_url`
hanya menerangkan fungsinya, tidak pernah formatnya. Yang berlaku karena itu adalah aturan
global `03-architecture/04-security.md`: *"File Upload | Validasi MIME + ukuran | **Hanya
JPG/PNG/PDF diperbolehkan**"*.

| Format | Putusan | Alasan |
| --- | --- | --- |
| JPG, PNG | **Diizinkan** | Tercantum pada aturan global Security 3.4 |
| PDF | **Tidak ditawarkan** | Diizinkan aturan global, tetapi bukan format gambar yang dapat dirender sebagai logo panel — Filament memuatnya lewat `<img>`. Ini pembatasan **lebih ketat** dari dokumen, bukan pelonggaran, sehingga tidak melanggar apa pun |
| WEBP | **Ditolak** | Hanya diizinkan `01-prd/02-features-phase1.md` SIS-03, yang lingkupnya eksplisit *"foto profil siswa"*. Tidak dapat diperluas ke logo tanpa requirement baru |
| SVG | **Ditolak** | Tidak disebut satu dokumen pun di seluruh blueprint |

Pola yang dianut: **requirement per-fitur menang atas aturan global bila ada** — itulah yang
membuat foto siswa sah menerima WEBP (SIS-03) dan bukti bayar sah menerima PDF (SPP-03).
Untuk logo requirement per-fitur itu tidak ada, jadi aturan global berlaku apa adanya.

Batas ukuran **2 MB**. Dokumen tidak menetapkan batas untuk logo; angka ini menyamakan diri
dengan dua unggahan lain yang batasnya memang tertulis — foto siswa (SIS-03: 2 MB) dan
dokumen PPDB (butir 17: 2 MB) — supaya tidak ada angka yang lahir tanpa sumber.

`WhiteLabelTest` membuktikan keduanya lewat unggahan sungguhan, bukan sekadar membaca
konfigurasi: WEBP, SVG, PDF, dan berkas 2049 KB ditolak; JPG dan PNG diterima.

### 43. Cabang tidak memiliki jalur hapus

API 4.3 hanya mengenal `PATCH /admin/schools/{id}/toggle`; tidak ada `DELETE`. Menghapus
tenant akan memutus seluruh data akademik, keuangan, dan rapor yang menggantung padanya.

`SchoolPolicy::delete()` karena itu selalu mengembalikan `false` — tetapi itu **bukan**
yang menutup jalurnya bagi Super Admin: `Gate::before` (Arsitektur 3.2.2) meloloskan Super
Admin sebelum policy mana pun dievaluasi. Yang benar-benar menutupnya adalah tidak adanya
aksi hapus di mana pun — tidak di tabel, tidak di halaman edit, tidak sebagai bulk action.
`SchoolManagementTest::test_no_delete_path_is_exposed_for_a_branch` menjaga ketiadaan itu,
bukan sekadar menguji policy-nya.

### 44. `schools` adalah satu-satunya tabel bisnis tanpa `school_id`

Tabel ini *adalah* tenant-nya, sehingga `SchoolScope` tidak berlaku dan `School::query()`
mengembalikan seluruh cabang bagi siapa pun yang berhasil menjalankannya. Isolasinya
karena itu ditegakkan dua lapis:

1. **Policy** — `SchoolPolicy` menuntut izin `tenant.*` (hanya SUPER_ADMIN pada matriks)
   dan, untuk peran School Level, mensyaratkan `user.school_id === school.id`.
2. **Query** — `SchoolResource::getEloquentQuery()` menyaring ke cabang sendiri bagi
   non-Super Admin. Konsekuensi yang disengaja: menebak URL cabang lain menghasilkan
   **404, bukan 403** — keberadaan cabang itu pun tidak terkonfirmasi.

Penyuntingan tampilan dipisahkan ke halaman `PengaturanTampilan` dengan izin
`white_label.manage`, karena matriks memberi SCHOOL_ADMIN akses white-label **tanpa**
memberi akses manajemen tenant. Field-nya diambil dari
`SchoolResource::brandingSection()` supaya kedua jalur menyunting kolom yang sama dengan
validasi yang sama.

## Foundation Gap Batch 2 — Audit Log

### 45. Audit log mencatat CUD, bukan CRUD

| | |
| --- | --- |
| **Status** | Resolusi konflik antar dokumen |
| **Dasar** | `03-architecture/04-security.md` — Audit Log |
| **Bertentangan dengan** | `01-prd/04-non-functional-requirements.md` — baris Audit |

Dua dokumen menyebut cakupan yang berbeda:

| Dokumen | Bunyi |
| --- | --- |
| NFR 1.4 | *"Semua aksi **CRUD** dicatat di tabel audit_logs dengan user & timestamp"* |
| Security 3.4 | *"Semua aksi **CUD** (Create, Update, Delete) dicatat: user, action, table, id, timestamp, IP"* |

Yang dipakai adalah **Security 3.4**: ia lebih spesifik — menyebut singkatannya secara
eksplisit *dan* merinci setiap field yang harus disimpan, sementara NFR hanya menyebut
"CRUD" sambil lalu dengan dua field. Mencatat aksi baca juga akan membuat tabel audit
tumbuh berkali lipat lebih cepat daripada data yang diauditnya, tanpa menambah satu pun
informasi yang dapat ditindaklanjuti. `App\Enums\AuditAction` karena itu hanya mengenal
CREATED, UPDATED, DELETED.

**Kolom `changes` sengaja tidak dibuat.** Tidak satu pun dari kedua dokumen memintanya,
dan menambahkannya berarti menyimpan salinan setiap perubahan data — termasuk data pribadi
siswa dan orang tua — di tabel yang aturan retensinya belum ditetapkan siapa pun.
Konsekuensi yang perlu diketahui: baris UPDATED memberi tahu *siapa mengubah record mana
dan kapan*, tetapi tidak *apa yang berubah*. Bila kelak dibutuhkan, kolom itu dapat
ditambah tanpa mengubah mekanisme penangkapannya.

**Tidak ada penghapusan otomatis.** Tidak ada requirement retensi untuk audit log; yang ada
hanya retensi 90 hari untuk notifikasi (NOTIF-04), dan itu modul berbeda. Menghapus jejak
audit tanpa dasar bertentangan dengan tujuan tabel ini, jadi barisnya tidak pernah
dibuang — juga tidak punya `updated_at`, karena tidak pernah berubah.

**Kewenangan membaca.** Matriks PRD 1.1.2 tidak memiliki baris Audit Log dan
`App\Enums\PermissionName` tidak punya `audit.*`. Membuat izin baru akan melanggar pola
yang dipegang sejak Sprint 2 (butir 7 & 18), sehingga `AuditLogPolicy` menumpang izin
paling ketat yang sudah ada: `tenant.view` — pada matriks hanya dimiliki SUPER_ADMIN.
Bila client kelak menghendaki Admin Sekolah membaca jejak cabangnya sendiri, yang perlu
ditambah adalah baris matriks, bukan kodenya.

**"Custom Middleware + Event" dipakai keduanya, masing-masing untuk alasannya sendiri.**
Bagian *Event* adalah satu listener wildcard di `AppServiceProvider` yang menangkap setiap
model tanpa trait di model mana pun — tidak ada yang bisa lupa dipasangi saat modul baru
lahir. Bagian *Middleware* (`RecordAuditIpAddress`) hanya menyerahkan IP klien, dan itu
bukan formalitas: di CLI, Symfony mengisi `REMOTE_ADDR` dengan `127.0.0.1` sebagai bawaan,
sehingga membaca `request()->ip()` langsung akan membuat setiap baris hasil seeder dan
worker antrean tercatat ber-IP padahal tidak punya klien sama sekali. Karena hanya
middleware yang mengisinya, **NULL berarti benar-benar tidak ada request** — bukan sekadar
tidak terbaca.

Yang **tidak** diaudit, beserta alasannya:

| Dilewati | Alasan |
| --- | --- |
| `AuditLog` sendiri | Menulis baris audit memicu event `created` miliknya sendiri — rekursi tak terbatas |
| `Role` & `Permission` (spatie) | **Oleh listener otomatis saja** — supaya `RolePermissionSeeder` yang membuat 8 peran dan puluhan izin tidak melahirkan puluhan baris audit di setiap seed dan setiap test. Jalur UI-nya diinstrumentasi terpisah; lihat butir 47 |
| Penugasan peran ke user (`model_has_roles`) | Pivot `belongsToMany` tidak memicu model event sama sekali, dan `config/permission.php` menyetel `events_enabled => false`. Ditutup lewat instrumentasi jalur UI — butir 47 |
| `users.last_login_at` | Ditulis `saveQuietly()` sejak Sprint 1: penandaan waktu login bukan mutasi data bisnis, dan mencatatnya akan memproduksi satu baris audit per login yang tidak menerangkan apa-apa |
| Aksi baca | Security 3.4 menyebut CUD |

Perubahan kewenangan lewat panel **tetap terekam**, tetapi bukan oleh listener ini —
lihat butir 47.

### 46. Dua jalur write yang tidak memicu model event

Listener wildcard hanya mendengar event Eloquent. Penelusuran seluruh jalur write di
`app/` menemukan **dua** tempat yang memutakhirkan baris lewat query builder, sehingga
tidak akan pernah sampai ke listener:

| Lokasi | Yang dilakukan |
| --- | --- |
| `AcademicYear::activate()` | Menonaktifkan tahun ajaran lain di cabang yang sama (API 4.6 — *"menonaktifkan yang lain"*) |
| `GradeConfigVersionManager::activate()` | Mengunci versi konfigurasi yang sebelumnya ACTIVE (keputusan Sprint 4 butir 4) |

Keduanya bukan detail sepele: sebuah Grade Config yang berpindah ke LOCKED mengubah cara
nilai dihitung, dan tahun ajaran yang nonaktif memindahkan seluruh konteks akademik cabang.
Justru perubahan seperti inilah yang paling perlu terlacak.

Penyelesaiannya paling kecil yang benar: id yang terdampak diambil lebih dulu, lalu dicatat
eksplisit lewat `AuditLogger::recordMany()`. Biayanya satu query `pluck` tambahan, dan
paling banyak **satu** baris pada masing-masing kasus — ERD menjamin hanya satu tahun
ajaran aktif per cabang, dan unique index menjamin hanya satu Grade Config ACTIVE per
(cabang, mapel, tahun ajaran). Perilaku bisnis kedua method tidak berubah sama sekali.

Jalur write lain sudah tercakup listener karena semuanya melewati model: `create()`,
`update()`, `updateOrCreate()`, `forceFill()->save()`, `delete()`, importer Excel, job
antrean, dan seluruh aksi Filament. Tidak ada `DB::table()` di seluruh `app/`.

**Seeder ikut menghasilkan baris audit, dan itu memang dibiarkan.** Menonaktifkannya
menuntut pengecualian khusus yang tidak diminta dokumen mana pun, sedangkan data yang lahir
dari seeder tetap data yang masuk ke sistem — `user_id` dan `ip_address`-nya NULL, dan
itulah yang membedakannya dari aksi manusia.

### 47. Perubahan peran & izin diinstrumentasi di jalur UI, bukan lewat event paket

| | |
| --- | --- |
| **Status** | Penutupan celah audit tanpa mengubah perilaku paket |
| **Dasar** | `03-architecture/04-security.md` — Audit Log: *"Semua aksi CUD … dicatat"* |

Mengubah peran seorang pengguna adalah aksi CUD yang mengubah **kewenangan**, dan justru
itu yang paling perlu terlacak. Namun perubahan itu tidak meninggalkan jejak apa pun pada
listener wildcard, karena dua sebab yang menumpuk:

1. Field "Peran" adalah relationship `belongsToMany`; Filament menyimpannya lewat `sync()`,
   dan `sync()` **tidak memicu model event apa pun**.
2. Bila hanya perannya yang diubah, `save()` tidak menemukan atribut yang kotor, sehingga
   event `updated` pada `User` pun tidak menyala.

Hasilnya: sebelum butir ini, mengganti peran pengguna dari GURU menjadi WALI_KELAS tidak
menghasilkan satu baris audit pun.

**Yang tidak dilakukan:** menyalakan `events_enabled => true` pada `config/permission.php`.
Itu akan membuat `RolePermissionSeeder` — 8 peran dan puluhan izin, dijalankan di **setiap**
test — melahirkan puluhan baris audit yang tidak menerangkan aksi manusia mana pun. Perilaku
paket pihak ketiga juga berubah secara global hanya untuk kebutuhan satu modul.

**Yang dilakukan:** instrumentasi eksplisit pada jalur UI yang memang mengubah kewenangan —
seluruhnya ada tiga, dan hanya tiga:

| Halaman | Yang berubah | Pencatatan |
| --- | --- | --- |
| `UserResource\Pages\EditUser` | Peran seorang pengguna | UPDATED atas `User`, `school_id` = cabang pengguna itu |
| `RoleResource\Pages\EditRole` | Izin sebuah peran | UPDATED atas `Role`, `school_id` NULL |
| `RoleResource\Pages\CreateRole` | Peran baru | CREATED atas `Role`, `school_id` NULL |

`school_id` NULL pada dua yang terakhir bukan kelalaian: definisi peran bersifat
platform-wide (PRD 1.1.1) dan tidak berada di dalam cabang mana pun.

**Pembuatan pengguna sengaja tidak diinstrumentasi.** `CreateUser` sudah menghasilkan satu
baris CREATED lewat listener, dan perannya ditetapkan pada aksi yang sama — baris kedua
hanya akan menduplikasi satu aksi manusia.

**Penjagaan terhadap baris ganda.** `EditUser` mencatat hanya bila peran berubah **dan**
`wasChanged()` bernilai false. Bila atribut pengguna ikut berubah, listener otomatis sudah
menulis satu baris UPDATED untuk penyimpanan yang sama; satu aksi manusia cukup diwakili
satu baris. Konsekuensi yang disengaja: baris itu tidak membedakan "atribut berubah" dari
"peran berubah" — konsisten dengan keputusan tidak menyimpan kolom `changes` (butir 45).

`AuditLogger::recordFor()` dipakai untuk jalur eksplisit ini dan sengaja **tidak** memeriksa
daftar pengecualian: pengecualian itu milik listener otomatis, sedangkan pemanggilan di sini
memang disengaja.

### 48. Biaya query audit: satu INSERT per aksi, tanpa pembacaan tambahan

`AuditLogger::record()` tidak menjalankan satu pun query baca: `shouldAudit()` murni PHP,
`schoolIdFor()` membaca atribut yang sudah dimuat, `Auth::id()` memakai pengguna yang sudah
ter-resolve, dan IP diambil dari properti yang diisi middleware. Yang tersisa hanyalah satu
`INSERT` — dan itu memang harga yang diminta requirement.

**Risiko yang perlu diketahui:**
`ReportCardPublishTest::test_generating_does_not_issue_queries_per_student` menjaga bahwa
menambah siswa tidak menambah query secara sebanding. Setelah audit aktif, angkanya
**11 → 14 query** dengan ambang 15 — lolos, tetapi hanya bersisa **satu** query. Fitur
berikutnya yang menambah satu penulisan per siswa akan membalikkannya.

Ambang itu **tidak dilonggarkan**: melonggarkan test yang masih hijau hanya menghapus
peringatan dini. Tidak ada pula optimasi yang aman untuk diambil — mem-*batch* baris audit
sampai akhir transaksi akan menambah state buffer dan menunda kemunculan jejak, dua hal yang
tidak diminta siapa pun demi satu query. Bila ambang itu kelak benar-benar tertembus,
yang perlu diperiksa lebih dulu adalah apakah generate rapor memang menambah penulisan baru
— bukan angkanya.

## Sprint 5 Batch 5.1 — Fondasi Keuangan & Jenis Tagihan

### 49. `payments.received_by` memakai `restrictOnDelete`, bukan `cascadeOnDelete`

ERD 2.2 menetapkan `payments.received_by` NOT NULL ("bendahara yang mencatat"), sehingga
`nullOnDelete` bukan pilihan. Konvensi FK non-nullable di project ini adalah
`cascadeOnDelete` (`class_subjects.teacher_id`, `grades.graded_by`) — tetapi di sini
konvensi itu **tidak** diikuti: akun pengguna memang dapat dihapus lewat UserResource
(`UserPolicy::delete()` mengizinkannya), dan dengan cascade, menghapus satu akun bendahara
akan ikut menghapus seluruh riwayat pembayaran yang pernah ia catat.

`restrictOnDelete` membuat database menolak penghapusan akun yang masih menempel pada
riwayat pembayaran. Konsekuensinya penghapusan pengguna semacam itu akan gagal di level
DB; itu memang hasil yang diinginkan — riwayat uang tidak boleh hilang sebagai efek
samping merapikan daftar pengguna. Nonaktifkan akunnya (`users.is_active`), jangan hapus.

Aturan yang dipakai: SECURITY/DATA INTEGRITY > konsistensi konvensi.

### 50. Jenis tagihan tidak memiliki jalur hapus sama sekali

SPP-01 poin 2: *"Jenis tagihan dapat dinonaktifkan tanpa menghapus histori."* Karena itu
`fee_types` tidak punya `deleted_at`, `FeeTypeResource` tidak punya `DeleteAction` maupun
bulk action apa pun, tidak ada halaman/route hapus, dan `FeeTypePolicy::delete()`
mengembalikan `false` tanpa syarat. Polanya sama dengan `StudentPolicy::delete()`
(SIS-02 poin 2) dan `GradeConfigPolicy::delete()` (butir 33).

Perlu diluruskan: penolakan mutlak di policy **tidak** mengikat Super Admin. `Gate::before`
pada `AppServiceProvider` mengembalikan `true` untuk setiap ability mereka dan
mendahului policy, sehingga `can('delete', $feeType)` tetap bernilai benar bagi Super
Admin. Yang benar-benar menutup penghapusan bagi semua peran adalah tidak adanya aksi UI
dan tidak adanya rute — bukan policy-nya.

Penonaktifan ditulis lewat `save()` pada model, bukan mass update, supaya event `updated`
tetap terpicu dan jejak auditnya tercatat (lihat butir 46).

### 51. Yang sengaja belum ada pada Batch 5.1

`student_fees` dan `payments` dibuat sebagai skema + model + relasi saja karena keduanya
dependensi inti Sprint 5. Yang **belum** diimplementasikan dan bukan kelalaian:
generate tagihan massal beserta preview-nya (SPP-02), pencatatan pembayaran dan upload
bukti (SPP-03), transisi status PARTIAL/PAID/WAIVED, portal orang tua (SPP-04), dan
ekspor laporan (SPP-05).

Khusus `FeeFrequency::Yearly` dan `FeeFrequency::Once`: dokumen menyebut ketiga frekuensi
pada SPP-01 dan ERD, tetapi hanya mendefinisikan perilaku penerbitan untuk yang bulanan
(SPP-02: "tagihan SPP bulanan ... due date otomatis diisi, misal tanggal 10 bulan
berjalan"). Semantik penerbitan YEARLY/ONCE karena itu **tidak dikarang** di batch ini —
keduanya baru tersimpan sebagai nilai enum. Menetapkannya sekarang berarti membuat aturan
bisnis tanpa dasar requirement.

Tidak ada pula kaitan otomatis ke `transactions` (buku kas): ERD tidak memuat kolom
penghubungnya dan KAS adalah modul Sprint 6.

## Sprint 5 Batch 5.2 — Generate Tagihan Massal (SPP-02)

### 52. Idempotency penerbitan massal tanpa unique index bisnis

ERD 2.2 tidak menetapkan keunikan apa pun pada `student_fees`, dan menambahkan
unique composite berarti mengarang aturan bisnis: blueprint tidak pernah menyatakan
seorang siswa hanya boleh punya satu tagihan per jenis per periode. Yang dibutuhkan
di sini bukan aturan bisnis melainkan **idempotency teknis** — pengaman agar retry,
klik ganda, dan dua permintaan identik tidak melipatgandakan tagihan.

Tiga lapis, semuanya memakai stack yang sudah ada:

1. **Gerbang pratinjau di UI.** Tombol Terbitkan baru hidup setelah pratinjau, dan
   pratinjau dilupakan begitu penerbitan dikirim — klik kedua harus melalui
   pratinjau lagi. Diperiksa di `generate()`, bukan hanya lewat tombol yang
   dinonaktifkan, karena permintaan Livewire bisa dikirim langsung.
2. **`ShouldBeUnique` pada job**, dengan `uniqueId()` = `school:feeType:period`.
   Dua permintaan identik yang masih menunggu di antrean dilipat menjadi satu.
3. **Lock aplikasi + pelewatan kombinasi yang sudah ada** di
   `StudentFeeGenerator::generate()`. Lock memakai cache driver `database` yang
   sudah dipakai project (tabel `cache_locks` sudah ada sejak migration bawaan
   Laravel), sehingga tidak ada infrastruktur baru. Di dalam lock, kombinasi
   `school_id + student_id + fee_type_id + period` yang sudah ada dilewati.

Lapis 3 yang menutup celah baca-lalu-tulis antara dua worker yang memproses
kombinasi sama secara bersamaan — lapis 1 dan 2 hanya mengurangi peluangnya.

**Batasnya perlu diketahui:** tanpa constraint database, jaminannya berlaku
selama seluruh penerbitan melewati `StudentFeeGenerator`. Penulisan `student_fees`
lewat jalur lain (mis. seeder atau perintah artisan yang ditulis kemudian) tidak
ikut terlindungi. Bila kelak requirement menyatakan keunikan itu memang aturan
bisnis, unique index adalah tempat yang benar untuk menegakkannya — dan saat itu
lock ini boleh dilepas.

### 53. Tahun ajaran tagihan: dari jenis tagihan, lalu tahun ajaran aktif

`student_fees.academic_year_id` nullable pada ERD tanpa keterangan. Yang dipakai:
nilai dari `fee_types.academic_year_id` bila jenis tagihannya memang terikat satu
tahun ajaran; bila NULL ("tagihan berulang" menurut ERD), dipakai tahun ajaran
**aktif** cabang tersebut — definisi yang sama dengan `AcademicYear::current()`
yang berlaku di seluruh project. Bila cabang belum punya tahun ajaran aktif,
nilainya tetap NULL, sesuai kolom yang memang nullable.

Alternatifnya — selalu NULL — membuang konteks yang sudah tersedia dan akan
mempersulit laporan per tahun ajaran (SPP-05) tanpa memberi keuntungan apa pun.

### 54. Tidak ada scheduler recurrence untuk MONTHLY/YEARLY/ONCE

`fee_types.frequency` menyimpan MONTHLY/YEARLY/ONCE, tetapi blueprint hanya
mendefinisikan perilaku penerbitan untuk yang bulanan (SPP-02: "tagihan SPP
bulanan ... due date otomatis diisi, misal tanggal 10 bulan berjalan"). Semantik
penerbitan YEARLY dan ONCE tidak dinyatakan di mana pun.

Karena itu Batch 5.2 **tidak** membuat scheduler, cron, maupun recurring billing:
penerbitan selalu tindakan eksplisit operator, dan `period` selalu dipilih
sendiri. YEARLY tidak diterjemahkan menjadi 12 record, dan ONCE tidak diberi
perlakuan "berlaku selamanya" — keduanya akan menjadi aturan bisnis karangan.
Frekuensi untuk sekarang berfungsi sebagai keterangan pada jenis tagihan;
idempotency exact-match tetap berlaku sama untuk ketiganya.

### 55. Snapshot nominal, dan mengapa penerbitan tidak memakai bulk insert

Setiap `student_fees.amount` adalah salinan `fee_types.amount` pada saat
penerbitan. Mengubah nominal jenis tagihan sesudahnya tidak menggeser tagihan
yang sudah terbit — polanya sama dengan snapshot bobot pada `grades` (butir 34).

Tagihan dibuat satu `create()` per siswa, bukan satu `DB::insert()` massal. Bulk
insert lewat query builder tidak memicu model event sama sekali (butir 46),
sehingga seluruh jejak audit CREATED akan hilang justru pada operasi yang paling
banyak membuat data. Sisi **baca**-nya tetap konstan: daftar siswa aktif dan
daftar tagihan yang sudah ada masing-masing diambil satu kali, tidak per siswa —
dijaga `GenerateStudentFeeJobTest::test_reads_do_not_grow_with_the_number_of_students`.

Konsekuensinya penerbitan untuk 500 siswa berarti 500 INSERT tagihan + 500 INSERT
audit. Itu memang mahal, dan justru itulah alasan requirement menempatkannya di
antrean, bukan di dalam request.

### 56. Jejak audit penerbitan ber-`user_id` NULL

Baris audit yang ditulis worker tidak punya pengguna: antrean berjalan tanpa
request dan tanpa sesi. `AuditLogger` sudah dirancang untuk itu (`Auth::id()`
menghasilkan NULL), dan `school_id`-nya tetap terisi karena diambil dari record
tagihannya sendiri. NULL di sini berarti "ditulis sistem", bukan data yang hilang.

Pratinjau tidak menulis apa pun, sehingga tidak menghasilkan baris audit
`student_fees` sama sekali; retry yang seluruhnya melewati tagihan yang sudah ada
juga tidak menambah satu baris CREATED pun.

## Sprint 5 Batch 5.3 — Catat Pembayaran, Cicilan & Bukti (SPP-03)

### 57. Izin pembayaran memakai `payment.*`, bukan `fee.*`

PRD 1.1.2 memuat dua baris yang mudah tertukar. "Tagihan SPP" memberi KEPALA ⭕,
sedangkan "Catat Pembayaran" memberi KEPALA **❌** — kepala sekolah boleh melihat
tagihan tetapi tidak boleh menyentuh pembayarannya sama sekali. `PermissionName` sudah
memisahkan keduanya sejak Sprint 1 (`fee.view`/`fee.manage` dan
`payment.view`/`payment.manage`), dan `RolePermissionSeeder` membagikannya persis
mengikuti dua baris itu: Bendahara dan School Admin mendapat `payment` → manage,
Kepala Sekolah tidak mendapat `payment` sama sekali.

`PaymentPolicy` karena itu memakai `payment.*`. Memakai `fee.manage` juga akan menolak
Kepala Sekolah — mereka hanya punya `fee.view` — tetapi menolaknya karena alasan yang
salah: matriks memisahkan kedua modul, dan menyamakannya akan membuat setiap perubahan
kewenangan pada satu modul diam-diam mengubah modul lainnya.

`StudentFeePolicy` tetap memakai `fee.*`: daftar tagihan adalah modul "Tagihan SPP".

### 58. Uang dihitung dengan bcmath, bukan float

`amount` dan `amount_paid` adalah `DECIMAL(12,2)` pada ERD, dan Eloquent membacanya
sebagai string. `PaymentRecorder` mempertahankannya sebagai string di sepanjang jalur —
`bcadd`, `bcsub`, `bccomp` dengan skala 2 — dan tidak pernah mengonversinya ke float
untuk dibandingkan.

Ini bukan kehati-hatian teoretis. Cicilan 0,10 lalu 0,20 atas tagihan 0,30 akan
menghasilkan `0.30000000000000004` dalam aritmetika float, sehingga
`totalPaid >= amount` bernilai benar secara kebetulan pada satu kasus dan salah pada
kasus lain — tagihan yang sudah lunas tercatat PARTIAL, atau sebaliknya. Kasus itu
diuji langsung (`test_decimal_installments_settle_exactly`).

Satu-satunya konversi float yang tersisa ada pada pembacaan `SUM()` dari database dan
pada normalisasi input; keduanya segera dibulatkan kembali ke dua desimal lewat
`number_format()` sebelum masuk perhitungan.

### 59. Kelebihan bayar ditolak karena blueprint tidak menetapkannya

SPP-03 hanya menyebut "status tagihan otomatis berubah ke PAID/PARTIAL". Tidak ada satu
pun dokumen yang menjelaskan apa yang terjadi bila pembayaran melebihi sisa tagihan:
tidak ada saldo siswa, tidak ada kembalian, tidak ada kolom untuk keduanya pada ERD
`students` maupun `student_fees`, dan tidak ada endpoint refund pada API 4.9.

Ketiga tafsir yang mungkin — menerima dan menyimpan kelebihannya, menerima dan
memotongnya, atau menolak — sama-sama tidak berdasar dokumen. Yang dipilih adalah yang
tidak mengarang entitas baru **dan** menjaga invarian yang memang tertulis:
`amount_paid` tidak pernah melampaui `amount`. Pembayaran yang membuat akumulasinya
melewati nominal tagihan ditolak, dengan pesan yang menyebut sisa tagihannya, sehingga
operator dapat mencatat jumlah yang benar.

Konsekuensi yang perlu diketahui pemilik produk: bila di lapangan orang tua benar-benar
membayar lebih, Phase 1 tidak punya tempat untuk mencatat kelebihannya. Keputusan
produknya (saldo, kembalian tunai, atau alokasi ke tagihan periode berikutnya) belum
diambil dan sengaja tidak diambil di sini.

### 60. Tagihan WAIVED menolak pembayaran

UI pembebasan tagihan belum ada — `PATCH /student-fees/{id}/waive` pada API 4.9 bukan
lingkup batch ini — tetapi statusnya sudah ada di ERD dan `waive_reason` sudah ada di
tabel. Blueprint tidak menjelaskan perilaku pembayaran atas tagihan yang sudah
dibebaskan.

Menerimanya berarti pembayaran diam-diam membatalkan pembebasan: status berpindah ke
PARTIAL atau PAID sementara `waive_reason` masih terisi, dan tidak ada jejak bahwa
pembebasan itu pernah dicabut oleh siapa pun. `PaymentRecorder` karena itu menolaknya,
dan aksi "Catat Pembayaran" disembunyikan untuk tagihan WAIVED. Bila nanti pembebasan
memang perlu dapat dicabut, jalurnya adalah aksi pencabutan tersendiri yang tercatat —
bukan efek samping sebuah pembayaran.

### 61. `amount_paid` dihitung ulang dari `payments`, bukan ditambahkan

Akumulasi tidak dilakukan dengan `amount_paid + amount`. Setiap pencatatan menjumlahkan
ulang seluruh baris `payments` milik tagihan itu di dalam transaksi yang sama, lalu
menuliskan hasilnya.

Bedanya muncul ketika kolom ringkasan itu pernah menyimpang — karena impor data, karena
perbaikan manual di database, atau karena bug yang sudah diperbaiki. Penambahan akan
meneruskan simpangan itu selamanya; penjumlahan ulang mengembalikannya pada pembayaran
berikutnya. Riwayat `payments` adalah kebenarannya, dan `student_fees.amount_paid`
hanyalah ringkasan yang dapat dibentuk ulang. Perilakunya diuji lewat
`test_a_drifted_amount_paid_is_recomputed_from_the_payment_history`.

Biayanya satu `SUM()` beragregat per pembayaran, di bawah lock yang memang sudah
dipegang — bukan pembacaan tambahan yang berarti.

### 62. Row lock, bukan lock aplikasi, untuk akumulasi pembayaran

Penerbitan massal (butir 52) memakai `Cache::lock` karena yang dijaga adalah kombinasi
bisnis yang belum tentu punya baris. Pembayaran berbeda: barisnya sudah ada, jadi yang
dipakai adalah `lockForUpdate()` atas baris `student_fees`-nya, di dalam
`DB::transaction`.

Urutannya adalah keseluruhan gunanya — kunci diambil **sebelum** sisa tagihan dibaca.
Dua pencatatan yang hampir bersamaan atas tagihan yang sama tanpa itu akan sama-sama
membaca sisa yang sudah basi, keduanya lolos pemeriksaan "tidak melebihi sisa", dan
akumulasinya menjadi salah (lost update). Dengan lock, yang kedua menunggu, membaca
sisa setelah yang pertama tersimpan, lalu ditolak bila memang tidak muat.

MySQL 8 adalah target produksinya dan menghasilkan `SELECT ... FOR UPDATE`. SQLite —
yang dipakai `phpunit.xml` — tidak mengenal klausa itu dan mengabaikannya; test
`test_the_student_fee_row_is_locked_before_its_balance_is_read` karena itu memeriksa
grammar-nya sesuai driver yang sedang berjalan, bukan berpura-pura SQLite menjaga hal
yang sama. Tidak ada infrastruktur baru yang ditambahkan.

### 63. Bukti pembayaran di disk privat, dan jalurnya tidak dipercaya

`03-architecture/04-security.md` menetapkan berkas unggahan "disimpan di storage/ (di
luar web root)". Foto siswa dan logo cabang melanggar itu secara sadar karena keduanya
memang ditampilkan sebagai gambar publik (butir 42); bukti pembayaran tidak. Disknya
`local` (`storage/app/private`) tanpa `url`, sama seperti PDF rapor, dan satu-satunya
jalan mengambilnya adalah aksi unduh yang memeriksa `PaymentPolicy::downloadProof`.

Nama berkasnya dibuat Filament (UUID), bukan diambil dari nama unggahan pengguna, dan
direktorinya dipisah per `school_id`. `PaymentRecorder` memeriksa ulang jalur yang
dikirim: hanya berkas di dalam `payment-proofs/{school_id}` milik tagihan itu yang
diterima, dan jalur yang memuat `..` ditolak. Tanpa pemeriksaan ini state Livewire dapat
menunjuk berkas cabang lain — atau berkas apa pun di disk itu — dan tersimpan sebagai
"bukti".

Nama berkas unduhannya pun dibentuk dari id pembayaran, bukan dari jalur penyimpanannya,
sehingga struktur penyimpanan tidak ikut terbaca pengguna.

### 64. `payments` bersifat append-only dari UI

ERD memberi `payments` sebuah `created_at` saja — tidak ada `updated_at`, tidak ada
kolom status, tidak ada soft delete — dan API 4.9 tidak memuat `PUT /payments/{id}`
maupun `DELETE /payments/{id}`. Tabel itu adalah riwayat.

`PaymentPolicy::update()` dan `::delete()` karena itu menolak secara mutlak, dan
riwayat pembayaran pada halaman detail tidak menyediakan aksi ubah, hapus, maupun aksi
massal. Salah catat diselesaikan dengan pencatatan baru, bukan dengan mengubah baris
lama — dan jalur koreksinya sendiri (pembatalan pembayaran) belum ada di dokumen mana
pun, sehingga tidak dikarang di sini.

Berlaku peringatan yang sama seperti pada `FeeTypePolicy` (butir 50): `Gate::before`
meloloskan Super Admin untuk **setiap** ability, sehingga penolakan mutlak di policy
tidak mengikat mereka. Yang mengikat semua peran adalah tidak adanya aksi UI dan tidak
adanya rute — bukan policy-nya.

### 65. `received_by` selalu pencatat yang sebenarnya

Berbeda dari penerbitan massal, yang jejak auditnya ber-`user_id` NULL karena worker
antrean tidak punya sesi (butir 56), pembayaran selalu dicatat oleh orang. `received_by`
diambil dari `Auth` lewat argumen layanan dan tidak pernah dibaca dari payload — begitu
pula `school_id` dan `student_id`, yang diturunkan dari tagihannya.

Satu pembayaran normal menghasilkan dua baris audit: `Payment` CREATED dan `StudentFee`
UPDATED. Keduanya benar — dua entity bisnis memang berubah — dan keduanya ditulis
listener wildcard yang sudah ada, tanpa logger baru. Karena keduanya berada di dalam
transaksi yang sama, pembayaran yang gagal tidak meninggalkan satu pun baris audit.

### 66. Yang sengaja belum ada pada Batch 5.3

UI pembebasan tagihan (WAIVED), portal orang tua (SPP-04), ekspor laporan (SPP-05),
notifikasi tagihan terbit, kaitan ke buku kas `transactions` (KAS, Sprint 6), integrasi
payment gateway (Phase 2), dan REST API. `PaymentMethod::PaymentGateway` tetap ada di
enum dan di kolom ENUM database karena ia bagian dari ERD; yang tidak ada adalah
kemampuan memilihnya maupun mengirimnya pada alur Phase 1.

## Sprint 5 Batch 5.4 — Pembebasan Tagihan (WAIVED)

### 67. Pembebasan tagihan adalah kewenangan Admin Sekolah, bukan Bendahara

Seluruh yang dikatakan blueprint tentang pembebasan ada di tiga baris:

- ERD 2.2 — `student_fees.status` memuat `WAIVED`
- ERD 2.2 — `waive_reason` VARCHAR(200) NULL, "alasan dibebaskan (jika status WAIVED)"
- API 4.9 — `PATCH /student-fees/{id}/waive`, Auth Level **Admin**, "bebaskan tagihan
  dengan alasan (status → WAIVED)"

PRD 1.2.6 tidak memuat user story pembebasan sama sekali, dan matriks 1.1.2 tidak punya
baris "Pembebasan Tagihan". Yang tersisa karena itu adalah label Auth Level-nya, dan
API 4.1 mendefinisikannya persis: *"Auth Level: Admin = Wajib token + role SCHOOL_ADMIN /
SUPER_ADMIN"*.

Kewenangannya karena itu **tidak** digantung pada `fee.manage`. Izin itu juga dipegang
BENDAHARA, dan menggantungkan pembebasan padanya berarti memberi Bendahara wewenang yang
tidak pernah diberikan dokumen mana pun kepada mereka.

Ini berbeda dari SPP-01, SPP-02, dan SPP-03. Ketiganya juga berlabel "Admin" di API 4.9,
tetapi masing-masing punya user story yang menyebut Bendahara secara eksplisit ("Sebagai
Bendahara, saya dapat membuat jenis tagihan baru / men-generate tagihan / mencatat
pembayaran"), dan user story adalah sumber yang lebih spesifik. Untuk pembebasan tidak
ada yang menimpa label itu, sehingga label itulah yang berlaku.

Polanya sudah ada di project: `GradeConfigPolicy` menolak menggantungkan konfigurasi
bobot pada `grade.manage` dengan alasan yang sama persis — izin itu juga dipegang Guru,
sedangkan NILAI-05 menyebut Admin Sekolah. `StudentFeePolicy::waive()` mengikuti idiom
itu (`hasRole(SchoolAdmin)`; Super Admin lolos lewat `Gate::before`), sehingga tidak ada
izin baru dan `RolePermissionSeeder` — yang bentuknya hanya `{modul}.view` /
`{modul}.manage` — tidak perlu disesuaikan.

Arahnya juga yang lebih aman. Mencatat pembayaran berarti mengakui uang yang masuk;
membebaskan tagihan berarti memutuskan uang itu tidak akan pernah masuk. Bendahara tetap
memegang seluruh kewenangan lainnya di modul keuangan.

### 68. Tagihan yang sudah menerima pembayaran tidak dapat dibebaskan

Blueprint tidak menjelaskan apa yang terjadi bila tagihan yang sudah dibayar sebagian
atau lunas dibebaskan, dan — yang menentukan — tidak menyediakan apa pun untuk
menyelesaikan akibatnya: tidak ada endpoint refund pada API 4.9, tidak ada kolom saldo
atau kredit siswa pada ERD, tidak ada tabel pembalikan, dan `payments` tidak punya kolom
status yang dapat menandai sebuah pembayaran sebagai batal.

Ketiga tafsir yang mungkin sama-sama merusak sesuatu:

- menerima dan membiarkan `amount_paid` tetap terisi → tagihan berstatus DIBEBASKAN
  padahal uangnya sudah diterima, dan laporan penerimaan menjadi tidak dapat dijelaskan
- menerima dan menolkan `amount_paid` → angka yang tidak cocok dengan riwayat
  `payments`, yang justru merupakan sumber kebenarannya (butir 61)
- menerima dan menghapus `payments`-nya → menghapus jejak uang yang benar-benar diterima

Yang dipilih adalah menolak: pembebasan hanya sah bagi tagihan berstatus UNPAID yang
belum menerima satu rupiah pun. Riwayat pembayaran tidak pernah disentuh oleh percobaan
pembebasan, dan tidak ada refund, kredit, maupun pembalikan yang dikarang.

Pemeriksaannya tidak hanya membaca kolom ringkasan `amount_paid` tetapi juga keberadaan
baris `payments`. Bila ringkasan itu pernah menyimpang menjadi nol, yang tidak boleh
terjadi adalah pembebasan yang lolos karena angka yang salah.

Konsekuensi operasionalnya perlu diketahui pemilik produk: bila di lapangan sebuah
tagihan yang sudah dicicil memang harus dibebaskan, Phase 1 belum punya jalurnya.
Keputusan produknya — apakah uangnya dikembalikan, dialihkan ke periode berikutnya, atau
pembayarannya dibatalkan dengan jejak tersendiri — belum diambil dan sengaja tidak
diambil di sini.

### 69. Pembebasan bukan keadaan yang dapat dimasuki dua kali

Tagihan yang sudah WAIVED menolak pembebasan berikutnya, dan alasan yang sudah tercatat
tidak ditimpa. Pembebasan kedua atas tagihan yang sama tidak menambah apa pun kecuali
menghapus jejak keputusan pertama — sedangkan `waive_reason` justru satu-satunya
penjelasan mengapa tagihan itu tidak akan pernah tertagih.

Blueprint juga tidak menyediakan pencabutan pembebasan: tidak ada endpoint unwaive, dan
tidak ada alur yang mengembalikan tagihan WAIVED ke UNPAID. Karena itu tidak dibuat.
Bila nanti pencabutan memang dibutuhkan, jalurnya adalah aksi tersendiri yang tercatat —
bukan efek samping dari pembayaran (butir 60) maupun dari pembebasan kedua.

### 70. Satu baris terkunci menyelesaikan lomba waive vs payment

`StudentFeeWaiver` dan `PaymentRecorder` mengunci baris `student_fees` yang sama dengan
`lockForUpdate()` di dalam `DB::transaction`, dan keduanya membaca keadaan tagihan
**setelah** kunci itu dipegang.

Akibatnya lomba antara "bebaskan" dan "catat pembayaran" selalu berakhir pada satu
keadaan yang konsisten, tanpa koordinasi tambahan:

- pembebasan menang → pembayaran menunggu, membaca status WAIVED, lalu ditolak guard
  Batch 5.3 (butir 60)
- pembayaran menang → pembebasan menunggu, melihat `amount_paid` sudah terisi beserta
  baris `payments`-nya, lalu ditolak guard butir 68

Tidak ada keadaan di mana pembayaran masuk ke tagihan yang sudah dibebaskan. Tidak ada
infrastruktur baru: kunci yang dipakai adalah kunci baris database yang sudah ada.

MySQL 8 adalah target produksinya dan menghasilkan `SELECT ... FOR UPDATE`. SQLite —
yang dipakai `phpunit.xml` — tidak mengenal klausa itu; test memeriksa grammar sesuai
driver yang sedang berjalan, dan menguji kedua arah guard-nya secara berurutan alih-alih
berpura-pura menjalankan dua koneksi paralel.

### 71. Sisa tagihan pindah ke model, bukan disalin

`remaining()` sekarang milik `StudentFee`; `PaymentRecorder::remainingFor()` tetap ada
sebagai pembungkus yang meneruskan ke sana. Alasannya bukan kerapian: konsumen
berikutnya adalah portal orang tua (SPP-04), dan dua rumus sisa tagihan yang dihitung di
dua tempat cepat atau lambat akan berbeda.

Ditambahkan pula `scopeForStudent()` dan `scopeWithBillingDetail()` — keduanya murni
pemilihan dan eager load, tanpa aturan bisnis. Tidak ada tabel, kolom, snapshot, rute,
maupun view portal yang dibuat: SPP-04 bukan lingkup batch ini. Yang dipastikan hanya
bahwa seluruh data yang nanti dibutuhkannya — siswa, jenis tagihan, periode, nominal,
terbayar, sisa, jatuh tempo, status, alasan pembebasan, dan riwayat pembayaran beserta
tanggal/metode/referensi/pencatatnya — sudah dapat dibaca dari model yang ada.

### 72. Yang sengaja belum ada pada Batch 5.4

Pencabutan pembebasan, refund, kredit/saldo siswa, pembalikan pembayaran, portal orang
tua (SPP-04), ekspor laporan (SPP-05), notifikasi tagihan, buku kas `transactions`
(Sprint 6), dashboard keuangan, integrasi payment gateway (Phase 2), dan REST API.
Pembebasan massal juga tidak ada: API 4.9 memberi `waive` sebuah `PATCH` untuk satu id,
dan membebaskan banyak tagihan sekaligus adalah keputusan yang layak dilakukan satu per
satu.

## Sprint 6 Batch 6.1 — Buku Kas Sekolah (KAS-01)

### 73. Bendahara berwenang atas buku kas, dan itu bukan kebalikan butir 67

API 4.9 memberi `POST /transactions` dan `PUT /transactions/{id}` label Auth Level
"Admin", sama seperti `PATCH /student-fees/{id}/waive`. Kesimpulannya tetap berbeda, dan
alasannya konsisten: KAS-01 menyebut pelakunya secara eksplisit — *"Sebagai **Bendahara**,
saya dapat mencatat pemasukan dan pengeluaran kas sekolah"* — sedangkan pembebasan
tagihan tidak punya satu pun user story (butir 67).

Aturannya satu: user story dan matriks izin adalah sumber yang lebih spesifik daripada
label Auth Level pada tabel API; bila keduanya diam, label itulah yang berlaku. Pola yang
sama sudah dipakai SPP-01, SPP-02, dan SPP-03.

Matriks 1.1.2 modul "Akuntansi & Kas" memberi SUPER_ADMIN ✅, SCHOOL_ADMIN ✅, KEPALA ⭕,
GURU/WALI ❌, BENDAHARA ✅, SISWA ❌, ORTU ❌ — dan `RolePermissionSeeder` sudah membagikan
`accounting.view` / `accounting.manage` persis mengikuti baris itu sejak Sprint 1. Tidak
ada izin baru yang dibuat.

### 74. "Soft delete" pada API 4.9 tidak punya tempat menyimpannya — konflik yang belum selesai

API 4.9 memuat `DELETE /transactions/{id}` dengan keterangan "Hapus transaksi (soft
delete)". ERD 2.2 `transactions` tidak memuat `deleted_at`, tidak memuat kolom status,
tidak memuat flag aktif, dan tidak ada bagian blueprint mana pun yang menjelaskan
bagaimana soft delete itu bekerja — tidak di ERD, tidak di PRD, tidak di dokumen
arsitektur.

Ketiga jalan keluar yang tersedia semuanya buruk:

- **hard delete** — bukan yang diminta dokumen ("soft delete"), dan menghapus baris buku
  kas secara permanen justru kebalikan dari maksudnya
- **menambah `deleted_at`** — mengubah skema blueprint atas dasar satu kata di tabel API
- **menambah status/flag sendiri** — mengarang kolom sekaligus mengarang semantiknya
  (apakah baris yang "dihapus" masih dihitung di saldo? masih muncul di laporan?)

Karena itu penghapusan **tidak diimplementasikan sama sekali** pada batch ini:
`TransactionPolicy::delete()` menolak tanpa syarat, `TransactionResource` tidak punya
`DeleteAction` maupun bulk action, tidak ada rute hapus, dan `TransactionRecorder` tidak
punya method penghapusan. Skemanya dibuat persis seperti ERD.

Ini dicatat sebagai **konflik blueprint yang belum selesai**, bukan sebagai keputusan
final. Yang dibutuhkan dari pemilik produk adalah semantiknya, bukan sekadar izin
menambah kolom: baris yang dihapus tetap dihitung di saldo kas atau tidak, tampil di
laporan periode berjalan atau tidak, dan siapa yang boleh menghapusnya. Sampai itu ada,
koreksi dilakukan lewat edit (`PUT`), yang memang tersedia dan terlacak di `audit_logs`.

Catatan yang berlaku sama seperti pada `FeeTypePolicy` (butir 50): `Gate::before`
meloloskan Super Admin untuk setiap ability, sehingga penolakan mutlak di policy tidak
mengikat mereka. Yang mengikat semua peran adalah tidak adanya aksi UI dan tidak adanya
rute.

### 75. `payments` dan `transactions` tetap dua domain terpisah

ERD menyebut `transactions` sebagai buku kas untuk pemasukan dan pengeluaran umum
"**di luar** tagihan SPP". Tidak ada kolom penghubung ke `payments` di kedua arah, dan
tidak ada endpoint rekonsiliasi di API 4.9.

Karena itu mencatat pembayaran SPP **tidak** menghasilkan baris `transactions`, dan tidak
ada relasi Eloquent antara keduanya. Godaannya nyata — KAS-02 nanti meminta "total
penerimaan SPP bulan ini" pada dashboard yang sama — tetapi angka itu dapat dihitung dari
`payments` langsung, tanpa menyalin uangnya ke buku kas dan tanpa mengarang aturan kapan
sebuah pembayaran menjadi transaksi kas.

Membuat kaitan otomatis sekarang berarti memutuskan pertanyaan akuntansi yang belum
ditanyakan: apakah penerimaan SPP masuk kas pada tanggal pembayaran atau tanggal setor,
apakah pembayaran yang dikoreksi ikut mengoreksi kas, dan apa yang terjadi pada tagihan
yang dibebaskan. Perilakunya dijaga test (`test_recording_a_payment_creates_no_cash_book_entry`).

### 76. Batas ukuran bukti transaksi mengikuti pola yang sudah ada

KAS-01 hanya menyebut "bukti dapat dilampirkan (scan nota/kwitansi)" — tanpa format dan
tanpa batas ukuran. Yang dipakai karena itu bukan angka baru:

- **format** — `03-architecture/04-security.md` menetapkan secara global "hanya JPG/PNG/PDF
  diperbolehkan"; daftarnya tidak diperluas
- **ukuran** — 5 MB, mengikuti bukti pembayaran (SPP-03), berkas sejenis di modul yang
  sama. Batas 2 MB pada foto siswa dan dokumen PPDB tidak dipakai karena keduanya
  menyangkut berkas yang berbeda sifatnya

Penyimpanannya mengikuti pola bukti pembayaran sepenuhnya: disk `local`
(`storage/app/private`, di luar web root), direktori `transaction-proofs/{school_id}`,
nama berkas UUID dari Filament, dan unduhan lewat aksi yang memeriksa
`TransactionPolicy::downloadProof`.

Pemeriksaan jalur berkasnya dipindahkan ke `App\Support\ProofPath` dan kini dipakai
bersama oleh bukti pembayaran dan bukti transaksi. Alasannya bukan kerapian: dua salinan
sebuah pagar keamanan cepat atau lambat akan berbeda, dan yang tertinggal justru yang
tidak diperbaiki. Perilaku sisi pembayaran tidak berubah — 20 test bukti pembayaran dari
Batch 5.3 tetap hijau tanpa satu pun disentuh.

### 77. `category` adalah teks bebas, bukan enum

ERD: *"Kategori: Gaji, Pembelian Alat, Dana BOS, Sumbangan, **dll.**"* — kata terakhir itu
yang menentukan. Kolomnya `VARCHAR(100)`, bukan ENUM, sehingga keempat contoh itu adalah
saran dan bukan daftar tertutup.

Membuat enum atau tabel master kategori berarti memutuskan sesuatu yang tidak diminta:
siapa yang boleh menambah kategori, apakah kategori dapat dinonaktifkan, dan apa yang
terjadi pada transaksi lama saat kategorinya dihapus. Yang dipakai adalah `TextInput`
biasa dengan `datalist` berisi keempat contoh sebagai saran ketik; kategori lain tetap
diterima, dibatasi hanya oleh panjang kolomnya.

### 78. Arah kas ada di `type`, bukan di tanda `amount`

`amount` selalu positif untuk INCOME maupun EXPENSE. ERD memberi kolom itu
`DECIMAL(12,2)` tanpa keterangan negatif, dan ada kolom `type` tersendiri yang menyatakan
arahnya — menyimpan pengeluaran sebagai angka negatif akan membuat dua sumber kebenaran
untuk hal yang sama, dan setiap penjumlahan harus memilih salah satunya.

Perhitungan dan pembandingannya memakai bcmath, bukan float, mengikuti butir 58. Batas
atasnya diperiksa eksplisit terhadap `9999999999.99`: MySQL akan menolak nilai di atas
`DECIMAL(12,2)`, tetapi ditolak sebagai pesan validasi lebih baik daripada sebagai galat
database.

### 79. Edit tanpa `updated_at`

API 4.9 menyediakan `PUT /transactions/{id}` dan `TransactionRecorder::update()`
melayaninya, tetapi `transactions` tidak punya `updated_at` — dan kolom itu tidak
ditambahkan. Riwayat perubahannya sudah ada di `audit_logs`, yang mencatat siapa, kapan,
dan dari IP mana.

`created_by` dan `school_id` tidak pernah ikut berubah saat edit. Yang pertama adalah
pencatat aslinya — menimpanya dengan editor berarti kehilangan satu-satunya penanda siapa
yang mula-mula memasukkan uang itu ke buku kas, dan blueprint tidak pernah memintanya.
Yang kedua adalah cabang tempat uangnya bergerak; memindahkannya berarti memindahkan
transaksi antar buku kas.

### 80. Yang sengaja belum ada pada Batch 6.1

Penghapusan transaksi (butir 74), dashboard ringkasan keuangan (KAS-02), dashboard lintas
cabang (KAS-03), ekspor laporan (SPP-05 dan `GET /finance/export`), `GET /finance/summary`
dan `/finance/spp-report`, portal orang tua (SPP-04), notifikasi, serta REST API. Saldo
kas juga belum dihitung di mana pun: itu KAS-02, dan mendefinisikan saldo tanpa menyelesaikan
butir 74 lebih dulu berarti menghitung angka yang belum jelas isinya.

### 81. Keterangan dan nomor referensi wajib di alur kerja, tetap NULL di skema

KAS-01 pada PRD 1.2.6 mencantumkan keterangan dan nomor referensi di dalam daftar
fieldnya: *"Field: jenis (INCOME/EXPENSE), kategori, jumlah, tanggal, keterangan, no.
referensi."* Companion database specification yang dipakai saat review menyatakan keenam
field itu sebagai field validasi **wajib**: `type`, `category`, `amount`,
`transaction_date`, `description`, dan `reference_number`.

ERD 2.2 memberi dua kolom terakhir `NULL`.

Keduanya tidak bertentangan. Nullability kolom database menyatakan apa yang **boleh
tersimpan** — termasuk baris lama, baris hasil impor, dan baris yang ditulis jalur lain —
sedangkan aturan validasi menyatakan apa yang **boleh dikirim seorang operator lewat alur
KAS-01**. Yang diubah karena itu hanya lapisan validasinya; `create_transactions_table`
tidak disentuh, dan sebuah test memastikannya (`test_the_migration_nullability_was_not_changed`)
dengan menulis NULL ke kedua kolom lewat factory.

Kewajibannya ditegakkan di dua tempat, seperti seluruh aturan tulis modul keuangan:
`required()` pada form Filament, dan pemeriksaan ulang di `TransactionRecorder` — karena
payload Livewire dapat dirakit sendiri dan validasi UI bukan pagar.

Aturannya berlaku pada CREATE maupun EDIT: `record()` dan `update()` memakai
`resolveAttributes()` yang sama, sehingga baris lama yang terlanjur tersimpan tanpa
keduanya tidak dapat disimpan ulang sebelum dilengkapi.

Perlu dibedakan dari `payments.reference_number`, yang sengaja tetap opsional (butir 57
bagian referensi): pembayaran tunai memang tidak punya nomor transfer, dan SPP-03
menyebut referensi sebagai isian form, bukan isian wajib. Buku kas berbeda — setiap
barisnya bersandar pada nota atau kuitansi yang nomornya dapat ditelusuri, dan uang yang
bergerak tanpa keterangan adalah persis yang membuat buku kas tidak dapat diaudit.

Catatan sumber, supaya dasarnya tidak hilang: daftar fieldnya berasal dari KAS-01 di
`smartsukses-docs/01-prd/02-features-phase1.md`, sedangkan status **wajib**-nya berasal
dari companion database specification yang dipakai dalam review — dokumen itu sendiri
belum ada di `smartsukses-docs/`, sehingga tidak dapat dirujuk lewat path. Ini bukan
keputusan owner yang diberikan secara eksplisit, melainkan pembacaan spesifikasi validasi
tersebut. Bila dokumen itu kelak masuk ke repositori, butir ini yang perlu dicocokkan
dengannya.

## Sprint 6 Batch 6.2 — Laporan Keuangan Bulanan (KAS-02)

### 82. Saldo kas adalah posisi, bukan pergerakan bulan itu

AC KAS-02 berbunyi: *"Dashboard keuangan menampilkan: saldo kas, total penerimaan SPP
bulan ini, total pengeluaran bulan ini."* Perhatikan letak keterangan "bulan ini" — ia
melekat pada penerimaan SPP dan pengeluaran, tetapi **tidak** pada saldo kas.

Itu bukan kelalaian penulisan melainkan sifat angkanya. Kas yang tersisa dari bulan
sebelumnya tetap ada di brankas; menghitung "saldo" sebagai `income - expense` bulan
berjalan saja akan menghasilkan angka yang berubah drastis setiap tanggal 1 dan tidak
pernah cocok dengan uang yang benar-benar ada.

Yang **tidak** ditetapkan dokumen adalah tanggal potongnya. Fallback yang dipakai: posisi
sampai **akhir periode terpilih** — seluruh INCOME dikurangi seluruh EXPENSE dengan
`transaction_date <= akhir bulan terpilih`. Konsekuensinya memilih bulan lampau
menampilkan saldo sebagaimana adanya saat itu, bukan saldo hari ini, dan itulah yang
membuat ringkasan bulan lalu tetap dapat dibaca ulang tanpa berubah.

Saldo hanya membaca `transactions`. Penerimaan SPP **tidak** ditambahkan ke dalamnya:
blueprint tidak menjelaskan kapan uang SPP masuk buku kas (butir 75), dan menjumlahkannya
di sini berarti mengarang aturan rekonsiliasi sekaligus berisiko menghitung ganda begitu
Bendahara benar-benar mencatat setorannya sebagai transaksi INCOME.

### 83. Tren memakai dua seri yang datanya memang ada

AC hanya menyebut "grafik tren 6 bulan terakhir" tanpa menetapkan serinya. Yang dipakai
adalah dua seri terkecil yang pasti didukung data: pemasukan dan pengeluaran
`transactions` per bulan.

Tidak ada proyeksi, persentase pertumbuhan, garis saldo berjalan, maupun seri turunan
lain — semuanya akan menjadi angka yang tidak pernah diminta siapa pun dan tidak dapat
diverifikasi terhadap dokumen. Test `test_the_trend_carries_no_invented_series` menjaga
bentuk keluarannya tetap persis empat kunci per bulan.

Enam bulannya selalu utuh: bulan terpilih dan lima sebelumnya, berurutan lama → baru,
dan bulan tanpa transaksi bernilai `0.00` alih-alih hilang dari sumbu — sumbu yang
melompati bulan kosong membuat tren terbaca lebih mulus daripada kenyataannya.

### 84. `GET /finance/summary` belum dibuat, dan itu disengaja

API 4.9 memuat `GET /finance/summary` (Auth Level "Auth", filter `year` dan `month`).
Endpoint itu **tidak** dibuat pada batch ini karena project belum punya lapisan REST API
sama sekali: `routes/api.php` tidak ada, tidak ada satu pun rute ber-prefix `api`, tidak
ada controller API, dan Sanctum belum dipakai untuk token. Membuat satu endpoint terisolasi
berarti mendirikan lapisan baru — konvensi respons, autentikasi token, penanganan error,
versioning — hanya untuk satu rute.

`FinanceSummaryService` sudah dirancang menjadi sumber rumusnya, sehingga endpoint itu
nanti tinggal memanggilnya. Tidak ada rumus yang perlu disalin, dan karena itu tidak ada
kemungkinan angka API berbeda dari angka dashboard. Seluruh endpoint API diselesaikan
bersama pada API implementation pass tersendiri.

### 85. KAS-02 memakai `financial_report.view`, bukan `accounting.*`

Tiga sumber menunjuk arah yang sama:

- **KAS-02** menyebut penggunanya "Kepala Sekolah / Admin"
- **Matriks 1.1.2 baris "Laporan Keuangan"** — SUPER_ADMIN ✅, SCHOOL_ADMIN ✅,
  KEPALA ⭕, GURU/WALI ❌, BENDAHARA ✅, SISWA ❌, ORTU ❌
- **Roadmap Sprint 6** menyebut "Dashboard Bendahara"

Bendahara mendapat akses bukan karena ia sudah memegang KAS-01 — itu argumen yang salah,
dan `accounting.*` adalah izin pencatatan buku kas, bukan izin membaca laporannya.
Dasarnya adalah baris matriks "Laporan Keuangan" yang memang memberi Bendahara ✅, dan
roadmap yang menyebut dashboard itu miliknya. KAS-02 adalah laporan, jadi izinnya
`financial_report.view` — izin yang sudah ada dan sudah dibagikan RolePermissionSeeder
persis mengikuti baris matriks itu sejak Sprint 1. Tidak ada izin baru.

Dipakai `.view`, bukan `.manage`: dashboard ini hanya membaca. Kepala Sekolah yang
ber-⭕ karena itu tetap masuk, sesuai kalimat KAS-02 yang justru menyebut mereka lebih
dulu.

### 86. Super Admin wajib memilih cabang — KAS-02 bukan KAS-03

KAS-02 adalah ringkasan satu cabang; KAS-03 (*"ringkasan keuangan semua cabang dalam satu
dashboard"*) adalah user story tersendiri yang belum dikerjakan.

Super Admin tidak memiliki `school_id`, sehingga tanpa penanganan khusus query-nya akan
melewati SchoolScope dan menjumlahkan seluruh cabang — menjadi KAS-03 secara diam-diam,
dengan angka yang tidak pernah dirancang (total tagihan, terkumpul, persentase lunas per
cabang) dan tanpa cara membedakan cabang mana yang bermasalah. Karena itu halaman ini
menampilkan pemilih cabang untuk Super Admin dan tidak menghitung apa pun sampai satu
cabang dipilih.

Bagi peran School Level pemilih itu tidak pernah dirender, dan nilainya di state Livewire
diabaikan sepenuhnya — pola `resolveSchoolId()` yang sama dengan FeeTypeResource dan
GenerateTagihan.

### 87. Agregat SQL per rentang, bukan percabangan driver

Seluruh angka dihitung dengan `SUM(amount)` yang dikelompokkan per `type`, sehingga tiap
query mengembalikan paling banyak dua baris — bukan seluruh transaksi cabang itu. Jumlah
query karenanya tetap sama berapa pun banyaknya record, dan itu diuji
(`test_the_query_count_stays_constant_as_data_grows`): satu agregat saldo, satu penerimaan
SPP, satu pengeluaran periode, dan satu per bulan tren — sembilan, selamanya.

Menghitung keenam bulan tren dalam **satu** query memerlukan ekstraksi bulan yang berbeda
antar driver (`DATE_FORMAT` di MySQL, `strftime` di SQLite). Project ini belum pernah
memakai SQL spesifik driver, dan memasang percabangannya demi menghemat lima query adalah
pertukaran yang buruk: satu query agregat per bulan tetap konstan terhadap volume data,
sedangkan percabangan driver menambah jalur kode yang hanya teruji di salah satu database.
`SUM()` dan `GROUP BY` sendiri ditulis apa adanya karena keduanya berlaku sama di kedua
driver.

Tidak ada migration maupun index baru: penyaringan berjalan di `school_id` (foreign key,
ber-index), `type` (ber-index menurut ERD), dan rentang tanggal.

### 88. Gap soft delete KAS-01 terbawa ke sini

Buku kas belum punya mekanisme penghapusan (butir 74), dan batch ini tidak
menyelesaikannya. Konsekuensinya untuk KAS-02: seluruh baris `transactions` yang ada di
tabel ikut terhitung, karena memang tidak ada penanda apa pun yang menyatakan sebuah baris
"terhapus".

Tidak ada `deleted_at`, `is_active`, status, maupun filter transaksi terhapus yang
ditambahkan — menambahkannya di sini berarti memutuskan diam-diam apa arti "terhapus"
bagi saldo kas, yang justru pertanyaan yang harus dijawab pemilik produk lebih dulu.
Ketika butir 74 selesai, saldo dan tren adalah dua tempat pertama yang perlu disesuaikan.

### 89. Yang sengaja belum ada pada Batch 6.2

Dashboard lintas cabang (KAS-03), ekspor laporan (SPP-05, `GET /finance/export`),
`GET /finance/spp-report`, endpoint `GET /finance/summary` (butir 84), portal orang tua
(SPP-04), notifikasi, dan penghapusan transaksi (butir 74/88). Ringkasan ini juga tidak
menyimpan hasilnya ke tabel mana pun: angkanya dihitung saat diminta, sehingga tidak ada
snapshot yang dapat basi terhadap `transactions` dan `payments` yang menjadi sumbernya.

## Sprint 6 Batch 6.3 — Keuangan Semua Cabang (KAS-03)

### 90. "Total tagihan" dan "total terkumpul" — semantik yang dipilih

KAS-03 hanya menyebut tiga kolom: *"total tagihan, total terkumpul, persentase lunas"*.
Tidak ada rumusnya di mana pun — bukan di PRD, bukan di ERD, bukan di API map. Yang
berikut adalah **keputusan implementasi**, bukan aturan yang diberikan owner.

- **total tagihan** = `SUM(student_fees.amount)` untuk filter yang berlaku. Ini nominal
  rupiah yang diterbitkan, bukan cacah record — "total tagihan" pada laporan keuangan
  membaca sebagai uang, bukan sebagai banyaknya lembar tagihan.
- **total terkumpul** = `SUM(student_fees.amount_paid)` atas kumpulan tagihan yang sama.

`amount_paid` dipakai, bukan menjumlahkan `payments` menurut `payment_date` seperti KAS-02
(butir 82). Bedanya disengaja dan penting: KAS-02 menjawab *"berapa uang yang masuk bulan
ini"*, sedangkan KAS-03 menjawab *"dari tagihan yang difilter ini, berapa yang sudah
tertagih"*. Menyaring pembayaran menurut tanggalnya akan membuat cicilan bulan berikutnya
hilang dari tagihan Agustus, dan angka "terkumpul" tidak akan pernah mencapai "tagihan"
walaupun seluruhnya sudah lunas.

Nominal tagihan yang kemudian dibebaskan tetap masuk **total tagihan**: recordnya nyata
dan pernah ditagihkan. Yang dikeluarkan hanyalah dari penyebut persentase (butir 91).

### 91. Rumus "persentase lunas", dan mengapa bukan amount_paid / amount

Juga keputusan implementasi — blueprint tidak memberi rumus matematisnya.

Persentase lunas = jumlah StudentFee berstatus PAID dibagi jumlah StudentFee yang masih
merupakan kewajiban pembayaran, dikali 100.

Penyebutnya UNPAID + PARTIAL + PAID. **WAIVED dikeluarkan**: tagihan yang dibebaskan bukan
kewajiban yang perlu dilunasi, dan memasukkannya ke penyebut membuat cabang yang banyak
memberi keringanan terlihat buruk justru karena kebijakannya — angka yang mengukur
kebalikan dari yang dimaksudkan. WAIVED juga tidak pernah dihitung sebagai PAID: keduanya
status terminal yang berbeda, dan menyamakannya menyembunyikan berapa banyak uang yang
memang tidak akan pernah masuk.

Yang **tidak** dipakai adalah `amount_paid / amount`. Itu tingkat penagihan (collection
rate), bukan proporsi tagihan yang lunas, dan keduanya dapat jauh berbeda: satu cabang
dengan seratus tagihan yang masing-masing dicicil 99% punya collection rate 99% tetapi
**nol** tagihan lunas. Dua pertanyaan berbeda; KAS-03 menulis "persentase lunas", dan
itulah yang dihitung. Perilakunya diuji langsung
(`test_a_partially_paid_fee_is_not_counted_as_paid`).

Penyebut nol menghasilkan `0.00`, bukan pembagian dengan nol — dan artinya "tidak ada
tagihan yang perlu dilunasi", bukan "gagal menagih".

### 92. Filter tahun ajaran memakai nama, karena academic_years milik tiap cabang

ERD menyebut `academic_years` sebagai "tahun ajaran dan semester aktif **per cabang**",
dan tabelnya memang ber-`school_id`. Konsekuensinya satu `academic_year_id` tidak pernah
berlaku lintas cabang: "2026/2027 Ganjil" di Cabang A dan di Cabang B adalah dua baris
dengan id berbeda.

Filter KAS-03 karena itu tidak dapat memakai `academic_year_id`. Yang dipakai adalah
`name` — satu-satunya identitas bersama yang memang sudah ada di skema — dan setiap cabang
dicocokkan pada baris miliknya sendiri yang bernama sama, lewat `whereHas` pada relasi
`academicYear`. Pilihan filternya dikumpulkan sebagai `DISTINCT name` dari seluruh cabang.

Tidak ada tabel pemetaan, kolom, maupun konsep "tahun ajaran global" yang dibuat —
membuatnya berarti menambah entitas yang tidak ada di ERD dan memutuskan siapa yang
mengelolanya.

**Batas yang perlu diketahui:** pencocokan ini bergantung pada penamaan yang konsisten
antarcabang. Cabang yang menulis "2026/2027 Ganjil" dan cabang yang menulis
"2026/2027 Semester 1" tidak akan pernah tergabung dalam satu filter, dan tidak ada
constraint apa pun yang mencegahnya. Kolomnya `VARCHAR(20)` tanpa unique index maupun
format yang ditetapkan. Selama belum ada aturan penamaan, ini keterbatasan nyata dan
bukan bug implementasi.

Tagihan berulang tanpa tahun ajaran (`academic_year_id` NULL, sah menurut ERD) tidak
muncul saat filter tahun ajaran dipakai — memang bukan bagian dari tahun ajaran mana pun.

### 93. Cabang nonaktif tetap tampil selama punya data

Blueprint tidak menyebut apakah cabang nonaktif ikut ditampilkan. Yang dipakai: sebuah
cabang muncul bila **aktif** atau **punya tagihan pada filter yang berlaku**.

Membuang cabang nonaktif begitu saja akan menghapus riwayat keuangannya dari laporan
historis — cabang yang tutup Desember lalu tetap harus terlihat saat Super Admin membuka
periode Agustus, dan total seluruh cabang akan salah tanpanya. Sebaliknya, menampilkan
seluruh cabang nonaktif yang memang tidak pernah punya tagihan hanya menambah baris nol
selamanya. Status aktifnya ditampilkan sebagai badge supaya angkanya dapat dibaca dengan
konteks yang benar.

Cabang aktif tanpa data tetap muncul dengan nilai nol — "tampil per cabang" berarti
seluruh cabang yang berjalan, termasuk yang belum menerbitkan tagihan.

### 94. Satu-satunya pelepasan SchoolScope pada jalur baca dashboard

`CrossSchoolFinanceSummaryService` adalah satu-satunya layanan yang membaca lintas cabang,
dan pelepasan `SchoolScope`-nya terjadi **setelah** `authorize()` memastikan pelakunya
Super Admin.

Pemeriksaannya lewat peran (`isSuperAdmin()`), bukan izin. `Gate::before` meloloskan Super
Admin untuk setiap ability, sehingga pemeriksaan berbasis izin justru tidak dapat
membedakan mereka dari Bendahara atau School Admin yang memegang izin keuangan yang sama —
dan ketiganya memang memegang `financial_report.manage`. Polanya sama dengan RolePolicy.

Tidak ada `school_id` yang diterima dari payload di mana pun pada jalur ini: cakupannya
selalu seluruh cabang, dan pembatasannya adalah peran, bukan parameter. Tidak ada field
cabang di formnya yang dapat diselundupkan.

### 95. Dua query, konstan terhadap cabang maupun tagihan

Agregasinya satu query `GROUP BY school_id, status` dengan `SUM`/`COUNT` — paling banyak
empat baris per cabang, bukan satu query per cabang dan bukan seluruh `student_fees` dimuat
ke PHP. Ditambah satu query daftar cabang: **dua query, selamanya**, diuji terhadap
pertumbuhan jumlah cabang maupun jumlah tagihan.

Pengelompokan per `status` dipilih alih-alih `SUM(CASE WHEN ...)` supaya tidak ada ekspresi
kondisional di SQL sama sekali; `SUM`, `COUNT`, dan `GROUP BY` berlaku identik di MySQL dan
SQLite. Total seluruh cabang di baris terakhir tabel dijumlahkan dari baris yang sudah ada
di memori, bukan dari query ketiga.

Tidak ada migration maupun index baru: penyaringannya di `school_id` (foreign key,
ber-index), `period` (ber-index menurut ERD), dan `status` (ber-index menurut ERD).

### 96. KAS-03 halaman terpisah, dan tidak menyerap KAS-02

Dashboard Super Admin yang ada saat ini adalah `Filament\Pages\Dashboard` bawaan dengan
`AccountWidget` saja — tidak ada extension point yang layak dimasuki tanpa merombaknya.
KAS-03 karena itu menjadi halaman tersendiri, mengikuti pola navigasi yang sudah dipakai
Generate Tagihan dan Laporan Keuangan.

Halaman ini juga tidak menampilkan metric KAS-02 (saldo kas, pengeluaran, tren): keduanya
mengukur hal berbeda dengan definisi "terkumpul" yang berbeda, dan menggabungkannya dalam
satu layar membuat dua angka bernama mirip berdiri berdampingan tanpa cara membedakannya.
`GET /admin/dashboard` dan `GET /admin/schools/{id}/stats` pada API map menunggu API
implementation pass, sama seperti `GET /finance/summary` (butir 84).

### 97. Yang sengaja belum ada pada Batch 6.3

Ekspor laporan (SPP-05, `GET /finance/export`), `GET /finance/spp-report`, endpoint API
mana pun, portal orang tua (SPP-04), notifikasi, dan penghapusan transaksi (butir 74).
Buku kas KAS-01 tidak ikut dihitung di sini sama sekali — KAS-03 mengukur tagihan siswa,
dan menggabungkan kas cabang ke dalamnya berarti mencampur dua domain yang blueprint
justru pisahkan (butir 75). Tidak ada ringkasan yang disimpan ke tabel: angkanya dihitung
saat diminta, sehingga tidak ada snapshot yang dapat basi terhadap `student_fees`.

## Sprint 6 Batch 6.4 — Export Laporan Tagihan (SPP-05)

### 98. Ekspor digantung pada `financial_report.manage`, bukan `fee.*`

Tiga sumber menyebut hal yang tampak berbeda:

- **SPP-05**: *"Sebagai **Bendahara**, saya dapat mengekspor laporan tagihan per periode"*
- **API 4.9** `GET /student-fees/export`: Auth Level **Admin**
- **Matriks 1.1.2 "Laporan Keuangan"**: SUPER_ADMIN ✅, SCHOOL_ADMIN ✅, KEPALA ⭕,
  BENDAHARA ✅, GURU/WALI ❌

Ketiganya sebenarnya sejalan begitu modulnya ditentukan. Ekspor adalah pengambilan
laporan keuangan ke luar sistem, jadi yang berlaku adalah baris "Laporan Keuangan" —
bukan "Tagihan SPP" (`fee.*`) yang mengatur pengelolaan tagihannya, dan bukan
`accounting.*` yang mengatur buku kas. `StudentFeePolicy::export()` karena itu memakai
`financial_report.manage`, izin yang sudah ada dan sudah dibagikan seeder persis mengikuti
baris matriks itu. Tidak ada izin baru.

Hasilnya: Super Admin, School Admin, dan Bendahara boleh mengunduh; **Kepala Sekolah
tidak**, karena mereka hanya memegang `financial_report.view`. Itu memang arti ⭕ pada
baris tersebut, dan berkas yang keluar dari sistem layak dibatasi lebih ketat daripada
tampilan di layar — sekali terunduh, ia tidak lagi tunduk pada tenant scope maupun audit
log.

`.manage` dipakai, bukan `.view`, karena `.view` juga dipegang Kepala Sekolah dan tidak
dapat membedakan keduanya.

### 99. Kolom Kelas mengikuti tahun ajaran tagihan, bukan kelas siswa saat ini

`student_fees` tidak menyimpan `class_id`, dan SPP-05 meminta kolom "kelas". Godaannya
adalah memakai penempatan terakhir siswa — dan itu salah: siswa yang naik kelas akan
membuat laporan tagihan tahun lalu tertulis dengan rombel tahun ini, sehingga rekapitulasi
per kelas untuk periode lampau tidak akan pernah cocok dengan kenyataannya.

`student_classes` sudah menyimpan `academic_year_id` dan `status ENUM(ACTIVE,MOVED)`, jadi
yang dipakai adalah penempatan **ACTIVE pada tahun ajaran yang sama dengan tagihannya**.
Siswa yang pindah rombel di tengah tahun punya satu baris MOVED dan satu ACTIVE untuk
tahun yang sama; yang tercetak yang ACTIVE.

Penyaring kelas memakai asosiasi yang **sama persis**, lewat `whereColumn` yang
mengikatkan `student_classes.academic_year_id` ke `student_fees.academic_year_id`. Kalau
tidak, isi berkas tidak akan cocok dengan filternya: baris yang lolos filter "kelas 7A"
bisa tercetak "8A" pada kolom kelasnya.

**Gap yang dicatat, bukan ditebak:** tagihan dengan `academic_year_id` NULL — sah menurut
ERD untuk tagihan berulang — tidak punya tahun ajaran untuk dicocokkan. Kolom kelasnya
dibiarkan **kosong** alih-alih diisi dari tahun lain. Dalam praktik ini jarang:
`StudentFeeGenerator` mengisi tahun ajaran aktif cabang saat jenis tagihannya tidak
terikat (butir 53), sehingga NULL hanya muncul bila cabang belum punya tahun ajaran aktif.
Siswa yang belum pernah ditempatkan di kelas mana pun juga tampil kosong, dengan alasan
yang sama: tidak ada data, dan menebaknya lebih buruk daripada mengosongkannya.

### 100. Periode wajib diisi

AC SPP-05 menulis *"ekspor dapat difilter per kelas, periode, atau status"* — "dapat"
membaca seperti opsional. Tetapi user story-nya sendiri berbunyi *"mengekspor laporan
tagihan **per periode**"*, dan itu lebih spesifik tentang apa laporannya.

Periode karena itu **wajib**; kelas dan status tetap opsional. Alasannya bukan sekadar
kepatuhan teks: tanpa periode, satu klik dapat menarik seluruh riwayat tagihan cabang ke
dalam satu berkas tanpa operator menyadarinya — dan itu justru pada layar yang tidak
menampilkan berapa baris yang akan terunduh. Batasan yang dapat dilonggarkan nanti bila
memang dibutuhkan; kebalikannya jauh lebih mahal.

### 101. `GET /student-fees/export` belum dibuat

Sama seperti `GET /finance/summary` (butir 84) dan endpoint dashboard lintas cabang
(butir 96): project belum punya lapisan REST API sama sekali, dan mendirikan satu endpoint
terisolasi berarti memutuskan konvensi respons, autentikasi token, dan penanganan error
untuk satu rute.

`StudentFeeReportExporter` sudah menjadi satu sumber untuk kewenangan, penyaringan, dan
penamaan berkas, sehingga endpoint itu nanti tinggal memanggil `download()` atau `query()`
yang sama. Tidak ada rumus maupun aturan akses yang perlu disalin.

### 102. Satu cabang per berkas, dan Super Admin memilihnya

SPP-05 adalah laporan cabang; tidak ada requirement yang meminta ekspor lintas cabang, dan
KAS-03 sudah menjawab kebutuhan perbandingan antarcabang di layar (Batch 6.3).

Super Admin tidak memiliki `school_id`, sehingga tanpa penanganan khusus query-nya akan
melewati SchoolScope dan mengunduh seluruh cabang dalam satu berkas — hal yang tidak
diminta siapa pun dan tidak terlihat sebelum berkasnya dibuka. Modal ekspor karena itu
mewajibkan Super Admin memilih cabang, dan pilihan kelasnya mengikuti cabang itu.

Bagi peran School Level field cabang tidak pernah dirender, dan `school_id` di payload
diabaikan sepenuhnya — pola `resolveSchoolId()` yang sama dengan FeeTypeResource,
GenerateTagihan, dan TransactionRecorder. Kelas dari cabang lain tidak perlu ditolak
dengan pesan khusus: query-nya sudah terkunci pada cabang laporan, sehingga id asing
menghasilkan laporan kosong, bukan kebocoran.

### 103. Nominal tetap sel angka

Ketiga kolom uang ditulis sebagai angka, bukan teks `"Rp 1.000.000"`. Teks membuat
penjumlahan dan pengurutan di Excel berhenti bekerja, dan laporan tagihan memang dibuat
untuk dihitung ulang penerimanya — itulah gunanya berkas Excel dan bukan PDF. Rupiahnya
dipasang sebagai format sel lewat `WithColumnFormatting`, jadi tetap terbaca sebagai uang
tanpa mengorbankan sifat angkanya.

PhpSpreadsheet tidak menyediakan konstanta format rupiah (hanya USD dan EUR), sehingga
format codenya ditulis langsung sebagai konstanta pada exporter.

Kolom "sisa" memakai `StudentFee::remaining()` — helper yang sama dengan yang dipakai layar
dan portal nanti (butir 71). Tidak ada rumus sisa kedua yang dapat menyimpang.

Label status memakai bahasa Indonesia (`StudentFeeStatus::label()`), berbeda dari
StudentsExport yang menulis nilai enum mentah. Pilihan itu disengaja: laporan tagihan
dibaca operator keuangan dan kemungkinan diteruskan ke luar, sedangkan ekspor siswa
(SIS-05) dirancang untuk dapat diimpor kembali lewat StudentsImport dan karena itu harus
memakai nilai yang sama dengan yang diterima importer. Tidak ada importer untuk laporan
tagihan.

### 104. Yang sengaja belum ada pada Batch 6.4

`GET /finance/export` (ekspor laporan keuangan umum — beda dari SPP-05 dan belum
dikerjakan), `GET /finance/spp-report`, endpoint API mana pun, portal orang tua (SPP-04),
notifikasi, dan penghapusan transaksi (butir 74). Ekspor ini juga tidak menyimpan riwayat
unduhan: tidak ada tabel untuk itu di ERD, dan `audit_logs` mencatat CUD, bukan pembacaan
(butir 45).

## Sprint 6 Batch 6.5 — Export Buku Kas (GET /finance/export)

### 105. Ekspor buku kas memakai `financial_report.manage`

Sama seperti ekspor laporan tagihan (butir 98), dan dengan alasan yang sama:
mengekspor adalah membaca laporan keuangan ke luar sistem, sehingga baris matriks yang
berlaku "Laporan Keuangan" — bukan `accounting.*` yang mengatur pencatatan buku kasnya
(KAS-01).

Ketiga sumbernya sejalan: API 4.9.2 memberi `GET /finance/export` Auth Level **Admin**;
matriks "Laporan Keuangan" memberi SUPER ✅, SCHOOL_ADMIN ✅, KEPALA ⭕, BENDAHARA ✅; dan
PRD 1.1.1 menyebut tanggung jawab Bendahara sebagai *"Kelola tagihan SPP, catat
pembayaran, akuntansi & **laporan keuangan**"* — penyebutan eksplisit yang menimpa label
"Admin" yang lebih kasar, persis seperti pada KAS-01 (butir 73).

Hasilnya Super Admin, School Admin, dan Bendahara boleh mengunduh; Kepala Sekolah tidak,
karena mereka hanya memegang `financial_report.view`. Mereka tetap dapat membaca buku kas
di layar. Berkas yang keluar dari sistem tidak lagi tunduk pada tenant scope maupun audit
log, dan itu perbedaan yang layak dijaga.

### 106. Isi berkasnya buku kas, bukan gabungan dengan tagihan SPP

Seluruh yang dikatakan dokumen tentang fitur ini adalah **satu baris**:
`GET /finance/export` — Admin — *"Export laporan keuangan ke Excel"*, ditambah "Export
Excel" pada daftar deliverable Sprint 6. Tidak ada kolom, tidak ada filter, tidak ada
struktur workbook. Yang berikut karena itu **keputusan implementasi**, bukan aturan yang
diberikan owner.

Yang menentukan pilihannya adalah **letak endpointnya**. Ia berada di bagian
**4.9.2 Akuntansi & Kas**, tepat setelah CRUD `/transactions` dan dua endpoint ringkasan —
sedangkan ekspor tagihan (`GET /student-fees/export`, SPP-05) berada di bagian
**4.9.1 Tagihan & Pembayaran SPP**. Dua ekspor di dua bagian, mengikuti dua domain yang
memang dipisahkan ERD (butir 75).

Karena itu berkasnya berisi `transactions` satu cabang saja. `payments` tidak ikut, tidak
disalin menjadi transaksi, dan tidak dijumlahkan bersama — tagihan SPP sudah punya
ekspornya sendiri, dan menggabungkan keduanya di sini berarti mengarang aturan
rekonsiliasi yang belum pernah dijelaskan. Perilakunya diuji
(`test_payments_never_appear_in_the_cash_ledger`).

### 107. Tujuh kolom, dan yang sengaja tidak ada

Kolomnya diambil dari ERD `transactions` apa adanya: Tanggal, Jenis, Kategori, Jumlah,
Keterangan, Nomor Referensi, Dicatat Oleh.

Yang **tidak** ditambahkan: saldo berjalan, nomor jurnal, akun debit/kredit, chart of
accounts, dan kode transaksi. Tidak satu pun ada di blueprint, dan masing-masing membawa
keputusan akuntansi tersendiri — saldo berjalan misalnya menuntut jawaban atas saldo awal
periode, yang tidak tersimpan di mana pun. `school_id` juga tidak menjadi kolom: berkasnya
memang satu cabang.

Nominal tetap positif untuk pemasukan maupun pengeluaran; arah kasnya dibaca dari kolom
Jenis, persis seperti yang tersimpan (butir 78). Membalik pengeluaran menjadi negatif di
berkas ekspor akan membuat dua representasi berbeda untuk data yang sama.

### 108. `proof_url` tidak diekspor

Bukti transaksi disimpan di disk privat justru supaya jalurnya tidak beredar (butir 76).
Menuliskan jalur itu ke dalam berkas Excel akan membatalkan maksudnya: berkas ekspor
dibuat untuk diteruskan, dan jalur penyimpanan yang ikut keluar memberi tahu penerimanya
struktur penyimpanan internal — tanpa memberi mereka kemampuan mengunduh berkasnya, jadi
tidak ada manfaat yang hilang.

Bila kelak dibutuhkan penanda "ada bukti/tidak", kolom boolean lebih tepat daripada
jalurnya. Itu belum diminta, jadi belum dibuat.

### 109. Rentang tanggal wajib

API 4.9.2 menyebut `date_from` dan `date_until` sebagai filter `GET /transactions`, dan
`/finance/export` tidak punya daftar filternya sendiri — jadi filter yang terdekat dengan
dokumen adalah milik `/transactions`. Ketiganya dipakai: rentang tanggal, `type`, dan
`category`.

Rentang tanggalnya dijadikan **wajib**, `type` dan `category` opsional. Alasannya sama
dengan periode wajib pada SPP-05 (butir 100): tanpa batas waktu, satu klik dapat menarik
seluruh riwayat buku kas cabang ke dalam satu berkas tanpa operator menyadarinya — dan
justru pada layar yang tidak menampilkan berapa baris yang akan terunduh. Tanggal akhir
yang mendahului tanggal mulai ditolak, bukan diam-diam menghasilkan berkas kosong.

Dipakai rentang tanggal, bukan periode `YYYY-MM` seperti KAS-02, karena dokumen memang
menyebut `date_from`/`date_until` untuk domain ini dan filter tabel Buku Kas (KAS-01)
sudah memakai rentang tanggal. Tidak ada tahun fiskal.

`category` dicocokkan **persis**, bukan `LIKE`: kolomnya teks bebas (butir 77), dan
pencocokan parsial akan membuat filter "Gaji" ikut menarik "Gaji Honorer" tanpa operator
memintanya. Daftar pilihannya diambil dari kategori yang benar-benar dipakai cabang itu.

### 110. `GET /finance/export` belum dibuat sebagai endpoint

Sama seperti `GET /finance/summary` (butir 84), endpoint dashboard lintas cabang
(butir 96), dan `GET /student-fees/export` (butir 101): project belum punya lapisan REST
API sama sekali.

`CashLedgerExporter` sudah menjadi satu sumber untuk kewenangan, penyaringan, dan
penamaan berkas, sehingga endpoint itu nanti tinggal memanggil `download()` atau `query()`
yang sama.

### 111. Gap soft delete KAS-01 terbawa ke ekspor

Buku kas belum punya mekanisme penghapusan (butir 74), dan batch ini tidak
menyelesaikannya. Konsekuensinya: berkas ekspor memuat seluruh baris `transactions` yang
ada di tabel, karena memang tidak ada penanda apa pun yang menyatakan sebuah baris
"terhapus".

Tidak ada `deleted_at`, `is_active`, status, maupun aturan pengecualian yang ditambahkan
di sini — menambahkannya berarti memutuskan diam-diam apa arti "terhapus" bagi laporan
yang sudah terunduh, yang justru pertanyaan yang harus dijawab pemilik produk lebih dulu.
Ketika butir 74 selesai, query ekspor ini dan agregasi KAS-02 (butir 88) adalah dua tempat
yang perlu disesuaikan bersama.

### 112. Yang sengaja belum ada pada Batch 6.5

Lembar ringkasan di dalam workbook (KAS-02 sudah punya dashboard dan
`FinanceSummaryService` sendiri; dokumen tidak meminta ringkasan ikut di berkas ekspor),
ekspor lintas cabang (KAS-03 menjawab kebutuhan itu di layar), `GET /finance/spp-report`,
endpoint API mana pun, portal orang tua (SPP-04), dan notifikasi. Ekspor ini juga tidak
menyimpan riwayat unduhan: tidak ada tabel untuk itu di ERD, dan `audit_logs` mencatat
CUD, bukan pembacaan (butir 45).

## Register Keputusan Implementasi — Disetujui untuk Phase 1

### 113. Sembilan keputusan fallback yang sudah disetujui, dan statusnya

Butir-butir sebelumnya mencatat sejumlah perilaku sebagai *fallback* karena blueprint
tidak menetapkannya, dan menandainya perlu dikonfirmasi. Konfirmasi itu sudah datang:
kesembilan keputusan berikut **disetujui untuk Phase 1** dan tidak lagi menunggu
persetujuan siapa pun.

Yang perlu dijaga ketelitiannya: ini tetap **keputusan implementasi**, bukan isi
blueprint. Bila kelak ada bagian blueprint yang secara eksplisit lebih spesifik, bagian
itulah yang menang — persis seperti sebelumnya.

| # | Keputusan | Butir | Status kode |
|---|---|---|---|
| 1 | Pembayaran melebihi sisa tagihan ditolak; tidak ada saldo/kredit siswa | 59 | Sudah sesuai |
| 2a | Pembayaran atas tagihan WAIVED ditolak | 60 | Sudah sesuai |
| 2b | Tagihan PARTIAL/PAID tidak dapat dibebaskan; tidak ada refund/reversal | 68, 69 | Sudah sesuai |
| 3 | Persentase lunas = PAID / (UNPAID + PARTIAL + PAID) × 100; WAIVED di luar penyebut | 91 | Sudah sesuai |
| 4 | Saldo kas periode lampau = posisi sampai akhir bulan terpilih | 82 | Sudah sesuai |
| 5 | Ekspor Excel keuangan = ledger `transactions` satu cabang; tanpa lembar ringkasan dan tanpa saldo berjalan | 106, 107 | Sudah sesuai |
| 6 | Kepala Sekolah view-only atas laporan keuangan; tidak dapat mengekspor | 98, 105 | Sudah sesuai |
| 7 | SPP dan Buku Kas tetap dua domain terpisah; Payment tidak membuat Transaction; tanpa rekonsiliasi otomatis Phase 1 | 75 | Sudah sesuai |
| 8 | YEARLY/ONCE diterbitkan eksplisit oleh operator; tanpa scheduler maupun aturan recurrence | 51, 54 | Sudah sesuai |
| 9 | **Bukti pembayaran boleh dilampirkan setelah Payment dibuat; bukti yang sudah ada tidak ditimpa diam-diam** | 64 | **Belum — lihat bawah** |

Delapan yang pertama menegaskan perilaku yang memang sudah berjalan; tidak ada kode yang
berubah karenanya. Nilainya ada pada statusnya: keputusan-keputusan itu tidak lagi
menggantung, sehingga tidak perlu ditanyakan ulang di setiap batch dan tidak boleh
diam-diam diubah.

Keputusan **9 adalah satu-satunya yang mengubah perilaku.** Saat ini `payments` bersifat
append-only sepenuhnya (butir 64): `PaymentPolicy::update()` menolak tanpa syarat dan
tidak ada satu pun jalur UI untuk menyentuh baris yang sudah tercatat. Konsekuensinya
alur yang wajar terjadi di lapangan — pembayaran transfer dicatat hari ini, scan
buktinya baru tiba besok — tidak punya jalan keluar sama sekali.

Persetujuan ini melonggarkan append-only itu **pada satu kolom saja**, `proof_url`, dan
hanya ke satu arah: dari kosong menjadi terisi. Nominal, metode, tanggal, dan pencatatnya
tetap tidak dapat diubah, karena tidak satu pun dari itu yang diminta longgar. Larangan
menimpa bukti yang sudah ada adalah bagian dari keputusannya, bukan tambahan: mengganti
bukti secara diam-diam menghapus dokumen yang mungkin sudah pernah dipakai
mempertanggungjawabkan uang.

Implementasinya belum dikerjakan pada saat butir ini ditulis; ia menjadi lingkup batch
tersendiri. Sampai batch itu selesai, butir 64 tetap menggambarkan perilaku yang berjalan.

## Sprint 6 Batch 6.6 — Fondasi REST API, Finance Wave 1, Late Payment Proof

### 114. Pratinjau SPP-02 pada API: dikembalikan, bukan dihapus

SPP-02 poin 3 mewajibkan *"preview daftar tagihan ditampilkan sebelum konfirmasi
generate"*, dan Batch 5.2 memperlakukannya sebagai gerbang, bukan kenyamanan. API map
justru hanya memuat `POST /student-fees/generate-bulk` — tidak ada endpoint pratinjau,
dan tidak ada isyarat dua langkah.

Ketiga jalan keluarnya tidak sama nilainya:

- **mengarang `POST /student-fees/preview`** — menambah endpoint yang tidak ada di
  blueprint, dan memaksa klien mengingat dua panggilan yang berpasangan
- **menerbitkan langsung tanpa pratinjau** — menghapus jaminan yang justru ditulis
  eksplisit di requirement
- **menghitung pratinjau di server lalu mengembalikannya bersama respons** — endpointnya
  tetap satu seperti API map, dan pemanggil selalu menerima daftar siswa yang ditagih

Yang dipakai yang ketiga. Responsnya `202 Accepted` dengan `preview.will_be_billed`,
`preview.already_billed`, dan daftar siswanya; pratinjau itu dihitung
`StudentFeeGenerator::preview()` — sumber logika yang sama dengan panel, sehingga apa yang
dilaporkan API adalah apa yang dikerjakan worker.

Yang jujur diakui: ini **lebih lemah** daripada gerbang di panel. Di panel operator
melihat pratinjau lalu menekan tombol kedua; di API penerbitannya sudah diantrekan saat
pratinjau dikembalikan. Idempotensi Batch 5.2 (butir 52) menahan akibat terburuknya —
menjalankan ulang tidak menghasilkan tagihan ganda — tetapi klien API tetap harus
menampilkan pratinjaunya sendiri bila ingin setara dengan panel. Bila kelak dianggap
kurang, jalur yang benar adalah menambahkan langkah konfirmasi ke blueprint lebih dulu.

### 115. Logout mencabut satu token, bukan semua perangkat

API 4.2 menulis `POST /auth/logout` sebagai *"invalidate token sesi aktif"* — tunggal.
Yang dicabut karena itu hanya token yang dipakai request tersebut; perangkat lain milik
pengguna yang sama tetap masuk.

Mencabut seluruh token akan menjadi "logout dari semua perangkat", fitur berbeda yang
tidak diminta dan berpotensi mengejutkan: menutup aplikasi di ponsel tidak seharusnya
mengeluarkan orang yang sama dari komputernya.

### 116. Record cabang lain menjadi 404, bukan 403

Tagihan, jenis tagihan, dan transaksi milik cabang lain tersaring SchoolScope sebelum
sampai ke policy, sehingga `findOrFail` menghasilkan `ModelNotFoundException` → 404.

Itu bukan sekadar konsekuensi teknis melainkan yang memang diinginkan: 403 mengonfirmasi
bahwa id tersebut **ada** di suatu cabang, dan itu sendiri sudah membocorkan sesuatu —
penyerang dapat memetakan rentang id yang terpakai walaupun tidak dapat membacanya. 403
tetap dipakai ketika yang kurang adalah perannya, bukan cabangnya.

### 117. "Auth Level: Admin" tidak diterjemahkan buta menjadi middleware

API 4.1 mendefinisikan Auth Level Admin sebagai "role SCHOOL_ADMIN / SUPER_ADMIN", dan
banyak endpoint keuangan berlabel demikian. Menerapkannya sebagai middleware pada semua
endpoint itu akan **menutup akses Bendahara** — bertentangan dengan user story SPP-01,
SPP-02, SPP-03, dan KAS-01 yang menyebut Bendahara secara eksplisit (butir 73, 98, 105).

Middleware `auth_level` karena itu hanya menegakkan tingkat kasar, dan endpoint keuangan
memakai `auth_level:auth` ditambah policy domainnya masing-masing — policy yang sama
persis dengan yang sudah diuji di panel. Hasilnya kewenangan API tidak pernah berbeda dari
kewenangan panel, karena keduanya membaca sumber yang sama.

`EnsureApiAuthLevel` tetap memeriksa dua hal yang bukan urusan policy: token milik akun
yang masih aktif, dan tingkat Admin/Super untuk endpoint yang labelnya memang tidak
tertimpa apa pun.

### 118. `proof_url` tidak pernah keluar lewat API

`PaymentResource` dan `TransactionResource` mengirim `has_proof` boolean, bukan jalur
berkasnya. Berkasnya ada di disk privat justru supaya jalurnya tidak beredar (butir 63,
108); mengirimkannya lewat JSON akan membatalkan maksud itu tanpa memberi klien kemampuan
apa pun — mereka tetap tidak bisa mengunduhnya.

Endpoint unduh bukti **tidak dibuat**: API map tidak memuatnya, dan mengarangnya hanya
karena jalurnya disembunyikan berarti menambah permukaan yang tidak diminta. Unduhan
terotorisasi lewat panel tetap ada. Bila klien API kelak benar-benar perlu mengunduh
bukti, itu endpoint baru yang layak masuk blueprint lebih dulu.

### 119. Bukti menyusul: satu kolom, satu arah

Keputusan implementasi Phase 1 yang disetujui (butir 113 nomor 9). Sebelumnya `payments`
append-only sepenuhnya (butir 64), sehingga alur lapangan yang wajar — transfer dicatat
hari ini, scan buktinya tiba besok — tidak punya jalan keluar sama sekali.

`PaymentProofAttacher` dibuat **terpisah** dari `PaymentRecorder`. Menjadikan pencatat
pembayaran sekaligus pengubah pembayaran akan mengaburkan satu-satunya jaminan yang
membuat `payments` dapat dipercaya sebagai riwayat; dua kelas dengan satu tanggung jawab
masing-masing membuat batasnya terbaca dari struktur kodenya sendiri.

Batasannya:

- hanya `proof_url` yang ditulis — diuji dengan membandingkan sembilan kolom lain
  sebelum dan sesudah
- hanya dari kosong menjadi terisi; bukti yang sudah ada **tidak pernah** ditimpa, dan
  keadaan itu diperiksa ulang di bawah row lock supaya dua permintaan bersamaan tidak
  saling menimpa
- `PaymentPolicy::update()` dan `::delete()` tetap menolak tanpa syarat; yang ditambahkan
  ability `attachProof` tersendiri
- kewenangannya mengikuti baris "Catat Pembayaran" (`payment.manage`): Super Admin,
  School Admin, Bendahara — Kepala Sekolah tidak (butir 57)

UI-nya satu aksi di riwayat pembayaran yang hanya muncul saat buktinya belum ada. Tidak
ada aksi "Ganti Bukti", dan tidak ada satu pun field pembayaran lain yang dapat disunting
dari sana.

### 120. Berkas yatim saat pelampiran gagal

Pada jalur API berkasnya disimpan sebelum transaksi dibuka, sehingga kegagalan penulisan
akan meninggalkan berkas yang tidak ditunjuk baris mana pun. Berkas itu dihapus di blok
`catch` — menutup kasus yang paling umum tanpa membangun sistem retensi baru.

Pada jalur panel berkasnya sudah dimiliki Filament FileUpload dan tidak dihapus: Filament
yang memilikinya, dan pengguna dapat mengunggah ulang jalur yang sama.

Yang **belum** tertangani dan sengaja tidak dikarang penyelesaiannya: berkas yatim dari
kegagalan proses (server mati di antara penyimpanan dan commit). Sama seperti gap yang
sudah dicatat pada unggahan bukti saat pencatatan — tidak ada requirement retensi di
blueprint.

### 121. Endpoint 4.2 yang belum dibuat

Dari API 4.2 baru `login`, `logout`, dan `me`. Belum ada: `POST /auth/refresh`,
`POST /auth/forgot-password`, `POST /auth/reset-password`, `PATCH /auth/me`, dan
`PATCH /auth/me/password`.

Keempat yang terakhir menyentuh alur yang sudah ada di panel (reset password lewat email,
ubah profil, ganti password wajib pada login pertama) dan layak dikerjakan bersama supaya
perilakunya tidak bercabang. `refresh` perlu keputusan lebih dulu: Sanctum tidak punya
masa berlaku token secara bawaan, dan "memperbarui token yang hampir kedaluwarsa" hanya
bermakna bila kedaluwarsanya memang disetel.

### 122. Filter `period` pada `/payments` lewat tagihannya

API 4.9.1 menyebut filter `student_id`, `period`, dan `method` untuk `GET /payments`.
`payments` tidak punya kolom periode — periodenya milik tagihan (ERD: `student_fees.period`
format `YYYY-MM`).

Filternya karena itu berjalan lewat `whereHas('studentFee')`. Menambahkan `payments.period`
akan menduplikasi data yang sudah dimiliki tagihannya, dan menciptakan kemungkinan kedua
angka itu berbeda.

### 123. `date_to` adalah kontrak publik; `date_until` nama internal

Blueprint menyebut filter `GET /transactions` sebagai `type, category, date_from,
date_to`. `CashLedgerExporter` memakai `date_until` secara internal — nama yang dipilih
saat belum ada lapisan API.

Sejak batch ini keduanya dibedakan tegas: controller API menerima **`date_to`** dan
memetakannya ke `date_until` internal. `date_until` tidak diterima sebagai parameter
publik, dan sebuah test memastikannya — mengirim `date_until` ke API tidak menyaring apa
pun.

Docblock `CashLedgerExporter` yang sebelumnya menulis bahwa blueprint memakai `date_until`
adalah salah kutip; sudah dikoreksi.

### 124. Filter `/finance/export` adalah kontrak implementasi

API map menulis `GET /finance/export` tanpa merinci filternya. Yang dipakai adalah
kontrak filter `GET /transactions` — domain yang sama, dan itulah yang terdekat dengan
dokumen. Rentang tanggalnya wajib (butir 109).

Ini disebut sebagai kontrak implementasi, bukan klaim blueprint.

### 125. Endpoint yang sengaja belum didaftarkan

Tidak ada rute placeholder untuk kelimanya — rute yang ada tetapi selalu gagal lebih buruk
daripada rute yang tidak ada, karena klien tidak dapat membedakan "belum dibuat" dari
"sedang rusak".

| Endpoint | Alasan |
| --- | --- |
| `DELETE /transactions/{id}` | "Soft delete" tanpa kolom di ERD; butuh keputusan pemilik produk (butir 74) |
| `GET /finance/summary` | Blueprint meminta `total income`; `FinanceSummaryService` menghitung `spp_received`, dan `income` tingkat-atas belum ada |
| `GET /finance/spp-report` | `tunggakan` belum dihitung di service mana pun, dan definisinya (apakah WAIVED termasuk?) belum diputuskan |
| `GET /admin/dashboard` | Field-nya — total siswa, total SPP terkumpul, PPDB aktif — belum ada di service mana pun |
| `GET /admin/schools/{id}/stats` | Idem: jumlah siswa, guru, tagihan terkumpul bulan ini, tunggakan |

### 126. Token API tidak diaudit

`PersonalAccessToken` masuk daftar pengecualian `AuditLogger`. Tanpa itu setiap login
lewat API menulis satu baris `CREATED` dan setiap logout satu baris `DELETED`, keduanya
untuk model yang bukan data bisnis dan tidak punya `school_id` — sehingga baris auditnya
tak bercabang dan mencemari jejak yang seharusnya menjelaskan perubahan data.

Security 3.4 meminta jejak aksi CUD atas data; waktu login sendiri sudah tersimpan di
`users.last_login_at`. Alasannya sejajar dengan Role dan Permission yang sudah lebih dulu
dikecualikan (butir 45).

### 127. Akun tanpa cabang: dari melihat semuanya menjadi melihat nol

Ditemukan saat membangun test tenant API, dan ini **cacat yang sudah ada sebelumnya**,
bukan akibat lapisan API.

`SchoolScope::currentSchoolId()` mengembalikan NULL untuk dua keadaan yang sangat berbeda:
Super Admin (memang lintas cabang) dan akun School Level yang `school_id`-nya NULL.
`apply()` memperlakukan NULL sebagai "tanpa filter", sehingga akun jenis kedua **membaca
seluruh cabang** — di panel Filament maupun di API.

Jalur tulis tidak pernah terpengaruh: `PaymentRecorder`, `TransactionRecorder`,
`StudentFeeWaiver`, dan kedua exporter semuanya menolak akun tanpa cabang secara eksplisit,
dan hal itu sudah diuji sejak Batch 5.3. Yang bocor hanya pembacaan.

`apply()` sekarang membedakan ketiga keadaan itu: tanpa sesi → tanpa filter (seeder,
antrean, CLI membawa cabangnya sebagai argumen); Super Admin → tanpa filter; akun tanpa
cabang → `WHERE 1 = 0`, karena tanpa cabang tidak ada satu pun baris yang menjadi
miliknya.

Perbaikannya berlaku ke seluruh aplikasi, bukan hanya API. Seluruh 983 test tetap hijau
sesudahnya, yang juga menunjukkan tidak ada bagian sistem yang diam-diam bergantung pada
perilaku lama.

## Sprint 6 Batch 6.7 — Soft Delete Transaksi, Finance Summary, Laporan SPP

### 128. `deleted_at` pada `transactions`: konflik yang akhirnya ditutup, dan atas dasar apa

API 4.9.2 menyebut `DELETE /transactions/{id}` sebagai *"Hapus transaksi (soft delete)"*.
ERD 2.2 `transactions` tidak memuat `deleted_at`, tidak memuat status, dan tidak memuat
flag aktif. Seluruh dokumen hanya menyebut frasa "soft delete" **satu kali**, yaitu pada
baris tabel API itu — tidak ada bagian lain yang menjelaskan mekanismenya.

Sampai Batch 6.6 konflik ini dibiarkan terbuka dan penghapusan tidak dibuat sama sekali
(butir 74): menghapus permanen bukan yang diminta dokumen, dan menambah kolom untuk
menandainya berarti mengarang skema.

Yang menutupnya sekarang adalah **keputusan implementasi Phase 1**, bukan temuan baru di
dalam blueprint dan bukan keputusan owner: satu kolom `deleted_at` ditambahkan secara
aditif, dan Laravel `SoftDeletes` dipakai apa adanya. Dasarnya, kata "soft delete" pada
API sudah menyebut *jenis* penghapusannya — yang hilang hanya tempat menyimpannya, dan
kolom nullable adalah bentuk paling kecil yang memenuhinya tanpa menambah kosakata baru.

Yang sengaja **tidak** dilakukan:

- tidak ada status `VOID`/`CANCELLED` yang dikarang,
- tidak ada `deleted_by` maupun `void_reason` — keduanya tidak diminta dokumen mana pun,
  dan siapa yang menghapus sudah tercatat di `audit_logs`,
- tidak ada `forceDelete`,
- tidak ada UI maupun endpoint restore pada Phase 1.

Migrasinya aditif dan aman terhadap data yang sudah ada: kolomnya nullable tanpa nilai
bawaan, sehingga seluruh baris lama langsung berarti "belum dihapus".

### 129. Yang boleh menghapus lebih sempit daripada yang boleh mencatat

`DELETE /transactions/{id}` berlabel Auth Level **Admin**, dan API 4.1 mendefinisikannya
sebagai "wajib token + role SCHOOL_ADMIN / SUPER_ADMIN".

Untuk `POST` dan `PUT` label itu **tertimpa** user story KAS-01 yang menyebut pelakunya
secara eksplisit — *"Sebagai **Bendahara**, saya dapat mencatat pemasukan dan pengeluaran
kas sekolah"* — sehingga keduanya digantung pada `accounting.manage` (butir 78). Untuk
`DELETE` tidak ada yang menimpanya: KAS-01 bicara tentang *mencatat*, dan tidak ada satu
pun user story penghapusan di PRD 1.2. Labelnya karena itu berlaku apa adanya.

Ada ketegangan yang perlu disebut jujur: PRD 1.1.2 memberi Bendahara ✅ pada modul
"Akuntansi & Kas", dan ✅ di sana didefinisikan sebagai akses penuh "(create, update,
delete)". Baris matriks itu berlaku untuk satu modul secara umum, sedangkan Auth Level
melekat pada satu endpoint — dan yang spesifik menang atas yang umum. Menghapus baris buku
kas juga destruktif satu arah selama tidak ada restore. Hasilnya:

| Peran | create | update | delete |
| --- | --- | --- | --- |
| SUPER_ADMIN | ✅ | ✅ | ✅ |
| SCHOOL_ADMIN | ✅ | ✅ | ✅ |
| BENDAHARA | ✅ | ✅ | ❌ |
| KEPALA_SEKOLAH | ❌ | ❌ | ❌ |
| GURU/WALI/SISWA/ORTU | ❌ | ❌ | ❌ |

Ini keputusan implementasi, bukan kutipan blueprint. `accounting.manage` sengaja tidak
diperluas maupun dipecah: menambah izin baru hanya untuk satu tombol akan membuat matriks
PRD dan daftar izin tidak lagi sebangun. Yang dipakai adalah pola yang sudah ada pada
pembebasan tagihan (butir 67) — policy memeriksa peran langsung untuk satu ability.

### 130. Transaksi cabang lain: konvensi yang sama dengan `update()`

`TransactionRecorder::delete()` mencari dulu, baru memeriksa izin. Transaksi cabang lain
karena itu tidak pernah sampai ke pemeriksaan izin dan keberadaannya tidak terkonfirmasi.

Jalur ini mengembalikan **422** lewat `ValidationException`, bukan 404 — persis seperti
`PUT /transactions/{id}` yang sudah ada sejak Batch 6.6. Butir 116 menetapkan 404 untuk
jalur baca yang mengandalkan `SchoolScope` (`findOrFail`), sedangkan jalur tulis
`transactions` memakai `findWithinTenant()` yang memang melempar ValidationException.
Konsistensi antar-method pada rute yang sama dinilai lebih penting daripada menyeragamkan
kode status lintas rute; keduanya sama-sama tidak membocorkan keberadaan record.

### 131. Tidak ada restore, dan tidak ada hapus massal

Phase 1 tidak punya jalan kembali: tidak ada halaman "trashed", tidak ada aksi restore,
tidak ada endpoint restore. `TransactionPolicy::restore()` dan `forceDelete()` tetap
ditulis dan tetap `false`, supaya bila suatu saat halaman trashed bawaan Filament
dipasang, ia tidak diam-diam mewarisi izin.

Penghapusan massal juga tidak ditambahkan. Yang diminta API adalah menghapus satu
transaksi; satu klik yang menghapus seluruh halaman buku kas adalah kesalahan yang jauh
lebih mahal, dan tidak ada dokumen yang memintanya.

### 132. Bukti transaksi tidak ikut dihapus

`deleted_at` terisi, `proof_url` tidak disentuh, dan berkas notanya tetap ada di disk.

Nota adalah dokumen sumber, dan transaksi yang dihapus karena salah catat justru sering
perlu ditelusuri kembali lewat buktinya. Menghapus berkasnya juga akan membuat
penghapusan tidak lagi *soft* — datanya tinggal separuh.

### 133. `SchoolContext`: aturan cabang yang tadinya disalin

Aturan "payload hanya dipercaya bila pelakunya Super Admin" sudah dipakai sejak SPP-05,
tetapi disalin utuh di `StudentFeeReportExporter` dan `CashLedgerExporter`. Batch ini
menambah dua pembaca lagi, dan menyalinnya untuk keempat kalinya berarti empat tempat yang
harus berubah bersamaan setiap kali aturannya bergeser.

Aturannya dipindahkan ke `App\Support\Finance\SchoolContext`. Perilakunya tidak berubah
sedikit pun — kedua exporter tetap punya method dengan nama yang sama dan kini
mendelegasikan ke sana, dan seluruh test keduanya tetap hijau tanpa satu pun disesuaikan.

### 134. `income` pada `/finance/summary` bukan "penerimaan SPP"

API 4.9.2: *"Ringkasan keuangan: total income, expense, saldo per bulan. Filter: year,
month"*.

`FinanceSummaryService` yang dipakai KAS-02 sudah menghitung `spp_received`, dan godaannya
adalah memakai angka itu sebagai `income`. Itu keliru. KAS-02 meminta "total penerimaan
SPP bulan ini", yang dibaca dari `payments`; endpoint ini meminta *income*, dan income buku
kas adalah `transactions` bertipe INCOME. ERD memisahkan keduanya (butir 75), dan
menjumlahkan penerimaan SPP ke sini akan menghitung uang yang sama dua kali begitu ia
disetor ke kas.

Definisi Phase 1:

- `income` = `SUM(amount)` `transactions` INCOME yang `transaction_date`-nya di dalam bulan
  terpilih dan belum dihapus,
- `expense` = idem untuk EXPENSE,
- `balance` = seluruh INCOME dikurangi seluruh EXPENSE **sejak awal riwayat sampai akhir
  bulan terpilih**, mengikuti tanggal potong saldo kas yang sudah disetujui (butir 82).

Konsekuensinya `income - expense` bulan berjalan tidak selalu sama dengan `balance`, dan
memang tidak seharusnya sama: yang satu pergerakan bulan itu, yang lain posisi.

Filter publiknya `year` dan `month` persis seperti API. Service bekerja dengan `period`
`YYYY-MM` di dalam, tetapi nama internal tidak pernah menjadi kontrak publik (butir 123) —
`?period=2026-08` ditolak 422.

### 135. Tunggakan: definisinya tidak ada di dokumen mana pun

API 4.9.2: *"Laporan SPP: total tagihan, terkumpul, tunggakan per periode"*. Kata
"tunggakan" muncul dua kali di seluruh dokumen — di sini dan pada `/admin/schools/{id}/stats`
— dan tidak pernah didefinisikan. Yang berikut karena itu keputusan implementasi Phase 1.

Untuk setiap periode:

- `total_billed` = `SUM(student_fees.amount)`,
- `total_collected` = `SUM(student_fees.amount_paid)`,
- `arrears` = `SUM(amount - amount_paid)` **hanya** untuk status UNPAID dan PARTIAL.

`total_billed - total_collected` sengaja **tidak** dipakai sebagai tunggakan. Rumus buta itu
benar selama semua tagihan masih tertagih, tetapi menjadi salah begitu ada WAIVED: tagihan
yang dibebaskan memang tidak akan pernah dibayar, dan menghitung selisihnya sebagai
tunggakan berarti melaporkan uang yang secara sadar direlakan sebagai piutang.

Perlakuan WAIVED:

- **tidak** dihitung sebagai tunggakan,
- **tetap** masuk `total_billed` sebagai nominal historis yang pernah diterbitkan —
  menghapusnya akan membuat laporan lama berubah surut setiap kali ada pembebasan baru,
- cicilan yang terlanjur masuk sebelum dibebaskan **tetap** dihitung pada
  `total_collected`, karena uangnya benar-benar diterima.

PAID otomatis tidak menyumbang tunggakan karena sisanya nol. Rumus agregatnya sengaja
sejajar dengan `StudentFee::remaining()` dan diuji agar keduanya selalu menghasilkan angka
yang sama — yang berbeda hanya tempat menghitungnya (SQL vs PHP), bukan aturannya.

### 136. Bentuk dan filter `/finance/spp-report`

API tidak menyebutkan satu pun filter untuk endpoint ini, dan tidak menetapkan bentuk
responsnya. Fallback Phase 1:

- `period=YYYY-MM` opsional — nama dan format yang sudah menjadi kontrak `student_fees`
  pada endpoint tetangganya, bukan istilah baru,
- tanpa `period`, seluruh periode yang benar-benar punya tagihan dikembalikan berurutan
  dari yang terbaru,
- hasilnya dibatasi `SppReportService::MAX_PERIODS` (60 periode ≈ lima tahun) dan respons
  membawa `truncated` supaya pemanggil tahu ia sedang melihat potongan.

Paginasi bergaya `meta` sengaja tidak dipakai: yang dipaginasi di endpoint lain adalah
daftar record, sedangkan ini agregat per periode yang jumlah barisnya sudah kecil menurut
sifatnya. Tidak ada pemetaan ke tahun ajaran — `student_fees.period` sudah `YYYY-MM` dan
dokumen tidak meminta pengelompokan lain.

Seluruh laporannya satu query agregat (`SUM` + `CASE WHEN` + `GROUP BY period`) yang
berlaku sama di MySQL maupun SQLite, sehingga jumlah query tidak tumbuh mengikuti banyaknya
tagihan maupun banyaknya periode.

### 137. "Auth Level: Auth" bukan berarti semua peran boleh membaca laporan keuangan

`/finance/summary` dan `/finance/spp-report` keduanya berlabel **Auth** pada API map. Label
itu menyatakan *token wajib*, bukan *boleh dibaca siapa saja yang punya token* — kalau
dibaca begitu, Guru dan Siswa akan mendapat laporan keuangan cabang hanya karena berhasil
login.

Yang menentukan adalah PRD 1.1.2 baris **Laporan Keuangan**: SUPER_ADMIN ✅,
SCHOOL_ADMIN ✅, KEPALA ⭕, BENDAHARA ✅, GURU/WALI ❌, SISWA ❌, ORTU ❌. Keduanya karena
itu digantung pada `financial_report.view`, ability yang sama dengan halaman Laporan
Keuangan di panel — sehingga API dan panel tidak dapat berbeda pendapat tentang siapa yang
berhak.

Keduanya juga laporan **satu cabang**. Super Admin wajib memilih cabang persis seperti pada
ekspor, dan tidak pernah diam-diam menerima gabungan seluruh cabang; yang lintas cabang
adalah KAS-03, domain yang berbeda.

### 138. Soft delete menulis satu baris audit, bukan dua

`SoftDeletes::runSoftDelete()` mengisi `deleted_at` lewat query builder, bukan lewat
`save()` pada model. Akibatnya event `updated` tidak ikut terpicu dan listener audit
wildcard hanya menerima `deleted` — satu baris DELETED, bukan UPDATED + DELETED.

Ini perilaku Laravel, bukan sesuatu yang perlu dipasang, tetapi diuji secara eksplisit
karena kalau suatu saat berubah, jejak auditnya akan mulai berlipat tanpa ada yang
menyadarinya.

### 139. Hari terakhir bulan yang diam-diam hilang

Ditemukan saat membangun `/finance/summary`, dan ini **bug nyata yang sudah ada sejak
KAS-02**, bukan sesuatu yang dibawa Batch 6.7.

`transaction_date` dan `payment_date` bertipe DATE menurut ERD, tetapi cast `date` Eloquent
menyimpannya sebagai `Y-m-d H:i:s`. Baris bertanggal 31 karena itu tersimpan sebagai
`2026-08-31 00:00:00`, dan `whereBetween('transaction_date', ['2026-08-01', '2026-08-31'])`
menganggapnya **lebih besar** daripada batas atas. Seluruh transaksi dan pembayaran pada
hari terakhir bulan hilang dari totalnya, tanpa error dan tanpa selisih yang mencolok.

Yang terdampak: `expenses` dan `trend` pada KAS-02, `spp_received` pada KAS-02, dan
`monthlySummary` yang baru. Saldo kas tidak terdampak karena sudah memakai `whereDate()`,
begitu pula buku kas dan ekspornya yang memakai scope `betweenDates` — scope itu memang
sudah benar sejak awal.

Perbaikannya memakai `whereDate()` di kedua ujung rentang, sama seperti scope yang sudah
benar itu. Ditambahkan test yang menjaga hari pertama dan hari terakhir bulan tetap ikut
terhitung, sekaligus memastikan bulan tetangga tetap di luar.

## Sprint 6 Batch 6.8 — Dashboard Super Admin, Statistik Cabang, Penutupan Sprint 6

### 140. "Jumlah siswa": satu definisi untuk dua endpoint

API 4.3 menyebut "total siswa" pada `/admin/dashboard` dan "jumlah siswa" pada
`/admin/schools/{id}/stats`, dan tidak mendefinisikan keduanya. Tidak ada penjelasan di
ERD, PRD, maupun arsitektur.

Keputusan implementasi Phase 1: keduanya berarti **siswa berstatus ACTIVE**. Alasannya
bukan selera, melainkan konsistensi dengan yang sudah ada — `Student::scopeActive()` sudah
dipakai penerbitan tagihan massal (SPP-02) untuk menjawab "siswa aktif", dan memakai
definisi kedua di sini akan membuat dua angka "siswa" yang saling membantah. GRADUATED,
DROPPED_OUT, dan TRANSFERRED tidak dihitung: siswa yang sudah lulus atau pindah bukan
siswa yang sedang bersekolah, dan menghitungnya akan membuat angka cabang lama terus
membengkak tanpa pernah turun.

Yang penting keduanya **sama**: ada test yang memastikan penjumlahan `student_count`
seluruh cabang selalu menghasilkan `total_students` dashboard. Kalau suatu saat salah satu
definisinya bergeser, test itu yang jatuh lebih dulu.

### 141. "Jumlah guru": peran, bukan jadwal mengajar

Tidak ada definisi di dokumen mana pun. Keputusan implementasi Phase 1: **akun aktif yang
perannya GURU atau WALI_KELAS**.

Wali kelas tetap seorang guru dengan tanggung jawab tambahan, jadi ia ikut. School Admin,
Kepala Sekolah, dan Bendahara tidak — walaupun sebagian dari mereka mungkin juga mengajar
di dunia nyata, yang dapat dibaca sistem hanyalah perannya, dan menebak lebih jauh berarti
mengarang. Akun `is_active = false` tidak dihitung: statistik ini menggambarkan keadaan
operasional sekarang, dan akun yang sudah dinonaktifkan bukan bagian darinya.

Pengguna yang memegang dua peran sekaligus dihitung **sekali**; `whereHas` + `distinct`
menjaga itu, dan sekaligus membuat hitungannya tetap satu query alih-alih satu pemeriksaan
peran per pengguna.

### 142. "PPDB aktif": pendaftarannya, bukan cabangnya

Frasa paling kabur dari ketiga metrik dashboard. Yang menentukan bacaannya adalah apa yang
benar-benar ada di skema: `ppdb_registrations` hanya punya `status`, dan **tidak ada**
konsep periode pendaftaran dibuka/ditutup di mana pun — tidak ada kolom `ppdb_open`, tidak
ada konfigurasi, tidak ada penanda pada `schools`. Seluruh repo dan seluruh dokumen sudah
ditelusuri untuk memastikannya. Membuat konsep itu sekarang berarti mengarang skema.

Keputusan implementasi Phase 1: yang dihitung **pendaftaran yang masih berjalan di alur**
— REGISTERED, DOCUMENT_REVIEW, dan PASSED. FAILED dan ENROLLED adalah ujung alur dan tidak
lagi aktif. PASSED tetap ikut karena alur PPDB berlanjut PASSED → enroll, sehingga calon
yang sudah lulus tetapi belum didaftarkan masih merupakan pekerjaan yang tertunda.

Perlu dicatat terang-terangan: artinya **"pendaftaran PPDB yang sedang berjalan"**, bukan
"jumlah cabang yang sedang membuka pendaftaran". Kalau yang dimaksud blueprint ternyata
yang kedua, yang dibutuhkan bukan perubahan rumus melainkan konsep periode PPDB yang
memang belum ada di skema.

### 143. "Total SPP terkumpul" tidak dibatasi bulan berjalan

Dua baris bersebelahan di tabel API 4.3 yang sama menulis hal berbeda: statistik cabang
menyebut "tagihan terkumpul **bulan ini**", sedangkan dashboard platform menyebut "**total**
SPP terkumpul" tanpa satu pun keterangan waktu. Perbedaan itu dibaca apa adanya —
menjadikan yang kedua diam-diam bulan berjalan berarti mengubah arti kalimatnya tanpa
dasar.

Keputusan implementasi Phase 1:

- `total_spp_collected` = `SUM(payments.amount_paid)` lintas seluruh cabang dan seluruh
  tanggal,
- `collected_this_month` = penerimaan cabang itu pada bulan kalender berjalan, memakai
  `FinanceSummaryService::sppReceived()` — definisi "penerimaan" yang sama dengan dashboard
  cabang (KAS-02), termasuk perbaikan batas hari terakhir bulan (butir 139).

Sumbernya `payments`, bukan `student_fees.amount_paid`: yang pertama riwayat uang yang
benar-benar diterima dan dapat disaring per tanggal, yang kedua kolom posisi tagihan yang
menjawab pertanyaan berbeda. Tidak ada penyaringan berdasarkan nama jenis tagihan — modul
SPP menaungi berbagai jenis tagihan dan blueprint tidak menyediakan penanda "ini SPP",
sehingga mencocokkan string `'SPP'` akan menjadi aturan karangan yang diam-diam membuang
uang gedung dan kegiatan dari angka penerimaan.

"Tunggakan" pada statistik cabang juga tanpa keterangan periode, jadi yang dilaporkan
**seluruh periode**. Angkanya tidak diambil dengan menjumlahkan hasil `report()` milik
laporan SPP: daftar itu dipotong pada `MAX_PERIODS`, sehingga cabang yang sudah berjalan
lebih dari lima tahun akan diam-diam melaporkan tunggakan yang terlalu kecil.
`SppReportService::totalArrearsForSchool()` memakai aturan yang sama persis — UNPAID dan
PARTIAL saja, WAIVED tidak pernah ikut (butir 135) — hanya tanpa `GROUP BY`. Tidak ada
rumus tunggakan kedua di mana pun.

### 144. Cabang nonaktif tetap punya statistik

`GET /admin/schools/{id}/stats` tidak menjadikan `is_active = false` sebagai 404. Cabang
yang ditutup tetap punya riwayat pembayaran dan tunggakan, dan Super Admin justru sering
perlu membacanya **setelah** cabang ditutup. Tidak ada satu pun dokumen yang meminta
sebaliknya.

Yang tetap mengikuti definisinya adalah metrik operasionalnya: siswa ACTIVE dan akun guru
aktif tetap dihitung dengan aturan yang sama, yang pada cabang tutup umumnya memang sudah
mendekati nol dengan sendirinya.

Untuk alasan yang sama, `total_students` dashboard tidak menyaring cabang nonaktif:
dokumen menyebut "semua cabang", dan menyaringnya berarti menambahkan aturan yang tidak
diminta.

### 145. Provenance kedua endpoint statistik Super Admin

Yang menentukan **apa** yang harus dikembalikan kedua endpoint ini adalah API 4.3 "Super
Admin — Manajemen Tenant": nama endpoint, Auth Level Super, dan daftar angkanya semua dari
sana. Itu tidak pernah diperdebatkan.

Yang perlu dicatat adalah soal **kapan** keduanya dijadwalkan, karena catatan versi
sebelumnya menyimpulkan terlalu jauh dari satu baris tabel.

`05-roadmap/01-implementation-order.md` (Lampiran A) merangkum Sprint 6 sebagai
*"Akuntansi | Buku kas (income/expense), Laporan keuangan, Export Excel, Dashboard
Bendahara"*. Baris itu ringkasan modul satu kalimat, dan tabel yang sama **tidak pernah
memetakan satu pun endpoint API ke sprint mana pun** — bukan hanya untuk kedua endpoint
ini. Karena itu baris tersebut tidak dapat dipakai sebagai bukti bahwa statistik Super
Admin *di luar* Sprint 6; ia hanya tidak menyebutnya, sama seperti ia tidak menyebut
endpoint lain. Catatan sebelumnya menyatakan "bukan deliverable Sprint 6" dan menebak
penempatannya ke Sprint 1 — dua-duanya kesimpulan yang tidak ditopang dokumen, dan
keduanya dicabut di sini.

Pemilik pekerjaan menyatakan bahwa roadmap yang lebih rinci menempatkan "statistik per
cabang untuk Super Admin" pada bagian reporting Sprint 6, dengan `/admin/dashboard` dan
`/admin/schools/{id}/stats` sebagai sumbernya. Pernyataan itu **tidak dapat diverifikasi
terhadap `smartsukses-docs/`**: folder itu adalah pecahan dari blueprint .docx (lihat
README-nya) dan bagian roadmap-nya hanya berisi tiga berkas — urutan sprint, biaya
infrastruktur, dan checklist go-live. Tidak ada berkas `02-ROADMAP.md`, tidak ada bagian
"Phase 8" maupun "Reporting", dan kata "statistik" hanya muncul dua kali di seluruh folder,
keduanya di tabel API 4.3.

Jadi penjadwalannya diperlakukan begini: keduanya dikerjakan sebagai bagian penutup
Sprint 6 sesuai arahan pemilik pekerjaan, dan catatan ini tidak mengklaim sebaliknya. Bila
sumber yang lebih rinci itu kelak masuk ke `smartsukses-docs/`, ia menjadi rujukan yang
lebih spesifik dan butir ini dapat menyebutnya langsung.

Kewenangannya Auth Level **Super**, dan tidak digantung pada `financial_report.view`
seperti dua laporan keuangan Batch 6.7. Alasannya berbeda kelas: laporan keuangan adalah
data satu cabang yang dibaca peran cabang, sedangkan kedua endpoint ini lintas cabang dan
eksklusif platform. Bendahara memegang `financial_report.view` dan tetap ditolak di sini.

Pemeriksaannya berlapis: `auth_level:super` pada rute, dan pemeriksaan yang sama di dalam
kedua service — karena widget panel memanggil service itu langsung tanpa melewati
middleware rute.

### 146. Yang belum ada dari API 4.3 dan 4.4, dan mengapa dibiarkan

Sisa API 4.3 — `GET /admin/schools`, `POST /admin/schools`, `GET/PUT /admin/schools/{id}`,
`PATCH /admin/schools/{id}/toggle` — dan seluruh API 4.4 `/users` belum dibuat sebagai
REST. Seluruh fungsinya sudah berjalan di panel lewat `SchoolResource` dan `UserResource`,
termasuk aktivasi/nonaktivasi cabang dan impor pengguna.

Keduanya sengaja tidak dikerjakan pada batch ini. Bukan karena tidak penting, melainkan
karena keduanya modul manajemen tenant dan pengguna yang fungsinya sudah berjalan sejak
lama di panel — yang belum pernah dibuat hanya lapisan REST-nya. Roadmap tidak memetakan
endpoint API ke sprint mana pun (butir 145), jadi yang dipakai di sini bukan klaim
penjadwalan melainkan alasan ruang lingkup: batch ini diminta menutup Sprint 6, dan
membangun CRUD tenant serta seluruh `/users` bukan bagian dari permintaan itu.

Yang perlu dipisah saat membaca status proyek: **Sprint 6 selesai** tidak sama dengan
**seluruh REST API blueprint selesai**.

## Sprint 7 Batch 7.1 — Fondasi Portal & Dashboard Orang Tua

### 147. Parent Portal: rute web biasa, bukan panel Filament kedua

Pilihan arsitektur, dan alasannya bermula dari satu baris yang sudah ada sejak lama.
`User::canAccessPanel()` menolak SISWA dan ORANG_TUA dari panel, dengan komentar yang
menyebut keduanya "dilayani portal terpisah". Aturan itu bukan penghalang yang perlu
disiasati — itu justru keputusan yang sudah diambil project ini.

Membuat panel Filament kedua akan memaksa `canAccessPanel()` menjadi sadar-panel, yaitu
mengubah method yang menjaga akses seluruh peran admin demi menambah satu peran baru.
Risikonya tidak sebanding, dan project sudah punya pola yang lebih kecil untuk halaman
non-panel: PPDB berjalan sebagai rute web biasa + komponen Livewire halaman-penuh + satu
berkas tata letak yang menyuntikkan warna cabang.

Parent Portal mengikuti pola itu:

- rute `/portal` di `routes/web.php`,
- `App\Livewire\Portal\ParentDashboard` sebagai komponen halaman-penuh,
- `resources/views/layouts/portal.blade.php` dengan CSS variables dari `SchoolBranding`,
- guard **`web` yang sama** dengan panel — bukan guard kedua, bukan tabel pengguna kedua,
  dan tidak ada token yang disimpan di peramban.

Yang berubah pada kode lama: **tidak ada**. `canAccessPanel()`, `SchoolScope`, throttle
login panel, dan aturan akses admin tidak disentuh sama sekali.

Satu hal yang memang harus dibuat: halaman masuk portal. Orang tua tidak dapat memakai
halaman masuk panel karena panel memang menolak mereka, dan melonggarkannya berarti
menyentuh aturan yang tidak boleh dilonggarkan. `PortalLogin` karena itu memakai
`Auth::attempt()` pada guard `web`, dengan throttle lima percobaan per menit seperti panel
(Arsitektur 3.4), pesan galat yang tidak membedakan email tak dikenal dari kata sandi
salah (butir 115), dan pembatalan sesi bila kredensialnya benar tetapi perannya bukan
orang tua.

Rute dashboard sengaja **tidak** memakai middleware `auth` bawaan. `auth` mengarahkan tamu
ke rute bernama `login`, dan rute itu tidak ada di project ini — satu-satunya halaman masuk
adalah milik panel (`filament.admin.auth.login`), yang justru bukan tempat orang tua.
Tanpa penyesuaian ini, tamu yang membuka `/portal` mendapat error 500, bukan pengalihan.
`EnsureParentPortalAccess` yang mengarahkannya ke halaman masuk portal.

### 148. Kepemilikan anak: dua syarat, dan keduanya wajib

Skema hanya menyediakan `students.parent_user_id` (ERD 2.2: "FK → users.id. Akun portal
ortu"). Tidak ada tabel wali, tidak ada relasi banyak-ke-banyak, dan tidak ada wali kedua —
jadi tidak ada satu pun dari itu yang dibuat.

Seorang anak dianggap milik akun ini bila **dua-duanya** benar:

1. `students.parent_user_id` = id akun yang login, dan
2. `students.school_id` = `school_id` akun itu.

Syarat kedua bukan kelebihan yang berjaga-jaga. Tanpanya, akun yang pernah dipindahkan ke
cabang lain tetap membawa anak dari cabang lamanya — dan itu kebocoran lintas cabang yang
tidak akan terlihat karena datanya memang "miliknya". Ada test khusus untuk keadaan itu.

Anak yang bukan miliknya menjadi **404**, bukan 403: membedakan "bukan milik Anda" dari
"tidak ada" sudah cukup untuk memberi tahu bahwa anak itu ada. Berlaku sama untuk anak
orang tua lain di cabang yang sama, anak cabang lain, dan id yang memang tidak ada.

Perannya diperiksa di service, bukan hanya di rute: halaman portal memanggil service yang
sama tanpa melewati middleware API. Seperti pada laporan keuangan (butir 137), Auth Level
"Auth" pada API map berarti *wajib token*, bukan *boleh dibaca semua peran yang punya
token* — admin sekolah tidak menjadi orang tua siapa pun. Akun tanpa cabang ditolak
eksplisit, tidak dibiarkan bergantung pada kebetulan bahwa `NULL` tidak pernah cocok.

### 149. Daftar anak memuat seluruh anak yang tertaut, bukan hanya yang aktif

Dokumen tidak menetapkan penyaringan status untuk `/parent/children`. Keputusan
implementasi Phase 1: seluruh siswa yang tertaut ke akun itu dikembalikan, termasuk yang
sudah GRADUATED atau TRANSFERRED.

Menyaring hanya ACTIVE akan membuat anak yang baru lulus lenyap dari portal orang tuanya
bersama seluruh riwayat tagihannya — termasuk tagihan yang mungkin masih tertunggak.
Kehilangan itu lebih merugikan daripada menampilkan satu baris tambahan.

Yang tetap mengikuti keadaan sekarang adalah data operasionalnya: `current_class` hanya
terisi bila anak benar-benar punya penempatan aktif pada tahun ajaran berjalan, dan NULL
bila tidak — bukan kelas terakhir yang sudah tidak berlaku.

### 150. Lima mapel di API, tiga di dashboard

Konflik yang eksplisit dan kedua sisinya benar:

- API 4.11 — "Dashboard anak: **nilai terbaru 5 mapel**, hadir bulan ini, tagihan pending"
- PORTAL-01 poin 1 — "Dashboard menampilkan: **3 nilai terbaru**, kehadiran bulan ini,
  tagihan belum lunas"

Keduanya tidak dipertentangkan dan tidak ada yang dikorbankan. Satu service menyediakan
lima (`SUMMARY_SUBJECTS = 5`), endpoint mengembalikan lima sesuai kontrak API, dan
dashboard memotong tampilannya menjadi tiga (`DASHBOARD_SUBJECTS = 3`) dari data yang sama.
Yang dipotong tampilannya, bukan datanya — jadi tidak ada permintaan yang hilang, dan tidak
ada dokumen yang perlu diubah. Ini interpretasi implementasi, bukan pernyataan blueprint.

### 151. "Lima mapel terbaru" berarti per mapel, dan portal hanya membaca

Dua hal yang mudah salah.

**Pertama**, "5 mapel" bukan "5 baris penilaian". Lima ulangan harian pada mapel yang sama
akan memenuhi seluruh kartu dan menyembunyikan empat mapel lain — pembacaan yang secara
harfiah mungkin, tetapi jelas bukan yang dimaksud "5 mapel". Yang diurutkan karena itu
mapelnya, berdasarkan penilaian terakhir yang masuk pada mapel itu, lalu lima teratas
diambil. Satu entri ringkas per mapel.

**Kedua**, nilainya dihitung `FinalScoreCalculator` — kalkulator yang sama dengan yang
dipakai panel dan rapor. Tidak ada rumus kedua, tidak ada snapshot baru, tidak ada
`GradeConfig` yang disentuh, dan tidak ada satu pun baris yang ditulis. Ada test yang
memastikan membaca ringkasan tidak mengubah satu atribut nilai pun dan tidak melahirkan
satu baris audit pun.

Mapel yang komponennya belum lengkap mengembalikan `score: null` dengan `is_complete:
false`, bukan angka yang dikarang — persis seperti yang sudah dilakukan kalkulatornya.

### 152. "Kehadiran bulan ini": sumber datanya tidak ada di Phase 1

PORTAL-01 meminta kehadiran bulan ini. Phase 1 tidak punya sumbernya, dan ini bukan
kelalaian implementasi melainkan keadaan blueprint itu sendiri:

- tidak ada tabel kehadiran di antara **21 tabel** yang didaftar ERD 2.1,
- tidak ada satu pun baris presensi harian yang tercatat di mana pun,
- **"Presensi Digital"** justru tercantum sebagai fitur **Phase 2**
  (`01-prd/03-phase2-overview.md`: "Absensi via aplikasi (GPS/selfie), rekap per bulan").

`report_cards` memang punya `attend_present`, `attend_sick`, `attend_permission`, dan
`attend_absent`. Keempatnya tidak dipakai, karena dua alasan: kolom itu rekap **satu tahun
ajaran**, bukan satu bulan, sehingga menjawab pertanyaan yang berbeda; dan sampai kini
tidak ada satu pun jalur di aplikasi yang mengisinya, sehingga membacanya berarti
menyajikan NULL sebagai data.

Fallback Phase 1: keadaan "belum tersedia" yang eksplisit —
`{"available": false, "reason": "attendance_source_not_available", "present_count": null}` —
dan dashboard menuliskan "Data kehadiran belum tersedia".

Yang sengaja **tidak** dilakukan: membuat tabel, menambah migrasi, menampilkan angka 0,
menghitung dari jadwal, atau mengasumsikan hadir penuh. Nol adalah pernyataan bahwa anak
tidak pernah hadir, dan itu keliru dengan cara yang tidak terlihat oleh pembacanya.

Perlu dicatat untuk penutupan Sprint 7 nanti: **PORTAL-01 tidak terpenuhi seutuhnya**.
Dua dari tiga angkanya nyata; yang ketiga menunggu sumber data yang menurut blueprint
sendiri baru datang di Phase 2.

### 153. Cabang tanpa tahun ajaran aktif bukan kesalahan

Ringkasan nilai memakai tahun ajaran aktif cabang **anaknya** — bukan cabang sesi, dan
bukan id yang dititipkan pemanggil.

Bila cabang itu belum punya tahun ajaran aktif (keadaan wajar di cabang yang baru dibuka),
`latest_grades` kembali kosong dan sisa ringkasan tetap terkirim. Bukan 500, dan bukan
respons setengah jadi. Tagihan tetap terhitung penuh karena tunggakan tidak bergantung pada
tahun ajaran mana pun.

### 154. Tagihan belum lunas memakai aturan yang sudah ada

PORTAL-01: "tagihan belum lunas (jumlah & nominal)". Aturannya sama persis dengan tunggakan
laporan SPP (butir 135): UNPAID dan PARTIAL saja.

PAID sisanya nol dengan sendirinya. WAIVED tidak pernah ikut — tagihan yang sudah
dibebaskan sekolah, lalu muncul lagi sebagai tunggakan di dashboard orang tuanya, akan
membatalkan keringanan itu di mata orang yang paling berkepentingan.

`count` dan `outstanding_amount` dihitung satu query agregat, dan nominalnya string dua
desimal seperti seluruh uang di sistem ini. `payments` dan `transactions` tidak ikut sama
sekali: yang ditanyakan posisi tagihan, bukan arus kas.

### 155. Yang belum dibuat dari API 4.11

Batch ini hanya membuat dua endpoint orang tua. Sisanya —
`/parent/children/{id}/grades`, `/fees`, `/schedule`, serta seluruh `/teacher` dan
`/student` — belum ada dan **tidak** didaftarkan sebagai placeholder, mengikuti kebiasaan
yang sama dengan butir 125.

### 156. Pemilih anak tidak dapat dipakai untuk melihat anak orang lain

Anak yang sedang dilihat disimpan sebagai id pada state komponen Livewire, dan setiap
permintaan pindah diperiksa ulang terhadap daftar anak milik akun itu sebelum dipakai. Id
yang bukan miliknya diabaikan sepenuhnya — pilihannya tetap pada anak sebelumnya, tanpa
pesan yang membedakan "anak orang lain" dari "anak tidak ada".

Ringkasannya sendiri tetap melewati `ParentPortalService::summary()`, jalur yang sama
dengan API, sehingga andai pemeriksaan di komponen suatu saat dilewati, pagar kepemilikan
di service masih berdiri.

### 157. Tidak ada pengguna setengah masuk di portal

Pemeriksaan sebelum push menemukan bahwa akun orang tua **tanpa cabang** dapat menyelesaikan
proses masuk portal: `Auth::attempt()` berhasil, sesi `web` terbentuk, dan penolakannya baru
terjadi di halaman dashboard sebagai 403. Sesi itu memang tidak membuka apa pun — panel juga
menolak ORANG_TUA — tetapi sesi sah yang menganggur adalah keadaan yang tidak perlu ada.

Seluruh syarat kelayakan kini dinilai di satu tempat (`refusalReasonFor()`) **sebelum** sesi
diakui: akun nonaktif, peran selain ORANG_TUA, akun tanpa cabang, dan penanda ganti kata
sandi. Bila salah satunya berlaku, sesi yang terlanjur dibuat `Auth::attempt()` dibuang
seluruhnya — `logout`, `invalidate`, `regenerateToken` — sehingga pemanggil kembali menjadi
tamu sepenuhnya.

Pesannya tetap satu kalimat yang sama untuk nonaktif, peran keliru, dan tanpa cabang:
membedakannya akan memberi tahu bahwa surel itu terdaftar dan seperti apa akunnya.

Regenerasi sesi pada jalur yang berhasil sudah ada sejak awal dan tetap ditempatkan setelah
seluruh pemeriksaan lolos — itu yang menutup fiksasi sesi, dan sekarang ada test yang
membandingkan id sesi sebelum dan sesudah masuk.

### 158. Portal tidak boleh menjadi jalan memutar "wajib ganti kata sandi"

Temuan paling serius pada pemeriksaan sebelum push, dan ini **cacat nyata** yang dibawa
Batch 7.1.

Aturannya berlaku untuk seluruh pengguna, bukan hanya pengguna panel:

- `03-architecture/04-security.md`: *"Minimum 8 karakter; password pertama wajib diganti
  saat login pertama"*
- `01-prd/04-non-functional-requirements.md`: *"Bcrypt/Argon2, minimal 8 karakter, wajib
  ganti password pertama"*

Tidak ada satu pun kalimat yang membatasinya pada pengguna panel. Penegaknya selama ini
`EnsurePasswordIsChanged`, tetapi middleware itu terpasang **hanya** di AdminPanelProvider
dan bekerja dengan memaksa pengguna ke halaman profil panel — jadi ia tidak dapat dipakai
ulang apa adanya untuk portal, dan tidak ikut berlaku di sana.

Akibatnya, sebelum perbaikan ini: orang tua yang kata sandinya baru direset admin
(PORTAL-04 — "reset password oleh Admin menghasilkan password sementara yang dikirim via
notifikasi") dapat masuk portal dengan kata sandi sementara itu dan memakainya tanpa batas
waktu. Kata sandi yang dikirim lewat pesan justru yang paling perlu segera diganti.

Perbaikannya dua lapis, dan keduanya menolak — bukan membuat sistem ganti kata sandi kedua:

1. **Saat masuk** — akun berpenanda ini tidak pernah diberi sesi.
2. **Di middleware portal** — sesi yang sudah berjalan berhenti begitu penandanya menyala.
   Lapis kedua ini bukan pengulangan: admin dapat mereset kata sandi pengguna yang sedang
   login, dan tanpa lapis ini sesi lama akan berjalan terus seolah tidak terjadi apa-apa.

Jalan keluarnya alur yang **sudah ada**, bukan yang baru: "lupa kata sandi" lewat surel
(AUTH-04, `admin/password-reset/request`). Rute itu rute tamu, dan justru karena akun
tersebut tidak pernah diberi sesi, alur itu selalu dapat dijangkau. Menyetel kata sandi baru
melepas penandanya sendiri lewat hook `updating` pada model User.

Yang perlu dicatat sebagai pekerjaan lanjutan: halaman ganti kata sandi **di dalam** portal
belum ada, sehingga pengalaman orang tua saat ini adalah menempuh alur surel. Membuat
halaman itu adalah pekerjaan fitur, bukan tambalan keamanan, dan tidak dikerjakan pada
pemeriksaan ini.

### 159. Yang diperiksa dan ternyata sudah benar

Dicatat supaya tidak diperiksa ulang dari nol di kemudian hari:

- **Cabang nonaktif** tidak diperiksa saat masuk — dan itu memang mengikuti perilaku yang
  ada: baik panel maupun `POST /auth/login` API tidak pernah memeriksa `schools.is_active`.
  Portal sengaja tidak membuat pengecualian sendiri.
- **Kunci throttle** memakai gabungan surel dan alamat IP, pola yang sama dengan Laravel dan
  dengan halaman masuk panel. Batasnya tetap lima percobaan per menit (Arsitektur 3.4).
- **Keluar** memakai `logout` + `invalidate` + `regenerateToken`, hanya menerima POST
  (GET menghasilkan 405), dan berada di grup `web` sehingga CSRF-nya dijaga middleware yang
  sama dengan seluruh aplikasi.
- **Pengalihan** tidak pernah berasal dari query string: tujuannya rute internal yang
  ditulis pasti. `redirect`, `next`, `return`, dan `intended` tidak berpengaruh apa pun.

## Sprint 7 Batch 7.2 — Nilai, Tagihan, dan Jadwal Anak

### 160. Nilai real-time dan rapor final hidup berdampingan

NILAI-04 menyebut keduanya dalam satu kalimat: *"saya dapat melihat nilai real-time
(sebelum rapor diterbitkan) **dan** rapor final"*, dengan poin 1 *"nilai harian/UTS/UAS
tampil segera setelah guru menyimpan"* dan poin 2 *"rapor final hanya tampil setelah Wali
Kelas menerbitkan"*.

Yang mudah keliru adalah membaca ini sebagai pergantian: seolah begitu rapor terbit, nilai
real-time digantikan angka rapor. Bukan itu bunyinya. Yang bersyarat hanya rapornya —
komponen nilainya sendiri tidak pernah disyaratkan hilang. Karena itu respons
`/parent/children/{id}/grades` selalu memuat `subjects[]` (rincian komponen dan nilai akhir
berjalan), dan `report_card` yang bernilai NULL sampai wali kelas menerbitkannya.

Nilai akhirnya dihitung `FinalScoreCalculator` — kalkulator yang sama dengan panel dan
rapor. Portal tidak punya rumus sendiri, tidak menyentuh `GradeConfig`, dan tidak menulis
apa pun; ada test yang memastikan membaca halaman ini tidak mengubah satu atribut nilai pun
dan tidak melahirkan satu baris audit pun.

### 161. Rincian komponen dipertahankan, metadata internalnya tidak

"Nilai lengkap" berarti lengkap sebagai penilaian, bukan sebagai baris basis data.

Yang dipertahankan karena bermakna bagi pembacanya: jenis penilaian (DAILY, ASSIGNMENT,
MIDTERM, FINAL, SKILL, ATTITUDE) beserta labelnya, sifat formatif/sumatifnya, nilainya,
keterangannya, dan kapan dinilai. Meringkas semuanya menjadi satu angka per mapel akan
menghilangkan alasan angka itu terbentuk — orang tua tidak akan tahu apakah 80 itu dari
ulangan harian atau dari ujian akhir.

Yang tidak ikut karena bukan urusan pembacanya: `grade_config_id`, `graded_by`, `weight`
hasil snapshot, `class_subject_id`, dan `school_id`. Semuanya jejak internal yang tidak
mengubah apa pun bagi orang tua.

### 162. Rapor: hanya yang terbit, dan hanya milik anaknya

Dua pagar, dan yang kedua yang mudah terlewat.

**Pertama**, hanya rapor berstatus terbit yang muncul maupun dapat diunduh. Rapor draf tidak
pernah keluar lewat portal dalam bentuk apa pun — bukan sebagai berkas, bukan sebagai angka
rata-rata, bukan sebagai keberadaan.

**Kedua**, kepemilikannya **tidak** diputuskan `ReportCardPolicy`. Policy itu memberi
ORANG_TUA izin `report_card.view` (RolePermissionSeeder) yang dipadankan dengan
`sharesTenant()` — artinya seorang orang tua lolos pemeriksaan policy untuk **setiap rapor
di cabangnya**, termasuk rapor anak orang lain. Itu memadai bagi peran panel yang memang
melihat seluruh siswa, tetapi jauh terlalu longgar di sini. Karena itu anaknya diresolusi
lebih dulu lewat pagar Batch 7.1, lalu rapornya wajib milik anak itu dan sudah terbit.
Diuji dari dua arah: lewat id anaknya sendiri dengan rapor orang lain, dan lewat id anak
orang lain.

Berkasnya dirender ulang lewat `ReportCardPdfRenderer`, renderer yang sama dengan panel.
Tidak ada pembuatan PDF kedua, kolom `pdf_*` tidak tersentuh, dan jalur penyimpanannya tidak
pernah muncul di respons — yang diberi tahu hanya apakah unduhannya ada.

### 163. Urutan tagihan: periode terbaru lebih dulu

SPP-04 poin 1 hanya menyebut *"tampil dalam daftar per periode"* tanpa arah urutan.

Keputusan implementasi Phase 1: `period` menurun, lalu `due_date` menurun, lalu `id`
menurun. Yang sedang berjalan itulah yang paling sering dicari orang tua, jadi ia di atas;
`id` sebagai pemutus supaya dua tagihan pada periode yang sama selalu berurutan tetap dan
tidak berpindah-pindah antar permintaan.

### 164. Kelas anak: penempatan aktif pada tahun ajaran aktif

Jadwal mengikuti kelas, jadi salah menentukan kelas berarti menampilkan jadwal yang salah
tanpa terlihat keliru.

Yang dipakai **bukan** baris `student_classes` terakhir. Anak yang pernah naik atau pindah
kelas punya beberapa baris, dan yang terakhir dibuat belum tentu yang berlaku pada tahun
ajaran yang sedang berjalan — barisnya bisa saja penempatan tahun lalu yang dicatat
belakangan. Yang dipakai penempatan berstatus ACTIVE pada tahun ajaran aktif cabangnya.
Bila tidak ada, hasilnya NULL dan jadwalnya kosong — bukan kelas lama yang sudah selesai.

Relasi `Student::activeStudentClass()` yang sudah ada sengaja tidak dipakai untuk ini:
relasi itu hanya menyaring status dan mengambil yang terbaru, tanpa mengikat tahun ajaran.

### 165. Hari, jam, dan yang tidak ditampilkan tentang guru

`schedules.day_of_week` menurut ERD bernomor 1 = Senin sampai 7 = Minggu, dan enum
`DayOfWeek` sudah ada. Tidak ada pemetaan baru yang dibuat.

`Carbon::dayOfWeek` menomori Minggu sebagai 0, jadi konversinya dilakukan sekali di service
alih-alih ditebar ke setiap pemanggil. "Hari ini" ditentukan dari tanggal lokal aplikasi,
dan disaring dari daftar mingguan yang sama — bukan dari query kedua, sehingga keduanya
tidak mungkin berbeda. Jam ditampilkan sebagai `HH:MM`; detik tidak menambah apa pun bagi
pembacanya.

Dari guru, yang keluar hanya namanya. Surel, nomor telepon, dan id akunnya bukan urusan
orang tua, dan mengirimkannya lewat portal akan menyebarkan data pribadi guru ke seluruh
orang tua di kelas itu.

### 166. Tagihan: sisa dari helper yang sudah ada, dan yang tidak ikut keluar

`remaining` memakai `StudentFee::remaining()` — helper domain yang sudah ada sejak Batch
5.x, bukan pengurangan yang ditulis ulang. Tidak ada rumus uang ketiga.

Status ditampilkan apa adanya menurut enumnya, dan **WAIVED tidak disamakan dengan PAID**:
domain membedakan keduanya, dan menyamakannya di layar akan menghapus jejak keringanan yang
diberikan sekolah. Alasan pembebasan justru ditampilkan — itu keringanan atas tagihan
anaknya sendiri, dan orang tua memang perlu tahu dasarnya.

Riwayat pembayaran memuat tanggal, jumlah, cara bayar, dan nomor referensi — persis yang
diminta SPP-04 poin 3. Yang **tidak** ikut: `proof_url` dan jalur berkasnya (disk privat),
`received_by` (id akun bendahara), `notes` petugas, dan `school_id`. Batch ini juga tidak
memberi orang tua satu pun jalur tulis: tidak mengunggah bukti, tidak mengubah, tidak
menghapus. Pelampiran bukti menyusul tetap alur administrasi keuangan (butir 119).

### 167. Satu pemilih anak untuk empat halaman

Ringkasan, nilai, tagihan, dan jadwal memakai trait `SelectsChild` yang sama. Kalau
masing-masing menyimpan pilihannya sendiri, orang tua dengan dua anak akan melihat anak yang
berbeda di tiap tab tanpa pernah merasa berpindah — dan itu jenis kebingungan yang mudah
berubah menjadi salah baca tagihan.

Pilihannya disimpan di sesi, bukan di query string: nilai dari URL adalah masukan pengguna,
dan menjadikannya sumber kebenaran berarti mengundang percobaan mengganti id anak lewat
alamat. Apa pun yang tersimpan tetap diperiksa ulang terhadap daftar anak miliknya setiap
kali halaman dimuat — id anak yang tautannya kemudian dicabut jatuh kembali ke anak pertama,
bukan dipertahankan.

Ringkasan setiap halaman tetap melewati `ParentPortalService`, jalur yang sama dengan API,
sehingga andai pemeriksaan di komponen suatu saat dilewati, pagar kepemilikan di service
masih berdiri.

### 168. Navigasi hanya memuat halaman yang benar-benar ada

Empat tautan: Ringkasan, Nilai, Tagihan, Jadwal — ditambah tombol keluar yang sudah ada di
kepala halaman. Tidak ada tab Notifikasi: notifikasi milik Sprint 8, dan memasang tautannya
sekarang berarti menjanjikan halaman yang belum ada.

Navigasinya menggulung ke samping hanya bila memang tidak muat, dan penggulungan itu terjadi
di dalam navigasinya sendiri — badan halaman tidak ikut melebar.

### 169. Yang masih tertinggal dari Batch 7.1

Halaman ganti kata sandi di dalam portal masih belum ada. Orang tua dengan kata sandi
sementara tetap ditolak masuk (butir 158) dan diarahkan ke alur "lupa kata sandi" lewat
surel. Perlindungannya sengaja tidak dilonggarkan pada batch ini; yang belum ada adalah
kenyamanannya, dan itu pekerjaan fitur tersendiri.

### 170. Izin modul + cabang tidak cukup untuk peran yang hanya berhak atas satu siswa

Ditemukan pada pemeriksaan sebelum push Batch 7.2. Satu kebocoran nyata, satu laten, dan
keduanya berakar pada hal yang sama.

`SchoolScope` menjawab pertanyaan "cabang mana", dan itu memang memadai untuk peran yang
memang melihat seluruh siswa cabangnya: admin sekolah, kepala sekolah, bendahara, guru.
Untuk dua peran ia tidak memadai, karena matriks PRD 1.1.2 memberi keduanya izin baca
modul:

- **ORANG_TUA** memegang `fee.view` (Tagihan SPP: ORTU ⭕) dan `report_card.view`
  (Generate Rapor: ORTU ⭕);
- **SISWA** memegang `report_card.view` (Generate Rapor: SISWA ⭕).

Pemeriksaan `izin + sharesTenant` karena itu meloloskan keduanya ke **seluruh record
cabang itu** — bukan hanya milik anaknya sendiri.

**Yang nyata**, dan terbukti lewat probe: `GET /api/v1/student-fees` mengembalikan seluruh
tagihan cabang kepada orang tua mana pun. Orang tua satu anak melihat tagihan anak orang
lain; `?student_id=<anak orang lain>` menunjuk langsung ke sana; dan
`GET /api/v1/student-fees/{id}` menjawab 200 untuk tagihan anak orang lain. Hanya lintas
cabang yang tertahan, karena itulah bagian yang memang dijaga SchoolScope.

**Yang laten**: `ReportCardPolicy::view()` — dan `downloadPdf()` yang mendelegasikan
kepadanya — bernilai true bagi orang tua untuk setiap rapor di cabangnya, dan bagi siswa
untuk rapor siswa lain. Tidak ada rute yang membukanya hari ini: satu-satunya konsumennya
panel, dan kedua peran itu tidak dapat memasuki panel (butir 147). Tetapi Batch 7.2 baru
saja membuka jalur baca portal, dan rute pertama yang kelak mengeksposnya tidak boleh
menemukan pagar yang bolong.

Aturannya ditulis sekali di `App\Support\StudentVisibility` dan dipakai dua bentuk, karena
dua keadaan itu memang berbeda: policy menjawab tentang **satu record**, sedangkan endpoint
daftar harus **menyaring baris** sebelum dikembalikan — policy tidak dapat melakukan yang
kedua. Peran yang tidak dibatasi melewati keduanya tanpa perubahan sama sekali, dan itu
dikunci test: admin sekolah, bendahara, kepala sekolah, guru, wali kelas, dan Super Admin
berperilaku persis seperti sebelumnya.

Tagihan milik anak orang lain kini menjadi **404**, bukan 403: barisnya disaring sebelum
pencarian, sehingga keberadaannya tidak terkonfirmasi — konvensi yang sama dengan record
cabang lain (butir 116).

`GET /api/v1/payments` tidak diubah. ORANG_TUA tidak memegang izin pembayaran sama sekali
dan endpointnya memang sudah menjawab 403; keadaan itu sekarang dikunci test supaya tidak
diam-diam terbuka di kemudian hari tanpa pagar barisnya ikut dipikirkan.

Perlu dicatat juga apa yang **tidak** ditemukan: rute `GET /report-cards/{id}/pdf` yang
disebut API 4.8 tidak pernah didaftarkan di aplikasi ini. Satu-satunya jalur PDF di luar
panel adalah rute portal Batch 7.2, dan `admin/report-cards` tetap panel-only.

## Sprint 7 Batch 7.3 — Portal Guru, Dasbor Kerja, dan Kelas Ajar

### 171. Portal guru menumpang sesi panel, bukan login ketiga

Berbeda dari portal orang tua, dan alasannya justru dari perbedaan yang sudah ada. Orang
tua **ditolak** seluruh panel (`canAccessPanel`), sehingga mereka memang butuh halaman
masuk sendiri (butir 147). Guru tidak: mereka sudah berhak memasuki panel, dan memang harus
— alur Input Nilai ada di sana.

Membuat halaman masuk ketiga karena itu tidak menyelesaikan apa pun dan menambah satu lagi
tempat yang harus benar. `/teacher` memakai sesi `web` yang sama dengan panel; tamu
diarahkan ke rute masuk panel yang sudah ada, lewat nama rutenya
(`filament.admin.auth.login`), bukan alamat yang ditulis tangan.

Konsekuensi yang disengaja: keluar dari portal guru berarti keluar dari panel juga. Itu
memang satu sesi, dan memisahkannya akan menciptakan dua keadaan masuk yang membingungkan
(butir 179).

Kewenangannya GURU dan WALI_KELAS. Wali kelas memegang seluruh akses guru ditambah
tanggung jawab perwaliannya (PRD 1.1.1), jadi keduanya masuk. Peran lain ditolak — termasuk
Super Admin, karena endpoint ini melekat pada **fungsi mengajar**, bukan pada tingkat
kewenangan: Super Admin tidak mengampu kelas mana pun, dan memberinya dasbor guru yang
kosong bukan kemurahan hati melainkan kebingungan.

### 172. "Kelas yang diampu": penugasan mengajar pada tahun ajaran aktif

Definisi kanoniknya satu, dan dipakai bersama endpoint REST, halaman portal, dan pagar
baris di panel:

> `class_subjects.teacher_id` = guru yang login,
> `class_subjects.academic_year_id` = tahun ajaran **aktif**,
> cabang yang sama.

Yang sengaja **tidak** dipakai: seluruh kelas cabang, `homeroom_teacher_id` saja, jadwal
sebagai sumber, dan penugasan tahun-tahun sebelumnya.

Satu kelas muncul **sekali** walaupun guru itu mengajar beberapa mata pelajaran di sana;
mata pelajarannya dikumpulkan di dalam barisnya. Urutannya tetap — tingkat, nama, lalu id
sebagai pemutus — supaya daftar yang sama tidak berpindah-pindah antar permintaan.

**Perwalian tidak dipalsukan menjadi penugasan mengajar.** Wali kelas yang tidak mengajar
satu pun mata pelajaran di kelas perwaliannya tetap melihat kelas itu sebagai
`homeroom_class` pada dasbor, tetapi `/teacher/classes` tidak melaporkannya — kelas itu
memang tidak punya mata pelajaran yang ia ajar, dan memasukkannya akan menghasilkan baris
dengan daftar mapel kosong. Keduanya keadaan yang berbeda dan ditampilkan berbeda.

### 173. Kelas yang tidak diampu adalah 404

Halaman daftar siswa meresolusi kelasnya lewat service, dan kelas yang tidak ada di daftar
kelas ajarnya menjadi `ModelNotFoundException` — termasuk kelas lain di cabang yang sama.
Guru tidak boleh memeriksa kelas sembarangan hanya dengan mengganti angka di alamat, dan
membedakan "bukan kelas Anda" dari "tidak ada" sudah cukup untuk memberi tahu kelas mana
yang ada.

Resolusinya terjadi saat `mount()`, bukan saat render: kelas yang bukan miliknya berhenti
sebelum kerangka halamannya sempat terbentuk.

### 174. Jadwal: milik gurunya, bukan milik kelasnya

Penyaring jadwal adalah `class_subjects.teacher_id`, bukan kelasnya. Dua guru dapat
mengajar di kelas yang sama, dan jadwal guru lain di kelas itu bukan urusannya.

Penomoran hari mengikuti ERD (1 = Senin … 7 = Minggu) lewat enum `DayOfWeek` yang sudah
ada, dan konversi `Carbon::dayOfWeek` (Minggu = 0) dilakukan sekali di service — sama
persis dengan jadwal orang tua (butir 165). Tidak ada konversi hari kedua di project ini.

Halaman `/teacher/jadwal` memenuhi KELAS-04 ("jadwal mengajar saya untuk minggu berjalan")
dengan service yang sama, tanpa endpoint API baru di luar API 4.11.

### 175. Dua hal yang belum bisa dipenuhi, dan keduanya disebut apa adanya

**Notifikasi.** API 4.11 menyebut "notifikasi masuk" pada dasbor guru. Subsistemnya milik
Sprint 8 — belum ada tabelnya, belum ada modulnya. Yang dikembalikan keadaan "belum
tersedia" secara eksplisit (`available: false`, `unread_count: null`), bentuk yang sama
dengan kehadiran pada Batch 7.1 (butir 152). Angka nol akan terbaca sebagai "tidak ada
notifikasi", padahal yang benar adalah "belum ada cara mengetahuinya".

**Pintasan "Buat Pengumuman".** Ini konflik kewenangan, bukan sekadar modul yang belum ada:

- PORTAL-02 poin 2 meminta pintasan ke Buat Pengumuman;
- NOTIF-01 menyatakan "Sebagai **Admin Sekolah**, saya dapat membuat pengumuman";
- matriks PRD 1.1.2 baris "Notifikasi (buat)" menandai **GURU/WALI ❌**.

Yang bersifat kewenangan dan lebih spesifik menang. Pintasannya tetap ditampilkan supaya
dasbornya tidak diam-diam kehilangan satu dari tiga hal yang diminta, tetapi **tidak dapat
diklik** dan menyebutkan alasannya. Tidak ada izin notifikasi yang diberikan kepada guru,
tidak ada rute notifikasi yang dibuat, dan tidak ada tautan ke halaman yang memang tidak
boleh dibukanya.

Perlu dicatat untuk penutupan Sprint 7: **PORTAL-02 poin 2 berstatus PARTIAL** — dua dari
tiga pintasan berfungsi penuh, yang ketiga terhalang konflik blueprint yang eksplisit.

### 176. Guru melihat siswa kelas ajarnya, bukan seluruh cabang

Ditemukan saat audit baris untuk batch ini, dan ini **kebocoran nyata yang sudah ada
sejak panel dibangun**: seorang guru yang membuka daftar siswa di panel melihat **seluruh
siswa cabang**, dan daftar jadwal menampilkan **jadwal seluruh guru**.

Bentuk persoalannya sama dengan butir 170: matriks PRD 1.1.2 memberi GURU/WALI ⭕ pada
modul Data Siswa serta Kelas & Jadwal, tetapi ⭕ menyatakan boleh membaca **modulnya** —
bukan boleh membaca **setiap barisnya**. Yang lebih spesifik menyebutkan batasnya:

- PRD 1.1.1 — "GURU | Guru Mata Pelajaran | Input nilai, **lihat daftar siswa kelas ajar**,
  **jadwal mengajar**";
- SIS-04 — "daftar siswa di kelas **yang saya ampu**";
- KELAS-04 — "jadwal mengajar **saya**".

`App\Support\TeacherClassVisibility` menuliskan aturannya sekali, dan dipakai pada query
kedua resource panel itu. Kelas perwalian ikut terlihat, karena wali kelas memang
bertanggung jawab atas rapor dan absensi kelas itu walaupun tidak mengajar mata pelajaran
di sana. Guru tanpa penugasan aktif melihat **nol** siswa, bukan semuanya.

Sengaja **tidak** dipasang di policy, berbeda dari butir 170. Policy nilai dan rapor
dipakai alur akademik yang sudah berjalan dan diuji sejak Sprint 4; membatasi di sana akan
mengubah perilaku penilaian, dan itu di luar lingkup batch ini. Peran administratif dan
platform melewatinya tanpa perubahan sama sekali — dikunci test.

Yang juga perlu dicatat: **tidak ada** `GET /api/v1/students` maupun `GET /api/v1/schedules`
di aplikasi ini. Satu-satunya jalur baca generik untuk keduanya adalah panel, dan itulah
yang diperbaiki.

### 177. Satu aturan kelayakan portal, dua portal

`EnsureTeacherPortalAccess` memeriksa hal yang sama dengan
`EnsureParentPortalAccess`: peran yang tepat, akun aktif, punya cabang, dan penanda ganti
kata sandi sudah lepas.

Yang terakhir itu yang paling mudah terlewat. Kedua portal berada di luar panel, sehingga
`EnsurePasswordIsChanged` — middleware panel — tidak ikut berlaku. Tanpa pemeriksaan ini,
portal guru akan menjadi jalan memutar yang persis sama dengan yang ditutup untuk orang tua
(butir 158): kata sandi sementara hasil reset admin berlaku selamanya selama pemiliknya
hanya membuka portal. Diperiksa juga pada sesi yang sudah berjalan, karena penandanya dapat
menyala setelah guru itu login.

### 178. Input Nilai menyeberang ke panel, dan itu disengaja

Pintasan "Input Nilai" menunjuk ke halaman `InputNilai` di panel lewat generator rutenya
(`InputNilai::getUrl()`), bukan alamat yang ditulis tangan.

Tidak ada antarmuka penilaian kedua yang dibuat. Alur penilaian sudah ada, sudah diuji, dan
sudah punya pagar kewenangannya sendiri sejak Sprint 4; membuat versi portalnya berarti dua
tempat memasukkan nilai dengan dua aturan yang perlahan berbeda. Guru memang berhak
memasuki panel, jadi penyeberangan ini bukan celah — hanya perpindahan halaman.

### 179. Keluar dari portal guru berarti keluar dari panel

Konsekuensi langsung dari butir 171: satu sesi `web`, satu tindakan keluar. Tombolnya
menunjuk rute keluar panel, dan sesinya berakhir di kedua tempat.

Berbeda dari portal orang tua, yang punya rutenya sendiri karena memang tidak berbagi
pintu masuk dengan siapa pun.

## Sprint 7 Batch 7.4 — Portal Siswa, Jadwal, dan Nilai

### 180. Satu aturan kelayakan portal untuk tiga portal

Batch 7.3 menyalin syarat masuk portal untuk kedua kalinya, dan batch ini akan menjadi
yang ketiga. Syaratnya sama persis di ketiganya — peran yang tepat, akun aktif, terhubung
ke sebuah cabang, dan kata sandi sementara sudah diganti — sehingga tiga salinan berarti
tiga tempat yang harus ikut berubah setiap kali salah satunya bergeser, dan yang tertinggal
adalah lubang yang tidak terlihat.

`App\Support\PortalEligibility` menuliskannya sekali. Ketiga middleware portal dan kedua
halaman masuk membacanya dari sana, masing-masing hanya menyebutkan peran yang berlaku
untuknya. Pesannya seragam untuk nonaktif, peran keliru, dan tanpa cabang: membedakannya
akan memberi tahu bahwa surel itu terdaftar dan seperti apa akunnya (butir 115, 157).

Yang paling mudah terlewat tetap syarat terakhir. Ketiga portal berada di luar panel,
sehingga `EnsurePasswordIsChanged` — middleware panel — tidak ikut berlaku di sana.

### 181. Identitas siswa selalu dari token, tidak pernah dari parameter

Berbeda dari portal orang tua, yang memang perlu memilih di antara beberapa anak, portal
siswa tidak punya pilihan sama sekali: yang dibaca hanya satu orang, dan orang itu adalah
pemilik akunnya.

Karena itu tidak ada satu pun endpoint maupun halaman yang menerima `student_id`. Alamat
profilnya pun tanpa id (`/siswa/profil`), sehingga tidak ada angka yang dapat diganti untuk
melihat siswa lain. Ada test yang memastikan seluruh rute `siswa/*` tidak memuat parameter
siswa, dan test lain yang menembakkan `?student_id=`, `?nis=`, dan `?nisn=` milik siswa
lain lalu memastikan yang kembali tetap data pemilik token.

Syarat kepemilikannya dua: `students.user_id` menunjuk akun ini, **dan** cabang siswa sama
dengan cabang akunnya. Syarat kedua menutup tautan yang tertinggal setelah akun dipindahkan
cabang — diuji dengan siswa yang tertaut ke akun cabang A tetapi datanya di cabang B.

### 182. Akun siswa yang belum tertaut bukan kesalahan

`students.user_id` boleh NULL menurut ERD: *"NULL jika siswa belum punya akun portal"*.
Kebalikannya juga mungkin — akun berperan SISWA sudah dibuat, penautannya belum.

Yang **tidak** dilakukan: mengambil siswa lain sebagai gantinya, atau membiarkan halaman
gagal dengan pesan sistem. API mengembalikan 404 yang konsisten; halaman portal menampilkan
keterangan bahwa akunnya belum terhubung dan menyarankan menghubungi administrasi. Keempat
halaman memakai keterangan yang sama, jadi tidak ada satu pun yang setengah jadi.

### 183. Notifikasi: menu tetap ada, isinya belum

PORTAL-03 poin 1 menyebut **empat** menu — Jadwal, Nilai, Notifikasi, Profil — dan API 4.11
menyebut notifikasi pada dashboard siswa. Subsistemnya milik Sprint 8: belum ada tabelnya,
belum ada modulnya.

Menu Notifikasi tetap ditampilkan supaya menunya tidak diam-diam berkurang dari empat
menjadi tiga, tetapi dirender sebagai teks yang tidak dapat diklik dan menyebutkan
alasannya. Tidak ada `href` mati, tidak ada halaman berisi data karangan, dan tidak ada
endpoint notifikasi yang dibuat. Dashboard mengembalikan keadaan "belum tersedia" secara
eksplisit — bentuk yang sama dengan kehadiran (butir 152) dan notifikasi guru (butir 175);
`unread_count: null`, bukan 0, karena nol berarti "tidak ada notifikasi" sedangkan yang
benar adalah "belum ada cara mengetahuinya".

**PORTAL-03 karena itu berstatus PARTIAL** sampai Sprint 8, dan harus disebut begitu pada
penutupan Sprint 7.

### 184. Semester nyata, bukan karangan

PORTAL-03 poin 2 meminta nilai per mata pelajaran, **per semester**, dan per komponen.

Semesternya benar-benar ada di skema: `academic_years.semester` bertipe TINYINT bernilai
1 atau 2 (ERD 2.2), dan `name` memang berbentuk "2024/2025 Semester 1". Jadi tidak ada
yang perlu dikarang — yang ditampilkan semester dari tahun ajaran aktif, dan API
mengembalikannya sebagai bagian dari `academic_year`.

Yang perlu dicatat jujur tentang batasnya: karena nilai selalu disaring ke tahun ajaran
**aktif** (sesuai API 4.11 — "tahun ajaran aktif"), yang terlihat adalah semester yang
sedang berjalan, bukan riwayat kedua semester berdampingan. Menampilkan keduanya sekaligus
akan melanggar batasan "tahun ajaran aktif" yang disebut endpointnya sendiri.

### 185. Rapor siswa: miliknya sendiri, dan hanya yang terbit

Pola yang sama dengan portal orang tua (butir 162), dengan pagar yang lebih sederhana
karena tidak ada anak yang perlu dipilih: identitas siswanya diresolusi dari akun, lalu
rapornya wajib milik siswa itu dan sudah `published()`.

Rapor draf dan rapor siswa lain sama-sama 404 — tanpa membedakan keduanya, sehingga
keberadaannya tidak terkonfirmasi. Berkasnya dirender lewat `ReportCardPdfRenderer` yang
sama dengan panel dan portal orang tua; tidak ada pembuatan PDF ketiga, `pdf_*` tidak
tersentuh, dan jalur penyimpanannya tidak pernah muncul di respons.

### 186. Profil tanpa id di alamatnya

PORTAL-03 meminta menu Profil, dan batch ini menyediakan halamannya — bukan menu mati.

Alamatnya `/siswa/profil`, tanpa parameter apa pun. Ini bukan sekadar kerapian: alamat
tanpa id tidak punya angka yang dapat diganti, sehingga tidak ada permukaan untuk mencoba
membuka profil siswa lain sejak awal.

Isinya nama, NIS, NISN, kelas aktif, tahun ajaran, status, keberadaan foto, dan nama
sekolahnya. Yang tidak ikut: `parent_user_id`, `school_id`, catatan administrasi, dan
jalur foto mentah.

### 187. Profil hanya dapat dibaca, dan itu memang batas repo saat ini

Mengubah profil dan kata sandi dari dalam portal belum dapat dilakukan, dan itu bukan
keputusan desain melainkan keadaan repo: `PATCH /auth/me` memang belum pernah dibuat, dan
membuatnya di batch ini berarti mengerjakan backlog endpoint lain.

Halamannya menyatakan bahwa perubahan data dilakukan lewat administrasi sekolah. Ini
tercatat sebagai gap yang tersisa, bersama halaman ganti kata sandi di dalam portal yang
masih tertinggal sejak Batch 7.1 (butir 169).

### 188. `StudentPolicy::view` ikut dibatasi ke barisnya

Ditemukan saat audit baris untuk batch ini. Bentuknya sama persis dengan butir 170 dan 176:
matriks PRD 1.1.2 memberi SISWA dan ORANG_TUA ⭕ pada modul Data Siswa, sehingga
`izin + sharesTenant` meloloskan keduanya ke **seluruh** siswa cabang itu.

Tidak ada rute yang mengeksposnya hari ini — tidak ada `GET /api/v1/students`, dan kedua
peran itu tidak dapat memasuki panel. Tetapi batch ini menambah portal yang membaca record
`Student`, dan pagar yang bolong sebaiknya ditutup sebelum ada yang menyandarinya.

`StudentVisibility` sudah menjawab pertanyaan itu sejak butir 170; yang dilakukan hanya
memakainya di `StudentPolicy::view()`. Peran administratif dan platform lewat tanpa
perubahan, dan guru tetap diatur `TeacherClassVisibility` di lapis query (butir 176) —
keduanya dikunci test.

### 189. Portal siswa tidak menyentuh keuangan sama sekali

Matriks PRD 1.1.2 menandai **Tagihan SPP ❌** dan **Catat Pembayaran ❌** untuk SISWA.
Portal ini karena itu tidak punya satu pun tautan, kartu, maupun angka keuangan — dan tidak
memakai ulang halaman tagihan portal orang tua.

Diuji dari dua arah: seluruh respons ketiga endpoint diperiksa tidak memuat kata maupun
angka keuangan sama sekali, dan keempat halaman diperiksa tidak memuat tautan tagihan.
Jalur generiknya juga dikunci — `GET /api/v1/student-fees` dan `/payments` menjawab 403,
tagihan siswa lain menjawab 404.

Satu perbedaan yang sempat mengecoh dan layak dicatat: tagihan **miliknya sendiri**
menjawab **403**, bukan 404. Barisnya lolos pagar baris karena memang miliknya, lalu
ditolak izin — sedangkan tagihan siswa lain disaring lebih dulu sehingga keberadaannya tidak
terkonfirmasi. Keduanya benar, dan keduanya diuji.

## Sprint 7 Batch 7.5 — Penutupan Portal

### 190. Penutupan Sprint 7: yang terbukti, dan yang hanya terlihat terbukti

Audit penutupan tidak menemukan bug baru — ketiga portal, kesepuluh endpoint §4.11, dan
seluruh pagar barisnya sudah pada tempatnya. Yang perlu dicatat justru soal **batas
klaim**, karena dua hal mudah dinyatakan selesai padahal tidak.

**Responsif.** Yang benar-benar diuji adalah struktur markup dan CSS: `viewport` meta,
satu kolom sebagai bawaan, `overflow-x: hidden`, sasaran sentuh 2,75rem, dan tabel yang
menggulung di dalam wadahnya sendiri. Itu bukan pengujian pada perangkat sungguhan, dan
dokumen penutupan menyebutnya persis begitu. Lintas perangkat adalah pekerjaan QA Sprint 9.

**NFR "< 3 detik pada 4G".** Yang terbukti adalah jumlah query tetap konstan saat data
bertambah, di seluruh halaman ketiga portal — bukan waktu muat pada jaringan ter-throttle.
Menyebut angka detik tanpa pengukuran itu akan menjadi klaim palsu, jadi statusnya ditulis
**NOT YET VERIFIED** dan diserahkan ke Sprint 9.

`docs/sprint-7-closure.md` menyimpan matriksnya, dan `SprintSevenClosureTest` menyimpan
buktinya — kelengkapan endpoint, tidak adanya tautan mati, matriks pagar baris lintas
peran, dan keseragaman aturan masuk ketiga portal. Test itu sengaja tidak mengulang
perilaku yang sudah diuji di tempatnya; ia menguji kesatuannya, yaitu hal-hal yang hanya
terlihat bila ketiga portal dibaca bersama.

Tiga kriteria PORTAL tetap berstatus tertunda dan disebut apa adanya: kehadiran (sumber
Phase 2), notifikasi (Sprint 8), dan pintasan Buat Pengumuman (konflik kewenangan yang
belum diputuskan). Verdict-nya karena itu "implementation complete with declared deferred
dependencies", bukan "100% acceptance criteria".

## Sprint 8 Batch 8.1 — Fondasi Notifikasi, Pengumuman Manual, dan API Inti

### 191. `GENERAL` diterima di pintu masuk, `ANNOUNCEMENT` yang tersimpan

| | |
| --- | --- |
| **Status** | Penyelesaian konflik dokumen |
| **Referensi** | `02-erd/07-tables-komunikasi.md` — Tabel: notifications, kolom `type` |
| **Bertentangan dengan** | `01-prd/02-features-phase1.md` — NOTIF-01 kriteria 1 |

NOTIF-01 menyebut kategori **ACADEMIC/BILLING/EMERGENCY/GENERAL**. ERD menyebut
**ANNOUNCEMENT/BILLING/ACADEMIC/EMERGENCY/SYSTEM**. Dua daftar ini tidak dapat keduanya
benar: `GENERAL` tidak ada di ERD, dan `ANNOUNCEMENT`/`SYSTEM` tidak ada di PRD.

Yang tersimpan adalah nilai ERD. Alasannya bukan urutan dokumen semata, melainkan bahwa
kolomnya sendiri yang didefinisikan di sana: menambahkan `GENERAL` ke ENUM berarti
mengarang nilai skema yang tidak diminta siapa pun, sedangkan ERD sudah menyediakan
padanan yang persis sama maknanya — kategori untuk pengumuman biasa, yaitu `ANNOUNCEMENT`.

Maka `GENERAL` diperlakukan sebagai **alias masukan**, bukan nilai simpan.
`NotificationType::fromInput()` menormalkannya ke `ANNOUNCEMENT`, sehingga klien yang
menulis integrasinya dari PRD tetap bekerja tanpa satu pun baris tersimpan memakai kata
yang tidak dikenal ERD.

`SYSTEM` tidak ditawarkan sebagai pilihan pada pembuatan manual
(`NotificationType::manualCases()`). Kategori itu milik notifikasi otomatis (NOTIF-03),
dan membiarkannya dipilih tangan manusia membuat pengumuman biasa menyamar sebagai
notifikasi sistem.

### 192. `UNIQUE(notification_id, user_id)` pada `notification_reads`

| | |
| --- | --- |
| **Status** | Pengerasan implementasi di luar ERD |
| **Referensi** | `02-erd/07-tables-komunikasi.md` — Tabel: notification_reads |

ERD tidak menyebutkan indeks unik pada pivot ini, tetapi menyebut arti kolomnya:
`read_at` adalah *"Waktu pertama kali dibaca"*. Kalimat itu hanya dapat benar bila
pasangan (notifikasi, pengguna) muncul paling banyak sekali — kalau ada dua baris, tidak
ada yang tahu mana yang "pertama".

Indeks unik ditambahkan sebagai penegak arti itu, bukan sebagai optimasi. Konsekuensinya:

- `markRead()` memakai `firstOrCreate`, jadi pemanggilan kedua mengembalikan baris yang
  sudah ada dan **tidak** menimpa `read_at`;
- `markAllRead()` memakai `insertOrIgnore`, jadi menandai semua berulang kali tetap
  idempoten tanpa perlu membaca dulu apa yang sudah ditandai;
- dua permintaan yang tiba bersamaan tidak dapat menghasilkan dua baris — yang kedua
  ditolak database, bukan diandalkan pada pemeriksaan di aplikasi.

### 193. Pivot bacaan tidak punya `school_id`, dan itu memang tidak diperlukan

| | |
| --- | --- |
| **Status** | Penyimpangan dari pola `BelongsToSchool` |
| **Referensi** | `02-erd/07-tables-komunikasi.md` — Tabel: notification_reads |

Hampir semua tabel di repo ini membawa `school_id` dan `SchoolScope`. `notification_reads`
tidak, karena ERD memberinya persis empat kolom dan tidak satu pun di antaranya cabang.

Menambahkannya akan menciptakan sumber kebenaran kedua tentang cabang sebuah notifikasi —
dan sumber kedua yang dapat berbeda dari yang pertama adalah bug yang menunggu waktu.
Pivot ini tidak pernah dipakai sebagai pagar tenant; ia selalu disandarkan pada query
notifikasi yang **sudah** tersaring (`NotificationCenter::visible()`), sehingga baris
bacaan tidak pernah menjadi jalan masuk ke notifikasi cabang lain.

`public $timestamps = false;` mengikuti alasan yang sama: ERD tidak memberi tabel ini
`created_at`/`updated_at`, dan `read_at` sudah menjawab satu-satunya pertanyaan waktu yang
relevan.

### 194. `target_id` tanpa foreign key

| | |
| --- | --- |
| **Status** | Mengikuti ERD apa adanya |
| **Referensi** | `02-erd/07-tables-komunikasi.md` — *"class_id atau user_id jika bukan ALL"* |

Satu kolom yang menunjuk dua tabel berbeda tidak dapat diberi foreign key. ERD memang
mendefinisikannya begitu, dan repo ini tidak mengubahnya menjadi dua kolom terpisah:
perubahan itu akan menyimpang dari skema sumber demi kenyamanan yang tidak diminta.

Konsekuensinya, makna kolom ini dijaga aplikasi, bukan database. `AnnouncementPublisher`
memvalidasi bahwa target benar-benar ada, berada **di cabang yang sama**, dan — untuk
INDIVIDUAL — masih aktif. Tanpa pemeriksaan itu, `target_id` hanyalah angka.

### 195. Yang sudah terkirim tidak dapat diubah

| | |
| --- | --- |
| **Status** | Keputusan implementasi (Phase 1), bukan isi dokumen |
| **Referensi** | `04-api/08-notifications.md`; NOTIF-04 kriteria 3 |

Blueprint tidak menyebutkan pengeditan pengumuman sama sekali: tidak ada `PUT`/`PATCH`
untuk isinya, dan tidak ada `DELETE`. Yang disebut justru sebaliknya — riwayat notifikasi
disimpan.

Pilihan yang diambil adalah yang menjaga jejak: **draf** dapat diubah dan dihapus dari
peredaran dengan tidak pernah dikirim, sedangkan **yang sudah terkirim** tidak dapat
diubah maupun dihapus. Alasannya sederhana: penerimanya sudah membacanya. Mengubah isi
sesudah itu membuat riwayat tidak lagi menggambarkan apa yang benar-benar dikirim, dan
menghapusnya menghilangkan bukti bahwa pesan itu pernah ada.

Ditegakkan berlapis: `NotificationPolicy::update()` menolak record yang sudah terkirim,
`AnnouncementPublisher::update()` dan `::send()` menolaknya lagi di service, dan panel
menyembunyikan tombol Ubah/Kirim. `delete()` mengembalikan `false` tanpa syarat.

Ini keputusan implementasi, bukan keputusan owner. Bila kelak pemilik produk meminta
pengumuman terkirim dapat diralat, yang tepat adalah menerbitkan pengumuman ralat —
bukan menulis ulang yang lama.

### 196. Satu definisi "penerima", dua bentuk

| | |
| --- | --- |
| **Status** | Keputusan arsitektur |
| **Referensi** | NOTIF-01 kriteria 2–3; `04-api/08-notifications.md` |

Pertanyaan "siapa yang menerima notifikasi ini" dipakai setidaknya empat kali: umpan
penerima, lencana belum dibaca, penandaan terbaca, dan nanti pembuatan tautan wa.me
(NOTIF-02). Menuliskannya empat kali berarti empat definisi yang perlahan berbeda, dan
perbedaannya baru ketahuan ketika seseorang menerima pengumuman yang bukan untuknya.

`NotificationRecipientResolver` adalah satu-satunya tempat aturan itu ditulis. Ia
menyediakan dua bentuk dari aturan yang **sama**:

- `recipientsOf(Notification)` — daftar penerima satu notifikasi, untuk sisi admin;
- `visibleTo(Builder, User)` — predikat query untuk umpan seorang pengguna.

Keduanya wajib memutuskan hal yang sama, dan itu bukan janji di komentar:
`NotificationFoundationTest::test_the_resolver_and_the_feed_predicate_agree()`
membandingkan keduanya untuk setiap kombinasi target × pengguna dan gagal bila keduanya
menyimpang.

### 197. Umpan disaring predikat, bukan perulangan

Godaan yang paling mudah adalah memuat 50 notifikasi lalu memanggil resolver untuk setiap
baris. Itu 50 query untuk satu halaman, dan lencana belum dibaca akan menambah 50 lagi.

Karena itu `visibleTo()` ditulis sebagai predikat SQL, keadaan terbaca ditempelkan lewat
satu `selectSub`, dan `unreadCount()` adalah satu agregat yang tidak menarik satu pun
baris notifikasi. Jumlah query untuk umpan tidak bergantung pada banyaknya notifikasi —
ada test yang menghitungnya.

Satu catatan portabilitas yang hanya muncul saat suite dijalankan terhadap MySQL: alias
`read_at` hasil `selectSub` **tidak** dapat dipakai di `WHERE`. SQLite menerimanya, MySQL
menolaknya dengan *Unknown column*. Filter `is_read` karena itu memakai
`whereExists`/`whereNotExists` atas subquery yang sama dengan `unreadCount()` — yang
sekaligus berarti "sudah dibaca" hanya punya satu definisi SQL di kelas ini.

### 198. Target ALL berarti pengguna aktif cabang itu, bukan semua orang

| | |
| --- | --- |
| **Status** | Pembacaan literal NOTIF-01 |
| **Referensi** | NOTIF-01 kriteria 2 — *"mengirim ke semua user aktif di cabang"* |

Tiga kata dalam kalimat itu masing-masing menutup satu kebocoran: **user** (bukan siswa
sebagai entitas, melainkan akun), **aktif** (`is_active = 1`), dan **di cabang**
(`school_id` notifikasi).

Super Admin tidak ikut. `school_id`-nya NULL, jadi ia bukan pengguna cabang mana pun, dan
`SchoolScope` yang dilewatinya tidak mengubah fakta itu. Ia juga tidak dapat dijadikan
target INDIVIDUAL, karena validasi target menuntut cabang yang sama. Keduanya konsisten:
dasbor platform bukan tempat pengumuman cabang.

### 199. Target CLASS hanya orang tua, dan satu orang tua satu kali

| | |
| --- | --- |
| **Status** | Pembacaan literal NOTIF-01 |
| **Referensi** | NOTIF-01 kriteria 3 — *"hanya untuk orang tua siswa kelas tersebut"* |

Bukan siswanya, bukan gurunya, bukan wali kelasnya. Kalimatnya menyebut orang tua, dan
kata "hanya" ada di sana. Implementasinya menyaring peran `ORANG_TUA` **dan** kepemilikan
anak dengan penempatan berstatus `ACTIVE` di kelas itu — penempatan yang sudah `MOVED`
atau `GRADUATED` bukan keanggotaan kelas sekarang.

Satu orang tua dengan dua anak di kelas yang sama muncul **sekali**. Ini bukan efek
samping: penyaringannya memakai `whereHas`, yang bertanya "apakah ada anak yang cocok",
bukan `join`, yang akan menghasilkan satu baris per anak. Untuk umpan, duplikasi hanya
membuat daftar aneh; untuk NOTIF-02 nanti, duplikasi berarti orang tua yang sama menerima
dua pesan WhatsApp yang identik.

### 200. Pengirim dan waktu kirim tidak pernah datang dari klien

Tiga nilai selalu ditentukan server, dan payload yang menyebutkannya diabaikan diam-diam:

- `sender_id` — dari sesi/token yang membuat permintaan;
- `sent_at` — dari jam server saat penerbitan;
- `school_id` — dari cabang pelakunya, kecuali Super Admin (butir 202).

Endpoint `POST /notifications` menerima `action: draft|send`, bukan `is_draft` dan
`sent_at`. Dua kolom itu dapat saling bertentangan bila diisi terpisah (`is_draft = 1`
dengan `sent_at` terisi berarti apa?), sedangkan satu kata kerja tidak bisa.

Panel dan API memakai `AnnouncementPublisher` yang sama, sehingga tidak ada jalur yang
dapat melewatkan pemeriksaan ini.

### 201. Label "Admin" pada API tidak dipakai sebagai pagar kewenangan

| | |
| --- | --- |
| **Status** | Penyelesaian konflik dokumen |
| **Referensi** | `01-prd/01-roles-and-access.md` — baris "Notifikasi (buat)"; NOTIF-01 |
| **Bertentangan dengan** | `04-api/08-notifications.md` — kolom Auth Level "Admin" |

API 4.10 memberi `POST /notifications` label Auth Level **Admin**, yang menurut konvensi
`04-api/01-conventions-and-auth.md` berarti SCHOOL_ADMIN dan SUPER_ADMIN saja. Sementara
itu matriks hak akses memberi modul "Notifikasi (buat)" kepada **SUPER_ADMIN ✅,
SCHOOL_ADMIN ✅, KEPALA ✅**.

Yang menang adalah kewenangan fungsional yang spesifik, bukan label generik: menutup
Kepala Sekolah berarti mencabut hak yang diberikan dokumen lain secara eksplisit,
sedangkan membukanya untuk Kepala tidak melanggar apa pun kecuali sebuah label ringkas.

Karena itu pagarnya adalah izin `notification.manage` — yang sudah dibagikan
`RolePermissionSeeder` persis kepada ketiga peran itu — bukan middleware `auth_level:admin`.
Tidak ada izin baru yang dibuat untuk Sprint 8.

Konflik PORTAL-02 (pintasan "Buat Pengumuman" di dasbor guru) **tetap terbuka** dan tidak
diselesaikan diam-diam dengan memberi guru izin ini. Statusnya masih seperti dicatat pada
penutupan Sprint 7.

### 202. Super Admin wajib memilih cabang, tidak mendapat gabungan semua cabang

Super Admin melewati `SchoolScope`. Bila dibiarkan, `GET /admin/notifications` akan
mengembalikan riwayat seluruh cabang tercampur tanpa penanda, dan `POST /notifications`
tanpa `school_id` akan mencoba menulis baris dengan `school_id` NULL pada kolom NOT NULL.

Keduanya ditutup dengan aturan yang sama, dan aturan itu sudah dipakai operasi
satu-cabang lain di repo ini: Super Admin **wajib** menyebut cabangnya. Tanpa itu,
jawabannya 422, bukan diam-diam menampilkan semuanya. Di panel, pilihan cabang muncul
hanya untuk Super Admin; peran cabang tidak pernah melihat kolomnya, dan nilai cabang dari
form mereka diabaikan.

### 203. Membaca notifikasi bukan soal izin, melainkan soal kepenerimaan

Sisi penerima (`/notifications*`) tidak dipagari izin sama sekali, dan itu disengaja.
Tidak ada peran yang boleh membaca notifikasi orang lain — termasuk School Admin, yang
boleh membuat pengumuman tetapi tidak boleh membaca kotak masuk seseorang. Yang
menentukan seseorang boleh membaca sebuah notifikasi adalah apakah notifikasi itu memang
ditujukan kepadanya, dan pertanyaan itu dijawab resolver (butir 196), bukan policy.

Bentuk responsnya mengikuti prinsip yang sama. `NotificationResource` adalah daftar-izin;
yang sengaja tidak ikut adalah `school_id`, `target_id` (id kelas atau pengguna lain),
`wa_template` (milik NOTIF-02, berisi teks yang sudah diisi variabel dan dapat memuat data
penerima lain), serta `is_draft` — penerima tidak pernah melihat draf, jadi menyebutkan
statusnya pun tidak ada gunanya.

Membaca daftar juga bukan tindakan "membaca notifikasi": `GET` tidak pernah menulis ke
`notification_reads`. Hanya `PATCH /notifications/{id}/read` dan `POST
/notifications/mark-all-read` yang menandai, sesuai NOTIF-04 kriteria 2 yang menyebut
klik, bukan tampil.

### 204. Notifikasi yang bukan untuknya adalah 404, bukan 403

Mengembalikan 403 untuk notifikasi milik orang lain berarti mengonfirmasi bahwa
notifikasi dengan id itu ada. Untuk sumber daya yang dipagari **baris**, bukan modul,
konfirmasi itu sendiri adalah kebocoran: seseorang dapat menyusuri id untuk mengetahui
berapa banyak pengumuman yang beredar dan kapan.

Karena itu `NotificationCenter` menyaring lebih dulu lalu `findOrFail`: notifikasi cabang
lain, notifikasi untuk kelas lain, dan draf semuanya menjadi 404 yang sama persis. Pola
ini sama dengan pagar baris pada portal orang tua, guru, dan siswa di Sprint 7.

### 205. Yang tidak dikerjakan di batch ini, dan disebut apa adanya

Batch ini membangun fondasi, pengumuman manual, dan API inti. Yang **belum** ada, beserta
tempatnya:

| Belum ada | Sumber | Rencana |
| --- | --- | --- |
| `GET /notifications/{id}/wa-links` | `04-api/08-notifications.md`; NOTIF-02 | batch wa.me berikutnya |
| Trigger otomatis (PPDB, tagihan, rapor) | NOTIF-03 kriteria 1 | batch trigger |
| Editor template notifikasi | NOTIF-03 kriteria 2 | batch trigger |
| Lonceng + halaman notifikasi di portal | NOTIF-03 kriteria 3; NOTIF-04 kriteria 1; PORTAL-03 | batch UI portal |
| Retensi riwayat 90 hari | NOTIF-04 kriteria 3 | batch retensi |

Kolom `wa_template` sudah ada di skema karena ERD mendefinisikannya, tetapi belum diisi
siapa pun; ia sengaja tidak diekspos ke penerima (butir 203). Menu Notifikasi di portal
siswa masih berupa penanda kosong seperti dicatat pada butir 183 — batch ini menyediakan
datanya, bukan tampilannya.

## Sprint 8 Batch 8.2 — Notification Center di Portal Orang Tua, Guru, dan Siswa

### 206. Lima notifikasi terbaru di dasbor adalah batas tampilan, bukan aturan bisnis

| | |
| --- | --- |
| **Status** | Keputusan implementasi (dokumen tidak menyebut angka) |
| **Referensi** | `04-api/09-portal-and-dashboard.md` — "notifikasi masuk", "notifikasi" |

API 4.11 menyebut "notifikasi masuk" pada dasbor guru dan "notifikasi" pada dasbor siswa,
dan tidak satu pun menyebut berapa banyak. Satu-satunya angka yang blueprint sebut untuk
notifikasi adalah "Limit: 50 terbaru" milik `GET /notifications` — dan lima puluh kartu di
sebuah ringkasan dasbor bukan ringkasan lagi.

Yang dipakai lima, dengan alasan yang dapat diperiksa: dasbor lain di aplikasi ini sudah
meringkas dengan lima ("nilai terbaru 5 mapel" pada dasbor anak, `SUMMARY_SUBJECTS`), jadi
angka yang sama membuat kedua ringkasan terbaca setara. Halaman Notifikasi tetap menjadi
tempat daftar penuhnya.

Ini **batas tampilan, bukan aturan bisnis**, dan bedanya nyata: lencana belum dibaca tetap
menghitung **seluruhnya**, bukan hanya lima yang ikut tampil. Kalau lima itu aturan bisnis,
notifikasi keenam akan hilang dari hitungan juga — dan itu akan salah.

Batasnya disediakan sebagai parameter `feed(['limit' => …])` alih-alih dengan mengambil
lima teratas dari lima puluh baris yang sudah ditarik: yang diminta dasbor lima, jadi yang
dibaca database pun lima. Nilai di atas 50 tetap tidak pernah melewati batas blueprint.

### 207. Satu formatter untuk dua permukaan

Notifikasi kini tampil di dua tempat di luar API: ringkasan dasbor guru/siswa, dan halaman
Notifikasi ketiga portal. Keduanya menjawab pertanyaan yang sama — "apa yang boleh
diketahui penerima tentang notifikasi ini" — dan menuliskannya dua kali berarti dua
daftar-izin yang perlahan berbeda. Sebuah kolom yang tidak boleh keluar hanya perlu lolos
sekali untuk bocor.

`NotificationPresenter` adalah satu-satunya tempat daftar itu ditulis, dalam dua bentuk:

- `summary()` — untuk dasbor: id, judul, kategori, label kategori, waktu kirim, keadaan
  terbaca. Tanpa isi pesan, karena dasbor bukan tempat membacanya;
- `detail()` — untuk halaman Notifikasi: `summary()` ditambah isi pesan, nama pengirim, dan
  label waktu yang siap tampil.

Yang sengaja tidak ikut pada keduanya sama dengan `NotificationResource`: `school_id`,
`target_type`/`target_id`, `wa_template`, `is_draft`, dan seluruh isi pivot selain keadaan
terbaca pengguna itu sendiri (butir 203).

Konsekuensi yang penting: **Blade tidak pernah menerima model `Notification`**. Ia menerima
array hasil presenter, sehingga tidak ada jalan bagi kolom yang tidak disebut di sini untuk
sampai ke halaman — bukan karena view-nya berhati-hati, melainkan karena datanya memang
tidak ada di sana.

Label waktu tidak ditambahkan ke muatan dasbor. `sent_at` dikirim sebagai ISO 8601, bentuk
yang sama dengan responsnya, dan komponen dasbor memformatnya sendiri; menambahkan label
siap-tampil ke muatan API akan memperluas kontraknya demi satu tampilan.

### 208. Satu komponen, tiga rute

| | |
| --- | --- |
| **Status** | Keputusan arsitektur |
| **Referensi** | NOTIF-04; PORTAL-03 kriteria 1 |

Orang tua, guru, dan siswa membaca kotak masuk dengan aturan yang **sama persis**: umpan
yang sama, lencana yang sama, penandaan yang sama. Tiga komponen Livewire karena itu akan
menjadi tiga salinan dari perilaku identik, dan ketika salah satu diperbaiki dua lainnya
tertinggal.

Yang ada satu: `App\Livewire\Portal\NotificationInbox`, dipakai tiga rute —
`/portal/notifikasi`, `/teacher/notifikasi`, `/siswa/notifikasi`. Yang membedakan ketiganya
**middleware portalnya**, bukan isi halamannya, dan itu memang tempat yang benar: pagar
peran sudah ada di sana untuk seluruh halaman portal lain.

Komponen itu tidak memeriksa peran sama sekali dan tidak menyaring penerima. Kepenerimaan
bukan soal peran (butir 203), jadi pertanyaan "notifikasi mana milik pengguna ini" tetap
dijawab `NotificationCenter` — yang sekaligus menjadi pagar keamanannya. Tidak ada
penargetan penerima di Blade.

Bentuknya daftar kartu pada satu halaman, dan mengklik satu kartu membentangkan pesannya.
Tidak ada halaman detail dengan `{id}` di alamatnya, karena tidak ada yang perlu
ditampilkan di sana selain pesan yang sudah ada di daftar — dan rute beralamat id berarti
satu permukaan lagi yang harus dipagari.

Menu Notifikasi karena itu berhenti menjadi penanda mati. Sampai Sprint 7, menu siswa
tampil tanpa tautan (butir 183) dan portal orang tua tidak menampilkannya sama sekali
(butir 168) — keduanya karena halamannya belum ada. Sekarang ada, jadi ketiganya tautan
sungguhan, dan test yang dulu menjaga ketiadaannya sekarang menjaga kehadirannya.

### 209. Memuat halaman bukan tindakan membaca

NOTIF-04 kriteria 2 menyebut **klik**: *"Klik notifikasi menandainya sebagai 'dibaca'"*.
Bukan tampil, bukan terlihat, bukan tergulir melewatinya.

Karena itu `GET` mana pun — halaman portal maupun `GET /notifications` — tidak pernah
menulis ke `notification_reads`, dan halaman selalu dibuka tanpa satu kartu pun terbentang.
Hanya `open()` di portal, `PATCH /notifications/{id}/read`, dan `POST
/notifications/mark-all-read` yang menandai.

Ini juga alasan pesannya baru tampil setelah diklik: satu tindakan dengan dua akibat yang
tidak dapat dipisahkan — pesannya terbuka dan notifikasinya tertandai. Kalau seluruh isi
sudah terbaca sejak halaman dimuat, "klik untuk menandai terbaca" menjadi tombol yang
maknanya dikarang, bukan tindakan yang benar-benar terjadi.

Klik kedua pada kartu yang sama menutupnya kembali dan tidak menandai apa pun lagi; klik
ketiga membuka lagi lewat jalur penandaan yang sama, yang idempoten (butir 192).

### 210. Isi notifikasi ditulis manusia, dan tetap tidak dipercaya

| | |
| --- | --- |
| **Status** | Pengerasan implementasi |
| **Referensi** | `03-architecture/04-security.md` |

Judul dan isi pesan datang dari Admin Sekolah atau Kepala Sekolah — orang yang berwenang,
bukan orang asing. Itu tidak membuatnya data yang aman dirender sebagai markup: akun
berwenang dapat ditembus, dan pesan yang sama dibaca ratusan penerima. Satu `{!! !!}` di
halaman ini berarti satu XSS tersimpan yang menjangkau seluruh cabang.

Seluruh judul dan pesan karena itu dirender `{{ }}` yang ter-escape. Tidak ada `{!! !!}`
di halaman notifikasi mana pun, dan ada test yang mengirim `<script>` sebagai isi pesan lalu
memastikan yang keluar `&lt;script&gt;`.

Baris baru tetap dihormati, tetapi lewat CSS `white-space: pre-line` — bukan dengan
mengubah `\n` menjadi `<br>`, yang berarti menyisipkan markup ke dalam isi yang ditulis
pengguna dan membuka kembali pintu yang baru saja ditutup.

### 211. Lencana dihitung sekali per halaman

NOTIF-04 kriteria 1 meminta jumlah belum dibaca tampil di badge pada ikon lonceng. Lonceng
itu ada di tata letak, yang dipakai setiap halaman portal — jadi pertanyaannya bukan
"bagaimana menghitungnya" melainkan "berapa kali".

Angkanya dihitung **sekali** di tata letak dan diteruskan ke lencana lonceng maupun entri
navigasi. Menghitungnya di dalam masing-masing komponen akan berarti satu query per elemen
untuk angka yang sama; dan karena entri navigasi dibuat sebagai komponen Blade bersama,
angkanya masuk sebagai prop alih-alih dihitung di dalamnya.

Di halaman Notifikasi sendiri lencananya **tidak ditampilkan**, dan karena itu tidak
dihitung. Alasannya perilaku Livewire: tata letak hanya dirender pada pemuatan halaman, dan
tidak ikut dirender ulang ketika aksi komponen berjalan. Kalau lencana lonceng tampil di
sana, angkanya akan tertinggal basi tepat di halaman tempat pengguna menandai bacaannya —
daftarnya berkata "semua sudah dibaca" sementara loncengnya masih menunjukkan tiga.

Menyinkronkannya akan menuntut JavaScript sendiri atau komponen lencana terpisah yang
menghitung sekali lagi; menghilangkannya di satu halaman yang memang tidak memerlukannya
tidak menuntut keduanya. Halaman itu sudah menyebut jumlahnya sendiri di judulnya, hidup,
dengan `aria-live` sehingga perubahannya terdengar. Hasilnya: tepat satu query hitungan per
halaman di mana pun, dan tidak ada satu tempat pun yang dapat menampilkan angka basi.

Hitungannya tetap `unreadCount()` kanonik: satu agregat yang tidak menarik satu baris
notifikasi pun. Tidak ada jalur yang memuat notifikasi lalu menghitungnya di PHP. Ada test
yang membuktikan biaya halaman dasbor orang tua sama untuk satu anak dan untuk empat anak —
lencana ini tidak pernah dihitung per anak.

Ketika tidak ada yang belum dibaca, lencananya **tidak muncul** — bukan nol yang dicetak.

### 212. Kotak masuk orang tua milik akunnya, bukan profil anaknya

| | |
| --- | --- |
| **Status** | Keputusan implementasi |
| **Referensi** | NOTIF-04; PORTAL-01 kriteria 2 |

Portal orang tua adalah satu-satunya portal dengan pemilih anak, jadi ia satu-satunya
tempat di mana "notifikasi siapa" dapat tertukar dengan "data anak yang mana".

Penerima sebuah notifikasi adalah **akun pengguna**, dan itu bukan tafsir: `notifications`
menargetkan `user_id` untuk INDIVIDUAL, dan `notification_reads.user_id` mencatat siapa yang
membaca. Tidak ada kolom siswa di mana pun pada kedua tabel itu.

Karena itu berpindah anak tidak mengubah isi kotak masuk, dan halaman Notifikasi tidak
menerima parameter anak sama sekali. Orang tua dengan anak di dua kelas melihat notifikasi
**kedua** kelas itu bersama-sama, apa pun anak yang sedang dipilih di halaman lain —
notifikasi kelas menjangkaunya karena ia orang tua siswa kelas itu (butir 199), bukan karena
ia sedang melihat profil anak itu.

Menyaringnya per anak akan menyembunyikan pengumuman yang sah ditujukan kepadanya, dan
penerimanya tidak akan pernah tahu ada yang disembunyikan.

Dasbor anak juga tidak mendapat data notifikasi:
`GET /parent/children/{studentId}/summary` tetap seperti apa adanya. Menyuntikkan
notifikasi ke sana akan menciptakan "notifikasi anak ini", yang tidak ada dalam skema mana
pun.

### 213. Guru menerima notifikasi, dan tetap tidak boleh membuatnya

| | |
| --- | --- |
| **Status** | Konflik yang **tetap terbuka** |
| **Referensi** | `01-prd/01-roles-and-access.md` — "Notifikasi (buat)": GURU/WALI ❌ |
| **Bertentangan dengan** | PORTAL-02 kriteria 2 — pintasan "Buat Pengumuman" |

Batch ini membuat notifikasi guru menjadi nyata, dan itu adalah godaan yang paling jelas
untuk menutup konflik PORTAL-02 diam-diam: modulnya sudah ada, jadi mengapa pintasannya
tidak diaktifkan?

Karena yang menghalanginya bukan ketiadaan modul. Matriks 1.1.2 menandai GURU/WALI ❌ pada
"Notifikasi (buat)", dan hadirnya modul tidak menggeser satu pun kewenangan. Menerima dan
membuat adalah dua hal berbeda; kalau keduanya disatukan, setiap penerima akan menjadi
pengirim.

Pintasan "Buat Pengumuman" karena itu **tetap tanpa tautan dan tetap dinyatakan sebagai
kewenangan Admin Sekolah**, dan konflik PORTAL-02 tetap tercatat sebagai belum
terselesaikan. Statusnya sama dengan pada penutupan Sprint 7; yang berubah hanya bahwa
sekarang ada halaman notifikasi di sebelahnya, dan itu memperjelas bedanya.

Yang dijaga test bukan hanya tombolnya: guru dan wali kelas tetap tidak memegang
`notification.manage` maupun `notification.view`, `POST /api/v1/notifications` dengan muatan
yang **sah** tetap 403 dan tidak menyisakan baris, dan tidak ada satu pun rute portal guru
yang menyentuh pembuatan pengumuman.

### 214. Keadaan "belum tersedia" dilepas, beserta kunci `reason`-nya

Dasbor guru dan siswa selama Sprint 7 menjawab notifikasi dengan keadaan tidak tersedia
yang eksplisit — `available: false`, `reason`, `unread_count: null` — karena angka nol akan
terbaca sebagai "tidak ada notifikasi" padahal yang benar "belum ada cara mengetahuinya"
(butir 152, 175, 183).

Sekarang caranya ada, jadi bentuknya menjadi `available: true`, `unread_count` berupa
bilangan, dan `items` berupa daftar. Nol akhirnya benar-benar berarti tidak ada notifikasi.

`reason` **hilang**, tidak diisi `null`. Kunci itu hanya punya arti selama jawabannya tidak
tersedia; mempertahankannya sebagai kunci kosong berarti menyimpan bekas penangguhan di
dalam kontrak yang sudah tidak menangguhkan apa pun. Ini perubahan bentuk respons yang
disengaja, dan pemakainya memang sudah diberi tahu bahwa keadaan itu sementara.

Kartu dasbor keduanya memakai komponen yang sama (butir 207) dan menautkan ke halaman
Notifikasi portalnya masing-masing.

### 215. Fiksur penerima diuji sebagai matriks, bukan sebagai tiga daftar

Ketiga portal harus diuji terhadap kombinasi target yang **sama persis**. Kalau tidak, satu
portal dapat lolos justru pada kombinasi yang tidak diujikan padanya — dan yang berbahaya
bukan satu target yang salah, melainkan satu peran yang kebetulan tidak pernah diperiksa
terhadap target tertentu.

Karena itu fiksurnya satu (`BuildsNotificationFixture`): dua cabang lengkap, delapan
notifikasi yang mencakup ALL, CLASS (kelas anaknya dan kelas lain), INDIVIDUAL (kepada
masing-masing peran dan kepada orang lain), draf, dan satu ALL cabang seberang. Empat
salinan fiksur ini akan perlahan berbeda, dan perbedaannya baru terasa ketika salah satu
portal membocorkan notifikasi yang bukan miliknya.

Ini penyimpangan kecil dari kebiasaan repo ini, di mana tiap kelas test membangun fiksurnya
sendiri. Alasannya spesifik: yang dibagi di sini bukan kenyamanan, melainkan **definisi
kasus ujinya**.

`PortalNotificationIntegrationTest` memeriksa matriks itu sebagai satu tabel utuh, ditambah
hal-hal yang hanya terlihat bila ketiga portal dan API dilihat bersama: keadaan terbaca yang
dibagi (API menandai → portal menampilkan terbaca, dan sebaliknya), idempotensi lewat tiga
jalur penandaan sekaligus, dan batas query.

### 216. Yang tidak dikerjakan di batch ini

Batch ini mengintegrasikan sisi penerima ke ketiga portal. Yang **belum** ada, beserta
tempatnya:

| Belum ada | Sumber | Rencana |
| --- | --- | --- |
| `GET /notifications/{id}/wa-links` | `04-api/08-notifications.md`; NOTIF-02 | batch wa.me |
| Normalisasi nomor & tombol "Buka WA" | NOTIF-02 kriteria 1, 3 | batch wa.me |
| Trigger otomatis (PPDB, tagihan, rapor) | NOTIF-03 kriteria 1 | batch trigger |
| Editor template notifikasi | NOTIF-03 kriteria 2 | batch trigger |
| Retensi riwayat 90 hari | NOTIF-04 kriteria 3 | batch retensi |
| Lonceng notifikasi di panel admin | NOTIF-04 kriteria 1 | belum dijadwalkan |

Retensi 90 hari **tidak** dikerjakan dan tidak dipalsukan: tidak ada scheduler, tidak ada
job pembersih, dan tidak ada penyaring tanggal yang menyembunyikan notifikasi lama sehingga
seakan-akan sudah dibersihkan. Riwayatnya tersimpan seluruhnya; yang belum ada mekanisme
pembatasannya.

Baris terakhir tabel itu perlu disebut apa adanya. NOTIF-04 berbunyi "Sebagai **Pengguna**",
dan Bendahara, Kepala Sekolah, serta Admin Sekolah adalah pengguna aktif cabang yang
**benar-benar menerima** notifikasi bertarget ALL — resolver memasukkan mereka (butir 198).
Tetapi ketiganya bekerja di panel admin, bukan di portal, dan panel belum punya lonceng.
Mereka karena itu punya notifikasi yang belum ada tempatnya dibaca, kecuali lewat
`GET /notifications`. Batch ini bercakupan portal, jadi itu di luar lingkupnya — bukan
karena tidak diperlukan.

## Sprint 8 Batch 8.3 — Notification Center di Panel Admin

### 217. NOTIF-04 berlaku untuk "Pengguna", dan panel adalah tempat sebagian dari mereka bekerja

| | |
| --- | --- |
| **Status** | Penutupan celah yang dicatat Batch 8.2 |
| **Referensi** | NOTIF-04 — *"Sebagai **Pengguna**, saya dapat melihat notifikasi..."* |

Butir 216 mencatatnya sebagai celah, dan batch ini menutupnya.

Kalimat NOTIF-04 tidak menyebut peran. Yang menentukan siapa penerimanya adalah penargetan,
dan penargetan ALL menjangkau **seluruh pengguna aktif cabang** (butir 198) — termasuk Admin
Sekolah, Kepala Sekolah, dan Bendahara. Ketiganya karena itu benar-benar menerima notifikasi
sejak Batch 8.1, tetapi sampai Batch 8.2 tidak punya satu pun tempat membacanya: portal
bukan untuk mereka, dan yang tersisa hanya `GET /notifications` — sebuah endpoint, bukan
halaman.

Yang membuat celah ini mudah terlewat: ia tidak terlihat sebagai bug. Tidak ada yang error,
tidak ada yang bocor, dan penerimanya tidak pernah tahu ada yang tidak sampai. Notifikasi
mereka ada, tersimpan, dan tidak terbaca siapa pun.

Halaman "Notifikasi Saya" di panel menutupnya. Ia tidak menambah endpoint, tidak menambah
tabel, dan tidak mengubah satu pun aturan penargetan — ia hanya memberi tempat kepada data
yang sudah ada.

Guru dan Wali Kelas ikut mendapatkannya meskipun sudah punya kotak masuk di portal guru,
dan itu keputusan yang perlu disebut alasannya. Mereka pengguna panel — Input Nilai ada di
sana — jadi menutup halaman ini bagi mereka berarti menolak seseorang melihat notifikasinya
**sendiri**, semata karena ia kebetulan sedang membuka permukaan yang lain. Kedua tampilan
membaca `notification_reads` yang sama, sehingga keduanya tidak pernah dapat berbeda; yang
ada bukan dua kotak masuk, melainkan satu kotak masuk yang terlihat dari dua tempat.

### 218. Kotak masuk penerima dan manajemen pengumuman adalah dua hal, dan tetap dua

| | |
| --- | --- |
| **Status** | Keputusan arsitektur |
| **Referensi** | `01-prd/01-roles-and-access.md` — "Notifikasi (buat)"; API §4.10 |

Panel kini punya dua menu bernotifikasi di grup Komunikasi, dan itu memang disengaja:

- **Pengumuman** (`NotificationResource`) — sisi **pembuat**. Menulis, mengirim, dan riwayat
  satu cabang **termasuk draf**. Dipagari izin `notification.manage`, yang dipegang
  SUPER_ADMIN, SCHOOL_ADMIN, dan KEPALA_SEKOLAH.
- **Notifikasi Saya** (`NotifikasiSaya`) — sisi **penerima**. Hanya yang ditujukan kepada
  pengguna yang sedang masuk, tanpa draf siapa pun. **Tidak dipagari izin sama sekali.**

Ketiadaan pagar izin pada yang kedua bukan kelalaian, melainkan konsekuensi butir 203:
kepenerimaan bukan soal izin. Menuntut `notification.manage` di sana akan salah dua kali
sekaligus. Ia akan menutup halaman bagi **Bendahara**, yang matriks tandai ❌ pada
"Notifikasi (buat)" tetapi tetap penerima sah notifikasi ALL cabangnya — dan menolak
seseorang membaca pesan yang memang dikirimkan kepadanya. Ia juga akan menyamakan "boleh
membuat" dengan "boleh membaca", padahal justru kebalikannya yang benar: School Admin boleh
membuat pengumuman dan **tidak** boleh membaca kotak masuk orang lain.

Yang menjaga isinya bukan izin melainkan kepenerimaan. Pengguna yang bukan penerima apa pun
membuka halaman kosong, bukan notifikasi orang lain — dan itu pagar yang lebih tepat, karena
ia menjawab pertanyaan yang sebenarnya.

Batch ini karena itu **tidak melebarkan** satu pun kewenangan pembuatan. Bendahara, Guru,
dan Wali Kelas tetap ditolak `NotificationResource`; ketiga peran yang berwenang tetap
berwenang. Ada test yang memeriksa keduanya sekaligus, supaya halaman baru ini tidak pernah
menjadi pintu belakang bagi modul di sebelahnya.

### 219. Lencana Filament tidak dapat menyegarkan dirinya, jadi ia tidak ditampilkan di tempat ia akan basi

Filament merender navigasi sebagai bagian dari tata letak, dan tata letak hanya dirender saat
halaman dimuat — bukan lagi ketika aksi Livewire berjalan. `getNavigationBadge()` karena itu
tidak dipanggil ulang setelah pengguna menandai notifikasinya terbaca.

Akibatnya persis sama dengan yang ditemukan di portal pada Batch 8.2 (butir 211): lencana
akan menunjukkan angka lama tepat di halaman tempat pengguna baru saja menandai bacaannya —
daftarnya berkata "semua sudah dibaca", loncengnya masih berkata tiga.

Solusinya sama, dan disebut apa adanya sebagai **batas**, bukan fitur: di halaman Notifikasi
Saya lencananya tidak ditampilkan, dan karena itu tidak dihitung. `getNavigationBadge()`
mengembalikan `null` ketika rute yang sedang dibuka adalah rute halaman itu sendiri.

Alternatif yang tidak diambil: menyegarkannya menuntut JavaScript sendiri, atau komponen
lencana terpisah yang menghitung sekali lagi — keduanya berbiaya lebih besar daripada
masalahnya. Halaman itu sudah menyebut jumlahnya sendiri di kepalanya, hidup, dengan
`aria-live` sehingga perubahannya terdengar.

Hasilnya: satu query hitungan per halaman panel, dan tidak ada satu tempat pun yang dapat
menampilkan angka basi. Nol tidak pernah dicetak — lencananya hilang.

### 220. Super Admin melewati izin dan scope, dan itu tetap tidak membuatnya penerima

| | |
| --- | --- |
| **Status** | Pengerasan implementasi |
| **Referensi** | Arsitektur 3.2.2; NOTIF-01 kriteria 2 |

`Gate::before` di `AppServiceProvider` meluluskan SUPER_ADMIN untuk **setiap** ability, dan
`SchoolScope` juga dilewatinya. Kalau kotak masuk ini dipagari policy, ia akan lolos; kalau
isinya disaring policy, ia akan melihat segalanya.

Ia tidak melihat apa pun, dan itu benar. Isinya disaring `NotificationCenter`, yang bertanya
"apakah orang ini penerimanya" — bukan "apakah orang ini berwenang". Jawabannya untuk Super
Admin adalah tidak: `school_id`-nya NULL, jadi ia bukan pengguna cabang mana pun, dan
resolver menutup umpannya sepenuhnya (butir 198). Ia juga tidak dapat dijadikan target
INDIVIDUAL, karena validasi target menuntut cabang yang sama.

Kotak masuknya karena itu kosong, dan halaman ini menampilkannya kosong apa adanya —
bukan diisi gabungan notifikasi seluruh cabang. Kotak masuk lintas cabang akan berarti
mengarang penerima yang tidak pernah ditargetkan siapa pun, dan dasbor platform bukan tempat
pengumuman cabang.

Ini juga menunjukkan mengapa pagar berbasis kepenerimaan lebih kuat daripada pagar berbasis
izin di tempat ini: `Gate::before` tidak punya apa pun untuk dilewati. Ada test yang
memastikan Super Admin tidak menerima notifikasi ALL cabang mana pun, tidak memegang
lencana, dan tetap dapat membuka halamannya.

Bila kelak seorang Super Admin diberi `school_id` — model mengenalinya lewat peran, bukan
lewat kolom NULL (butir 127) — ia akan menerima notifikasi cabang itu seperti pengguna lain.
Itu konsisten, bukan pengecualian: yang menentukan tetap penargetan.

### 221. Yang tidak dikerjakan di batch ini

Batch ini hanya melengkapi sisi penerima bagi pengguna panel. Yang **belum** ada tidak
berubah dari butir 216:

| Belum ada | Sumber | Rencana |
| --- | --- | --- |
| `GET /notifications/{id}/wa-links` | `04-api/08-notifications.md`; NOTIF-02 | batch wa.me |
| Normalisasi nomor & tombol "Buka WA" | NOTIF-02 kriteria 1, 3 | batch wa.me |
| Trigger otomatis (PPDB, tagihan, rapor) | NOTIF-03 kriteria 1 | batch trigger |
| Editor template notifikasi | NOTIF-03 kriteria 2 | batch trigger |
| Retensi riwayat 90 hari | NOTIF-04 kriteria 3 | batch retensi |

Retensi 90 hari tetap **tidak** dikerjakan dan tetap tidak dipalsukan: tidak ada scheduler,
tidak ada job pembersih, dan tidak ada penyaring tanggal yang menyembunyikan notifikasi lama
sehingga riwayatnya seakan-akan sudah dipangkas.

Tidak ada skema yang berubah pada batch ini: tidak ada migrasi, tidak ada kolom, dan tidak
ada endpoint API baru. Permukaan API tetap 40, dan `wa-links` tetap tidak ada.

Dengan mendaratnya halaman ini, NOTIF-04 poin 1 dan 2 berlaku untuk **seluruh** peran yang
dapat menerima notifikasi. Poin 3 — riwayat 90 hari — masih menunggu batch retensi, jadi
NOTIF-04 belum dapat disebut selesai seluruhnya.

## Sprint 8 Batch 8.4 — Tautan wa.me per penerima (NOTIF-02)

### 222. Satu aturan normalisasi nomor, dan satu cacat di dalamnya yang diperbaiki

| | |
| --- | --- |
| **Status** | Perbaikan cacat pada helper bersama |
| **Referensi** | NOTIF-02 poin 1; PPDB-04 poin 2; ERD 2.2 — `users.phone`, `students.parent_phone` |

Sprint 3 sudah meninggalkan `App\Support\WhatsAppLink`, lengkap dengan normalisasi
nomor dan pembentuk URL, dan berkasnya bahkan sudah menyebut NOTIF-02 pada
docblock-nya. Batch ini karena itu **memakainya**, bukan membuat yang kedua. Aturan
normalisasi yang berbeda antara PPDB dan Notifikasi akan berarti nomor yang sama
menghasilkan tautan berbeda tergantung modul mana yang membuatnya — dan yang
menemukannya adalah orang tua yang menerima pesan di nomor yang salah.

Satu hal di dalamnya perlu diperbaiki, dan perlu disebut sebagai perbaikan cacat,
bukan sebagai preferensi. Cabang terakhir normalisasinya berbunyi "kalau tidak
diawali 0 dan tidak diawali 62, tambahkan 62 di depannya". Untuk nomor Indonesia
yang ditulis tanpa awalan — `81234567890` — itu benar dan memang lazim. Untuk nomor
berkode negara lain, hasilnya bukan nomor orang itu: `+65 9123 4567` menjadi
`626591234567`, yaitu **nomor Indonesia milik orang lain**, yang panjangnya lolos
pemeriksaan dan tautannya terlihat wajar sepenuhnya.

Di PPDB-04 kesalahan itu terjadi satu per satu dan Admin membaca nomornya sebelum
menekan kirim. Di NOTIF-02 ia terjadi massal — satu baris per penerima, puluhan
sekaligus — dan tidak ada yang membacanya satu per satu. Pesan sekolah akan sampai
kepada orang yang tidak pernah ada hubungannya dengan sekolah itu.

Yang diubah sekecil mungkin: awalan 62 hanya ditambahkan bila nomornya dimulai `8`,
yaitu bentuk nasional nomor seluler Indonesia. Seluruh kasus yang sudah dikunci test
PPDB tetap berlaku persis — `0…`, `+62…`, `62…`, `81…`, pemisah spasi/hubung/kurung,
dan penolakan nomor terlalu pendek. Yang berubah **hanya** nomor tanpa awalan yang
tidak dimulai `8`: sebelumnya diberi awalan 62, kini dilaporkan tidak dapat dipakai.
Seluruh nomor pada factory dan seeder dimulai `0`, sehingga tidak ada data uji
maupun demo yang terpengaruh, dan suite PPDB lengkap dijalankan ulang untuk
membuktikannya.

Ini **bukan** validasi awalan operator. Tidak ada daftar 811/812/813 di mana pun;
yang dibedakan hanya "nomor Indonesia" dari "nomor negara lain".

Aturan yang berlaku, seluruhnya, adalah aturan implementasi — bukan keputusan
pemilik. Blueprint hanya menuliskan bentuk URL-nya (`wa.me/62[nomorHP]`) dan tidak
pernah menyebut bagaimana `+62`, `0`, spasi, atau nomor asing diperlakukan.

### 223. Membuat pengumuman dan membuka daftar nomornya adalah dua kewenangan

| | |
| --- | --- |
| **Status** | Keputusan kewenangan |
| **Referensi** | User Flow — pemetaan peran per fitur; NOTIF-01; NOTIF-02; API 4.1 — Auth Level "Admin"; API 4.10 |

NOTIF-02 dipetakan **hanya kepada Admin Sekolah**. User Flow memetakan Admin Sekolah
ke NOTIF-01…02 dan Kepala Sekolah **hanya** ke NOTIF-01, dan API 4.1 mendefinisikan
Auth Level "Admin" — label yang dipakai API 4.10 untuk `GET
/notifications/{id}/wa-links` — sebagai SCHOOL_ADMIN / SUPER_ADMIN.

Kewenangannya karena itu:

| Peran | Buat pengumuman (NOTIF-01) | Daftar wa.me (NOTIF-02) |
| --- | --- | --- |
| SUPER_ADMIN | ✅ | ✅ |
| SCHOOL_ADMIN | ✅ | ✅ (cabangnya sendiri) |
| KEPALA_SEKOLAH | ✅ (butir 201) | ❌ |
| BENDAHARA, GURU, WALI_KELAS, ORANG_TUA, SISWA | ❌ | ❌ |

Baris Kepala Sekolah itulah inti butir ini, dan pembacaan pertama pada batch ini
sempat salah di sana. Alasannya perlu ditulis supaya tidak terulang: butir 201
meluluskan Kepala pada `POST /notifications` karena NOTIF-01 memang memetakan
pembuatan kepadanya. Pengecualian itu **berhenti di NOTIF-01**. Meneruskannya ke
NOTIF-02 berarti melebarkan kewenangan lewat kemiripan label — dua endpoint yang
kebetulan sama-sama berlabel "Admin" — bukan lewat sumber yang memetakan fiturnya.

Yang dilebarkan pun bukan hal kecil. Daftar ini memuat nomor telepon **seluruh**
penerima, termasuk orang tua yang tidak pernah membuka aplikasi ini. Ketika dua
pembacaan sama-sama mungkin dan yang dipertaruhkan data pribadi pihak ketiga, yang
lebih sempit yang dipilih — dan di sini pembacaan yang lebih sempit juga yang
didukung pemetaan peran.

`NotificationPolicy::waLinks()` karena itu **tidak** meneruskan ke `view()` dan
**tidak** memakai `notification.manage`. Izin itu justru dipegang Kepala Sekolah
juga, sehingga memakainya akan menghasilkan jawaban yang salah. Yang diperiksa
perannya: Super Admin lulus (ditulis eksplisit meski `Gate::before` sudah
meluluskannya, supaya pagarnya terbaca utuh dari satu tempat), Admin Sekolah lulus
untuk cabangnya sendiri, sisanya ditolak.

Kepala Sekolah tidak kehilangan apa pun yang memang miliknya: ia tetap membuat,
menyimpan draf, mengirim, dan membaca riwayat pengumuman cabangnya, dan ada test
yang menjaga NOTIF-01 tetap hijau justru di berkas yang sama dengan penolakan
NOTIF-02-nya. Guru dan Wali Kelas tetap tidak memperoleh kewenangan apa pun atas
pembuatan maupun pengiriman, dan konflik PORTAL-02 tetap terbuka.

Cabangnya dijaga terpisah dari peran: Admin Sekolah dituntut satu cabang dengan
notifikasinya, dan Super Admin — yang melewati `SchoolScope` — tetap hanya melihat
penerima **satu** notifikasi yang ia buka, bukan gabungan cabang (butir 227).

### 224. Draf tidak punya tautan siap kirim, dan jawabannya 422

| | |
| --- | --- |
| **Status** | Penafsiran implementasi |
| **Referensi** | NOTIF-02 — "daftar link wa.me **siap kirim**" |

Blueprint tidak menyebut draf pada NOTIF-02 sama sekali. Yang dipilih mengikuti kata
"siap kirim": draf belum menjadi komunikasi kepada siapa pun. Mengirimkan tautannya
lewat WhatsApp akan menyampaikan pengumuman yang menurut sistem tidak pernah
diterbitkan — penerimanya membaca pesannya di WhatsApp lalu tidak menemukannya di
kotak masuk, dan riwayat cabang pun tidak mencatatnya sebagai terkirim. Dua kanal
yang saling membantah.

Jawabannya **422, bukan 404**. Notifikasinya memang ada, dan pelakunya memang boleh
melihatnya — ia bahkan tampil di daftar Pengumuman lengkap dengan draf. Yang belum
ada adalah keadaannya, dan 404 akan berbohong tentang hal yang lain. Di panel,
halaman tautannya tetap terbuka dan menyatakan dengan jelas bahwa pengumumannya
masih draf; pada kedua jalur, tidak satu pun nomor telepon dibaca untuk draf.

Ini penafsiran implementasi, bukan keputusan pemilik.

### 225. Nomor mana yang dipakai, dan kapan sistem menolak menebak

| | |
| --- | --- |
| **Status** | Keputusan arsitektur |
| **Referensi** | ERD 2.2 — `users.phone`; ERD 2.3 — `students.parent_phone` |

Identitas penerima adalah **akun**, karena penerima ditentukan resolver dan resolver
mengembalikan `User`. Urutannya karena itu:

1. `users.phone` milik penerima;
2. `students.parent_phone`, hanya bila akun itu tidak punya nomor **dan** hubungannya
   jelas.

Syarat kedua adalah hubungan `students.parent_user_id`, bukan label peran. Hubungan
itulah yang membuat sebuah nomor relevan bagi sebuah akun; memeriksanya lewat relasi
juga menghindari satu query peran tambahan. Ruang lingkupnya mengikuti target: pada
notifikasi CLASS hanya anak **di kelas yang ditarget** yang dihitung, sedangkan pada
ALL dan INDIVIDUAL seluruh anak di cabang notifikasi itu.

Nomor siswa sendiri (`users.phone` milik akun siswa) tidak pernah dipakai sebagai
nomor orang tuanya. Kolomnya memang bukan nomor mereka, dan ada test yang menjaganya.

Yang paling perlu disebut adalah kasus yang sistem **tolak** jawab. Satu orang tua
dengan dua anak yang `parent_phone`-nya berbeda tidak punya jawaban benar: keduanya
tersimpan sebagai nomor orang yang sama, dan hanya sekolah yang tahu mana yang masih
aktif. Memilih salah satunya — yang pertama, yang terbaru, mana pun — berarti
mengirim ke nomor yang mungkin bukan miliknya sambil terlihat berhasil. Statusnya
dilaporkan `AMBIGUOUS_PHONE`, dan nomornya dikosongkan. Dua anak dengan nomor yang
**sama** bukan ambiguitas: nomornya dipakai sebagai kunci, sehingga duplikat runtuh
menjadi satu calon.

### 226. Penerima tanpa nomor tetap ada di daftar

| | |
| --- | --- |
| **Status** | Keputusan arsitektur |

Godaannya adalah membuang penerima yang tidak punya nomor: daftarnya jadi rapi dan
setiap barisnya dapat diklik. Justru itu bahayanya. Admin akan membaca daftar yang
memendekkan dirinya sendiri sebagai "semua penerima", padahal yang hilang persis
orang-orang yang perlu dihubungi dengan cara lain — dan tidak ada apa pun di layar
yang memberi tahu bahwa mereka ada.

Setiap penerima karena itu selalu muncul, dengan `wa_available` dan alasannya bila
tidak: `MISSING_PHONE`, `INVALID_PHONE`, atau `AMBIGUOUS_PHONE`. Di atas daftarnya
ada tiga hitungan — jumlah penerima, yang terjangkau, dan yang tidak.

Ketiga hitungan itu selalu menggambarkan **seluruh** penerima, bukan hanya baris yang
lolos filter. Kalau ikut menyusut, menyaring ke "tidak terjangkau" akan memberi tahu
Admin bahwa tidak ada seorang pun yang terjangkau — kebalikan dari yang benar.
Halamannya menyebutkan hal ini di bawah angkanya, dan ada test yang menjaganya.

### 227. Kewenangan diperiksa sebelum satu pun nomor dibaca

| | |
| --- | --- |
| **Status** | Pengerasan implementasi |

Respons ini memuat data pribadi orang yang bahkan tidak sedang memakai aplikasi.
Urutan di controller karena itu disengaja: notifikasinya diselesaikan lebih dulu
**dengan** global scope-nya — sehingga id milik cabang lain berakhir 404 dan tidak
pernah mengonfirmasi bahwa id itu ada (butir 116) — lalu kewenangan diperiksa, lalu
keadaan draf, dan baru sesudah semuanya lolos service-nya dipanggil. Penyaring
pencarian pun divalidasi setelah itu, supaya percobaan lintas cabang selalu dijawab
sama apa pun query string-nya.

Daftar-putih responsnya ada di satu tempat, `NotificationWaLinkService`, dan API
maupun panel memakainya. Notifikasinya hanya `id` dan `title`; penerimanya hanya
`name`, `phone`, `normalized_phone`, `wa_available`, `wa_url`, `reason`, dan
`reason_label`. Tidak ada surel, sandi, `school_id`, `target_id`, `is_draft`,
`sender_id`, keadaan terbaca, maupun satu pun data anak — nama anak tidak ikut
meskipun nomornya berasal dari sana. Ada test yang memeriksa kunci barisnya persis,
bukan sekadar ketiadaan beberapa kata.

Panel tidak memanggil API-nya sendiri lewat HTTP; ia memakai service yang sama, jadi
kedua permukaan tidak dapat berbeda.

### 228. Sistem hanya membuat tautan, dan tidak berpura-pura tahu lebih

Phase 1 memakai wa.me manual (Ringkasan Eksekutif), dan itu dipatuhi seluruhnya.
Tidak ada pesan WhatsApp yang dikirim, tidak ada antrean pengiriman, tidak ada
integrasi WhatsApp Business API, dan **tidak ada apa pun yang ditandai "sudah
terkirim ke WhatsApp"**.

Yang terakhir itu keputusan, bukan kelalaian. Sistem tidak punya cara mengetahui
apakah Admin jadi menekan kirim di aplikasi WhatsApp-nya; mencatat "terkirim" saat
tautannya dibuat berarti menyimpan fakta yang tidak dimiliki siapa pun, dan riwayat
itu akan dipercaya justru ketika ada yang mengaku tidak menerima pesan. Ada test yang
memastikan tidak ada kunci semacam itu pada barisnya.

Membuka daftar ini juga tidak menimbulkan efek samping apa pun: tidak ada
`notification_reads` yang dibuat, `sent_at` tidak bergeser, isi notifikasi dan
`wa_template` tidak berubah, dan nomor yang sudah dinormalkan **tidak** ditulis balik
ke `users` maupun `students` — normalisasi dilakukan untuk membuat tautannya, bukan
untuk memperbaiki data yang disimpan sekolah.

### 229. Teks pesan: `wa_template` bila ada, isi pengumuman bila tidak

`notifications.wa_template` sudah ada di skema sejak Batch 8.1 dan sampai kini tidak
pernah diisi apa pun. Bila kelak berisi teks WA yang dimaksud, teks itu yang dipakai;
bila kosong, `notifications.message` yang dikirim.

Yang **tidak** dikerjakan di sini: tidak ada placeholder yang diproses, tidak ada
editor template, dan tidak satu pun kolom `wa_template_ppdb`/`_spp`/`_rapor` milik
`schools` yang disentuh. Semuanya milik NOTIF-03 dan menunggu batch tersendiri.

### 230. Biaya query tidak tumbuh bersama jumlah penerima

Daftar penerima target ALL sebesar satu cabang. Mencarikan nomor per baris akan
berarti satu query per orang tua, dan pada cabang berisi ratusan akun itu bukan
lambat melainkan tidak dapat dipakai.

Nomor cadangan karena itu dimuat sekaligus: satu query untuk seluruh penerima yang
akunnya tidak punya nomor, dikelompokkan di memori berdasarkan `parent_user_id`.
Totalnya dua query — satu daftar penerima dari resolver, satu nomor cadangan — dan
menjadi satu bila semua penerima punya nomornya sendiri. Ada test yang membandingkan
5 penerima dengan 50, sedikit siswa dengan banyak siswa, dan satu anak dengan enam
anak; ketiganya menuntut angka yang **sama persis**, bukan sekadar "tidak terlalu
besar".

Penyaringan pencarian dan ketersediaan dikerjakan atas daftar yang sudah tersusun,
bukan sebagai query, karena nomor sebagian penerima berasal dari data anak — menyaring
di database berarti menyaring atas kolom yang belum tentu dipakai. Daftarnya sendiri
sudah dibatasi target notifikasinya.

### 231. Daftar tautan milik sisi pembuat, dan tetap terpisah dari kotak masuk

Aksi "Link WhatsApp" ada di tabel Pengumuman, dan halamannya hidup di bawah resource
yang sama (`/admin/notifications/{record}/wa-links`). Ia **tidak** ada di Notifikasi
Saya, dan itu lanjutan langsung dari butir 218.

Kotak masuk menjawab "apa yang ditujukan kepada saya". Halaman ini menjawab "kepada
siapa saja pengumuman ini ditujukan, dan bagaimana menghubungi mereka". Menaruhnya di
kotak masuk berarti setiap penerima melihat nomor telepon seluruh penerima lain —
persis kebalikan dari pemisahan yang dijaga sejak batch sebelumnya. Ada test yang
membuka Notifikasi Saya dan memastikan tidak ada `wa.me` maupun "Buka WA" di sana.

Aksinya hanya muncul untuk pengumuman yang sudah terkirim, dan hanya bagi yang
kewenangannya lolos — visibilitasnya memakai policy yang sama dengan endpoint-nya,
bukan pemeriksaan kedua yang dapat berbeda.

### 232. Menyalin satu per satu, dan tidak pernah membuka tab sendiri

NOTIF-02 poin 2 menyebut "disalin **satu per satu**", dan itu diikuti apa adanya:
satu tombol Salin per penerima, tanpa salin massal — karena kirimnya pun satu per
satu. Yang disalin adalah URL wa.me-nya, karena yang diminta memang daftar link.

Penyalinannya memakai Alpine yang sudah dibundel Filament; tidak ada pustaka baru.
Clipboard API tidak selalu tersedia — pada HTTP polos atau peramban lama ia tidak ada
sama sekali — jadi kegagalannya tidak dibiarkan senyap: tautannya ditampilkan pada
kotak teks yang otomatis terpilih saat diklik, dengan keterangan bahwa salin otomatis
tidak tersedia.

"Buka WA" adalah tautan biasa dengan `target="_blank"` dan `rel="noopener noreferrer"`,
dan hanya terbuka bila diklik. Tidak ada `window.open` di halaman ini dan tidak ada
aksi yang membuka banyak tab sekaligus: membuka puluhan tab WhatsApp bukan bantuan,
dan sebagian besar peramban akan memblokirnya. Ada test yang memeriksa ketiganya.

### 233. Yang tidak dikerjakan di batch ini

| Belum ada | Sumber | Rencana |
| --- | --- | --- |
| Trigger otomatis (PPDB, tagihan, rapor) | NOTIF-03 kriteria 1 | batch trigger |
| Editor template notifikasi per sekolah | NOTIF-03 kriteria 2 | batch trigger |
| Retensi riwayat 90 hari | NOTIF-04 kriteria 3 | batch retensi |
| Pengiriman WhatsApp otomatis | Phase 2 — Fonnte/Meta Cloud API | di luar Phase 1 |

Retensi 90 hari tetap **tidak** dikerjakan dan tetap tidak dipalsukan: tidak ada
scheduler, tidak ada job pembersih, dan tidak ada penyaring tanggal yang
menyembunyikan notifikasi lama sehingga riwayatnya seakan-akan sudah dipangkas.

Tidak ada skema yang berubah pada batch ini: tidak ada migrasi dan tidak ada kolom
baru. Permukaan API bertambah tepat satu, dari 40 menjadi 41, dan tambahannya adalah
`wa-links` itu sendiri.

Dengan mendaratnya batch ini, **NOTIF-02 selesai seluruhnya** — ketiga kriterianya
ada, dengan catatan bahwa normalisasi nomornya aturan implementasi (butir 222) dan
penolakan draf penafsiran implementasi (butir 224). Kewenangannya sendiri bukan
tafsir: pemetaan peran User Flow menyebut NOTIF-02 milik Admin Sekolah, bukan
Kepala Sekolah (butir 223). NOTIF-01 dan NOTIF-04 poin 1–2
sudah selesai sejak batch sebelumnya. Yang tersisa di modul Notifikasi tinggal
NOTIF-03 seluruhnya dan NOTIF-04 poin 3.

## Sprint 8 Batch 8.5 — Trigger otomatis & template WhatsApp cabang (NOTIF-03)

### 234. Tiga trigger, dan tepat tiga

| | |
| --- | --- |
| **Status** | Ruang lingkup |
| **Referensi** | NOTIF-03 kriteria 1 |

NOTIF-03 menyebutkan daftarnya tertutup: "Trigger tersedia untuk: PPDB status
berubah, tagihan baru terbit, rapor diterbitkan." Titik integrasinya karena itu tepat
tiga, dan seluruhnya di dalam jalur bisnis yang sudah ada:

| Kejadian | Tempat | Pemicu tepatnya |
| --- | --- | --- |
| Status PPDB berubah | `PpdbStatusUpdater::update()` | `status` lama ≠ status baru |
| Tagihan baru terbit | `StudentFeeGenerator::issue()` | setiap `StudentFee` yang benar-benar baru dibuat |
| Rapor diterbitkan | `ReportCardGenerator::publish()` | peralihan `is_published` false → true |

Tidak ada yang keempat. Tidak ada pengingat jatuh tempo, tidak ada pengingat
pembayaran, tidak ada pengingat ketidakhadiran, dan tidak ada penjadwal yang
menerbitkan notifikasi atas dasar waktu. Ada test yang melewati tanggal jatuh tempo
enam puluh hari dan memastikan tidak ada satu baris pun bertambah — bukan karena
fiturnya dimatikan, melainkan karena memang tidak ada yang mendengarkan.

### 235. Notifikasi tanpa penulis manusia butuh jalur tulisnya sendiri

| | |
| --- | --- |
| **Status** | Keputusan arsitektur |
| **Referensi** | ERD 2.2 — `notifications.sender_id` "NULL jika notifikasi sistem otomatis" |

`AnnouncementPublisher` tidak dipakai untuk notifikasi otomatis, dan sebaliknya.
Keduanya menulis tabel yang sama tetapi menjawab pertanyaan yang berbeda: pengumuman
manual **selalu** punya pengirim dan wajib melewati pemeriksaan kewenangan
pembuatnya, sedangkan notifikasi otomatis tidak punya siapa pun untuk diperiksa.
Memaksa keduanya lewat satu kelas berarti salah satu pemeriksaan harus dilonggarkan —
dan yang akan dilonggarkan adalah pemeriksaan pada jalur yang dipakai manusia.

`SystemNotificationPublisher` karena itu berdiri sendiri, dengan aturan yang tetap:
`sender_id` NULL, `is_draft` false, `sent_at` waktu server, `target_type` INDIVIDUAL,
dan `target_id` sebuah `users.id` nyata.

`sender_id` NULL adalah penanda asal-sistem yang memang disediakan ERD. Tidak ada
akun palsu yang dibuat untuk menjadi "pengirim sistem": akun semacam itu akan muncul
di daftar pengguna, dapat dipakai login bila kelak diberi sandi, dan membuat jejak
audit menunjuk seseorang yang tidak ada.

### 236. Pemetaan kategori — penafsiran implementasi

| | |
| --- | --- |
| **Status** | Penafsiran implementasi |

Tidak ada satu pun sumber yang memetakan ketiga kejadian ini ke nilai
`notifications.type`. Yang dipakai:

| Kejadian | Kategori | Alasan |
| --- | --- | --- |
| Status PPDB berubah | `SYSTEM` | ERD menyediakan SYSTEM justru untuk notifikasi yang dipicu sistem, dan perubahan status PPDB bukan akademik maupun tagihan |
| Tagihan baru terbit | `BILLING` | ERD memang menyediakannya untuk tagihan |
| Rapor diterbitkan | `ACADEMIC` | rapor adalah hasil akademik |

Enum `NotificationType` tidak diubah — SYSTEM sudah ada sejak Batch 8.1 dan memang
disiapkan untuk batch ini. Ia tetap tidak dapat dipilih pada pengumuman manual
(`manualCases()`), sehingga pengumuman buatan manusia tidak dapat menyamar sebagai
notifikasi sistem (butir 191).

Ini penafsiran implementasi, bukan keputusan pemilik.

### 237. Sintaks template tidak baru, dan tidak boleh baru

| | |
| --- | --- |
| **Status** | Keputusan arsitektur |
| **Referensi** | PPDB-04 poin 1; `App\Support\PpdbWaTemplate` (Sprint 3) |

Sprint 3 sudah menetapkan bentuknya: token `[…]` di dalam teks biasa, diganti
`str_replace`. Batch ini memakainya apa adanya. Template PPDB tetap ditangani
`PpdbWaTemplate` dengan kosakatanya sendiri; tagihan dan rapor memakai
`StudentWaTemplate` dengan sintaks yang sama persis.

Yang **tidak** dilakukan, dan sengaja: tidak ada Blade yang dirender, tidak ada
`eval`, tidak ada mesin template, tidak ada HTML, dan tidak ada kode pengguna yang
dijalankan. Template adalah teks yang ditulis Admin Sekolah, dan teks yang ditulis
pengguna tidak pernah menjadi kode — apalagi teks yang tujuan akhirnya dikirim ke
orang tua.

### 238. Kosakata token untuk tagihan dan rapor sengaja sempit

| | |
| --- | --- |
| **Status** | Penafsiran implementasi |

Blueprint menyebut ketiga kolom template ada dan dapat diedit, tetapi tidak pernah
mendefinisikan daftar token untuk SPP maupun rapor. Yang disediakan karena itu hanya
tiga, dan ketiganya **sudah** punya makna yang ditetapkan PPDB:

- `[nama]` — nama siswa (di PPDB: nama calon siswa);
- `[sekolah]` — nama cabang;
- `[ortu]` — nama orang tua/wali.

Token untuk nominal, jatuh tempo, periode, atau semester **tidak** disediakan.
Mengarangnya berarti membuat kosakata template baru hanya demi kenyamanan, dan
kosakata semacam itu sulit dicabut setelah sekolah menulis templatenya.

Ada alasan kedua yang kebetulan searah. Teks WA dikirim **manual** ke nomor yang bisa
saja salah — Batch 8.4 sudah menunjukkan nomor tersimpan tidak selalu benar — dan
nominal tagihan bukan hal yang sebaiknya ikut terkirim ke nomor yang keliru. Rincian
angkanya ada di pesan in-app dan di portal orang tua, yang keduanya hanya terbaca
oleh akun yang memang berhak.

Konsekuensinya perlu disebut jujur: sekolah yang menulis templatenya sendiri tidak
dapat mencantumkan nominal di pesan WhatsApp-nya. Itu batas yang berasal dari
sumbernya, bukan pilihan gaya, dan menutupnya menuntut kosakata token yang
didefinisikan pemilik.

### 239. Template kosong bukan kegagalan

| | |
| --- | --- |
| **Status** | Copy implementasi |

Ketiga kolom template nullable dan memang boleh kosong — pemasangan baru tidak punya
satu pun template. Kejadian bisnisnya tidak boleh gagal karenanya: tagihan tetap
terbit dan rapor tetap diterbitkan meskipun sekolah belum pernah menulis apa pun.

Bila kosong, dipakai teks bawaan. Untuk PPDB teks itu sudah ada sejak Sprint 3
(`PpdbStatus::waTemplate()`, satu per status). Untuk tagihan dan rapor, teksnya
ditulis di batch ini dan hanya memakai tiga token yang sudah mapan.

Teks bawaan itu **copy implementasi, bukan keputusan pemilik**. Blueprint menyebut
kolomnya ada; bunyinya tidak pernah dituliskan siapa pun. Judul notifikasinya —
"Status PPDB diperbarui", "Tagihan baru terbit", "Rapor diterbitkan" — juga copy
implementasi dengan alasan yang sama. Judul dan isi tidak pernah kosong.

### 240. Sebagian penerima otomatis tidak dapat diwakili skema ini

| | |
| --- | --- |
| **Status** | **Celah struktural yang dinyatakan terbuka** |
| **Referensi** | ERD 2.2 — `notifications.target_id`; ERD PPDB — `ppdb_registrations`; ERD Akademik — `students.parent_user_id` |

Ini temuan terpenting batch ini, dan ia tidak ditutup.

`notifications.target_id` pada target INDIVIDUAL berarti `users.id`. Itu satu-satunya
artinya, dan `NotificationRecipientResolver` membacanya begitu. Sementara itu penerima
ketiga kejadian otomatis adalah orang tua — dan orang tua tidak selalu punya akun:

- **PPDB.** `ppdb_registrations` tidak menyimpan **satu pun** rujukan ke `users`.
  Kolomnya `parent_name`, `parent_phone`, `parent_email` — nama, nomor, surel, bukan
  akun. Calon siswa yang belum di-enroll karena itu tidak punya `users.id` sama
  sekali. Alur enroll (PPDB-05) pun tidak membuat akun orang tua maupun mengisi
  `students.parent_user_id`.
- **Tagihan dan rapor.** `students.parent_user_id` nullable. Siswa yang orang tuanya
  belum dibuatkan akun portal tidak punya penerima yang dapat ditulis.

Yang **tidak** dilakukan, dan masing-masing punya akibatnya sendiri:

| Jalan pintas | Akibatnya |
| --- | --- |
| Menaruh `ppdb_registrations.id` di `target_id` | resolver membacanya sebagai `users.id`; notifikasi itu akan muncul di kotak masuk pengguna yang kebetulan ber-id sama |
| Menaruh `students.id` di `target_id` | sama persis, dan lebih mungkin terjadi karena id siswa dan id pengguna sama-sama kecil |
| Memakai target ALL | seluruh cabang membaca tagihan atau rapor satu anak |
| Memakai akun siswa sebagai pengganti orang tua | tagihan dikirim kepada anaknya |
| Membuat akun pengguna palsu | akun yang tidak ada muncul di daftar pengguna dan dapat menjadi target notifikasi lain |

Yang dilakukan: bila tidak ada penerima yang sah, **tidak ada baris notifikasi yang
ditulis sama sekali**. `SystemNotificationPublisher::toUser()` mengembalikan NULL, dan
kejadian bisnisnya berjalan seperti biasa. Kanal manualnya tetap ada dan tetap
bekerja — untuk PPDB, tautan wa.me per pendaftar dari Sprint 3, yang membaca
`parent_phone` langsung dan tidak membutuhkan akun apa pun.

Satu tautan pengguna yang **memang** ada di repositori tetap dipakai: pendaftaran yang
sudah menjadi siswa (`converted_student_id`) dan siswa itu punya `parent_user_id`.
Dalam keadaan itu penerimanya nyata dan notifikasinya dibuat seperti biasa. Ada test
untuk keduanya — yang punya tautan dan yang tidak.

Menutup celah ini menuntut perubahan skema — kolom penerima eksternal, atau akun
portal yang dibuat lebih awal pada alur PPDB — dan keduanya keputusan pemilik, bukan
keputusan implementasi. Batch ini **tidak** menambahkannya.

### 241. Notifikasi otomatis adalah potret, bukan tautan hidup

| | |
| --- | --- |
| **Status** | Keputusan arsitektur |

Pada saat kejadian, template cabang dibaca, placeholder-nya diisi, dan hasil akhirnya
disimpan ke `notifications.wa_template`. Sesudah itu ia tidak pernah dihitung ulang.

Kalau teksnya dirender ulang setiap kali daftar wa.me diminta, mengubah template hari
ini akan mengubah bunyi notifikasi tahun lalu — dan riwayatnya berhenti menggambarkan
apa yang benar-benar dikirim. Itu keberatan yang sama dengan butir 195 tentang
pengumuman terkirim yang tidak dapat diubah.

Batch 8.4 sudah memakai `wa_template` sebagai sumber teks tautan bila terisi, jadi
tautan wa.me notifikasi otomatis memakai potret itu tanpa perubahan apa pun pada
`NotificationWaLinkService`. Ada test untuk ketiga jalur: menyimpan template baru
setelah notifikasi terbit tidak menggeser satu huruf pun.

### 242. Idempotensi tagihan menumpang pada idempotensi yang sudah ada

| | |
| --- | --- |
| **Status** | Keputusan arsitektur |
| **Referensi** | butir 52 — lock dan pelewatan kombinasi yang sudah ada |

Tidak ada kolom penanda kejadian yang tersedia, dan tidak ada yang ditambahkan.
Idempotensinya seluruhnya berasal dari satu keputusan: **notifikasi dibuat di dalam
transaksi yang sama, tepat setelah `StudentFee` yang benar-benar baru dibuat.**

Konsekuensinya mengikuti sendirinya. Kombinasi `school_id + student_id + fee_type_id
+ period` yang sudah ada tidak pernah sampai ke baris pembuatan, sehingga job yang
diulang — karena retry, klik ganda, maupun permintaan generate kedua — tidak
menghasilkan notifikasi kedua. Pratinjau tidak menulis apa-apa, jadi tidak
memberitahu siapa pun. Transaksi yang gagal membatalkan keduanya sekaligus, sehingga
tidak ada notifikasi yatim yang menyebut tagihan yang tidak pernah ada.

Ada test untuk keempatnya: penerbitan pertama, penerbitan ulang, tagihan yang sudah
ada sebelumnya, dan transaksi yang dibatalkan.

### 243. Cabang diperiksa di tempat seluruh jalur otomatis bertemu

`SystemNotificationPublisher` menolak penerima yang `school_id`-nya tidak sama dengan
cabang kejadiannya, dan menolak akun nonaktif. Pemeriksaan itu ada di publisher — bukan
dipercayakan kepada tiga pemanggil — karena inilah satu-satunya tempat ketiganya
bertemu.

`students.parent_user_id` yang menunjuk akun cabang lain adalah data rusak. Yang benar
adalah memperlakukannya sebagai tidak ada: notifikasi tidak boleh menjadi cara data
rusak itu terbaca orang di cabang seberang. `StudentFeeGenerator` juga menyaring
cabang saat memuat akun orang tua, jadi pagarnya dua lapis. Ada test yang sengaja
membuat data rusak itu dan memastikan tidak ada baris yang ditulis.

Siapa yang menjalankan aksinya tidak mengubah apa pun. Super Admin yang menerbitkan
rapor cabang A tetap menghasilkan notifikasi milik cabang A.

### 244. Batas transaksi milik kejadian bisnisnya

| | |
| --- | --- |
| **Status** | Keputusan arsitektur |

`SystemNotificationPublisher` tidak membuka transaksinya sendiri. Notifikasi harus
hidup dan mati bersama tagihan atau rapor yang memicunya, bukan bersama dirinya
sendiri.

- **Tagihan** — sudah berada di dalam `DB::transaction` milik `issue()`; notifikasinya
  ikut di sana.
- **Rapor** — `publish()` sebelumnya menulis tanpa transaksi. Kini peralihan keadaan,
  penguncian konfigurasi, dan notifikasinya satu transaksi. Rapor yang terbit tanpa
  notifikasinya masih dapat diperbaiki; notifikasi yang terbit tanpa rapornya memberi
  tahu orang tua sesuatu yang tidak ada.
- **PPDB** — `PpdbStatusUpdater::update()` membungkus perubahan status dan
  notifikasinya.

Kejadian bisnisnya tetap yang berwenang: bila penerimanya tidak ada, tagihan tetap
terbit dan rapor tetap diterbitkan. Yang tidak terjadi hanyalah notifikasinya.

### 245. Biaya baca tidak tumbuh bersama jumlah siswa

Penerbitan massal menulis satu tagihan dan satu notifikasi per siswa — itu memang
jumlah kejadiannya, dan tidak dapat lebih sedikit. Yang tidak boleh tumbuh adalah
**pembacaannya**.

Cabang beserta templatenya dibaca **sekali** untuk seluruh angkatan penerbitan, dan
akun orang tua seluruh siswa sasaran dimuat dalam **satu** query lalu dipetakan di
memori. Tidak ada satu query pun yang dijalankan per siswa. Ada test yang
membandingkan jumlah SELECT untuk lima siswa dengan tiga puluh siswa dan menuntut
angka yang sama persis, ditambah satu yang menuntut tabel `schools` dibaca paling
banyak sekali.

Satu orang tua dengan dua anak yang sama-sama ditagih menerima **dua** notifikasi.
Itu dua kejadian bisnis, bukan satu yang tergandakan, dan menggabungkannya akan
menghilangkan informasi tentang anak mana yang ditagih. Tidak ada sumber yang meminta
penggabungan.

### 246. Rapor: hanya peralihan, dan hanya sekali

Pemicunya peralihan `is_published` false → true, bukan permintaan penerbitan.
Perbedaannya penting karena `publish()` menolak rapor yang sudah terbit dengan
`ValidationException` **sebelum** menyentuh apa pun — sehingga permintaan kedua tidak
pernah sampai ke baris notifikasi. Pagar keadaan yang sudah ada itulah pagar
kejadiannya; tidak ada penanda baru yang ditambahkan, dan aturan penguncian rapor
tidak dilonggarkan sedikit pun.

Generate draf, membuka rapor, dan mengunduh PDF tidak memicu apa pun — ketiganya tidak
melewati `publish()`. `publishClass()` menerbitkan satu per satu lewat `publish()`
yang sama, jadi satu notifikasi per rapor yang benar-benar terbit.

### 247. Pembaruan status PPDB dipindahkan ke service

Sampai batch ini status PPDB ditulis langsung di dalam aksi Filament
(`$record->update([...])`). Ia dipindahkan ke `App\Services\Ppdb\PpdbStatusUpdater`
karena kini ada dua tulisan yang harus hidup atau mati bersama, dan karena
"benar-benar berubah" menjadi pertanyaan yang perlu dijawab di satu tempat.

Polanya mengikuti yang sudah ada di project ini — `StudentFeeGenerator`,
`ReportCardGenerator`, `AnnouncementPublisher` — bukan pola baru. Aksi Filament tetap
menjadi satu-satunya jalur masuknya; tidak ada endpoint API PPDB yang ditambahkan,
dan API 4.7 memang tidak menyebutnya untuk batch ini.

### 248. Menyimpan catatan pada status yang sama bukan perubahan status

NOTIF-03 menyebut pemicunya "PPDB status **berubah**". Menyimpan ulang status yang
sama dengan catatan alasan baru tetap tersimpan — itu perilaku yang sudah ada dan
tidak diubah — tetapi tidak memicu notifikasi apa pun. Mengirim pesan kepada orang tua
setiap kali Admin memperbaiki kalimat catatannya adalah gangguan, bukan pemberitahuan.

`update()` mengembalikan `true` hanya bila statusnya benar-benar berpindah. Setiap
perpindahan yang nyata punya notifikasinya sendiri, jadi
REGISTERED → DOCUMENT_REVIEW → PASSED menghasilkan dua.

### 249. Editor template: Admin Sekolah, dan hanya Admin Sekolah

| | |
| --- | --- |
| **Status** | Keputusan kewenangan |
| **Referensi** | NOTIF-03 kriteria 2 |

NOTIF-03 poin 2 berbunyi "Template teks notifikasi dapat diedit oleh **Admin
Sekolah**". Perannya diperiksa langsung, bukan lewat izin, dan alasannya sama persis
dengan butir 223:

- `notification.manage` juga dipegang Kepala Sekolah, sehingga memakainya akan memberi
  Kepala kewenangan yang tidak disebut sumber mana pun.
- `white_label.manage` kebetulan dipegang persis SUPER_ADMIN dan SCHOOL_ADMIN, tetapi
  template WhatsApp bukan pengaturan white-label. Meminjam izinnya berarti dua hal
  berbeda menjadi tidak dapat dipisahkan bila kelak salah satunya berubah.

Kewenangannya: SUPER_ADMIN dan SCHOOL_ADMIN (cabangnya sendiri). Kepala Sekolah,
Bendahara, Guru, dan Wali Kelas ditolak; Orang Tua dan Siswa tidak dapat membuka panel
sama sekali. Setiap peran disebut namanya di test, karena kesalahan yang sama sudah
terjadi sekali pada NOTIF-02.

Halamannya `Pengaturan Notifikasi → Template WhatsApp`, mengikuti pola
`PengaturanTampilan` dan `PengaturanPenilaian`: Admin Sekolah tidak boleh membuka
`SchoolResource` — itu "Manajemen Tenant", baris matriks yang berbeda dan hanya untuk
Super Admin — sehingga pengaturan cabangnya sendiri diberikan lewat halaman
tersendiri, dan Super Admin yang tidak terikat cabang memilih cabangnya di form.
Nilai cabang dari form hanya dipercaya dari Super Admin, dan policy diperiksa lagi
sebelum menyimpan.

Halamannya sengaja **terpisah** dari Pengaturan Tampilan. Menyatukannya berarti satu
kewenangan tidak lagi dapat berubah tanpa ikut mengubah yang lain. `SchoolResource`
tetap memuat ketiga kolom yang sama untuk Super Admin, tidak disentuh batch ini.

Hanya tiga kolom itu yang ditulis; pengaturan cabang lainnya tidak ikut tertulis
ulang. Kosong disimpan sebagai NULL, bukan string kosong, supaya "belum diisi" hanya
punya satu bentuk.

### 250. Jejak audit memakai yang sudah ada

Penyuntingan template adalah `update` pada `schools`, dan listener CUD wildcard yang
sudah ada (butir 45) menangkapnya seperti update apa pun. Tidak ada pencatatan baru
yang ditambahkan, sehingga tidak ada baris ganda.

Pembuatan notifikasi otomatis juga tercatat lewat listener yang sama, dengan
`user_id` apa adanya — kosong bila berjalan di dalam worker antrean. Itu memang
gambaran yang benar: tidak ada pengguna yang menekan tombol. Tidak ada aktor palsu
yang dikarang untuk mengisi kolom itu.

### 251. Yang tidak dikerjakan di batch ini

| Belum ada | Sumber | Rencana |
| --- | --- | --- |
| Retensi riwayat 90 hari | NOTIF-04 kriteria 3 | Batch 8.6 |
| Pengiriman WhatsApp otomatis | Phase 2 — Fonnte/Meta Cloud API | di luar Phase 1 |
| Notifikasi in-app bagi penerima tanpa akun | celah struktural butir 240 | menunggu keputusan pemilik |

Retensi 90 hari tetap **tidak** dikerjakan dan tetap tidak dipalsukan: tidak ada
scheduler, tidak ada job pembersih, tidak ada perintah artisan, dan tidak ada
penyaring tanggal yang menyembunyikan notifikasi lama sehingga riwayatnya
seakan-akan sudah dipangkas.

Tidak ada skema yang berubah: tidak ada migrasi, tidak ada kolom, dan tidak ada
endpoint API baru — permukaannya tetap 41.

**Status NOTIF-03.** Kriteria 1 dan 2 terpenuhi untuk seluruh penerima yang **dapat
diwakili** skema saat ini, dan kriteria 3 ("notifikasi muncul di in-app notification
center dan wa.me link tersedia") berlaku penuh untuk mereka. Untuk penerima tanpa akun
portal — yang pada PPDB adalah keadaan **normal**, bukan pengecualian — sisi in-app-nya
tidak ada, dan yang tersedia hanya kanal wa.me manual. NOTIF-03 karena itu belum dapat
disebut selesai seluruhnya, dan butir 240 menyebutkan apa yang dibutuhkan untuk
menutupnya.

## Menjalankan test terhadap MySQL

`phpunit.xml` memakai SQLite in-memory. Untuk memverifikasi perilaku yang bergantung
pada MySQL (panjang kolom, unique index, tipe ENUM):

```sh
export DB_CONNECTION=mysql DB_DATABASE=smartsukses_test
php artisan test
```

Ini bukan formalitas. Cara ini menemukan dua kegagalan yang tidak muncul di SQLite
karena SQLite mengabaikan panjang `VARCHAR`:

- Sprint 2 — `SchoolFactory` menghasilkan `slug`/`code` melebihi batas kolom ERD.
- Sprint 3 — kasus uji panjang `reg_number` memakai kode cabang 23 karakter, di atas
  batas `schools.code` VARCHAR(20); kasusnya diperbaiki menjadi kode 20 karakter
  (batas sah terpanjang) sehingga benar-benar menguji pemotongan di butir 13.

Langkah 1 — Attitude Scale Validation

A > B > C > D wajib.
Nilai harus 0–100.
Skala tidak valid → ditolak.
Skala rusak/incomplete yang sudah ada → fallback ke default.

Langkah 2 — C-6

Komponen akademik SUMMATIVE yang ada nilainya tetapi tidak ada di Grade Config dilaporkan.
Tidak mengubah perhitungan nilai akhir.
Formative dan Attitude tidak dianggap sebagai ignored component.

Langkah 3 — LOCKED Config

Jika tidak ada ACTIVE tetapi ada LOCKED, guru mendapat warning.
Tidak otomatis unlock.
Tidak otomatis membuat versi baru.
Nilai tetap tersimpan dengan snapshot kosong sesuai behavior existing.

Langkah 4 — PDF Queue

Download satu rapor tetap synchronous.
Generate PDF satu kelas → queue/background job.
QUEUED → READY/FAILED.
PDF disimpan di private/local storage.
pdf_path, pdf_status, pdf_generated_at.
Worker wajib aktif di deployment.