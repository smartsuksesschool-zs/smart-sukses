# Smart Sukses School — User Flow

---

| Parameter | Detail |
|---|---|
| **Nama Produk** | Smart Sukses School |
| **Platform** | `apps.smartsukses.sch.id` |
| **Versi Dokumen** | v1.0 |
| **Sumber Utama** | `blueprint/SmartSukses_FullBlueprint_v1.0.0.docx` (v1.0.0 · Agustus 2025) · `docs/01-PRD.md` (v1.1) · `docs/02-ROADMAP.md` (v1.1) |
| **Cakupan** | Phase 1 (MVP) — 8 role · 38 functional requirement |
| **Sifat Dokumen** | Turunan blueprint, PRD, dan Roadmap. Tidak menambah, mengurangi, atau mengubah requirement. |
| **Kerahasiaan** | KONFIDENSIAL — hanya untuk penggunaan internal |

> **Konvensi dokumen:** Setiap informasi yang tidak tercantum dalam blueprint ditulis sebagai **"Belum dijelaskan dalam blueprint."**

---

## Daftar Isi

| No | Section |
|---|---|
| 1 | [Overview](#1-overview) |
| 2 | [Global Application Flow](#2-global-application-flow) |
| 3 | [User Flow per Role](#3-user-flow-per-role) |
| 4 | [Authentication Flow](#4-authentication-flow) |
| 5 | [PPDB Flow](#5-ppdb-flow) |
| 6 | [Academic Flow](#6-academic-flow) |
| 7 | [Finance Flow](#7-finance-flow) |
| 8 | [Notification Flow](#8-notification-flow) |
| 9 | [Error Flow](#9-error-flow) |
| 10 | [Security Flow](#10-security-flow) |
| 11 | [Module Dependency](#11-module-dependency) |
| 12 | [User Journey Summary](#12-user-journey-summary) |
| 13 | [Future Improvement](#13-future-improvement) |
| 14 | [Lampiran](#14-lampiran) |

---

## 1. Overview

### 1.1 Tujuan Dokumen

Dokumen ini memetakan **perjalanan pengguna (user flow)** di dalam Smart Sukses School — dari titik masuk hingga keluar — untuk seluruh 8 role yang didefinisikan blueprint. Tujuannya:

| ID | Tujuan | Penjelasan |
|---|---|---|
| **TU-01** | Menerjemahkan 38 functional requirement menjadi **urutan langkah yang dialami pengguna** | PRD §9 menjelaskan *apa* yang bisa dilakukan; dokumen ini menjelaskan *dalam urutan apa* |
| **TU-02** | Memastikan setiap role hanya menempuh jalur yang diizinkan **matriks izin** | PRD §8.2 |
| **TU-03** | Menjadi acuan desain antarmuka dan navigasi sebelum coding dimulai | Roadmap §2.2 (tahap Design) |
| **TU-04** | Menjadi dasar penyusunan **skenario pengujian** end-to-end | Roadmap §2.4 |
| **TU-05** | Menandai secara eksplisit titik-titik alur yang **belum dijelaskan blueprint** agar tidak diisi asumsi developer | Konvensi dokumen |

### 1.2 Cakupan

| Tercakup | Tidak Tercakup |
|---|---|
| 8 role: Super Admin, Admin Sekolah, Kepala Sekolah, Guru, Wali Kelas, Siswa, Orang Tua, Bendahara | Fitur Phase 2 (LMS, Presensi Digital, BK, E-Library, Inventaris, Payroll, Payment Gateway, WA API, DAPODIK) |
| Pengguna publik: calon siswa & orang tua calon siswa (PPDB tanpa login) | Aplikasi mobile native — **Belum dijelaskan dalam blueprint.** |
| Alur autentikasi, akademik, keuangan, notifikasi, error, keamanan | Desain visual (wireframe/mockup) — di luar cakupan dokumen ini |

### 1.3 Cara Membaca Diagram

| Simbol | Arti |
|:---:|---|
| `↓` | Langkah berurutan |
| `├─▶` / `└─▶` | Percabangan |
| `◆` | Titik keputusan (decision point) |
| `▣` | Titik verifikasi sistem (validasi/otorisasi) |
| `⚠` | Titik yang belum dijelaskan blueprint |
| `[ ]` | Halaman / layar |
| `( )` | Aksi pengguna |

### 1.4 Prinsip Alur yang Berlaku di Seluruh Dokumen

| Prinsip | Dasar |
|---|---|
| Seluruh role mengakses **URL yang sama** — `apps.smartsukses.sch.id` | Blueprint §Solusi; CON-12 |
| Visibilitas data ditentukan oleh **role** dan **`school_id`** pengguna | Blueprint §Solusi; AUTH-02 |
| Setiap pengguna memiliki **tepat satu peran utama** | Blueprint §1.1; ASM-08 |
| Setelah login, tampilan otomatis mengikuti **white-label cabang** pengguna | AUTH-03 |
| Setiap query data otomatis ter-scope `WHERE school_id` | CON-15 |
| Super Admin (`school_id = NULL`) **melewati** scope tersebut | CON-16 |

---

## 2. Global Application Flow

### 2.1 Diagram Alur Global

```
                          ┌──────────────────────────┐
                          │      LANDING PAGE        │  ⚠ lihat §2.2
                          └────────────┬─────────────┘
                                       │
                    ┌──────────────────┴──────────────────┐
                    │                                     │
            (pengguna terdaftar)                  (calon siswa / publik)
                    │                                     │
                    ▼                                     ▼
          ┌───────────────────┐                 ┌───────────────────┐
          │   [ LOGIN ]       │                 │  [ PPDB PUBLIK ]  │
          │  email + password │                 │  tanpa login      │
          └─────────┬─────────┘                 └─────────┬─────────┘
                    │                                     │
                    ▼                                     └──▶ lihat §5
        ┌───────────────────────┐
        │  ▣ AUTHENTICATION     │
        │  verifikasi kredensial│
        └───────────┬───────────┘
                    │
              ◆ valid?
          ┌─────────┴─────────┐
          │ TIDAK             │ YA
          ▼                   ▼
   ┌─────────────┐   ┌───────────────────────────┐
   │ Error login │   │  ▣ ROLE & TENANT DETECTION│
   │ lihat §9.1  │   │  lookup users.school_id   │
   └─────────────┘   │  TenantMiddleware bootstrap│
                     └───────────┬───────────────┘
                                 │
                                 ▼
                     ┌───────────────────────────┐
                     │  ▣ WHITE-LABEL INJECTION  │
                     │  logo + CSS variables     │
                     └───────────┬───────────────┘
                                 │
                                 ▼
                     ┌───────────────────────────┐
                     │      [ DASHBOARD ]        │
                     │   sesuai role pengguna    │
                     └───────────┬───────────────┘
                                 │
                                 ▼
                     ┌───────────────────────────┐
                     │  ▣ MODULE ACCESS CONTROL  │
                     │  matriks izin PRD §8.2    │
                     └───────────┬───────────────┘
                                 │
              ┌──────────────────┼──────────────────┐
              ▼                  ▼                  ▼
      [ Modul Akademik ]  [ Modul Keuangan ]  [ Modul Komunikasi ]
              │                  │                  │
              └──────────────────┼──────────────────┘
                                 │
                                 ▼
                     ┌───────────────────────────┐
                     │        [ LOGOUT ]         │
                     │  invalidate token/sesi    │
                     └───────────┬───────────────┘
                                 │
                                 ▼
                          kembali ke [ LOGIN ]
```

### 2.2 Penjelasan Tiap Tahap

#### Tahap 1 — Landing Page

| Aspek | Keterangan |
|---|---|
| **Yang dijelaskan blueprint** | Terdapat halaman publik daftar cabang yang membuka PPDB (`GET /ppdb/schools`) dan halaman info PPDB per cabang (`GET /ppdb/{schoolCode}/info`) |
| **Yang tidak dijelaskan** | Halaman landing umum aplikasi (di luar konteks PPDB), isi, dan tata letaknya: **Belum dijelaskan dalam blueprint.** |
| **Konsekuensi alur** | Pengguna terdaftar diasumsikan langsung menuju halaman login; calon siswa menuju halaman PPDB publik |

#### Tahap 2 — Login

| Aspek | Keterangan |
|---|---|
| **Masukan** | Email + password (AUTH-01 AC-1) |
| **Endpoint** | `POST /auth/login` — Auth Level: Public |
| **Keluaran sukses** | Bearer token + informasi user + konfigurasi sekolah (logo, warna) |
| **Keluaran gagal** | Pesan error yang **tidak mengungkap detail sistem** (AUTH-01 AC-2) |
| **Pembatasan** | Maksimal **5 percobaan per menit** (CON-28) |

#### Tahap 3 — Authentication

| Aspek | Keterangan |
|---|---|
| **Mekanisme** | Laravel Sanctum — cookie-based session token untuk web; Bearer token untuk API (Blueprint §3.4) |
| **Masa berlaku** | Token kedaluwarsa setelah **8 jam tidak aktif** (AUTH-01 AC-4; CON-29) |
| **Catatan konsistensi** | NFR menyebut "JWT + session", §3.4 menyebut Sanctum. Blueprint memuat dua pernyataan berbeda — lihat PRD §17.3 #10 |

#### Tahap 4 — Role & Tenant Detection

Mengikuti **alur 7 langkah** blueprint §3.2.2:

```
1. Pengguna mengakses apps.smartsukses.sch.id (URL tunggal semua cabang)
        ↓
2. Pengguna memasukkan email dan password
        ↓
3. Sistem lookup users.email → memperoleh users.school_id
        ↓
4. TenantMiddleware (spatie/laravel-multitenancy) bootstrap:
   menyimpan school_id ke context sesi
        ↓
5. Semua query Eloquent selanjutnya otomatis di-scope dengan school_id
   via Global Scope
        ↓
6. Sistem membaca schools.logo_url dan schools.primary_color
   → inject sebagai CSS variables
        ↓
7. Pengguna melihat tampilan white-label sesuai cabangnya
```

| Kasus khusus | Perilaku |
|---|---|
| `SUPER_ADMIN` (`school_id = NULL`) | **Melewati Global Scope** — dapat mengakses data seluruh tenant (Blueprint §3.2.2; CON-16) |
| Pengguna biasa | Tidak memiliki cara apa pun mengakses data cabang lain (AUTH-02 AC-3) |

#### Tahap 5 — Dashboard

Setiap role memperoleh dashboard berbeda:

| Role | Dashboard | Endpoint |
|---|---|---|
| `SUPER_ADMIN` | Ringkasan seluruh cabang: total siswa, total SPP terkumpul, PPDB aktif | `GET /admin/dashboard` |
| `SCHOOL_ADMIN` | Operasional cabang | Blueprint tidak merinci isi dashboard Admin Sekolah — **Belum dijelaskan dalam blueprint.** |
| `KEPALA_SEKOLAH` | Dashboard cabang: saldo kas, penerimaan SPP, pengeluaran, grafik tren 6 bulan | KAS-02 |
| `GURU` / `WALI_KELAS` | Jadwal hari ini, kelas aktif, notifikasi masuk | `GET /teacher/dashboard`; PORTAL-02 |
| `BENDAHARA` | Dashboard Bendahara | Sprint 6 blueprint menyebut "Dashboard Bendahara"; rincian isinya **Belum dijelaskan dalam blueprint.** |
| `SISWA` | Jadwal hari ini, 5 nilai terbaru, notifikasi | `GET /student/dashboard` |
| `ORANG_TUA` | Nilai terbaru, kehadiran bulan ini, tagihan belum lunas | PORTAL-01; `GET /parent/children/{id}/summary` |

> **Catatan konsistensi:** PORTAL-01 menyebut "3 nilai terbaru", sedangkan `GET /parent/children/{id}/summary` menyebut "nilai terbaru 5 mapel" (PRD §17.3 #4).

#### Tahap 6 — Module Access

Akses modul ditentukan **matriks izin PRD §8.2** (✅ penuh · ⭕ baca saja · ❌ tidak ada akses), diperkuat oleh:

| Lapisan | Mekanisme |
|---|---|
| Menu/navigasi | Hanya menampilkan modul yang diizinkan role |
| Route middleware | Auth Level: Public / Auth / Admin / Super (PRD §8.4) |
| Policy per model | `StudentPolicy`, `GradePolicy`, dll. (Blueprint §3.4) |
| Global Scope | `WHERE school_id` otomatis pada setiap query (CON-15) |

#### Tahap 7 — Logout

| Aspek | Keterangan |
|---|---|
| **Endpoint** | `POST /auth/logout` — Auth Level: Auth |
| **Efek** | Invalidate token sesi aktif |
| **Logout otomatis** | Terjadi bila token kedaluwarsa (8 jam tidak aktif) atau setelah reset password berhasil (AUTH-04 AC-3) |
| **Tujuan setelah logout** | Blueprint tidak menyebutkan halaman tujuan setelah logout — **Belum dijelaskan dalam blueprint.** |

---

## 3. User Flow per Role

### 3.1 Super Admin

**Level:** Platform · **`school_id`:** NULL · **Cakupan:** seluruh cabang

#### Diagram Alur

```
[ LOGIN ]
    ↓
▣ Authentication (POST /auth/login)
    ↓
▣ Role Detection → SUPER_ADMIN, school_id = NULL
    ↓
▣ Global Scope DILEWATI (akses lintas cabang)
    ↓
[ DASHBOARD SUPER ADMIN ]  ── GET /admin/dashboard
   • Total siswa seluruh cabang
   • Total SPP terkumpul
   • PPDB aktif
    ↓
    ├──▶ [ MANAJEMEN TENANT / CABANG ]              ✅
    │      ↓
    │    (Lihat daftar cabang) ── GET /admin/schools
    │      ↓
    │    ◆ Aksi?
    │      ├─▶ (Daftarkan cabang baru) ── POST /admin/schools
    │      │      ↓ isi: name, code, slug, alamat, kontak
    │      │      ↓ atur white-label: logo_url, primary_color, secondary_color
    │      │      ↓ atur template WA: ppdb, spp, rapor
    │      │      ↓ ▣ Cabang baru aktif TANPA perubahan kode (ASM-02)
    │      │
    │      ├─▶ (Ubah data & white-label cabang) ── PUT /admin/schools/{id}
    │      │      ↓ ▣ Perubahan berlaku TANPA deployment ulang (CON-19)
    │      │
    │      ├─▶ (Aktif/nonaktifkan cabang) ── PATCH /admin/schools/{id}/toggle
    │      │
    │      └─▶ (Lihat statistik cabang) ── GET /admin/schools/{id}/stats
    │             ↓ jumlah siswa, guru, tagihan terkumpul bulan ini, tunggakan
    │
    ├──▶ [ DASHBOARD KEUANGAN LINTAS CABANG ]       ✅  (KAS-03)
    │      ↓ per cabang: total tagihan, total terkumpul, % lunas
    │      ↓ filter: tahun ajaran / bulan
    │
    ├──▶ [ SELURUH MODUL SEKOLAH ]                  ✅
    │      ↓ SIS · PPDB · Kelas & Jadwal · Nilai · Rapor
    │      ↓ Tagihan · Pembayaran · Akuntansi · Laporan
    │      ↓ Notifikasi · Portal Siswa · Parent Portal
    │      ↓ White-label Settings · User Management
    │
    └──▶ [ PROFIL ] ── PATCH /auth/me (nama, telepon, avatar, locale)
    ↓
[ LOGOUT ] ── POST /auth/logout
```

#### Penjelasan

| Tahap | Keterangan |
|---|---|
| **Titik masuk** | Halaman login yang sama dengan seluruh role lain (single domain) |
| **Pembeda utama** | `school_id = NULL` memicu bypass Global Scope, sehingga seluruh data lintas cabang terlihat |
| **Kewenangan eksklusif** | Manajemen Tenant/Cabang — satu-satunya role dengan ✅ pada modul ini (PRD §8.2) |
| **Kewenangan penuh** | Seluruh 15 baris matriks izin bertanda ✅ untuk role ini |
| **Batasan** | Blueprint tidak menjelaskan apakah Super Admin dapat "masuk sebagai" pengguna cabang (impersonate) — **Belum dijelaskan dalam blueprint.** |
| **Risiko terkait** | Jalur bypass ini adalah titik lemah keamanan yang perlu test case khusus (Roadmap RSK-16) |

---

### 3.2 Admin Sekolah

**Level:** Sekolah · **Cakupan:** satu cabang (`school_id` miliknya)

#### Diagram Alur

```
[ LOGIN ]
    ↓
▣ Authentication → SCHOOL_ADMIN, school_id = X
    ↓
▣ Global Scope AKTIF → seluruh data ter-filter school_id = X
    ↓
▣ White-label cabang X ter-inject
    ↓
[ DASHBOARD ADMIN SEKOLAH ]   ⚠ isi dashboard belum dijelaskan blueprint
    ↓
    ├──▶ [ USER MANAGEMENT ]                        ✅  (PORTAL-04)
    │      ├─▶ (Buat akun) ── POST /users  → assign role
    │      ├─▶ (Import massal Excel) ── POST /users/import
    │      │      ↓ ▣ Sistem mengembalikan daftar error per baris
    │      ├─▶ (Edit akun) ── PUT /users/{id}
    │      ├─▶ (Nonaktifkan) ── DELETE /users/{id}  ▣ soft deactivate (CON-45)
    │      └─▶ (Reset password) ── POST /users/{id}/reset-password
    │             ↓ password sementara dikirim via notifikasi
    │
    ├──▶ [ WHITE-LABEL SETTINGS ]                   ✅
    │      ↓ ubah logo, primary_color, secondary_color
    │      ↓ ▣ berlaku langsung tanpa deployment ulang (AUTH-03 AC-3)
    │      ↓ edit template WA: ppdb, spp, rapor (NOTIF-03 AC-2)
    │
    ├──▶ [ DATA SISWA / SIS ]                       ✅
    │      ├─▶ (Tambah siswa) ── POST /students
    │      │      ▣ validasi NISN 10 digit · NIS unik per sekolah
    │      ├─▶ (Edit siswa) ── PUT /students/{id}   ▣ soft update
    │      ├─▶ (Ubah status) ── PATCH /students/{id}/status
    │      ├─▶ (Upload foto) ── POST /students/{id}/photo  ▣ maks 2 MB → 400×400
    │      ├─▶ (Import massal) ── POST /students/import
    │      └─▶ (Export Excel) ── GET /students/export
    │             ↓ siswa_[kode_sekolah]_[tanggal].xlsx
    │
    ├──▶ [ PPDB ]                                   ✅   → lihat §5
    │      ├─▶ (Lihat pendaftar + filter status) ── GET /admin/ppdb
    │      ├─▶ (Ubah status + catatan alasan) ── PATCH /admin/ppdb/{id}/status
    │      ├─▶ (Generate wa.me) ── GET /admin/ppdb/{id}/wa-link
    │      └─▶ (Enroll jadi siswa) ── POST /admin/ppdb/{id}/enroll
    │
    ├──▶ [ KELAS & JADWAL ]                         ✅
    │      ├─▶ (Buat tahun ajaran) ── POST /academic-years
    │      │      ↓ (Aktifkan) ── PATCH /academic-years/{id}/activate
    │      │      ▣ hanya satu TA aktif per sekolah (CON-37)
    │      ├─▶ (Buat mata pelajaran) ── POST /subjects
    │      ├─▶ (Buat kelas + wali kelas) ── POST /classes
    │      │      ▣ 1 guru = 1 kelas per TA (CON-36)
    │      ├─▶ (Tambah siswa ke kelas) ── POST /classes/{id}/students
    │      │      ▣ 1 siswa = 1 kelas per TA (CON-35)
    │      ├─▶ (Pindah/keluarkan siswa) ── DELETE /classes/{id}/students/{studentId}
    │      └─▶ (Buat jadwal) ── POST /schedules
    │             ▣ deteksi konflik guru / ruang / kelas (CON-48)
    │
    ├──▶ [ PENILAIAN ]                              ✅
    │      ├─▶ (Set bobot komponen) ── POST /grade-configs
    │      │      ▣ perubahan hanya berlaku untuk TA baru (CON-42)
    │      └─▶ (Input nilai) ── POST /grades
    │
    ├──▶ [ KEUANGAN ]                               ✅
    │      ├─▶ (Jenis tagihan) ── POST /fee-types
    │      ├─▶ (Generate massal) ── POST /student-fees/generate-bulk
    │      ├─▶ (Bebaskan tagihan) ── PATCH /student-fees/{id}/waive
    │      ├─▶ (Catat pembayaran) ── POST /payments
    │      └─▶ (Kas & laporan) ── /transactions · /finance/*
    │
    ├──▶ [ NOTIFIKASI ]                             ✅   → lihat §8
    │      ├─▶ (Buat pengumuman) ── POST /notifications
    │      │      ↓ target: ALL / CLASS / INDIVIDUAL
    │      └─▶ (Ambil daftar wa.me) ── GET /notifications/{id}/wa-links
    │
    └──▶ [ PROFIL & BAHASA ] ── PATCH /auth/me
    ↓
[ LOGOUT ]
```

#### Penjelasan

| Tahap | Keterangan |
|---|---|
| **Cakupan data** | Seluruh operasional **satu cabang** — otomatis ter-scope `school_id` |
| **Kewenangan penuh (✅)** | Data Siswa, PPDB, Kelas & Jadwal, Input Nilai, Generate Rapor, Tagihan SPP, Catat Pembayaran, Akuntansi & Kas, Laporan Keuangan, Notifikasi, Portal Siswa, Parent Portal, White-label Settings, User Management (PRD §8.2) |
| **Tidak diizinkan (❌)** | Manajemen Tenant/Cabang — hanya Super Admin |
| **Peran sebagai pintu masuk data** | Admin Sekolah adalah satu-satunya role yang dapat membuat akun untuk seluruh role lain di cabangnya |

---

### 3.3 Kepala Sekolah

**Level:** Sekolah · **Sifat akses:** dominan **baca/monitoring**

#### Diagram Alur

```
[ LOGIN ]
    ↓
▣ Authentication → KEPALA_SEKOLAH, school_id = X
    ↓
[ DASHBOARD KEPALA SEKOLAH ]   (KAS-02)
   • Saldo kas
   • Total penerimaan SPP bulan ini
   • Total pengeluaran bulan ini
   • Grafik tren 6 bulan terakhir
    ↓
    ├──▶ [ MONITORING AKADEMIK ]                    ⭕ baca saja
    │      ├─▶ (Lihat data siswa) ── GET /students
    │      ├─▶ (Lihat kelas & jadwal) ── GET /classes · GET /schedules
    │      ├─▶ (Lihat nilai) ── GET /grades
    │      └─▶ (Lihat rapor) ── GET /report-cards
    │
    ├──▶ [ MONITORING PPDB ]                        ⭕ baca saja
    │      └─▶ (Lihat daftar & status pendaftar)
    │
    ├──▶ [ MONITORING KEUANGAN ]                    ⭕ baca saja
    │      ├─▶ (Ringkasan keuangan) ── GET /finance/summary
    │      ├─▶ (Laporan SPP) ── GET /finance/spp-report
    │      ├─▶ (Lihat tagihan) ── GET /student-fees
    │      └─▶ (Lihat kas) ── GET /transactions
    │             ▣ TIDAK dapat mencatat pembayaran (❌ Catat Pembayaran)
    │
    ├──▶ [ BUAT NOTIFIKASI ]                        ✅ akses penuh
    │      └─▶ (Buat pengumuman) ── POST /notifications
    │             ▣ satu-satunya modul dengan ✅ bagi role ini
    │
    ├──▶ [ MONITORING PORTAL ]                      ⭕ baca saja
    │      └─▶ Portal Siswa · Parent Portal
    │
    └──▶ [ PROFIL & BAHASA ]
    ↓
[ LOGOUT ]
```

#### Penjelasan

| Tahap | Keterangan |
|---|---|
| **Sifat peran** | Blueprint mendeskripsikan role ini sebagai *"Monitoring & approval: laporan akademik, keuangan, dashboard cabang"* |
| **Satu-satunya akses penuh** | Notifikasi (buat) — ✅ pada matriks izin |
| **Tidak diizinkan (❌)** | Manajemen Tenant, Catat Pembayaran, White-label Settings, User Management |
| **⚠ Isu terbuka** | Deskripsi role menyebut **"approval"**, namun **tidak ada satu pun user story Phase 1 yang mendefinisikan objek maupun alur approval**. Matriks izin hanya memberi ⭕ pada modul-modul yang relevan. → Alur approval: **Belum dijelaskan dalam blueprint.** (PRD §17.3 #6; Roadmap RSK-06) |

---

### 3.4 Guru (Guru Mata Pelajaran)

**Level:** Sekolah · **Cakupan:** kelas yang diampu

#### Diagram Alur

```
[ LOGIN ]
    ↓
▣ Authentication → GURU, school_id = X
    ↓
[ DASHBOARD GURU ]  ── GET /teacher/dashboard   (PORTAL-02)
   • Jadwal hari ini
   • Kelas aktif
   • Notifikasi masuk
   • Shortcut: Input Nilai · Daftar Siswa Kelas · Buat Pengumuman ⚠
    ↓
    ├──▶ [ JADWAL MENGAJAR ]                        (KELAS-04)
    │      ↓ tabel/kalender mingguan
    │      ↓ (Klik jadwal) → detail: kelas, mata pelajaran, ruang
    │      ▣ hanya jadwal dirinya sendiri (GET /schedules)
    │
    ├──▶ [ DAFTAR SISWA KELAS AJAR ]                (SIS-04)
    │      ↓ GET /students  ▣ hanya siswa kelas yang diampu
    │      ↓ tampil: nama · NIS · foto · status kehadiran hari ini ⚠
    │      ▣ hanya siswa aktif
    │
    ├──▶ [ INPUT NILAI ]                            ✅  (NILAI-01)
    │      ↓ pilih kelas → mata pelajaran → komponen penilaian
    │      ↓ ◆ Metode input?
    │      │    ├─▶ (Satu per satu) ── POST /grades
    │      │    ├─▶ (Massal per kelas) ── POST /grades/bulk
    │      │    └─▶ (Import Excel) ── POST /grades/import
    │      ↓ ▣ validasi skala 0–100
    │      ↓ ▣ guru hanya boleh menilai kelas yang diampu
    │      ↓ ◆ Rapor sudah published?
    │      │    ├─▶ BELUM → nilai dapat diedit (PUT /grades/{id})
    │      │    └─▶ SUDAH → ▣ nilai TERKUNCI (CON-40)
    │      ↓
    │      ▣ Sistem menghitung nilai akhir otomatis (NILAI-02)
    │      ▣ Nilai langsung terlihat siswa & ortu (NILAI-04 AC-1)
    │
    ├──▶ [ LIHAT RAPOR ]                            ⭕
    │      ▣ generate/publish rapor HANYA untuk Wali Kelas
    │
    ├──▶ [ NOTIFIKASI MASUK ]                       (NOTIF-04)
    │      ↓ bell icon + badge unread
    │      ↓ (Klik) → tandai dibaca
    │
    └──▶ [ PROFIL & BAHASA ]
    ↓
[ LOGOUT ]
```

#### Penjelasan

| Tahap | Keterangan |
|---|---|
| **Kewenangan penuh (✅)** | Input Nilai — satu-satunya modul dengan ✅ bagi Guru murni |
| **Baca saja (⭕)** | Data Siswa, Kelas & Jadwal, Portal Siswa |
| **Tidak diizinkan (❌)** | PPDB, Tagihan SPP, Catat Pembayaran, Akuntansi, Laporan Keuangan, Parent Portal, White-label, User Management |
| **Pembatasan data** | Guru hanya melihat siswa dan jadwal pada kelas yang diampunya |
| **⚠ Isu terbuka #1** | Shortcut **"Buat Pengumuman"** pada dashboard (PORTAL-02 AC-2) bertentangan dengan matriks izin yang menyatakan GURU/WALI **❌** pada "Notifikasi (buat)". Blueprint memuat dua pernyataan berbeda (PRD §17.3 #3; Roadmap RSK-03) |
| **⚠ Isu terbuka #2** | "Status kehadiran hari ini" pada daftar siswa (SIS-04 AC-2) memerlukan data absensi, sementara modul Presensi Digital berada di Phase 2 dan tidak ada tabel absensi di ERD. → **Belum dijelaskan dalam blueprint.** (PRD §17.3 #1; Roadmap RSK-01) |

---

### 3.5 Wali Kelas

**Level:** Sekolah · **Cakupan:** semua akses Guru **+** kelas yang diampu sebagai wali

#### Diagram Alur

```
[ LOGIN ]
    ↓
▣ Authentication → WALI_KELAS, school_id = X
    ↓
[ DASHBOARD GURU ]  (sama seperti Guru)
    ↓
    ├──▶ ═══ SELURUH AKSES GURU ═══
    │      • Jadwal mengajar
    │      • Daftar siswa kelas ajar
    │      • Input nilai (satuan / massal / import Excel)
    │      • Notifikasi masuk
    │
    ├──▶ [ KELOLA RAPOR KELAS PERWALIAN ]           ✅  (NILAI-03)
    │      ↓
    │    (Generate draft rapor sekelas) ── POST /report-cards/generate
    │      ↓
    │    ▣ VALIDASI PRA-PUBLISH
    │      ↓ ◆ Semua mata pelajaran sudah punya nilai akhir?
    │      │    ├─▶ TIDAK → ✗ Publish DITOLAK
    │      │    │            ↓ kembali: lengkapi nilai
    │      │    └─▶ YA → lanjut
    │      ↓
    │    (Lengkapi data rapor)
    │      ↓ nilai sikap (A/B/C/D)
    │      ↓ rekap kehadiran: hadir · sakit · izin · alpa   ⚠
    │      ↓ peringkat di kelas (rank_in_class)             ⚠
    │      ↓ catatan wali kelas (homeroom_notes)
    │      ↓
    │    (Publish rapor) ── POST /report-cards/{id}/publish
    │      ↓
    │    ▣ is_published = 1 · published_at · published_by terisi
    │    ▣ SELURUH NILAI TERKUNCI — tidak dapat diedit (CON-40)
    │    ▣ Rapor tampil di Portal Siswa & Parent Portal
    │    ▣ Trigger notifikasi otomatis ke orang tua (NOTIF-03)
    │      ↓
    │    ⚠ Tidak ada alur unpublish / koreksi setelah publish
    │
    ├──▶ [ KELOLA ABSENSI KELAS ]                   ⚠
    │      ▣ Deskripsi role menyebut "kelola absensi",
    │        namun modul Presensi Digital ada di Phase 2
    │      → Belum dijelaskan dalam blueprint.
    │
    └──▶ [ PROFIL & BAHASA ]
    ↓
[ LOGOUT ]
```

#### Penjelasan

| Tahap | Keterangan |
|---|---|
| **Definisi role** | *"Semua akses guru + kelola rapor & absensi kelas yang diampu"* (Blueprint §1.1.1) |
| **Kewenangan eksklusif** | Generate & publish rapor — matriks izin menandai ✅(Wali) pada modul Generate Rapor |
| **Batasan struktural** | Satu guru hanya boleh menjadi wali kelas **satu kelas per tahun ajaran** (KELAS-01 AC-3; CON-36) |
| **Titik tanpa jalan kembali** | Setelah publish, nilai terkunci permanen. **Mekanisme unpublish/koreksi: Belum dijelaskan dalam blueprint.** (PRD §17.3 #8; Roadmap RSK-08) |
| **⚠ Isu terbuka** | Rekap kehadiran pada rapor dan rumus `rank_in_class` sama-sama **Belum dijelaskan dalam blueprint** (PRD §17.3 #1 dan #13) |
| **Catatan matriks** | Matriks izin PRD §8.2 menggabungkan GURU dan WALI_KELAS dalam satu kolom "GURU/WALI". Pemisahan permission secara rinci: **Belum dijelaskan dalam blueprint.** |

---

### 3.6 Bendahara

**Level:** Sekolah · **Cakupan:** keuangan satu cabang

#### Diagram Alur

```
[ LOGIN ]
    ↓
▣ Authentication → BENDAHARA, school_id = X
    ↓
[ DASHBOARD BENDAHARA ]   ⚠ rincian isi belum dijelaskan blueprint
    ↓
    ├──▶ [ JENIS TAGIHAN ]                          ✅  (SPP-01)
    │      ├─▶ (Buat jenis tagihan) ── POST /fee-types
    │      │      ↓ nama · jumlah (Rp) · frekuensi MONTHLY/YEARLY/ONCE
    │      └─▶ (Nonaktifkan) ── PUT /fee-types/{id}
    │             ▣ histori tidak dihapus (CON-45)
    │
    ├──▶ [ GENERATE TAGIHAN MASSAL ]                ✅  (SPP-02)
    │      ↓ pilih jenis tagihan + periode (YYYY-MM) + due date
    │      ↓
    │    ▣ PREVIEW DAFTAR TAGIHAN (wajib, CON-47)
    │      ↓ ◆ Konfirmasi?
    │      │    ├─▶ BATAL → kembali
    │      │    └─▶ YA → POST /student-fees/generate-bulk
    │      ↓
    │    ▣ Record student_fees terbentuk untuk SETIAP siswa aktif
    │    ▣ Status awal: UNPAID · due date terisi otomatis
    │    ▣ Trigger notifikasi "tagihan baru terbit" (NOTIF-03)
    │
    ├──▶ [ CATAT PEMBAYARAN ]                       ✅  (SPP-03)
    │      ↓ POST /payments
    │      ↓ isi: siswa · periode · metode (CASH/TRANSFER) · jumlah
    │      ↓      tanggal · nomor referensi
    │      ↓ (Upload bukti) ── POST /payments/{id}/proof
    │      ↓      ▣ JPG/PNG/PDF maks 5 MB (CON-44)
    │      ↓
    │    ▣ amount_paid terakumulasi pada student_fees
    │    ▣ ◆ Status otomatis:
    │         ├─▶ sebagian → PARTIAL
    │         └─▶ lunas    → PAID
    │    ▣ Cicilan didukung: 1 tagihan → banyak pembayaran
    │
    ├──▶ [ BUKU KAS ]                               ✅  (KAS-01)
    │      ├─▶ (Catat pemasukan) ── POST /transactions  type=INCOME
    │      ├─▶ (Catat pengeluaran) ── POST /transactions  type=EXPENSE
    │      │      ↓ kategori · jumlah · tanggal · keterangan · no. referensi
    │      │      ↓ lampiran scan nota/kwitansi
    │      ├─▶ (Edit) ── PUT /transactions/{id}
    │      └─▶ (Hapus) ── DELETE /transactions/{id}  ▣ soft delete
    │
    ├──▶ [ LAPORAN KEUANGAN ]                       ✅
    │      ├─▶ (Ringkasan) ── GET /finance/summary
    │      ├─▶ (Laporan SPP) ── GET /finance/spp-report
    │      ├─▶ (Export tagihan) ── GET /student-fees/export
    │      │      ↓ kolom: nama · kelas · periode · tagihan · bayar · sisa · status
    │      │      ↓ filter: kelas / periode / status
    │      └─▶ (Export keuangan) ── GET /finance/export
    │
    ├──▶ [ DATA SISWA ]                             ⭕ baca saja
    │      ▣ untuk keperluan penagihan; tidak dapat mengubah data siswa
    │
    └──▶ [ PROFIL & BAHASA ]
    ↓
[ LOGOUT ]
```

#### Penjelasan

| Tahap | Keterangan |
|---|---|
| **Kewenangan penuh (✅)** | Tagihan SPP, Catat Pembayaran, Akuntansi & Kas, Laporan Keuangan |
| **Baca saja (⭕)** | Data Siswa |
| **Tidak diizinkan (❌)** | PPDB, Kelas & Jadwal, Input Nilai, Generate Rapor, Notifikasi (buat), Portal Siswa, Parent Portal, White-label, User Management, Manajemen Tenant |
| **Pembebasan tagihan** | `PATCH /student-fees/{id}/waive` memiliki Auth Level **Admin**, sehingga tindakan ini berada pada Admin Sekolah / Super Admin, bukan Bendahara |
| **⚠ Isu terbuka** | Perilaku generate tagihan untuk frekuensi `YEARLY` dan `ONCE` **Belum dijelaskan dalam blueprint** — SPP-02 hanya membahas tagihan bulanan (PRD §17.3 #9; Roadmap RSK-10) |

---

### 3.7 Orang Tua / Wali Murid

**Level:** Sekolah · **Cakupan:** data anak sendiri

#### Diagram Alur

```
[ LOGIN ]
    ↓
▣ Authentication → ORANG_TUA, school_id = X
    ↓
▣ Sistem mengambil daftar anak ── GET /parent/children
    ↓
    ◆ Jumlah anak?
      ├─▶ 1 anak  → langsung ke dashboard anak tersebut
      └─▶ >1 anak → [ PEMILIH PROFIL ANAK ]  (PORTAL-01 AC-2)
                         ↓ (Pilih anak)
    ↓
[ DASHBOARD ANAK ]  ── GET /parent/children/{studentId}/summary
   • Nilai terbaru        (PORTAL-01: 3 · API Map: 5)  ⚠
   • Kehadiran bulan ini                               ⚠
   • Tagihan belum lunas: jumlah & nominal
   ▣ Responsive untuk tampilan mobile (PORTAL-01 AC-3)
    ↓
    ├──▶ [ NILAI ANAK ]                             ⭕
    │      ↓ GET /parent/children/{studentId}/grades
    │      ↓ ◆ Rapor sudah published?
    │      │    ├─▶ BELUM → tampil nilai real-time per komponen
    │      │    │            (harian / UTS / UAS) — NILAI-04 AC-1
    │      │    └─▶ SUDAH → tampil rapor final
    │      │                 ↓ (Unduh PDF) ── GET /report-cards/{id}/pdf
    │
    ├──▶ [ TAGIHAN ANAK ]                           ⭕  (SPP-04)
    │      ↓ GET /parent/children/{studentId}/fees
    │      ↓ daftar per periode
    │      ↓ ▣ tagihan belum lunas ditandai merah/warning
    │      ↓ riwayat pembayaran: tanggal + metode bayar
    │      ▣ hanya tagihan anaknya sendiri
    │      ▣ TIDAK dapat membayar online (Phase 1: pembayaran manual)
    │
    ├──▶ [ JADWAL PELAJARAN ANAK ]
    │      ↓ GET /parent/children/{studentId}/schedule
    │      ↓ jadwal hari ini & minggu ini
    │
    ├──▶ [ NOTIFIKASI ]                             (NOTIF-04)
    │      ↓ GET /notifications  ▣ hanya notifikasi yang ditujukan padanya
    │      ↓ bell icon + badge unread
    │      ↓ (Klik) → PATCH /notifications/{id}/read
    │      ↓ notifikasi otomatis: tagihan baru · rapor terbit
    │
    ├──▶ (Berpindah ke profil anak lain)  → kembali ke [ DASHBOARD ANAK ]
    │
    └──▶ [ PROFIL & BAHASA ] ── PATCH /auth/me
    ↓
[ LOGOUT ]
```

#### Penjelasan

| Tahap | Keterangan |
|---|---|
| **Kewenangan penuh (✅)** | Parent Portal |
| **Baca saja (⭕)** | Data Siswa, Generate Rapor (lihat hasil), Tagihan SPP |
| **Tidak diizinkan (❌)** | PPDB, Kelas & Jadwal, Input Nilai, Catat Pembayaran, Akuntansi, Laporan Keuangan, Notifikasi (buat), Portal Siswa, White-label, User Management |
| **Pembatasan data** | Hanya dapat melihat data **anaknya sendiri** (`GET /students/{id}/fees`) |
| **Relasi data** | Ditentukan melalui `students.parent_user_id` → `users.id` |
| **⚠ Isu terbuka #1** | Jumlah nilai terbaru pada dashboard: 3 (PORTAL-01) vs 5 (API Map) — PRD §17.3 #4 |
| **⚠ Isu terbuka #2** | "Kehadiran bulan ini" memerlukan data absensi — **Belum dijelaskan dalam blueprint.** |
| **⚠ Isu terbuka #3** | Skenario **dua wali untuk satu siswa**: **Belum dijelaskan dalam blueprint.** (Roadmap RSK-13) |

---

### 3.8 Siswa

**Level:** Sekolah · **Cakupan:** data diri sendiri

#### Diagram Alur

```
[ LOGIN ]
    ↓
▣ Authentication → SISWA, school_id = X
    ↓
[ DASHBOARD SISWA ]  ── GET /student/dashboard
   • Jadwal hari ini
   • 5 nilai terbaru
   • Notifikasi
    ↓
    ├──▶ [ JADWAL ]                                 (PORTAL-03 AC-1)
    │      ↓ GET /student/schedule
    │      ↓ jadwal pelajaran tahun ajaran aktif
    │
    ├──▶ [ NILAI ]                                  (PORTAL-03 AC-2)
    │      ↓ GET /student/grades
    │      ↓ tampil per mata pelajaran · per semester · per komponen
    │      ↓ ◆ Rapor sudah published?
    │      │    ├─▶ BELUM → nilai real-time (harian/UTS/UAS)
    │      │    └─▶ SUDAH → rapor final
    │      │                 ↓ (Unduh PDF) ── GET /report-cards/{id}/pdf
    │
    ├──▶ [ NOTIFIKASI ]                             (PORTAL-03 AC-3)
    │      ↓ GET /notifications  ▣ tampil dengan timestamp
    │      ↓ bell icon + badge unread
    │      ↓ (Klik) → PATCH /notifications/{id}/read
    │      ↓ (Tandai semua) → POST /notifications/mark-all-read
    │
    ├──▶ [ PROFIL ]
    │      ↓ lihat data pribadi sendiri
    │      ↓ (Ubah profil) ── PATCH /auth/me — nama, telepon, avatar, locale
    │      ↓ (Ganti password) ── PATCH /auth/me/password
    │
    └──▶ [ GANTI BAHASA ] ── toggle ID / EN di navbar (AUTH-05)
    ↓
[ LOGOUT ]
```

#### Penjelasan

| Tahap | Keterangan |
|---|---|
| **Kewenangan penuh (✅)** | Portal Siswa |
| **Baca saja (⭕)** | Data Siswa (data dirinya), Kelas & Jadwal, Generate Rapor (melihat hasil) |
| **Tidak diizinkan (❌)** | PPDB, Input Nilai, **Tagihan SPP**, Catat Pembayaran, Akuntansi, Laporan Keuangan, Notifikasi (buat), Parent Portal, White-label, User Management |
| **Catatan penting** | Matriks izin menandai **Tagihan SPP = ❌ untuk Siswa** dan ⭕ untuk Orang Tua. Artinya **siswa tidak melihat tagihannya sendiri** — hanya orang tuanya (PRD §8.2) |
| **Akun opsional** | Kolom `students.user_id` bersifat nullable — seorang siswa **tidak wajib** memiliki akun portal (ERD `students`) |
| **Menu portal** | Empat menu tetap: Jadwal · Nilai · Notifikasi · Profil (PORTAL-03 AC-1) |

---

### 3.9 Ringkasan Perbedaan Alur Antar Role

| Titik Alur | SUPER_ADMIN | SCHOOL_ADMIN | KEPALA | GURU | WALI | BENDAHARA | SISWA | ORTU |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| Login di URL yang sama | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| Terikat pada satu `school_id` | ✘ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| Melewati Global Scope | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| Melihat white-label cabang | — | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| Dashboard lintas cabang | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| Membuat akun pengguna | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| Mengelola data siswa | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| Memproses PPDB | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| Menginput nilai | ✔ | ✔ | ✘ | ✔ | ✔ | ✘ | ✘ | ✘ |
| Menerbitkan rapor | ✔ | ✔ | ✘ | ✘ | ✔ | ✘ | ✘ | ✘ |
| Menerbitkan tagihan | ✔ | ✔ | ✘ | ✘ | ✘ | ✔ | ✘ | ✘ |
| Mencatat pembayaran | ✔ | ✔ | ✘ | ✘ | ✘ | ✔ | ✘ | ✘ |
| Membuat pengumuman | ✔ | ✔ | ✔ | ✘⚠ | ✘⚠ | ✘ | ✘ | ✘ |
| Melihat tagihan | ✔ | ✔ | ✔ | ✘ | ✘ | ✔ | ✘ | ✔ |
| Menerima notifikasi in-app | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |

⚠ Konflik antara matriks izin dan shortcut PORTAL-02 — lihat §3.4.

---

## 4. Authentication Flow

### 4.1 Flow Login

```
[ HALAMAN LOGIN ]
    ↓
(Pengguna memasukkan email + password)
    ↓
▣ RATE LIMIT CHECK — maks 5 percobaan/menit (CON-28)
    ↓ ◆ Melebihi batas?
      ├─▶ YA → ✗ Permintaan ditolak sementara
      └─▶ TIDAK → lanjut
    ↓
POST /auth/login   (Auth Level: Public)
    ↓
▣ VERIFIKASI KREDENSIAL
    ↓ ◆ Email terdaftar & password cocok?
      │
      ├─▶ TIDAK ──▶ ✗ Pesan error yang TIDAK mengungkap detail sistem
      │                (AUTH-01 AC-2) → kembali ke [ HALAMAN LOGIN ]
      │
      └─▶ YA
           ↓
      ▣ CEK STATUS AKUN — users.is_active
           ↓ ⚠ Perilaku bila akun nonaktif: Belum dijelaskan dalam blueprint.
           ↓
      ▣ TERBITKAN TOKEN — Laravel Sanctum
           ↓ masa berlaku: 8 jam tidak aktif (AUTH-01 AC-4)
           ↓
      ▣ LOOKUP users.school_id
           ↓
      ▣ TENANT BOOTSTRAPPING — simpan school_id ke context sesi
           ↓
      ▣ MUAT ROLE & PERMISSION — spatie/laravel-permission
           ↓
      ▣ INJECT WHITE-LABEL — logo_url · primary_color · secondary_color
           ↓
      ▣ CATAT users.last_login_at
           ↓
      ◆ Login pertama kali?
        ├─▶ YA → [ WAJIB GANTI PASSWORD ]  (CON-27; NFR-07)
        │          ↓ PATCH /auth/me/password
        │          ↓
        └─▶ TIDAK ────────────────────────────┐
                                              ↓
                                     [ DASHBOARD sesuai role ]
```

**Response login** memuat: Bearer token + info user + konfigurasi sekolah (logo, warna) — Blueprint §4.2.

### 4.2 Flow Forgot Password

```
[ HALAMAN LOGIN ]
    ↓
(Klik "Lupa Password")
    ↓
[ FORM FORGOT PASSWORD ]
    ↓
(Masukkan email terdaftar)
    ↓
POST /auth/forgot-password   (Auth Level: Public)
    ↓
▣ Sistem mengirim link reset ke email terdaftar (AUTH-04 AC-1)
    ↓
[ PESAN KONFIRMASI ]
    ↓
(Pengguna membuka email → klik link)
    ↓
▣ VALIDASI TOKEN RESET
    ↓ ◆ Link masih berlaku? (maks 60 menit — AUTH-04 AC-2; CON-30)
      ├─▶ TIDAK → ✗ Link kedaluwarsa → ulangi dari awal
      └─▶ YA → lanjut
    ↓
[ FORM PASSWORD BARU ]
    ↓ ▣ validasi: minimal 8 karakter (CON-27)
    ↓
POST /auth/reset-password   (Auth Level: Public)
    ↓
▣ Password di-hash Argon2id
    ↓
▣ SELURUH SESI AKTIF DI-INVALIDATE (AUTH-04 AC-3)
    ↓
[ HALAMAN LOGIN ]  → pengguna login ulang dengan password baru
```

**Jalur alternatif — reset oleh Admin Sekolah:**

```
Admin Sekolah → POST /users/{id}/reset-password
    ↓
▣ Sistem membuat password sementara (PORTAL-04 AC-2)
    ↓
▣ Password sementara dikirim via notifikasi
    ↓
Pengguna login → ▣ wajib ganti password saat login pertama (CON-27)
```

### 4.3 Flow Session

```
▣ TOKEN TERBIT saat login berhasil
    ↓
┌───────────── SIKLUS SESI AKTIF ─────────────┐
│                                             │
│  (Pengguna beraktivitas)                    │
│      ↓                                      │
│  ▣ Setiap request menyertakan token         │
│    Authorization: Bearer {token}            │
│      ↓                                      │
│  ▣ Rate limit API: maks 60 request/menit    │
│    per user (CON-28)                        │
│      ↓                                      │
│  ▣ CSRF token wajib untuk POST/PUT/DELETE   │
│    dari form web (CON-32)                   │
│      ↓                                      │
│  ◆ Tidak aktif ≥ 8 jam?                     │
│    ├─▶ TIDAK → sesi berlanjut ──────────────┘
│    └─▶ YA → ▣ TOKEN KEDALUWARSA
│                ↓
│              lihat §9.4 Session Habis
└─────────────────────────────────────────────┘

Perpanjangan token: POST /auth/refresh  (Auth Level: Auth)
    ↓ memperbarui token yang hampir kedaluwarsa
```

### 4.4 Flow Logout

```
(Pengguna klik Logout)
    ↓
POST /auth/logout   (Auth Level: Auth)
    ↓
▣ Invalidate token sesi aktif
    ↓
▣ Context tenant (school_id) dibersihkan dari sesi
    ↓
⚠ Halaman tujuan setelah logout: Belum dijelaskan dalam blueprint.
```

**Logout yang terjadi tanpa aksi pengguna:**

| Pemicu | Dasar |
|---|---|
| Token kedaluwarsa (8 jam tidak aktif) | AUTH-01 AC-4 |
| Reset password berhasil — seluruh sesi aktif di-invalidate | AUTH-04 AC-3 |
| Akun dinonaktifkan Admin | `DELETE /users/{id}`. Efeknya terhadap sesi yang sedang aktif: **Belum dijelaskan dalam blueprint.** |

### 4.5 Flow Role Validation

```
Request masuk
    ↓
▣ LAPIS 1 — AUTHENTICATION
    ↓ ◆ Token valid?
      ├─▶ TIDAK → ✗ 401 · lihat §9.4
      └─▶ YA → lanjut
    ↓
▣ LAPIS 2 — AUTH LEVEL ENDPOINT  (Blueprint §4.1)
    ↓ ◆ Level yang dibutuhkan?
      ├─▶ Public → lolos tanpa token
      ├─▶ Auth   → cukup token valid
      ├─▶ Admin  → ▣ role harus SCHOOL_ADMIN atau SUPER_ADMIN
      └─▶ Super  → ▣ role harus SUPER_ADMIN
    ↓ ◆ Role memenuhi?
      ├─▶ TIDAK → ✗ Ditolak · lihat §9.3
      └─▶ YA → lanjut
    ↓
▣ LAPIS 3 — TENANT SCOPE
    ↓ ◆ Super Admin?
      ├─▶ YA → lewati Global Scope (akses lintas cabang)
      └─▶ TIDAK → tambahkan WHERE school_id = auth()->user()->school_id
    ↓
lanjut ke Permission Validation (§4.6)
```

### 4.6 Flow Permission Validation

```
▣ LAPIS 4 — MATRIKS IZIN MODUL  (PRD §8.2)
    ↓ ◆ Hak akses role pada modul ini?
      ├─▶ ❌ tidak ada akses → ✗ Ditolak · lihat §9.3
      ├─▶ ⭕ baca saja       → hanya operasi GET diizinkan
      └─▶ ✅ akses penuh     → seluruh operasi diizinkan
    ↓
▣ LAPIS 5 — POLICY PER MODEL  (StudentPolicy, GradePolicy, dll.)
    ↓ aturan spesifik per record:
      • Guru → hanya siswa & nilai pada kelas yang diampu
      • Wali Kelas → hanya rapor kelas perwaliannya
      • Orang Tua → hanya data anaknya sendiri
      • Siswa → hanya data dirinya sendiri
    ↓ ◆ Policy mengizinkan?
      ├─▶ TIDAK → ✗ Ditolak · lihat §9.3
      └─▶ YA → lanjut
    ↓
▣ LAPIS 6 — VALIDASI INPUT  (Laravel Form Request)
    ↓ ◆ Input valid?
      ├─▶ TIDAK → ✗ 422 dengan objek errors
      └─▶ YA → lanjut
    ↓
▣ EKSEKUSI OPERASI
    ↓
▣ AUDIT LOG — bila operasi Create/Update/Delete (CON-33)
    ↓ catat: user · action · table · id · timestamp · IP
    ↓
▣ RESPONSE
    { "success": true, "data": {...}, "message": "..." }
```

### 4.7 Ringkasan Enam Lapis Validasi

| Lapis | Nama | Mekanisme | Kegagalan menuju |
|:---:|---|---|---|
| 1 | Authentication | Laravel Sanctum | §9.4 |
| 2 | Auth Level Endpoint | Route middleware | §9.3 |
| 3 | Tenant Scope | Eloquent Global Scope | Data kosong (bukan error) |
| 4 | Matriks Izin Modul | `spatie/laravel-permission` | §9.3 |
| 5 | Policy per Model | Gate + Policy | §9.3 |
| 6 | Validasi Input | Laravel Form Request | Response 422 |

---

## 5. PPDB Flow

Alur ini melibatkan **dua aktor**: calon siswa/orang tua (publik, tanpa login) dan Admin Sekolah (terautentikasi).

### 5.1 Diagram Alur Lengkap

```
╔══════════════ AKTOR: CALON SISWA / ORANG TUA (PUBLIK) ══════════════╗
║                                                                      ║
║  [ LANDING PPDB ]  ── GET /ppdb/schools   (Public)                   ║
║     ↓ daftar cabang yang membuka PPDB                                ║
║     ↓                                                                ║
║  (Pilih cabang)                                                      ║
║     ↓                                                                ║
║  [ INFO PPDB CABANG ]  ── GET /ppdb/{schoolCode}/info   (Public)     ║
║     ↓ syarat · jadwal · kuota                                        ║
║     ↓                                                                ║
║  [ FORM PENDAFTARAN ]  ── /ppdb/[kode_sekolah]   (Public, no login)  ║
║     ↓ isi: nama lengkap · jenis kelamin · tanggal lahir              ║
║     ↓      asal sekolah · nama ortu · no. HP · email  (PPDB-01 AC-2) ║
║     ↓                                                                ║
║  ( UPLOAD DOKUMEN )                                                  ║
║     ↓ tersimpan pada ppdb_registrations.documents (JSON)             ║
║     ↓ ⚠ batas ukuran & format dokumen: Belum dijelaskan dalam        ║
║     ↓   blueprint.                                                   ║
║     ↓                                                                ║
║  ( SUBMIT )  ── POST /ppdb/{schoolCode}/register                     ║
║     ↓                                                                ║
║  ▣ SISTEM MENERBITKAN NOMOR PENDAFTARAN UNIK                         ║
║     ↓ format: [KODE_CABANG]-[TAHUN]-[SEQ]                            ║
║     ↓ status awal: REGISTERED                                        ║
║     ↓ registered_at terisi                                           ║
║     ↓                                                                ║
║  [ HALAMAN KONFIRMASI ]  → tampil nomor pendaftaran (PPDB-01 AC-3)   ║
║                                                                      ║
╚══════════════════════════════════════════════════════════════════════╝
                                  ↓
╔══════════════════ AKTOR: ADMIN SEKOLAH (LOGIN) ══════════════════════╗
║                                                                      ║
║  [ PANEL PPDB ]  ── GET /admin/ppdb   (Auth Level: Admin)            ║
║     ↓ tabel: no. daftar · nama · asal sekolah · status · tgl daftar  ║
║     ↓ filter per status tersedia            (PPDB-03 AC-1, AC-2)     ║
║     ↓                                                                ║
║  (Buka detail pendaftar) ── GET /admin/ppdb/{id}                     ║
║     ↓                                                                ║
║  ═══ TAHAP REVIEW ═══                                                ║
║     ↓                                                                ║
║  (Ubah status → DOCUMENT_REVIEW) ── PATCH /admin/ppdb/{id}/status    ║
║     ↓ ▣ WAJIB menyertakan catatan alasan (PPDB-03 AC-3)              ║
║     ↓ ▣ tersimpan pada status_notes                                  ║
║     ↓ ▣ TRIGGER notifikasi otomatis (NOTIF-03)                       ║
║     ↓                                                                ║
║  (Periksa berkas yang diunggah)                                      ║
║     ↓                                                                ║
║  ═══ TAHAP KEPUTUSAN ═══                                             ║
║     ↓                                                                ║
║  ◆ Hasil seleksi?                                                    ║
║    │                                                                 ║
║    ├─▶ TIDAK LULUS ── PATCH status → FAILED                          ║
║    │      ↓ + catatan alasan                                         ║
║    │      ↓ (Generate wa.me) ── GET /admin/ppdb/{id}/wa-link         ║
║    │      ↓ (Klik "Buka WhatsApp") → kirim MANUAL                    ║
║    │      ↓ ▣ ALUR BERAKHIR                                          ║
║    │                                                                 ║
║    └─▶ LULUS ── PATCH status → PASSED                                ║
║           ↓ + catatan alasan                                         ║
║           ↓ (Generate wa.me) ── GET /admin/ppdb/{id}/wa-link         ║
║           ↓   template: "Selamat, Ananda [nama] dinyatakan LULUS..." ║
║           ↓ (Klik "Buka WhatsApp") → kirim MANUAL   (PPDB-04 AC-3)   ║
║           ↓                                                          ║
║       ═══ TAHAP ENROLL ═══                                           ║
║           ↓                                                          ║
║       (Klik "Enroll")  ── POST /admin/ppdb/{id}/enroll  (PPDB-05)    ║
║           ↓                                                          ║
║       ▣ Data formulir PPDB OTOMATIS mengisi form siswa baru          ║
║           ↓                                                          ║
║       [ FORM SISWA — PRA-ISI ]                                       ║
║           ↓ (Admin melengkapi data yang belum ada)                   ║
║           ↓   NIS · NISN · agama · alamat · tahun masuk · dll.       ║
║           ↓ ▣ validasi NISN 10 digit · NIS unik per sekolah          ║
║           ↓                                                          ║
║       (Konfirmasi)                                                   ║
║           ↓                                                          ║
║       ▣ Record students TERBENTUK  → status ACTIVE                   ║
║       ▣ ppdb_registrations.converted_student_id terisi               ║
║       ▣ Status PPDB → ENROLLED           (PPDB-05 AC-3)              ║
║           ↓                                                          ║
║       ▣ Siswa siap ditempatkan ke kelas (KELAS-02)                   ║
║                                                                      ║
╚══════════════════════════════════════════════════════════════════════╝
                                  ↓
╔══════════════ AKTOR: CALON SISWA (CEK STATUS KAPAN SAJA) ════════════╗
║                                                                      ║
║  [ HALAMAN CEK STATUS ]  ── GET /ppdb/check-status   (Public)        ║
║     ↓ masukkan: nomor pendaftaran + tanggal lahir                    ║
║     ↓                                                                ║
║  ▣ Tampil status terkini:                                            ║
║     REGISTERED → DOCUMENT_REVIEW → PASSED / FAILED → ENROLLED        ║
║                                              (PPDB-02 AC-2)          ║
╚══════════════════════════════════════════════════════════════════════╝
```

### 5.2 Diagram Transisi Status PPDB

```
   ┌──────────────┐
   │  REGISTERED  │  ← terbentuk saat formulir di-submit
   └──────┬───────┘
          │ Admin memulai review
          ▼
   ┌──────────────────┐
   │ DOCUMENT_REVIEW  │
   └──────┬───────────┘
          │ Admin memutuskan hasil seleksi
     ┌────┴─────┐
     ▼          ▼
┌─────────┐ ┌────────┐
│ PASSED  │ │ FAILED │ ← alur berakhir
└────┬────┘ └────────┘
     │ Admin klik "Enroll"
     ▼
┌──────────┐
│ ENROLLED │ ← record students terbentuk
└──────────┘
```

> Setiap transisi status **wajib disertai catatan alasan** (PPDB-03 AC-3) dan **memicu notifikasi otomatis** (NOTIF-03 AC-1).

### 5.3 Titik Penting dalam Alur PPDB

| Titik | Keterangan |
|---|---|
| **Tanpa login** | Form pendaftaran dan cek status dapat diakses publik (PPDB-01 AC-1, PPDB-02 AC-1) |
| **Tetap ter-scope tenant** | Meski publik, data pendaftar tetap terikat pada `school_id` cabang yang dipilih (CON-14) |
| **Pengiriman WhatsApp manual** | Sistem hanya **menyiapkan link**; admin mengirim satu per satu (PPDB-04 AC-3; CON-49) |
| **Enroll bukan otomatis** | Admin dapat melengkapi data sebelum konfirmasi (PPDB-05 AC-2) |
| **Jembatan ke modul SIS** | Enroll adalah satu-satunya jalur otomatis dari PPDB ke tabel `students` |
| **⚠ Belum dijelaskan** | Batas ukuran/format dokumen PPDB · perilaku bila pendaftar mendaftar dua kali · pembatalan pendaftaran · kuota penuh |

---

## 6. Academic Flow

### 6.1 Diagram Alur Akademik End-to-End

```
╔═══════════ PRASYARAT — AKTOR: ADMIN SEKOLAH ═══════════╗
║                                                         ║
║  (Buat & aktifkan Tahun Ajaran)                         ║
║     ▣ hanya satu TA aktif per sekolah (CON-37)          ║
║     ↓                                                   ║
║  (Buat Mata Pelajaran)                                  ║
║     ↓                                                   ║
║  (Buat Kelas + tetapkan Wali Kelas)                     ║
║     ▣ 1 guru = 1 kelas per TA (CON-36)                  ║
║     ↓                                                   ║
║  (Tempatkan Siswa ke Kelas)                             ║
║     ▣ 1 siswa = 1 kelas per TA (CON-35)                 ║
║     ↓                                                   ║
║  (Tetapkan Guru Pengampu per Mapel per Kelas)           ║
║     → class_subjects terbentuk                          ║
║     ↓                                                   ║
║  (Susun Jadwal Pelajaran)                               ║
║     ▣ deteksi konflik guru / ruang / kelas (CON-48)     ║
║     ↓                                                   ║
║  (Set Konfigurasi Bobot Penilaian)  ── NILAI-05         ║
║     ↓ contoh: Harian 40% · UTS 30% · UAS 30%            ║
║     ↓ dapat berbeda antar mata pelajaran                ║
║     ▣ perubahan hanya berlaku untuk TA baru (CON-42)    ║
╚═════════════════════════════════════════════════════════╝
                          ↓
╔═══════════════ TAHAP 1 — AKTOR: GURU ═══════════════════╗
║                                                          ║
║  [ INPUT NILAI ]  ── NILAI-01                            ║
║     ↓ pilih kelas → mata pelajaran → komponen            ║
║     ↓   (DAILY / MIDTERM / FINAL / ASSIGNMENT /          ║
║     ↓    SKILL / ATTITUDE)                               ║
║     ↓                                                    ║
║  ◆ Metode input?                                         ║
║    ├─▶ Satu per satu   ── POST /grades                   ║
║    ├─▶ Massal sekelas  ── POST /grades/bulk              ║
║    └─▶ Import Excel    ── POST /grades/import            ║
║     ↓                                                    ║
║  ▣ VALIDASI                                              ║
║     ↓ • skala nilai 0–100                                ║
║     ↓ • guru hanya boleh menilai kelas yang diampu       ║
║     ↓ • rapor belum published (CON-40)                   ║
║     ↓                                                    ║
║  ▣ SIMPAN — graded_by & graded_at terisi                 ║
║     ↓                                                    ║
║  ▣ NILAI LANGSUNG TERLIHAT siswa & ortu (NILAI-04 AC-1)  ║
╚══════════════════════════════════════════════════════════╝
                          ↓
╔══════════════ TAHAP 2 — AKTOR: SISTEM ═══════════════════╗
║                                                           ║
║  ▣ HITUNG NILAI AKHIR OTOMATIS  ── NILAI-02               ║
║     ↓                                                     ║
║  Nilai Akhir = (Harian × bobot) + (UTS × bobot)           ║
║                + (UAS × bobot)                            ║
║     ↓                                                     ║
║  ▣ Pembulatan 2 desimal (CON-39)                          ║
║     ↓                                                     ║
║  ⚠ Presedensi bobot: grades.weight vs grade_configs       ║
║    → Belum dijelaskan dalam blueprint. (PRD §17.3 #7)     ║
╚═══════════════════════════════════════════════════════════╝
                          ↓
╔════════════ TAHAP 3 — AKTOR: WALI KELAS ═════════════════╗
║                                                           ║
║  (Generate draft rapor sekelas)                           ║
║     ── POST /report-cards/generate   ▣ Wali Kelas only    ║
║     ↓                                                     ║
║  ▣ VALIDASI PRA-PUBLISH  ── NILAI-03 AC-1                 ║
║     ↓ ◆ Semua mata pelajaran punya nilai akhir?           ║
║       ├─▶ TIDAK → ✗ DITOLAK                               ║
║       │            ↓ kembali ke Tahap 1: lengkapi nilai   ║
║       └─▶ YA → lanjut                                     ║
║     ↓                                                     ║
║  (Lengkapi isi rapor)                                     ║
║     ↓ • nilai sikap A/B/C/D                               ║
║     ↓ • rekap kehadiran: hadir/sakit/izin/alpa      ⚠     ║
║     ↓ • peringkat di kelas (rank_in_class)          ⚠     ║
║     ↓ • catatan wali kelas                                ║
║     ↓                                                     ║
║  (PUBLISH RAPOR) ── POST /report-cards/{id}/publish       ║
║     ↓                                                     ║
║  ▣ is_published = 1 · published_at · published_by         ║
║  ▣ SELURUH NILAI TERKUNCI PERMANEN (CON-40)               ║
║  ▣ TRIGGER notifikasi otomatis ke orang tua (NOTIF-03)    ║
║     ↓                                                     ║
║  ⚠ Tidak ada alur unpublish / koreksi                     ║
║    → Belum dijelaskan dalam blueprint.                    ║
╚═══════════════════════════════════════════════════════════╝
                          ↓
        ┌─────────────────┴─────────────────┐
        ▼                                   ▼
╔══════════════════════╗          ╔══════════════════════════╗
║ TAHAP 4 — SISWA      ║          ║ TAHAP 5 — ORANG TUA      ║
║                      ║          ║                          ║
║ [ PORTAL SISWA ]     ║          ║ [ PARENT PORTAL ]        ║
║  → menu "Nilai"      ║          ║  → pilih profil anak     ║
║    ↓                 ║          ║    ↓                     ║
║ GET /student/grades  ║          ║ GET /parent/children/    ║
║    ↓                 ║          ║     {id}/grades          ║
║ tampil per:          ║          ║    ↓                     ║
║  • mata pelajaran    ║          ║ ◆ Rapor published?       ║
║  • semester          ║          ║   ├─▶ BELUM → nilai      ║
║  • komponen          ║          ║   │    real-time         ║
║    ↓                 ║          ║   └─▶ SUDAH → rapor final║
║ ◆ Rapor published?   ║          ║        ↓                 ║
║   └─▶ YA → rapor     ║          ║ (Unduh PDF)              ║
║        final         ║          ║  GET /report-cards/      ║
║        ↓             ║          ║      {id}/pdf            ║
║ (Unduh PDF)          ║          ║    ↓                     ║
║                      ║          ║ ▣ Notifikasi "rapor      ║
║                      ║          ║   diterbitkan" diterima  ║
╚══════════════════════╝          ╚══════════════════════════╝
```

### 6.2 Aturan yang Mengikat Alur Akademik

| Aturan | Dasar |
|---|---|
| Nilai dalam skala 0–100 | NILAI-01 AC-1; CON-39 |
| Nilai dapat diedit **hanya** selama rapor belum published | NILAI-01 AC-3; CON-40 |
| Publish ditolak bila ada mata pelajaran tanpa nilai akhir | NILAI-03 AC-1; CON-41 |
| Setelah publish, nilai **terkunci permanen** | NILAI-03 AC-2; CON-40 |
| Publish hanya oleh **Wali Kelas** | NILAI-03; `POST /report-cards/generate` |
| Pembulatan hasil perhitungan: 2 desimal | NILAI-02 AC-3 |
| Perubahan konfigurasi bobot hanya berlaku untuk TA baru | NILAI-05 AC-2; CON-42 |
| Guru hanya menilai kelas yang diampunya | `POST /grades` |

### 6.3 Titik ⚠ dalam Alur Akademik

| Titik | Status |
|---|---|
| Rekap kehadiran pada rapor (`attend_present`, `attend_sick`, `attend_permission`, `attend_absent`) | Sumber data: **Belum dijelaskan dalam blueprint.** |
| Rumus dan tie-break `rank_in_class` | **Belum dijelaskan dalam blueprint.** |
| Presedensi bobot `grades.weight` vs `grade_configs.components` | **Belum dijelaskan dalam blueprint.** |
| Mekanisme unpublish / koreksi rapor | **Belum dijelaskan dalam blueprint.** |

---

## 7. Finance Flow

### 7.1 Diagram Alur Keuangan End-to-End

```
╔═════════ TAHAP 1 — GENERATE TAGIHAN · AKTOR: BENDAHARA ═════════╗
║                                                                  ║
║  [ JENIS TAGIHAN ]  ── SPP-01                                    ║
║     ↓ (Buat) ── POST /fee-types                                  ║
║     ↓   nama (SPP / Uang Gedung / dll.)                          ║
║     ↓   jumlah (Rupiah)                                          ║
║     ↓   frekuensi: MONTHLY / YEARLY / ONCE                       ║
║     ↓ ▣ dapat dinonaktifkan tanpa menghapus histori (CON-45)     ║
║     ↓                                                            ║
║  [ GENERATE TAGIHAN MASSAL ]  ── SPP-02                          ║
║     ↓ pilih: fee_type_id · period (YYYY-MM) · due_date           ║
║     ↓                                                            ║
║  ▣ SISTEM MENAMPILKAN PREVIEW DAFTAR TAGIHAN  (WAJIB — CON-47)   ║
║     ↓ ◆ Konfirmasi?                                              ║
║       ├─▶ BATAL → kembali, tidak ada data terbentuk              ║
║       └─▶ YA → POST /student-fees/generate-bulk                  ║
║     ↓                                                            ║
║  ▣ Job berjalan melalui Laravel Queue                            ║
║  ▣ Record student_fees terbentuk untuk SETIAP siswa aktif        ║
║  ▣ status = UNPAID · amount_paid = 0.00 · due_date terisi        ║
║     ↓                                                            ║
║  ▣ TRIGGER notifikasi "tagihan baru terbit" (NOTIF-03 AC-1)      ║
║     ↓                                                            ║
║  ⚠ Perilaku untuk frekuensi YEARLY dan ONCE:                     ║
║    Belum dijelaskan dalam blueprint. (PRD §17.3 #9)              ║
╚══════════════════════════════════════════════════════════════════╝
                              ↓
╔═════════ TAHAP 2 — ORANG TUA MELIHAT TAGIHAN ═══════════════════╗
║                                                                  ║
║  [ PARENT PORTAL → TAGIHAN ]  ── SPP-04                          ║
║     ↓ GET /parent/children/{studentId}/fees                      ║
║     ↓ daftar per periode                                         ║
║     ↓ ▣ tagihan belum lunas DITANDAI MERAH/WARNING               ║
║     ↓ riwayat pembayaran: tanggal + metode bayar                 ║
║     ↓ ▣ hanya tagihan anaknya sendiri                            ║
║     ↓                                                            ║
║  ▣ Orang tua TIDAK dapat membayar melalui aplikasi               ║
║    (Phase 1 tidak memiliki payment gateway — CON-50)             ║
╚══════════════════════════════════════════════════════════════════╝
                              ↓
╔═════════ TAHAP 3 — PEMBAYARAN (DI LUAR SISTEM) ═════════════════╗
║                                                                  ║
║  Orang tua membayar secara CASH atau TRANSFER                    ║
║     ↓                                                            ║
║  ⚠ Alur konfirmasi pembayaran dari orang tua ke sekolah          ║
║    (mis. unggah bukti oleh ortu): Belum dijelaskan dalam         ║
║    blueprint. Blueprint hanya menyebut Bendahara yang            ║
║    mengunggah bukti (SPP-03 AC-2).                               ║
╚══════════════════════════════════════════════════════════════════╝
                              ↓
╔═══ TAHAP 4 — VERIFIKASI & PENCATATAN · AKTOR: BENDAHARA ════════╗
║                                                                  ║
║  [ CATAT PEMBAYARAN ]  ── SPP-03 · POST /payments                ║
║     ↓ isi: student_fee_id · jumlah · metode (CASH/TRANSFER)      ║
║     ↓      tanggal · nomor referensi                             ║
║     ↓                                                            ║
║  (Unggah bukti) ── POST /payments/{id}/proof                     ║
║     ↓ ▣ JPG / PNG / PDF · maksimal 5 MB (CON-44)                 ║
║     ↓                                                            ║
║  ▣ received_by terisi (bendahara yang mencatat)                  ║
║  ▣ amount_paid pada student_fees TERAKUMULASI                    ║
║     ↓                                                            ║
║  ◆ Status tagihan otomatis:                                      ║
║    ├─▶ amount_paid < amount  → PARTIAL                           ║
║    └─▶ amount_paid = amount  → PAID                              ║
║     ↓                                                            ║
║  ▣ Cicilan didukung: satu tagihan → banyak record payments       ║
║     ↓                                                            ║
║  ─── JALUR ALTERNATIF: PEMBEBASAN TAGIHAN ───                    ║
║  Admin Sekolah ── PATCH /student-fees/{id}/waive                 ║
║     ↓ + waive_reason                                             ║
║     ↓ ▣ status → WAIVED                                          ║
║     ↓ ▣ Auth Level: Admin (bukan Bendahara)                      ║
╚══════════════════════════════════════════════════════════════════╝
                              ↓
╔═════════ TAHAP 5 — BUKU KAS UMUM · AKTOR: BENDAHARA ════════════╗
║                                                                  ║
║  [ BUKU KAS ]  ── KAS-01 · POST /transactions                    ║
║     ↓ type: INCOME / EXPENSE                                     ║
║     ↓ kategori: Gaji · Pembelian Alat · Dana BOS · Sumbangan     ║
║     ↓ jumlah · tanggal · keterangan · no. referensi              ║
║     ↓ lampiran scan nota / kwitansi                              ║
║     ↓ created_by terisi                                          ║
║     ↓                                                            ║
║  ▣ Buku kas mencatat transaksi UMUM di luar tagihan SPP          ║
╚══════════════════════════════════════════════════════════════════╝
                              ↓
╔═════════════════ TAHAP 6 — PELAPORAN ═══════════════════════════╗
║                                                                  ║
║  ┌── AKTOR: BENDAHARA ──────────────────────────────────┐        ║
║  │  (Export laporan tagihan) ── GET /student-fees/export│        ║
║  │     ↓ kolom: nama siswa · kelas · periode · jumlah   │        ║
║  │     ↓        tagihan · jumlah bayar · sisa · status  │        ║
║  │     ↓ filter: kelas / periode / status    (SPP-05)   │        ║
║  │  (Export laporan keuangan) ── GET /finance/export    │        ║
║  └──────────────────────────────────────────────────────┘        ║
║                                                                  ║
║  ┌── AKTOR: KEPALA SEKOLAH / ADMIN ─────────────────────┐        ║
║  │  [ DASHBOARD KEUANGAN CABANG ]  ── KAS-02            │        ║
║  │     ↓ saldo kas                                      │        ║
║  │     ↓ total penerimaan SPP bulan ini                 │        ║
║  │     ↓ total pengeluaran bulan ini                    │        ║
║  │     ↓ ▣ GRAFIK TREN 6 BULAN TERAKHIR                 │        ║
║  │     ↓ GET /finance/summary · /finance/spp-report     │        ║
║  └──────────────────────────────────────────────────────┘        ║
║                                                                  ║
║  ┌── AKTOR: SUPER ADMIN ────────────────────────────────┐        ║
║  │  [ DASHBOARD LINTAS CABANG ]  ── KAS-03              │        ║
║  │     ↓ per cabang: total tagihan · total terkumpul    │        ║
║  │     ↓             PERSENTASE LUNAS                   │        ║
║  │     ↓ filter: tahun ajaran / bulan                   │        ║
║  │     ↓ ▣ Global Scope dilewati → seluruh cabang       │        ║
║  └──────────────────────────────────────────────────────┘        ║
╚══════════════════════════════════════════════════════════════════╝
```

### 7.2 Diagram Transisi Status Tagihan

```
   ┌──────────┐
   │  UNPAID  │  ← terbentuk saat generate tagihan massal
   └────┬─────┘
        │
   ┌────┴─────────────────────┬──────────────────────┐
   │ pembayaran sebagian      │ pembayaran lunas     │ dibebaskan Admin
   ▼                          ▼                      ▼
┌─────────┐               ┌────────┐            ┌────────┐
│ PARTIAL │──pelunasan──▶ │  PAID  │            │ WAIVED │
└─────────┘               └────────┘            └────────┘
                                                 + waive_reason
```

### 7.3 Aktor dan Kewenangan dalam Alur Keuangan

| Aktor | Kewenangan | Tidak Diizinkan |
|---|---|---|
| **Bendahara** | Buat jenis tagihan · Generate massal · Catat pembayaran · Buku kas · Laporan & export | Membebaskan tagihan (Auth Level Admin) |
| **Admin Sekolah** | Seluruh kewenangan keuangan + pembebasan tagihan | — |
| **Kepala Sekolah** | ⭕ Melihat tagihan, akuntansi, dan laporan | ❌ Mencatat pembayaran |
| **Super Admin** | Seluruhnya + dashboard lintas cabang | — |
| **Orang Tua** | ⭕ Melihat tagihan & riwayat pembayaran anaknya | Membayar melalui aplikasi |
| **Siswa** | ❌ Tidak memiliki akses ke modul tagihan sama sekali | — |
| **Guru / Wali Kelas** | ❌ Tidak memiliki akses ke modul keuangan | — |

### 7.4 Titik ⚠ dalam Alur Keuangan

| Titik | Status |
|---|---|
| Perilaku generate tagihan frekuensi `YEARLY` dan `ONCE` | **Belum dijelaskan dalam blueprint.** |
| Alur konfirmasi/unggah bukti bayar oleh orang tua | **Belum dijelaskan dalam blueprint.** — blueprint hanya menyebut Bendahara yang mengunggah bukti |
| Alur penanganan tagihan yang lewat jatuh tempo (reminder tunggakan) | **Belum dijelaskan dalam blueprint.** — `due_date` tersimpan, namun tidak ada user story reminder otomatis |
| Pembatalan atau koreksi pembayaran yang salah catat | **Belum dijelaskan dalam blueprint.** |
| Metode `PAYMENT_GATEWAY` pada ENUM `payments.payment_method` | Tersedia di ERD, namun integrasinya baru pada Phase 2 |

---

## 8. Notification Flow

### 8.1 Diagram Alur Notifikasi

```
                    ╔═══════════════════════════╗
                    ║      SUMBER NOTIFIKASI    ║
                    ╚═══════════════════════════╝
                                  │
            ┌─────────────────────┴─────────────────────┐
            ▼                                           ▼
╔═══════════════════════════╗              ╔═══════════════════════════╗
║   A. PENGUMUMAN MANUAL    ║              ║  B. TRIGGER OTOMATIS      ║
║   Aktor: Admin Sekolah /  ║              ║  Aktor: Sistem            ║
║          Kepala Sekolah   ║              ║                           ║
║                           ║              ║  ▣ Status PPDB berubah    ║
║  (Buat pengumuman)        ║              ║  ▣ Tagihan baru terbit    ║
║   ── POST /notifications  ║              ║  ▣ Rapor diterbitkan      ║
║     ↓                     ║              ║        (NOTIF-03 AC-1)    ║
║  isi: judul · pesan       ║              ║     ↓                     ║
║  kategori:                ║              ║  ▣ Sistem memakai template║
║   ACADEMIC / BILLING /    ║              ║    yang tersimpan di      ║
║   EMERGENCY / GENERAL     ║              ║    tabel schools:         ║
║     ↓                     ║              ║     • wa_template_ppdb    ║
║  ◆ Target?                ║              ║     • wa_template_spp     ║
║   ├─▶ ALL → semua user    ║              ║     • wa_template_rapor   ║
║   │    aktif di cabang    ║              ║     ↓                     ║
║   ├─▶ CLASS → hanya ORANG ║              ║  ▣ Template dapat diedit  ║
║   │    TUA siswa kelas    ║              ║    Admin Sekolah          ║
║   │    tersebut           ║              ║       (NOTIF-03 AC-2)     ║
║   └─▶ INDIVIDUAL →        ║              ║                           ║
║        satu pengguna      ║              ║  ▣ sender_id = NULL       ║
║     ↓                     ║              ║    (notifikasi sistem)    ║
║  ◆ Simpan sebagai?        ║              ║                           ║
║   ├─▶ Draft (is_draft=1)  ║              ║                           ║
║   └─▶ Kirim (is_draft=0)  ║              ║                           ║
║        ↓ sent_at terisi   ║              ║                           ║
╚═══════════════════════════╝              ╚═══════════════════════════╝
            │                                           │
            └─────────────────────┬─────────────────────┘
                                  ▼
                    ╔═══════════════════════════╗
                    ║   DUA KANAL PENYAMPAIAN   ║
                    ╚═══════════════════════════╝
                                  │
            ┌─────────────────────┴─────────────────────┐
            ▼                                           ▼
╔═══════════════════════════╗              ╔═══════════════════════════╗
║  KANAL 1 — IN-APP         ║              ║  KANAL 2 — WHATSAPP       ║
║       (NOTIF-04)          ║              ║       (NOTIF-02)          ║
║                           ║              ║                           ║
║  ▣ Muncul di bell icon    ║              ║  Admin membuka daftar     ║
║    seluruh portal         ║              ║  penerima                 ║
║     ↓                     ║              ║   ── GET /notifications/  ║
║  ▣ Badge menampilkan      ║              ║      {id}/wa-links        ║
║    jumlah belum dibaca    ║              ║     ↓                     ║
║   ── GET /notifications/  ║              ║  ▣ Sistem generate URL    ║
║      unread-count         ║              ║    per penerima:          ║
║     ↓                     ║              ║    wa.me/62[nomorHP]      ║
║  (Pengguna klik)          ║              ║      ?text=[ter-encode]   ║
║   ── PATCH /notifications/║              ║     ↓                     ║
║      {id}/read            ║              ║  ▣ Daftar dapat difilter  ║
║     ↓                     ║              ║    dan disalin satu-satu  ║
║  ▣ Tercatat di            ║              ║     ↓                     ║
║    notification_reads     ║              ║  (Klik "Buka WA")         ║
║    (read_at)              ║              ║     ↓                     ║
║     ↓                     ║              ║  ▣ WhatsApp terbuka       ║
║  (Tandai semua dibaca)    ║              ║    dengan pesan terisi    ║
║   ── POST /notifications/ ║              ║     ↓                     ║
║      mark-all-read        ║              ║  ▣ ADMIN MENGIRIM MANUAL  ║
║     ↓                     ║              ║    SATU PER SATU (CON-49) ║
║  ▣ Riwayat disimpan       ║              ║                           ║
║    90 HARI (CON-46)       ║              ║  ⚠ Tidak ada pengiriman   ║
║                           ║              ║    otomatis pada Phase 1  ║
╚═══════════════════════════╝              ╚═══════════════════════════╝
            │                                           │
            └─────────────────────┬─────────────────────┘
                                  ▼
                    ╔═══════════════════════════╗
                    ║        PENERIMA           ║
                    ║  Orang Tua · Siswa ·      ║
                    ║  Guru · seluruh role      ║
                    ║  sesuai target            ║
                    ╚═══════════════════════════╝
```

### 8.2 Flow Pengumuman (Manual)

```
Admin Sekolah / Kepala Sekolah  → [ BUAT PENGUMUMAN ]
    ↓
(Isi judul + isi pesan + kategori)
    ↓
(Pilih target)
    ├─▶ ALL         → seluruh user aktif di cabang    (NOTIF-01 AC-2)
    ├─▶ CLASS       → hanya orang tua siswa kelas itu (NOTIF-01 AC-3)
    └─▶ INDIVIDUAL  → satu pengguna (target_id = user_id)
    ↓
◆ Simpan sebagai draft atau kirim?
    ├─▶ DRAFT → is_draft = 1, tersimpan tanpa dikirim
    └─▶ KIRIM → is_draft = 0, sent_at terisi
                    ↓
              ▣ Notifikasi masuk ke bell icon penerima
              ▣ Daftar wa.me link tersedia bagi admin
    ↓
(Admin membuka daftar wa.me) → kirim manual per penerima
    ↓
(Admin dapat meninjau seluruh pengumuman) ── GET /admin/notifications
    ↓ termasuk yang berstatus draft
```

### 8.3 Flow Notifikasi Otomatis

| Event Pemicu | Modul Sumber | Template | Penerima |
|---|---|---|---|
| **Status PPDB berubah** | PPDB (PPDB-03) | `wa_template_ppdb` | Calon siswa / orang tua pendaftar |
| **Tagihan baru terbit** | Keuangan (SPP-02) | `wa_template_spp` | Orang tua siswa |
| **Rapor diterbitkan** | E-Rapor (NILAI-03) | `wa_template_rapor` | Orang tua siswa |

```
▣ Event terjadi di modul sumber
    ↓
▣ Sistem membuat record notifications  (sender_id = NULL)
    ↓ type sesuai kategori · target sesuai penerima
    ↓
▣ DUA KELUARAN BERSAMAAN  (NOTIF-03 AC-3)
    ├─▶ Notification center in-app (bell icon)
    └─▶ wa.me link tersedia bagi Admin untuk dikirim manual
```

> Ketiga trigger di atas adalah **satu-satunya** trigger otomatis yang didefinisikan blueprint (NOTIF-03 AC-1).

### 8.4 Flow Reminder

| Aspek | Status |
|---|---|
| **Reminder tagihan jatuh tempo** | Blueprint menyimpan `student_fees.due_date`, dan Parent Portal menandai tagihan belum lunas dengan warna merah/warning (SPP-04 AC-2). Namun **tidak ada user story reminder otomatis menjelang atau setelah jatuh tempo**. → **Belum dijelaskan dalam blueprint.** |
| **Reminder ketidakhadiran (notifikasi alpa)** | Tercantum sebagai fitur **Phase 2** pada modul Presensi Digital, bukan Phase 1 |
| **Reminder batas kembali buku** | Tercantum sebagai fitur **Phase 2** pada modul E-Library |
| **Reminder terjadwal lainnya** | **Belum dijelaskan dalam blueprint.** |

**Kesimpulan:** pada Phase 1, satu-satunya bentuk "pengingat" yang tersedia adalah **penandaan visual** tagihan belum lunas di Parent Portal dan **pengumuman manual** yang dibuat Admin. Tidak ada mekanisme reminder terjadwal.

### 8.5 Titik ⚠ dalam Alur Notifikasi

| Titik | Status |
|---|---|
| Aturan normalisasi nomor HP ke awalan `62` | **Belum dijelaskan dalam blueprint.** (PRD §17.3 #14) |
| Penanganan penerima yang tidak memiliki WhatsApp | **Belum dijelaskan dalam blueprint.** |
| Kewenangan Guru membuat pengumuman | Konflik antara matriks izin (❌) dan shortcut PORTAL-02 (tersedia) |
| Perbedaan daftar kategori | NOTIF-01 menyebut `ACADEMIC`/`BILLING`/`EMERGENCY`/`GENERAL`; ERD menyebut `ANNOUNCEMENT`/`BILLING`/`ACADEMIC`/`EMERGENCY`/`SYSTEM` |
| Konfirmasi bahwa pesan WhatsApp benar-benar terkirim | Tidak dapat dilacak sistem karena pengiriman manual — **Belum dijelaskan dalam blueprint.** |

---

## 9. Error Flow

> **Catatan penting:** blueprint mendefinisikan **format response error API** (`{ "success": false, "message": "...", "errors": {...} }`) dan beberapa perilaku error spesifik. Namun **desain halaman error, teks pesan, dan alur pemulihan bagi pengguna sebagian besar tidak dijelaskan**. Setiap butir di bawah menandai secara tegas mana yang berasal dari blueprint dan mana yang tidak.

### 9.1 Login Gagal

```
[ HALAMAN LOGIN ]
    ↓
(Submit email + password)
    ↓
▣ RATE LIMIT — maks 5 percobaan/menit (CON-28)
    ↓ ◆ Melebihi batas?
      ├─▶ YA → ✗ Permintaan ditolak sementara
      │         ⚠ Pesan & durasi blokir: Belum dijelaskan dalam blueprint.
      └─▶ TIDAK → lanjut
    ↓
◆ Kredensial cocok?
  └─▶ TIDAK
       ↓
    ✗ TAMPILKAN PESAN ERROR
       ▣ Pesan TIDAK BOLEH mengungkap detail sistem (AUTH-01 AC-2)
       ▣ Artinya: tidak membedakan "email tidak terdaftar"
         vs "password salah"
       ↓
    kembali ke [ HALAMAN LOGIN ]
       ↓
    (Pengguna dapat memilih "Lupa Password" → §4.2)
```

| Aspek | Status |
|---|---|
| Pesan error tidak mengungkap detail sistem | ✔ Blueprint — AUTH-01 AC-2 |
| Rate limit 5 percobaan/menit | ✔ Blueprint — §3.4; CON-28 |
| Teks pesan error yang tepat | **Belum dijelaskan dalam blueprint.** |
| Durasi pemblokiran setelah melebihi rate limit | **Belum dijelaskan dalam blueprint.** |
| Perilaku bila akun berstatus `is_active = 0` | **Belum dijelaskan dalam blueprint.** |
| Mekanisme CAPTCHA atau penguncian akun | **Belum dijelaskan dalam blueprint.** |

### 9.2 Data Tidak Ditemukan

```
(Pengguna meminta data — mis. GET /students/{id})
    ↓
▣ Query dijalankan DENGAN Global Scope
    ↓ WHERE school_id = auth()->user()->school_id
    ↓
◆ Data ditemukan?
  ├─▶ YA → tampilkan data
  └─▶ TIDAK
       ↓
     ◆ Penyebabnya?
       ├─▶ Data memang tidak ada
       └─▶ Data ada, tetapi milik cabang lain
            ▣ Global Scope menyaringnya → tampak "tidak ditemukan"
            ▣ Ini adalah PERILAKU YANG DIKEHENDAKI: pengguna tidak
              boleh mengetahui keberadaan data cabang lain (AUTH-02 AC-3)
       ↓
     ✗ Response error
       { "success": false, "message": "...", "errors": {...} }
       ↓
     ⚠ Kode status HTTP, teks pesan, dan tampilan halaman:
       Belum dijelaskan dalam blueprint.
```

| Aspek | Status |
|---|---|
| Format response error | ✔ Blueprint — §4.1 |
| Data cabang lain tersaring Global Scope | ✔ Blueprint — AUTH-02 AC-3 |
| Kode status HTTP untuk data tidak ditemukan | **Belum dijelaskan dalam blueprint.** |
| Tampilan halaman "data tidak ditemukan" | **Belum dijelaskan dalam blueprint.** |

### 9.3 Tidak Memiliki Hak Akses

```
(Pengguna mencoba mengakses modul / melakukan aksi)
    ↓
▣ Melewati enam lapis validasi (§4.7)
    ↓
◆ Lapis mana yang menolak?
  │
  ├─▶ LAPIS 2 — Auth Level endpoint tidak terpenuhi
  │     contoh: GURU mengakses POST /students (butuh Admin)
  │
  ├─▶ LAPIS 4 — Matriks izin modul menandai ❌ atau ⭕
  │     contoh: BENDAHARA mencoba POST /grades (Input Nilai = ❌)
  │     contoh: KEPALA_SEKOLAH mencoba POST /payments
  │              (Catat Pembayaran = ❌)
  │
  └─▶ LAPIS 5 — Policy per model menolak record spesifik
        contoh: GURU A menilai kelas yang diampu GURU B
        contoh: ORANG TUA membuka tagihan anak orang lain
    ↓
✗ AKSES DITOLAK
    ▣ Response: { "success": false, "message": "...", "errors": {...} }
    ▣ Idealnya menu modul tersebut memang tidak ditampilkan
      sejak awal (pencegahan di lapis navigasi)
    ↓
⚠ Kode status HTTP, teks pesan, dan halaman "akses ditolak":
  Belum dijelaskan dalam blueprint.
```

| Aspek | Status |
|---|---|
| Enam lapis validasi | ✔ Blueprint — §3.4, §4.1, matriks izin |
| Format response error | ✔ Blueprint — §4.1 |
| Kode status HTTP (401/403) | **Belum dijelaskan dalam blueprint.** |
| Halaman khusus "akses ditolak" | **Belum dijelaskan dalam blueprint.** |
| Apakah upaya akses ilegal dicatat khusus | Audit log mencakup aksi CUD; pencatatan upaya akses yang ditolak: **Belum dijelaskan dalam blueprint.** |

### 9.4 Session Habis

```
(Pengguna tidak beraktivitas ≥ 8 jam)
    ↓
▣ TOKEN KEDALUWARSA (AUTH-01 AC-4; CON-29)
    ↓
(Pengguna melakukan aksi berikutnya)
    ↓
▣ Validasi token gagal
    ↓
✗ SESI TIDAK VALID
    ↓
⚠ Perilaku setelah sesi habis — apakah pengguna diarahkan ke
  halaman login, apakah muncul peringatan sebelum kedaluwarsa,
  dan apakah pekerjaan yang belum tersimpan dipertahankan:
  Belum dijelaskan dalam blueprint.
    ↓
Jalur pencegahan yang tersedia:
  POST /auth/refresh → memperbarui token yang hampir kedaluwarsa
```

**Penyebab lain sesi berakhir:**

| Penyebab | Dasar |
|---|---|
| Reset password berhasil → seluruh sesi aktif di-invalidate | AUTH-04 AC-3 |
| Logout manual | `POST /auth/logout` |
| Akun dinonaktifkan Admin | Efek terhadap sesi aktif: **Belum dijelaskan dalam blueprint.** |

### 9.5 Error 404 — Halaman Tidak Ditemukan

| Aspek | Status |
|---|---|
| Halaman 404, desain, dan isinya | **Belum dijelaskan dalam blueprint.** |
| Yang dijelaskan blueprint | Konfigurasi Nginx `try_files $uri $uri/ /index.php?$query_string` — seluruh request yang tidak cocok diteruskan ke Laravel (§3.3.2) |
| Format response error API | ✔ `{ "success": false, "message": "...", "errors": {...} }` (§4.1) |

### 9.6 Error 500 — Kesalahan Server

| Aspek | Status |
|---|---|
| Halaman 500, desain, dan isinya | **Belum dijelaskan dalam blueprint.** |
| Prinsip yang dapat dijadikan acuan | Pesan error tidak boleh mengungkap detail sistem — dinyatakan blueprint untuk konteks login (AUTH-01 AC-2) |
| Pemantauan | Monitoring uptime aktif via UptimeRobot / Better Stack (Lampiran A.3 #10) |
| Penanganan error, logging, dan alerting | **Belum dijelaskan dalam blueprint.** |
| Prosedur incident response | **Belum dijelaskan dalam blueprint.** |

### 9.7 Error Validasi Input

```
(Pengguna submit form)
    ↓
▣ Laravel Form Request memvalidasi seluruh input (§3.4)
    ↓
◆ Valid?
  └─▶ TIDAK
       ↓
     ✗ Response error dengan objek errors
       { "success": false, "message": "...", "errors": {...} }
       ↓
     Contoh validasi yang dinyatakan blueprint:
       • NISN bukan 10 digit angka          (SIS-01 AC-2)
       • NIS duplikat dalam satu sekolah    (SIS-01 AC-3)
       • Foto melebihi 2 MB                 (SIS-03 AC-2)
       • Bukti bayar melebihi 5 MB          (SPP-03 AC-2)
       • Password kurang dari 8 karakter    (NFR-07)
       • Nilai di luar skala 0–100          (NILAI-01 AC-1)
       • Konflik jadwal guru/ruang/kelas    (KELAS-03 AC-2)
       • 1 siswa di 2 kelas pada TA sama    (KELAS-02 AC-2)
       • 1 guru jadi wali 2 kelas pada TA sama (KELAS-01 AC-3)
       • Publish rapor tanpa nilai lengkap  (NILAI-03 AC-1)
       ↓
     ⚠ Teks pesan validasi dalam Bahasa Indonesia dan English:
       harus tersedia dua bahasa (AUTH-05 AC-3), namun bunyi
       pesannya: Belum dijelaskan dalam blueprint.
```

### 9.8 Ringkasan Error Flow

| Jenis Error | Ditangani blueprint? | Catatan |
|---|:---:|---|
| Login gagal | Sebagian | Prinsip pesan + rate limit ada; teks pesan tidak |
| Data tidak ditemukan | Sebagian | Format response ada; kode status & halaman tidak |
| Tidak punya hak akses | Sebagian | Mekanisme penolakan ada; kode status & halaman tidak |
| Session habis | Sebagian | Masa berlaku token ada; perilaku setelah kedaluwarsa tidak |
| 404 | Tidak | **Belum dijelaskan dalam blueprint.** |
| 500 | Tidak | **Belum dijelaskan dalam blueprint.** |
| Validasi input | Sebagian | Aturan validasi ada; teks pesan tidak |

---

## 10. Security Flow

### 10.1 Diagram Lapisan Keamanan

```
                     (Request dari pengguna)
                              ↓
        ╔═════════════════════════════════════════════╗
        ║  LAPIS 0 — TRANSPORT                        ║
        ║  ▣ HTTPS wajib (TLS 1.2/1.3)                ║
        ║  ▣ Redirect HTTP → HTTPS via Nginx          ║
        ║  ▣ HSTS header aktif                        ║
        ║  ▣ Cloudflare: proteksi DDoS                ║
        ║  ▣ CORS: hanya apps.smartsukses.sch.id      ║
        ╚═════════════════════════════════════════════╝
                              ↓
        ╔═════════════════════════════════════════════╗
        ║  LAPIS 1 — RATE LIMITING                    ║
        ║  ▣ Login: maks 5 percobaan/menit            ║
        ║  ▣ API: maks 60 request/menit per user      ║
        ║    (Laravel Throttle Middleware)            ║
        ╚═════════════════════════════════════════════╝
                              ↓
        ╔═════════════════════════════════════════════╗
        ║  LAPIS 2 — AUTHENTICATION                   ║
        ║  ▣ Laravel Sanctum (SPA mode)               ║
        ║  ▣ Cookie-based session token untuk web     ║
        ║  ▣ Bearer token untuk API                   ║
        ║  ▣ Password: Argon2id, min 8 karakter       ║
        ║  ▣ Wajib ganti password saat login pertama  ║
        ║  ▣ Token kedaluwarsa: 8 jam tidak aktif     ║
        ╚═════════════════════════════════════════════╝
                              ↓
        ╔═════════════════════════════════════════════╗
        ║  LAPIS 3 — CSRF PROTECTION                  ║
        ║  ▣ Token CSRF wajib untuk POST/PUT/DELETE   ║
        ║    dari form web (Laravel CSRF Middleware)  ║
        ╚═════════════════════════════════════════════╝
                              ↓
        ╔═════════════════════════════════════════════╗
        ║  LAPIS 4 — AUTHORIZATION (RBAC)             ║
        ║  ▣ spatie/laravel-permission + Gate         ║
        ║  ▣ Auth Level: Public / Auth / Admin / Super║
        ║  ▣ Matriks izin 15 modul × 8 role           ║
        ║  ▣ Policy per model: StudentPolicy,         ║
        ║    GradePolicy, dll.                        ║
        ╚═════════════════════════════════════════════╝
                              ↓
        ╔═════════════════════════════════════════════╗
        ║  LAPIS 5 — TENANT ISOLATION                 ║
        ║  ▣ Eloquent Global Scope                    ║
        ║  ▣ WHERE school_id WAJIB pada semua query   ║
        ║  ▣ Super Admin (school_id=NULL) melewatinya ║
        ║  ▣ Diverifikasi via unit test — 100%        ║
        ║  ▣ TOLERANSI KEBOCORAN: NOL (NFR-06)        ║
        ╚═════════════════════════════════════════════╝
                              ↓
        ╔═════════════════════════════════════════════╗
        ║  LAPIS 6 — INPUT VALIDATION                 ║
        ║  ▣ Laravel Form Request                     ║
        ║  ▣ Sanitasi XSS via htmlspecialchars        ║
        ║  ▣ SQL Injection: Eloquent parameterized;   ║
        ║    raw SQL dilarang kecuali DB::select()    ║
        ║    dengan binding                           ║
        ║  ▣ Upload: validasi MIME + ukuran;          ║
        ║    disimpan di storage/ (di luar web root)  ║
        ╚═════════════════════════════════════════════╝
                              ↓
                    ▣ EKSEKUSI OPERASI
                              ↓
        ╔═════════════════════════════════════════════╗
        ║  LAPIS 7 — AUDIT LOG                        ║
        ║  ▣ Custom Middleware + Event                ║
        ║  ▣ Seluruh aksi Create/Update/Delete dicatat║
        ║  ▣ Isi: user · action · table · id ·        ║
        ║         timestamp · IP                      ║
        ║  ⚠ Tabel audit_logs tidak ada dalam daftar  ║
        ║    21 tabel ERD → strukturnya Belum         ║
        ║    dijelaskan dalam blueprint.              ║
        ╚═════════════════════════════════════════════╝
                              ↓
                    ▣ RESPONSE ke pengguna
```

### 10.2 Flow Authentication (Keamanan)

| Aspek | Implementasi | Dasar |
|---|---|---|
| Mekanisme | Laravel Sanctum (SPA mode) | §3.4 |
| Token web | Cookie-based session token | §3.4 |
| Token API | Bearer token (untuk API mobile *future*) | §3.4 |
| Hash password | Argon2id (Laravel default) | §3.4 |
| Panjang password minimum | 8 karakter | NFR-07 |
| Ganti password pertama | **Wajib** saat login pertama | NFR-07; CON-27 |
| Masa berlaku token | 8 jam tidak aktif | AUTH-01 AC-4 |
| Link reset password | Berlaku 60 menit | AUTH-04 AC-2 |
| Invalidasi setelah reset | Seluruh sesi aktif di-invalidate | AUTH-04 AC-3 |

### 10.3 Flow Authorization

```
▣ Auth Level pada endpoint (§4.1 blueprint)
    ├─▶ Public → tanpa token
    ├─▶ Auth   → token valid, data ter-scope school_id
    ├─▶ Admin  → token + role SCHOOL_ADMIN / SUPER_ADMIN
    └─▶ Super  → token + role SUPER_ADMIN
    ↓
▣ Gate + Policy per model
    ├─▶ StudentPolicy → siapa boleh melihat/mengubah siswa mana
    ├─▶ GradePolicy   → guru hanya kelas yang diampu
    └─▶ dst. per model
```

### 10.4 Flow RBAC

```
▣ Paket: spatie/laravel-permission
    ↓
▣ 8 role terdaftar:
    Platform : SUPER_ADMIN
    Sekolah  : SCHOOL_ADMIN · KEPALA_SEKOLAH · GURU · WALI_KELAS ·
               SISWA · ORANG_TUA · BENDAHARA
    ↓
▣ Setiap pengguna memiliki TEPAT SATU peran utama (Blueprint §1.1)
    ↓
▣ Assignment tersimpan di tabel model_has_roles
    ↓
▣ Matriks izin 15 modul × 8 role menentukan:
    ✅ akses penuh · ⭕ baca saja · ❌ tidak ada akses
    ↓
⚠ Pemisahan permission antara GURU dan WALI_KELAS secara rinci:
  Belum dijelaskan dalam blueprint. (matriks menggabungkan keduanya)
```

### 10.5 Flow Audit Log

```
(Pengguna melakukan aksi Create / Update / Delete)
    ↓
▣ Custom Middleware + Event menangkap aksi
    ↓
▣ Dicatat: user · action · table · id · timestamp · IP
    ↓
▣ Tersimpan di tabel audit_logs
    ↓
⚠ Struktur tabel audit_logs, masa retensi, dan siapa yang berhak
  membaca log: Belum dijelaskan dalam blueprint.
  (tabel ini tidak termasuk dalam daftar 21 entitas ERD)
```

### 10.6 Flow Session (Keamanan)

| Aspek | Ketentuan | Dasar |
|---|---|---|
| Pembentukan | Token terbit saat login berhasil | AUTH-01 AC-3 |
| Isolasi tenant | `school_id` tersimpan di context sesi oleh TenantMiddleware | §3.2.2 |
| Masa berlaku | 8 jam tidak aktif | AUTH-01 AC-4 |
| Perpanjangan | `POST /auth/refresh` | §4.2 blueprint |
| Pengakhiran manual | `POST /auth/logout` | §4.2 blueprint |
| Pengakhiran paksa | Reset password → seluruh sesi di-invalidate | AUTH-04 AC-3 |
| Perlindungan request | CSRF token untuk POST/PUT/DELETE | §3.4 |
| Pembatasan laju | 60 request/menit per user | §3.4 |

### 10.7 Verifikasi Keamanan Sebelum Go-Live

| # | Butir | Dasar |
|:---:|---|---|
| 1 | Unit test Global Scope (tenant isolation) lulus **100%** | Lampiran A.3 #1 |
| 2 | Uji akses lintas-tenant: user Madani tidak bisa melihat data Cinangka | Lampiran A.3 #2 |
| 6 | SSL aktif dan redirect HTTP→HTTPS berjalan | Lampiran A.3 #6 |
| 8 | Seluruh password default diubah (MySQL root, admin panel) | Lampiran A.3 #8 |
| 9 | CORS hanya menerima dari `apps.smartsukses.sch.id` | Lampiran A.3 #9 |
| 10 | Monitoring uptime diaktifkan | Lampiran A.3 #10 |

### 10.8 Titik ⚠ dalam Alur Keamanan

| Titik | Status |
|---|---|
| Struktur tabel `audit_logs` | **Belum dijelaskan dalam blueprint.** |
| Masa retensi audit log | **Belum dijelaskan dalam blueprint.** |
| Siapa yang berhak membaca audit log | **Belum dijelaskan dalam blueprint.** |
| Pencatatan upaya akses yang ditolak | **Belum dijelaskan dalam blueprint.** |
| Mekanisme two-factor authentication | **Belum dijelaskan dalam blueprint.** |
| Kebijakan kedaluwarsa password berkala | **Belum dijelaskan dalam blueprint.** |
| Prosedur incident response bila terjadi kebocoran | **Belum dijelaskan dalam blueprint.** |

---

## 11. Module Dependency

Bagian ini menjelaskan **urutan penggunaan modul oleh pengguna** — berbeda dari urutan pengembangan pada Roadmap §5, yang mengatur urutan pengerjaan oleh developer.

### 11.1 Urutan Penggunaan Modul

```
        ┌──────────────────────────────────────────┐
        │  1. MANAJEMEN TENANT / CABANG            │
        │     Aktor: SUPER_ADMIN                   │
        │     → cabang terdaftar + white-label     │
        └────────────────────┬─────────────────────┘
                             ↓
        ┌──────────────────────────────────────────┐
        │  2. USER MANAGEMENT                      │
        │     Aktor: SUPER_ADMIN / SCHOOL_ADMIN    │
        │     → akun untuk seluruh role di cabang  │
        └────────────────────┬─────────────────────┘
                             ↓
        ┌──────────────────────────────────────────┐
        │  3. TAHUN AJARAN                         │
        │     Aktor: SCHOOL_ADMIN                  │
        │     → satu TA aktif per sekolah          │
        └────────────────────┬─────────────────────┘
                             ↓
        ┌──────────────────────────────────────────┐
        │  4. DATA SISWA (SIS)                     │
        │     Aktor: SCHOOL_ADMIN                  │
        │     → dari input manual, import Excel,   │
        │       atau hasil enroll PPDB             │
        └───────┬──────────────────────┬───────────┘
                ↓                      ↓
    ┌───────────────────────┐  ┌──────────────────────────┐
    │  5a. PPDB             │  │  5b. KELAS & ROMBEL      │
    │  Aktor: publik +      │  │  Aktor: SCHOOL_ADMIN     │
    │         SCHOOL_ADMIN  │  │  → siswa masuk kelas     │
    │  → menghasilkan siswa │  └────────────┬─────────────┘
    │    baru (feed ke 4)   │               ↓
    └───────────────────────┘  ┌──────────────────────────┐
                               │  6. MAPEL & PENGAMPU     │
                               │  → class_subjects        │
                               └────────────┬─────────────┘
                                            ↓
                    ┌───────────────────────┴──────────────┐
                    ↓                                      ↓
        ┌──────────────────────┐              ┌──────────────────────┐
        │  7a. JADWAL          │              │  7b. PENILAIAN       │
        │  Aktor: ADMIN        │              │  Aktor: GURU         │
        │  → jadwal mingguan   │              │  → nilai per komponen│
        └──────────┬───────────┘              └──────────┬───────────┘
                   │                                     ↓
                   │                          ┌──────────────────────┐
                   │                          │  8. E-RAPOR          │
                   │                          │  Aktor: WALI_KELAS   │
                   │                          │  → rapor + PDF       │
                   │                          └──────────┬───────────┘
                   │                                     │
        ┌──────────┴─────────────────────────────────────┴───────────┐
        │                                                            │
        │  ── JALUR PARALEL: KEUANGAN ──                             │
        │                                                            │
        │  ┌──────────────────────┐                                  │
        │  │  9. JENIS TAGIHAN    │  Aktor: BENDAHARA                │
        │  └──────────┬───────────┘                                  │
        │             ↓                                              │
        │  ┌──────────────────────┐                                  │
        │  │ 10. TAGIHAN SISWA    │  ← membutuhkan daftar siswa (4)  │
        │  └──────────┬───────────┘                                  │
        │             ↓                                              │
        │  ┌──────────────────────┐   ┌──────────────────────┐       │
        │  │ 11. PEMBAYARAN       │   │ 12. BUKU KAS         │       │
        │  └──────────┬───────────┘   └──────────┬───────────┘       │
        │             └───────────┬──────────────┘                   │
        │                         ↓                                  │
        │              ┌──────────────────────┐                      │
        │              │ 13. LAPORAN & DASBOR │                      │
        │              └──────────────────────┘                      │
        └────────────────────────┬───────────────────────────────────┘
                                 ↓
                     ┌──────────────────────────┐
                     │ 14. NOTIFIKASI           │
                     │  ← trigger dari PPDB (5a)│
                     │    tagihan (10), rapor(8)│
                     └────────────┬─────────────┘
                                  ↓
                     ┌──────────────────────────┐
                     │ 15. PORTAL               │
                     │  Parent · Siswa · Guru   │
                     │  ← mengonsumsi 4,7,8,10, │
                     │    11, 14                │
                     └──────────────────────────┘
```

### 11.2 Prasyarat Penggunaan per Modul

| Modul | Tidak dapat digunakan sebelum | Alasan |
|---|---|---|
| User Management | Cabang terdaftar | Akun harus terikat pada `school_id` |
| Tahun Ajaran | Cabang terdaftar | `academic_years.school_id` |
| Data Siswa | Tahun ajaran aktif tersedia | Penempatan kelas dan tagihan merujuk TA |
| PPDB | Tahun ajaran tersedia · tabel `students` siap | Enroll menulis ke `students` |
| Kelas & Rombel | Data siswa & akun guru tersedia | Butuh siswa untuk ditempatkan, guru untuk wali kelas |
| Mapel & Pengampu | Kelas tersedia · akun guru tersedia | `class_subjects` merujuk keduanya |
| Jadwal | `class_subjects` tersedia | `schedules.class_subject_id` |
| Penilaian | `class_subjects` + konfigurasi bobot tersedia | Nilai merujuk `class_subject_id` |
| E-Rapor | Seluruh mapel memiliki nilai akhir | Validasi pra-publish (CON-41) |
| Jenis Tagihan | Cabang terdaftar | `fee_types.school_id` |
| Tagihan Siswa | Daftar siswa aktif tersedia | Generate massal menyasar siswa aktif |
| Pembayaran | Tagihan sudah terbit | `payments.student_fee_id` |
| Laporan & Dashboard | Tagihan, pembayaran, dan kas terisi | Sumber data agregasi |
| Notifikasi | Daftar user aktif tersedia | Target `ALL`/`CLASS`/`INDIVIDUAL` |
| Portal | Nilai, tagihan, jadwal, notifikasi tersedia | Portal adalah agregator |

### 11.3 Urutan Operasional per Periode

```
═══ AWAL TAHUN AJARAN ═══
  1. Admin membuat & mengaktifkan Tahun Ajaran baru
  2. Admin membuka PPDB → pendaftar diproses → enroll jadi siswa
  3. Admin membuat Kelas + menetapkan Wali Kelas
  4. Admin menempatkan siswa ke kelas
  5. Admin menetapkan guru pengampu per mapel
  6. Admin menyusun jadwal pelajaran
  7. Admin menetapkan konfigurasi bobot penilaian
  8. Bendahara membuat jenis tagihan untuk TA berjalan

═══ SETIAP BULAN ═══
  1. Bendahara generate tagihan SPP bulanan
  2. Sistem memicu notifikasi "tagihan baru terbit"
  3. Orang tua melihat tagihan di Parent Portal
  4. Bendahara mencatat pembayaran yang masuk
  5. Bendahara mencatat transaksi kas
  6. Kepala Sekolah memantau dashboard keuangan

═══ SEPANJANG SEMESTER ═══
  1. Guru mengajar sesuai jadwal
  2. Guru menginput nilai per komponen
  3. Siswa & orang tua memantau nilai real-time
  4. Admin/Kepala Sekolah menyebar pengumuman bila perlu

═══ AKHIR SEMESTER ═══
  1. Guru melengkapi seluruh komponen nilai
  2. Sistem menghitung nilai akhir
  3. Wali Kelas generate draft rapor
  4. Wali Kelas melengkapi & publish rapor
  5. Sistem memicu notifikasi "rapor diterbitkan"
  6. Siswa & orang tua mengunduh rapor PDF
```

---

## 12. User Journey Summary

### 12.1 Ringkasan Perjalanan per Role

| Role | Titik Masuk | Dashboard | Tugas Utama | Modul yang Diakses | Keluaran | Titik Keluar |
|---|---|---|---|---|---|---|
| **Super Admin** | Login (`school_id` = NULL) | Ringkasan seluruh cabang | Kelola tenant · pantau seluruh cabang · atur white-label | Seluruh 15 modul (✅) | Cabang baru aktif · laporan lintas cabang | Logout |
| **Admin Sekolah** | Login | Operasional cabang ⚠ | Kelola akun · data siswa · PPDB · kelas & jadwal · keuangan · pengumuman | 14 modul (✅), kecuali Manajemen Tenant | Data induk lengkap · siswa ter-enroll · pengumuman tersebar | Logout |
| **Kepala Sekolah** | Login | Saldo kas · SPP · pengeluaran · tren 6 bulan | Memantau akademik & keuangan · membuat pengumuman | 1 modul ✅ (Notifikasi) · 9 modul ⭕ | Keputusan berbasis data · pengumuman | Logout |
| **Guru** | Login | Jadwal hari ini · kelas aktif · notifikasi | Mengajar sesuai jadwal · menginput nilai | 1 modul ✅ (Input Nilai) · 3 modul ⭕ | Nilai tersimpan & terlihat siswa/ortu | Logout |
| **Wali Kelas** | Login | Sama seperti Guru | Seluruh tugas Guru + menerbitkan rapor kelas perwalian | Akses Guru + ✅ Generate Rapor | Rapor terbit & terkunci · PDF tersedia | Logout |
| **Bendahara** | Login | Dashboard Bendahara ⚠ | Menerbitkan tagihan · mencatat pembayaran · membukukan kas · menyusun laporan | 4 modul ✅ · 1 modul ⭕ | Tagihan terbit · pembayaran tercatat · laporan Excel | Logout |
| **Orang Tua** | Login | Nilai terbaru · kehadiran ⚠ · tagihan belum lunas | Memantau nilai, kehadiran, dan tagihan anak | 1 modul ✅ (Parent Portal) · 3 modul ⭕ | Informasi anak terpantau · rapor PDF terunduh | Logout |
| **Siswa** | Login | Jadwal hari ini · 5 nilai terbaru · notifikasi | Melihat jadwal, nilai, dan notifikasi | 1 modul ✅ (Portal Siswa) · 3 modul ⭕ | Jadwal & nilai diketahui · rapor PDF terunduh | Logout |
| **Calon Siswa** *(publik)* | Halaman PPDB (tanpa login) | — | Mendaftar · mengunggah dokumen · memantau status | PPDB publik saja | Nomor pendaftaran · status seleksi · menjadi siswa bila lulus | Menutup halaman |

### 12.2 Ringkasan Frekuensi Penggunaan

| Role | Frekuensi Khas | Puncak Aktivitas |
|---|---|---|
| Super Admin | Berkala | Saat penambahan cabang · tinjauan bulanan |
| Admin Sekolah | Harian | Awal tahun ajaran (setup) · musim PPDB |
| Kepala Sekolah | Berkala | Akhir bulan (laporan keuangan) · akhir semester |
| Guru | Harian | Akhir semester (input nilai) |
| Wali Kelas | Harian | Akhir semester (generate & publish rapor) |
| Bendahara | Harian | Awal bulan (generate tagihan) · tanggal jatuh tempo |
| Orang Tua | Berkala | Akhir semester (rapor) · awal bulan (tagihan) |
| Siswa | Berkala | Setelah guru menginput nilai · penerbitan rapor |
| Calon Siswa | Sekali | Musim PPDB |

> Pola frekuensi di atas adalah pembacaan atas sifat alur, bukan pernyataan blueprint. Blueprint tidak memuat data pola penggunaan — **Belum dijelaskan dalam blueprint.**

### 12.3 Ringkasan Titik Interaksi Antar Role

| Alur | Role Terlibat | Titik Serah-Terima |
|---|---|---|
| **PPDB → Siswa** | Calon Siswa → Admin Sekolah | Admin klik "Enroll" → record `students` terbentuk |
| **Penilaian → Rapor** | Guru → Wali Kelas | Wali Kelas generate rapor setelah seluruh nilai lengkap |
| **Rapor → Portal** | Wali Kelas → Siswa & Orang Tua | Publish rapor → tampil di portal + notifikasi otomatis |
| **Tagihan → Pembayaran** | Bendahara → Orang Tua → Bendahara | Generate tagihan → ortu membayar di luar sistem → bendahara mencatat |
| **Keuangan → Monitoring** | Bendahara → Kepala Sekolah → Super Admin | Data kas & tagihan mengalir ke dashboard berjenjang |
| **Pengumuman → Penerima** | Admin/Kepala Sekolah → seluruh role | In-app langsung; WhatsApp dikirim manual oleh Admin |

### 12.4 Perjalanan Terpanjang dalam Sistem

Perjalanan dari calon siswa hingga menerima rapor pertama melibatkan **5 role** dan **6 modul**:

```
[Calon Siswa]  daftar PPDB
      ↓
[Admin Sekolah]  review → LULUS → enroll → jadi siswa
      ↓
[Admin Sekolah]  tempatkan ke kelas → tetapkan jadwal
      ↓
[Bendahara]  terbitkan tagihan SPP
      ↓
[Guru]  input nilai sepanjang semester
      ↓
[Wali Kelas]  generate & publish rapor
      ↓
[Siswa & Orang Tua]  lihat nilai + unduh rapor PDF + terima notifikasi
```

---

## 13. Future Improvement

Bagian ini **hanya memuat hal yang disebutkan blueprint**. Fitur Phase 2 di bawah akan mengubah beberapa alur pengguna pada dokumen ini.

### 13.1 Perubahan Alur akibat Fitur Phase 2

| Fitur Phase 2 | Alur yang Berubah | Perubahan yang Terjadi |
|---|---|---|
| **Presensi Digital** (absensi GPS/selfie, rekap bulanan, notifikasi alpa) | §3.4 Guru · §3.5 Wali Kelas · §3.7 Orang Tua · §6 Academic Flow | Menyediakan sumber data kehadiran yang saat ini menjadi titik ⚠ pada SIS-04, PORTAL-01, dan rekap kehadiran rapor |
| **Payment Gateway** (Midtrans/Xendit) | §7 Finance Flow — Tahap 3 & 4 | Orang tua dapat membayar **langsung di aplikasi**; pembayaran tidak lagi harus dicatat manual oleh Bendahara. ENUM `PAYMENT_GATEWAY` pada `payments.payment_method` menjadi aktif |
| **WhatsApp API** (Fonnte / Meta Cloud API) | §8 Notification Flow — Kanal 2 | Pengiriman WhatsApp menjadi **otomatis**; Admin tidak perlu lagi klik "Buka WA" satu per satu |
| **LMS — Ruang Kelas Virtual** (Google Meet) | §3.4 Guru · §3.8 Siswa | Menambah alur sesi belajar daring dan link meeting per kelas |
| **LMS — CBT / Ujian Online** | §6 Academic Flow | Menambah alur pembuatan soal, pengerjaan ujian, dan kunci otomatis |
| **LMS — Bank Materi** | §3.8 Siswa | Siswa dapat mengakses materi PDF/video kapan saja |
| **Konseling & BK** | Alur baru | Pencatatan pelanggaran/prestasi, skor poin, rekam jejak bimbingan |
| **E-Library** | Alur baru | Katalog buku, peminjaman, notifikasi batas kembali |
| **Manajemen Inventaris** | Alur baru | Pendataan aset sekolah |
| **Payroll Guru & Staf** | Alur baru | Komponen gaji, potongan, slip gaji PDF |
| **DAPODIK Export** | §3.2 Admin Sekolah | Menambah aksi export data siswa ke format Kemdikbud |

### 13.2 Prasyarat Phase 2

| Prasyarat | Dasar |
|---|---|
| Phase 1 telah go-live dan memenuhi checklist Lampiran A.3 | CON-56 |
| Phase 1 berjalan **stabil minimal 3 bulan** | CON-54; SM-13 |
| Uptime ≥ 99% per bulan selama masa tersebut | NFR-08 |

### 13.3 Peningkatan Infrastruktur yang Disebutkan Blueprint

| Komponen | Kondisi Phase 1 | Rencana |
|---|---|---|
| File Storage | Local VPS disk | Backblaze B2 |
| Cache | Laravel Cache (DB driver) | Redis |
| Backup | Lokal di VPS | Diunggah ke Backblaze B2 |
| Kapasitas server | VPS 2C/2GB | Skalabilitas vertikal |
| Notifikasi WhatsApp | wa.me manual (gratis) | Fonnte (~Rp 150.000/bln) |

### 13.4 Arah Pengembangan Lain

| Arah | Dasar |
|---|---|
| Dukungan API untuk aplikasi mobile — Bearer token sudah disiapkan | §3.4 |
| Penambahan bahasa antarmuka di luar ID/EN | NFR-11 "dapat diperluas" |
| Penambahan cabang hingga 10+ tanpa refactoring | NFR-03 |

### 13.5 Hal yang Tidak Disebutkan Blueprint

Perbaikan alur pengguna berikut **tidak** disebutkan blueprint dan karenanya **tidak** dimasukkan sebagai rencana:

> Desain halaman landing umum · alur onboarding pengguna baru · notifikasi push browser · reminder otomatis jatuh tempo · alur unpublish rapor · impersonate oleh Super Admin · two-factor authentication · dark mode · pencarian global · ekspor data pribadi pengguna.
>
> **Belum dijelaskan dalam blueprint.**

---

## 14. Lampiran

### 14.1 Referensi Silang: Alur ↔ Functional Requirement

| Section | FR Terkait |
|---|---|
| §2 Global Application Flow | AUTH-01, AUTH-02, AUTH-03 |
| §3.1 Super Admin | KAS-03 + seluruh modul |
| §3.2 Admin Sekolah | PORTAL-04, SIS-01…05, PPDB-01…05, KELAS-01…04, SPP-01…05, KAS-01…03, NOTIF-01…02 |
| §3.3 Kepala Sekolah | KAS-02, NOTIF-01 |
| §3.4 Guru | SIS-04, KELAS-04, NILAI-01, PORTAL-02, NOTIF-04 |
| §3.5 Wali Kelas | NILAI-01, NILAI-03, PORTAL-02 |
| §3.6 Bendahara | SPP-01…05, KAS-01 |
| §3.7 Orang Tua | PORTAL-01, NILAI-04, SPP-04, NOTIF-04 |
| §3.8 Siswa | PORTAL-03, NILAI-04, NOTIF-04 |
| §4 Authentication Flow | AUTH-01, AUTH-02, AUTH-04, PORTAL-04 |
| §5 PPDB Flow | PPDB-01…05, NOTIF-03 |
| §6 Academic Flow | NILAI-01…05, KELAS-01…03, NOTIF-03 |
| §7 Finance Flow | SPP-01…05, KAS-01…03, NOTIF-03 |
| §8 Notification Flow | NOTIF-01…04 |
| §9 Error Flow | AUTH-01 AC-2 + format response §4.1 |
| §10 Security Flow | AUTH-02 + §3.4 Arsitektur Keamanan |

### 14.2 Daftar Titik ⚠ dalam Seluruh Alur

| # | Titik | Section | Isu PRD §17.3 |
|:---:|---|---|:---:|
| 1 | Halaman landing umum aplikasi | §2.2 | — |
| 2 | Isi dashboard Admin Sekolah | §2.2 | — |
| 3 | Isi dashboard Bendahara | §2.2, §3.6 | — |
| 4 | Impersonate oleh Super Admin | §3.1 | — |
| 5 | Alur approval Kepala Sekolah | §3.3 | #6 |
| 6 | Kewenangan Guru membuat pengumuman | §3.4, §8.5 | #3 |
| 7 | Sumber data kehadiran | §3.4, §3.5, §3.7, §6.3 | #1 |
| 8 | Rumus `rank_in_class` | §3.5, §6.3 | #13 |
| 9 | Mekanisme unpublish / koreksi rapor | §3.5, §6.3 | #8 |
| 10 | Presedensi bobot nilai | §6.1, §6.3 | #7 |
| 11 | Jumlah nilai terbaru dashboard ortu (3 vs 5) | §2.2, §3.7 | #4 |
| 12 | Skenario dua wali untuk satu siswa | §3.7 | — |
| 13 | Generate tagihan `YEARLY` / `ONCE` | §3.6, §7.4 | #9 |
| 14 | Alur unggah bukti bayar oleh orang tua | §7.4 | — |
| 15 | Reminder tagihan jatuh tempo | §7.4, §8.4 | — |
| 16 | Pembatalan / koreksi pembayaran | §7.4 | — |
| 17 | Normalisasi nomor HP ke awalan `62` | §8.5 | #14 |
| 18 | Penerima tanpa WhatsApp | §8.5 | — |
| 19 | Batas ukuran & format dokumen PPDB | §5.1, §5.3 | — |
| 20 | Perilaku login akun nonaktif | §4.1, §9.1 | — |
| 21 | Halaman & kode status error (404, 500, 403) | §9.5, §9.6 | — |
| 22 | Perilaku setelah sesi habis | §9.4 | — |
| 23 | Halaman tujuan setelah logout | §2.2, §4.4 | — |
| 24 | Struktur & retensi `audit_logs` | §10.5, §10.8 | #2 |
| 25 | Pemisahan permission GURU vs WALI_KELAS | §10.4 | — |

### 14.3 Referensi Dokumen

| Dokumen | Peran |
|---|---|
| `blueprint/SmartSukses_FullBlueprint_v1.0.0.docx` | Sumber kebenaran tunggal seluruh requirement |
| `docs/01-Analisis-Blueprint.md` | Analisis awal blueprint |
| `docs/01-PRD.md` (v1.1) | 38 FR · 12 NFR · 21 ASM · 56 CON · AC per modul · 14 isu terbuka |
| `docs/02-ROADMAP.md` (v1.1) | 11 fase · 9 sprint · 92 deliverable · 32 risiko · milestone · DoD |
| `docs/03-USER_FLOW.md` | Dokumen ini — alur pengguna 8 role + publik |

---

## Riwayat Revisi Dokumen

| Versi | Tanggal | Penulis | Keterangan |
|---|---|---|---|
| v1.0 | — | Tim Pengembang | User Flow awal, diturunkan dari `SmartSukses_FullBlueprint_v1.0.0.docx`, `docs/01-PRD.md` v1.1, dan `docs/02-ROADMAP.md` v1.1 |

---

*Dokumen ini disusun sepenuhnya berdasarkan `blueprint/SmartSukses_FullBlueprint_v1.0.0.docx`, `docs/01-PRD.md`, dan `docs/02-ROADMAP.md`. Tidak ada requirement yang ditambahkan, dikurangi, atau diubah. Seluruh informasi yang tidak tercantum dalam blueprint ditandai secara eksplisit sebagai "Belum dijelaskan dalam blueprint."*

**Smart Sukses School · User Flow v1.0 · KONFIDENSIAL**
