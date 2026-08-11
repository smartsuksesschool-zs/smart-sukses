# Catatan Implementasi

Catatan penyimpangan implementasi terhadap `smartsukses-docs/`. Folder
`smartsukses-docs/` adalah satu-satunya sumber requirement dan **tidak diubah**;
dokumen ini hanya mencatat titik-titik di mana kode sengaja berbeda dari ERD,
beserta alasan dan referensi dokumennya.

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
