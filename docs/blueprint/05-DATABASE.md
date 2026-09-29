# Smart Sukses School — Database Design Specification

---

| Parameter | Detail |
|---|---|
| **Nama Produk** | Smart Sukses School |
| **Platform** | `apps.smartsukses.sch.id` |
| **Versi Dokumen** | v1.0 |
| **Sumber Utama** | `blueprint/SmartSukses_FullBlueprint_v1.0.0.docx` Bagian 2 & 3 · `docs/01-PRD.md` v1.1 · `docs/02-ROADMAP.md` v1.1 · `docs/03-USER_FLOW.md` v1.0 · `docs/04-ERD.md` v1.0 |
| **DBMS** | MySQL 8.0+ |
| **Jumlah Tabel** | **21 tabel utama** (Blueprint §2.1) |
| **Pola Multi-Tenant** | Shared Database, Shared Schema — isolasi via `school_id` |
| **Sifat Dokumen** | Turunan blueprint dan ERD. Tidak menambah tabel, kolom, maupun requirement. |
| **Kerahasiaan** | KONFIDENSIAL — hanya untuk penggunaan internal |

> **Konvensi dokumen:** Setiap informasi yang tidak tercantum dalam blueprint ditulis sebagai **"Belum dijelaskan dalam blueprint."**

> **Batasan dokumen:** Dokumen ini **tidak memuat SQL, migration Laravel, maupun model Eloquent** — hanya spesifikasi desain.

---

## Daftar Isi

| No | Section |
|---|---|
| 1 | [Overview](#1-overview) |
| 2 | [Database Configuration](#2-database-configuration) |
| 3 | [Naming Convention](#3-naming-convention) |
| 4 | [Data Type Standards](#4-data-type-standards) |
| 5 | [Table Specification](#5-table-specification) |
| 6 | [Index Strategy](#6-index-strategy) |
| 7 | [Multi Tenant Strategy](#7-multi-tenant-strategy) |
| 8 | [Audit Strategy](#8-audit-strategy) |
| 9 | [Data Integrity Rules](#9-data-integrity-rules) |
| 10 | [Backup Strategy](#10-backup-strategy) |
| 11 | [Future Database Expansion](#11-future-database-expansion) |
| 12 | [Lampiran](#12-lampiran) |

---

## 1. Overview

### 1.1 Tujuan Dokumen

Dokumen ini menyusun **Database Design Specification (DDS)** sebagai acuan implementasi database MySQL dan penulisan migration Laravel.

| ID | Tujuan | Penjelasan |
|---|---|---|
| **TD-01** | Menetapkan **spesifikasi kolom lengkap** untuk seluruh 21 tabel | Tipe, nullability, default, key, dan keterangan |
| **TD-02** | Menetapkan **konvensi penamaan** yang konsisten | Diturunkan dari nama tabel dan kolom yang dipakai blueprint |
| **TD-03** | Menetapkan **standar tipe data** | Hanya tipe yang benar-benar dipakai blueprint |
| **TD-04** | Mendokumentasikan **aturan validasi dan aturan bisnis** per tabel | Bersumber dari acceptance criteria pada PRD §9 |
| **TD-05** | Menetapkan **strategi indeks, isolasi tenant, audit, dan integritas data** | Blueprint §2.1, §3.2, §3.4 |
| **TD-06** | Menandai secara eksplisit bagian yang **belum dijelaskan blueprint** agar tidak diisi asumsi developer | Konvensi dokumen |

### 1.2 Dokumen Ini Merupakan Turunan dari ERD

Hubungan antar dokumen:

```
blueprint/SmartSukses_FullBlueprint_v1.0.0.docx   ← sumber kebenaran tunggal
        │
        ├─▶ docs/01-PRD.md          → requirement (38 FR · 12 NFR · 56 CON)
        │
        ├─▶ docs/02-ROADMAP.md      → rencana pengerjaan (11 fase · 9 sprint)
        │
        ├─▶ docs/03-USER_FLOW.md    → alur pengguna (8 role)
        │
        ├─▶ docs/04-ERD.md          → model data (21 entitas · 56 relasi)
        │         │
        │         └─▶ docs/05-DATABASE.md   ← DOKUMEN INI
        │                 spesifikasi implementasi database
```

| Aspek | ERD (`04-ERD.md`) | DDS (dokumen ini) |
|---|---|---|
| Fokus | **Apa** entitasnya dan **bagaimana** relasinya | **Bagaimana** entitas itu diimplementasikan di MySQL |
| Tingkat | Konseptual & logis | Fisik (spesifikasi) |
| Isi khas | Diagram, kardinalitas, normalisasi | Tipe kolom, default, indeks, constraint, aturan validasi |
| Yang dijawab | "Siapa berelasi dengan siapa" | "Kolom apa, tipe apa, nullable atau tidak, indeks apa" |

### 1.3 Ketentuan yang Berlaku

| Ketentuan | Keterangan |
|---|---|
| **Tidak ada tabel baru** | Hanya 21 tabel yang didaftarkan blueprint §2.1 |
| **Tidak ada kolom baru** | Definisi kolom mengikuti blueprint §2.2 apa adanya |
| **Tidak ada perubahan tipe data** | Tipe, nullability, dan key mengikuti blueprint |
| **Aturan validasi bersumber dari AC** | Diambil dari acceptance criteria pada PRD §9, bukan diciptakan |
| **Kekosongan ditandai eksplisit** | Ditulis "Belum dijelaskan dalam blueprint." |

---

## 2. Database Configuration

### 2.1 Database Engine

| Aspek | Nilai | Dasar |
|---|---|---|
| DBMS | **MySQL 8.0+** | Blueprint §3.1 Stack Teknologi Detail |
| Peran | Relasional database utama (shared schema multi-tenant) | Blueprint §3.1 |
| Lisensi | Open-source (GPL) | Blueprint §Stack Teknologi |
| Lokasi | VPS `localhost` — **tidak diekspos ke publik** | Blueprint §3.3.1; CON-22 |
| Port | 3306 (akses localhost saja) | Blueprint §3.3.1 |
| Akses aplikasi | Melalui Laravel Eloquent ORM | Blueprint §3.1 |

### 2.2 Character Set & Collation

| Aspek | Status |
|---|---|
| Character set | **Belum dijelaskan dalam blueprint.** |
| Collation | **Belum dijelaskan dalam blueprint.** |

**Yang dapat dipastikan dari blueprint:**

| Fakta | Implikasi |
|---|---|
| Antarmuka bilingual Bahasa Indonesia + English (NFR-11) | Character set harus mendukung kedua bahasa |
| Data mencakup nama mata pelajaran seperti "Fikih" dan "Bahasa Arab" (contoh pada tabel `subjects`) | Perlu dukungan karakter di luar ASCII dasar |
| Kolom `notifications.message` dan `wa_template` memuat teks pesan WhatsApp | Berpotensi memuat emoji |

> Blueprint tidak menetapkan character set maupun collation. Keputusan ini perlu diambil pemilik blueprint sebelum migration ditulis, dan bila ditetapkan, wajib diterbitkan sebagai revisi blueprint (CON-55).

### 2.3 Timezone

| Aspek | Status |
|---|---|
| Konfigurasi timezone database | **Belum dijelaskan dalam blueprint.** |

**Yang dapat dipastikan dari blueprint:**

| Fakta | Sumber |
|---|---|
| Format timestamp API: ISO 8601 dengan offset **`+07:00`** — contoh `2025-08-06T10:30:00+07:00` | Blueprint §4.1 Konvensi API |
| Backup dijadwalkan pukul **02:00 WIB** | Blueprint §3.4 |

Kedua fakta di atas menunjuk pada zona waktu **WIB (UTC+07:00)**. Namun blueprint tidak menyatakan apakah nilai `TIMESTAMP` disimpan dalam UTC lalu dikonversi, atau disimpan langsung dalam waktu lokal. → **Belum dijelaskan dalam blueprint.**

### 2.4 Storage Engine

| Aspek | Status |
|---|---|
| Storage engine | **Belum dijelaskan dalam blueprint.** |

**Yang dapat dipastikan dari blueprint:**

| Fakta | Implikasi |
|---|---|
| Terdapat **56 relasi foreign key** antar tabel (ERD §4.10) | Memerlukan storage engine yang mendukung foreign key constraint |
| Integritas referensial merupakan prinsip desain | ERD §2.2 |
| Transaksi keuangan (`payments`, `transactions`, `student_fees`) memerlukan konsistensi | Memerlukan dukungan transaksi |

> Blueprint tidak menyebut storage engine secara eksplisit. Kebutuhan foreign key dan transaksi di atas adalah fakta blueprint; pemilihan engine tetap keputusan yang perlu ditetapkan.

### 2.5 Multi Tenant Strategy (Ringkas)

| Aspek | Nilai | Dasar |
|---|---|---|
| Pola | **Shared Database, Shared Schema** | Blueprint §2.1; §3.2.1 |
| Jumlah database | **Satu** untuk seluruh tenant | Blueprint §2.1 |
| Jumlah set tabel | **Satu** set 21 tabel untuk seluruh tenant | Blueprint §3.2.1 |
| Kunci isolasi | Kolom `school_id` | CON-14 |
| Penegakan | Laravel Global Scope | CON-15 |

Rincian pada [§7 Multi Tenant Strategy](#7-multi-tenant-strategy).

### 2.6 Konfigurasi Pendukung

| Komponen | Konfigurasi | Dasar |
|---|---|---|
| Cache driver | Laravel Cache — **database driver** (Phase 1) | Blueprint §3.1; CON-07 |
| Queue driver | Laravel Queue — **database driver** (Phase 1) | Blueprint §3.1; CON-07 |
| Queue worker | Supervisor pada VPS | Blueprint §3.3.1 |
| Peningkatan Phase 2 | Cache dapat dialihkan ke Redis | Blueprint §3.1 |

> Tabel bawaan Laravel untuk cache, queue, sessions, dan password reset **tidak termasuk dalam daftar 21 entitas** blueprint. Keberadaan dan strukturnya: **Belum dijelaskan dalam blueprint.**

### 2.7 Batasan Kapasitas

| Aspek | Nilai | Dasar |
|---|---|---|
| Jumlah tenant target | 10+ cabang tanpa refactoring | NFR-03 |
| Skala awal | 3 cabang · 50–200 siswa/cabang · 800–1.500 akun | Blueprint §Target Skala Awal |
| Kapasitas disk VPS | 40 GB SSD (bersama seluruh komponen) | Blueprint §3.1 |
| User konkuren | Minimal 200 pada VPS 2C/2GB | NFR-04 |
| Target response time | < 500 ms untuk 95% request | NFR-02 |

---

## 3. Naming Convention

Konvensi berikut **diturunkan dari nama tabel dan kolom yang benar-benar dipakai blueprint** pada §2.2, bukan ditetapkan baru.

### 3.1 Nama Tabel

| Aturan | Contoh dari Blueprint |
|---|---|
| Huruf kecil semua (*lowercase*) | `schools`, `students`, `payments` |
| Kata dipisah garis bawah (*snake_case*) | `academic_years`, `student_classes`, `ppdb_registrations` |
| Bentuk **jamak** (*plural*) | `users`, `classes`, `subjects`, `grades` |
| Tabel pivot memakai gabungan dua entitas | `student_classes`, `class_subjects`, `notification_reads` |
| Tabel konfigurasi memakai akhiran deskriptif | `grade_configs`, `fee_types` |

**Pengecualian:**

| Tabel | Penyimpangan | Alasan |
|---|---|---|
| `model_has_roles` | Tidak mengikuti pola pivot `{a}_{b}` | Mengikuti konvensi bawaan paket `spatie/laravel-permission` |
| `classes` | Merupakan kata kunci di banyak bahasa pemrograman | Blueprint tetap memakainya; entitas ini disebut "rombel" dalam deskripsi |

### 3.2 Nama Kolom

| Aturan | Contoh dari Blueprint |
|---|---|
| Huruf kecil, *snake_case* | `full_name`, `birth_date`, `grade_level`, `amount_paid` |
| Bentuk **tunggal** | `name`, `code`, `status`, `amount` |
| Kolom boolean memakai awalan `is_` | `is_active`, `is_published`, `is_draft` |
| Kolom tanggal memakai akhiran `_date` | `birth_date`, `due_date`, `payment_date`, `transaction_date`, `start_date`, `end_date` |
| Kolom waktu memakai akhiran `_at` | `created_at`, `updated_at`, `graded_at`, `published_at`, `sent_at`, `read_at`, `registered_at`, `last_login_at`, `email_verified_at` |
| Kolom jam memakai akhiran `_time` | `start_time`, `end_time` |
| Kolom path berkas memakai akhiran `_url` | `logo_url`, `photo_url`, `avatar_url`, `proof_url` |
| Kolom template memakai awalan `wa_template` | `wa_template_ppdb`, `wa_template_spp`, `wa_template_rapor` |
| Kolom nomor referensi | `reference_number`, `reg_number` |

### 3.3 Primary Key

| Aturan | Keterangan |
|---|---|
| Nama kolom | **`id`** — seragam di seluruh tabel |
| Tipe | `BIGINT UNSIGNED` |
| Sifat | Auto-increment, NOT NULL |
| Jenis | **Surrogate key tunggal** — tidak ada composite primary key |
| Cakupan | Berlaku pada **19 tabel** yang definisinya tersedia |

**Pengecualian:**

| Tabel | Status |
|---|---|
| `roles` | Struktur kolom **Belum dijelaskan dalam blueprint.** |
| `model_has_roles` | Struktur kolom **Belum dijelaskan dalam blueprint.** |

### 3.4 Foreign Key

| Pola | Aturan | Contoh |
|---|---|---|
| **Pola utama** | `{tabel_tujuan_tunggal}_id` | `school_id` → `schools.id` · `student_id` → `students.id` · `class_id` → `classes.id` · `subject_id` → `subjects.id` · `academic_year_id` → `academic_years.id` · `fee_type_id` → `fee_types.id` · `student_fee_id` → `student_fees.id` · `class_subject_id` → `class_subjects.id` · `notification_id` → `notifications.id` · `user_id` → `users.id` |
| **Pola peran** | `{peran}_id` — ketika kolom merujuk `users.id` dengan makna peran tertentu | `teacher_id` (guru pengampu) · `homeroom_teacher_id` (wali kelas) · `parent_user_id` (akun ortu) · `sender_id` (pengirim notifikasi) |
| **Pola pelaku** | `{kata_kerja}_by` — ketika kolom mencatat siapa melakukan aksi | `graded_by` · `published_by` · `received_by` · `created_by` |
| **Pola konversi** | `converted_{tujuan}_id` | `converted_student_id` → `students.id` |

> **Catatan:** blueprint **tidak memakai pola penamaan seragam** untuk kolom yang merujuk `users.id`. Terdapat empat pola berbeda (`user_id`, `{peran}_id`, `{aksi}_by`, `sender_id`), semuanya menunjuk tabel yang sama.

**Kolom yang menyerupai FK tetapi bukan FK:**

| Kolom | Keterangan |
|---|---|
| `notifications.target_id` | Berisi `class_id` atau `user_id` tergantung `target_type`, namun **tidak ditandai FK** oleh blueprint |

### 3.5 Timestamp

| Kolom | Aturan | Tabel yang memilikinya |
|---|---|---|
| `created_at` | `TIMESTAMP`, nullable | 18 tabel |
| `updated_at` | `TIMESTAMP`, nullable | 10 tabel |
| Timestamp domain | Dinamai sesuai peristiwa | `graded_at`, `published_at`, `sent_at`, `read_at`, `registered_at`, `last_login_at`, `email_verified_at` |

**Tiga pola penerapan pada blueprint:**

| Pola | Jumlah | Tabel |
|---|:---:|---|
| `created_at` + `updated_at` | 10 | `schools`, `users`, `students`, `academic_years`, `classes`, `ppdb_registrations`, `grades`, `report_cards`, `student_fees`, `notifications` |
| `created_at` saja | 8 | `student_classes`, `subjects`, `class_subjects`, `schedules`, `grade_configs`, `fee_types`, `payments`, `transactions` |
| Tanpa timestamps standar | 1 | `notification_reads` — hanya `read_at` |
| Tidak dijelaskan | 2 | `roles`, `model_has_roles` |

> Penerapan yang tidak seragam ini adalah **kondisi blueprint apa adanya**, bukan rekomendasi dokumen ini.

### 3.6 Soft Delete

| Aspek | Status |
|---|---|
| Kolom `deleted_at` | **Tidak ada satu pun tabel** dalam 21 entitas yang memilikinya |
| Status | **Belum dijelaskan dalam blueprint.** |

**Mekanisme penonaktifan yang benar-benar tersedia:**

| Pola | Kolom | Tabel |
|---|---|---|
| Flag boolean | `is_active` | `schools`, `users`, `subjects`, `fee_types` |
| Kolom status | `status` | `students` (`ACTIVE`/`GRADUATED`/`DROPPED_OUT`/`TRANSFERRED`) · `student_classes` (`ACTIVE`/`MOVED`) |
| Flag publikasi | `is_published` | `report_cards` |
| Flag draft | `is_draft` | `notifications` |

**Aturan bisnis terkait (CON-45):** data siswa, akun pengguna, dan jenis tagihan **tidak boleh dihapus** — hanya dinonaktifkan.

> **⚠ Kesenjangan:** API Map menyebut `DELETE /transactions/{id}` sebagai *"Hapus transaksi (soft delete)"*, namun tabel `transactions` **tidak memiliki kolom `deleted_at` maupun kolom status**. Mekanismenya: **Belum dijelaskan dalam blueprint.**

### 3.7 Index

| Pola | Aturan | Penandaan Blueprint |
|---|---|---|
| Primary index | Otomatis pada kolom `id` | `PK` |
| Unique index | Pada kolom yang wajib unik | `UQ` |
| Secondary index | Pada kolom yang sering difilter | `IX` |

**Kolom bertanda `UQ` (4 kolom):** `schools.code`, `schools.slug`, `users.email`, `ppdb_registrations.reg_number`

**Kolom bertanda `IX` (15 kolom):** `schools.is_active`, `users.is_active`, `students.nis`, `students.nisn`, `students.parent_phone`, `students.status`, `academic_years.is_active`, `schedules.day_of_week`, `ppdb_registrations.status`, `grades.grade_type`, `report_cards.is_published`, `student_fees.period`, `student_fees.status`, `transactions.type`, `notifications.type`

| Aspek | Status |
|---|---|
| Nama indeks (naming convention indeks) | **Belum dijelaskan dalam blueprint.** |
| Composite index | **Belum dijelaskan dalam blueprint.** |
| Index pada kolom foreign key | Tidak ditandai eksplisit oleh blueprint — lihat [§6.2](#62-foreign-index) |

---

## 4. Data Type Standards

### 4.1 Tipe Data yang Dipakai Blueprint

Hanya sebelas tipe berikut yang muncul pada definisi tabel blueprint §2.2:

| Tipe | Penggunaan | Contoh Kolom |
|---|---|---|
| **`BIGINT UNSIGNED`** | Seluruh primary key dan foreign key | `id`, `school_id`, `student_id`, `graded_by` |
| **`VARCHAR(n)`** | Teks pendek dengan panjang terbatas | `name`, `code`, `email`, `nis`, `period` |
| **`TEXT`** | Teks panjang tanpa batas praktis | `address`, `description`, `notes`, `message`, `wa_template_*` |
| **`TINYINT(1)`** | Nilai boolean (0/1) | `is_active`, `is_published`, `is_draft` |
| **`TINYINT`** | Bilangan bulat kecil | `semester`, `grade_level`, `day_of_week`, `credit_hours` |
| **`SMALLINT`** | Bilangan bulat menengah | `capacity`, `attend_present`, `rank_in_class` |
| **`DECIMAL(p,s)`** | Nilai numerik presisi tetap | `score`, `weight`, `amount`, `amount_paid` |
| **`DATE`** | Tanggal tanpa jam | `birth_date`, `due_date`, `payment_date`, `start_date` |
| **`TIME`** | Jam tanpa tanggal | `start_time`, `end_time` |
| **`YEAR`** | Tahun saja | `entry_year` |
| **`TIMESTAMP`** | Tanggal + jam | `created_at`, `updated_at`, `graded_at`, `read_at` |
| **`JSON`** | Struktur data bervariasi | `documents`, `final_scores`, `components` |
| **`ENUM`** | Himpunan nilai tetap | `gender`, `status`, `grade_type`, `frequency`, `type` |

### 4.2 Tipe Data yang **Tidak** Dipakai Blueprint

| Tipe | Keterangan |
|---|---|
| **`BOOLEAN`** | **Tidak dipakai.** Blueprint konsisten memakai `TINYINT(1)` untuk nilai boolean |
| **`DATETIME`** | **Tidak dipakai.** Blueprint memakai `TIMESTAMP` untuk tanggal+jam, dan `DATE` untuk tanggal saja |
| `INT` / `INTEGER` | Tidak dipakai — blueprint memakai `BIGINT UNSIGNED`, `SMALLINT`, atau `TINYINT` |
| `FLOAT` / `DOUBLE` | Tidak dipakai — nilai numerik memakai `DECIMAL` demi presisi |
| `CHAR` | Tidak dipakai |
| `BLOB` | Tidak dipakai — berkas disimpan sebagai path pada kolom `VARCHAR(500)` |
| `UUID` | Tidak dipakai — primary key memakai auto-increment |

### 4.3 Standar Panjang `VARCHAR`

| Panjang | Penggunaan | Kolom |
|:---:|---|---|
| `VARCHAR(5)` | Kode locale | `users.locale` |
| `VARCHAR(7)` | Kode warna heksadesimal · periode `YYYY-MM` | `primary_color`, `secondary_color`, `student_fees.period` |
| `VARCHAR(10)` | NISN (10 digit) | `students.nisn` |
| `VARCHAR(20)` | Kode, nomor telepon, NIS, nomor pendaftaran, nama tahun ajaran | `schools.code`, `phone`, `nis`, `reg_number`, `academic_years.name` |
| `VARCHAR(30)` | Agama | `students.religion` |
| `VARCHAR(50)` | Slug, nama kelas, ruang | `schools.slug`, `classes.name`, `room` |
| `VARCHAR(100)` | Nama mata pelajaran, kategori, nomor referensi, tempat lahir, remember token | `subjects.name`, `transactions.category`, `reference_number`, `birth_place` |
| `VARCHAR(150)` | Nama orang dan lembaga, email | `schools.name`, `users.name`, `full_name`, `email`, `parent_name`, `origin_school` |
| `VARCHAR(200)` | Judul, deskripsi singkat, alasan | `notifications.title`, `grades.description`, `waive_reason` |
| `VARCHAR(255)` | Hash password | `users.password` |
| `VARCHAR(500)` | Path berkas | `logo_url`, `photo_url`, `avatar_url`, `proof_url` |

### 4.4 Standar `DECIMAL`

| Presisi | Rentang | Penggunaan | Kolom |
|---|---|---|---|
| `DECIMAL(5,2)` | 0.00 – 999.99 | Nilai skala 0–100 | `grades.score` |
| `DECIMAL(4,2)` | 0.00 – 99.99 | Bobot komponen (mis. 0.40 = 40%) | `grades.weight` |
| `DECIMAL(12,2)` | hingga 9.999.999.999,99 | Nilai Rupiah | `fee_types.amount`, `student_fees.amount`, `student_fees.amount_paid`, `payments.amount_paid`, `transactions.amount` |

**Aturan pembulatan:** hasil perhitungan nilai akhir dibulatkan **2 desimal** (NILAI-02 AC-3; CON-39).

### 4.5 Standar `ENUM`

Seluruh ENUM yang didefinisikan blueprint — **tidak boleh ditambah nilai baru tanpa revisi blueprint**:

| Tabel | Kolom | Nilai | Default |
|---|---|---|---|
| `students` | `gender` | `L`, `P` | — |
| `students` | `status` | `ACTIVE`, `GRADUATED`, `DROPPED_OUT`, `TRANSFERRED` | `ACTIVE` |
| `student_classes` | `status` | `ACTIVE`, `MOVED` | `ACTIVE` |
| `ppdb_registrations` | `gender` | `L`, `P` | — |
| `ppdb_registrations` | `status` | `REGISTERED`, `DOCUMENT_REVIEW`, `PASSED`, `FAILED`, `ENROLLED` | `REGISTERED` |
| `grades` | `grade_type` | `DAILY`, `MIDTERM`, `FINAL`, `ASSIGNMENT`, `SKILL`, `ATTITUDE` | — |
| `report_cards` | `attitude_score` | `A`, `B`, `C`, `D` | — |
| `fee_types` | `frequency` | `MONTHLY`, `YEARLY`, `ONCE` | — |
| `student_fees` | `status` | `UNPAID`, `PARTIAL`, `PAID`, `WAIVED` | `UNPAID` |
| `payments` | `payment_method` | `CASH`, `TRANSFER`, `PAYMENT_GATEWAY` | — |
| `transactions` | `type` | `INCOME`, `EXPENSE` | — |
| `notifications` | `type` | `ANNOUNCEMENT`, `BILLING`, `ACADEMIC`, `EMERGENCY`, `SYSTEM` | — |
| `notifications` | `target_type` | `ALL`, `CLASS`, `INDIVIDUAL` | — |

> **⚠ Catatan konsistensi:** NOTIF-01 AC-1 menyebut kategori `ACADEMIC`/`BILLING`/`EMERGENCY`/**`GENERAL`**, sedangkan ENUM `notifications.type` memuat `ANNOUNCEMENT`/`BILLING`/`ACADEMIC`/`EMERGENCY`/`SYSTEM`. Nilai `GENERAL` tidak ada dalam ENUM. (PRD §16.4)

### 4.6 Standar Kolom `JSON`

| Tabel | Kolom | Nullable | Struktur | Contoh |
|---|---|:---:|---|---|
| `ppdb_registrations` | `documents` | YA | Array path berkas | — |
| `report_cards` | `final_scores` | TIDAK | Objek `{kode_mapel: nilai}` | `{"MTK": 87.5, "BIN": 90}` |
| `grade_configs` | `components` | TIDAK | Array objek komponen | `[{"type":"DAILY","weight":0.40},{"type":"MIDTERM","weight":0.30}]` |

| Aspek | Status |
|---|---|
| Skema validasi isi JSON | **Belum dijelaskan dalam blueprint.** |
| Apakah ada JSON index | **Belum dijelaskan dalam blueprint.** |

### 4.7 Standar Kolom Boolean

| Aturan | Keterangan |
|---|---|
| Tipe | `TINYINT(1)` — bukan `BOOLEAN` |
| Nullability | Selalu `NOT NULL` |
| Penamaan | Awalan `is_` |
| Nilai | 0 = tidak/nonaktif · 1 = ya/aktif |

| Kolom | Default | Makna default |
|---|:---:|---|
| `schools.is_active` | `1` | Cabang aktif sejak dibuat |
| `users.is_active` | `1` | Akun aktif sejak dibuat |
| `subjects.is_active` | `1` | Mata pelajaran aktif sejak dibuat |
| `fee_types.is_active` | `1` | Jenis tagihan aktif sejak dibuat |
| `academic_years.is_active` | `0` | **Tahun ajaran nonaktif** sejak dibuat — harus diaktifkan eksplisit |
| `report_cards.is_published` | `0` | Rapor berstatus draft sejak dibuat |
| `notifications.is_draft` | `1` | Notifikasi berstatus draft sejak dibuat |

---

## 5. Table Specification

Spesifikasi 21 tabel mengikuti blueprint §2.2 apa adanya.

**Keterangan kolom tabel spesifikasi:**
`PK` = Primary Key · `FK` = Foreign Key · `UQ` = Unique · `IX` = Index · `—` = tidak ada · `⚠` = belum dijelaskan blueprint

---

### 5.1 `schools`

**Tujuan:** Tabel inti tenant. Setiap baris mewakili satu cabang sekolah dan menjadi **akar seluruh isolasi data** serta sumber konfigurasi white-label. Dikelola Super Admin.

#### Kolom

| Column | Type | Nullable | Default | PK | FK | Index | Keterangan |
|---|---|:---:|---|:---:|:---:|:---:|---|
| `id` | `BIGINT UNSIGNED` | NO | auto | ✔ | — | PK | Auto-increment primary key |
| `name` | `VARCHAR(150)` | NO | — | — | — | — | Nama resmi cabang (mis. Smart Sukses School Madani) |
| `code` | `VARCHAR(20)` | NO | — | — | — | UQ | Kode unik cabang (MADANI, PUSAT, CINANGKA) |
| `slug` | `VARCHAR(50)` | NO | — | — | — | UQ | URL-friendly identifier (mis. `madani`) |
| `logo_url` | `VARCHAR(500)` | YES | — | — | — | — | Path berkas logo untuk white-label UI |
| `primary_color` | `VARCHAR(7)` | NO | — | — | — | — | Hex warna utama (mis. `#1B3A6B`) |
| `secondary_color` | `VARCHAR(7)` | NO | — | — | — | — | Hex warna aksen (mis. `#E07020`) |
| `address` | `TEXT` | YES | — | — | — | — | Alamat lengkap sekolah |
| `phone` | `VARCHAR(20)` | YES | — | — | — | — | Nomor telepon sekolah |
| `email` | `VARCHAR(150)` | YES | — | — | — | — | Email resmi sekolah |
| `head_name` | `VARCHAR(150)` | YES | — | — | — | — | Nama kepala sekolah |
| `wa_template_ppdb` | `TEXT` | YES | — | — | — | — | Template teks WA untuk notifikasi PPDB |
| `wa_template_spp` | `TEXT` | YES | — | — | — | — | Template teks WA untuk notifikasi tagihan |
| `wa_template_rapor` | `TEXT` | YES | — | — | — | — | Template teks WA untuk notifikasi rapor |
| `is_active` | `TINYINT(1)` | NO | `1` | — | — | IX | Status aktif tenant |
| `created_at` | `TIMESTAMP` | YES | — | — | — | — | |
| `updated_at` | `TIMESTAMP` | YES | — | — | — | — | |

#### Relationship

| Relasi | Kardinalitas | Tabel Tujuan |
|---|:---:|---|
| Induk bagi seluruh tabel bisnis | `1 ---- *` | 17 tabel melalui kolom `school_id` |
| Induk bagi pengguna | `1 ---- 0..*` | `users` (nullable — Super Admin `NULL`) |
| Dirujuk sebagai target notifikasi | — | `notifications.target_id` bila `target_type = CLASS` merujuk `classes`, bukan tabel ini |

**Satu-satunya tabel tanpa kolom `school_id`** karena merupakan akar tenant itu sendiri.

#### Validation Rules

| Aturan | Sumber |
|---|---|
| `code` wajib unik lintas seluruh platform | Blueprint §2.2 — Key `UQ` |
| `slug` wajib unik lintas seluruh platform | Blueprint §2.2 — Key `UQ` |
| `primary_color` dan `secondary_color` wajib diisi (NOT NULL) | Blueprint §2.2 |
| Format nilai warna heksadesimal (`#RRGGBB`) | Implisit dari `VARCHAR(7)` dan contoh `#1B3A6B` |

#### Business Rules

| Aturan | Sumber |
|---|---|
| Hanya Super Admin yang boleh mengelola tabel ini | PRD §8.2 — Manajemen Tenant ✅ hanya `SUPER_ADMIN` |
| Perubahan white-label (`logo_url`, `primary_color`, `secondary_color`) **berlaku tanpa deployment ulang** | AUTH-03 AC-3; CON-19 |
| White-label dapat diubah Admin Sekolah untuk cabangnya sendiri | PRD §8.2 — White-label Settings ✅ `SCHOOL_ADMIN` |
| Cabang dapat diaktif/nonaktifkan, bukan dihapus | `PATCH /admin/schools/{id}/toggle` |
| Penambahan cabang tidak memerlukan perubahan kode | ASM-02; NFR-03 |
| Template WA dapat diedit Admin Sekolah | NOTIF-03 AC-2 |

#### Notes

- Sistem membaca `logo_url`, `primary_color`, dan `secondary_color` setiap kali pengguna login, lalu menyuntikkannya sebagai CSS variables (Blueprint §3.2.2 langkah 6–7).
- Aturan `ON DELETE` untuk 17 relasi anaknya: **Belum dijelaskan dalam blueprint.**

---

### 5.2 `users`

**Tujuan:** Menyimpan seluruh pengguna sistem dari semua role dalam satu tabel. Super Admin memiliki `school_id = NULL`.

#### Kolom

| Column | Type | Nullable | Default | PK | FK | Index | Keterangan |
|---|---|:---:|---|:---:|:---:|:---:|---|
| `id` | `BIGINT UNSIGNED` | NO | auto | ✔ | — | PK | Auto-increment primary key |
| `school_id` | `BIGINT UNSIGNED` | YES | — | — | ✔ | — | → `schools.id`. **`NULL` untuk Super Admin** |
| `name` | `VARCHAR(150)` | NO | — | — | — | — | Nama lengkap pengguna |
| `email` | `VARCHAR(150)` | NO | — | — | — | UQ | Email unik, dipakai sebagai username login |
| `password` | `VARCHAR(255)` | NO | — | — | — | — | Hash Bcrypt/Argon2 |
| `phone` | `VARCHAR(20)` | YES | — | — | — | — | Nomor HP — dipakai untuk generate wa.me link |
| `avatar_url` | `VARCHAR(500)` | YES | — | — | — | — | Path foto profil |
| `locale` | `VARCHAR(5)` | NO | `id` | — | — | — | Preferensi bahasa: `id` atau `en` |
| `is_active` | `TINYINT(1)` | NO | `1` | — | — | IX | Status aktif |
| `email_verified_at` | `TIMESTAMP` | YES | — | — | — | — | Timestamp verifikasi email |
| `last_login_at` | `TIMESTAMP` | YES | — | — | — | — | Waktu login terakhir |
| `remember_token` | `VARCHAR(100)` | YES | — | — | — | — | Laravel remember token |
| `created_at` | `TIMESTAMP` | YES | — | — | — | — | |
| `updated_at` | `TIMESTAMP` | YES | — | — | — | — | |

#### Relationship

| Relasi | Kardinalitas | Kolom | Peran |
|---|:---:|---|---|
| Milik satu cabang | `0..1 ---- 1` | `users.school_id` | Semua kecuali Super Admin |
| Akun portal siswa | `1 ---- 0..1` | `students.user_id` | Siswa |
| Wali murid dari siswa | `1 ---- 0..*` | `students.parent_user_id` | Orang Tua |
| Wali kelas dari kelas | `1 ---- 0..*` | `classes.homeroom_teacher_id` | Wali Kelas |
| Pengampu mata pelajaran | `1 ---- *` | `class_subjects.teacher_id` | Guru |
| Penginput nilai | `1 ---- *` | `grades.graded_by` | Guru |
| Penerbit rapor | `1 ---- 0..*` | `report_cards.published_by` | Wali Kelas |
| Pembuat konfigurasi bobot | `1 ---- *` | `grade_configs.created_by` | Admin Sekolah |
| Penerima pembayaran | `1 ---- *` | `payments.received_by` | Bendahara |
| Pencatat transaksi kas | `1 ---- *` | `transactions.created_by` | Bendahara |
| Pengirim notifikasi | `1 ---- 0..*` | `notifications.sender_id` | Admin / Kepala Sekolah |
| Pembaca notifikasi | `1 ---- *` | `notification_reads.user_id` | Semua role |
| Pemilik peran | `* ---- *` | via `model_has_roles` | Semua role |

#### Validation Rules

| Aturan | Sumber |
|---|---|
| `email` wajib unik **lintas seluruh platform**, bukan per cabang | Blueprint §2.2 — Key `UQ`; ASM-12 |
| Password minimal **8 karakter** | NFR-07; CON-27 |
| Password disimpan sebagai hash Argon2id | Blueprint §3.4 |
| `locale` hanya bernilai `id` atau `en` | Blueprint §2.2 |
| Pesan error login tidak boleh mengungkap detail sistem | AUTH-01 AC-2; CON-34 |

#### Business Rules

| Aturan | Sumber |
|---|---|
| Setiap pengguna memiliki **tepat satu peran utama** | Blueprint §1.1; ASM-08 |
| Super Admin memiliki `school_id = NULL` dan **melewati Global Scope** | Blueprint §3.2.2; CON-16 |
| Penonaktifan bersifat **soft deactivate**, bukan hard delete | `DELETE /users/{id}`; CON-45 |
| Password **wajib diganti saat login pertama** | NFR-07; CON-27 |
| Token sesi kedaluwarsa setelah **8 jam tidak aktif** | AUTH-01 AC-4; CON-29 |
| Reset password meng-invalidate **seluruh sesi aktif** | AUTH-04 AC-3; CON-30 |
| Pembuatan akun guru dan siswa dapat dilakukan **massal via import Excel** | PORTAL-04 AC-1 |
| Reset password oleh Admin menghasilkan **password sementara** | PORTAL-04 AC-2 |
| Kolom `phone` menjadi sumber nomor untuk link `wa.me/62[nomorHP]` | NOTIF-02 AC-1 |

#### Notes

- **Tidak ada tabel `teachers` maupun `parents`.** Guru dan orang tua adalah baris pada tabel ini, dibedakan oleh role.
- Pembatasan bahwa hanya user ber-role `GURU`/`WALI_KELAS` yang boleh mengisi `class_subjects.teacher_id` **tidak dijamin skema** — harus ditegakkan di lapisan aplikasi.
- Aturan normalisasi `phone` ke awalan `62`: **Belum dijelaskan dalam blueprint.**
- Perilaku sesi aktif ketika akun dinonaktifkan: **Belum dijelaskan dalam blueprint.**

---

### 5.3 `roles`

**Tujuan:** Mendefinisikan 8 peran sistem mengikuti paket `spatie/laravel-permission`.

#### Kolom

| Column | Type | Nullable | Default | PK | FK | Index | Keterangan |
|---|---|:---:|---|:---:|:---:|:---:|---|
| ⚠ | ⚠ | ⚠ | ⚠ | ⚠ | ⚠ | ⚠ | **Belum dijelaskan dalam blueprint.** Blueprint mendaftarkan tabel ini pada §2.1 dengan deskripsi *"Definisi peran (spatie/permission)"*, namun **tidak menyediakan definisi kolom** pada §2.2 |

#### Relationship

| Relasi | Kardinalitas | Melalui |
|---|:---:|---|
| Peran dimiliki banyak pengguna | `* ---- *` | `model_has_roles` |

#### Validation Rules

**Belum dijelaskan dalam blueprint.**

#### Business Rules

| Aturan | Sumber |
|---|---|
| Terdapat **8 peran**: `SUPER_ADMIN`, `SCHOOL_ADMIN`, `KEPALA_SEKOLAH`, `GURU`, `WALI_KELAS`, `SISWA`, `ORANG_TUA`, `BENDAHARA` | Blueprint §1.1.1 |
| Dua level peran: **Platform** (`SUPER_ADMIN`) dan **Sekolah** (7 lainnya) | Blueprint §1.1 |
| Setiap pengguna memiliki tepat satu peran utama | Blueprint §1.1 |

#### Notes

- Tabel ini **tidak memiliki kolom `school_id`** — definisi peran bersifat global lintas cabang.
- Blueprint memakai paket `spatie/laravel-permission` yang secara bawaan juga menyediakan tabel `permissions` dan `role_has_permissions`, namun **kedua tabel tersebut tidak didaftarkan** dalam 21 entitas. → **Belum dijelaskan dalam blueprint.**

---

### 5.4 `model_has_roles`

**Tujuan:** Tabel pivot yang menghubungkan pengguna dengan perannya.

#### Kolom

| Column | Type | Nullable | Default | PK | FK | Index | Keterangan |
|---|---|:---:|---|:---:|:---:|:---:|---|
| ⚠ | ⚠ | ⚠ | ⚠ | ⚠ | ⚠ | ⚠ | **Belum dijelaskan dalam blueprint.** Blueprint mendaftarkan tabel ini pada §2.1 dengan deskripsi *"Pivot: assignment peran ke user"*, namun **tidak menyediakan definisi kolom** pada §2.2 |

#### Relationship

| Relasi | Kardinalitas | Tabel |
|---|:---:|---|
| Merujuk pengguna | `* ---- 1` | `users` |
| Merujuk peran | `* ---- 1` | `roles` |

#### Validation Rules

**Belum dijelaskan dalam blueprint.**

#### Business Rules

| Aturan | Sumber |
|---|---|
| Secara struktural mendukung banyak peran per pengguna, namun blueprint menetapkan **tepat satu peran utama per pengguna** | Blueprint §1.1; ASM-08 |

#### Notes

- Tabel ini **tidak memiliki kolom `school_id`** — isolasi diturunkan melalui `users.school_id`.
- Apakah pembatasan "satu peran per pengguna" ditegakkan constraint database atau hanya aplikasi: **Belum dijelaskan dalam blueprint.**

---

### 5.5 `students`

**Tujuan:** Data induk siswa per cabang. Seorang siswa dapat memiliki akun portal (`user_id`) namun tidak wajib.

#### Kolom

| Column | Type | Nullable | Default | PK | FK | Index | Keterangan |
|---|---|:---:|---|:---:|:---:|:---:|---|
| `id` | `BIGINT UNSIGNED` | NO | auto | ✔ | — | PK | |
| `school_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `schools.id`. **Tenant isolation key** |
| `user_id` | `BIGINT UNSIGNED` | YES | — | — | ✔ | — | → `users.id`. `NULL` jika siswa belum punya akun portal |
| `nis` | `VARCHAR(20)` | NO | — | — | — | IX | Nomor Induk Siswa — lokal, unik per sekolah |
| `nisn` | `VARCHAR(10)` | YES | — | — | — | IX | Nomor Induk Siswa Nasional (10 digit) |
| `full_name` | `VARCHAR(150)` | NO | — | — | — | — | Nama lengkap siswa |
| `gender` | `ENUM('L','P')` | NO | — | — | — | — | L = Laki-laki, P = Perempuan |
| `birth_place` | `VARCHAR(100)` | YES | — | — | — | — | Kota lahir |
| `birth_date` | `DATE` | YES | — | — | — | — | Tanggal lahir |
| `religion` | `VARCHAR(30)` | YES | — | — | — | — | Agama |
| `address` | `TEXT` | YES | — | — | — | — | Alamat domisili |
| `photo_url` | `VARCHAR(500)` | YES | — | — | — | — | Path foto siswa (resize 400×400) |
| `parent_name` | `VARCHAR(150)` | YES | — | — | — | — | Nama orang tua / wali |
| `parent_phone` | `VARCHAR(20)` | YES | — | — | — | IX | Nomor HP ortu — untuk wa.me link |
| `parent_email` | `VARCHAR(150)` | YES | — | — | — | — | Email ortu — untuk notifikasi |
| `parent_user_id` | `BIGINT UNSIGNED` | YES | — | — | ✔ | — | → `users.id`. Akun portal orang tua |
| `entry_year` | `YEAR` | YES | — | — | — | — | Tahun masuk sekolah |
| `status` | `ENUM('ACTIVE','GRADUATED','DROPPED_OUT','TRANSFERRED')` | NO | `ACTIVE` | — | — | IX | Status siswa |
| `notes` | `TEXT` | YES | — | — | — | — | Catatan tambahan dari admin |
| `created_at` | `TIMESTAMP` | YES | — | — | — | — | |
| `updated_at` | `TIMESTAMP` | YES | — | — | — | — | |

#### Relationship

| Relasi | Kardinalitas | Tabel Tujuan |
|---|:---:|---|
| Milik satu cabang | `* ---- 1` | `schools` |
| Memiliki akun portal siswa | `0..1 ---- 1` | `users` (via `user_id`) |
| Memiliki akun portal ortu | `0..1 ---- 1` | `users` (via `parent_user_id`) |
| Ditempatkan di kelas | `1 ---- *` | `student_classes` |
| Memperoleh nilai | `1 ---- *` | `grades` |
| Menerima rapor | `1 ---- *` | `report_cards` |
| Menanggung tagihan | `1 ---- *` | `student_fees` |
| Melakukan pembayaran | `1 ---- *` | `payments` (denormalisasi) |
| Berasal dari pendaftaran PPDB | `0..1 ---- 1` | `ppdb_registrations.converted_student_id` |

#### Validation Rules

| Aturan | Sumber |
|---|---|
| Field wajib saat pembuatan: nama, NIS, NISN, tanggal lahir, jenis kelamin, agama, alamat, nama ortu, no. HP ortu | SIS-01 AC-1 |
| **NISN wajib 10 digit angka** | SIS-01 AC-2; CON-38 |
| **NIS wajib unik dalam satu sekolah** | SIS-01 AC-3; CON-38 |
| Foto: format JPG/PNG/WEBP, maksimal **2 MB**, auto-resize **400×400 px** | SIS-03; CON-43 |

#### Business Rules

| Aturan | Sumber |
|---|---|
| Edit data **tidak menghapus histori** (soft update) | SIS-02 AC-1 |
| Siswa yang dinonaktifkan **tidak dihapus** dari database | SIS-02 AC-2; CON-45 |
| Siswa tidak aktif **tidak muncul** di daftar kelas aktif | SIS-02 AC-3 |
| Guru hanya melihat siswa pada kelas yang diampunya | SIS-04 AC-1; PRD §8.3 |
| Satu siswa hanya boleh berada di satu kelas per tahun ajaran | KELAS-02 AC-2; CON-35 |
| Akun portal siswa bersifat **opsional** (`user_id` nullable) | Blueprint §2.2 |
| Orang tua hanya dapat melihat data anaknya sendiri | PRD §8.3 |

#### Notes

- **⚠ Ketidaksesuaian status:** SIS-02 AC-2 menyebut `status = INACTIVE`, sedangkan ENUM tidak memuat nilai tersebut. → **Belum dijelaskan dalam blueprint.** (PRD §17.3 #5)
- **⚠ Ketidaksesuaian format foto:** SIS-03 mengizinkan **WEBP**, sedangkan Blueprint §3.4 menyatakan *"Hanya JPG/PNG/PDF diperbolehkan"*. (PRD §17.3 #12)
- **⚠ Keunikan NIS:** kolom ditandai `IX` (index), bukan `UQ`. Penegakan keunikan komposit `school_id` + `nis`: **Belum dijelaskan dalam blueprint.**
- **⚠ Duplikasi data ortu:** ketika orang tua memiliki akun, kontak tersimpan di dua tempat (`parent_phone`/`parent_email` dan `users`). Sumber kebenaran mana yang berlaku: **Belum dijelaskan dalam blueprint.**
- **⚠ Dua wali:** skenario satu siswa dengan dua akun wali: **Belum dijelaskan dalam blueprint.**

---

### 5.6 `academic_years`

**Tujuan:** Menandai periode akademik dan semester aktif per cabang. Menjadi sumbu waktu bagi hampir seluruh entitas akademik dan keuangan.

#### Kolom

| Column | Type | Nullable | Default | PK | FK | Index | Keterangan |
|---|---|:---:|---|:---:|:---:|:---:|---|
| `id` | `BIGINT UNSIGNED` | NO | auto | ✔ | — | PK | |
| `school_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `schools.id` |
| `name` | `VARCHAR(20)` | NO | — | — | — | — | Contoh: `2024/2025 Semester 1` |
| `start_date` | `DATE` | NO | — | — | — | — | Tanggal mulai tahun ajaran |
| `end_date` | `DATE` | NO | — | — | — | — | Tanggal berakhir tahun ajaran |
| `semester` | `TINYINT` | NO | — | — | — | — | 1 atau 2 |
| `is_active` | `TINYINT(1)` | NO | `0` | — | — | IX | **Hanya satu tahun ajaran per sekolah boleh aktif** |
| `created_at` | `TIMESTAMP` | YES | — | — | — | — | |
| `updated_at` | `TIMESTAMP` | YES | — | — | — | — | |

#### Relationship

| Relasi | Kardinalitas | Tabel Tujuan |
|---|:---:|---|
| Milik satu cabang | `* ---- 1` | `schools` |
| Menaungi kelas | `1 ---- *` | `classes` |
| Menaungi penempatan siswa | `1 ---- *` | `student_classes` |
| Menaungi penetapan pengampu | `1 ---- *` | `class_subjects` |
| Menaungi pendaftaran PPDB | `1 ---- 0..*` | `ppdb_registrations` (nullable) |
| Menaungi nilai | `1 ---- *` | `grades` |
| Menaungi rapor | `1 ---- *` | `report_cards` |
| Menaungi konfigurasi bobot | `1 ---- *` | `grade_configs` |
| Menaungi jenis tagihan | `1 ---- 0..*` | `fee_types` (nullable) |
| Menaungi tagihan siswa | `1 ---- 0..*` | `student_fees` (nullable) |

**Dirujuk oleh 9 tabel** — terbanyak kedua setelah `schools`.

#### Validation Rules

| Aturan | Sumber |
|---|---|
| `semester` bernilai 1 atau 2 | Blueprint §2.2 |
| `start_date` dan `end_date` wajib diisi | Blueprint §2.2 — NOT NULL |

#### Business Rules

| Aturan | Sumber |
|---|---|
| **Hanya satu tahun ajaran per sekolah boleh aktif** | Blueprint §2.2; CON-37; ASM-10 |
| Mengaktifkan satu tahun ajaran menonaktifkan yang lain | `PATCH /academic-years/{id}/activate` |
| Default `is_active = 0` — tahun ajaran baru harus diaktifkan eksplisit | Blueprint §2.2 |
| Perubahan konfigurasi bobot penilaian hanya berlaku untuk tahun ajaran baru | NILAI-05 AC-2; CON-42 |

#### Notes

- Penegakan aturan "hanya satu aktif" pada tingkat database (mis. partial unique index): **Belum dijelaskan dalam blueprint.**
- Kolom `name` bertipe `VARCHAR(20)` sementara contoh nilainya `2024/2025 Semester 1` berpanjang 21 karakter. Blueprint tidak menjelaskan penyesuaiannya. → **Belum dijelaskan dalam blueprint.**

---

### 5.7 `classes`

**Tujuan:** Rombongan belajar (rombel) per tahun ajaran per cabang.

#### Kolom

| Column | Type | Nullable | Default | PK | FK | Index | Keterangan |
|---|---|:---:|---|:---:|:---:|:---:|---|
| `id` | `BIGINT UNSIGNED` | NO | auto | ✔ | — | PK | |
| `school_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `schools.id` |
| `academic_year_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `academic_years.id` |
| `name` | `VARCHAR(50)` | NO | — | — | — | — | Nama kelas: X-A, XI-IPA-1 |
| `grade_level` | `TINYINT` | NO | — | — | — | — | Tingkat: 10, 11, atau 12 |
| `homeroom_teacher_id` | `BIGINT UNSIGNED` | YES | — | — | ✔ | — | → `users.id` (guru dengan role `WALI_KELAS`) |
| `room` | `VARCHAR(50)` | YES | — | — | — | — | Kode atau nama ruang kelas |
| `capacity` | `SMALLINT` | NO | `35` | — | — | — | Kapasitas maksimum siswa |
| `created_at` | `TIMESTAMP` | YES | — | — | — | — | |
| `updated_at` | `TIMESTAMP` | YES | — | — | — | — | |

#### Relationship

| Relasi | Kardinalitas | Tabel Tujuan |
|---|:---:|---|
| Milik satu cabang | `* ---- 1` | `schools` |
| Berada pada satu tahun ajaran | `* ---- 1` | `academic_years` |
| Diampu satu wali kelas | `* ---- 0..1` | `users` (via `homeroom_teacher_id`) |
| Menampung siswa | `1 ---- *` | `student_classes` |
| Mengajarkan mata pelajaran | `1 ---- *` | `class_subjects` |
| Menaungi rapor | `1 ---- *` | `report_cards` |
| Dirujuk sebagai target notifikasi | — | `notifications.target_id` bila `target_type = CLASS` (tanpa FK) |

#### Validation Rules

| Aturan | Sumber |
|---|---|
| Field wajib: nama kelas, tingkat, wali kelas, kapasitas | KELAS-01 AC-1 |
| Wali kelas **hanya dapat dipilih dari daftar guru aktif** | KELAS-01 AC-2 |
| `grade_level` bernilai 10, 11, atau 12 | Blueprint §2.2 |

#### Business Rules

| Aturan | Sumber |
|---|---|
| **Satu guru hanya boleh menjadi wali kelas satu kelas per tahun ajaran** | KELAS-01 AC-3; CON-36 |
| Kelas dibuat untuk tahun ajaran aktif | KELAS-01 |
| Hanya siswa aktif yang dapat ditempatkan | KELAS-02 AC-1 |

#### Notes

- **⚠ Duplikasi kolom `room`:** ada di sini dan di `schedules`. Presedensi keduanya: **Belum dijelaskan dalam blueprint.**
- Pembatasan bahwa `homeroom_teacher_id` harus ber-role `WALI_KELAS` **tidak dijamin skema** — harus ditegakkan aplikasi.
- Apakah `capacity` divalidasi saat penambahan siswa: **Belum dijelaskan dalam blueprint.**

---

### 5.8 `student_classes`

**Tujuan:** Tabel pivot yang merekam siswa mana masuk kelas mana pada tahun ajaran tertentu (*enrollment*).

#### Kolom

| Column | Type | Nullable | Default | PK | FK | Index | Keterangan |
|---|---|:---:|---|:---:|:---:|:---:|---|
| `id` | `BIGINT UNSIGNED` | NO | auto | ✔ | — | PK | |
| `school_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `schools.id` |
| `student_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `students.id` |
| `class_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `classes.id` |
| `academic_year_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `academic_years.id` |
| `status` | `ENUM('ACTIVE','MOVED')` | NO | `ACTIVE` | — | — | — | Status siswa di kelas ini |
| `created_at` | `TIMESTAMP` | YES | — | — | — | — | **Tidak ada `updated_at`** |

#### Relationship

| Relasi | Kardinalitas | Tabel Tujuan |
|---|:---:|---|
| Merujuk siswa | `* ---- 1` | `students` |
| Merujuk kelas | `* ---- 1` | `classes` |
| Merujuk tahun ajaran | `* ---- 1` | `academic_years` |
| Mewujudkan relasi many-to-many | `students * ---- * classes` | per tahun ajaran |

#### Validation Rules

| Aturan | Sumber |
|---|---|
| Siswa dipilih dari daftar **siswa aktif yang belum terdaftar di kelas manapun** untuk tahun ajaran tersebut | KELAS-02 AC-1 |

#### Business Rules

| Aturan | Sumber |
|---|---|
| **Satu siswa hanya boleh ada di satu kelas per tahun ajaran** | KELAS-02 AC-2; CON-35 |
| Status `MOVED` menandai perpindahan kelas tanpa menghapus riwayat | Blueprint §2.2 |
| Penghapusan siswa dari kelas dilakukan melalui `DELETE /classes/{id}/students/{studentId}` | Blueprint §4.6 |

#### Notes

- Tabel ini menyimpan `academic_year_id` sendiri meskipun dapat diturunkan melalui `classes` — **denormalisasi disengaja** untuk mempercepat filter per tahun ajaran (ERD §7.3).
- Keberadaan **unique composite constraint** (`student_id` + `academic_year_id`): **Belum dijelaskan dalam blueprint.** Aturan CON-35 karena itu ditegakkan di lapisan aplikasi.
- Tabel ini **tidak memiliki `updated_at`** — perubahan status tidak meninggalkan jejak waktu pada tingkat baris.

---

### 5.9 `subjects`

**Tujuan:** Daftar mata pelajaran per cabang. Dapat dikustomisasi sesuai kurikulum masing-masing cabang.

#### Kolom

| Column | Type | Nullable | Default | PK | FK | Index | Keterangan |
|---|---|:---:|---|:---:|:---:|:---:|---|
| `id` | `BIGINT UNSIGNED` | NO | auto | ✔ | — | PK | |
| `school_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `schools.id` |
| `name` | `VARCHAR(100)` | NO | — | — | — | — | Nama mapel: Matematika, Fikih, Bahasa Arab |
| `code` | `VARCHAR(20)` | NO | — | — | — | — | Kode mapel: MTK, FIK, ARB |
| `credit_hours` | `TINYINT` | YES | — | — | — | — | Jam pelajaran per minggu |
| `description` | `TEXT` | YES | — | — | — | — | Deskripsi singkat mata pelajaran |
| `is_active` | `TINYINT(1)` | NO | `1` | — | — | — | Status aktif |
| `created_at` | `TIMESTAMP` | YES | — | — | — | — | **Tidak ada `updated_at`** |

#### Relationship

| Relasi | Kardinalitas | Tabel Tujuan |
|---|:---:|---|
| Milik satu cabang | `* ---- 1` | `schools` |
| Diajarkan di banyak kelas | `1 ---- *` | `class_subjects` |
| Dikonfigurasi bobot penilaiannya | `1 ---- *` | `grade_configs` |

#### Validation Rules

**Belum dijelaskan dalam blueprint.** Blueprint tidak memuat user story khusus untuk pembuatan mata pelajaran; entitas ini muncul melalui `POST /subjects` pada API Map §4.6.

#### Business Rules

| Aturan | Sumber |
|---|---|
| Mata pelajaran **dapat dikustomisasi per cabang** — tidak mengikuti format Merdeka/K13 secara kaku | Blueprint §Target Skala Awal; §2.2 |
| Dapat dinonaktifkan melalui `is_active` | Blueprint §2.2 |
| Konfigurasi bobot dapat berbeda antar mata pelajaran | NILAI-05 AC-1 |

#### Notes

- **Tidak ada relasi foreign key** antara `subjects` dan `report_cards` — nilai akhir per mapel disimpan sebagai JSON pada `report_cards.final_scores` dengan kunci berupa kode mapel (ERD §5.8).
- Keunikan `code` per sekolah: **Belum dijelaskan dalam blueprint** (kolom tidak ditandai `UQ`).
- Tabel ini **tidak memiliki `updated_at`**.

---

### 5.10 `class_subjects`

**Tujuan:** Mata pelajaran yang diajarkan di kelas tertentu oleh guru tertentu. Menjadi **dasar input nilai dan penyusunan jadwal**.

#### Kolom

| Column | Type | Nullable | Default | PK | FK | Index | Keterangan |
|---|---|:---:|---|:---:|:---:|:---:|---|
| `id` | `BIGINT UNSIGNED` | NO | auto | ✔ | — | PK | |
| `school_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `schools.id` |
| `class_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `classes.id` |
| `subject_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `subjects.id` |
| `teacher_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `users.id` (guru yang mengajar) |
| `academic_year_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `academic_years.id` |
| `created_at` | `TIMESTAMP` | YES | — | — | — | — | **Tidak ada `updated_at`** |

#### Relationship

| Relasi | Kardinalitas | Tabel Tujuan |
|---|:---:|---|
| Merujuk kelas | `* ---- 1` | `classes` |
| Merujuk mata pelajaran | `* ---- 1` | `subjects` |
| Merujuk guru pengampu | `* ---- 1` | `users` |
| Merujuk tahun ajaran | `* ---- 1` | `academic_years` |
| Dijadwalkan pada slot waktu | `1 ---- *` | `schedules` |
| Menghasilkan nilai | `1 ---- *` | `grades` |

**Tabel dengan foreign key terbanyak (5)** — simpul penghubung antara kelas, mata pelajaran, guru, dan tahun ajaran.

#### Validation Rules

**Belum dijelaskan dalam blueprint** secara eksplisit. Blueprint menyebut penetapan pengampu sebagai bagian dari alur akademik (KELAS-03) tanpa memberikan acceptance criteria tersendiri.

#### Business Rules

| Aturan | Sumber |
|---|---|
| Guru hanya dapat menginput nilai untuk `class_subject` yang diampunya | `POST /grades`; PRD §8.3 |
| Guru hanya melihat siswa pada kelas yang diampunya | SIS-04; PRD §8.3 |
| Guru hanya melihat jadwal dirinya sendiri | `GET /schedules`; PRD §8.3 |

#### Notes

- Tanpa entitas ini, jadwal dan penilaian **tidak dapat terbentuk** — `schedules.class_subject_id` dan `grades.class_subject_id` keduanya NOT NULL.
- Apakah satu kombinasi kelas + mapel + tahun ajaran hanya boleh muncul sekali (unique composite): **Belum dijelaskan dalam blueprint.**
- Pembatasan bahwa `teacher_id` harus ber-role `GURU`/`WALI_KELAS` **tidak dijamin skema**.
- Tabel ini **tidak memiliki `updated_at`** — pergantian guru pengampu tidak meninggalkan jejak waktu.

---

### 5.11 `schedules`

**Tujuan:** Jadwal pelajaran mingguan per `class_subject`.

#### Kolom

| Column | Type | Nullable | Default | PK | FK | Index | Keterangan |
|---|---|:---:|---|:---:|:---:|:---:|---|
| `id` | `BIGINT UNSIGNED` | NO | auto | ✔ | — | PK | |
| `school_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `schools.id` |
| `class_subject_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `class_subjects.id` |
| `day_of_week` | `TINYINT` | NO | — | — | — | IX | 1 = Senin, 2 = Selasa, …, 7 = Minggu |
| `start_time` | `TIME` | NO | — | — | — | — | Jam mulai — contoh `07:00:00` |
| `end_time` | `TIME` | NO | — | — | — | — | Jam selesai — contoh `08:30:00` |
| `room` | `VARCHAR(50)` | YES | — | — | — | — | Ruang kelas yang digunakan |
| `created_at` | `TIMESTAMP` | YES | — | — | — | — | **Tidak ada `updated_at`** |

#### Relationship

| Relasi | Kardinalitas | Tabel Tujuan |
|---|:---:|---|
| Milik satu cabang | `* ---- 1` | `schools` |
| Merujuk penetapan pengampu | `* ---- 1` | `class_subjects` |

Melalui `class_subjects`, jadwal terhubung ke kelas, mata pelajaran, guru, dan tahun ajaran.

#### Validation Rules

| Aturan | Sumber |
|---|---|
| Field wajib: kelas, mata pelajaran, guru, hari, jam mulai, jam selesai, ruang | KELAS-03 AC-1 |
| **Sistem wajib mendeteksi konflik jadwal** — guru, ruangan, atau kelas yang sama pada waktu bersamaan | KELAS-03 AC-2; CON-48 |
| `day_of_week` bernilai 1–7 | Blueprint §2.2 |

#### Business Rules

| Aturan | Sumber |
|---|---|
| Guru dapat melihat jadwal mengajarnya untuk minggu berjalan | KELAS-04 AC-1 |
| Klik jadwal menampilkan detail kelas, mata pelajaran, dan ruang | KELAS-04 AC-2 |
| Guru hanya melihat jadwal dirinya sendiri | `GET /schedules` |

#### Notes

- Deteksi konflik melibatkan **tiga dimensi** yang berasal dari dua tabel: guru dan kelas dari `class_subjects`, ruang dari `schedules.room`. Ini tercatat sebagai risiko implementasi RSK-21 pada Roadmap.
- **⚠ Duplikasi `room`:** kolom yang sama ada pada `classes`. Presedensi: **Belum dijelaskan dalam blueprint.**
- Apakah `end_time` divalidasi harus lebih besar dari `start_time`: **Belum dijelaskan dalam blueprint.**
- Tabel ini **tidak memiliki `updated_at`**.

---

### 5.12 `ppdb_registrations`

**Tujuan:** Data pendaftar PPDB. Dapat diisi **tanpa login** melalui form publik. Setelah diterima, data ini menjadi sumber pembuatan record `students`.

#### Kolom

| Column | Type | Nullable | Default | PK | FK | Index | Keterangan |
|---|---|:---:|---|:---:|:---:|:---:|---|
| `id` | `BIGINT UNSIGNED` | NO | auto | ✔ | — | PK | |
| `school_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `schools.id` |
| `academic_year_id` | `BIGINT UNSIGNED` | YES | — | — | ✔ | — | → `academic_years.id` (tahun ajaran yang didaftar) |
| `reg_number` | `VARCHAR(20)` | NO | — | — | — | UQ | Nomor pendaftaran: `[KODE_CABANG]-[TAHUN]-[SEQ]` |
| `full_name` | `VARCHAR(150)` | NO | — | — | — | — | Nama lengkap calon siswa |
| `gender` | `ENUM('L','P')` | NO | — | — | — | — | Jenis kelamin |
| `birth_date` | `DATE` | YES | — | — | — | — | Tanggal lahir |
| `origin_school` | `VARCHAR(150)` | YES | — | — | — | — | Asal SMP/MTs |
| `parent_name` | `VARCHAR(150)` | YES | — | — | — | — | Nama orang tua |
| `parent_phone` | `VARCHAR(20)` | YES | — | — | — | — | Nomor HP orang tua |
| `parent_email` | `VARCHAR(150)` | YES | — | — | — | — | Email orang tua |
| `documents` | `JSON` | YES | — | — | — | — | Path berkas dokumen yang diunggah (array URL) |
| `status` | `ENUM('REGISTERED','DOCUMENT_REVIEW','PASSED','FAILED','ENROLLED')` | NO | `REGISTERED` | — | — | IX | Status alur PPDB |
| `status_notes` | `TEXT` | YES | — | — | — | — | Catatan alasan perubahan status |
| `converted_student_id` | `BIGINT UNSIGNED` | YES | — | — | ✔ | — | → `students.id` (setelah di-enroll) |
| `registered_at` | `TIMESTAMP` | NO | — | — | — | — | Waktu submit formulir |
| `created_at` | `TIMESTAMP` | YES | — | — | — | — | |
| `updated_at` | `TIMESTAMP` | YES | — | — | — | — | |

#### Relationship

| Relasi | Kardinalitas | Tabel Tujuan |
|---|:---:|---|
| Milik satu cabang | `* ---- 1` | `schools` |
| Mendaftar pada tahun ajaran | `* ---- 0..1` | `academic_years` (nullable) |
| Dikonversi menjadi siswa | `1 ---- 0..1` | `students` (via `converted_student_id`) |

#### Validation Rules

| Aturan | Sumber |
|---|---|
| Field formulir: nama lengkap, jenis kelamin, tanggal lahir, asal sekolah, nama ortu, no. HP, email | PPDB-01 AC-2 |
| `reg_number` wajib **unik** | Blueprint §2.2 — Key `UQ` |
| Perubahan status **wajib disertai catatan alasan** | PPDB-03 AC-3 |
| Pengecekan status publik memakai nomor pendaftaran + tanggal lahir | `GET /ppdb/check-status` |

#### Business Rules

| Aturan | Sumber |
|---|---|
| Form dapat diakses **publik tanpa login** via `/ppdb/[kode_sekolah]` | PPDB-01 AC-1 |
| Setelah submit, sistem menampilkan **nomor pendaftaran unik** | PPDB-01 AC-3 |
| Alur status: `REGISTERED` → `DOCUMENT_REVIEW` → `PASSED`/`FAILED` → `ENROLLED` | PPDB-02 AC-2 |
| Setiap perubahan status **memicu notifikasi otomatis** | NOTIF-03 AC-1 |
| Enroll satu klik: data PPDB mengisi otomatis form siswa; admin dapat melengkapi sebelum konfirmasi | PPDB-05 AC-1, AC-2 |
| Setelah enroll berhasil, status menjadi `ENROLLED` dan `converted_student_id` terisi | PPDB-05 AC-3 |
| Hanya Admin Sekolah dan Super Admin yang dapat memproses pendaftar | PRD §8.2 |

#### Notes

- Meskipun form bersifat publik, data tetap terikat pada `school_id` cabang yang dipilih (CON-14).
- Batas ukuran dan format berkas dokumen PPDB: **Belum dijelaskan dalam blueprint.**
- Perilaku bila pendaftar mendaftar dua kali, pembatalan pendaftaran, dan penanganan kuota penuh: **Belum dijelaskan dalam blueprint.**
- Skema validasi isi kolom `documents`: **Belum dijelaskan dalam blueprint.**

---

### 5.13 `grades`

**Tujuan:** Nilai siswa per komponen penilaian per mata pelajaran. Satu baris = satu entri nilai dari satu guru.

#### Kolom

| Column | Type | Nullable | Default | PK | FK | Index | Keterangan |
|---|---|:---:|---|:---:|:---:|:---:|---|
| `id` | `BIGINT UNSIGNED` | NO | auto | ✔ | — | PK | |
| `school_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `schools.id` |
| `student_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `students.id` |
| `class_subject_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `class_subjects.id` |
| `academic_year_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `academic_years.id` |
| `grade_type` | `ENUM('DAILY','MIDTERM','FINAL','ASSIGNMENT','SKILL','ATTITUDE')` | NO | — | — | — | IX | Komponen penilaian |
| `score` | `DECIMAL(5,2)` | NO | — | — | — | — | Nilai skala 0.00 – 100.00 |
| `weight` | `DECIMAL(4,2)` | YES | — | — | — | — | Bobot komponen (mis. 0.40 = 40%). Nullable jika mengacu `grade_configs` |
| `description` | `VARCHAR(200)` | YES | — | — | — | — | Keterangan (mis. "Ulangan Harian Bab 3") |
| `graded_by` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `users.id` (guru yang menginput) |
| `graded_at` | `TIMESTAMP` | NO | — | — | — | — | Waktu nilai diinput |
| `created_at` | `TIMESTAMP` | YES | — | — | — | — | |
| `updated_at` | `TIMESTAMP` | YES | — | — | — | — | |

#### Relationship

| Relasi | Kardinalitas | Tabel Tujuan |
|---|:---:|---|
| Milik satu cabang | `* ---- 1` | `schools` |
| Milik satu siswa | `* ---- 1` | `students` |
| Berasal dari satu penetapan pengampu | `* ---- 1` | `class_subjects` |
| Berada pada satu tahun ajaran | `* ---- 1` | `academic_years` |
| Diinput satu guru | `* ---- 1` | `users` (via `graded_by`) |

#### Validation Rules

| Aturan | Sumber |
|---|---|
| Nilai dalam **skala 0–100** | NILAI-01 AC-1; CON-39 |
| Guru hanya dapat menginput nilai untuk kelas yang diampunya | `POST /grades`; PRD §8.3 |
| Nilai dapat diedit **hanya selama rapor belum diterbitkan** | NILAI-01 AC-3; CON-40 |

#### Business Rules

| Aturan | Sumber |
|---|---|
| Input dapat dilakukan satu per satu, massal per `class_subject`, atau melalui **import Excel** | NILAI-01 AC-2; `POST /grades/bulk`, `POST /grades/import` |
| Nilai **langsung terlihat** siswa dan orang tua setelah guru menyimpan | NILAI-04 AC-1 |
| Setelah rapor di-publish, nilai **terkunci permanen** | NILAI-03 AC-2; CON-40 |
| Nilai akhir dihitung otomatis: `(Harian × bobot) + (UTS × bobot) + (UAS × bobot)`, dibulatkan 2 desimal | NILAI-02 |

#### Notes

- **⚠ Presedensi bobot:** kolom `weight` nullable dengan keterangan *"nullable jika mengacu `grade_configs`"*. Mana yang berlaku ketika keduanya terisi: **Belum dijelaskan dalam blueprint.** (PRD §17.3 #7; Roadmap RSK-07)
- Tabel menyimpan `academic_year_id` sendiri meskipun dapat diturunkan melalui `class_subjects` — denormalisasi disengaja (ERD §7.3).
- Apakah satu siswa boleh memiliki lebih dari satu nilai pada `grade_type` yang sama untuk satu `class_subject` (mis. beberapa ulangan harian): kolom `description` mengindikasikan ya, namun aturannya tidak dinyatakan eksplisit. → **Belum dijelaskan dalam blueprint.**

---

### 5.14 `report_cards`

**Tujuan:** Rapor final per siswa per semester. Dibuat oleh Wali Kelas. Setelah dipublikasikan, data terkunci.

#### Kolom

| Column | Type | Nullable | Default | PK | FK | Index | Keterangan |
|---|---|:---:|---|:---:|:---:|:---:|---|
| `id` | `BIGINT UNSIGNED` | NO | auto | ✔ | — | PK | |
| `school_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `schools.id` |
| `student_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `students.id` |
| `class_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `classes.id` |
| `academic_year_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `academic_years.id` |
| `final_scores` | `JSON` | NO | — | — | — | — | Nilai akhir per mapel: `{"MTK": 87.5, "BIN": 90, …}` |
| `attitude_score` | `ENUM('A','B','C','D')` | YES | — | — | — | — | Nilai sikap |
| `attend_present` | `SMALLINT` | YES | — | — | — | — | Jumlah hadir |
| `attend_sick` | `SMALLINT` | YES | — | — | — | — | Jumlah sakit |
| `attend_permission` | `SMALLINT` | YES | — | — | — | — | Jumlah izin |
| `attend_absent` | `SMALLINT` | YES | — | — | — | — | Jumlah alpa |
| `rank_in_class` | `SMALLINT` | YES | — | — | — | — | Peringkat di kelas |
| `homeroom_notes` | `TEXT` | YES | — | — | — | — | Catatan wali kelas |
| `is_published` | `TINYINT(1)` | NO | `0` | — | — | IX | 0 = Draft, 1 = Published |
| `published_at` | `TIMESTAMP` | YES | — | — | — | — | Waktu rapor diterbitkan |
| `published_by` | `BIGINT UNSIGNED` | YES | — | — | ✔ | — | → `users.id` (wali kelas yang menerbitkan) |
| `created_at` | `TIMESTAMP` | YES | — | — | — | — | |
| `updated_at` | `TIMESTAMP` | YES | — | — | — | — | |

#### Relationship

| Relasi | Kardinalitas | Tabel Tujuan |
|---|:---:|---|
| Milik satu cabang | `* ---- 1` | `schools` |
| Milik satu siswa | `* ---- 1` | `students` |
| Berada pada satu kelas | `* ---- 1` | `classes` |
| Berada pada satu tahun ajaran | `* ---- 1` | `academic_years` |
| Diterbitkan satu wali kelas | `* ---- 0..1` | `users` (via `published_by`) |
| **Tidak ada relasi FK** ke `subjects` | — | Nilai per mapel disimpan sebagai JSON |

#### Validation Rules

| Aturan | Sumber |
|---|---|
| Sebelum publish, sistem memvalidasi **semua mata pelajaran sudah memiliki nilai akhir** | NILAI-03 AC-1; CON-41 |
| Generate dan publish hanya oleh **Wali Kelas** | NILAI-03; `POST /report-cards/generate` |

#### Business Rules

| Aturan | Sumber |
|---|---|
| Setelah publish, **nilai terkunci** dan tidak dapat diedit | NILAI-03 AC-2; CON-40 |
| Setelah publish, rapor tersedia di Portal Siswa dan Parent Portal | NILAI-03 AC-3 |
| Publish **memicu notifikasi otomatis** ke orang tua | NOTIF-03 AC-1 |
| Rapor dapat diunduh dalam **format PDF** | NILAI-04 AC-3; `GET /report-cards/{id}/pdf` |
| Rapor final hanya tampil setelah Wali Kelas menerbitkan | NILAI-04 AC-2 |
| Siswa dan Orang Tua memiliki akses ⭕ (baca saja) | PRD §8.2 |

#### Notes

- **⚠ Sumber data kehadiran:** empat kolom `attend_*` membutuhkan data absensi, sementara **tidak ada tabel absensi** dalam 21 entitas dan modul Presensi Digital berada di Phase 2. → **Belum dijelaskan dalam blueprint.** (PRD §17.3 #1; Roadmap RSK-01)
- **⚠ Peringkat kelas:** rumus perhitungan dan aturan tie-break `rank_in_class`: **Belum dijelaskan dalam blueprint.** (PRD §17.3 #13)
- **⚠ Koreksi rapor:** tidak ada mekanisme unpublish atau koreksi setelah nilai terkunci. → **Belum dijelaskan dalam blueprint.** (PRD §17.3 #8)
- Penyimpanan nilai akhir sebagai JSON menyulitkan agregasi lintas siswa untuk pelaporan — tercatat sebagai RSK-22 pada Roadmap.
- Generate PDF dijalankan melalui Laravel Queue (Blueprint §3.1).

---

### 5.15 `grade_configs`

**Tujuan:** Konfigurasi bobot komponen penilaian per mata pelajaran per tahun ajaran.

#### Kolom

| Column | Type | Nullable | Default | PK | FK | Index | Keterangan |
|---|---|:---:|---|:---:|:---:|:---:|---|
| `id` | `BIGINT UNSIGNED` | NO | auto | ✔ | — | PK | |
| `school_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `schools.id` |
| `subject_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `subjects.id` |
| `academic_year_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `academic_years.id` |
| `components` | `JSON` | NO | — | — | — | — | Definisi komponen & bobot: `[{"type":"DAILY","weight":0.40},{"type":"MIDTERM","weight":0.30},…]` |
| `created_by` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `users.id` |
| `created_at` | `TIMESTAMP` | YES | — | — | — | — | **Tidak ada `updated_at`** |

#### Relationship

| Relasi | Kardinalitas | Tabel Tujuan |
|---|:---:|---|
| Milik satu cabang | `* ---- 1` | `schools` |
| Mengonfigurasi satu mata pelajaran | `* ---- 1` | `subjects` |
| Berlaku pada satu tahun ajaran | `* ---- 1` | `academic_years` |
| Dibuat satu pengguna | `* ---- 1` | `users` (via `created_by`) |

#### Validation Rules

| Aturan | Sumber |
|---|---|
| Konfigurasi **dapat berbeda antar mata pelajaran** | NILAI-05 AC-1 |
| Contoh bobot: Harian 40%, UTS 30%, UAS 30% | NILAI-02 AC-1 |

#### Business Rules

| Aturan | Sumber |
|---|---|
| Perubahan konfigurasi **hanya berlaku untuk tahun ajaran baru** | NILAI-05 AC-2; CON-42 |
| Hanya Admin Sekolah dan Super Admin yang dapat mengatur konfigurasi | `POST /grade-configs` — Auth Level: Admin |
| Nilai akhir dihitung berdasarkan bobot yang dikonfigurasi di sini | NILAI-02 AC-2 |

#### Notes

- **⚠ Presedensi bobot:** kolom `weight` pada tabel `grades` dapat memuat bobot yang berbeda dari konfigurasi di sini. Mana yang berlaku: **Belum dijelaskan dalam blueprint.** (PRD §17.3 #7)
- Apakah total bobot dalam `components` divalidasi harus berjumlah 1.00 (100%): **Belum dijelaskan dalam blueprint.**
- Apakah satu kombinasi `subject_id` + `academic_year_id` hanya boleh memiliki satu konfigurasi (unique composite): **Belum dijelaskan dalam blueprint.**
- Tabel ini **tidak memiliki `updated_at`** — padahal NILAI-05 membahas perubahan konfigurasi.

---

### 5.16 `fee_types`

**Tujuan:** Master jenis tagihan yang dapat dibuat Bendahara. Setiap jenis dapat memiliki frekuensi dan nominal berbeda.

#### Kolom

| Column | Type | Nullable | Default | PK | FK | Index | Keterangan |
|---|---|:---:|---|:---:|:---:|:---:|---|
| `id` | `BIGINT UNSIGNED` | NO | auto | ✔ | — | PK | |
| `school_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `schools.id` |
| `name` | `VARCHAR(100)` | NO | — | — | — | — | Nama tagihan: SPP, Uang Gedung, Kegiatan OSIS |
| `amount` | `DECIMAL(12,2)` | NO | — | — | — | — | Nominal tagihan dalam Rupiah |
| `frequency` | `ENUM('MONTHLY','YEARLY','ONCE')` | NO | — | — | — | — | Frekuensi penerbitan |
| `academic_year_id` | `BIGINT UNSIGNED` | YES | — | — | ✔ | — | → `academic_years.id`. `NULL` untuk tagihan berulang |
| `description` | `TEXT` | YES | — | — | — | — | Keterangan tambahan |
| `is_active` | `TINYINT(1)` | NO | `1` | — | — | — | Status aktif |
| `created_at` | `TIMESTAMP` | YES | — | — | — | — | **Tidak ada `updated_at`** |

#### Relationship

| Relasi | Kardinalitas | Tabel Tujuan |
|---|:---:|---|
| Milik satu cabang | `* ---- 1` | `schools` |
| Berlaku pada satu tahun ajaran | `* ---- 0..1` | `academic_years` (nullable) |
| Menghasilkan tagihan siswa | `1 ---- *` | `student_fees` |

#### Validation Rules

| Aturan | Sumber |
|---|---|
| Field wajib: nama tagihan, jumlah (Rupiah), frekuensi | SPP-01 AC-1 |
| `frequency` hanya bernilai `MONTHLY`, `YEARLY`, atau `ONCE` | Blueprint §2.2 |

#### Business Rules

| Aturan | Sumber |
|---|---|
| Jenis tagihan dapat **dinonaktifkan tanpa menghapus histori** | SPP-01 AC-2; CON-45 |
| Hanya Bendahara, Admin Sekolah, dan Super Admin yang dapat mengelola | PRD §8.2 — Tagihan SPP ✅ |
| `academic_year_id` bernilai `NULL` untuk tagihan berulang yang tidak terikat periode | Blueprint §2.2 |

#### Notes

- **⚠ Perilaku frekuensi:** SPP-02 hanya menjelaskan generate tagihan **bulanan**. Perilaku untuk `YEARLY` dan `ONCE`: **Belum dijelaskan dalam blueprint.** (PRD §17.3 #9; Roadmap RSK-10)
- Tabel ini **tidak memiliki `updated_at`** — padahal SPP-01 membahas penonaktifan jenis tagihan.

---

### 5.17 `student_fees`

**Tujuan:** Tagihan per siswa per periode. Di-generate secara massal oleh Bendahara.

#### Kolom

| Column | Type | Nullable | Default | PK | FK | Index | Keterangan |
|---|---|:---:|---|:---:|:---:|:---:|---|
| `id` | `BIGINT UNSIGNED` | NO | auto | ✔ | — | PK | |
| `school_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `schools.id` |
| `student_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `students.id` |
| `fee_type_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `fee_types.id` |
| `academic_year_id` | `BIGINT UNSIGNED` | YES | — | — | ✔ | — | → `academic_years.id` |
| `amount` | `DECIMAL(12,2)` | NO | — | — | — | — | Nominal tagihan |
| `amount_paid` | `DECIMAL(12,2)` | NO | `0.00` | — | — | — | Total yang sudah dibayar |
| `due_date` | `DATE` | NO | — | — | — | — | Batas waktu pembayaran |
| `period` | `VARCHAR(7)` | NO | — | — | — | IX | Periode format `YYYY-MM` (mis. `2025-08`) |
| `status` | `ENUM('UNPAID','PARTIAL','PAID','WAIVED')` | NO | `UNPAID` | — | — | IX | Status tagihan |
| `waive_reason` | `VARCHAR(200)` | YES | — | — | — | — | Alasan dibebaskan (jika status `WAIVED`) |
| `created_at` | `TIMESTAMP` | YES | — | — | — | — | |
| `updated_at` | `TIMESTAMP` | YES | — | — | — | — | |

#### Relationship

| Relasi | Kardinalitas | Tabel Tujuan |
|---|:---:|---|
| Milik satu cabang | `* ---- 1` | `schools` |
| Ditanggung satu siswa | `* ---- 1` | `students` |
| Berasal dari satu jenis tagihan | `* ---- 1` | `fee_types` |
| Berada pada satu tahun ajaran | `* ---- 0..1` | `academic_years` (nullable) |
| Dilunasi melalui pembayaran | `1 ---- *` | `payments` |

#### Validation Rules

| Aturan | Sumber |
|---|---|
| `period` berformat `YYYY-MM` | Blueprint §2.2 |
| Pembebasan tagihan **wajib disertai alasan** | `PATCH /student-fees/{id}/waive`; Blueprint §2.2 |

#### Business Rules

| Aturan | Sumber |
|---|---|
| Generate massal membuat record untuk **setiap siswa aktif** | SPP-02 AC-1 |
| `due_date` diisi otomatis (mis. tanggal 10 bulan berjalan) | SPP-02 AC-2 |
| **Preview daftar tagihan wajib ditampilkan sebelum konfirmasi generate** | SPP-02 AC-3; CON-47 |
| Status berubah otomatis mengikuti akumulasi `amount_paid`: `PARTIAL` bila sebagian, `PAID` bila lunas | SPP-03 AC-3 |
| Pembebasan tagihan mengubah status menjadi `WAIVED` | `PATCH /student-fees/{id}/waive` |
| Pembebasan hanya oleh Admin (Auth Level: Admin), **bukan Bendahara** | Blueprint §4.9.1 |
| Penerbitan tagihan **memicu notifikasi otomatis** | NOTIF-03 AC-1 |
| Orang tua melihat tagihan anaknya per periode; tagihan belum lunas ditandai merah/warning | SPP-04 AC-1, AC-2 |
| Orang tua **hanya** dapat melihat tagihan anaknya sendiri | `GET /students/{id}/fees`; PRD §8.3 |
| Siswa **tidak memiliki akses** ke modul tagihan | PRD §8.2 — Tagihan SPP ❌ untuk SISWA |

#### Notes

- Job generate massal dijalankan melalui Laravel Queue agar tidak timeout (Blueprint §3.1; §3.3.1).
- Apakah satu kombinasi `student_id` + `fee_type_id` + `period` hanya boleh muncul sekali (unique composite): **Belum dijelaskan dalam blueprint.**
- Alur penanganan tagihan yang lewat jatuh tempo (reminder tunggakan): **Belum dijelaskan dalam blueprint.**
- Apakah `amount` dapat berbeda dari `fee_types.amount` (mis. keringanan sebagian): **Belum dijelaskan dalam blueprint.**

---

### 5.18 `payments`

**Tujuan:** Riwayat setiap transaksi pembayaran tagihan. Satu tagihan dapat memiliki beberapa pembayaran (cicilan).

#### Kolom

| Column | Type | Nullable | Default | PK | FK | Index | Keterangan |
|---|---|:---:|---|:---:|:---:|:---:|---|
| `id` | `BIGINT UNSIGNED` | NO | auto | ✔ | — | PK | |
| `school_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `schools.id` |
| `student_fee_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `student_fees.id` |
| `student_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `students.id` — **denormalisasi untuk query cepat** |
| `payment_method` | `ENUM('CASH','TRANSFER','PAYMENT_GATEWAY')` | NO | — | — | — | — | Metode pembayaran |
| `amount_paid` | `DECIMAL(12,2)` | NO | — | — | — | — | Jumlah yang dibayarkan |
| `reference_number` | `VARCHAR(100)` | YES | — | — | — | — | Nomor referensi transfer / kwitansi |
| `proof_url` | `VARCHAR(500)` | YES | — | — | — | — | Path bukti pembayaran yang diunggah |
| `payment_date` | `DATE` | NO | — | — | — | — | Tanggal pembayaran |
| `received_by` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `users.id` (bendahara yang mencatat) |
| `notes` | `TEXT` | YES | — | — | — | — | Catatan tambahan |
| `created_at` | `TIMESTAMP` | YES | — | — | — | — | **Tidak ada `updated_at`** |

#### Relationship

| Relasi | Kardinalitas | Tabel Tujuan |
|---|:---:|---|
| Milik satu cabang | `* ---- 1` | `schools` |
| Melunasi satu tagihan | `* ---- 1` | `student_fees` |
| Merujuk satu siswa | `* ---- 1` | `students` (denormalisasi) |
| Dicatat satu bendahara | `* ---- 1` | `users` (via `received_by`) |
| **Tidak ada relasi** ke `transactions` | — | Dua jalur pencatatan keuangan yang terpisah |

#### Validation Rules

| Aturan | Sumber |
|---|---|
| Field wajib: nama siswa, periode, metode bayar, jumlah, tanggal, referensi | SPP-03 AC-1 |
| Bukti pembayaran: format **JPG/PNG/PDF, maksimal 5 MB** | SPP-03 AC-2; CON-44 |
| Berkas disimpan di `storage/` — **di luar web root** | Blueprint §3.4; CON-31 |

#### Business Rules

| Aturan | Sumber |
|---|---|
| Pencatatan dilakukan **manual** oleh Bendahara — tidak ada payment gateway pada Phase 1 | SPP-03; CON-50 |
| Satu tagihan dapat menerima **beberapa pembayaran** (cicilan) | Blueprint §2.2 |
| Status tagihan berubah otomatis ke `PAID` atau `PARTIAL` | SPP-03 AC-3 |
| Hanya Bendahara, Admin Sekolah, dan Super Admin yang dapat mencatat pembayaran | PRD §8.2 — Catat Pembayaran ✅ |
| Kepala Sekolah **tidak dapat** mencatat pembayaran | PRD §8.2 — ❌ |

#### Notes

- Kolom `student_id` **sengaja diduplikasi** — blueprint menyebutnya *"denormalized untuk query cepat"* (ERD §7.3).
- Nilai ENUM `PAYMENT_GATEWAY` sudah tersedia, namun integrasinya baru pada **Phase 2**.
- Alur konfirmasi atau unggah bukti bayar **oleh orang tua**: **Belum dijelaskan dalam blueprint** — blueprint hanya menyebut Bendahara yang mengunggah bukti.
- Pembatalan atau koreksi pembayaran yang salah catat: **Belum dijelaskan dalam blueprint.**
- Tabel ini **tidak memiliki `updated_at`** — koreksi pembayaran tidak meninggalkan jejak waktu pada tingkat baris.

---

### 5.19 `transactions`

**Tujuan:** Buku kas sekolah. Mencatat semua pemasukan dan pengeluaran umum **di luar** tagihan SPP.

#### Kolom

| Column | Type | Nullable | Default | PK | FK | Index | Keterangan |
|---|---|:---:|---|:---:|:---:|:---:|---|
| `id` | `BIGINT UNSIGNED` | NO | auto | ✔ | — | PK | |
| `school_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `schools.id` |
| `type` | `ENUM('INCOME','EXPENSE')` | NO | — | — | — | IX | Jenis transaksi |
| `category` | `VARCHAR(100)` | NO | — | — | — | — | Kategori: Gaji, Pembelian Alat, Dana BOS, Sumbangan |
| `amount` | `DECIMAL(12,2)` | NO | — | — | — | — | Jumlah dalam Rupiah |
| `description` | `TEXT` | YES | — | — | — | — | Keterangan detail transaksi |
| `reference_number` | `VARCHAR(100)` | YES | — | — | — | — | Nomor nota / referensi |
| `proof_url` | `VARCHAR(500)` | YES | — | — | — | — | Path scan nota / bukti |
| `transaction_date` | `DATE` | NO | — | — | — | — | Tanggal transaksi |
| `created_by` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `users.id` |
| `created_at` | `TIMESTAMP` | YES | — | — | — | — | **Tidak ada `updated_at`** |

#### Relationship

| Relasi | Kardinalitas | Tabel Tujuan |
|---|:---:|---|
| Milik satu cabang | `* ---- 1` | `schools` |
| Dicatat satu pengguna | `* ---- 1` | `users` (via `created_by`) |
| **Tidak ada relasi** ke `payments` | — | Penerimaan SPP dan buku kas umum adalah dua jalur terpisah |

#### Validation Rules

| Aturan | Sumber |
|---|---|
| Field wajib: jenis (`INCOME`/`EXPENSE`), kategori, jumlah, tanggal, keterangan, nomor referensi | KAS-01 AC-1 |
| Bukti dapat dilampirkan berupa scan nota/kwitansi | KAS-01 AC-2 |

#### Business Rules

| Aturan | Sumber |
|---|---|
| Hanya Bendahara, Admin Sekolah, dan Super Admin yang dapat mencatat | PRD §8.2 — Akuntansi & Kas ✅ |
| Kepala Sekolah memiliki akses ⭕ (baca saja) | PRD §8.2 |
| Data ini menjadi sumber saldo kas dan grafik tren 6 bulan | KAS-02 AC-1, AC-2 |
| Data ini menjadi sumber dashboard keuangan lintas cabang Super Admin | KAS-03 |

#### Notes

- **⚠ Soft delete tanpa kolom:** API Map menyebut `DELETE /transactions/{id}` sebagai *"Hapus transaksi (soft delete)"*, namun tabel ini **tidak memiliki `deleted_at` maupun kolom status**. Mekanismenya: **Belum dijelaskan dalam blueprint.**
- Cara merekonsiliasi penerimaan SPP (`payments`) dengan buku kas umum (`transactions`) pada laporan keuangan: **Belum dijelaskan dalam blueprint.**
- Daftar kategori yang diizinkan bersifat bebas (`VARCHAR`), bukan ENUM — tidak ada pembatasan skema.
- Tabel ini **tidak memiliki `updated_at`** — padahal `PUT /transactions/{id}` tersedia untuk mengedit transaksi.

---

### 5.20 `notifications`

**Tujuan:** Pengumuman dan notifikasi per cabang, dibuat Admin atau dipicu otomatis oleh sistem.

#### Kolom

| Column | Type | Nullable | Default | PK | FK | Index | Keterangan |
|---|---|:---:|---|:---:|:---:|:---:|---|
| `id` | `BIGINT UNSIGNED` | NO | auto | ✔ | — | PK | |
| `school_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `schools.id` |
| `sender_id` | `BIGINT UNSIGNED` | YES | — | — | ✔ | — | → `users.id`. **`NULL` jika notifikasi sistem otomatis** |
| `title` | `VARCHAR(200)` | NO | — | — | — | — | Judul notifikasi |
| `message` | `TEXT` | NO | — | — | — | — | Isi pesan lengkap |
| `type` | `ENUM('ANNOUNCEMENT','BILLING','ACADEMIC','EMERGENCY','SYSTEM')` | NO | — | — | — | IX | Kategori notifikasi |
| `target_type` | `ENUM('ALL','CLASS','INDIVIDUAL')` | NO | — | — | — | — | Target penerima |
| `target_id` | `BIGINT UNSIGNED` | YES | — | — | **✘** | — | `class_id` atau `user_id` jika bukan `ALL` — **tidak ditandai FK** |
| `wa_template` | `TEXT` | YES | — | — | — | — | Template teks WA yang sudah diisi variabel |
| `is_draft` | `TINYINT(1)` | NO | `1` | — | — | — | 1 = draft, 0 = terkirim |
| `sent_at` | `TIMESTAMP` | YES | — | — | — | — | Waktu notifikasi diterbitkan |
| `created_at` | `TIMESTAMP` | YES | — | — | — | — | |
| `updated_at` | `TIMESTAMP` | YES | — | — | — | — | |

#### Relationship

| Relasi | Kardinalitas | Tabel Tujuan |
|---|:---:|---|
| Milik satu cabang | `* ---- 1` | `schools` |
| Dikirim satu pengguna | `* ---- 0..1` | `users` (via `sender_id`) |
| Dibaca banyak pengguna | `1 ---- *` | `notification_reads` |
| Menyasar kelas atau pengguna | `1 ---- 0..1` | `classes` **atau** `users` via `target_id` — **tanpa FK** |

#### Validation Rules

| Aturan | Sumber |
|---|---|
| Field wajib: judul, isi pesan, target, kategori | NOTIF-01 AC-1 |
| URL wa.me dibentuk sebagai `wa.me/62[nomorHP]?text=[pesan_ter-encode]` | NOTIF-02 AC-1 |

#### Business Rules

| Aturan | Sumber |
|---|---|
| Target `ALL` mengirim ke **semua user aktif di cabang** | NOTIF-01 AC-2 |
| Target `CLASS` mengirim **hanya kepada orang tua siswa kelas tersebut** | NOTIF-01 AC-3 |
| Trigger otomatis tersedia untuk **tiga event**: perubahan status PPDB, penerbitan tagihan, penerbitan rapor | NOTIF-03 AC-1 |
| Template teks dapat diedit Admin Sekolah dan tersimpan di `schools.wa_template_*` | NOTIF-03 AC-2 |
| Notifikasi muncul di **notification center in-app** dan menyediakan **wa.me link** | NOTIF-03 AC-3 |
| Pengiriman WhatsApp dilakukan **manual** oleh Admin — sistem hanya menyiapkan link | NOTIF-02 AC-3; CON-49 |
| Riwayat notifikasi disimpan **90 hari** | NOTIF-04 AC-3; CON-46 |
| Hanya Admin Sekolah, Kepala Sekolah, dan Super Admin yang dapat membuat notifikasi | PRD §8.2 — Notifikasi (buat) ✅ |

#### Notes

- **⚠ `target_id` tanpa foreign key:** integritas referensial ke `classes` maupun `users` **tidak dijamin skema** — harus divalidasi di lapisan aplikasi (ERD §5.11).
- **⚠ Perbedaan daftar kategori:** NOTIF-01 AC-1 menyebut `ACADEMIC`/`BILLING`/`EMERGENCY`/**`GENERAL`**, sedangkan ENUM memuat `ANNOUNCEMENT`/`BILLING`/`ACADEMIC`/`EMERGENCY`/`SYSTEM`. Nilai `GENERAL` tidak ada dalam ENUM.
- **⚠ Kewenangan guru:** matriks izin menyatakan GURU/WALI ❌ pada "Notifikasi (buat)", sementara PORTAL-02 menyediakan shortcut "Buat Pengumuman". (PRD §17.3 #3)
- Mekanisme penerapan retensi 90 hari (job terjadwal atau lainnya): **Belum dijelaskan dalam blueprint.**
- Aturan normalisasi nomor HP ke awalan `62`: **Belum dijelaskan dalam blueprint.**

---

### 5.21 `notification_reads`

**Tujuan:** Tabel pivot yang merekam siapa saja yang sudah membaca notifikasi tertentu.

#### Kolom

| Column | Type | Nullable | Default | PK | FK | Index | Keterangan |
|---|---|:---:|---|:---:|:---:|:---:|---|
| `id` | `BIGINT UNSIGNED` | NO | auto | ✔ | — | PK | |
| `notification_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `notifications.id` |
| `user_id` | `BIGINT UNSIGNED` | NO | — | — | ✔ | — | → `users.id` |
| `read_at` | `TIMESTAMP` | NO | — | — | — | — | Waktu pertama kali dibaca |

**Tabel dengan kolom paling sedikit (4)** dan **satu-satunya tabel tanpa `created_at`**.

#### Relationship

| Relasi | Kardinalitas | Tabel Tujuan |
|---|:---:|---|
| Merujuk notifikasi | `* ---- 1` | `notifications` |
| Merujuk pengguna | `* ---- 1` | `users` |
| Mewujudkan relasi many-to-many | `notifications * ---- * users` | status baca |

#### Validation Rules

**Belum dijelaskan dalam blueprint** secara eksplisit.

#### Business Rules

| Aturan | Sumber |
|---|---|
| Jumlah notifikasi belum dibaca tampil di **badge (bell icon)** | NOTIF-04 AC-1; `GET /notifications/unread-count` |
| Klik notifikasi menandainya sebagai "dibaca" | NOTIF-04 AC-2; `PATCH /notifications/{id}/read` |
| Tersedia aksi menandai semua sebagai dibaca | `POST /notifications/mark-all-read` |
| Riwayat notifikasi disimpan **90 hari** | NOTIF-04 AC-3; CON-46 |

#### Notes

- **⚠ Tanpa `school_id`:** ini adalah satu-satunya tabel pivot bisnis yang **tidak memiliki kunci isolasi tenant**. Isolasinya bergantung pada JOIN ke `notifications`. Cara penerapan Global Scope pada tabel ini: **Belum dijelaskan dalam blueprint.** (ERD §8.4)
- **⚠ Tanpa timestamps standar:** hanya memiliki `read_at`, tanpa `created_at` maupun `updated_at`.
- Apakah satu kombinasi `notification_id` + `user_id` hanya boleh muncul sekali (unique composite): **Belum dijelaskan dalam blueprint.** Kolom `read_at` bertipe NOT NULL mengindikasikan baris hanya dibuat saat notifikasi dibaca.

---

### 5.22 Ringkasan Spesifikasi Tabel

| # | Tabel | Jumlah Kolom | FK | `school_id` | UQ | IX | ENUM | JSON | Timestamps |
|:---:|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|---|
| 1 | `schools` | 17 | 0 | — | 2 | 1 | 0 | 0 | created + updated |
| 2 | `users` | 14 | 1 | ✔ | 1 | 1 | 0 | 0 | created + updated |
| 3 | `roles` | ⚠ | ⚠ | ✘ | ⚠ | ⚠ | ⚠ | ⚠ | ⚠ |
| 4 | `model_has_roles` | ⚠ | 2 | ✘ | ⚠ | ⚠ | ⚠ | ⚠ | ⚠ |
| 5 | `students` | 21 | 3 | ✔ | 0 | 4 | 2 | 0 | created + updated |
| 6 | `academic_years` | 9 | 1 | ✔ | 0 | 1 | 0 | 0 | created + updated |
| 7 | `classes` | 10 | 3 | ✔ | 0 | 0 | 0 | 0 | created + updated |
| 8 | `student_classes` | 7 | 4 | ✔ | 0 | 0 | 1 | 0 | created saja |
| 9 | `subjects` | 8 | 1 | ✔ | 0 | 0 | 0 | 0 | created saja |
| 10 | `class_subjects` | 7 | 5 | ✔ | 0 | 0 | 0 | 0 | created saja |
| 11 | `schedules` | 8 | 2 | ✔ | 0 | 1 | 0 | 0 | created saja |
| 12 | `ppdb_registrations` | 18 | 3 | ✔ | 1 | 1 | 2 | 1 | created + updated |
| 13 | `grades` | 13 | 5 | ✔ | 0 | 1 | 1 | 0 | created + updated |
| 14 | `report_cards` | 18 | 5 | ✔ | 0 | 1 | 1 | 1 | created + updated |
| 15 | `grade_configs` | 7 | 4 | ✔ | 0 | 0 | 0 | 1 | created saja |
| 16 | `fee_types` | 9 | 2 | ✔ | 0 | 0 | 1 | 0 | created saja |
| 17 | `student_fees` | 13 | 4 | ✔ | 0 | 2 | 1 | 0 | created + updated |
| 18 | `payments` | 12 | 4 | ✔ | 0 | 0 | 1 | 0 | created saja |
| 19 | `transactions` | 11 | 2 | ✔ | 0 | 1 | 1 | 0 | created saja |
| 20 | `notifications` | 13 | 2 | ✔ | 0 | 1 | 2 | 0 | created + updated |
| 21 | `notification_reads` | 4 | 2 | ✘ | 0 | 0 | 0 | 0 | `read_at` saja |

**Total (19 tabel yang terdefinisi):** 219 kolom · 53 FK · 4 UQ · 15 IX · 13 ENUM · 3 JSON

---

## 6. Index Strategy

### 6.1 Primary Index

| Aspek | Ketentuan |
|---|---|
| Kolom | `id` pada seluruh tabel yang terdefinisi |
| Tipe | `BIGINT UNSIGNED`, auto-increment |
| Jenis | **Surrogate key tunggal** — tidak ada composite primary key |
| Jumlah | 19 tabel (definisi `roles` dan `model_has_roles` tidak tersedia) |
| Alasan | Konvensi Laravel Eloquent; relasi seragam dan sederhana (ERD §7.4) |

### 6.2 Foreign Index

Blueprint menandai **53 kolom foreign key** pada 19 tabel terdefinisi, namun **tidak menandai satu pun di antaranya dengan `IX`**.

| Aspek | Status |
|---|---|
| Apakah kolom FK otomatis diberi index | **Belum dijelaskan dalam blueprint.** |
| Jumlah kolom FK | 53 |
| Kolom FK terbanyak per tabel | `class_subjects`, `grades`, `report_cards` — masing-masing 5 |

**Sebaran kolom FK menurut tabel tujuan:**

| Tabel Tujuan | Jumlah kolom yang merujuk | Kolom |
|---|:---:|---|
| `schools` | 17 | `school_id` pada seluruh tabel bisnis |
| `users` | 11 | `students.user_id`, `students.parent_user_id`, `classes.homeroom_teacher_id`, `class_subjects.teacher_id`, `grades.graded_by`, `report_cards.published_by`, `grade_configs.created_by`, `payments.received_by`, `transactions.created_by`, `notifications.sender_id`, `notification_reads.user_id` |
| `academic_years` | 9 | pada `classes`, `student_classes`, `class_subjects`, `ppdb_registrations`, `grades`, `report_cards`, `grade_configs`, `fee_types`, `student_fees` |
| `students` | 5 | `student_classes`, `grades`, `report_cards`, `student_fees`, `payments` |
| `classes` | 3 | `student_classes`, `class_subjects`, `report_cards` |
| `subjects` | 2 | `class_subjects`, `grade_configs` |
| `class_subjects` | 2 | `schedules`, `grades` |
| `fee_types` | 1 | `student_fees` |
| `student_fees` | 1 | `payments` |
| `notifications` | 1 | `notification_reads` |
| `ppdb_registrations` → `students` | 1 | `converted_student_id` |

> **Pertimbangan performa:** kolom `school_id` muncul pada **setiap query** karena Global Scope menambahkan `WHERE school_id = ...` secara otomatis (CON-15). Blueprint tidak menandainya sebagai index. Keputusan pengindeksan kolom ini: **Belum dijelaskan dalam blueprint.**

### 6.3 Composite Index

| Aspek | Status |
|---|---|
| Composite index | **Belum dijelaskan dalam blueprint.** |

**Kombinasi kolom yang sering dipakai bersama menurut blueprint** (fakta, bukan usulan indeks):

| Kombinasi | Sumber pemakaian |
|---|---|
| `school_id` + `status` pada `students` | Filter "siswa aktif per cabang" — SPP-02 (generate massal), SIS-04 |
| `school_id` + `is_active` pada `academic_years` | Pencarian tahun ajaran aktif — CON-37 |
| `student_id` + `academic_year_id` pada `student_classes` | Aturan 1 siswa = 1 kelas per TA — CON-35 |
| `homeroom_teacher_id` + `academic_year_id` pada `classes` | Aturan 1 guru = 1 wali kelas per TA — CON-36 |
| `school_id` + `nis` pada `students` | Keunikan NIS per sekolah — CON-38 |
| `class_subject_id` + `student_id` + `grade_type` pada `grades` | Pengambilan nilai per komponen |
| `student_id` + `period` + `status` pada `student_fees` | Daftar tagihan per periode — SPP-04, SPP-05 |
| `class_subject_id` + `day_of_week` pada `schedules` | Deteksi konflik jadwal — CON-48 |
| `notification_id` + `user_id` pada `notification_reads` | Status baca per pengguna |

### 6.4 Unique Index

Blueprint menandai **4 kolom** dengan `UQ`:

| Tabel | Kolom | Cakupan Keunikan | Sumber |
|---|---|---|---|
| `schools` | `code` | Lintas seluruh platform | Blueprint §2.2 |
| `schools` | `slug` | Lintas seluruh platform | Blueprint §2.2 |
| `users` | `email` | **Lintas seluruh platform**, bukan per cabang | Blueprint §2.2; ASM-12 |
| `ppdb_registrations` | `reg_number` | Lintas seluruh platform | Blueprint §2.2 |

**Keunikan yang disyaratkan aturan bisnis tetapi tidak ditandai `UQ`:**

| Aturan | Kolom terkait | Penandaan blueprint | Status |
|---|---|---|---|
| NIS unik **dalam satu sekolah** (SIS-01 AC-3; CON-38) | `students.nis` | Ditandai `IX`, bukan `UQ` | Penegakan keunikan komposit `school_id` + `nis`: **Belum dijelaskan dalam blueprint.** |
| Satu siswa = satu kelas per TA (CON-35) | `student_classes` | Tidak ada `UQ` | **Belum dijelaskan dalam blueprint** — ditegakkan di lapisan aplikasi |
| Satu guru = satu wali kelas per TA (CON-36) | `classes.homeroom_teacher_id` | Tidak ada `UQ` | **Belum dijelaskan dalam blueprint** — ditegakkan di lapisan aplikasi |
| Satu TA aktif per sekolah (CON-37) | `academic_years.is_active` | Ditandai `IX` | **Belum dijelaskan dalam blueprint** — ditegakkan di lapisan aplikasi |

### 6.5 Secondary Index

Blueprint menandai **15 kolom** dengan `IX`:

| Tabel | Kolom | Alasan penggunaan |
|---|---|---|
| `schools` | `is_active` | Filter cabang aktif |
| `users` | `is_active` | Filter pengguna aktif |
| `students` | `nis` | Pencarian siswa berdasarkan NIS |
| `students` | `nisn` | Pencarian siswa berdasarkan NISN |
| `students` | `parent_phone` | Pembentukan daftar wa.me link |
| `students` | `status` | Filter siswa aktif |
| `academic_years` | `is_active` | Pencarian tahun ajaran aktif |
| `schedules` | `day_of_week` | Tampilan jadwal harian dan mingguan |
| `ppdb_registrations` | `status` | Filter pendaftar per status — PPDB-03 AC-2 |
| `grades` | `grade_type` | Filter nilai per komponen |
| `report_cards` | `is_published` | Filter rapor terbit vs draft |
| `student_fees` | `period` | Filter tagihan per periode |
| `student_fees` | `status` | Filter tagihan per status |
| `transactions` | `type` | Filter pemasukan vs pengeluaran |
| `notifications` | `type` | Filter notifikasi per kategori |

### 6.6 Ringkasan Strategi Indeks

| Jenis Index | Jumlah | Status Definisi |
|---|:---:|---|
| Primary | 19 | ✔ Ditetapkan blueprint |
| Unique | 4 | ✔ Ditetapkan blueprint |
| Secondary | 15 | ✔ Ditetapkan blueprint |
| Foreign key index | 0 ditandai | **Belum dijelaskan dalam blueprint.** |
| Composite | 0 ditandai | **Belum dijelaskan dalam blueprint.** |
| JSON index | 0 ditandai | **Belum dijelaskan dalam blueprint.** |
| Konvensi penamaan index | — | **Belum dijelaskan dalam blueprint.** |

---

## 7. Multi Tenant Strategy

### 7.1 Penggunaan `school_id`

| Aspek | Ketentuan | Dasar |
|---|---|---|
| Tipe kolom | `BIGINT UNSIGNED` | Blueprint §2.2 |
| Referensi | → `schools.id` | Blueprint §2.2 |
| Nullability | **NOT NULL** pada 16 tabel bisnis · **nullable** hanya pada `users` | Blueprint §2.2 |
| Posisi | Kolom kedua setelah `id` pada seluruh tabel bisnis | Blueprint §2.2 |
| Kewajiban | **Wajib hadir di semua tabel bisnis** | Blueprint §3.2.1; CON-14 |

**Tabel yang memiliki `school_id` (17):**
`users` (nullable) · `students` · `academic_years` · `classes` · `student_classes` · `subjects` · `class_subjects` · `schedules` · `ppdb_registrations` · `grades` · `report_cards` · `grade_configs` · `fee_types` · `student_fees` · `payments` · `transactions` · `notifications`

**Tabel yang tidak memiliki `school_id` (4):**

| Tabel | Alasan | Cara isolasi |
|---|---|---|
| `schools` | Akar tenant itu sendiri | Melalui hak akses Super Admin |
| `roles` | Definisi peran bersifat **global** lintas cabang | Tidak memerlukan isolasi |
| `model_has_roles` | Pivot peran | Diturunkan melalui `users.school_id` |
| `notification_reads` | Pivot status baca | Diturunkan melalui `notification_id` → `notifications.school_id` |

### 7.2 Data Isolation

> *"Semua cabang menggunakan satu database dan satu set tabel yang sama. Isolasi data dilakukan melalui kolom `school_id` yang wajib hadir di semua tabel bisnis."* — Blueprint §3.2.1

| Aspek | Ketentuan | Dasar |
|---|---|---|
| Pola | Shared Database, Shared Schema | Blueprint §2.1; §3.2.1 |
| Target isolasi | **100%** — tidak ada kebocoran data antar tenant | NFR-06 |
| Toleransi | **Nol** | CON-26 |
| Kategori risiko | RSK-15 — zona **Kritis** meski skornya 8 | Roadmap §13.4 |

**Verifikasi wajib sebelum go-live:**

| # | Butir | Dasar |
|:---:|---|---|
| 1 | Unit test Global Scope (tenant isolation) lulus **100%** | Lampiran A.3 #1 |
| 2 | Uji akses lintas-tenant: user Madani tidak dapat melihat data Cinangka | Lampiran A.3 #2 |

### 7.3 Tenant Filtering

**Alur penerapan filter (Blueprint §3.2.2):**

```
1. Pengguna login → sistem lookup users.email → memperoleh users.school_id
        ↓
2. TenantMiddleware (spatie/laravel-multitenancy) menyimpan school_id
   ke context sesi
        ↓
3. Setiap query Eloquent otomatis mendapat tambahan kondisi:
   WHERE school_id = auth()->user()->school_id
        ↓
4. Pengguna hanya melihat data cabangnya sendiri
```

| Aspek | Ketentuan | Dasar |
|---|---|---|
| Mekanisme | Laravel Global Scope | CON-15 |
| Paket | `spatie/laravel-multitenancy` 3.x | Blueprint §3.1; CON-05 |
| Kewajiban | **Semua query aplikasi WAJIB** menggunakan Global Scope | Blueprint §2.1 Catatan |
| Larangan | Raw SQL dilarang kecuali `DB::select()` dengan binding | Blueprint §3.4; CON-10 |

**Pengecualian Super Admin:**

| Aspek | Ketentuan |
|---|---|
| Penanda | `users.school_id = NULL` |
| Perilaku | Global Scope **dilewati** — akses data seluruh tenant |
| Mekanisme | `if (auth()->user()->isSuperAdmin()) { return $query; } // skip scope` (Blueprint §3.2.2) |
| Kebutuhan | Dashboard lintas cabang (KAS-03) dan manajemen tenant |
| Risiko | Titik lemah keamanan — memerlukan test case khusus (Roadmap RSK-16) |

### 7.4 Titik Rawan Isolasi

| Titik Rawan | Penjelasan | Status |
|---|---|---|
| Model baru tanpa Global Scope | Satu model yang terlewat sudah cukup melanggar NFR-06 | Mitigasi: prinsip tenant-first + unit test setiap sprint (Roadmap §7.2) |
| Query melalui raw SQL | Global Scope tidak berlaku | Dilarang oleh CON-10 |
| Jalur bypass Super Admin | Berlaku untuk seluruh query | Memerlukan test case khusus (RSK-16) |
| `notification_reads` tanpa `school_id` | Global Scope tidak dapat diterapkan langsung | **Belum dijelaskan dalam blueprint.** |
| `notifications.target_id` tanpa FK | Nilai dapat menunjuk entitas cabang lain bila tidak divalidasi | Validasi di lapisan aplikasi |
| Data publik PPDB | Form dapat diakses tanpa login | Data tetap terikat `school_id` cabang yang dipilih (CON-14) |
| `users.email` unik global | Satu orang tidak dapat memiliki akun di dua cabang dengan email sama | Konsekuensi desain (ASM-12) |

### 7.5 Skalabilitas Tenant

| Aspek | Ketentuan | Dasar |
|---|---|---|
| Menambah cabang | Cukup menambah satu baris pada `schools` — tanpa perubahan skema | ASM-02 |
| Target kapasitas | 10+ cabang tanpa refactoring | NFR-03 |
| Skala awal | 3 cabang · 50–200 siswa/cabang | Blueprint §Target Skala Awal |
| Strategi sharding atau database per tenant | **Belum dijelaskan dalam blueprint.** | — |

---

## 8. Audit Strategy

### 8.1 `created_at`

| Aspek | Ketentuan |
|---|---|
| Tipe | `TIMESTAMP` |
| Nullability | **Nullable** pada seluruh tabel yang memilikinya |
| Cakupan | **18 dari 19 tabel** yang terdefinisi |
| Pengecualian | `notification_reads` — hanya memiliki `read_at` |
| Status | ✔ Ada pada blueprint |

### 8.2 `updated_at`

| Aspek | Ketentuan |
|---|---|
| Tipe | `TIMESTAMP` |
| Nullability | Nullable |
| Cakupan | **Hanya 10 dari 19 tabel** yang terdefinisi |

**Tabel yang memilikinya (10):** `schools` · `users` · `students` · `academic_years` · `classes` · `ppdb_registrations` · `grades` · `report_cards` · `student_fees` · `notifications`

**Tabel yang TIDAK memilikinya (9):** `student_classes` · `subjects` · `class_subjects` · `schedules` · `grade_configs` · `fee_types` · `payments` · `transactions` · `notification_reads`

> **⚠ Implikasi:** sembilan tabel di atas **tidak memiliki jejak waktu perubahan pada tingkat baris**. Padahal beberapa di antaranya memiliki endpoint edit — `PUT /transactions/{id}`, `PUT /fee-types/{id}` — dan beberapa memiliki aturan bisnis tentang perubahan (SPP-01 penonaktifan jenis tagihan, NILAI-05 perubahan konfigurasi bobot). Untuk tabel-tabel ini, riwayat perubahan sepenuhnya bergantung pada `audit_logs`.

### 8.3 `deleted_at`

| Aspek | Status |
|---|---|
| Keberadaan kolom | **Tidak ada satu pun** dari 21 tabel yang memilikinya |
| Status | **Belum dijelaskan dalam blueprint.** |

**Mekanisme pengganti yang benar-benar tersedia:**

| Tabel | Kolom | Nilai |
|---|---|---|
| `schools` | `is_active` | 1 = aktif, 0 = nonaktif |
| `users` | `is_active` | 1 = aktif, 0 = nonaktif |
| `subjects` | `is_active` | 1 = aktif |
| `fee_types` | `is_active` | 1 = aktif |
| `students` | `status` | `ACTIVE` / `GRADUATED` / `DROPPED_OUT` / `TRANSFERRED` |
| `student_classes` | `status` | `ACTIVE` / `MOVED` |

**Aturan bisnis terkait (CON-45):** data siswa, akun pengguna, dan jenis tagihan **tidak boleh dihapus** — hanya dinonaktifkan.

> **⚠ Kesenjangan:** `DELETE /transactions/{id}` disebut *"soft delete"* pada API Map, namun `transactions` **tidak memiliki `deleted_at` maupun kolom status**. Mekanismenya: **Belum dijelaskan dalam blueprint.**

### 8.4 `created_by`

Blueprint **tidak memakai nama `created_by` secara seragam**. Enam tabel memiliki kolom pelaku dengan nama kontekstual:

| Tabel | Kolom | Merujuk | Null | Makna |
|---|---|---|:---:|---|
| `grades` | `graded_by` | `users.id` | TIDAK | Guru yang menginput nilai |
| `report_cards` | `published_by` | `users.id` | YA | Wali kelas yang menerbitkan rapor |
| `grade_configs` | `created_by` | `users.id` | TIDAK | Admin yang membuat konfigurasi bobot |
| `payments` | `received_by` | `users.id` | TIDAK | Bendahara yang mencatat pembayaran |
| `transactions` | `created_by` | `users.id` | TIDAK | Pencatat transaksi kas |
| `notifications` | `sender_id` | `users.id` | YA | Pengirim; `NULL` untuk notifikasi sistem |

**Tiga belas tabel lainnya tidak memiliki kolom pelaku** — termasuk `students`, `classes`, `student_fees`, `ppdb_registrations`, dan `subjects`. Jejak pembuatnya hanya tersedia melalui `audit_logs`.

### 8.5 `updated_by`

| Aspek | Status |
|---|---|
| Keberadaan kolom | **Tidak ada satu pun** dari 21 tabel yang memilikinya |
| Jejak pengubah terakhir | Hanya melalui `audit_logs` |
| Status | **Belum dijelaskan dalam blueprint.** |

### 8.6 Audit Log

| Aspek | Ketentuan | Dasar |
|---|---|---|
| Nama tabel | `audit_logs` | NFR-12 |
| Cakupan (NFR-12) | **Semua aksi CRUD** dicatat | NFR-12 |
| Cakupan (§3.4) | **Semua aksi CUD** (Create, Update, Delete) | Blueprint §3.4 |
| Isi catatan | `user` · `action` · `table` · `id` · `timestamp` · `IP` | Blueprint §3.4 |
| Mekanisme | Custom Middleware + Event | Blueprint §3.4 |

> **⚠ Kesenjangan utama:** tabel `audit_logs` **tidak termasuk dalam daftar 21 entitas** blueprint §2.1 dan **tidak memiliki definisi kolom** pada §2.2. → **Belum dijelaskan dalam blueprint.** (PRD §17.3 #2; Roadmap RSK-02)

**Aspek berikut juga tidak dijelaskan:**

| Aspek | Status |
|---|---|
| Struktur kolom `audit_logs` | **Belum dijelaskan dalam blueprint.** |
| Apakah `audit_logs` memiliki `school_id` | **Belum dijelaskan dalam blueprint.** |
| Masa retensi audit log | **Belum dijelaskan dalam blueprint.** |
| Siapa yang berhak membaca audit log | **Belum dijelaskan dalam blueprint.** |
| Format penyimpanan nilai lama vs nilai baru | **Belum dijelaskan dalam blueprint.** |
| Apakah upaya akses yang ditolak juga dicatat | **Belum dijelaskan dalam blueprint.** |

### 8.7 Ringkasan Kemampuan Audit

| Kemampuan | Tersedia? | Sumber |
|---|:---:|---|
| Kapan data dibuat | 18 dari 19 tabel | `created_at` |
| Kapan data diubah | 10 dari 19 tabel | `updated_at` |
| Siapa membuat data | 6 dari 19 tabel | Kolom pelaku kontekstual |
| Siapa mengubah data | **Tidak ada** | Hanya `audit_logs` — struktur belum dijelaskan |
| Data yang dinonaktifkan | 6 tabel | Kolom `is_active` / `status` |
| Data yang dihapus | **Tidak ada** | Tidak ada `deleted_at` |
| Jejak IP dan aksi | Direncanakan | `audit_logs` — struktur belum dijelaskan |

---

## 9. Data Integrity Rules

### 9.1 Foreign Key

| Aspek | Ketentuan | Dasar |
|---|---|---|
| Jumlah kolom FK | 53 pada 19 tabel terdefinisi | Blueprint §2.2 |
| Penandaan | Kolom Key bertanda `FK` | Blueprint §2.2 |
| Tipe kolom FK | Selalu `BIGINT UNSIGNED` — sama dengan tipe PK tujuan | Blueprint §2.2 |
| Penegakan di aplikasi | Melalui Eloquent ORM dengan parameterized query | Blueprint §3.4 |

**Kolom FK yang bersifat nullable (10):**

| Tabel | Kolom | Makna `NULL` |
|---|---|---|
| `users` | `school_id` | Super Admin — tidak terikat cabang |
| `students` | `user_id` | Siswa belum memiliki akun portal |
| `students` | `parent_user_id` | Orang tua belum memiliki akun portal |
| `classes` | `homeroom_teacher_id` | Wali kelas belum ditetapkan |
| `ppdb_registrations` | `academic_year_id` | Tahun ajaran tujuan belum ditentukan |
| `ppdb_registrations` | `converted_student_id` | Pendaftar belum di-enroll |
| `report_cards` | `published_by` | Rapor masih berstatus draft |
| `fee_types` | `academic_year_id` | Tagihan berulang, tidak terikat periode |
| `student_fees` | `academic_year_id` | Tagihan tidak terikat periode |
| `notifications` | `sender_id` | Notifikasi sistem otomatis |

**Kolom yang menyerupai FK tetapi bukan FK:**

| Kolom | Keterangan |
|---|---|
| `notifications.target_id` | Berisi `class_id` atau `user_id` tergantung `target_type`. Blueprint **tidak menandainya FK** — integritas referensial tidak dijamin skema |

### 9.2 Cascade

| Aspek | Status |
|---|---|
| Aturan `ON DELETE CASCADE` | **Belum dijelaskan dalam blueprint.** |
| Aturan `ON UPDATE CASCADE` | **Belum dijelaskan dalam blueprint.** |

**Yang dapat dipastikan dari blueprint** (fakta yang relevan bagi keputusan cascade):

| Fakta | Implikasi |
|---|---|
| Data siswa, akun pengguna, dan jenis tagihan **tidak boleh dihapus** — hanya dinonaktifkan (CON-45) | Penghapusan berjenjang jarang terjadi pada alur normal |
| Edit data siswa **tidak menghapus histori** (SIS-02 AC-1) | Riwayat harus terjaga |
| Cabang dapat diaktif/nonaktifkan melalui `is_active`, bukan dihapus | `schools` tidak dimaksudkan untuk dihapus |
| `schools` menjadi induk 17 tabel bisnis | Penghapusan satu cabang akan memutus seluruh rantai datanya |

> Keputusan aturan cascade untuk **53 relasi foreign key** perlu ditetapkan pemilik blueprint sebelum migration ditulis, dan wajib diterbitkan sebagai revisi blueprint (CON-55).

### 9.3 Restrict

| Aspek | Status |
|---|---|
| Aturan `ON DELETE RESTRICT` | **Belum dijelaskan dalam blueprint.** |
| Aturan `ON DELETE SET NULL` | **Belum dijelaskan dalam blueprint.** |
| Aturan `ON DELETE NO ACTION` | **Belum dijelaskan dalam blueprint.** |

**Constraint tingkat database yang juga tidak dijelaskan:**

| Constraint | Aturan bisnis yang seharusnya ditegakkan | Status |
|---|---|---|
| Unique composite `school_id` + `nis` | NIS unik per sekolah (CON-38) | **Belum dijelaskan dalam blueprint.** |
| Unique composite `student_id` + `academic_year_id` | 1 siswa = 1 kelas per TA (CON-35) | **Belum dijelaskan dalam blueprint.** |
| Unique composite `homeroom_teacher_id` + `academic_year_id` | 1 guru = 1 wali kelas per TA (CON-36) | **Belum dijelaskan dalam blueprint.** |
| Partial unique pada `is_active` | 1 TA aktif per sekolah (CON-37) | **Belum dijelaskan dalam blueprint.** |
| CHECK constraint pada `score` | Nilai 0–100 (CON-39) | **Belum dijelaskan dalam blueprint.** |
| CHECK constraint pada `semester` | Bernilai 1 atau 2 | **Belum dijelaskan dalam blueprint.** |
| CHECK constraint pada `day_of_week` | Bernilai 1–7 | **Belum dijelaskan dalam blueprint.** |

> **Konsekuensi:** karena blueprint tidak menetapkan constraint tingkat database, seluruh aturan bisnis di atas **ditegakkan di lapisan aplikasi** melalui Laravel Form Request dan Policy. Ini konsisten dengan Blueprint §3.4 yang menyebut *"Validasi semua input sebelum pemrosesan"*, tetapi berarti pelanggaran melalui jalur non-aplikasi tidak tercegah.

### 9.4 Validation

Validasi diterapkan di **lapisan aplikasi** melalui Laravel Form Request (Blueprint §3.4).

**Aturan validasi yang dinyatakan blueprint:**

| Aturan | Kolom terkait | Sumber |
|---|---|---|
| NISN wajib **10 digit angka** | `students.nisn` | SIS-01 AC-2 |
| NIS **unik dalam satu sekolah** | `students.nis` | SIS-01 AC-3 |
| Foto siswa: JPG/PNG/WEBP, maks **2 MB**, resize **400×400** | `students.photo_url` | SIS-03 |
| Bukti pembayaran: JPG/PNG/PDF, maks **5 MB** | `payments.proof_url` | SPP-03 AC-2 |
| Password minimal **8 karakter** | `users.password` | NFR-07 |
| Nilai dalam skala **0–100** | `grades.score` | NILAI-01 AC-1 |
| Pembulatan hasil perhitungan **2 desimal** | Nilai akhir | NILAI-02 AC-3 |
| **1 siswa = 1 kelas per tahun ajaran** | `student_classes` | KELAS-02 AC-2 |
| **1 guru = 1 wali kelas per tahun ajaran** | `classes.homeroom_teacher_id` | KELAS-01 AC-3 |
| **1 tahun ajaran aktif per sekolah** | `academic_years.is_active` | Blueprint §2.2 |
| **Deteksi konflik jadwal** guru/ruang/kelas | `schedules` | KELAS-03 AC-2 |
| Publish rapor ditolak bila ada mapel tanpa nilai akhir | `report_cards` | NILAI-03 AC-1 |
| Nilai tidak dapat diedit setelah rapor published | `grades` | NILAI-01 AC-3 |
| Perubahan status PPDB **wajib disertai catatan alasan** | `ppdb_registrations.status_notes` | PPDB-03 AC-3 |
| Pembebasan tagihan **wajib disertai alasan** | `student_fees.waive_reason` | Blueprint §4.9.1 |
| Preview wajib sebelum generate tagihan massal | `student_fees` | SPP-02 AC-3 |

**Perlindungan tingkat aplikasi lainnya (Blueprint §3.4):**

| Aspek | Implementasi |
|---|---|
| Sanitasi XSS | `htmlspecialchars` |
| SQL Injection | Eloquent parameterized query; raw SQL dilarang kecuali `DB::select()` dengan binding |
| CSRF | Token wajib untuk POST/PUT/DELETE dari form web |
| File upload | Validasi MIME + ukuran; disimpan di `storage/` di luar web root |
| Rate limiting | Login 5/menit; API 60/menit per user |

### 9.5 Ringkasan Integritas Data

| Lapisan | Tersedia? | Keterangan |
|---|:---:|---|
| Primary key | ✔ | 19 tabel terdefinisi |
| Foreign key | ✔ | 53 kolom ditandai `FK` |
| Unique constraint | Sebagian | 4 kolom `UQ`; keunikan komposit tidak dijelaskan |
| Cascade / Restrict | ✘ | **Belum dijelaskan dalam blueprint.** |
| CHECK constraint | ✘ | **Belum dijelaskan dalam blueprint.** |
| ENUM | ✔ | 13 kolom dengan nilai tetap |
| NOT NULL | ✔ | Ditandai pada kolom Nullable di blueprint |
| Default value | ✔ | 9 kolom memiliki default |
| Validasi aplikasi | ✔ | Laravel Form Request — 16 aturan dari acceptance criteria |

---

## 10. Backup Strategy

Blueprint **menjelaskan strategi backup** pada NFR-09, §3.4 Arsitektur Keamanan, dan Lampiran A.3.

### 10.1 Ketentuan Backup

| Aspek | Ketentuan | Dasar |
|---|---|---|
| **Metode** | `mysqldump` via cron | Blueprint §3.4 |
| **Frekuensi** | **Harian otomatis** | NFR-09; Blueprint §3.4 |
| **Waktu** | Pukul **02:00 WIB** | Blueprint §3.4 |
| **Retensi** | **30 hari** | NFR-09; Blueprint §3.4 |
| **Lokasi Phase 1** | **Lokal di VPS** | Blueprint §3.4 |
| **Lokasi Phase 2** | Diunggah ke **Backblaze B2** | Blueprint §3.4 |
| **Verifikasi** | Backup **dapat di-restore** | Lampiran A.3 #7 |

### 10.2 Verifikasi Sebelum Go-Live

| # | Butir Checklist | Dasar |
|:---:|---|---|
| 7 | Backup database otomatis berjalan **dan dapat di-restore** | Lampiran A.3 #7 |

Uji restore dijadwalkan pada Sprint 9 / pra go-live (Roadmap §2.4, DLV-S9-10).

### 10.3 Risiko yang Tercatat

| Risiko | Penjelasan | Sumber |
|---|---|---|
| **RSK-23** | Backup hanya tersimpan **lokal di VPS** pada Phase 1 — jika VPS hilang, backup ikut hilang. Probabilitas Rendah, Dampak **Tinggi** | Roadmap §6.2 |
| Mitigasi | Uji restore sebelum go-live; blueprint sendiri menyebut Backblaze B2 sebagai tujuan pada Phase 2 | Roadmap §7.2 |

### 10.4 Aspek Backup yang Tidak Dijelaskan

| Aspek | Status |
|---|---|
| Format berkas backup (kompresi, enkripsi) | **Belum dijelaskan dalam blueprint.** |
| Lokasi penyimpanan berkas backup di VPS | **Belum dijelaskan dalam blueprint.** |
| Prosedur restore langkah demi langkah | **Belum dijelaskan dalam blueprint.** |
| Frekuensi uji restore setelah go-live | **Belum dijelaskan dalam blueprint.** |
| Backup berkas unggahan (foto, bukti bayar, dokumen PPDB) | **Belum dijelaskan dalam blueprint.** — blueprint hanya menyebut backup *database* |
| Recovery Point Objective (RPO) dan Recovery Time Objective (RTO) | **Belum dijelaskan dalam blueprint.** |
| Backup sebelum migration atau deployment | **Belum dijelaskan dalam blueprint.** |
| Retensi backup di Backblaze B2 (Phase 2) | **Belum dijelaskan dalam blueprint.** |

---

## 11. Future Database Expansion

Bagian ini **hanya memuat hal yang disebutkan blueprint**.

### 11.1 Modul Phase 2 dan Kebutuhan Entitasnya

Blueprint mendaftarkan 11 modul Phase 2, namun **tidak mendefinisikan satu pun entitas atau struktur tabelnya**:

| Modul Phase 2 | Estimasi | Status Definisi Entitas |
|---|:---:|:---:|
| LMS — Ruang Kelas Virtual (Google Meet) | 4–6 minggu | **Belum dijelaskan dalam blueprint.** |
| LMS — CBT (Ujian Online) | 6–8 minggu | **Belum dijelaskan dalam blueprint.** |
| LMS — Bank Materi | 3–4 minggu | **Belum dijelaskan dalam blueprint.** |
| Presensi Digital | 4–5 minggu | **Belum dijelaskan dalam blueprint.** |
| Konseling & BK | 3–4 minggu | **Belum dijelaskan dalam blueprint.** |
| E-Library | 4–5 minggu | **Belum dijelaskan dalam blueprint.** |
| Manajemen Inventaris | 3–4 minggu | **Belum dijelaskan dalam blueprint.** |
| Payroll Guru & Staf | 5–6 minggu | **Belum dijelaskan dalam blueprint.** |
| Payment Gateway (Midtrans/Xendit) | 4–6 minggu | **Belum dijelaskan dalam blueprint.** |
| WhatsApp API (Fonnte / Meta Cloud API) | 2–3 minggu | **Belum dijelaskan dalam blueprint.** |
| DAPODIK Export | 3–4 minggu | **Belum dijelaskan dalam blueprint.** |

**Prasyarat memulai Phase 2:** Phase 1 stabil dan digunakan **minimal 3 bulan** (CON-54; SM-13).

### 11.2 Kolom yang Sudah Menyiapkan Jalan bagi Phase 2

Meskipun tidak ada entitas baru, beberapa kolom pada 21 tabel yang ada **sudah menyiapkan jalan**:

| Kolom | Tabel | Menyiapkan untuk | Perubahan skema yang diperlukan |
|---|---|---|---|
| `payment_method = 'PAYMENT_GATEWAY'` | `payments` | Integrasi Midtrans/Xendit | Nilai ENUM sudah ada — tidak perlu diubah |
| `attend_present`, `attend_sick`, `attend_permission`, `attend_absent` | `report_cards` | Rekap kehadiran dari Presensi Digital | Kolom sudah ada — memerlukan tabel absensi sebagai sumber |
| `wa_template_ppdb`, `wa_template_spp`, `wa_template_rapor` | `schools` | Migrasi ke WhatsApp API | Template dapat dipakai ulang |
| `wa_template` | `notifications` | Pengiriman otomatis | Isi pesan siap kirim sudah tersimpan |
| Seluruh kolom identitas siswa | `students` | DAPODIK Export | Sumber data sudah lengkap |
| Kolom `*_url` (`logo_url`, `photo_url`, `proof_url`, `avatar_url`) | Berbagai tabel | Migrasi storage ke Backblaze B2 | Menyimpan path — migrasi tidak mengubah skema |
| `users.locale` bertipe `VARCHAR(5)` | `users` | Penambahan bahasa di luar ID/EN | Tipe sudah mendukung kode locale lain (NFR-11) |

> **Catatan penting:** empat kolom rekap kehadiran pada `report_cards` **sudah ada sekarang**, tetapi sumber datanya baru tersedia di Phase 2. Inilah akar isu terbuka nomor 1 pada PRD §17.3 — kolom tersedia, sumber data tidak.

### 11.3 Tabel yang Dibutuhkan Phase 1 tetapi Belum Didefinisikan

Tiga tabel berikut **dibutuhkan pada Phase 1** namun tidak ada dalam 21 entitas:

| Tabel | Dibutuhkan oleh | Status | Memblokir |
|---|---|---|---|
| `audit_logs` | NFR-12 · Blueprint §3.4 | **Belum dijelaskan dalam blueprint.** | Phase 1 / Sprint 1 |
| Tabel absensi | SIS-04 · PORTAL-01 · `report_cards.attend_*` · deskripsi role Wali Kelas | **Belum dijelaskan dalam blueprint.** | Phase 2 (Master Data), 4 (Academic), 6 (Portal) |
| `permissions` / `role_has_permissions` | Paket `spatie/laravel-permission` yang ditetapkan blueprint | **Belum dijelaskan dalam blueprint.** | Phase 1 / Sprint 1 |

> Ketiganya wajib diklarifikasi ke pemilik blueprint **sebelum migration ditulis** — lihat Roadmap §14.3 (daftar isu pemblokir).

### 11.4 Peningkatan Infrastruktur Data

| Komponen | Phase 1 | Rencana Phase 2 | Dampak pada skema |
|---|---|---|---|
| File storage | Local disk VPS | Backblaze B2 | Tidak ada — kolom menyimpan path |
| Cache | Laravel Cache (database driver) | Redis | Tabel cache Laravel tidak lagi dipakai |
| Queue | Laravel Queue (database driver) | Dapat mengikuti cache | Tabel queue Laravel tidak lagi dipakai |
| Backup | `mysqldump` lokal, retensi 30 hari | Diunggah ke Backblaze B2 | Tidak ada |
| Kapasitas server | VPS 2C/2GB | Skalabilitas vertikal | Tidak ada |

### 11.5 Batas Skalabilitas

| Aspek | Ketentuan | Dasar |
|---|---|---|
| Target tenant | 10+ cabang tanpa refactoring skema | NFR-03 |
| Penambahan cabang | Cukup menambah baris `schools` | ASM-02 |
| Strategi sharding | **Belum dijelaskan dalam blueprint.** | — |
| Strategi partisi tabel | **Belum dijelaskan dalam blueprint.** | — |
| Strategi arsip data tahun ajaran lama | **Belum dijelaskan dalam blueprint.** | — |
| Batas pertumbuhan data | **Belum dijelaskan dalam blueprint.** | — |

---

## 12. Lampiran

### 12.1 Ringkasan Statistik Database

| Metrik | Nilai |
|---|:---:|
| Jumlah tabel | 21 (19 terdefinisi + 2 tanpa definisi kolom) |
| Total kolom (19 tabel terdefinisi) | 219 |
| Kolom foreign key | 53 |
| Kolom dengan `school_id` | 17 |
| Unique index | 4 |
| Secondary index | 15 |
| Kolom ENUM | 13 |
| Kolom JSON | 3 |
| Kolom dengan nilai default | 9 |
| Tabel dengan `created_at` | 18 |
| Tabel dengan `updated_at` | 10 |
| Tabel dengan `deleted_at` | **0** |
| Tabel dengan kolom pelaku | 6 |

### 12.2 Daftar Kolom dengan Nilai Default

| Tabel | Kolom | Default |
|---|---|:---:|
| `schools` | `is_active` | `1` |
| `users` | `locale` | `id` |
| `users` | `is_active` | `1` |
| `students` | `status` | `ACTIVE` |
| `academic_years` | `is_active` | `0` |
| `classes` | `capacity` | `35` |
| `student_classes` | `status` | `ACTIVE` |
| `subjects` | `is_active` | `1` |
| `ppdb_registrations` | `status` | `REGISTERED` |
| `report_cards` | `is_published` | `0` |
| `fee_types` | `is_active` | `1` |
| `student_fees` | `amount_paid` | `0.00` |
| `student_fees` | `status` | `UNPAID` |
| `notifications` | `is_draft` | `1` |

### 12.3 Referensi Silang: Tabel ↔ Modul ↔ Sprint

| Tabel | Modul | Fase | Sprint |
|---|---|:---:|:---:|
| `schools`, `users`, `roles`, `model_has_roles` | M0 Core Platform | Phase 0–1 | 1 |
| `students` | M1.1 SIS | Phase 2 | 2 |
| `academic_years` | M1.3 Tahun Ajaran | Phase 2 | 2 |
| `classes`, `student_classes` | M1.4 Kelas & Rombel | Phase 2 | 2 |
| `subjects`, `class_subjects` | M1.5 Mapel & Pengampu | Phase 2 | 2 |
| `schedules` | M1.6 Jadwal | Phase 2 | 2 |
| `ppdb_registrations` | M1.2 PPDB | Phase 3 | 3 |
| `grades`, `grade_configs` | M1.7 Penilaian | Phase 4 | 4 |
| `report_cards` | M1.8 E-Rapor | Phase 4 | 4 |
| `fee_types`, `student_fees` | M3.1–3.2 Tagihan | Phase 5 | 5 |
| `payments` | M3.3 Pembayaran | Phase 5 | 5 |
| `transactions` | M3.4 Buku Kas | Phase 5 | 6 |
| `notifications`, `notification_reads` | M5.1–5.2 Notifikasi | Phase 7 | 8 |

### 12.4 Daftar Titik ⚠ dalam Spesifikasi Database

| # | Titik | Section | Isu PRD §17.3 |
|:---:|---|---|:---:|
| 1 | Character set dan collation | §2.2 | — |
| 2 | Konfigurasi timezone database | §2.3 | — |
| 3 | Storage engine | §2.4 | — |
| 4 | Struktur kolom `roles` dan `model_has_roles` | §5.3, §5.4 | — |
| 5 | Tabel `permissions` / `role_has_permissions` | §5.3, §11.3 | — |
| 6 | Tabel absensi tidak ada | §5.14, §11.3 | #1 |
| 7 | Tabel `audit_logs` tidak ada | §8.6, §11.3 | #2 |
| 8 | Status `INACTIVE` tidak ada dalam ENUM `students.status` | §5.5 | #5 |
| 9 | Format WEBP vs JPG/PNG/PDF pada foto siswa | §5.5 | #12 |
| 10 | Penegakan keunikan `school_id` + `nis` | §5.5, §6.4 | — |
| 11 | Presedensi `grades.weight` vs `grade_configs.components` | §5.13, §5.15 | #7 |
| 12 | Rumus `rank_in_class` | §5.14 | #13 |
| 13 | Mekanisme unpublish / koreksi rapor | §5.14 | #8 |
| 14 | Perilaku generate tagihan `YEARLY` / `ONCE` | §5.16 | #9 |
| 15 | `notifications.target_id` tanpa foreign key | §5.20, §9.1 | — |
| 16 | Perbedaan daftar kategori notifikasi | §4.5, §5.20 | — |
| 17 | `notification_reads` tanpa `school_id` | §5.21, §7.4 | — |
| 18 | `notification_reads` tanpa `created_at`/`updated_at` | §5.21, §8.1 | — |
| 19 | Sembilan tabel tanpa `updated_at` | §8.2 | — |
| 20 | Tidak ada kolom `deleted_at` di tabel manapun | §8.3 | — |
| 21 | Tidak ada kolom `updated_by` di tabel manapun | §8.5 | — |
| 22 | Mekanisme soft delete `transactions` | §5.19, §8.3 | — |
| 23 | Aturan `ON DELETE` / `ON UPDATE` untuk 53 FK | §9.2, §9.3 | — |
| 24 | Unique composite constraint pada tabel pivot | §6.4, §9.3 | — |
| 25 | CHECK constraint (`score`, `semester`, `day_of_week`) | §9.3 | — |
| 26 | Index pada kolom foreign key | §6.2 | — |
| 27 | Composite index dan konvensi penamaan index | §6.3, §6.6 | — |
| 28 | Presedensi `classes.room` vs `schedules.room` | §5.7, §5.11 | — |
| 29 | Sumber kebenaran data ortu (`students.parent_*` vs `users`) | §5.5 | — |
| 30 | Panjang `academic_years.name` (`VARCHAR(20)`) vs contoh nilainya | §5.6 | — |
| 31 | Validasi total bobot `grade_configs.components` = 1.00 | §5.15 | — |
| 32 | Backup berkas unggahan (non-database) | §10.4 | — |
| 33 | Prosedur restore, RPO, dan RTO | §10.4 | — |
| 34 | Strategi sharding, partisi, dan arsip data | §11.5 | — |

### 12.5 Referensi Dokumen

| Dokumen | Peran |
|---|---|
| `blueprint/SmartSukses_FullBlueprint_v1.0.0.docx` | Sumber kebenaran tunggal — Bagian 2 (ERD) dan Bagian 3 (Arsitektur) |
| `docs/01-Analisis-Blueprint.md` | Analisis awal blueprint |
| `docs/01-PRD.md` (v1.1) | 38 FR · 12 NFR · 21 ASM · 56 CON · 14 isu terbuka |
| `docs/02-ROADMAP.md` (v1.1) | 11 fase · 9 sprint · 92 deliverable · 32 risiko |
| `docs/03-USER_FLOW.md` (v1.0) | Alur pengguna 8 role · 25 titik ⚠ |
| `docs/04-ERD.md` (v1.0) | 21 entitas · 56 relasi · Mermaid ER Diagram · 21 titik ⚠ |
| `docs/05-DATABASE.md` | Dokumen ini — spesifikasi 21 tabel · 219 kolom · 34 titik ⚠ |

---

## Riwayat Revisi Dokumen

| Versi | Tanggal | Penulis | Keterangan |
|---|---|---|---|
| v1.0 | — | Tim Pengembang | Database Design Specification awal, diturunkan dari `SmartSukses_FullBlueprint_v1.0.0.docx` Bagian 2 & 3 beserta `docs/01-PRD.md`, `docs/02-ROADMAP.md`, `docs/03-USER_FLOW.md`, dan `docs/04-ERD.md` |

---

*Dokumen ini disusun sepenuhnya berdasarkan `blueprint/SmartSukses_FullBlueprint_v1.0.0.docx` dan dokumen turunannya. Tidak ada tabel, kolom, tipe data, maupun requirement yang ditambahkan, dikurangi, atau diubah. Seluruh informasi yang tidak tercantum dalam blueprint ditandai secara eksplisit sebagai "Belum dijelaskan dalam blueprint."*

**Smart Sukses School · Database Design Specification v1.0 · KONFIDENSIAL**

