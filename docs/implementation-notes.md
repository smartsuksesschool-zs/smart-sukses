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
  cara menjalankannya di bagian Sprint 2 di bawah.

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

### Menjalankan test terhadap MySQL

`phpunit.xml` memakai SQLite in-memory. Untuk memverifikasi perilaku yang bergantung
pada MySQL (panjang kolom, unique index, tipe ENUM):

```sh
export DB_CONNECTION=mysql DB_DATABASE=smartsukses_test
php artisan test
```

Ini bukan formalitas: menjalankan cara ini menemukan `SchoolFactory` menghasilkan
`slug`/`code` melebihi batas kolom ERD — kegagalan yang tidak muncul di SQLite karena
SQLite mengabaikan panjang `VARCHAR`.
