> Sumber: SmartSukses_FullBlueprint_v1_0_0.docx (v1.0.0, Agustus 2025) — KONFIDENSIAL, internal only.

# 2.2 Definisi Tabel — Kelompok Akademik

Tabel: `students`, `academic_years`, `classes`, `student_classes`, `subjects`, `class_subjects`, `schedules`.

### Tabel: students

Data induk siswa. Seorang siswa dapat memiliki akun user (kolom user_id untuk akses portal) namun tidak wajib.

| **Field** | **Tipe** | **Null** | **Key** | **Keterangan** |
| --- | --- | --- | --- | --- |
| id | BIGINT UNSIGNED | NO | PK |  |
| school_id | BIGINT UNSIGNED | NO | FK | FK → schools.id. Tenant isolation key |
| user_id | BIGINT UNSIGNED | YES | FK | FK → users.id. NULL jika siswa belum punya akun portal |
| nis | VARCHAR(20) | NO | IX | Nomor Induk Siswa (lokal, unik per sekolah) |
| nisn | VARCHAR(10) | YES | IX | Nomor Induk Siswa Nasional (10 digit, nullable) |
| full_name | VARCHAR(150) | NO |  | Nama lengkap siswa |
| gender | ENUM(L,P) | NO |  | Jenis kelamin: L (Laki) atau P (Perempuan) |
| birth_place | VARCHAR(100) | YES |  | Kota lahir |
| birth_date | DATE | YES |  | Tanggal lahir |
| religion | VARCHAR(30) | YES |  | Agama |
| address | TEXT | YES |  | Alamat domisili |
| photo_url | VARCHAR(500) | YES |  | Path foto siswa (resize 400×400) |
| parent_name | VARCHAR(150) | YES |  | Nama orang tua / wali |
| parent_phone | VARCHAR(20) | YES | IX | Nomor HP ortu (untuk wa.me link) |
| parent_email | VARCHAR(150) | YES |  | Email ortu (untuk notifikasi) |
| parent_user_id | BIGINT UNSIGNED | YES | FK | FK → users.id. Akun portal ortu |
| entry_year | YEAR | YES |  | Tahun masuk sekolah |
| status | ENUM(ACTIVE,GRADUATED,DROPPED_OUT,TRANSFERRED) | NO | IX | Status siswa. Default: ACTIVE |
| notes | TEXT | YES |  | Catatan tambahan dari admin |
| created_at | TIMESTAMP | YES |  |  |
| updated_at | TIMESTAMP | YES |  |  |

### Tabel: academic_years

Tahun ajaran dan semester aktif per cabang.

| **Field** | **Tipe** | **Null** | **Key** | **Keterangan** |
| --- | --- | --- | --- | --- |
| id | BIGINT UNSIGNED | NO | PK |  |
| school_id | BIGINT UNSIGNED | NO | FK | FK → schools.id |
| name | VARCHAR(20) | NO |  | Contoh: 2024/2025 Semester 1 |
| start_date | DATE | NO |  | Tanggal mulai tahun ajaran |
| end_date | DATE | NO |  | Tanggal berakhir tahun ajaran |
| semester | TINYINT | NO |  | 1 atau 2 |
| is_active | TINYINT(1) | NO | IX | Hanya satu tahun ajaran per sekolah boleh aktif. Default: 0 |
| created_at | TIMESTAMP | YES |  |  |
| updated_at | TIMESTAMP | YES |  |  |

### Tabel: classes (rombel)

Rombongan belajar per tahun ajaran per cabang.

| **Field** | **Tipe** | **Null** | **Key** | **Keterangan** |
| --- | --- | --- | --- | --- |
| id | BIGINT UNSIGNED | NO | PK |  |
| school_id | BIGINT UNSIGNED | NO | FK | FK → schools.id |
| academic_year_id | BIGINT UNSIGNED | NO | FK | FK → academic_years.id |
| name | VARCHAR(50) | NO |  | Nama kelas: misal X-A, XI-IPA-1 |
| grade_level | TINYINT | NO |  | Tingkat: 10, 11, atau 12 |
| homeroom_teacher_id | BIGINT UNSIGNED | YES | FK | FK → users.id (guru dengan role WALI_KELAS) |
| room | VARCHAR(50) | YES |  | Kode atau nama ruang kelas |
| capacity | SMALLINT | NO |  | Kapasitas maksimum siswa. Default: 35 |
| created_at | TIMESTAMP | YES |  |  |
| updated_at | TIMESTAMP | YES |  |  |

### Tabel: student_classes

Tabel pivot yang merecord siswa mana masuk ke kelas mana pada tahun ajaran tertentu.

| **Field** | **Tipe** | **Null** | **Key** | **Keterangan** |
| --- | --- | --- | --- | --- |
| id | BIGINT UNSIGNED | NO | PK |  |
| school_id | BIGINT UNSIGNED | NO | FK | FK → schools.id |
| student_id | BIGINT UNSIGNED | NO | FK | FK → students.id |
| class_id | BIGINT UNSIGNED | NO | FK | FK → classes.id |
| academic_year_id | BIGINT UNSIGNED | NO | FK | FK → academic_years.id |
| status | ENUM(ACTIVE,MOVED) | NO |  | Status siswa di kelas ini. Default: ACTIVE |
| created_at | TIMESTAMP | YES |  |  |

### Tabel: subjects

Daftar mata pelajaran per cabang. Dapat dikustomisasi sesuai kurikulum masing-masing cabang.

| **Field** | **Tipe** | **Null** | **Key** | **Keterangan** |
| --- | --- | --- | --- | --- |
| id | BIGINT UNSIGNED | NO | PK |  |
| school_id | BIGINT UNSIGNED | NO | FK | FK → schools.id |
| name | VARCHAR(100) | NO |  | Nama mata pelajaran: misal Matematika, Fikih, Bahasa Arab |
| code | VARCHAR(20) | NO |  | Kode mapel: MTK, FIK, ARB |
| credit_hours | TINYINT | YES |  | Jam pelajaran per minggu |
| description | TEXT | YES |  | Deskripsi singkat mata pelajaran |
| is_active | TINYINT(1) | NO |  | Default: 1 |
| created_at | TIMESTAMP | YES |  |  |

### Tabel: class_subjects

Mata pelajaran yang diajarkan di kelas tertentu oleh guru tertentu. Dasar untuk input nilai dan jadwal.

| **Field** | **Tipe** | **Null** | **Key** | **Keterangan** |
| --- | --- | --- | --- | --- |
| id | BIGINT UNSIGNED | NO | PK |  |
| school_id | BIGINT UNSIGNED | NO | FK | FK → schools.id |
| class_id | BIGINT UNSIGNED | NO | FK | FK → classes.id |
| subject_id | BIGINT UNSIGNED | NO | FK | FK → subjects.id |
| teacher_id | BIGINT UNSIGNED | NO | FK | FK → users.id (guru yang mengajar) |
| academic_year_id | BIGINT UNSIGNED | NO | FK | FK → academic_years.id |
| created_at | TIMESTAMP | YES |  |  |

### Tabel: schedules

Jadwal pelajaran mingguan per class_subject.

| **Field** | **Tipe** | **Null** | **Key** | **Keterangan** |
| --- | --- | --- | --- | --- |
| id | BIGINT UNSIGNED | NO | PK |  |
| school_id | BIGINT UNSIGNED | NO | FK |  |
| class_subject_id | BIGINT UNSIGNED | NO | FK | FK → class_subjects.id |
| day_of_week | TINYINT | NO | IX | 1=Senin, 2=Selasa, …, 7=Minggu |
| start_time | TIME | NO |  | Jam mulai: contoh 07:00:00 |
| end_time | TIME | NO |  | Jam selesai: contoh 08:30:00 |
| room | VARCHAR(50) | YES |  | Ruang kelas yang digunakan |
| created_at | TIMESTAMP | YES |  |  |
