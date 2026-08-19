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