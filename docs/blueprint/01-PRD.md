# Product Requirements Document (PRD)
## Smart Sukses School — Platform SaaS Manajemen Sekolah Multi-Cabang

---

| Parameter | Detail |
|---|---|
| **Nama Produk** | Smart Sukses School |
| **Platform** | `apps.smartsukses.sch.id` |
| **Versi PRD** | v1.1 |
| **Sumber Requirement** | `blueprint/SmartSukses_FullBlueprint_v1.0.0.docx` (v1.0.0 · Agustus 2025 · Status: DRAFT) |
| **Model Produk** | Multi-Tenant · Single Domain · White-Label per Cabang |
| **Fase Dokumen** | Phase 1 (MVP — detail) + Phase 2 (roadmap — overview) |
| **Sifat Dokumen** | Turunan blueprint. Tidak menambah, mengurangi, atau mengubah requirement. |
| **Kerahasiaan** | KONFIDENSIAL — hanya untuk penggunaan internal |

> **Konvensi dokumen:** Setiap informasi yang tidak tercantum dalam blueprint ditulis sebagai **"Belum dijelaskan dalam blueprint."**

---

## Daftar Isi

| No | Section |
|---|---|
| 1 | [Overview](#1-overview) |
| 2 | [Product Vision](#2-product-vision) |
| 3 | [Objectives](#3-objectives) |
| 4 | [Problem Statement](#4-problem-statement) |
| 5 | [Scope](#5-scope) |
| 6 | [Assumptions](#6-assumptions) |
| 7 | [Constraints](#7-constraints) |
| 8 | [User Roles](#8-user-roles) |
| 9 | [Functional Requirements](#9-functional-requirements) |
| 10 | [Acceptance Criteria per Modul Utama](#10-acceptance-criteria-per-modul-utama) |
| 11 | [Non Functional Requirements](#11-non-functional-requirements) |
| 12 | [Dependencies Antar Modul](#12-dependencies-antar-modul) |
| 13 | [Success Metrics](#13-success-metrics) |
| 14 | [Out of Scope](#14-out-of-scope) |
| 15 | [Future Development](#15-future-development) |
| 16 | [Glossary](#16-glossary) |
| 17 | [Lampiran](#17-lampiran) |

---

## 1. Overview

### 1.1 Latar Belakang

Smart Sukses School adalah jaringan SMA Terbuka yang terdiri dari beberapa cabang — **Smart Pusat**, **Smart Madani**, **Smart Cinangka**, serta cabang-cabang yang akan berkembang. Saat ini pengelolaan operasional (data siswa, penilaian, tagihan SPP, hingga komunikasi dengan orang tua) masih dilakukan secara manual menggunakan spreadsheet dan aplikasi terpisah-pisah, sehingga sulit dimonitor secara terpusat oleh Pusat.

### 1.2 Deskripsi Produk

Platform SaaS berbasis web dengan arsitektur **multi-tenant single domain**. Setiap cabang memiliki data terisolasi (diidentifikasi melalui `school_id`) namun berjalan dalam satu aplikasi yang sama. Setiap cabang dapat memiliki tampilan white-label sendiri (logo dan warna khas). Semua pengguna mengakses URL yang sama; visibilitas data ditentukan oleh peran (role) dan sekolah tempat pengguna terdaftar.

### 1.3 Karakteristik Produk

| Karakteristik | Nilai |
|---|---|
| Jenis produk | Aplikasi web (SaaS) |
| Akses | Satu URL tunggal: `apps.smartsukses.sch.id` |
| Pola isolasi data | Shared Database, Shared Schema — kolom `school_id` |
| Identitas visual | White-label per cabang (logo, warna primer, warna sekunder) |
| Bahasa antarmuka | Bilingual — Bahasa Indonesia (default) & English |
| Kurikulum | Kustom per sekolah — tidak mengikuti format Merdeka/K13 secara kaku |
| Perangkat | Desktop (Chrome/Firefox/Edge) + Mobile (browser iOS/Android) |

### 1.4 Target Skala Awal

| Parameter | Detail |
|---|---|
| Jumlah Cabang | 3 cabang (Pusat, Madani, Cinangka) — dapat ditambah tanpa pengembangan ulang |
| Siswa per Cabang | 50–200 siswa |
| Total Pengguna Awal | ~800–1.500 akun (siswa, guru, orang tua, admin, bendahara) |

### 1.5 Stack Teknologi

| Layer | Teknologi | Lisensi / Biaya |
|---|---|---|
| Backend Framework | Laravel 11 (PHP 8.3) | Open-source (MIT) |
| Admin Panel | Filament PHP 3 | Open-source (MIT) |
| Multi-tenancy | `spatie/laravel-multitenancy` | Open-source (MIT) |
| Frontend Portal | Livewire 3 + Alpine.js | Open-source (MIT) |
| CSS Framework | Tailwind CSS 3 | Open-source |
| Auth & RBAC | Laravel Sanctum + `spatie/laravel-permission` | Open-source |
| Database | MySQL 8.0 | Open-source (GPL) |
| Cache & Queue | Laravel Cache/Queue (database driver) | Gratis (dalam Laravel) |
| PDF Generation | DomPDF / Browsershot | Open-source |
| Storage | Local disk / Backblaze B2 | Gratis s.d. 10 GB (Backblaze) |
| Email | SMTP (Gmail / Mailtrap) | Gratis |
| DNS & SSL | Cloudflare Free + Let's Encrypt | Gratis |
| Web Server | Nginx + PHP-FPM | Open-source |
| OS | Ubuntu 22.04 LTS | Open-source |
| Hosting | VPS 2 Core / 2 GB RAM / 40 GB SSD | ~Rp 85.000–120.000/bln |
| WhatsApp Notifikasi | wa.me Manual Link (Phase 1) | Gratis |
| Video Conference | Google Meet Link Generation | Gratis |

Total estimasi biaya infrastruktur setelah go-live: **Rp 90.000–130.000 per bulan**.

---

## 2. Product Vision

> **Menjadi satu sumber kebenaran (single source of truth) bagi seluruh operasional akademik, keuangan, dan komunikasi jaringan Smart Sukses School — di mana setiap cabang berjalan mandiri dengan identitas visualnya sendiri, namun tetap dapat dimonitor secara terpusat dan real-time oleh Pusat, dengan biaya operasional yang sangat terjangkau.**

### 2.1 Prinsip Produk (turunan dari blueprint)

| Prinsip | Penjelasan | Dasar di Blueprint |
|---|---|---|
| **Satu aplikasi, banyak cabang** | Satu instance Laravel melayani semua tenant; penambahan cabang tidak memerlukan pengembangan ulang | §3.2 Arsitektur Multi-Tenant; §3.3.1 |
| **Isolasi data mutlak** | Tidak boleh ada kebocoran data antar cabang, dalam kondisi apa pun | NFR Keamanan — Data isolation 100% |
| **Identitas cabang tetap terjaga** | White-label logo & warna per cabang, berlaku tanpa deployment ulang | AUTH-03; §3.2.3 |
| **Transparansi untuk orang tua** | Orang tua memiliki akses langsung dan mandiri ke nilai, absensi, dan tagihan anak | Masalah #5; PORTAL-01 |
| **Hemat biaya** | Seluruh stack open-source, satu VPS kelas ekonomi | §Stack Teknologi; Lampiran A.2 |
| **Dapat diakses siapa saja** | Bilingual ID/EN, responsive mobile | AUTH-05; PORTAL-01; NFR Aksesibilitas |

---

## 3. Objectives

### 3.1 Objective Bisnis

| ID | Objective | Dasar di Blueprint |
|---|---|---|
| **OBJ-01** | Menyediakan **single source of truth** untuk data siswa seluruh cabang | Ringkasan Eksekutif — Masalah #1 |
| **OBJ-02** | Memungkinkan Kepala Sekolah dan Admin Pusat **memonitor kondisi akademik dan keuangan semua cabang secara real-time** | Masalah #2; KAS-03; `GET /admin/dashboard` |
| **OBJ-03** | **Mendigitalkan proses PPDB** dari pendaftaran publik hingga konversi menjadi siswa aktif | Masalah #3; PPDB-01…PPDB-05 |
| **OBJ-04** | Memastikan **tagihan SPP terbit tepat waktu dan pembayarannya terlacak** | Masalah #4; SPP-01…SPP-05 |
| **OBJ-05** | Memberi **orang tua dan siswa akses mandiri** ke nilai, absensi, dan tagihan | Masalah #5; PORTAL-01, PORTAL-03 |
| **OBJ-06** | **Menstrukturkan komunikasi sekolah** melalui pengumuman terpusat dan notifikasi wa.me | Masalah #6; NOTIF-01…NOTIF-04 |
| **OBJ-07** | Menjalankan **satu aplikasi untuk banyak cabang** dengan isolasi data 100% dan identitas visual masing-masing | §Solusi; AUTH-02, AUTH-03 |
| **OBJ-08** | Menekan **biaya operasional** hingga terjangkau untuk skala 3 cabang | Lampiran A.2 |

### 3.2 Objective Teknis

| ID | Objective | Target |
|---|---|---|
| **TECH-01** | Arsitektur yang dapat menampung pertumbuhan cabang | 10+ cabang tanpa refactoring |
| **TECH-02** | Isolasi tenant yang terverifikasi | Unit test Global Scope lulus 100% |
| **TECH-03** | Performa memadai pada infrastruktur ekonomis | 200 user konkuren pada VPS 2C/2GB |
| **TECH-04** | Ketersediaan layanan | Uptime 99% per bulan |
| **TECH-05** | Keamanan data dan auditabilitas | Semua aksi CRUD tercatat di `audit_logs` |

---

## 4. Problem Statement

### 4.1 Kondisi Saat Ini

Pengelolaan operasional seluruh cabang masih **manual menggunakan spreadsheet dan aplikasi terpisah-pisah**, sehingga sulit dimonitor secara terpusat oleh Pusat.

### 4.2 Daftar Masalah

| ID | Masalah | Dampak Bisnis | Objective Penyelesaian |
|---|---|---|---|
| **P-01** | Data siswa tersebar di spreadsheet masing-masing cabang — tidak ada sumber kebenaran tunggal (*single source of truth*) | Data ganda, tidak sinkron, sulit direkonsiliasi | OBJ-01 |
| **P-02** | Kepala Sekolah dan Admin Pusat tidak bisa memonitor kondisi akademik dan keuangan semua cabang secara real-time | Pengambilan keputusan lambat dan berbasis data usang | OBJ-02 |
| **P-03** | Proses PPDB masih manual, rawan kesalahan data dan lambat dalam komunikasi status kepada pendaftar | Calon siswa tidak mendapat kepastian; potensi kehilangan pendaftar | OBJ-03 |
| **P-04** | Tagihan SPP sering terlambat diterbitkan dan sulit dilacak pembayarannya | Arus kas terganggu; tunggakan tidak terdeteksi | OBJ-04 |
| **P-05** | Orang tua tidak memiliki akses langsung ke perkembangan nilai, absensi, dan tagihan anak | Beban komunikasi jatuh ke admin/guru; transparansi rendah | OBJ-05 |
| **P-06** | Penyebaran pengumuman masih via WhatsApp personal, tidak terstruktur | Pesan tidak terarsip, tidak terverifikasi sampai/tidak | OBJ-06 |

### 4.3 Solusi yang Diusulkan

Platform SaaS berbasis web (`apps.smartsukses.sch.id`) dengan arsitektur multi-tenant single domain:

- Setiap cabang memiliki **data terisolasi** melalui `school_id`, namun berjalan dalam **satu aplikasi yang sama**.
- Setiap cabang dapat memiliki **tampilan white-label** sendiri (logo dan warna khas).
- Semua pengguna mengakses **URL yang sama**; visibilitas data ditentukan oleh **peran (role)** dan **sekolah** tempat pengguna terdaftar.

---

## 5. Scope

### 5.1 Definisi Fase

| Fase | Definisi | Prasyarat Mulai |
|---|---|---|
| **Phase 1 (MVP)** | Administrasi & Akademik, Keuangan, Komunikasi & Portal | — |
| **Phase 2** | LMS, Presensi Digital, BK, Perpustakaan, Inventaris, Payroll | Phase 1 stabil dan telah digunakan **minimal 3 bulan** |

### 5.2 In Scope — Phase 1 (MVP)

| Kode | Modul | Cakupan Fitur |
|---|---|---|
| **M0** | Cross-Cutting | Multi-tenant, White-label UI, RBAC (Role-Based Access Control), Bilingual ID/EN, Autentikasi, User Management, Audit Log |
| **M1** | Administrasi & Akademik | SIS (Sistem Informasi Siswa), PPDB Online, Manajemen Kelas & Jadwal, E-Rapor & Penilaian |
| **M3** | Keuangan | Tagihan SPP Digital, Pencatatan Pembayaran, Akuntansi & Kas |
| **M5** | Komunikasi & Portal | Parent Portal, Portal Guru & Siswa, Notifikasi via wa.me |

> **Catatan:** Penomoran modul dalam blueprint melompat dari Modul 1 → Modul 3 → Modul 5. Isi **Modul 2** dan **Modul 4**: **Belum dijelaskan dalam blueprint.**

### 5.3 Rincian Sub-Modul Phase 1

| Modul | Sub-Modul |
|---|---|
| **M0 — Core Platform** | M0.1 Manajemen Tenant/Cabang · M0.2 Autentikasi & Session · M0.3 RBAC & Policy · M0.4 White-Label Theming · M0.5 User Management & Import Excel · M0.6 Lokalisasi ID/EN · M0.7 Audit Log |
| **M1 — Administrasi & Akademik** | M1.1 SIS · M1.2 PPDB Online · M1.3 Tahun Ajaran · M1.4 Kelas & Rombel · M1.5 Mata Pelajaran & Pengampu · M1.6 Jadwal Pelajaran · M1.7 Penilaian · M1.8 E-Rapor & PDF |
| **M3 — Keuangan** | M3.1 Jenis Tagihan · M3.2 Generate Tagihan Massal · M3.3 Pencatatan Pembayaran · M3.4 Buku Kas · M3.5 Laporan & Export Keuangan |
| **M5 — Komunikasi & Portal** | M5.1 Notifikasi In-App · M5.2 wa.me Link Generator & Template · M5.3 Parent Portal · M5.4 Portal Siswa · M5.5 Portal Guru |

### 5.4 Rencana Rilis Phase 1

| Sprint | Durasi | Target | Cakupan |
|---|---|---|---|
| Sprint 1 | 2 minggu | Foundation | Setup VPS, Laravel, Filament, Multi-tenant, Auth, RBAC, White-label theming, User management |
| Sprint 2 | 2 minggu | Core SIS | Data siswa (SIS), Tahun Ajaran, Kelas & Jadwal, Mata Pelajaran, Import Excel |
| Sprint 3 | 2 minggu | PPDB | Form PPDB publik, Review Admin, Status update, wa.me link generator, Enroll siswa |
| Sprint 4 | 2 minggu | Akademik | Input nilai, Grade config, Auto-hitung nilai akhir, Generate & publish rapor, PDF rapor |
| Sprint 5 | 2 minggu | Keuangan | Jenis tagihan, Generate SPP massal, Catat pembayaran, Upload bukti, Laporan SPP |
| Sprint 6 | 2 minggu | Akuntansi | Buku kas (income/expense), Laporan keuangan, Export Excel, Dashboard Bendahara |
| Sprint 7 | 2 minggu | Portal | Parent Portal (dashboard, nilai, tagihan), Portal Siswa, Portal Guru, Jadwal view |
| Sprint 8 | 2 minggu | Notifikasi | Sistem notifikasi in-app, wa.me bulk link, Template WA, Trigger otomatis |
| Sprint 9 | 2 minggu | Polish & QA | Bilingual (EN), Responsive mobile, Load testing, Security audit, Bug fixing |

**Total estimasi Phase 1: 18 minggu (~4,5 bulan)** dengan 1 developer full-stack berpengalaman Laravel. Dapat dipercepat dengan 2 developer (paralel Sprint 3–4 dan Sprint 5–6).

### 5.5 Out of Scope

Lihat [Bagian 14 — Out of Scope](#14-out-of-scope).

---

## 6. Assumptions

Asumsi adalah kondisi yang **dianggap benar** agar requirement dan estimasi dalam PRD ini berlaku. Asumsi bukan requirement baru. Setiap asumsi mencantumkan dasar di blueprint dan konsekuensi jika asumsi tersebut ternyata tidak terpenuhi.

### 6.1 Asumsi Bisnis & Organisasi

| ID | Asumsi | Dasar di Blueprint | Konsekuensi Jika Tidak Terpenuhi |
|---|---|---|---|
| **ASM-01** | Selama Phase 1, jumlah cabang tetap 3 (Pusat, Madani, Cinangka) dengan 50–200 siswa per cabang dan total 800–1.500 akun | §Target Skala Awal | Perhitungan kapasitas VPS 2C/2GB dan target 200 user konkuren perlu ditinjau ulang |
| **ASM-02** | Penambahan cabang baru cukup dilakukan melalui pendaftaran tenant oleh Super Admin, tanpa pengembangan ulang | §Target Skala Awal; `POST /admin/schools` | Klaim skalabilitas 10+ cabang tanpa refactoring tidak terpenuhi |
| **ASM-03** | Setiap cabang bersedia menggunakan struktur mata pelajaran dan bobot penilaian yang dapat direpresentasikan melalui `subjects` + `grade_configs` | §Target Skala Awal — "Kurikulum kustom per sekolah, tidak mengikuti format Merdeka/K13 secara kaku" | Diperlukan penyesuaian model penilaian di luar scope Phase 1 |
| **ASM-04** | Admin Sekolah bersedia melakukan pengiriman WhatsApp **secara manual** satu per satu selama Phase 1 | NOTIF-02; PPDB-04 — "Admin tinggal klik Buka WhatsApp untuk mengirim manual" | Beban kerja admin menjadi hambatan adopsi; percepatan ke WhatsApp API (Phase 2) perlu dipertimbangkan |
| **ASM-05** | Seluruh orang tua/wali memiliki nomor HP aktif yang terdaftar pada WhatsApp | NOTIF-02 — format `wa.me/62[nomorHP]`; `students.parent_phone` | Sebagian penerima tidak dapat dijangkau. Penanganan penerima tanpa WhatsApp: **Belum dijelaskan dalam blueprint.** |
| **ASM-06** | Nomor HP tersimpan dalam format yang dapat dikonversi ke awalan `62` | NOTIF-02 | Link wa.me gagal terbentuk. Aturan normalisasi nomor: **Belum dijelaskan dalam blueprint.** |
| **ASM-07** | Blueprint berstatus DRAFT namun scope-nya tidak berubah signifikan selama Phase 1 berjalan | §Penutup — "Setiap perubahan signifikan harus didokumentasikan sebagai versi baru" | Estimasi 18 minggu dan urutan sprint menjadi tidak valid |

### 6.2 Asumsi Data & Aturan Bisnis

| ID | Asumsi | Dasar di Blueprint | Konsekuensi Jika Tidak Terpenuhi |
|---|---|---|---|
| **ASM-08** | Setiap pengguna memiliki **tepat satu peran utama** | §1.1 — "Setiap pengguna memiliki tepat satu peran utama" | Matriks izin pada Bagian 8 tidak dapat diterapkan apa adanya |
| **ASM-09** | Hanya `SUPER_ADMIN` yang memiliki `school_id = NULL`; semua role lain selalu terikat pada satu cabang | Tabel `users` — "NULL untuk Super Admin"; §3.2.2 | Global Scope kehilangan kunci isolasi; risiko kebocoran data antar tenant |
| **ASM-10** | Setiap cabang memiliki **tepat satu tahun ajaran aktif** pada satu waktu | `academic_years.is_active` — "Hanya satu tahun ajaran per sekolah boleh aktif" | Query "tahun ajaran aktif" menjadi ambigu di hampir seluruh modul akademik dan keuangan |
| **ASM-11** | Guru direpresentasikan sebagai record pada tabel `users` dengan role `GURU`/`WALI_KELAS`, bukan entitas tersendiri | `classes.homeroom_teacher_id` → `users.id`; `class_subjects.teacher_id` → `users.id` | Data kepegawaian guru (NIP, keahlian mapel) tidak tertampung. → **Belum dijelaskan dalam blueprint.** |
| **ASM-12** | Email pengguna bersifat unik **lintas seluruh platform**, bukan unik per cabang | `users.email` — Key = UQ | Satu orang tidak dapat memiliki akun di dua cabang dengan email yang sama |
| **ASM-13** | Data siswa awal dimasukkan melalui form manual (SIS-01) atau import Excel (`POST /students/import`) | SIS-01; API Map §4.5 | Diperlukan proses migrasi khusus. Rencana migrasi dari spreadsheat lama: **Belum dijelaskan dalam blueprint.** |
| **ASM-14** | Satu siswa memiliki satu akun portal siswa (opsional) dan satu akun portal orang tua | `students.user_id` (nullable), `students.parent_user_id` (nullable) | Skenario dua wali untuk satu siswa: **Belum dijelaskan dalam blueprint.** |

### 6.3 Asumsi Teknis & Infrastruktur

| ID | Asumsi | Dasar di Blueprint | Konsekuensi Jika Tidak Terpenuhi |
|---|---|---|---|
| **ASM-15** | Satu VPS 2 Core / 2 GB RAM / 40 GB SSD mencukupi seluruh komponen pada skala awal | §3.3.1 — "satu VPS cukup untuk semua komponen" | Perlu upgrade vertikal atau pemisahan komponen di luar rencana biaya |
| **ASM-16** | Cache dan Queue dengan **database driver** memadai untuk beban Phase 1 | §3.1 — "Dapat diupgrade ke Redis di Phase 2" | Target response time < 500 ms dan 200 user konkuren berisiko tidak tercapai |
| **ASM-17** | Storage lokal VPS mencukupi untuk foto siswa, bukti bayar, dokumen PPDB, dan scan nota selama Phase 1 | §3.1 — "Backblaze B2 di Phase 2"; Lampiran A.2 | Perlu percepatan migrasi ke Backblaze B2 (~Rp 15.000/10 GB/bln) |
| **ASM-18** | Limit email SMTP gratis (500–2.000 email/hari) mencukupi kebutuhan reset password dan verifikasi | Lampiran A.2 — "cukup untuk skala awal" | Perlu layanan SMTP berbayar di luar estimasi biaya |
| **ASM-19** | Pengguna mengakses aplikasi dengan koneksi minimal setara 4G (10 Mbps) | NFR Performa | Target load halaman < 3 detik tidak terukur secara valid |
| **ASM-20** | Pengguna menggunakan browser yang didukung: Chrome/Firefox/Edge (desktop) atau browser bawaan iOS/Android | NFR Aksesibilitas | Kompatibilitas di luar daftar tersebut: **Belum dijelaskan dalam blueprint.** |
| **ASM-21** | Estimasi 18 minggu berlaku untuk **1 developer full-stack berpengalaman Laravel** | Lampiran A.1 — catatan estimasi | Jadwal rilis meleset jika komposisi atau pengalaman tim berbeda |

---

## 7. Constraints

Constraint adalah **batasan yang mengikat** desain dan implementasi. Seluruh butir di bawah bersumber langsung dari blueprint dan tidak boleh dilanggar tanpa menerbitkan versi blueprint baru.

### 7.1 Constraint Teknologi

| ID | Constraint | Dasar di Blueprint |
|---|---|---|
| **CON-01** | Stack backend **wajib** Laravel 11 di atas PHP 8.3+ | §3.1 Stack Teknologi Detail |
| **CON-02** | Admin panel **wajib** menggunakan Filament PHP 3.x | §3.1 |
| **CON-03** | Portal pengguna **wajib** menggunakan Livewire 3 + Alpine.js — tanpa JS build pipeline | §3.1 |
| **CON-04** | Database **wajib** MySQL 8.0+ | §3.1 |
| **CON-05** | Multi-tenancy **wajib** menggunakan `spatie/laravel-multitenancy` 3.x | §3.1 |
| **CON-06** | RBAC **wajib** menggunakan `spatie/laravel-permission`; autentikasi menggunakan Laravel Sanctum | §3.1; §3.4 |
| **CON-07** | Cache dan Queue **wajib** menggunakan database driver pada Phase 1 (Redis baru pada Phase 2) | §3.1 |
| **CON-08** | Penyimpanan file **wajib** local disk pada Phase 1 | §3.1 |
| **CON-09** | Generate PDF menggunakan DomPDF atau Browsershot | §3.1 |
| **CON-10** | Query melalui raw SQL **dilarang**, kecuali menggunakan `DB::select()` dengan binding | §3.4 SQL Injection |
| **CON-11** | Seluruh komponen stack harus open-source atau gratis | §Stack Teknologi — kolom Lisensi/Biaya |

### 7.2 Constraint Arsitektur

| ID | Constraint | Dasar di Blueprint |
|---|---|---|
| **CON-12** | Seluruh cabang diakses melalui **satu domain tunggal** `apps.smartsukses.sch.id` — tidak ada subdomain per cabang | §Solusi; §3.3.2 |
| **CON-13** | Pola isolasi **wajib** Shared Database, Shared Schema — satu database, satu set tabel untuk semua tenant | §3.2.1 |
| **CON-14** | Kolom `school_id` **wajib hadir** pada semua tabel bisnis | §3.2.1 |
| **CON-15** | Semua query aplikasi **WAJIB** menggunakan Laravel Global Scope yang menambahkan `WHERE school_id = auth()->user()->school_id` secara otomatis | §2.1 Catatan; §3.2.1 |
| **CON-16** | Pengecualian Global Scope **hanya** untuk Super Admin (`school_id = NULL`) | §3.2.2 |
| **CON-17** | Satu instance aplikasi Laravel melayani seluruh tenant | §3.3.1 |
| **CON-18** | Theming white-label **wajib** melalui injeksi CSS variables (`--color-primary`, `--color-secondary`) — semua komponen UI menggunakan `var(--color-primary)` | §3.2.3 |
| **CON-19** | Perubahan white-label **tidak boleh** memerlukan deployment ulang | AUTH-03 AC-3 |

### 7.3 Constraint Infrastruktur & Deployment

| ID | Constraint | Dasar di Blueprint |
|---|---|---|
| **CON-20** | Seluruh komponen berjalan pada **satu VPS** (Nginx, PHP-FPM, MySQL, Queue worker, Certbot) | §3.3.1 |
| **CON-21** | Spesifikasi VPS: 2 Core, 2 GB RAM, 40 GB SSD, Ubuntu 22.04 LTS | §3.1; §3.3.1 |
| **CON-22** | MySQL **hanya** dapat diakses dari localhost — tidak boleh diekspos ke publik | §3.3.1 |
| **CON-23** | CORS **wajib** dikonfigurasi hanya menerima dari domain `apps.smartsukses.sch.id` | Lampiran A.3 |
| **CON-24** | HTTPS wajib; HTTP harus di-redirect ke HTTPS; HSTS header diaktifkan | §3.4 |
| **CON-25** | Backup database dijalankan harian pukul **02:00 WIB** via `mysqldump` dengan retensi 30 hari | §3.4 |

### 7.4 Constraint Keamanan

| ID | Constraint | Dasar di Blueprint |
|---|---|---|
| **CON-26** | Kebocoran data antar tenant: **toleransi nol (0%)** | NFR Keamanan — Data isolation 100% |
| **CON-27** | Password minimal 8 karakter, hash Argon2id/Bcrypt, **wajib diganti pada login pertama** | NFR Keamanan; §3.4 |
| **CON-28** | Rate limit login: maksimal **5 percobaan/menit**; API: maksimal **60 request/menit per user** | §3.4 |
| **CON-29** | Token sesi kedaluwarsa setelah **8 jam tidak aktif** | AUTH-01 AC-4 |
| **CON-30** | Link reset password berlaku maksimal **60 menit**; seluruh sesi aktif di-invalidate setelah reset berhasil | AUTH-04 AC-2, AC-3 |
| **CON-31** | File upload disimpan di `storage/` — **di luar web root** | §3.4 |
| **CON-32** | CSRF token wajib untuk seluruh request POST/PUT/DELETE dari form web | §3.4 |
| **CON-33** | Seluruh aksi Create/Update/Delete wajib tercatat (user, action, table, id, timestamp, IP) | §3.4; NFR Audit |
| **CON-34** | Pesan error login tidak boleh mengungkap detail sistem | AUTH-01 AC-2 |

### 7.5 Constraint Aturan Bisnis (membatasi desain data & UI)

| ID | Constraint | Dasar di Blueprint |
|---|---|---|
| **CON-35** | Satu siswa hanya boleh berada di **satu kelas per tahun ajaran** | KELAS-02 AC-2 |
| **CON-36** | Satu guru hanya boleh menjadi wali kelas **satu kelas per tahun ajaran** | KELAS-01 AC-3 |
| **CON-37** | Hanya **satu tahun ajaran aktif** per sekolah | `academic_years.is_active` |
| **CON-38** | NIS **unik dalam satu sekolah**; NISN harus 10 digit angka | SIS-01 AC-2, AC-3 |
| **CON-39** | Nilai menggunakan skala **0–100**; hasil perhitungan dibulatkan **2 desimal** | NILAI-01 AC-1; NILAI-02 AC-3 |
| **CON-40** | Rapor yang sudah di-publish **terkunci** dan nilainya tidak dapat diedit | NILAI-01 AC-3; NILAI-03 AC-2 |
| **CON-41** | Publish rapor hanya diizinkan setelah **semua mata pelajaran memiliki nilai akhir** | NILAI-03 AC-1 |
| **CON-42** | Perubahan konfigurasi bobot penilaian **hanya berlaku untuk tahun ajaran baru** | NILAI-05 AC-2 |
| **CON-43** | Foto siswa: format JPG/PNG/WEBP, maksimal **2 MB**, auto-resize **400×400 px** | SIS-03 |
| **CON-44** | Bukti pembayaran: format JPG/PNG/PDF, maksimal **5 MB** | SPP-03 AC-2 |
| **CON-45** | Data siswa dan jenis tagihan **tidak boleh dihapus** — hanya dinonaktifkan (soft deactivate) | SIS-02 AC-2; SPP-01 AC-2; `DELETE /users/{id}` |
| **CON-46** | Riwayat notifikasi disimpan **90 hari** | NOTIF-04 AC-3 |
| **CON-47** | Generate tagihan massal **wajib menampilkan preview** sebelum konfirmasi | SPP-02 AC-3 |
| **CON-48** | Sistem **wajib** mendeteksi konflik jadwal (guru/ruangan/kelas pada waktu bersamaan) | KELAS-03 AC-2 |
| **CON-49** | Pengiriman WhatsApp pada Phase 1 **manual** — sistem hanya menghasilkan link wa.me | PPDB-04 AC-3; NOTIF-02 |
| **CON-50** | Pembayaran pada Phase 1 dicatat **manual** oleh Bendahara — tidak ada payment gateway | SPP-03; §1.3 Phase 2 |

> **Catatan konflik constraint:** CON-43 (SIS-03) mengizinkan format **WEBP** untuk foto siswa, sedangkan §3.4 Arsitektur Keamanan menyatakan *"Hanya JPG/PNG/PDF diperbolehkan"*. Blueprint memuat dua pernyataan yang tidak konsisten — perlu klarifikasi. Lihat [17.3 Daftar Isu Terbuka](#173-daftar-isu-terbuka-yang-perlu-klarifikasi).

### 7.6 Constraint Proyek & Tata Kelola

| ID | Constraint | Dasar di Blueprint |
|---|---|---|
| **CON-51** | Durasi Phase 1: **18 minggu** (9 sprint × 2 minggu) dengan 1 developer full-stack | Lampiran A.1 |
| **CON-52** | Paralelisasi hanya dimungkinkan pada **Sprint 3–4** dan **Sprint 5–6** dengan 2 developer | Lampiran A.1 catatan |
| **CON-53** | Total biaya infrastruktur bulanan maksimal **Rp 130.000** | Lampiran A.2 |
| **CON-54** | Phase 2 **tidak boleh dimulai** sebelum Phase 1 stabil dan digunakan minimal **3 bulan** | §1.3 |
| **CON-55** | Setiap perubahan signifikan pada scope, arsitektur, atau teknologi **wajib** didokumentasikan sebagai versi baru blueprint | §Penutup |
| **CON-56** | Go-live hanya boleh dilakukan setelah seluruh **10 butir checklist Lampiran A.3** terpenuhi | Lampiran A.3 |

---

## 8. User Roles

Platform menggunakan sistem **RBAC (Role-Based Access Control)** berbasis paket `spatie/laravel-permission`. **Setiap pengguna memiliki tepat satu peran utama.** Terdapat dua level: **Platform Level** (lintas semua cabang) dan **School Level** (terikat pada satu cabang / `school_id`).

### 8.1 Daftar Peran

| Kode Peran | Nama Peran | Level | Deskripsi Singkat |
|---|---|---|---|
| `SUPER_ADMIN` | Super Administrator | Platform | Akses penuh ke semua cabang, konfigurasi sistem, manajemen tenant |
| `SCHOOL_ADMIN` | Admin Sekolah | Sekolah | Kelola seluruh operasional satu cabang: siswa, guru, keuangan, pengaturan |
| `KEPALA_SEKOLAH` | Kepala Sekolah | Sekolah | Monitoring & approval: laporan akademik, keuangan, dashboard cabang |
| `GURU` | Guru Mata Pelajaran | Sekolah | Input nilai, lihat daftar siswa kelas ajar, jadwal mengajar |
| `WALI_KELAS` | Wali Kelas | Sekolah | Semua akses guru + kelola rapor & absensi kelas yang diampu |
| `SISWA` | Siswa | Sekolah | Lihat nilai, jadwal, notifikasi, dan data pribadi sendiri |
| `ORANG_TUA` | Orang Tua / Wali Murid | Sekolah | Parent portal: nilai anak, absensi anak, tagihan, notifikasi |
| `BENDAHARA` | Bendahara | Sekolah | Kelola tagihan SPP, catat pembayaran, akuntansi & laporan keuangan |

### 8.2 Matriks Izin per Modul (Phase 1)

**Legenda:** ✅ = akses penuh · ⭕ = akses baca/view saja · ❌ = tidak ada akses

| Modul | SUPER_ADMIN | SCHOOL_ADMIN | KEPALA | GURU/WALI | BENDAHARA | SISWA | ORTU |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| Manajemen Tenant/Cabang | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Data Siswa (SIS) | ✅ | ✅ | ⭕ | ⭕ | ⭕ | ⭕ | ⭕ |
| PPDB Online | ✅ | ✅ | ⭕ | ❌ | ❌ | ❌ | ❌ |
| Kelas & Jadwal | ✅ | ✅ | ⭕ | ⭕ | ❌ | ⭕ | ❌ |
| Input Nilai | ✅ | ✅ | ⭕ | ✅ | ❌ | ❌ | ❌ |
| Generate Rapor | ✅ | ✅ | ⭕ | ✅ (Wali) | ❌ | ⭕ | ⭕ |
| Tagihan SPP | ✅ | ✅ | ⭕ | ❌ | ✅ | ❌ | ⭕ |
| Catat Pembayaran | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ |
| Akuntansi & Kas | ✅ | ✅ | ⭕ | ❌ | ✅ | ❌ | ❌ |
| Laporan Keuangan | ✅ | ✅ | ⭕ | ❌ | ✅ | ❌ | ❌ |
| Notifikasi (buat) | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Portal Siswa | ✅ | ✅ | ⭕ | ⭕ | ❌ | ✅ | ❌ |
| Parent Portal | ✅ | ✅ | ⭕ | ❌ | ❌ | ❌ | ✅ |
| White-label Settings | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| User Management | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |

### 8.3 Aturan Akses Tambahan (Eksplisit dalam Blueprint)

| Aturan | Dasar |
|---|---|
| Super Admin memiliki `school_id = NULL` dan **melewati Global Scope**, sehingga dapat mengakses data semua tenant | §3.2.2 |
| Pengguna biasa **tidak memiliki cara apa pun** untuk mengakses data cabang lain | AUTH-02 |
| Guru hanya melihat siswa **kelas yang dia ampu** | SIS-04; `GET /students` |
| Guru hanya dapat input nilai untuk **kelas yang dia ampu** | `POST /grades` |
| Orang tua hanya dapat melihat tagihan **anaknya sendiri** | `GET /students/{id}/fees` |
| Generate & publish rapor hanya oleh **Wali Kelas** | NILAI-03; `POST /report-cards/generate` |
| Satu guru hanya boleh menjadi wali kelas **satu kelas per tahun ajaran** | KELAS-01 |
| Guru hanya melihat **jadwal dirinya sendiri** | `GET /schedules` |

### 8.4 Pemetaan Role ke Auth Level API

| Auth Level | Ketentuan | Contoh Endpoint |
|---|---|---|
| **Public** | Tidak perlu token — akses bebas | `POST /auth/login`, `POST /ppdb/{schoolCode}/register`, `GET /ppdb/check-status` |
| **Auth** | Wajib token; akses dibatasi ke data sekolah user tersebut | `GET /students`, `GET /grades`, `GET /notifications` |
| **Admin** | Wajib token + role `SCHOOL_ADMIN` / `SUPER_ADMIN` | `POST /students`, `POST /student-fees/generate-bulk` |
| **Super** | Wajib token + role `SUPER_ADMIN` | `GET /admin/schools`, `GET /admin/dashboard` |

### 8.5 Catatan Ketidakjelasan pada Definisi Role

| Isu | Keterangan |
|---|---|
| Mekanisme **approval** oleh Kepala Sekolah | Deskripsi role menyebut "approval", namun tidak ada user story Phase 1 yang mendefinisikan objek maupun alur approval. → **Belum dijelaskan dalam blueprint.** |
| Kewenangan **Guru membuat pengumuman** | Matriks izin menyatakan GURU/WALI ❌ pada "Notifikasi (buat)", sementara PORTAL-02 menyediakan shortcut "Buat Pengumuman" bagi Guru. Blueprint memuat dua pernyataan yang tidak konsisten. |
| Kewenangan **Wali Kelas mengelola absensi** | Deskripsi role menyebut "kelola absensi", namun modul Presensi Digital berada di Phase 2. → Sumber data absensi Phase 1: **Belum dijelaskan dalam blueprint.** |
| Kemampuan Super Admin **masuk ke portal cabang (impersonate)** | **Belum dijelaskan dalam blueprint.** |
| Perbedaan teknis antara role `GURU` dan `WALI_KELAS` dalam penetapan permission | Matriks izin menggabungkan keduanya dalam satu kolom "GURU/WALI". Pemisahan permission secara rinci: **Belum dijelaskan dalam blueprint.** |

---

## 9. Functional Requirements

Setiap requirement ditulis dalam format **User Story**: *"Sebagai [peran], saya dapat [aksi], sehingga [manfaat]"*. **Acceptance Criteria** mendefinisikan kondisi minimum agar fitur dianggap selesai.

**Total: 38 functional requirement** dalam 9 kelompok.

---

### 9.1 FR-A · Cross-Cutting: Multi-Tenant, White-Label & Autentikasi

#### AUTH-01 — Login Email & Password
**User Story:** Sebagai Pengguna, saya dapat login menggunakan email dan password.

**Acceptance Criteria:**
1. Form login minimal memiliki field email + password.
2. Jika kredensial salah, tampil pesan error yang tidak mengungkap detail sistem.
3. Berhasil login menghasilkan JWT/session token.
4. Token kedaluwarsa setelah **8 jam tidak aktif**.

**Prioritas:** Must Have · **Sprint:** 1 · **Role:** Semua

---

#### AUTH-02 — Deteksi Tenant Otomatis & Isolasi Data
**User Story:** Sebagai Sistem, setelah pengguna login, sistem otomatis mendeteksi `school_id` pengguna dan memfilter semua data berdasarkan school tersebut.

**Acceptance Criteria:**
1. Setiap query ke database wajib disertai `WHERE school_id = [current_user.school_id]`.
2. Super Admin yang tidak punya `school_id` dapat melihat semua data lintas cabang.
3. Tidak ada cara bagi pengguna biasa untuk mengakses data cabang lain.

**Prioritas:** Must Have · **Sprint:** 1 · **Role:** Sistem

---

#### AUTH-03 — White-Label UI per Cabang
**User Story:** Sebagai Pengguna, setelah login saya melihat tampilan (logo, warna utama) yang sesuai dengan cabang sekolah saya.

**Acceptance Criteria:**
1. Sistem membaca `logo_url`, `primary_color`, `secondary_color` dari tabel `schools` berdasarkan `school_id`.
2. CSS variables di-inject secara dinamis ke halaman.
3. Perubahan white-label oleh Admin Sekolah **langsung berlaku tanpa deployment ulang**.

**Prioritas:** Must Have · **Sprint:** 1 · **Role:** Semua

---

#### AUTH-04 — Reset Password via Email
**User Story:** Sebagai Pengguna, saya dapat me-reset password melalui email.

**Acceptance Criteria:**
1. Link reset dikirim ke email terdaftar.
2. Link berlaku selama **60 menit**.
3. Setelah reset berhasil, semua sesi aktif di-invalidate.

**Prioritas:** Must Have · **Sprint:** 1 · **Role:** Semua

---

#### AUTH-05 — Ganti Bahasa Antarmuka (ID / EN)
**User Story:** Sebagai Pengguna, saya dapat mengganti bahasa antarmuka antara Bahasa Indonesia dan English.

**Acceptance Criteria:**
1. Tombol toggle bahasa tersedia di navbar.
2. Preferensi bahasa tersimpan di profil pengguna (field `locale`).
3. Semua label, pesan error, dan placeholder tersedia dalam dua bahasa.

**Prioritas:** Must Have · **Sprint:** 9 (cross-cutting sejak Sprint 1) · **Role:** Semua

---

### 9.2 FR-B · Sistem Informasi Siswa (SIS)

#### SIS-01 — Tambah Data Siswa Manual
**User Story:** Sebagai Admin Sekolah, saya dapat menambahkan data siswa baru secara manual.

**Acceptance Criteria:**
1. Form minimal: nama, NIS, NISN, tanggal lahir, jenis kelamin, agama, alamat, nama ortu, no. HP ortu.
2. Validasi format NISN (**10 digit angka**).
3. **NIS unik dalam satu sekolah.**

**Prioritas:** Must Have · **Sprint:** 2 · **Role:** SCHOOL_ADMIN

---

#### SIS-02 — Edit & Nonaktifkan Data Siswa
**User Story:** Sebagai Admin Sekolah, saya dapat mengedit dan menonaktifkan data siswa.

**Acceptance Criteria:**
1. Edit tidak menghapus histori (*soft update*).
2. Siswa dinonaktifkan (status = `INACTIVE`) **tidak dihapus** dari database.
3. Siswa tidak aktif tidak muncul di daftar kelas aktif.

**Prioritas:** Must Have · **Sprint:** 2 · **Role:** SCHOOL_ADMIN

> **Catatan konsistensi:** AC-2 menyebut status `INACTIVE`, sedangkan ERD mendefinisikan `students.status` sebagai ENUM(`ACTIVE`, `GRADUATED`, `DROPPED_OUT`, `TRANSFERRED`) — tanpa nilai `INACTIVE`. Perlu klarifikasi ke pemilik blueprint.

---

#### SIS-03 — Upload Foto Profil Siswa
**User Story:** Sebagai Admin Sekolah, saya dapat mengunggah foto profil siswa.

**Acceptance Criteria:**
1. Format yang diterima: **JPG, PNG, WEBP**.
2. Ukuran maksimum: **2 MB**.
3. Foto otomatis di-resize ke **400×400 px**.

**Prioritas:** Should Have · **Sprint:** 2 · **Role:** SCHOOL_ADMIN

---

#### SIS-04 — Guru Melihat Daftar Siswa Kelas Ajar
**User Story:** Sebagai Guru, saya dapat melihat daftar siswa di kelas yang saya ampu.

**Acceptance Criteria:**
1. Hanya siswa aktif yang ditampilkan.
2. Informasi tampil: nama, NIS, foto, **status kehadiran hari ini**.

**Prioritas:** Must Have · **Sprint:** 2 · **Role:** GURU, WALI_KELAS

> **Catatan dependency:** AC-2 memerlukan data kehadiran harian. Modul Presensi Digital berada di Phase 2 dan tidak terdapat tabel absensi dalam ERD. → Sumber data kehadiran Phase 1: **Belum dijelaskan dalam blueprint.**

---

#### SIS-05 — Export Data Siswa ke Excel
**User Story:** Sebagai Admin Sekolah, saya dapat mengekspor data siswa ke format Excel (.xlsx).

**Acceptance Criteria:**
1. Ekspor mencakup semua field data siswa.
2. File ekspor diberi nama dengan format: `siswa_[kode_sekolah]_[tanggal].xlsx`.

**Prioritas:** Should Have · **Sprint:** 2 · **Role:** SCHOOL_ADMIN

**Fitur terkait dari API Map:** `POST /students/import` — import siswa massal dari Excel.

---

### 9.3 FR-C · PPDB Online (Penerimaan Peserta Didik Baru)

#### PPDB-01 — Formulir Pendaftaran Publik
**User Story:** Sebagai Calon Siswa / Orang Tua, saya dapat mengisi formulir pendaftaran PPDB secara online **tanpa perlu login**.

**Acceptance Criteria:**
1. Halaman PPDB dapat diakses publik via URL: `/ppdb/[kode_sekolah]`.
2. Form: nama lengkap, jenis kelamin, tanggal lahir, asal sekolah, nama ortu, no. HP, email.
3. Setelah submit, tampil **nomor pendaftaran unik**.

**Prioritas:** Must Have · **Sprint:** 3 · **Role:** Publik

**Format nomor pendaftaran (dari ERD):** `[KODE_CABANG]-[TAHUN]-[SEQ]`

---

#### PPDB-02 — Cek Status Pendaftaran
**User Story:** Sebagai Calon Siswa, saya dapat mengecek status pendaftaran menggunakan nomor pendaftaran.

**Acceptance Criteria:**
1. Halaman cek status dapat diakses publik.
2. Tampil status terkini: `REGISTERED`, `DOCUMENT_REVIEW`, `PASSED`, `FAILED`, `ENROLLED`.

**Prioritas:** Must Have · **Sprint:** 3 · **Role:** Publik

**Parameter pengecekan (dari API Map):** nomor daftar + tanggal lahir.

---

#### PPDB-03 — Kelola Pendaftar
**User Story:** Sebagai Admin Sekolah, saya dapat melihat semua pendaftar, memfilter berdasarkan status, dan memperbarui status.

**Acceptance Criteria:**
1. Tampil dalam tabel dengan kolom: no. daftar, nama, asal sekolah, status, tanggal daftar.
2. Filter per status tersedia.
3. Perubahan status disimpan **dengan catatan alasan**.

**Prioritas:** Must Have · **Sprint:** 3 · **Role:** SCHOOL_ADMIN

---

#### PPDB-04 — Generate Link wa.me Notifikasi PPDB
**User Story:** Sebagai Admin Sekolah, saya dapat meng-generate link wa.me notifikasi untuk dikirim ke calon siswa.

**Acceptance Criteria:**
1. Sistem menyediakan template teks per perubahan status (misal: *"Selamat, Ananda [nama] dinyatakan LULUS seleksi..."*).
2. Link wa.me otomatis tergenerate dengan template teks siap kirim.
3. Admin tinggal klik **"Buka WhatsApp"** untuk mengirim **manual**.

**Prioritas:** Must Have · **Sprint:** 3 · **Role:** SCHOOL_ADMIN

---

#### PPDB-05 — Konversi Pendaftar Lulus Menjadi Siswa Aktif
**User Story:** Sebagai Admin Sekolah, saya dapat mengonversi pendaftar PPDB yang LULUS menjadi siswa aktif (enroll) **dalam satu klik**.

**Acceptance Criteria:**
1. Data dari formulir PPDB otomatis mengisi form siswa baru.
2. Admin dapat melengkapi data sebelum konfirmasi.
3. Status PPDB berubah menjadi `ENROLLED` setelah berhasil.

**Prioritas:** Must Have · **Sprint:** 3 · **Role:** SCHOOL_ADMIN

**Fitur terkait dari API Map:** `GET /ppdb/schools` (landing publik daftar cabang yang membuka PPDB), `GET /ppdb/{schoolCode}/info` (syarat, jadwal, kuota), upload dokumen pendaftaran (`ppdb_registrations.documents`).

---

### 9.4 FR-D · Manajemen Kelas & Jadwal Pelajaran

#### KELAS-01 — Buat Kelas & Tentukan Wali Kelas
**User Story:** Sebagai Admin Sekolah, saya dapat membuat kelas baru untuk tahun ajaran aktif dan menentukan wali kelasnya.

**Acceptance Criteria:**
1. Field: nama kelas (misal: X-A), tingkat, wali kelas, kapasitas.
2. Wali kelas dipilih dari daftar guru aktif.
3. **Satu guru hanya boleh menjadi wali kelas satu kelas per tahun ajaran.**

**Prioritas:** Must Have · **Sprint:** 2 · **Role:** SCHOOL_ADMIN

---

#### KELAS-02 — Tambah Siswa ke Kelas
**User Story:** Sebagai Admin Sekolah, saya dapat menambahkan siswa ke dalam kelas.

**Acceptance Criteria:**
1. Siswa dipilih dari daftar siswa aktif yang **belum terdaftar di kelas manapun** untuk tahun ajaran tersebut.
2. **Satu siswa hanya boleh ada di satu kelas per tahun ajaran.**

**Prioritas:** Must Have · **Sprint:** 2 · **Role:** SCHOOL_ADMIN

---

#### KELAS-03 — Buat Jadwal Pelajaran
**User Story:** Sebagai Admin Sekolah, saya dapat membuat jadwal pelajaran per kelas dengan alokasi guru dan ruangan.

**Acceptance Criteria:**
1. Field: kelas, mata pelajaran, guru, hari, jam mulai, jam selesai, ruang.
2. Sistem **mendeteksi konflik jadwal** (guru/ruangan/kelas yang sama di waktu bersamaan).

**Prioritas:** Must Have · **Sprint:** 2 · **Role:** SCHOOL_ADMIN

---

#### KELAS-04 — Guru Melihat Jadwal Mengajar
**User Story:** Sebagai Guru, saya dapat melihat jadwal mengajar saya untuk minggu berjalan.

**Acceptance Criteria:**
1. Tampil dalam format tabel/kalender mingguan.
2. Klik jadwal menampilkan detail: kelas, mata pelajaran, ruang.

**Prioritas:** Must Have · **Sprint:** 2 · **Role:** GURU, WALI_KELAS

**Fitur pendukung dari API Map:** manajemen Tahun Ajaran (`/academic-years`, aktivasi satu tahun ajaran aktif) dan Mata Pelajaran (`/subjects`).

---

### 9.5 FR-E · E-Rapor & Penilaian

#### NILAI-01 — Input Nilai per Komponen
**User Story:** Sebagai Guru, saya dapat menginput nilai per komponen (Harian, UTS, UAS) untuk setiap siswa di kelas yang saya ampu.

**Acceptance Criteria:**
1. Nilai dalam **skala 0–100**.
2. Input dapat dilakukan satu per satu atau melalui **import Excel**.
3. Nilai yang sudah diinput dapat diedit **selama rapor belum diterbitkan** (`published = false`).

**Prioritas:** Must Have · **Sprint:** 4 · **Role:** GURU, WALI_KELAS

**Komponen penilaian tersedia (dari ERD):** `DAILY`, `MIDTERM`, `FINAL`, `ASSIGNMENT`, `SKILL`, `ATTITUDE`.

---

#### NILAI-02 — Perhitungan Otomatis Nilai Akhir
**User Story:** Sebagai Sistem, nilai akhir per mata pelajaran dihitung otomatis berdasarkan bobot komponen yang dikonfigurasi Admin.

**Acceptance Criteria:**
1. Admin dapat mengatur bobot: contoh Harian 40%, UTS 30%, UAS 30%.
2. Formula: **Nilai Akhir = (Harian × bobot) + (UTS × bobot) + (UAS × bobot)**.
3. Hasil pembulatan **2 desimal**.

**Prioritas:** Must Have · **Sprint:** 4 · **Role:** Sistem

---

#### NILAI-03 — Publish Rapor oleh Wali Kelas
**User Story:** Sebagai Wali Kelas, saya dapat menerbitkan (publish) rapor untuk semua siswa di kelas saya.

**Acceptance Criteria:**
1. Sebelum publish, sistem memvalidasi bahwa **semua mata pelajaran sudah memiliki nilai akhir**.
2. Setelah publish, **nilai terkunci** (tidak dapat diedit).
3. Rapor tersedia di portal siswa dan orang tua.

**Prioritas:** Must Have · **Sprint:** 4 · **Role:** WALI_KELAS

> **Catatan:** Mekanisme unpublish / koreksi rapor yang sudah diterbitkan: **Belum dijelaskan dalam blueprint.**

---

#### NILAI-04 — Siswa & Orang Tua Melihat Nilai
**User Story:** Sebagai Siswa / Orang Tua, saya dapat melihat nilai real-time (sebelum rapor diterbitkan) dan rapor final.

**Acceptance Criteria:**
1. Nilai harian/UTS/UAS tampil **segera setelah guru menyimpan**.
2. Rapor final hanya tampil setelah Wali Kelas menerbitkan.
3. Rapor dapat dicetak dalam **format PDF**.

**Prioritas:** Must Have · **Sprint:** 4 · **Role:** SISWA, ORANG_TUA

---

#### NILAI-05 — Konfigurasi Komponen & Bobot Penilaian
**User Story:** Sebagai Admin Sekolah, saya dapat mengkonfigurasi komponen penilaian dan bobot per mata pelajaran.

**Acceptance Criteria:**
1. Konfigurasi dapat berbeda antar mata pelajaran.
2. Perubahan konfigurasi **hanya berlaku untuk tahun ajaran baru**.

**Prioritas:** Must Have · **Sprint:** 4 · **Role:** SCHOOL_ADMIN

**Isi rapor (dari ERD `report_cards`):** nilai akhir per mapel (JSON), nilai sikap (A/B/C/D), rekap kehadiran (hadir, sakit, izin, alpa), peringkat di kelas, catatan wali kelas, status publikasi.

> **Catatan:** Rumus perhitungan `rank_in_class` dan aturan *tie-break*: **Belum dijelaskan dalam blueprint.**

---

### 9.6 FR-F · Tagihan & Pembayaran SPP Digital

#### SPP-01 — Buat Jenis Tagihan
**User Story:** Sebagai Bendahara, saya dapat membuat jenis tagihan baru (SPP, Uang Gedung, dll.) dengan jumlah dan frekuensi tertentu.

**Acceptance Criteria:**
1. Field: nama tagihan, jumlah (Rupiah), frekuensi (`MONTHLY` / `YEARLY` / `ONCE`).
2. Jenis tagihan dapat dinonaktifkan **tanpa menghapus histori**.

**Prioritas:** Must Have · **Sprint:** 5 · **Role:** BENDAHARA

---

#### SPP-02 — Generate Tagihan Massal
**User Story:** Sebagai Bendahara, saya dapat men-generate tagihan SPP bulanan untuk semua siswa aktif **dalam satu klik**.

**Acceptance Criteria:**
1. Sistem membuat record `student_fees` untuk setiap siswa aktif.
2. Due date otomatis diisi (misal: tanggal 10 bulan berjalan).
3. **Preview daftar tagihan ditampilkan sebelum konfirmasi generate.**

**Prioritas:** Must Have · **Sprint:** 5 · **Role:** BENDAHARA

> **Catatan:** Perilaku generate untuk frekuensi `YEARLY` dan `ONCE`: **Belum dijelaskan dalam blueprint.**

---

#### SPP-03 — Catat Pembayaran Manual
**User Story:** Sebagai Bendahara, saya dapat mencatat pembayaran siswa secara manual (cash atau transfer).

**Acceptance Criteria:**
1. Form: nama siswa, periode, metode bayar, jumlah, tanggal, referensi.
2. Bukti transfer dapat diunggah (**JPG/PNG/PDF, maks 5 MB**).
3. Status tagihan otomatis berubah ke `PAID` / `PARTIAL`.

**Prioritas:** Must Have · **Sprint:** 5 · **Role:** BENDAHARA

**Metode pembayaran (dari ERD):** `CASH`, `TRANSFER`, `PAYMENT_GATEWAY` (gateway baru aktif di Phase 2).
**Status tagihan (dari ERD):** `UNPAID`, `PARTIAL`, `PAID`, `WAIVED`.
**Cicilan:** Satu tagihan dapat memiliki beberapa pembayaran.

---

#### SPP-04 — Orang Tua Melihat Tagihan Anak
**User Story:** Sebagai Orang Tua, saya dapat melihat daftar tagihan anak, status pembayaran, dan riwayat pembayaran.

**Acceptance Criteria:**
1. Tampil dalam daftar per periode.
2. Tagihan belum lunas **ditandai merah/warning**.
3. Riwayat pembayaran mencantumkan tanggal dan metode bayar.

**Prioritas:** Must Have · **Sprint:** 5 (tampilan portal: Sprint 7) · **Role:** ORANG_TUA

---

#### SPP-05 — Export Laporan Tagihan
**User Story:** Sebagai Bendahara, saya dapat mengekspor laporan tagihan per periode ke Excel.

**Acceptance Criteria:**
1. Kolom: nama siswa, kelas, periode, jumlah tagihan, jumlah bayar, sisa, status.
2. Ekspor dapat difilter per kelas, periode, atau status.

**Prioritas:** Should Have · **Sprint:** 5 · **Role:** BENDAHARA

**Fitur terkait dari API Map:** `PATCH /student-fees/{id}/waive` — pembebasan tagihan dengan alasan (status → `WAIVED`).

---

### 9.7 FR-G · Akuntansi & Kas Sekolah

#### KAS-01 — Catat Pemasukan & Pengeluaran Kas
**User Story:** Sebagai Bendahara, saya dapat mencatat pemasukan dan pengeluaran kas sekolah.

**Acceptance Criteria:**
1. Field: jenis (`INCOME` / `EXPENSE`), kategori, jumlah, tanggal, keterangan, no. referensi.
2. Bukti dapat dilampirkan (scan nota/kwitansi).

**Prioritas:** Must Have · **Sprint:** 6 · **Role:** BENDAHARA

**Contoh kategori (dari ERD):** Gaji, Pembelian Alat, Dana BOS, Sumbangan.

---

#### KAS-02 — Ringkasan Keuangan Bulanan
**User Story:** Sebagai Kepala Sekolah / Admin, saya dapat melihat ringkasan keuangan bulanan: total pemasukan, pengeluaran, dan saldo.

**Acceptance Criteria:**
1. Dashboard keuangan menampilkan: saldo kas, total penerimaan SPP bulan ini, total pengeluaran bulan ini.
2. **Grafik tren 6 bulan terakhir.**

**Prioritas:** Must Have · **Sprint:** 6 · **Role:** KEPALA_SEKOLAH, SCHOOL_ADMIN

---

#### KAS-03 — Dashboard Keuangan Semua Cabang
**User Story:** Sebagai Super Admin, saya dapat melihat ringkasan keuangan semua cabang dalam satu dashboard.

**Acceptance Criteria:**
1. Tampil per cabang: total tagihan, total terkumpul, **persentase lunas**.
2. Filter per tahun ajaran / bulan.

**Prioritas:** Must Have · **Sprint:** 6 · **Role:** SUPER_ADMIN

---

### 9.8 FR-H · Notifikasi & Pengumuman (wa.me)

#### NOTIF-01 — Buat Pengumuman Bertarget
**User Story:** Sebagai Admin Sekolah, saya dapat membuat pengumuman dengan target (semua, per kelas, atau individu).

**Acceptance Criteria:**
1. Field: judul, isi pesan, target, kategori (`ACADEMIC` / `BILLING` / `EMERGENCY` / `GENERAL`).
2. Target "semua" mengirim ke semua user aktif di cabang.
3. Target "per kelas" hanya untuk **orang tua siswa kelas tersebut**.

**Prioritas:** Must Have · **Sprint:** 8 · **Role:** SCHOOL_ADMIN, KEPALA_SEKOLAH

**Tipe notifikasi di ERD:** `ANNOUNCEMENT`, `BILLING`, `ACADEMIC`, `EMERGENCY`, `SYSTEM`.
**Target di ERD:** `ALL`, `CLASS`, `INDIVIDUAL`.

---

#### NOTIF-02 — Daftar Link wa.me Siap Kirim
**User Story:** Sebagai Admin, untuk setiap notifikasi saya mendapatkan daftar link wa.me siap kirim per penerima.

**Acceptance Criteria:**
1. Sistem generate URL: `wa.me/62[nomorHP]?text=[pesan_ter-encode]`.
2. Daftar dapat difilter dan disalin satu per satu.
3. Tombol **"Buka WA"** membuka WhatsApp dengan pesan terisi.

**Prioritas:** Must Have · **Sprint:** 8 (dibutuhkan parsial sejak Sprint 3 untuk PPDB-04) · **Role:** SCHOOL_ADMIN

---

#### NOTIF-03 — Trigger Notifikasi Otomatis
**User Story:** Sebagai Sistem, notifikasi trigger otomatis tersedia untuk event tertentu.

**Acceptance Criteria:**
1. Trigger tersedia untuk: **PPDB status berubah**, **tagihan baru terbit**, **rapor diterbitkan**.
2. Template teks notifikasi dapat diedit oleh Admin Sekolah.
3. Notifikasi muncul di **in-app notification center (bell icon)** dan **wa.me link tersedia**.

**Prioritas:** Must Have · **Sprint:** 8 · **Role:** Sistem

**Template tersimpan di tabel `schools`:** `wa_template_ppdb`, `wa_template_spp`, `wa_template_rapor`.

---

#### NOTIF-04 — Notification Center In-App
**User Story:** Sebagai Pengguna, saya dapat melihat notifikasi yang belum dibaca di panel notifikasi.

**Acceptance Criteria:**
1. Jumlah notifikasi belum baca tampil di **badge (bell icon)**.
2. Klik notifikasi menandainya sebagai "dibaca".
3. Riwayat notifikasi tersimpan **90 hari**.

**Prioritas:** Must Have · **Sprint:** 8 · **Role:** Semua

---

### 9.9 FR-I · Portal Orang Tua, Guru, dan Siswa

#### PORTAL-01 — Dashboard Orang Tua
**User Story:** Sebagai Orang Tua, setelah login saya dapat melihat dashboard anak yang menampilkan informasi penting secara ringkas.

**Acceptance Criteria:**
1. Dashboard menampilkan: **3 nilai terbaru**, kehadiran bulan ini, tagihan belum lunas (jumlah & nominal).
2. Jika memiliki lebih dari satu anak, dapat **berpindah antar profil anak**.
3. **Responsive untuk tampilan mobile.**

**Prioritas:** Must Have · **Sprint:** 7 · **Role:** ORANG_TUA

> **Catatan konsistensi:** AC-1 menyebut "3 nilai terbaru", sedangkan `GET /parent/children/{studentId}/summary` pada API Map menyebut "nilai terbaru 5 mapel". Perlu klarifikasi ke pemilik blueprint.
> **Catatan dependency:** "Kehadiran bulan ini" memerlukan data absensi — lihat catatan pada SIS-04.

---

#### PORTAL-02 — Dashboard Guru
**User Story:** Sebagai Guru, saya dapat mengakses dasbor kerja yang menampilkan jadwal hari ini dan kelas yang aktif.

**Acceptance Criteria:**
1. Jadwal hari ini tampil di halaman utama.
2. Shortcut ke: **Input Nilai**, **Daftar Siswa Kelas**, **Buat Pengumuman**.

**Prioritas:** Must Have · **Sprint:** 7 · **Role:** GURU, WALI_KELAS

> **Catatan konsistensi:** Shortcut "Buat Pengumuman" bertentangan dengan matriks izin yang menyatakan GURU/WALI ❌ pada "Notifikasi (buat)". Perlu klarifikasi ke pemilik blueprint.

---

#### PORTAL-03 — Portal Siswa
**User Story:** Sebagai Siswa, saya dapat melihat jadwal pelajaran, nilai, dan notifikasi dari sekolah.

**Acceptance Criteria:**
1. Tab/menu: **Jadwal, Nilai, Notifikasi, Profil**.
2. Nilai menampilkan **per mata pelajaran, per semester, per komponen**.
3. Notifikasi dari sekolah tampil dengan timestamp.

**Prioritas:** Must Have · **Sprint:** 7 · **Role:** SISWA

---

#### PORTAL-04 — Manajemen Akun Pengguna
**User Story:** Sebagai Admin Sekolah, saya dapat mengelola akun pengguna (buat, edit, nonaktifkan, reset password).

**Acceptance Criteria:**
1. Pembuatan akun guru dan siswa bisa dilakukan **massal via import Excel**.
2. Reset password oleh Admin menghasilkan **password sementara** yang dikirim via notifikasi.

**Prioritas:** Must Have · **Sprint:** 1 · **Role:** SCHOOL_ADMIN, SUPER_ADMIN

---

### 9.10 Rekapitulasi Functional Requirement

| Kelompok | Kode | Jumlah | Sprint |
|---|---|:---:|---|
| Cross-Cutting: Auth, Multi-Tenant, White-Label | AUTH-01…05 | 5 | 1, 9 |
| Sistem Informasi Siswa | SIS-01…05 | 5 | 2 |
| PPDB Online | PPDB-01…05 | 5 | 3 |
| Manajemen Kelas & Jadwal | KELAS-01…04 | 4 | 2 |
| E-Rapor & Penilaian | NILAI-01…05 | 5 | 4 |
| Tagihan & Pembayaran SPP | SPP-01…05 | 5 | 5 |
| Akuntansi & Kas | KAS-01…03 | 3 | 6 |
| Notifikasi & Pengumuman | NOTIF-01…04 | 4 | 8 |
| Portal Ortu, Guru, Siswa | PORTAL-01…04 | 4 | 1, 7 |
| **TOTAL** | | **38** | |

---

## 10. Acceptance Criteria per Modul Utama

Bagian ini menetapkan **Definition of Done pada tingkat modul**. Isinya merupakan **agregasi** dari acceptance criteria per user story ([Bagian 9](#9-functional-requirements)), constraint ([Bagian 7](#7-constraints)), NFR ([Bagian 11](#11-non-functional-requirements)), dan checklist go-live (Lampiran A.3 blueprint). **Tidak ada kriteria baru di luar blueprint.**

Sebuah modul dinyatakan **SELESAI** apabila seluruh butir pada tabelnya terpenuhi.

---

### 10.1 AC Modul M0 — Core Platform (Multi-Tenant, Auth, RBAC, White-Label)

**FR tercakup:** AUTH-01, AUTH-02, AUTH-03, AUTH-04, AUTH-05, PORTAL-04
**Sprint:** 1 (AUTH-05 diselesaikan pada Sprint 9)

| # | Acceptance Criteria Modul | Sumber |
|---|---|---|
| AC-M0-01 | Seluruh AC pada AUTH-01…AUTH-05 dan PORTAL-04 terpenuhi | §9.1, §9.9 |
| AC-M0-02 | Login dengan email + password berhasil dan menghasilkan token; token kedaluwarsa setelah 8 jam tidak aktif | AUTH-01 |
| AC-M0-03 | Pesan error login tidak mengungkap detail sistem | AUTH-01 AC-2; CON-34 |
| AC-M0-04 | **Setiap query ke tabel bisnis otomatis ter-scope `WHERE school_id`** tanpa perlu ditulis manual di setiap query | AUTH-02; CON-15 |
| AC-M0-05 | **Unit test tenant isolation (Global Scope) lulus 100%** | Lampiran A.3 #1; NFR-06 |
| AC-M0-06 | Pengujian manual lintas-tenant membuktikan user cabang Madani tidak dapat melihat data cabang Cinangka | Lampiran A.3 #2 |
| AC-M0-07 | Super Admin (`school_id = NULL`) dapat mengakses data seluruh cabang | AUTH-02 AC-2; §3.2.2 |
| AC-M0-08 | Logo, `primary_color`, dan `secondary_color` cabang ter-inject sebagai CSS variables dan tampil benar setelah login | AUTH-03 |
| AC-M0-09 | Perubahan white-label oleh Admin Sekolah berlaku **tanpa deployment ulang** | AUTH-03 AC-3; CON-19 |
| AC-M0-10 | Reset password via email berfungsi; link berlaku 60 menit; seluruh sesi aktif ter-invalidate setelah reset | AUTH-04; CON-30 |
| AC-M0-11 | Toggle bahasa ID/EN tersedia di navbar, preferensi tersimpan di `users.locale`, dan seluruh label/error/placeholder tersedia dalam dua bahasa | AUTH-05 |
| AC-M0-12 | Admin dapat membuat, mengedit, menonaktifkan, dan mereset password akun pengguna; pembuatan akun guru & siswa dapat dilakukan massal via import Excel | PORTAL-04 |
| AC-M0-13 | Penonaktifan user bersifat *soft deactivate*, bukan hard delete | `DELETE /users/{id}`; CON-45 |
| AC-M0-14 | Matriks izin ([§8.2](#82-matriks-izin-per-modul-phase-1)) terimplementasi: setiap role hanya dapat mengakses modul sesuai tanda ✅/⭕/❌ | §8.2 |
| AC-M0-15 | Password tersimpan sebagai hash Argon2id/Bcrypt, minimal 8 karakter, dan wajib diganti pada login pertama | CON-27; NFR-07 |
| AC-M0-16 | Rate limit aktif: login maksimal 5 percobaan/menit; API maksimal 60 request/menit per user | CON-28 |
| AC-M0-17 | Seluruh aksi Create/Update/Delete tercatat dengan user, action, table, id, timestamp, dan IP | CON-33; NFR-12 |
| AC-M0-18 | Penambahan cabang baru oleh Super Admin tidak memerlukan perubahan kode | ASM-02; NFR-03 |

---

### 10.2 AC Modul M1.1 — Sistem Informasi Siswa (SIS)

**FR tercakup:** SIS-01, SIS-02, SIS-03, SIS-04, SIS-05
**Sprint:** 2

| # | Acceptance Criteria Modul | Sumber |
|---|---|---|
| AC-SIS-01 | Seluruh AC pada SIS-01…SIS-05 terpenuhi | §9.2 |
| AC-SIS-02 | Form tambah siswa memuat minimal: nama, NIS, NISN, tanggal lahir, jenis kelamin, agama, alamat, nama ortu, no. HP ortu | SIS-01 AC-1 |
| AC-SIS-03 | Validasi menolak NISN yang bukan 10 digit angka | SIS-01 AC-2; CON-38 |
| AC-SIS-04 | Validasi menolak NIS duplikat **dalam satu sekolah** (NIS boleh sama antar cabang) | SIS-01 AC-3; CON-38 |
| AC-SIS-05 | Edit data siswa tidak menghapus histori (*soft update*) | SIS-02 AC-1 |
| AC-SIS-06 | Siswa yang dinonaktifkan tetap ada di database dan tidak muncul di daftar kelas aktif | SIS-02 AC-2, AC-3; CON-45 |
| AC-SIS-07 | Upload foto menerima JPG/PNG/WEBP maksimal 2 MB dan menghasilkan file ter-resize 400×400 px | SIS-03; CON-43 |
| AC-SIS-08 | Guru hanya melihat siswa **aktif** pada kelas yang dia ampu, lengkap dengan nama, NIS, foto, dan status kehadiran hari ini | SIS-04 |
| AC-SIS-09 | Export Excel memuat seluruh field data siswa dan diberi nama `siswa_[kode_sekolah]_[tanggal].xlsx` | SIS-05 |
| AC-SIS-10 | Import siswa massal dari Excel berfungsi dan mengembalikan daftar error per baris | `POST /students/import`; `POST /users/import` |
| AC-SIS-11 | Seluruh data siswa ter-scope pada `school_id` cabang pengguna | CON-14, CON-15 |

> **Blocker terbuka:** AC-SIS-08 memerlukan data "status kehadiran hari ini" yang sumbernya **Belum dijelaskan dalam blueprint** (lihat catatan SIS-04). Modul tidak dapat dinyatakan 100% selesai sebelum isu ini diklarifikasi.

---

### 10.3 AC Modul M1.2 — PPDB Online

**FR tercakup:** PPDB-01, PPDB-02, PPDB-03, PPDB-04, PPDB-05
**Sprint:** 3

| # | Acceptance Criteria Modul | Sumber |
|---|---|---|
| AC-PPDB-01 | Seluruh AC pada PPDB-01…PPDB-05 terpenuhi | §9.3 |
| AC-PPDB-02 | Halaman `/ppdb/[kode_sekolah]` dapat diakses **tanpa login** | PPDB-01 AC-1 |
| AC-PPDB-03 | Form pendaftaran memuat: nama lengkap, jenis kelamin, tanggal lahir, asal sekolah, nama ortu, no. HP, email | PPDB-01 AC-2 |
| AC-PPDB-04 | Setelah submit, sistem menampilkan nomor pendaftaran unik berformat `[KODE_CABANG]-[TAHUN]-[SEQ]` | PPDB-01 AC-3; ERD `reg_number` |
| AC-PPDB-05 | Halaman cek status dapat diakses publik menggunakan nomor daftar + tanggal lahir | PPDB-02; `GET /ppdb/check-status` |
| AC-PPDB-06 | Kelima status alur PPDB berfungsi: `REGISTERED` → `DOCUMENT_REVIEW` → `PASSED`/`FAILED` → `ENROLLED` | PPDB-02 AC-2 |
| AC-PPDB-07 | Admin dapat melihat daftar pendaftar dalam tabel (no. daftar, nama, asal sekolah, status, tanggal daftar) dan memfilter per status | PPDB-03 AC-1, AC-2 |
| AC-PPDB-08 | Setiap perubahan status tersimpan **beserta catatan alasan** | PPDB-03 AC-3 |
| AC-PPDB-09 | Sistem menghasilkan link wa.me dengan template teks siap kirim untuk setiap perubahan status | PPDB-04 AC-1, AC-2 |
| AC-PPDB-10 | Tombol "Buka WhatsApp" membuka WhatsApp dengan pesan sudah terisi; pengiriman dilakukan **manual** oleh admin | PPDB-04 AC-3; CON-49 |
| AC-PPDB-11 | Enroll satu klik menyalin data PPDB ke form siswa, memungkinkan admin melengkapi data, lalu mengubah status menjadi `ENROLLED` | PPDB-05 |
| AC-PPDB-12 | Setelah enroll, `converted_student_id` terisi dan record siswa terbentuk di modul SIS | ERD `ppdb_registrations` |
| AC-PPDB-13 | Data pendaftar ter-scope pada cabang yang bersangkutan meskipun form bersifat publik | CON-14 |
| AC-PPDB-14 | Uji encoding: seluruh template teks wa.me ter-encode dengan benar | Lampiran A.3 #4 |

---

### 10.4 AC Modul M1.3–M1.6 — Tahun Ajaran, Kelas, Mata Pelajaran & Jadwal

**FR tercakup:** KELAS-01, KELAS-02, KELAS-03, KELAS-04
**Sprint:** 2

| # | Acceptance Criteria Modul | Sumber |
|---|---|---|
| AC-KELAS-01 | Seluruh AC pada KELAS-01…KELAS-04 terpenuhi | §9.4 |
| AC-KELAS-02 | Admin dapat membuat tahun ajaran dan mengaktifkan salah satunya; **hanya satu tahun ajaran aktif per sekolah** | CON-37; `PATCH /academic-years/{id}/activate` |
| AC-KELAS-03 | Admin dapat membuat mata pelajaran per cabang (nama, kode, jam pelajaran) | `POST /subjects`; ERD `subjects` |
| AC-KELAS-04 | Pembuatan kelas memuat field: nama kelas, tingkat, wali kelas, kapasitas | KELAS-01 AC-1 |
| AC-KELAS-05 | Wali kelas hanya dapat dipilih dari daftar **guru aktif** | KELAS-01 AC-2 |
| AC-KELAS-06 | Sistem menolak penetapan satu guru sebagai wali kelas di lebih dari satu kelas pada tahun ajaran yang sama | KELAS-01 AC-3; CON-36 |
| AC-KELAS-07 | Daftar pilihan siswa hanya menampilkan siswa aktif yang belum terdaftar di kelas manapun untuk tahun ajaran tersebut | KELAS-02 AC-1 |
| AC-KELAS-08 | Sistem menolak penempatan satu siswa di lebih dari satu kelas pada tahun ajaran yang sama | KELAS-02 AC-2; CON-35 |
| AC-KELAS-09 | Pembuatan jadwal memuat: kelas, mata pelajaran, guru, hari, jam mulai, jam selesai, ruang | KELAS-03 AC-1 |
| AC-KELAS-10 | **Sistem mendeteksi dan menolak konflik jadwal** untuk guru, ruangan, maupun kelas yang sama pada waktu bersamaan | KELAS-03 AC-2; CON-48 |
| AC-KELAS-11 | Guru dapat melihat jadwal mengajarnya untuk minggu berjalan dalam format tabel/kalender, dan klik jadwal menampilkan detail kelas, mapel, ruang | KELAS-04 |
| AC-KELAS-12 | Guru hanya melihat jadwal dirinya sendiri | `GET /schedules` |

---

### 10.5 AC Modul M1.7–M1.8 — Penilaian & E-Rapor

**FR tercakup:** NILAI-01, NILAI-02, NILAI-03, NILAI-04, NILAI-05
**Sprint:** 4

| # | Acceptance Criteria Modul | Sumber |
|---|---|---|
| AC-NILAI-01 | Seluruh AC pada NILAI-01…NILAI-05 terpenuhi | §9.5 |
| AC-NILAI-02 | Admin dapat mengkonfigurasi komponen dan bobot penilaian yang **berbeda antar mata pelajaran** | NILAI-05 AC-1 |
| AC-NILAI-03 | Perubahan konfigurasi bobot **hanya berlaku untuk tahun ajaran baru** | NILAI-05 AC-2; CON-42 |
| AC-NILAI-04 | Guru dapat menginput nilai skala 0–100 secara satuan maupun melalui import Excel | NILAI-01 AC-1, AC-2; CON-39 |
| AC-NILAI-05 | Guru hanya dapat menginput nilai untuk kelas yang dia ampu | `POST /grades`; §8.3 |
| AC-NILAI-06 | Nilai dapat diedit selama rapor belum diterbitkan, dan **tidak dapat diedit** setelah publish | NILAI-01 AC-3; CON-40 |
| AC-NILAI-07 | Nilai akhir terhitung otomatis dengan formula `(Harian × bobot) + (UTS × bobot) + (UAS × bobot)` dan dibulatkan 2 desimal | NILAI-02; CON-39 |
| AC-NILAI-08 | Sistem menolak publish rapor jika masih ada mata pelajaran yang belum memiliki nilai akhir | NILAI-03 AC-1; CON-41 |
| AC-NILAI-09 | Publish rapor hanya dapat dilakukan oleh **Wali Kelas** untuk kelas yang diampunya | NILAI-03; `POST /report-cards/generate` |
| AC-NILAI-10 | Setelah publish, `is_published`, `published_at`, dan `published_by` terisi, dan rapor tampil di portal siswa & orang tua | NILAI-03 AC-2, AC-3; ERD `report_cards` |
| AC-NILAI-11 | Siswa dan orang tua dapat melihat nilai harian/UTS/UAS **segera setelah guru menyimpan**, sebelum rapor terbit | NILAI-04 AC-1 |
| AC-NILAI-12 | Rapor final dapat diunduh dalam format PDF | NILAI-04 AC-3; `GET /report-cards/{id}/pdf` |
| AC-NILAI-13 | **Uji PDF rapor: format dan data sesuai** | Lampiran A.3 #5 |
| AC-NILAI-14 | Publish rapor memicu notifikasi ke orang tua | NOTIF-03 AC-1; `POST /report-cards/{id}/publish` |

> **Blocker terbuka:** rapor memuat rekap kehadiran (`attend_present`, `attend_sick`, `attend_permission`, `attend_absent`) yang sumber datanya **Belum dijelaskan dalam blueprint.** Rumus `rank_in_class` juga **Belum dijelaskan dalam blueprint.**

---

### 10.6 AC Modul M3 — Keuangan (Tagihan SPP, Pembayaran, Kas & Laporan)

**FR tercakup:** SPP-01…SPP-05, KAS-01, KAS-02, KAS-03
**Sprint:** 5–6

| # | Acceptance Criteria Modul | Sumber |
|---|---|---|
| AC-KEU-01 | Seluruh AC pada SPP-01…SPP-05 dan KAS-01…KAS-03 terpenuhi | §9.6, §9.7 |
| AC-KEU-02 | Bendahara dapat membuat jenis tagihan dengan nama, nominal Rupiah, dan frekuensi `MONTHLY`/`YEARLY`/`ONCE` | SPP-01 AC-1 |
| AC-KEU-03 | Jenis tagihan dapat dinonaktifkan **tanpa menghapus histori** | SPP-01 AC-2; CON-45 |
| AC-KEU-04 | Generate tagihan massal membuat record `student_fees` untuk **setiap siswa aktif** dengan due date terisi otomatis | SPP-02 AC-1, AC-2 |
| AC-KEU-05 | **Preview daftar tagihan wajib tampil sebelum konfirmasi generate** | SPP-02 AC-3; CON-47 |
| AC-KEU-06 | Pencatatan pembayaran memuat: nama siswa, periode, metode bayar, jumlah, tanggal, referensi | SPP-03 AC-1 |
| AC-KEU-07 | Bukti pembayaran dapat diunggah dalam format JPG/PNG/PDF maksimal 5 MB | SPP-03 AC-2; CON-44 |
| AC-KEU-08 | Status tagihan berubah otomatis ke `PAID` (lunas) atau `PARTIAL` (sebagian) sesuai akumulasi pembayaran | SPP-03 AC-3 |
| AC-KEU-09 | Satu tagihan dapat menerima beberapa pembayaran (cicilan) dan `amount_paid` terakumulasi dengan benar | ERD `payments`, `student_fees` |
| AC-KEU-10 | Admin dapat membebaskan tagihan dengan alasan (status → `WAIVED`) | `PATCH /student-fees/{id}/waive`; ERD `waive_reason` |
| AC-KEU-11 | Orang tua melihat tagihan anak per periode; tagihan belum lunas **ditandai merah/warning**; riwayat memuat tanggal dan metode bayar | SPP-04 |
| AC-KEU-12 | Orang tua hanya dapat melihat tagihan anaknya sendiri | `GET /students/{id}/fees`; §8.3 |
| AC-KEU-13 | Export laporan tagihan memuat kolom: nama siswa, kelas, periode, jumlah tagihan, jumlah bayar, sisa, status — dengan filter kelas/periode/status | SPP-05 |
| AC-KEU-14 | Bendahara dapat mencatat transaksi kas `INCOME`/`EXPENSE` beserta kategori, jumlah, tanggal, keterangan, no. referensi, dan lampiran bukti | KAS-01 |
| AC-KEU-15 | Dashboard keuangan cabang menampilkan saldo kas, total penerimaan SPP bulan ini, total pengeluaran bulan ini, dan **grafik tren 6 bulan terakhir** | KAS-02 |
| AC-KEU-16 | Dashboard Super Admin menampilkan per cabang: total tagihan, total terkumpul, **persentase lunas**, dengan filter tahun ajaran/bulan | KAS-03 |
| AC-KEU-17 | Seluruh data keuangan ter-scope pada `school_id`; Bendahara satu cabang tidak dapat melihat keuangan cabang lain | CON-15, CON-26 |
| AC-KEU-18 | Generate tagihan massal untuk seluruh siswa aktif berjalan tanpa timeout (diproses melalui queue) | §3.1 Queue; §3.3.1 |

> **Blocker terbuka:** perilaku generate tagihan untuk frekuensi `YEARLY` dan `ONCE` **Belum dijelaskan dalam blueprint** — modul tidak dapat dinyatakan lengkap untuk kedua frekuensi tersebut.

---

### 10.7 AC Modul M5.1–M5.2 — Notifikasi & wa.me

**FR tercakup:** NOTIF-01, NOTIF-02, NOTIF-03, NOTIF-04
**Sprint:** 8 (kemampuan generate wa.me dibutuhkan parsial sejak Sprint 3)

| # | Acceptance Criteria Modul | Sumber |
|---|---|---|
| AC-NOTIF-01 | Seluruh AC pada NOTIF-01…NOTIF-04 terpenuhi | §9.8 |
| AC-NOTIF-02 | Pengumuman dapat dibuat dengan judul, isi pesan, target, dan kategori (`ACADEMIC`/`BILLING`/`EMERGENCY`/`GENERAL`) | NOTIF-01 AC-1 |
| AC-NOTIF-03 | Target `ALL` mengirim ke **semua user aktif di cabang tersebut** | NOTIF-01 AC-2 |
| AC-NOTIF-04 | Target `CLASS` mengirim **hanya kepada orang tua siswa kelas tersebut** | NOTIF-01 AC-3 |
| AC-NOTIF-05 | Sistem menghasilkan URL `wa.me/62[nomorHP]?text=[pesan_ter-encode]` untuk setiap penerima | NOTIF-02 AC-1 |
| AC-NOTIF-06 | Daftar link wa.me dapat difilter dan disalin satu per satu; tombol "Buka WA" membuka WhatsApp dengan pesan terisi | NOTIF-02 AC-2, AC-3 |
| AC-NOTIF-07 | **Uji encoding: seluruh template teks wa.me ter-encode dengan benar** | Lampiran A.3 #4 |
| AC-NOTIF-08 | Trigger otomatis aktif untuk tiga event: **PPDB status berubah**, **tagihan baru terbit**, **rapor diterbitkan** | NOTIF-03 AC-1 |
| AC-NOTIF-09 | Template teks notifikasi dapat diedit oleh Admin Sekolah dan tersimpan di `wa_template_ppdb`, `wa_template_spp`, `wa_template_rapor` | NOTIF-03 AC-2; ERD `schools` |
| AC-NOTIF-10 | Setiap notifikasi muncul di notification center in-app **dan** menyediakan link wa.me | NOTIF-03 AC-3 |
| AC-NOTIF-11 | Badge bell icon menampilkan jumlah notifikasi belum dibaca; klik notifikasi menandainya sebagai dibaca dan tercatat di `notification_reads` | NOTIF-04 AC-1, AC-2 |
| AC-NOTIF-12 | Riwayat notifikasi tersimpan **90 hari** | NOTIF-04 AC-3; CON-46 |
| AC-NOTIF-13 | Notifikasi ter-scope pada cabang; user tidak menerima notifikasi dari cabang lain | CON-15 |

---

### 10.8 AC Modul M5.3–M5.5 — Portal Orang Tua, Siswa & Guru

**FR tercakup:** PORTAL-01, PORTAL-02, PORTAL-03
**Sprint:** 7

| # | Acceptance Criteria Modul | Sumber |
|---|---|---|
| AC-PORTAL-01 | Seluruh AC pada PORTAL-01…PORTAL-03 terpenuhi | §9.9 |
| AC-PORTAL-02 | Dashboard orang tua menampilkan nilai terbaru, kehadiran bulan ini, dan tagihan belum lunas (jumlah & nominal) | PORTAL-01 AC-1 |
| AC-PORTAL-03 | Orang tua dengan lebih dari satu anak dapat berpindah antar profil anak | PORTAL-01 AC-2 |
| AC-PORTAL-04 | Seluruh halaman parent portal **responsive pada tampilan mobile** | PORTAL-01 AC-3; NFR-10 |
| AC-PORTAL-05 | Dashboard guru menampilkan jadwal hari ini beserta shortcut Input Nilai, Daftar Siswa Kelas, dan Buat Pengumuman | PORTAL-02 |
| AC-PORTAL-06 | Portal siswa menyediakan menu Jadwal, Nilai, Notifikasi, dan Profil | PORTAL-03 AC-1 |
| AC-PORTAL-07 | Nilai pada portal siswa ditampilkan per mata pelajaran, per semester, dan per komponen | PORTAL-03 AC-2 |
| AC-PORTAL-08 | Notifikasi pada portal siswa tampil dengan timestamp | PORTAL-03 AC-3 |
| AC-PORTAL-09 | Setiap portal hanya menampilkan data milik pengguna yang bersangkutan (siswa melihat dirinya sendiri; ortu melihat anaknya; guru melihat kelas ajarnya) | §8.3; CON-26 |
| AC-PORTAL-10 | Halaman utama portal termuat **< 3 detik** pada koneksi 4G (10 Mbps) | NFR-01 |
| AC-PORTAL-11 | Portal tetap berfungsi dengan **200 user konkuren** tanpa error/timeout | NFR-04; Lampiran A.3 #3 |

> **Blocker terbuka:** AC-PORTAL-02 memerlukan data "kehadiran bulan ini" yang sumbernya **Belum dijelaskan dalam blueprint.** Jumlah nilai terbaru pada dashboard (3 vs 5) juga masih ambigu — lihat catatan PORTAL-01.

---

### 10.9 AC Rilis Phase 1 (MVP)

Phase 1 dinyatakan **siap go-live** apabila seluruh AC modul di atas terpenuhi **dan** seluruh 10 butir checklist Lampiran A.3 tercapai. Lihat [§13.5 Metrik Penyelesaian Proyek](#135-metrik-penyelesaian-proyek-definition-of-done--phase-1).

---

## 11. Non Functional Requirements

### 11.1 Daftar Kebutuhan Non-Fungsional

| ID | Kategori | Requirement | Target / Standar |
|---|---|---|---|
| **NFR-01** | Performa | Waktu load halaman utama | **< 3 detik** pada koneksi 4G (10 Mbps) |
| **NFR-02** | Performa | Response time API | **< 500 ms** untuk 95% request |
| **NFR-03** | Skalabilitas | Jumlah tenant (cabang) | Dapat menampung **10+ cabang** tanpa refactoring |
| **NFR-04** | Skalabilitas | Jumlah user konkuren | Minimal **200 user konkuren** pada VPS 2C/2GB |
| **NFR-05** | Keamanan | Autentikasi | JWT + session, HTTPS wajib, CSRF protection |
| **NFR-06** | Keamanan | Data isolation | **100%** — tidak ada kebocoran data antar tenant |
| **NFR-07** | Keamanan | Password | Bcrypt/Argon2, minimal 8 karakter, **wajib ganti password pertama** |
| **NFR-08** | Ketersediaan | Uptime target | **99% per bulan** (~7 jam downtime/bulan) |
| **NFR-09** | Backup | Frekuensi backup database | **Harian otomatis**, retensi **30 hari** |
| **NFR-10** | Aksesibilitas | Perangkat yang didukung | Desktop (Chrome/Firefox/Edge) + Mobile (browser iOS/Android) |
| **NFR-11** | Lokalisasi | Bahasa | Bahasa Indonesia (default) + English, dapat diperluas |
| **NFR-12** | Audit | Log aktivitas | Semua aksi CRUD dicatat di tabel `audit_logs` dengan user & timestamp |

> **Catatan:** Tabel `audit_logs` disyaratkan oleh NFR-12 dan §3.4 Arsitektur Keamanan, namun **tidak termasuk dalam daftar 21 tabel** pada ERD blueprint. Struktur tabelnya: **Belum dijelaskan dalam blueprint.**

### 11.2 Arsitektur Keamanan (Detail Implementasi)

| Aspek | Implementasi | Detail |
|---|---|---|
| Autentikasi | Laravel Sanctum (SPA mode) | Cookie-based session token untuk web app; Bearer token untuk API mobile future |
| Otorisasi | `spatie/laravel-permission` + Gate | RBAC berbasis peran; policy per model (`StudentPolicy`, `GradePolicy`, dll.) |
| Isolasi Tenant | Eloquent Global Scope | `WHERE school_id` wajib pada semua query; **diverifikasi via unit test** |
| Input Validation | Laravel Form Request | Validasi semua input sebelum pemrosesan; sanitasi XSS via `htmlspecialchars` |
| SQL Injection | Eloquent ORM (parameterized query) | Raw SQL dilarang kecuali `DB::select()` dengan binding |
| CSRF Protection | Laravel CSRF Middleware | Token CSRF wajib untuk semua request POST/PUT/DELETE dari form web |
| HTTPS | Let's Encrypt (TLS 1.2/1.3) | Redirect HTTP → HTTPS via Nginx; **HSTS header aktif** |
| File Upload | Validasi MIME + ukuran | Hanya JPG/PNG/PDF; disimpan di `storage/` (di luar web root) |
| Password | Argon2id (Laravel default) | Minimum 8 karakter; **password pertama wajib diganti saat login pertama** |
| Rate Limiting | Laravel Throttle Middleware | Login: **max 5 percobaan/menit**; API: **max 60 request/menit per user** |
| Audit Log | Custom Middleware + Event | Semua aksi CUD dicatat: user, action, table, id, timestamp, IP |
| Backup | `mysqldump` via cron | Backup harian **pukul 02:00 WIB**; retensi 30 hari; upload ke Backblaze B2 (Phase 1: lokal) |

### 11.3 Kebutuhan Arsitektur

| Aspek | Ketentuan |
|---|---|
| Pola multi-tenancy | Shared Database, Shared Schema — isolasi via kolom `school_id` |
| Global Scope | Semua query aplikasi **WAJIB** menggunakan global scope Laravel yang menambahkan `WHERE school_id = auth()->user()->school_id` secara otomatis |
| Pengecualian Super Admin | `if (auth()->user()->isSuperAdmin()) { return $query; } // skip scope` |
| White-label theming | Middleware menyuntikkan CSS variables `--color-primary` & `--color-secondary` ke `<head>`; semua komponen UI menggunakan `var(--color-primary)` |
| Deployment | Single VPS: Nginx (80/443) + PHP-FPM (9000) + MySQL 8 (localhost only) + Laravel Queue (supervisor) + Certbot (cron harian) + Cloudflare DNS |
| Domain | Single domain `apps.smartsukses.sch.id` untuk seluruh tenant |

### 11.4 Konvensi API

| Elemen | Konvensi |
|---|---|
| Base URL | `https://apps.smartsukses.sch.id/api/v1` |
| Content-Type | `application/json` |
| Auth Header | `Authorization: Bearer {token}` (Laravel Sanctum) |
| Response sukses | `{ "success": true, "data": {...}, "message": "..." }` |
| Response error | `{ "success": false, "message": "...", "errors": {...} }` |
| Pagination | `{ "data": [...], "meta": { "total", "page", "per_page", "last_page" } }` |
| Timestamp format | ISO 8601 — `2025-08-06T10:30:00+07:00` |
| Tenant isolation | Semua endpoint Auth otomatis di-scope ke `school_id` user yang login |

---

## 12. Dependencies Antar Modul

Bagian ini memetakan ketergantungan antar modul Phase 1. Urutan implementasi pada [§5.4](#54-rencana-rilis-phase-1) mengikuti peta ini.

### 12.1 Peta Dependency

```
                        ┌─────────────────────────────────────┐
                        │  M0 · CORE PLATFORM                 │
                        │  schools · users · roles            │
                        │  Global Scope school_id · RBAC      │
                        │  White-label · Locale · Audit       │
                        └───────────────┬─────────────────────┘
                                        │  (semua modul bergantung)
              ┌─────────────────────────┼──────────────────────────┐
              │                         │                          │
     ┌────────▼─────────┐               │                          │
     │ M1.3 Tahun Ajaran│◄──────────────┼──────────────┐           │
     │ academic_years   │               │              │           │
     └────────┬─────────┘               │              │           │
              │                         │              │           │
     ┌────────▼─────────┐      ┌────────▼────────┐     │           │
     │ M1.1 SIS         │◄─────┤ M1.2 PPDB       │     │           │
     │ students         │enroll│ ppdb_registr.   │     │           │
     └────┬────────┬────┘      └─────────────────┘     │           │
          │        │                                    │           │
          │        └──────────────────┐                 │           │
          │                           │                 │           │
 ┌────────▼──────────┐      ┌─────────▼─────────┐       │           │
 │ M1.4 Kelas/Rombel │      │ M3 KEUANGAN       │       │           │
 │ classes           │      │ fee_types         │       │           │
 │ student_classes   │      │ student_fees      │       │           │
 └────────┬──────────┘      │ payments          │       │           │
          │                 │ transactions      │       │           │
 ┌────────▼──────────┐      └─────────┬─────────┘       │           │
 │ M1.5 Mapel+Guru   │                │                 │           │
 │ subjects          │                │                 │           │
 │ class_subjects    │                │                 │           │
 └────┬─────────┬────┘                │                 │           │
      │         │                     │                 │           │
┌─────▼─────┐ ┌─▼──────────────┐      │                 │           │
│M1.6 Jadwal│ │M1.7 Penilaian  │      │                 │           │
│schedules  │ │grades          │      │                 │           │
│           │ │grade_configs   │      │                 │           │
└─────┬─────┘ └─┬──────────────┘      │                 │           │
      │         │                     │                 │           │
      │  ┌──────▼──────────┐          │                 │           │
      │  │M1.8 E-Rapor     │          │                 │           │
      │  │report_cards     │          │                 │           │
      │  └──────┬──────────┘          │                 │           │
      │         │                     │                 │           │
      └─────────┼─────────────────────┼─────────────────┘           │
                │                     │                             │
        ┌───────▼─────────────────────▼──────────┐                  │
        │ M5.1/5.2 NOTIFIKASI & wa.me            │◄─────────────────┘
        │ notifications · notification_reads     │  (trigger: PPDB, SPP, Rapor)
        └───────────────────┬────────────────────┘
                            │
        ┌───────────────────▼────────────────────┐
        │ M5.3/5.4/5.5 PORTAL                    │
        │ Parent · Siswa · Guru                  │
        │ (agregator: nilai + tagihan + jadwal   │
        │  + notifikasi + kehadiran*)            │
        └────────────────────────────────────────┘
                 * sumber data kehadiran: Belum dijelaskan dalam blueprint
```

### 12.2 Matriks Dependency Modul

| Modul | Bergantung pada | Dibutuhkan oleh |
|---|---|---|
| **M0** Core Platform | — | Seluruh modul |
| **M1.3** Tahun Ajaran | M0 | M1.2, M1.4, M1.5, M1.6, M1.7, M1.8, M3.1, M3.2 |
| **M1.1** SIS | M0, M1.3 | M1.2 (enroll), M1.4, M1.7, M1.8, M3.2, M3.3, M5.3, M5.4 |
| **M1.2** PPDB | M0, M1.3, M1.1 (target enroll), M5.2 (wa.me link) | M1.1 (sumber siswa baru) |
| **M1.4** Kelas & Rombel | M0, M1.3, M1.1 | M1.5, M1.6, M1.8, M3.5 (filter kelas), M5.1 (target CLASS), M5.3–5.5 |
| **M1.5** Mapel & Pengampu | M0, M1.3, M1.4, users (guru) | M1.6, M1.7, grade_configs |
| **M1.6** Jadwal | M0, M1.5 | M5.3, M5.4, M5.5 |
| **M1.7** Penilaian | M0, M1.1, M1.3, M1.5, grade_configs | M1.8, M5.3, M5.4 |
| **M1.8** E-Rapor & PDF | M0, M1.1, M1.4, M1.7, data kehadiran* | M5.1 (trigger), M5.3, M5.4 |
| **M3.1** Jenis Tagihan | M0, M1.3 | M3.2 |
| **M3.2** Generate Tagihan | M0, M3.1, M1.1 (siswa aktif) | M3.3, M3.5, M5.1 (trigger), M5.3 |
| **M3.3** Pembayaran | M0, M3.2 | M3.5, M5.3, KAS-03 |
| **M3.4** Buku Kas | M0 | M3.5, KAS-02, KAS-03 |
| **M3.5** Laporan & Export | M0, M1.4, M3.2, M3.3, M3.4 | Dashboard Kepsek & Super Admin |
| **M5.1** Notifikasi In-App | M0 | M5.3, M5.4, M5.5 |
| **M5.2** wa.me Generator | M0, `users.phone` / `students.parent_phone`, template di `schools` | M1.2 (PPDB-04), M3.2, M1.8, NOTIF-01 |
| **M5.3** Parent Portal | M0, M1.1, M1.6, M1.7, M1.8, M3.2, M3.3, M5.1 | — (konsumen akhir) |
| **M5.4** Portal Siswa | M0, M1.1, M1.6, M1.7, M1.8, M5.1 | — (konsumen akhir) |
| **M5.5** Portal Guru | M0, M1.4, M1.5, M1.6, M5.1 | — (konsumen akhir) |

\* Sumber data kehadiran: **Belum dijelaskan dalam blueprint.**

### 12.3 Jalur Kritis (Critical Path)

```
M0 Core (tenant + auth + RBAC + Global Scope)
  └─→ M1.3 Tahun Ajaran
        └─→ M1.1 SIS
              ├─→ M1.4 Kelas → M1.5 class_subjects → M1.7 Penilaian → M1.8 E-Rapor
              └─→ M3.2 Tagihan → M3.3 Pembayaran
                    └─→ M5.3 Parent Portal  ← titik konvergensi (7 dependency)
```

**Implikasi:**
- **M0 tidak boleh dilewati atau dikerjakan sebagian.** Global Scope yang menyusul belakangan berisiko meninggalkan query tanpa filter `school_id` — melanggar CON-26 (toleransi nol).
- **Parent Portal (M5.3) memiliki dependency terbanyak** dan karena itu ditempatkan pada Sprint 7, setelah akademik (Sprint 4) dan keuangan (Sprint 5–6) selesai.

### 12.4 Dependency Lintas-Sprint yang Perlu Diperhatikan

| Ketergantungan | Kondisi dalam Blueprint | Catatan |
|---|---|---|
| **PPDB (Sprint 3) → SIS (Sprint 2)** | Enroll menulis record ke tabel `students` | Urutan sprint blueprint sudah sesuai |
| **PPDB-04 (Sprint 3) → wa.me generator (Sprint 8)** | PPDB-04 membutuhkan kemampuan generate link wa.me, namun modul Notifikasi dijadwalkan Sprint 8 | Cara penanganannya: **Belum dijelaskan dalam blueprint.** |
| **NOTIF-03 (Sprint 8) → PPDB / SPP / Rapor** | Trigger otomatis bergantung pada tiga modul yang selesai lebih dahulu (Sprint 3, 5, 4) | Trigger dipasang mundur pada Sprint 8 |
| **AUTH-05 Bilingual (Sprint 9) → seluruh sprint sebelumnya** | Semua label/error/placeholder harus dwibahasa, sementara pengerjaan EN dijadwalkan Sprint 9 | Penggunaan translation key sejak Sprint 1 mengurangi kerja retrofit |
| **M1.8 E-Rapor & M5.3 Parent Portal → data kehadiran** | Rapor dan dashboard ortu memerlukan data absensi; modul Presensi Digital ada di Phase 2 | **Belum dijelaskan dalam blueprint.** |

### 12.5 Paralelisasi yang Diizinkan Blueprint

| Kombinasi | Syarat |
|---|---|
| Sprint 3 (PPDB) ∥ Sprint 4 (Akademik) | 2 developer; keduanya bergantung pada Sprint 1–2 yang sudah selesai |
| Sprint 5 (Keuangan) ∥ Sprint 6 (Akuntansi) | 2 developer |

Kombinasi paralel di luar dua pasangan tersebut: **Belum dijelaskan dalam blueprint.**

### 12.6 Dependency Eksternal

| Dependensi | Dipakai oleh | Catatan |
|---|---|---|
| WhatsApp (wa.me) | M5.2, PPDB-04, NOTIF-02 | Manual, gratis, tanpa API pada Phase 1 |
| SMTP (Gmail / Mailtrap) | AUTH-04, verifikasi email, reset password oleh admin | Limit 500–2.000 email/hari |
| DomPDF / Browsershot | NILAI-04 (rapor PDF), laporan keuangan | — |
| Let's Encrypt + Certbot | HTTPS | Auto-renew setiap 90 hari |
| Cloudflare Free | DNS, proteksi DDoS, cache statis | — |
| Library import/export Excel | SIS-05, SPP-05, PORTAL-04, NILAI-01, `/finance/export` | Nama library: **Belum dijelaskan dalam blueprint.** |
| `spatie/laravel-multitenancy`, `spatie/laravel-permission`, Laravel Sanctum | M0 | Ditetapkan blueprint (CON-05, CON-06) |
| Google Meet | Phase 2 (LMS) | Link generation, gratis |

---

## 13. Success Metrics

> **Catatan penting:** Blueprint **tidak memuat bagian Success Metrics / KPI bisnis secara eksplisit**. Metrik di bawah adalah **target terukur yang dinyatakan langsung dalam blueprint** (NFR, checklist go-live, target skala). KPI bisnis di luar itu — misalnya tingkat adopsi pengguna, penurunan tunggakan SPP, atau tingkat kepuasan orang tua — **Belum dijelaskan dalam blueprint.**

### 13.1 Metrik Teknis — Performa & Ketersediaan

| ID | Metrik | Target | Cara Ukur | Sumber |
|---|---|---|---|---|
| SM-01 | Waktu load halaman utama | < 3 detik pada 4G (10 Mbps) | Pengujian performa halaman | NFR-01 |
| SM-02 | Response time API | < 500 ms untuk 95% request | Monitoring p95 latency | NFR-02 |
| SM-03 | Kapasitas user konkuren | ≥ 200 user tanpa error/timeout | Load test (checklist go-live) | NFR-04; Lampiran A.3 |
| SM-04 | Uptime bulanan | ≥ 99% (maks ~7 jam downtime/bulan) | Monitoring uptime (UptimeRobot / Better Stack) | NFR-08; Lampiran A.3 |

### 13.2 Metrik Keamanan & Integritas Data

| ID | Metrik | Target | Cara Ukur | Sumber |
|---|---|---|---|---|
| SM-05 | Isolasi data antar tenant | **100%** — nol kebocoran | Unit test Global Scope lulus 100% | NFR-06; Lampiran A.3 |
| SM-06 | Uji akses lintas-tenant | Nol pelanggaran | User Madani terverifikasi tidak dapat melihat data Cinangka | Lampiran A.3 |
| SM-07 | Backup dapat dipulihkan | Backup harian berjalan **dan dapat di-restore** | Uji restore berkala | NFR-09; Lampiran A.3 |
| SM-08 | Cakupan audit log | Seluruh aksi CRUD tercatat | Verifikasi isi `audit_logs` | NFR-12 |

### 13.3 Metrik Skalabilitas

| ID | Metrik | Target | Sumber |
|---|---|---|---|
| SM-09 | Jumlah cabang yang dapat ditampung | 10+ cabang tanpa refactoring | NFR-03 |
| SM-10 | Penambahan cabang baru | Dapat ditambah **tanpa pengembangan ulang** | §Target Skala Awal |
| SM-11 | Kapasitas akun pengguna | 800–1.500 akun pada skala awal | §Target Skala Awal |

### 13.4 Metrik Efisiensi Biaya

| ID | Metrik | Target | Sumber |
|---|---|---|---|
| SM-12 | Biaya infrastruktur bulanan | Rp 90.000–130.000 / bulan untuk 3 cabang | Lampiran A.2 |

### 13.5 Metrik Penyelesaian Proyek (Definition of Done — Phase 1)

Checklist go-live dari Lampiran A.3 berfungsi sebagai kriteria keberhasilan rilis MVP:

| # | Kriteria | Status |
|---|---|:---:|
| 1 | Semua unit test untuk Global Scope (tenant isolation) lulus 100% | ☐ |
| 2 | Pengujian akses lintas-tenant: user Madani tidak bisa melihat data Cinangka | ☐ |
| 3 | Load test: 200 user konkuren mengakses dashboard tanpa error/timeout | ☐ |
| 4 | Pengujian wa.me link: semua template teks ter-encode dengan benar | ☐ |
| 5 | Pengujian PDF rapor: format dan data sesuai | ☐ |
| 6 | SSL aktif dan redirect HTTP→HTTPS berjalan | ☐ |
| 7 | Backup database otomatis berjalan dan dapat di-restore | ☐ |
| 8 | Semua password default diubah (MySQL root, admin panel) | ☐ |
| 9 | CORS dikonfigurasi hanya menerima dari domain `apps.smartsukses.sch.id` | ☐ |
| 10 | Monitoring uptime diaktifkan (UptimeRobot free tier atau Better Stack) | ☐ |

### 13.6 Metrik Kesiapan Phase 2

| ID | Kriteria | Target | Sumber |
|---|---|---|---|
| SM-13 | Masa pemakaian Phase 1 sebelum Phase 2 dimulai | **Minimal 3 bulan** dalam kondisi stabil | §1.3 Gambaran Umum Phase 2 |

---

## 14. Out of Scope

### 14.1 Secara Eksplisit Dinyatakan di Luar Scope MVP

Blueprint menyatakan: *"Fitur-fitur berikut **tidak termasuk dalam scope MVP (Phase 1)**."*

| # | Fitur / Modul | Alasan |
|---|---|---|
| 1 | **LMS — Ruang Kelas Virtual** (Google Meet, link meeting per kelas, jadwal sesi online) | Phase 2 |
| 2 | **LMS — CBT / Ujian Online** (soal PG & essay, kunci otomatis, hasil real-time) | Phase 2 |
| 3 | **LMS — Bank Materi** (upload PDF/video per mata pelajaran) | Phase 2 |
| 4 | **Presensi Digital** (absensi GPS/selfie, rekap bulanan, notifikasi alpa) | Phase 2 |
| 5 | **Konseling & BK** (pelanggaran/prestasi, skor poin, rekam jejak) | Phase 2 |
| 6 | **E-Library** (katalog buku digital, tracking peminjaman) | Phase 2 |
| 7 | **Manajemen Inventaris** (aset sekolah, kondisi, lokasi) | Phase 2 |
| 8 | **Payroll Guru & Staf** (komponen gaji, potongan, slip PDF) | Phase 2 |
| 9 | **Payment Gateway** (Midtrans/Xendit untuk pembayaran SPP online) | Phase 2 |
| 10 | **WhatsApp API otomatis** (Fonnte / Meta Cloud API) | Phase 2 — Phase 1 memakai wa.me manual |
| 11 | **DAPODIK Export** (format kompatibel Kemdikbud) | Phase 2 |

### 14.2 Batasan Teknis Phase 1

| Aspek | Batasan Phase 1 | Kondisi Phase 2 |
|---|---|---|
| Pengiriman WhatsApp | **Manual** — sistem hanya menyediakan link wa.me, admin klik kirim satu per satu | Otomatis via Fonnte / Meta Cloud API |
| Pembayaran SPP | **Pencatatan manual** oleh Bendahara (cash/transfer) | Online via payment gateway |
| File storage | **Local disk VPS** | Backblaze B2 |
| Cache & Queue | **Database driver** | Dapat diupgrade ke Redis |
| Infrastruktur | **Single VPS** (semua komponen dalam satu server) | Skalabilitas vertikal (upgrade RAM/CPU) |
| Backup | **Lokal di VPS** | Upload ke Backblaze B2 |
| Video conference | Tidak ada | Google Meet link generation |

### 14.3 Tidak Dibahas dalam Blueprint

Hal-hal berikut tidak dinyatakan sebagai in-scope maupun out-of-scope:

| Aspek | Status |
|---|---|
| Aplikasi mobile native (Android/iOS) | **Belum dijelaskan dalam blueprint.** (Blueprint hanya menyebut "Bearer token untuk API mobile *future*") |
| Isi Modul 2 dan Modul 4 | **Belum dijelaskan dalam blueprint.** |
| Sumber data kehadiran/absensi untuk Phase 1 | **Belum dijelaskan dalam blueprint.** |
| Struktur tabel `audit_logs` | **Belum dijelaskan dalam blueprint.** |
| Mekanisme approval oleh Kepala Sekolah | **Belum dijelaskan dalam blueprint.** |
| Mekanisme unpublish / koreksi rapor yang sudah diterbitkan | **Belum dijelaskan dalam blueprint.** |
| Rumus dan aturan tie-break `rank_in_class` | **Belum dijelaskan dalam blueprint.** |
| Perilaku generate tagihan untuk frekuensi `YEARLY` dan `ONCE` | **Belum dijelaskan dalam blueprint.** |
| Batas ukuran file dokumen PPDB | **Belum dijelaskan dalam blueprint.** |
| Aturan normalisasi nomor HP ke awalan `62` | **Belum dijelaskan dalam blueprint.** |
| Penanganan penerima notifikasi yang tidak memiliki WhatsApp | **Belum dijelaskan dalam blueprint.** |
| Data induk kepegawaian guru (NIP, mapel keahlian) — guru direpresentasikan sebagai `users` | **Belum dijelaskan dalam blueprint.** |
| Skenario dua wali/orang tua untuk satu siswa | **Belum dijelaskan dalam blueprint.** |
| Rencana migrasi data historis dari spreadsheet | **Belum dijelaskan dalam blueprint.** |
| Rencana UAT, pelatihan pengguna, dan SLA support pasca go-live | **Belum dijelaskan dalam blueprint.** |
| Strategi CI/CD dan rollback deployment | **Belum dijelaskan dalam blueprint.** |
| Strategi high-availability / failover | **Belum dijelaskan dalam blueprint.** |
| Nama library import/export Excel yang dipakai | **Belum dijelaskan dalam blueprint.** |

---

## 15. Future Development

### 15.1 Roadmap Phase 2

**Prasyarat mulai:** Phase 1 stabil dan telah digunakan **minimal 3 bulan**.

| Prioritas | Modul | Fitur Utama | Estimasi Durasi |
|:---:|---|---|---|
| 1 | **LMS — Ruang Kelas Virtual** | Integrasi Google Meet, link meeting per kelas, jadwal sesi online | 4–6 minggu |
| 2 | **LMS — CBT (Ujian Online)** | Buat soal (pilihan ganda, essay), kunci otomatis, hasil real-time | 6–8 minggu |
| 3 | **LMS — Bank Materi** | Upload PDF/video per mata pelajaran, dapat diakses siswa kapan saja | 3–4 minggu |
| 4 | **Presensi Digital** | Absensi via aplikasi (GPS/selfie), rekap per bulan, notifikasi alpa | 4–5 minggu |
| 5 | **Konseling & BK** | Catat pelanggaran/prestasi, skor poin, rekam jejak bimbingan konseling | 3–4 minggu |
| 6 | **E-Library** | Katalog buku digital, tracking peminjaman, notifikasi batas kembali | 4–5 minggu |
| 7 | **Manajemen Inventaris** | Pendataan aset sekolah (komputer, meja, lab), kondisi, lokasi | 3–4 minggu |
| 8 | **Payroll Guru & Staf** | Input komponen gaji, potongan, slip gaji PDF, riwayat pembayaran | 5–6 minggu |
| 9 | **Payment Gateway** | Integrasi Midtrans/Xendit untuk pembayaran SPP online oleh ortu | 4–6 minggu |
| 10 | **WhatsApp API** | Upgrade dari wa.me ke Fonnte / Meta Cloud API untuk pengiriman otomatis | 2–3 minggu |
| 11 | **DAPODIK Export** | Export data siswa dalam format kompatibel Dapodik Kemdikbud | 3–4 minggu |

> Urutan prioritas pada kolom pertama mengikuti urutan penyajian dalam blueprint. Prioritas bisnis definitif untuk Phase 2: **Belum dijelaskan dalam blueprint.**

### 15.2 Rencana Peningkatan Infrastruktur

| Komponen | Kondisi Phase 1 | Rencana Peningkatan | Pemicu |
|---|---|---|---|
| File Storage | Local VPS disk (40 GB SSD) | Backblaze B2 (~Rp 15.000/10 GB/bln) | Disk penuh |
| Cache | Laravel Cache (DB driver) | Redis | Phase 2 |
| Backup | Lokal di VPS | Upload ke Backblaze B2 | Phase 2 |
| Kapasitas server | VPS 2C/2GB | Skalabilitas vertikal (upgrade RAM/CPU) | Sesuai kebutuhan |
| Notifikasi WhatsApp | wa.me manual (gratis) | Fonnte (~Rp 150.000/bln) | Phase 2 |

### 15.3 Arah Pengembangan Jangka Panjang

| Arah | Dasar di Blueprint |
|---|---|
| **Penambahan cabang baru** tanpa pengembangan ulang — arsitektur menampung 10+ cabang | §Target Skala Awal; NFR-03 |
| **Dukungan API untuk aplikasi mobile** — Bearer token sudah disiapkan pada arsitektur autentikasi | §3.4 Arsitektur Keamanan |
| **Penambahan bahasa antarmuka** di luar ID/EN | NFR-11 "dapat diperluas" |

### 15.4 Tata Kelola Perubahan

Blueprint menetapkan: *"Setiap perubahan signifikan pada scope, arsitektur, atau teknologi harus didokumentasikan sebagai **versi baru dari blueprint** ini."*

Konsekuensi bagi PRD ini:
- PRD hanya boleh diubah mengikuti perubahan blueprint, bukan sebaliknya.
- Setiap penambahan requirement yang tidak ada di blueprint harus lebih dahulu masuk ke revisi blueprint (v1.0.1 / v1.1.0 dst.), baru diturunkan ke PRD.

---

## 16. Glossary

### 16.1 Istilah Umum & Domain Sekolah

| Istilah | Definisi | Sumber |
|---|---|---|
| **Tenant** | Satu unit sekolah/cabang yang menggunakan platform (misal: Smart Madani) | Glosarium blueprint |
| **White-label** | Tampilan logo, warna, dan nama aplikasi yang dapat dikustomisasi per cabang | Glosarium blueprint |
| **PPDB** | Penerimaan Peserta Didik Baru — proses pendaftaran siswa baru | Glosarium blueprint |
| **SPP** | Sumbangan Pembinaan Pendidikan — iuran bulanan siswa | Glosarium blueprint |
| **Rapor** | Laporan hasil belajar siswa per semester | Glosarium blueprint |
| **Rombel** | Rombongan Belajar — satuan kelas | ERD `classes` |
| **Tahun Ajaran** | Periode akademik beserta semesternya (contoh: 2024/2025 Semester 1) | ERD `academic_years` |
| **Wali Kelas** | Guru yang bertanggung jawab atas satu rombel; berwenang menerbitkan rapor | §1.1.1 |
| **NIS** | Nomor Induk Siswa — nomor lokal, unik per sekolah | ERD `students.nis` |
| **NISN** | Nomor Induk Siswa Nasional — 10 digit | ERD `students.nisn` |
| **Dapodik** | Data Pokok Pendidikan Kemdikbud — target export pada Phase 2 | §1.3 Phase 2 |

### 16.2 Istilah Teknis & Arsitektur

| Istilah | Definisi | Sumber |
|---|---|---|
| **`school_id`** | Primary key tabel `schools`; digunakan sebagai kunci isolasi data antar cabang | Glosarium blueprint |
| **Multi-tenant** | Arsitektur di mana satu instance aplikasi melayani banyak tenant dengan data terisolasi | §3.2 |
| **Shared Database, Shared Schema** | Pola isolasi di mana semua tenant berbagi satu database dan satu set tabel, dibedakan oleh `school_id` | §3.2.1 |
| **Global Scope** | Mekanisme Laravel yang menambahkan kondisi `WHERE school_id = ...` secara otomatis pada setiap query Eloquent | §3.2.1 |
| **RBAC** | Role-Based Access Control — pengaturan hak akses berbasis peran | §1.1 |
| **Single Domain** | Seluruh cabang diakses melalui satu URL yang sama | §Solusi |
| **Tenant Bootstrapping** | Proses middleware menyimpan `school_id` ke context sesi setelah login | §3.2.2 |
| **CSS Variables** | Variabel CSS (`--color-primary`, `--color-secondary`) yang di-inject dinamis untuk white-label | §3.2.3 |
| **Soft Deactivate / Soft Update** | Menonaktifkan atau memperbarui record tanpa menghapus data dan histori | SIS-02; `DELETE /users/{id}` |
| **Queue Worker** | Proses latar (via Supervisor) yang menangani job berat seperti generate tagihan massal dan PDF rapor | §3.1; §3.3.1 |
| **Audit Log** | Pencatatan seluruh aksi Create/Update/Delete beserta user, tabel, id, timestamp, dan IP | §3.4; NFR-12 |
| **API** | Application Programming Interface — jalur komunikasi antar komponen sistem | Glosarium blueprint |
| **wa.me link** | URL WhatsApp click-to-chat dengan pesan template pre-filled | Glosarium blueprint |
| **DXA** | Unit ukuran dokumen (1440 DXA = 1 inch) pada standar OOXML | Glosarium blueprint |

### 16.3 Istilah Proyek & Dokumen

| Istilah | Definisi | Sumber |
|---|---|---|
| **Phase 1** | MVP: Administrasi & Akademik, Keuangan, Komunikasi & Portal | Glosarium blueprint |
| **Phase 2** | LMS, Presensi Digital, BK, Perpustakaan, Inventaris, Payroll | Glosarium blueprint |
| **MVP** | Minimum Viable Product — cakupan minimum yang dirilis lebih dahulu, setara Phase 1 | §Cakupan Phase 1 |
| **User Story** | Format requirement: "Sebagai [peran], saya dapat [aksi], sehingga [manfaat]" | §1.2 |
| **Acceptance Criteria** | Kondisi minimum agar sebuah fitur dinyatakan selesai | §1.2 |
| **Sprint** | Satuan iterasi pengembangan berdurasi 2 minggu | Lampiran A.1 |
| **Go-Live** | Peluncuran sistem ke lingkungan produksi setelah seluruh checklist Lampiran A.3 terpenuhi | Lampiran A.3 |

### 16.4 Daftar Nilai ENUM (Referensi Cepat)

| Konteks | Nilai yang Diizinkan | Sumber |
|---|---|---|
| Status siswa | `ACTIVE`, `GRADUATED`, `DROPPED_OUT`, `TRANSFERRED` | ERD `students.status` |
| Status siswa di kelas | `ACTIVE`, `MOVED` | ERD `student_classes.status` |
| Status PPDB | `REGISTERED`, `DOCUMENT_REVIEW`, `PASSED`, `FAILED`, `ENROLLED` | ERD `ppdb_registrations.status` |
| Komponen nilai | `DAILY`, `MIDTERM`, `FINAL`, `ASSIGNMENT`, `SKILL`, `ATTITUDE` | ERD `grades.grade_type` |
| Nilai sikap | `A`, `B`, `C`, `D` | ERD `report_cards.attitude_score` |
| Frekuensi tagihan | `MONTHLY`, `YEARLY`, `ONCE` | ERD `fee_types.frequency` |
| Status tagihan | `UNPAID`, `PARTIAL`, `PAID`, `WAIVED` | ERD `student_fees.status` |
| Metode pembayaran | `CASH`, `TRANSFER`, `PAYMENT_GATEWAY` | ERD `payments.payment_method` |
| Jenis transaksi kas | `INCOME`, `EXPENSE` | ERD `transactions.type` |
| Tipe notifikasi | `ANNOUNCEMENT`, `BILLING`, `ACADEMIC`, `EMERGENCY`, `SYSTEM` | ERD `notifications.type` |
| Target notifikasi | `ALL`, `CLASS`, `INDIVIDUAL` | ERD `notifications.target_type` |
| Kategori notifikasi (UI) | `ACADEMIC`, `BILLING`, `EMERGENCY`, `GENERAL` | NOTIF-01 AC-1 |
| Jenis kelamin | `L` (Laki-laki), `P` (Perempuan) | ERD `students.gender` |
| Locale | `id`, `en` | ERD `users.locale` |

> **Catatan:** Kategori notifikasi pada NOTIF-01 (`GENERAL`) dan tipe notifikasi pada ERD (`ANNOUNCEMENT`, `SYSTEM`) tidak identik. Blueprint memuat dua daftar yang berbeda.

---

## 17. Lampiran

### 17.1 Referensi Silang: FR ↔ Entitas Data

| Kelompok FR | Tabel Terkait (dari ERD blueprint) |
|---|---|
| AUTH | `schools`, `users`, `roles`, `model_has_roles` |
| SIS | `students` |
| PPDB | `ppdb_registrations`, `students` |
| KELAS | `academic_years`, `classes`, `student_classes`, `subjects`, `class_subjects`, `schedules` |
| NILAI | `grades`, `report_cards`, `grade_configs` |
| SPP | `fee_types`, `student_fees`, `payments` |
| KAS | `transactions` |
| NOTIF | `notifications`, `notification_reads` |
| PORTAL | Agregasi dari seluruh tabel di atas |

Total entitas: **21 tabel utama** dalam satu database MySQL yang di-share antar tenant (Shared Database, Shared Schema).

### 17.2 Referensi Silang: Section PRD ↔ Bagian Blueprint

| Section PRD | Bagian Blueprint |
|---|---|
| 1 Overview | Ringkasan Eksekutif; Target Skala Awal; Stack Teknologi; §3.1 |
| 2 Product Vision | Ringkasan Eksekutif — Solusi |
| 3 Objectives | Ringkasan Eksekutif — Masalah & Solusi |
| 4 Problem Statement | Ringkasan Eksekutif — Masalah yang Diselesaikan |
| 5 Scope | Cakupan Phase 1; §1.3; Lampiran A.1 |
| 6 Assumptions | Derivasi dari Target Skala Awal, §3.2, §3.3, ERD, Lampiran A |
| 7 Constraints | §3.1, §3.2, §3.3, §3.4; seluruh AC pada §1.2; Lampiran A |
| 8 User Roles | §1.1.1 Daftar Peran; §1.1.2 Matriks Izin; §4.1 |
| 9 Functional Requirements | §1.2.1 – §1.2.9 |
| 10 Acceptance Criteria per Modul | Agregasi §1.2 + §1.4 + Lampiran A.3 |
| 11 Non Functional Requirements | §1.4; §3.4; §4.1 |
| 12 Dependencies Antar Modul | §2.1 ERD; §2.2 relasi FK; Lampiran A.1 |
| 13 Success Metrics | §1.4; Lampiran A.2; Lampiran A.3 |
| 14 Out of Scope | §1.3 Gambaran Umum Phase 2 |
| 15 Future Development | §1.3; §3.1; Lampiran A.2 |
| 16 Glossary | Glosarium Singkat; ERD §2.2 |

### 17.3 Daftar Isu Terbuka yang Perlu Klarifikasi

| # | Isu | Section PRD Terkait |
|---|---|---|
| 1 | Sumber data kehadiran/absensi Phase 1 (dibutuhkan SIS-04, PORTAL-01, `report_cards`) | 9.2, 9.9, 10.2, 10.5, 10.8 |
| 2 | Struktur dan scope tabel `audit_logs` — disyaratkan NFR-12 namun tidak ada di daftar 21 tabel | 11.1 |
| 3 | Kewenangan Guru membuat pengumuman (matriks izin ❌ vs shortcut PORTAL-02) | 8.2, 8.5, 9.9 |
| 4 | Jumlah nilai terbaru pada dashboard ortu (PORTAL-01: 3 vs API Map: 5) | 9.9, 10.8 |
| 5 | Nilai status `INACTIVE` pada `students.status` — tidak ada di ENUM ERD | 9.2, 10.2 |
| 6 | Objek dan alur approval Kepala Sekolah | 8.5 |
| 7 | Presedensi bobot: `grades.weight` vs `grade_configs.components` | 9.5, 10.5 |
| 8 | Mekanisme unpublish / koreksi rapor yang sudah diterbitkan | 9.5, 10.5 |
| 9 | Perilaku generate tagihan untuk frekuensi `YEARLY` dan `ONCE` | 9.6, 10.6 |
| 10 | Mekanisme autentikasi definitif — "JWT + session" (NFR) vs Laravel Sanctum (§3.4) | 9.1, 11.2 |
| 11 | Penjadwalan wa.me generator — dibutuhkan Sprint 3 (PPDB-04), dijadwalkan Sprint 8 | 9.3, 9.8, 12.4 |
| 12 | Format file upload foto siswa — SIS-03 mengizinkan WEBP, §3.4 hanya JPG/PNG/PDF | 7.5 (CON-43) |
| 13 | Rumus dan tie-break `rank_in_class` | 9.5, 10.5 |
| 14 | Aturan normalisasi nomor HP ke awalan `62` untuk link wa.me | 6.1 (ASM-06), 9.8 |

---

## Riwayat Revisi Dokumen

| Versi | Tanggal | Penulis | Keterangan |
|---|---|---|---|
| v1.0 | — | Tim Pengembang | PRD awal, diturunkan sepenuhnya dari `SmartSukses_FullBlueprint_v1.0.0.docx` |
| v1.1 | — | Tim Pengembang | Penambahan section Assumptions, Constraints, Acceptance Criteria per Modul Utama, Dependencies Antar Modul, dan Glossary. Renumbering section. **Tidak ada perubahan pada isi requirement.** |

---

*Dokumen ini disusun sepenuhnya berdasarkan `blueprint/SmartSukses_FullBlueprint_v1.0.0.docx`. Tidak ada requirement yang ditambahkan, dikurangi, atau diubah. Seluruh informasi yang tidak tercantum dalam blueprint ditandai secara eksplisit sebagai "Belum dijelaskan dalam blueprint."*

**Smart Sukses School · Product Requirements Document v1.1 · KONFIDENSIAL**
