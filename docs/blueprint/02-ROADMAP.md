# Smart Sukses School Development Roadmap

---

| Parameter | Detail |
|---|---|
| **Nama Produk** | Smart Sukses School |
| **Platform** | `apps.smartsukses.sch.id` |
| **Versi Roadmap** | v1.1 |
| **Sumber Utama** | `blueprint/SmartSukses_FullBlueprint_v1.0.0.docx` (v1.0.0 · Agustus 2025) dan `docs/01-PRD.md` (v1.1) |
| **Cakupan** | Phase 1 (MVP) — 18 minggu · Roadmap Phase 2 disajikan sebagai gambaran |
| **Sifat Dokumen** | Turunan blueprint & PRD. Tidak menambah, mengurangi, atau mengubah requirement. |
| **Kerahasiaan** | KONFIDENSIAL — hanya untuk penggunaan internal |

> **Konvensi dokumen:** Setiap informasi yang tidak tercantum dalam blueprint ditulis sebagai **"Belum dijelaskan dalam blueprint."**

> **Catatan penting mengenai struktur:** Blueprint menetapkan rencana implementasi dalam bentuk **9 sprint × 2 minggu = 18 minggu** (Lampiran A.1). Roadmap ini menyajikan pekerjaan yang sama dalam **11 fase (Phase 0–10)** sebagai pengelompokan berbasis kapabilitas, agar lebih mudah dilacak. **Fase adalah cara mengelompokkan; sprint adalah jadwal yang mengikat.** Total durasi tetap 18 minggu sesuai CON-51 — tidak ada penambahan scope maupun waktu. Pemetaan Fase ↔ Sprint tersedia pada [§3.1](#31-pemetaan-fase--sprint-blueprint).

---

## Daftar Isi

| No | Section |
|---|---|
| 1 | [Tujuan Roadmap](#1-tujuan-roadmap) |
| 2 | [Development Strategy](#2-development-strategy) |
| 3 | [Development Phases](#3-development-phases) |
| 4 | [Sprint Planning](#4-sprint-planning) |
| 5 | [Module Dependency](#5-module-dependency) |
| 6 | [Risks](#6-risks) |
| 7 | [Mitigation](#7-mitigation) |
| 8 | [Milestone](#8-milestone) |
| 9 | [Definition of Done](#9-definition-of-done) |
| 10 | [Timeline Visual (Mingguan)](#10-timeline-visual-mingguan) |
| 11 | [Sprint Deliverables Register](#11-sprint-deliverables-register) |
| 12 | [Prioritas Modul](#12-prioritas-modul) |
| 13 | [Risk Matrix (Impact vs Probability)](#13-risk-matrix-impact-vs-probability) |
| 14 | [Lampiran](#14-lampiran) |

---

## 1. Tujuan Roadmap

### 1.1 Maksud Dokumen

Roadmap ini menerjemahkan requirement pada blueprint dan PRD menjadi **rencana pengerjaan yang berurutan, terukur, dan dapat dilacak**, sehingga tim pengembang mengetahui secara pasti: apa yang dikerjakan, kapan, dalam urutan apa, dan kapan sesuatu dinyatakan selesai.

### 1.2 Tujuan Spesifik

| ID | Tujuan | Penjelasan |
|---|---|---|
| **TR-01** | **Menetapkan urutan pengerjaan yang aman secara teknis** | Urutan mengikuti peta dependency modul ([§5](#5-module-dependency)) sehingga tidak ada modul dikerjakan sebelum prasyaratnya siap |
| **TR-02** | **Memecah 38 functional requirement menjadi unit kerja per fase dan per sprint** | Setiap FR pada PRD §9 memiliki fase dan sprint yang jelas |
| **TR-03** | **Menjaga total durasi tetap 18 minggu** sesuai batasan blueprint | CON-51: 9 sprint × 2 minggu, 1 developer full-stack Laravel |
| **TR-04** | **Menempatkan fondasi multi-tenant sebagai prioritas mutlak** | Global Scope `school_id` harus benar sejak awal; menyusul belakangan berisiko melanggar CON-26 (toleransi kebocoran data nol) |
| **TR-05** | **Menyediakan kriteria selesai yang tidak ambigu** pada tingkat user story, modul, sprint, fase, dan rilis | Lihat [§9 Definition of Done](#9-definition-of-done) |
| **TR-06** | **Memetakan risiko pengembangan beserta mitigasinya sejak awal** | Terutama risiko isolasi tenant, kapasitas VPS, dan gap requirement yang belum dijelaskan blueprint |
| **TR-07** | **Menetapkan milestone yang dapat diverifikasi pemangku kepentingan** | Setiap milestone punya exit criteria terukur |
| **TR-08** | **Memastikan go-live hanya terjadi setelah 10 butir checklist Lampiran A.3 terpenuhi** | CON-56 |

### 1.3 Batasan Roadmap

| Batasan | Keterangan |
|---|---|
| Roadmap **tidak menambah fitur** | Seluruh isi berasal dari blueprint dan PRD |
| Roadmap **tidak mengubah estimasi blueprint** | 18 minggu, 9 sprint, 1 developer (CON-51) |
| Roadmap **tidak mengubah urutan sprint blueprint** | Urutan Sprint 1–9 pada Lampiran A.1 dipertahankan apa adanya |
| Perubahan scope **wajib** melalui revisi blueprint | CON-55 |

### 1.4 Pengguna Dokumen Ini

| Peran | Cara memakai roadmap ini |
|---|---|
| Developer | Menentukan urutan kerja harian dan prasyarat tiap modul |
| Pemilik produk / Pusat | Memantau milestone dan menyetujui exit criteria tiap fase |
| QA | Menyusun rencana pengujian dari Definition of Done |
| Pemilik blueprint | Menindaklanjuti isu terbuka yang menghambat fase tertentu |

---

## 2. Development Strategy

Pendekatan pengembangan mengikuti siklus **Analisis → Design → Development → Testing → Deployment → Maintenance**. Karena blueprint menetapkan kerja dalam sprint 2 mingguan, siklus ini berjalan **iteratif per sprint**, bukan sekali jalan (waterfall murni).

```
   ┌──────────┐   ┌────────┐   ┌─────────────┐   ┌─────────┐   ┌────────────┐   ┌─────────────┐
   │ ANALISIS │──▶│ DESIGN │──▶│ DEVELOPMENT │──▶│ TESTING │──▶│ DEPLOYMENT │──▶│ MAINTENANCE │
   └──────────┘   └────────┘   └─────────────┘   └─────────┘   └────────────┘   └─────────────┘
        │              │              ▲               │               │                │
        │              │              └───────────────┘               │                │
        │              │            iterasi per sprint (2 minggu)     │                │
        └──────────────┴──────────────────────────────────────────────┴────────────────┘
                          umpan balik → revisi blueprint (CON-55)
```

### 2.1 Analisis

| Aspek | Ketentuan |
|---|---|
| **Sumber kebenaran tunggal** | `SmartSukses_FullBlueprint_v1.0.0.docx`. Tidak ada requirement dari sumber lain |
| **Keluaran** | `docs/01-Analisis-Blueprint.md` dan `docs/01-PRD.md` (sudah selesai) |
| **Aktivitas** | Ekstraksi 38 FR + 12 NFR, penyusunan matriks izin 8 role, pemetaan 21 entitas, identifikasi dependency antar modul |
| **Kapan** | Sebelum Sprint 1 dimulai, dan berulang di awal setiap fase untuk memvalidasi ulang AC modul |
| **Isu terbuka** | 14 butir tercatat pada PRD §17.3. Butir yang memblokir sebuah fase **wajib** diklarifikasi sebelum fase tersebut dimulai |
| **Aturan** | Setiap kekosongan requirement ditulis **"Belum dijelaskan dalam blueprint"** — tidak boleh diisi asumsi developer tanpa persetujuan pemilik blueprint |

### 2.2 Design

| Aspek | Ketentuan |
|---|---|
| **Desain data** | Mengikuti 21 tabel ERD blueprint §2.2 apa adanya. Kolom `school_id` wajib pada semua tabel bisnis (CON-14) |
| **Desain API** | Mengikuti API Endpoint Map blueprint Bagian 4, termasuk konvensi response, pagination, dan auth level (PRD §11.4) |
| **Desain arsitektur** | Shared Database–Shared Schema; Global Scope Laravel; single domain (CON-12, CON-13, CON-15) |
| **Desain UI** | Filament 3 untuk admin panel; Livewire 3 + Alpine.js + Tailwind 3 untuk portal (CON-02, CON-03) |
| **White-label** | CSS variables `--color-primary` / `--color-secondary` di-inject dinamis; seluruh komponen wajib memakai variabel tersebut (CON-18) |
| **Desain keamanan** | Policy per model (`StudentPolicy`, `GradePolicy`, dst.), Form Request untuk validasi, rate limiting (PRD §11.2) |
| **Lokalisasi** | Seluruh teks ditulis sebagai **translation key sejak Sprint 1**, meskipun terjemahan EN baru diselesaikan pada Sprint 9 — mengurangi kerja retrofit (PRD §12.4) |
| **Keluaran** | Dokumen ERD, data dictionary, spesifikasi API, diagram alur bisnis (lihat daftar dokumentasi pada `docs/01-Analisis-Blueprint.md`) |

### 2.3 Development

| Aspek | Ketentuan |
|---|---|
| **Stack** | Laravel 11 (PHP 8.3), Filament 3, Livewire 3, Alpine.js, Tailwind 3, MySQL 8 (CON-01…CON-06) |
| **Urutan kerja** | Mengikuti peta dependency ([§5](#5-module-dependency)) — fondasi dahulu, portal terakhir |
| **Prinsip tenant-first** | Setiap model baru **wajib** langsung memiliki Global Scope `school_id` beserta unit test isolasinya — bukan ditambahkan belakangan |
| **Larangan** | Raw SQL kecuali `DB::select()` dengan binding (CON-10); hard delete pada data siswa, user, dan jenis tagihan (CON-45) |
| **Job berat** | Generate tagihan massal dan generate PDF rapor dijalankan melalui Laravel Queue (database driver) dengan Supervisor |
| **Import/Export** | Excel dipakai pada SIS-05, SPP-05, PORTAL-04, NILAI-01, `/finance/export`. Nama library: **Belum dijelaskan dalam blueprint.** |
| **Kapasitas tim** | 1 developer full-stack Laravel; paralelisasi 2 developer hanya diizinkan pada Sprint 3–4 dan Sprint 5–6 (CON-52) |

### 2.4 Testing

Pengujian berjalan **di dalam setiap sprint** (bukan hanya di Sprint 9). Sprint 9 adalah pengujian menyeluruh dan perbaikan akhir, bukan satu-satunya kesempatan menguji.

| Level | Isi | Kapan |
|---|---|---|
| **Unit test — tenant isolation** | Verifikasi Global Scope pada setiap model. **Target lulus 100%** | Setiap sprint, sejak Sprint 1 |
| **Unit test — aturan bisnis** | 1 siswa = 1 kelas/TA (CON-35), 1 guru = 1 wali kelas/TA (CON-36), 1 TA aktif (CON-37), NIS unik per sekolah (CON-38), deteksi konflik jadwal (CON-48), penguncian rapor (CON-40) | Pada sprint modul terkait |
| **Uji akses lintas-tenant** | User cabang Madani diverifikasi tidak dapat melihat data cabang Cinangka | Sprint 1 dan diulang pada Sprint 9 |
| **Uji perhitungan** | Nilai akhir berbobot dengan pembulatan 2 desimal; akumulasi `amount_paid` dan transisi status tagihan | Sprint 4 dan Sprint 5 |
| **Uji encoding wa.me** | Seluruh template teks ter-encode benar pada URL `wa.me/62[nomorHP]?text=` | Sprint 3 (PPDB) dan Sprint 8 |
| **Uji PDF rapor** | Format dan data sesuai | Sprint 4, diulang Sprint 9 |
| **Load test** | 200 user konkuren mengakses dashboard tanpa error/timeout pada VPS 2C/2GB | Sprint 9 |
| **Security audit** | Rate limiting, CSRF, HTTPS/HSTS, validasi upload, password policy, CORS | Sprint 9 |
| **Uji responsive** | Parent portal dan seluruh portal pada tampilan mobile | Sprint 9 |
| **Uji restore backup** | Backup harian dapat dipulihkan | Sprint 9 / pra go-live |

> **Belum dijelaskan dalam blueprint:** rencana UAT (User Acceptance Test) formal bersama pengguna sekolah, target code coverage, dan strategi automated regression test selain unit test Global Scope.

### 2.5 Deployment

| Aspek | Ketentuan |
|---|---|
| **Topologi** | Single VPS: Nginx (80/443) + PHP-FPM 8.3 (9000) + MySQL 8 (localhost only) + Queue worker (Supervisor) + Certbot (cron harian) + Cloudflare DNS (CON-20…CON-22) |
| **Spesifikasi** | 2 Core, 2 GB RAM, 40 GB SSD, Ubuntu 22.04 LTS (CON-21) |
| **Domain** | Satu domain `apps.smartsukses.sch.id` untuk seluruh tenant (CON-12) |
| **SSL** | Let's Encrypt via Certbot, auto-renew 90 hari; redirect HTTP→HTTPS; HSTS aktif (CON-24) |
| **CORS** | Hanya menerima dari `apps.smartsukses.sch.id` (CON-23) |
| **Backup** | `mysqldump` harian pukul 02:00 WIB, retensi 30 hari (CON-25) |
| **Monitoring** | Uptime monitoring aktif (UptimeRobot free tier atau Better Stack) |
| **Gerbang go-live** | Seluruh **10 butir checklist Lampiran A.3** wajib terpenuhi (CON-56) |
| **Belum dijelaskan dalam blueprint** | Strategi CI/CD, prosedur rollback, dan keberadaan environment staging terpisah |

### 2.6 Maintenance

| Aspek | Ketentuan |
|---|---|
| **Target ketersediaan** | Uptime 99% per bulan (~7 jam downtime/bulan) — NFR-08 |
| **Backup rutin** | Harian otomatis, retensi 30 hari; Phase 1 tersimpan lokal, Phase 2 diunggah ke Backblaze B2 |
| **Perpanjangan SSL** | Otomatis via Certbot setiap 90 hari |
| **Pemantauan kapasitas** | Disk 40 GB (foto siswa, bukti bayar, dokumen PPDB, scan nota) → pemicu migrasi ke Backblaze B2 |
| **Masa stabilisasi** | Phase 1 harus berjalan stabil **minimal 3 bulan** sebelum Phase 2 dimulai (CON-54, SM-13) |
| **Tata kelola perubahan** | Setiap perubahan signifikan pada scope/arsitektur/teknologi wajib diterbitkan sebagai versi blueprint baru (CON-55) |
| **Belum dijelaskan dalam blueprint** | SLA support pasca go-live, prosedur incident response, dan siklus rilis patch |

---

## 3. Development Phases

### 3.1 Pemetaan Fase ↔ Sprint Blueprint

| Fase | Nama Fase | Sprint Blueprint | Minggu | Durasi |
|---|---|---|---|---|
| **Phase 0** | Project Initialization | Sprint 1 (paruh pertama) | 1 | ~1 minggu |
| **Phase 1** | Authentication & RBAC | Sprint 1 (paruh kedua) | 2 | ~1 minggu |
| **Phase 2** | Master Data | Sprint 2 | 3–4 | 2 minggu |
| **Phase 3** | PPDB | Sprint 3 | 5–6 | 2 minggu |
| **Phase 4** | Academic Module | Sprint 4 | 7–8 | 2 minggu |
| **Phase 5** | Finance Module | Sprint 5 + Sprint 6 (paruh pertama) | 9–11 | 3 minggu |
| **Phase 8** | Reporting | Sprint 6 (paruh kedua) | 12 | 1 minggu |
| **Phase 6** | Parent, Student & Teacher Portal | Sprint 7 | 13–14 | 2 minggu |
| **Phase 7** | Notification | Sprint 8 | 15–16 | 2 minggu |
| **Phase 9** | Testing | Sprint 9 | 17–18 | 2 minggu |
| **Phase 10** | Deployment | Setup: Sprint 1 · Go-live: setelah Sprint 9 | 1 & 18 | Tercakup |
| | | | **TOTAL** | **18 minggu** |

**Catatan penting atas pemetaan ini:**

1. **Phase 0 dan Phase 1 keduanya berada di Sprint 1.** Blueprint mengalokasikan Sprint 1 sebagai satu unit 2 minggu; pembagian 1 + 1 minggu adalah pembagian kerja internal roadmap, bukan ketentuan blueprint.
2. **Phase 8 (Reporting) dieksekusi lebih awal daripada Phase 6 dan Phase 7.** Blueprint menempatkan pelaporan keuangan di Sprint 6, sebelum Portal (Sprint 7) dan Notifikasi (Sprint 8). Roadmap ini **mempertahankan urutan blueprint** — penomoran fase mengikuti permintaan struktur dokumen, tetapi **urutan eksekusi mengikuti sprint**.
3. **Blueprint tidak memiliki sprint khusus bernama "Reporting".** Fitur pelaporan tersebar di beberapa sprint. Phase 8 mengelompokkan ulang fitur tersebut untuk keperluan verifikasi menyeluruh; sebagian di antaranya (export siswa, PDF rapor, export tagihan) sudah dikerjakan pada fase sebelumnya dan hanya diverifikasi ulang di Phase 8.
4. **Phase 10 tidak memiliki alokasi durasi terpisah.** Setup infrastruktur berada di Sprint 1, dan aktivitas go-live dilakukan di ujung Sprint 9. Durasi khusus untuk aktivitas go-live: **Belum dijelaskan dalam blueprint.**

---

### 3.2 Phase 0 — Project Initialization

**Sprint:** 1 (paruh pertama) · **Minggu:** 1 · **Estimasi:** ~1 minggu

#### Objective
Menyiapkan fondasi teknis, infrastruktur, dan kerangka multi-tenant sehingga seluruh pekerjaan berikutnya berjalan di atas arsitektur yang benar sejak awal.

#### Features / Aktivitas

| Aktivitas | Dasar |
|---|---|
| Provisioning VPS 2C/2GB/40GB, Ubuntu 22.04 LTS | Sprint 1 blueprint; CON-21 |
| Instalasi Nginx + PHP-FPM 8.3 + MySQL 8 (localhost only) | §3.3.1; CON-20, CON-22 |
| Instalasi Laravel 11 dan Filament PHP 3 | Sprint 1; CON-01, CON-02 |
| Instalasi Livewire 3, Alpine.js, Tailwind CSS 3 | CON-03 |
| Instalasi `spatie/laravel-multitenancy`, `spatie/laravel-permission`, Laravel Sanctum | CON-05, CON-06 |
| Konfigurasi Cache & Queue dengan database driver + Supervisor | CON-07 |
| Konfigurasi DNS Cloudflare + SSL Let's Encrypt (Certbot) | §3.3.1 |
| Struktur migrasi tabel inti: `schools`, `users`, `roles`, `model_has_roles` | ERD §2.1 |
| Implementasi **Global Scope `school_id`** dan mekanisme bypass Super Admin | CON-15, CON-16 |
| Kerangka lokalisasi (translation key ID/EN) | AUTH-05; PRD §2.2 |
| Konfigurasi backup `mysqldump` harian 02:00 WIB | CON-25 |

#### Deliverables
- Aplikasi Laravel + Filament berjalan di `apps.smartsukses.sch.id` dengan HTTPS aktif.
- Tabel `schools`, `users`, `roles`, `model_has_roles` tersedia.
- Global Scope `school_id` terpasang beserta **unit test isolasi tenant pertama**.
- Queue worker berjalan melalui Supervisor.
- Backup harian terjadwal.
- Struktur translation key ID/EN siap dipakai.

#### Dependencies
Tidak ada. **Phase 0 adalah prasyarat mutlak seluruh fase lainnya.**

#### Exit Criteria
- Halaman aplikasi dapat diakses via HTTPS.
- Global Scope aktif dan unit test isolasi lulus.
- Data seed 3 cabang (Pusat, Madani, Cinangka) dapat dibuat.

---

### 3.3 Phase 1 — Authentication & RBAC

**Sprint:** 1 (paruh kedua) · **Minggu:** 2 · **Estimasi:** ~1 minggu

#### Objective
Memastikan setiap pengguna dapat masuk dengan aman, otomatis terikat pada cabangnya, melihat tampilan white-label cabang tersebut, dan hanya dapat mengakses modul sesuai perannya.

#### Features

| ID | Fitur | Sumber |
|---|---|---|
| AUTH-01 | Login email + password; token kedaluwarsa 8 jam tidak aktif | PRD §9.1 |
| AUTH-02 | Deteksi `school_id` otomatis & isolasi data seluruh query | PRD §9.1 |
| AUTH-03 | White-label UI per cabang (logo + warna, tanpa deployment ulang) | PRD §9.1 |
| AUTH-04 | Reset password via email (link 60 menit, sesi ter-invalidate) | PRD §9.1 |
| PORTAL-04 | Manajemen akun pengguna: buat, edit, nonaktifkan, reset password, import Excel massal | PRD §9.9 |
| — | Implementasi 8 role + matriks izin 15 modul | PRD §8.1, §8.2 |
| — | Policy per model + Gate | PRD §11.2 |
| — | Audit log untuk seluruh aksi Create/Update/Delete | CON-33; NFR-12 |
| — | Manajemen tenant oleh Super Admin (`/admin/schools`) | Blueprint §4.3 |

> AUTH-05 (toggle bahasa) diselesaikan pada Phase 9 / Sprint 9 sesuai blueprint, namun **kerangka translation key** sudah disiapkan sejak Phase 0.

#### Deliverables
- Halaman login berfungsi dengan pesan error yang tidak membocorkan detail sistem.
- Middleware tenant bootstrapping aktif (alur 7 langkah blueprint §3.2.2).
- CSS variables white-label ter-inject dinamis dari tabel `schools`.
- 8 role terdaftar di `spatie/permission` dengan permission sesuai matriks izin.
- CRUD user + import Excel massal + reset password (password sementara).
- CRUD cabang oleh Super Admin (`POST/PUT/PATCH /admin/schools`).
- Tabel dan mekanisme audit log berjalan.
- **Unit test tenant isolation lulus 100%** dan uji manual lintas-tenant tidak menemukan pelanggaran.

#### Dependencies
Phase 0 (Laravel, Filament, tabel inti, Global Scope).

#### Exit Criteria
- Login dengan akun dari 3 cabang berbeda menghasilkan tampilan white-label yang berbeda.
- User cabang Madani terbukti tidak dapat mengakses data cabang Cinangka.
- Super Admin (`school_id = NULL`) dapat melihat data lintas cabang.
- Setiap role hanya melihat menu sesuai matriks izin.

> **Isu terbuka yang memengaruhi fase ini:** mekanisme autentikasi definitif — NFR menyebut "JWT + session", §3.4 menyebut Laravel Sanctum (PRD §17.3 #10). Perlu diputuskan sebelum implementasi dimulai.

---

### 3.4 Phase 2 — Master Data

**Sprint:** 2 · **Minggu:** 3–4 · **Estimasi:** 2 minggu

#### Objective
Menyediakan seluruh data induk akademik — siswa, tahun ajaran, kelas, mata pelajaran, pengampu, dan jadwal — sebagai fondasi bagi modul PPDB, Penilaian, Keuangan, dan Portal.

#### Features

| ID | Fitur | Sumber |
|---|---|---|
| SIS-01 | Tambah data siswa manual (validasi NISN 10 digit, NIS unik per sekolah) | PRD §9.2 |
| SIS-02 | Edit & nonaktifkan siswa (soft update, tidak dihapus) | PRD §9.2 |
| SIS-03 | Upload foto siswa (JPG/PNG/WEBP, maks 2 MB, resize 400×400) | PRD §9.2 |
| SIS-04 | Guru melihat daftar siswa kelas ajar | PRD §9.2 |
| SIS-05 | Export data siswa ke Excel | PRD §9.2 |
| — | Import siswa & user massal dari Excel | `POST /students/import`, `POST /users/import` |
| — | Manajemen Tahun Ajaran + aktivasi (hanya satu aktif per sekolah) | `/academic-years`; CON-37 |
| — | Manajemen Mata Pelajaran per cabang | `/subjects` |
| KELAS-01 | Buat kelas + tentukan wali kelas (1 guru = 1 kelas/TA) | PRD §9.4 |
| KELAS-02 | Tambah siswa ke kelas (1 siswa = 1 kelas/TA) | PRD §9.4 |
| KELAS-03 | Buat jadwal pelajaran + **deteksi konflik** guru/ruang/kelas | PRD §9.4 |
| KELAS-04 | Guru melihat jadwal mengajar mingguan | PRD §9.4 |

#### Deliverables
- CRUD siswa lengkap dengan validasi, foto, dan export/import Excel.
- CRUD tahun ajaran dengan mekanisme aktivasi tunggal.
- CRUD mata pelajaran, kelas, dan penetapan wali kelas.
- Penempatan siswa ke kelas dengan validasi satu kelas per tahun ajaran.
- Penetapan `class_subjects` (mapel + guru pengampu per kelas).
- Modul jadwal dengan deteksi konflik yang terbukti melalui unit test.
- Tampilan jadwal mingguan untuk guru.

#### Dependencies
Phase 1 (autentikasi, RBAC, Global Scope, akun guru).

#### Exit Criteria
- Seluruh AC modul pada PRD §10.2 (SIS) dan §10.4 (Kelas & Jadwal) terpenuhi.
- Unit test aturan bisnis lulus: CON-35, CON-36, CON-37, CON-38, CON-48.

> **Isu terbuka yang memengaruhi fase ini:**
> - SIS-04 memerlukan "status kehadiran hari ini" — sumber datanya **Belum dijelaskan dalam blueprint** (PRD §17.3 #1). Modul tidak dapat dinyatakan 100% selesai sebelum diklarifikasi.
> - Nilai status `INACTIVE` pada SIS-02 tidak ada di ENUM `students.status` (PRD §17.3 #5).
> - Format WEBP pada SIS-03 bertentangan dengan §3.4 blueprint (PRD §17.3 #12).

---

### 3.5 Phase 3 — PPDB

**Sprint:** 3 · **Minggu:** 5–6 · **Estimasi:** 2 minggu

#### Objective
Mendigitalkan alur penerimaan siswa baru dari formulir publik hingga konversi menjadi siswa aktif, termasuk komunikasi status kepada pendaftar.

#### Features

| ID | Fitur | Sumber |
|---|---|---|
| PPDB-01 | Formulir pendaftaran publik `/ppdb/[kode_sekolah]` tanpa login | PRD §9.3 |
| PPDB-02 | Cek status pendaftaran publik (no. daftar + tanggal lahir) | PRD §9.3 |
| PPDB-03 | Kelola pendaftar: tabel, filter status, ubah status + catatan alasan | PRD §9.3 |
| PPDB-04 | Generate link wa.me notifikasi per perubahan status | PRD §9.3 |
| PPDB-05 | Enroll pendaftar LULUS menjadi siswa aktif dalam satu klik | PRD §9.3 |
| — | Landing publik daftar cabang yang membuka PPDB | `GET /ppdb/schools` |
| — | Info PPDB per cabang (syarat, jadwal, kuota) | `GET /ppdb/{schoolCode}/info` |
| — | Upload dokumen pendaftaran | ERD `ppdb_registrations.documents` |

#### Deliverables
- Halaman PPDB publik yang dapat diakses tanpa login dan tetap ter-scope ke cabang yang benar.
- Generator nomor pendaftaran berformat `[KODE_CABANG]-[TAHUN]-[SEQ]`.
- Halaman cek status publik.
- Panel admin PPDB dengan filter status dan pencatatan alasan perubahan.
- **Generator link wa.me** (komponen ini juga dipakai ulang pada Phase 7).
- Fitur enroll satu klik yang mengisi `converted_student_id` dan membuat record siswa.

#### Dependencies
- Phase 1 (autentikasi & RBAC untuk panel admin).
- Phase 2 (tabel `students` sebagai target enroll; tahun ajaran aktif).

#### Exit Criteria
- Seluruh AC modul pada PRD §10.3 terpenuhi.
- Alur lima status terverifikasi: `REGISTERED` → `DOCUMENT_REVIEW` → `PASSED`/`FAILED` → `ENROLLED`.
- Uji encoding wa.me: seluruh template teks ter-encode benar.

> **Catatan dependency lintas-sprint:** PPDB-04 membutuhkan generator wa.me, sementara modul Notifikasi dijadwalkan Sprint 8. Roadmap ini membangun generator wa.me sebagai **komponen bersama pada Phase 3**, lalu dipakai ulang pada Phase 7. Cara penanganan resmi menurut blueprint: **Belum dijelaskan dalam blueprint** (PRD §17.3 #11).

---

### 3.6 Phase 4 — Academic Module

**Sprint:** 4 · **Minggu:** 7–8 · **Estimasi:** 2 minggu

#### Objective
Menyediakan siklus penilaian lengkap: konfigurasi bobot, input nilai oleh guru, perhitungan otomatis nilai akhir, hingga penerbitan rapor final dalam bentuk PDF.

#### Features

| ID | Fitur | Sumber |
|---|---|---|
| NILAI-05 | Konfigurasi komponen & bobot penilaian per mata pelajaran | PRD §9.5 |
| NILAI-01 | Input nilai per komponen (satuan / bulk / import Excel), skala 0–100 | PRD §9.5 |
| NILAI-02 | Perhitungan otomatis nilai akhir berbobot, pembulatan 2 desimal | PRD §9.5 |
| NILAI-03 | Generate & publish rapor oleh Wali Kelas (nilai terkunci setelah publish) | PRD §9.5 |
| NILAI-04 | Siswa & orang tua melihat nilai real-time dan rapor final; cetak PDF | PRD §9.5 |

#### Deliverables
- CRUD `grade_configs` dengan bobot berbeda per mata pelajaran.
- Form input nilai satuan, input massal per `class_subject`, dan import Excel.
- Pembatasan akses: guru hanya dapat menginput nilai untuk kelas yang diampunya.
- Engine perhitungan nilai akhir dengan pembulatan 2 desimal.
- Validasi pra-publish: seluruh mata pelajaran wajib memiliki nilai akhir.
- Mekanisme penguncian nilai setelah publish (`is_published`, `published_at`, `published_by`).
- Generator rapor PDF (DomPDF / Browsershot) melalui queue.

#### Dependencies
- Phase 2 (siswa, kelas, mata pelajaran, `class_subjects`, tahun ajaran).
- Phase 1 (role Guru & Wali Kelas beserta policy-nya).

#### Exit Criteria
- Seluruh AC modul pada PRD §10.5 terpenuhi.
- Uji PDF rapor: format dan data sesuai (Lampiran A.3 #5).
- Nilai terbukti tidak dapat diedit setelah rapor di-publish.

> **Isu terbuka yang memengaruhi fase ini:**
> - Rekap kehadiran pada rapor (`attend_present/sick/permission/absent`) — sumber data **Belum dijelaskan dalam blueprint** (PRD §17.3 #1).
> - Rumus dan tie-break `rank_in_class` — **Belum dijelaskan dalam blueprint** (PRD §17.3 #13).
> - Presedensi bobot `grades.weight` vs `grade_configs.components` (PRD §17.3 #7).
> - Mekanisme unpublish / koreksi rapor (PRD §17.3 #8).

---

### 3.7 Phase 5 — Finance Module

**Sprint:** 5 + Sprint 6 (paruh pertama) · **Minggu:** 9–11 · **Estimasi:** 3 minggu

#### Objective
Mendigitalkan penerbitan tagihan SPP, pencatatan pembayaran, dan pembukuan kas sekolah, sehingga arus kas setiap cabang tercatat dan dapat dilacak.

#### Features

| ID | Fitur | Sprint | Sumber |
|---|---|---|---|
| SPP-01 | Buat jenis tagihan (nama, nominal, frekuensi `MONTHLY`/`YEARLY`/`ONCE`) | 5 | PRD §9.6 |
| SPP-02 | Generate tagihan massal untuk seluruh siswa aktif + **preview wajib** | 5 | PRD §9.6 |
| SPP-03 | Catat pembayaran manual (cash/transfer) + upload bukti maks 5 MB | 5 | PRD §9.6 |
| SPP-04 | Orang tua melihat tagihan & riwayat pembayaran anak | 5 | PRD §9.6 |
| — | Pembebasan tagihan (`WAIVED`) dengan alasan | 5 | `PATCH /student-fees/{id}/waive` |
| KAS-01 | Catat pemasukan & pengeluaran kas + lampiran bukti | 6 | PRD §9.7 |

#### Deliverables
- CRUD `fee_types` dengan mekanisme nonaktif tanpa hapus histori.
- Job generate tagihan massal via queue, dengan preview sebelum konfirmasi.
- Form pencatatan pembayaran + upload bukti + akumulasi `amount_paid`.
- Transisi status tagihan otomatis: `UNPAID` → `PARTIAL` → `PAID`, serta `WAIVED`.
- Dukungan cicilan (satu tagihan, banyak pembayaran).
- Buku kas umum (`transactions`) dengan kategori dan lampiran bukti.
- Data tagihan siap dikonsumsi Parent Portal pada Phase 6.

#### Dependencies
- Phase 2 (daftar siswa aktif sebagai target tagihan; kelas untuk filter laporan).
- Phase 1 (role Bendahara beserta policy-nya).

#### Exit Criteria
- AC modul PRD §10.6 butir AC-KEU-01 hingga AC-KEU-14 terpenuhi.
- Generate tagihan massal untuk seluruh siswa aktif berjalan tanpa timeout.
- Akumulasi pembayaran dan transisi status terverifikasi melalui unit test.

> **Isu terbuka:** perilaku generate tagihan untuk frekuensi `YEARLY` dan `ONCE` **Belum dijelaskan dalam blueprint** (PRD §17.3 #9).

---

### 3.8 Phase 8 — Reporting

**Sprint:** 6 (paruh kedua) · **Minggu:** 12 · **Estimasi:** 1 minggu
**Urutan eksekusi:** dikerjakan **sebelum** Phase 6 dan Phase 7, mengikuti urutan sprint blueprint.

#### Objective
Menyediakan seluruh keluaran pelaporan dan dashboard yang dibutuhkan Bendahara, Kepala Sekolah, dan Super Admin, serta memverifikasi konsistensi seluruh fitur export yang tersebar di fase sebelumnya.

> **Catatan:** Blueprint tidak memiliki sprint khusus bernama "Reporting". Fase ini mengelompokkan fitur pelaporan yang tersebar pada Sprint 2, 4, 5, dan 6. Fitur yang sudah selesai lebih dahulu (SIS-05, PDF rapor, SPP-05) hanya **diverifikasi ulang** di sini, bukan dikerjakan ulang.

#### Features

| ID | Fitur | Status | Sumber |
|---|---|---|---|
| KAS-02 | Ringkasan keuangan bulanan: saldo kas, penerimaan SPP, pengeluaran + **grafik tren 6 bulan** | Baru di fase ini | PRD §9.7 |
| KAS-03 | Dashboard keuangan seluruh cabang: total tagihan, terkumpul, **% lunas**, filter TA/bulan | Baru di fase ini | PRD §9.7 |
| SPP-05 | Export laporan tagihan ke Excel (filter kelas/periode/status) | Verifikasi ulang (Phase 5) | PRD §9.6 |
| — | Export laporan keuangan ke Excel | Baru di fase ini | `GET /finance/export` |
| — | Ringkasan keuangan & laporan SPP per periode | Baru di fase ini | `/finance/summary`, `/finance/spp-report` |
| SIS-05 | Export data siswa ke Excel | Verifikasi ulang (Phase 2) | PRD §9.2 |
| NILAI-04 | Rapor PDF | Verifikasi ulang (Phase 4) | PRD §9.5 |
| — | Statistik per cabang untuk Super Admin | Baru di fase ini | `/admin/schools/{id}/stats`, `/admin/dashboard` |

#### Deliverables
- Dashboard keuangan cabang (Kepala Sekolah / Admin / Bendahara) dengan grafik tren 6 bulan.
- Dashboard lintas cabang untuk Super Admin dengan persentase pelunasan.
- Seluruh export Excel berfungsi dengan filter yang ditentukan blueprint.
- Verifikasi konsistensi format dan penamaan file export.

#### Dependencies
- Phase 5 (data tagihan, pembayaran, dan kas).
- Phase 2 (data siswa & kelas untuk filter).
- Phase 4 (data nilai & rapor).
- Phase 1 (RBAC: Bendahara, Kepala Sekolah, Super Admin).

#### Exit Criteria
- AC modul PRD §10.6 butir AC-KEU-15 hingga AC-KEU-17 terpenuhi.
- Dashboard Super Admin menampilkan data seluruh cabang secara benar (Super Admin melewati Global Scope).
- Seluruh file export dapat dibuka dan kolomnya sesuai spesifikasi blueprint.

---

### 3.9 Phase 6 — Parent, Student & Teacher Portal

**Sprint:** 7 · **Minggu:** 13–14 · **Estimasi:** 2 minggu

#### Objective
Membuka akses mandiri bagi orang tua, siswa, dan guru terhadap data yang relevan bagi masing-masing — menyelesaikan masalah P-05 pada PRD.

#### Features

| ID | Fitur | Sumber |
|---|---|---|
| PORTAL-01 | Dashboard orang tua: nilai terbaru, kehadiran bulan ini, tagihan belum lunas; switch antar anak; responsive mobile | PRD §9.9 |
| PORTAL-02 | Dashboard guru: jadwal hari ini + shortcut Input Nilai, Daftar Siswa Kelas, Buat Pengumuman | PRD §9.9 |
| PORTAL-03 | Portal siswa: menu Jadwal, Nilai, Notifikasi, Profil | PRD §9.9 |
| — | Endpoint parent: daftar anak, ringkasan, nilai, tagihan, jadwal | Blueprint §4.11 |
| — | Endpoint teacher: dashboard, daftar kelas ajar | Blueprint §4.11 |
| — | Endpoint student: dashboard, jadwal, nilai | Blueprint §4.11 |

#### Deliverables
- Parent Portal responsive dengan pemilih profil anak.
- Portal Siswa dengan empat menu utama.
- Portal Guru dengan jadwal hari ini dan tiga shortcut.
- Pembatasan data: siswa melihat dirinya sendiri, ortu melihat anaknya, guru melihat kelas ajarnya.

#### Dependencies
Fase dengan dependency terbanyak — **titik konvergensi roadmap**:
- Phase 1 (autentikasi & role SISWA, ORANG_TUA, GURU).
- Phase 2 (data siswa, kelas, jadwal).
- Phase 4 (nilai & rapor).
- Phase 5 (tagihan & pembayaran).
- Phase 7 (notifikasi in-app) — **dikerjakan setelah fase ini** pada Sprint 8; komponen notifikasi pada portal diselesaikan saat Phase 7.

#### Exit Criteria
- Seluruh AC modul pada PRD §10.8 terpenuhi.
- Halaman portal termuat < 3 detik pada koneksi 4G (NFR-01).
- Tampilan mobile terverifikasi responsive.

> **Isu terbuka yang memengaruhi fase ini:**
> - "Kehadiran bulan ini" pada PORTAL-01 — sumber data **Belum dijelaskan dalam blueprint** (PRD §17.3 #1).
> - Jumlah nilai terbaru pada dashboard ortu: 3 (PORTAL-01) vs 5 (API Map) — PRD §17.3 #4.
> - Shortcut "Buat Pengumuman" pada dashboard guru bertentangan dengan matriks izin (PRD §17.3 #3).

---

### 3.10 Phase 7 — Notification

**Sprint:** 8 · **Minggu:** 15–16 · **Estimasi:** 2 minggu

#### Objective
Menstrukturkan komunikasi sekolah melalui pengumuman terpusat, notification center in-app, dan link wa.me siap kirim — menyelesaikan masalah P-06 pada PRD.

#### Features

| ID | Fitur | Sumber |
|---|---|---|
| NOTIF-01 | Buat pengumuman bertarget (`ALL` / `CLASS` / `INDIVIDUAL`) dengan kategori | PRD §9.8 |
| NOTIF-02 | Daftar link wa.me siap kirim per penerima + tombol "Buka WA" | PRD §9.8 |
| NOTIF-03 | Trigger otomatis: PPDB status berubah, tagihan baru terbit, rapor diterbitkan; template editable | PRD §9.8 |
| NOTIF-04 | Notification center in-app: badge unread, tandai dibaca, riwayat 90 hari | PRD §9.8 |

#### Deliverables
- CRUD notifikasi dengan tiga jenis target dan status draft/terkirim.
- Generator wa.me bulk (memperluas komponen yang dibangun pada Phase 3).
- Editor template WA per event, tersimpan di `wa_template_ppdb`, `wa_template_spp`, `wa_template_rapor`.
- Tiga trigger otomatis terpasang mundur ke modul PPDB (Phase 3), Keuangan (Phase 5), dan Rapor (Phase 4).
- Bell icon + badge unread + tabel `notification_reads` terintegrasi ke seluruh portal.
- Mekanisme retensi riwayat 90 hari.

#### Dependencies
- Phase 1 (daftar user aktif per cabang, `users.phone`).
- Phase 2 (data kelas untuk target `CLASS`; `students.parent_phone`).
- Phase 3, 4, 5 (sumber tiga event trigger otomatis).
- Phase 6 (portal sebagai tempat notification center ditampilkan).

#### Exit Criteria
- Seluruh AC modul pada PRD §10.7 terpenuhi.
- Uji encoding wa.me lulus untuk seluruh template (Lampiran A.3 #4).
- Ketiga trigger otomatis terbukti aktif melalui pengujian end-to-end.

> **Isu terbuka yang memengaruhi fase ini:**
> - Aturan normalisasi nomor HP ke awalan `62` — **Belum dijelaskan dalam blueprint** (PRD §17.3 #14).
> - Penanganan penerima yang tidak memiliki WhatsApp — **Belum dijelaskan dalam blueprint.**
> - Perbedaan daftar kategori notifikasi antara NOTIF-01 dan ERD (PRD §16.4).

---

### 3.11 Phase 9 — Testing

**Sprint:** 9 · **Minggu:** 17–18 · **Estimasi:** 2 minggu

#### Objective
Memverifikasi keseluruhan sistem terhadap NFR dan checklist go-live, menyelesaikan lokalisasi English, memastikan tampilan mobile, dan menutup seluruh bug sebelum rilis.

> Pengujian per modul sudah berjalan sejak Sprint 1. Fase ini adalah **verifikasi menyeluruh dan perbaikan akhir**, bukan awal aktivitas pengujian.

#### Features / Aktivitas

| Aktivitas | Target | Sumber |
|---|---|---|
| AUTH-05 — penyelesaian bilingual ID/EN | Seluruh label, pesan error, dan placeholder tersedia dua bahasa | PRD §9.1; Sprint 9 |
| Uji responsive mobile | Seluruh portal, terutama Parent Portal | NFR-10; PORTAL-01 AC-3 |
| **Load test** | 200 user konkuren mengakses dashboard tanpa error/timeout | NFR-04; Lampiran A.3 #3 |
| Uji performa | Load halaman < 3 detik pada 4G; response API < 500 ms untuk 95% request | NFR-01, NFR-02 |
| **Security audit** | Rate limiting, CSRF, HTTPS/HSTS, validasi upload, password policy, CORS | PRD §11.2; Sprint 9 |
| **Regresi isolasi tenant** | Unit test Global Scope lulus **100%** + uji manual lintas-tenant | Lampiran A.3 #1, #2 |
| Uji encoding wa.me menyeluruh | Seluruh template pada semua event | Lampiran A.3 #4 |
| Uji PDF rapor menyeluruh | Format dan data sesuai | Lampiran A.3 #5 |
| Uji restore backup | Backup harian dapat dipulihkan | Lampiran A.3 #7 |
| Bug fixing | Seluruh temuan dari aktivitas di atas | Sprint 9 |

#### Deliverables
- Berkas hasil load test yang menunjukkan 200 user konkuren tertangani.
- Laporan security audit beserta tindak lanjutnya.
- Bukti unit test tenant isolation lulus 100%.
- Seluruh string antarmuka tersedia dalam Bahasa Indonesia dan English.
- Daftar bug beserta statusnya (seluruhnya tertutup atau disepakati ditunda).

#### Dependencies
Seluruh fase sebelumnya (Phase 0–8) harus selesai.

#### Exit Criteria
- Butir 1–5 dan 7 pada checklist go-live Lampiran A.3 terpenuhi.
- Tidak ada bug berkategori blocker yang tersisa.

> **Belum dijelaskan dalam blueprint:** rencana UAT formal bersama pengguna sekolah, target code coverage, dan kriteria klasifikasi severity bug.

---

### 3.12 Phase 10 — Deployment

**Sprint:** Setup pada Sprint 1 · Go-live setelah Sprint 9 · **Minggu:** 1 & 18
**Estimasi:** Tercakup dalam Sprint 1 dan Sprint 9. Alokasi durasi terpisah untuk aktivitas go-live: **Belum dijelaskan dalam blueprint.**

#### Objective
Menjalankan sistem di lingkungan produksi secara aman, dengan seluruh gerbang keamanan dan operasional terpenuhi.

#### Features / Aktivitas

| Aktivitas | Kapan | Sumber |
|---|---|---|
| Provisioning VPS + instalasi Nginx, PHP-FPM, MySQL | Sprint 1 (Phase 0) | §3.3.1 |
| Konfigurasi Nginx single domain + reverse proxy PHP-FPM | Sprint 1 | §3.3.2 |
| SSL Let's Encrypt via Certbot + auto-renew | Sprint 1 | §3.3.1 |
| Cloudflare DNS (A record → IP VPS) + cache statis | Sprint 1 | §3.3.1 |
| Supervisor untuk queue worker | Sprint 1 | §3.3.1 |
| Cron backup `mysqldump` harian 02:00 WIB, retensi 30 hari | Sprint 1 | §3.4 |
| Verifikasi redirect HTTP→HTTPS dan HSTS | Pra go-live | Lampiran A.3 #6 |
| Penggantian seluruh password default (MySQL root, admin panel) | Pra go-live | Lampiran A.3 #8 |
| Konfigurasi CORS hanya `apps.smartsukses.sch.id` | Pra go-live | Lampiran A.3 #9 |
| Aktivasi monitoring uptime (UptimeRobot / Better Stack) | Pra go-live | Lampiran A.3 #10 |
| Verifikasi backup dapat di-restore | Pra go-live | Lampiran A.3 #7 |

#### Deliverables
- Aplikasi produksi berjalan di `apps.smartsukses.sch.id` dengan HTTPS.
- Checklist go-live 10 butir tertandatangani lengkap.
- Monitoring uptime aktif dan mengirim peringatan.
- Backup harian terjadwal dan terbukti dapat dipulihkan.

#### Dependencies
Phase 9 (seluruh pengujian lulus).

#### Exit Criteria
**Seluruh 10 butir checklist Lampiran A.3 terpenuhi** (CON-56). Tanpa itu, go-live tidak boleh dilakukan.

> **Belum dijelaskan dalam blueprint:** keberadaan environment staging terpisah, strategi CI/CD, prosedur rollback, serta rencana migrasi data historis dan pelatihan pengguna.

---

### 3.13 Ringkasan Seluruh Fase

| Fase | Nama | Sprint | Minggu | Durasi | FR Utama |
|---|---|---|---|---|---|
| 0 | Project Initialization | 1a | 1 | ~1 mgg | — (fondasi) |
| 1 | Authentication & RBAC | 1b | 2 | ~1 mgg | AUTH-01…04, PORTAL-04 |
| 2 | Master Data | 2 | 3–4 | 2 mgg | SIS-01…05, KELAS-01…04 |
| 3 | PPDB | 3 | 5–6 | 2 mgg | PPDB-01…05 |
| 4 | Academic Module | 4 | 7–8 | 2 mgg | NILAI-01…05 |
| 5 | Finance Module | 5 + 6a | 9–11 | 3 mgg | SPP-01…04, KAS-01 |
| 8 | Reporting | 6b | 12 | 1 mgg | KAS-02, KAS-03, SPP-05 |
| 6 | Portal | 7 | 13–14 | 2 mgg | PORTAL-01…03 |
| 7 | Notification | 8 | 15–16 | 2 mgg | NOTIF-01…04 |
| 9 | Testing | 9 | 17–18 | 2 mgg | AUTH-05 + QA menyeluruh |
| 10 | Deployment | 1 & 9 | 1 & 18 | Tercakup | — (operasional) |
| | | | | **18 mgg** | **38 FR** |

---

## 4. Sprint Planning

Sprint di bawah ini **mengikuti Lampiran A.1 blueprint apa adanya** — 9 sprint × 2 minggu. Kolom "Fase" menunjukkan fase mana yang dikerjakan pada sprint tersebut.

---

### Sprint 1 — Foundation
**Minggu 1–2 · Fase: Phase 0 + Phase 1 + setup Phase 10**

| Aspek | Isi |
|---|---|
| **Goal** | Aplikasi berjalan di produksi dengan autentikasi aman, isolasi tenant terverifikasi, dan tampilan white-label per cabang berfungsi. |
| **Modules** | Setup VPS · Laravel 11 · Filament 3 · Multi-tenant (Global Scope) · Auth (AUTH-01…04) · RBAC 8 role · White-label theming (AUTH-03) · User Management (PORTAL-04) · Manajemen tenant Super Admin · Audit log |
| **Deliverables** | 1. VPS + Nginx + PHP-FPM + MySQL + Supervisor + Certbot aktif<br>2. Tabel `schools`, `users`, `roles`, `model_has_roles`<br>3. Global Scope `school_id` + bypass Super Admin<br>4. **Unit test tenant isolation lulus 100%**<br>5. Login, reset password, ganti password<br>6. 8 role + matriks izin 15 modul terpasang<br>7. CSS variables white-label ter-inject dinamis<br>8. CRUD user + import Excel massal<br>9. CRUD cabang oleh Super Admin<br>10. Audit log berjalan<br>11. Backup harian terjadwal<br>12. Kerangka translation key ID/EN |

---

### Sprint 2 — Core SIS
**Minggu 3–4 · Fase: Phase 2**

| Aspek | Isi |
|---|---|
| **Goal** | Seluruh data induk akademik tersedia dan siap dipakai modul PPDB, Penilaian, dan Keuangan. |
| **Modules** | SIS (SIS-01…05) · Tahun Ajaran · Mata Pelajaran · Kelas & Rombel (KELAS-01, KELAS-02) · `class_subjects` · Jadwal (KELAS-03, KELAS-04) · Import/Export Excel |
| **Deliverables** | 1. CRUD siswa + validasi NISN 10 digit + NIS unik per sekolah<br>2. Soft update & nonaktifkan siswa<br>3. Upload foto (2 MB → resize 400×400)<br>4. Export siswa `.xlsx` + import massal<br>5. CRUD tahun ajaran + aktivasi tunggal<br>6. CRUD mata pelajaran<br>7. CRUD kelas + penetapan wali kelas (validasi 1 guru = 1 kelas/TA)<br>8. Penempatan siswa ke kelas (validasi 1 siswa = 1 kelas/TA)<br>9. Penetapan guru pengampu per mapel per kelas<br>10. Jadwal + **deteksi konflik guru/ruang/kelas**<br>11. Tampilan jadwal mingguan guru<br>12. Unit test CON-35, CON-36, CON-37, CON-38, CON-48 |

---

### Sprint 3 — PPDB
**Minggu 5–6 · Fase: Phase 3**

| Aspek | Isi |
|---|---|
| **Goal** | Calon siswa dapat mendaftar dan memantau statusnya secara mandiri; admin dapat memproses hingga enroll menjadi siswa aktif. |
| **Modules** | Form PPDB publik (PPDB-01) · Cek status publik (PPDB-02) · Panel review admin (PPDB-03) · Generator wa.me (PPDB-04) · Enroll siswa (PPDB-05) |
| **Deliverables** | 1. Halaman publik `/ppdb/[kode_sekolah]` tanpa login<br>2. Generator `reg_number` berformat `[KODE_CABANG]-[TAHUN]-[SEQ]`<br>3. Upload dokumen pendaftaran<br>4. Halaman cek status publik (no. daftar + tgl lahir)<br>5. Panel admin: tabel, filter status, ubah status + catatan alasan<br>6. **Komponen generator link wa.me** (dipakai ulang pada Sprint 8)<br>7. Template teks WA per perubahan status<br>8. Enroll satu klik → record `students` + `converted_student_id`<br>9. Landing publik daftar cabang yang membuka PPDB<br>10. Uji encoding wa.me lulus |

---

### Sprint 4 — Akademik
**Minggu 7–8 · Fase: Phase 4**

| Aspek | Isi |
|---|---|
| **Goal** | Guru dapat menilai, sistem menghitung nilai akhir otomatis, dan Wali Kelas dapat menerbitkan rapor final berformat PDF. |
| **Modules** | Grade config (NILAI-05) · Input nilai (NILAI-01) · Perhitungan otomatis (NILAI-02) · Generate & publish rapor (NILAI-03) · Tampilan nilai & PDF (NILAI-04) |
| **Deliverables** | 1. CRUD `grade_configs` dengan bobot berbeda per mapel<br>2. Input nilai satuan, massal per `class_subject`, dan import Excel<br>3. Pembatasan: guru hanya menilai kelas yang diampunya<br>4. Engine nilai akhir berbobot, pembulatan 2 desimal<br>5. Validasi pra-publish: semua mapel wajib punya nilai akhir<br>6. Publish rapor oleh Wali Kelas + penguncian nilai<br>7. Tampilan nilai real-time untuk siswa & ortu<br>8. Generator rapor PDF via queue<br>9. **Uji PDF rapor: format & data sesuai** |

---

### Sprint 5 — Keuangan
**Minggu 9–10 · Fase: Phase 5 (bagian pertama)**

| Aspek | Isi |
|---|---|
| **Goal** | Bendahara dapat menerbitkan tagihan massal dan mencatat pembayaran; orang tua dapat melihat status tagihan anaknya. |
| **Modules** | Jenis tagihan (SPP-01) · Generate massal (SPP-02) · Catat pembayaran (SPP-03) · Tampilan tagihan ortu (SPP-04) · Pembebasan tagihan (`WAIVED`) |
| **Deliverables** | 1. CRUD `fee_types` + nonaktif tanpa hapus histori<br>2. Job generate tagihan massal via queue + **preview wajib sebelum konfirmasi**<br>3. Due date otomatis<br>4. Form catat pembayaran + upload bukti (JPG/PNG/PDF maks 5 MB)<br>5. Akumulasi `amount_paid` + transisi status `UNPAID`/`PARTIAL`/`PAID`<br>6. Dukungan cicilan (1 tagihan, banyak pembayaran)<br>7. Pembebasan tagihan → `WAIVED` + alasan<br>8. Data tagihan siap dikonsumsi Parent Portal<br>9. Unit test akumulasi pembayaran & transisi status |

---

### Sprint 6 — Akuntansi & Pelaporan
**Minggu 11–12 · Fase: Phase 5 (bagian kedua) + Phase 8**

| Aspek | Isi |
|---|---|
| **Goal** | Kas sekolah tercatat dan seluruh pihak berwenang memiliki dashboard serta laporan keuangan yang dapat diekspor. |
| **Modules** | Buku kas (KAS-01) · Ringkasan keuangan bulanan (KAS-02) · Dashboard lintas cabang (KAS-03) · Export laporan (SPP-05, `/finance/export`) · Dashboard Bendahara |
| **Deliverables** | 1. CRUD `transactions` (`INCOME`/`EXPENSE`) + kategori + lampiran bukti<br>2. Dashboard keuangan cabang: saldo kas, penerimaan SPP bulan ini, pengeluaran bulan ini<br>3. **Grafik tren 6 bulan terakhir**<br>4. Dashboard Super Admin lintas cabang: total tagihan, terkumpul, **% lunas**, filter TA/bulan<br>5. Export laporan tagihan `.xlsx` dengan filter kelas/periode/status<br>6. Export laporan keuangan `.xlsx`<br>7. Endpoint `/finance/summary` dan `/finance/spp-report`<br>8. Statistik per cabang untuk Super Admin<br>9. Verifikasi ulang seluruh export yang dibuat pada sprint sebelumnya |

---

### Sprint 7 — Portal
**Minggu 13–14 · Fase: Phase 6**

| Aspek | Isi |
|---|---|
| **Goal** | Orang tua, siswa, dan guru dapat mengakses data yang relevan secara mandiri melalui portal masing-masing. |
| **Modules** | Parent Portal (PORTAL-01) · Portal Guru (PORTAL-02) · Portal Siswa (PORTAL-03) · Tampilan jadwal |
| **Deliverables** | 1. Dashboard ortu: nilai terbaru, kehadiran bulan ini, tagihan belum lunas<br>2. Pemilih profil anak untuk ortu dengan >1 anak<br>3. Parent Portal responsive mobile<br>4. Halaman nilai, tagihan, dan jadwal anak<br>5. Dashboard guru: jadwal hari ini + 3 shortcut<br>6. Daftar kelas ajar guru<br>7. Portal siswa: Jadwal, Nilai, Notifikasi, Profil<br>8. Nilai siswa per mapel/semester/komponen<br>9. Pembatasan data per pengguna terverifikasi |

---

### Sprint 8 — Notifikasi
**Minggu 15–16 · Fase: Phase 7**

| Aspek | Isi |
|---|---|
| **Goal** | Sekolah dapat menyebarkan pengumuman terstruktur dan sistem memicu notifikasi otomatis pada tiga event kunci. |
| **Modules** | Pengumuman bertarget (NOTIF-01) · wa.me bulk (NOTIF-02) · Trigger otomatis (NOTIF-03) · Notification center (NOTIF-04) |
| **Deliverables** | 1. CRUD notifikasi dengan target `ALL`/`CLASS`/`INDIVIDUAL` + kategori<br>2. Status draft dan terkirim (`is_draft`, `sent_at`)<br>3. Generator wa.me bulk per penerima + filter + salin + tombol "Buka WA"<br>4. Editor template WA (`wa_template_ppdb`, `wa_template_spp`, `wa_template_rapor`)<br>5. Trigger otomatis pada perubahan status PPDB<br>6. Trigger otomatis pada penerbitan tagihan<br>7. Trigger otomatis pada penerbitan rapor<br>8. Bell icon + badge unread + `notification_reads` di seluruh portal<br>9. Retensi riwayat notifikasi 90 hari<br>10. Uji encoding wa.me seluruh template |

---

### Sprint 9 — Polish & QA
**Minggu 17–18 · Fase: Phase 9 + go-live Phase 10**

| Aspek | Isi |
|---|---|
| **Goal** | Sistem lulus seluruh pengujian NFR dan 10 butir checklist go-live, serta siap dirilis ke produksi. |
| **Modules** | Bilingual EN (AUTH-05) · Responsive mobile · Load testing · Security audit · Bug fixing · Aktivitas go-live |
| **Deliverables** | 1. Seluruh label, error, dan placeholder tersedia ID + EN<br>2. Toggle bahasa di navbar + preferensi tersimpan di `users.locale`<br>3. Seluruh portal terverifikasi responsive mobile<br>4. **Load test 200 user konkuren lulus tanpa error/timeout**<br>5. Verifikasi load halaman < 3 detik dan API < 500 ms (p95)<br>6. Laporan security audit + tindak lanjut<br>7. **Unit test tenant isolation lulus 100%** (regresi)<br>8. Uji manual lintas-tenant: nol pelanggaran<br>9. Uji encoding wa.me & PDF rapor menyeluruh<br>10. Uji restore backup berhasil<br>11. Password default diganti; CORS dikunci; monitoring uptime aktif<br>12. **Checklist go-live 10 butir tertandatangani lengkap** |

---

### Ringkasan Sprint

| Sprint | Minggu | Target | Fase | FR Diselesaikan |
|---|---|---|---|---|
| 1 | 1–2 | Foundation | 0, 1, 10 (setup) | AUTH-01…04, PORTAL-04 |
| 2 | 3–4 | Core SIS | 2 | SIS-01…05, KELAS-01…04 |
| 3 | 5–6 | PPDB | 3 | PPDB-01…05 |
| 4 | 7–8 | Akademik | 4 | NILAI-01…05 |
| 5 | 9–10 | Keuangan | 5a | SPP-01…04 |
| 6 | 11–12 | Akuntansi & Pelaporan | 5b, 8 | KAS-01…03, SPP-05 |
| 7 | 13–14 | Portal | 6 | PORTAL-01…03 |
| 8 | 15–16 | Notifikasi | 7 | NOTIF-01…04 |
| 9 | 17–18 | Polish & QA | 9, 10 (go-live) | AUTH-05 |

**Paralelisasi yang diizinkan blueprint (dengan 2 developer):** Sprint 3 ∥ Sprint 4, dan Sprint 5 ∥ Sprint 6 (CON-52). Kombinasi paralel lainnya: **Belum dijelaskan dalam blueprint.**

---

## 5. Module Dependency

### 5.1 Rantai Dependency Utama

```
        Project Initialization
        (Laravel · Filament · Global Scope school_id)
                    ↓
             Authentication
             (AUTH-01, AUTH-02, AUTH-04)
                    ↓
                  RBAC
             (8 role · matriks izin · policy)
                    ↓
              White-Label UI
             (AUTH-03)
                    ↓
              Master Data
     (Tahun Ajaran → Siswa → Kelas → Mapel → Jadwal)
                    ↓
        ┌───────────┴───────────┐
        ↓                       ↓
      PPDB                  Academic
   (PPDB-01…05)          (NILAI-01…05)
        │                       │
        └───────────┬───────────┘
                    ↓
                 Finance
            (SPP-01…04 · KAS-01)
                    ↓
                Reporting
            (KAS-02 · KAS-03 · Export)
                    ↓
                  Portal
        (Parent · Student · Teacher)
                    ↓
               Notification
            (NOTIF-01…04 · trigger otomatis)
                    ↓
                 Testing
                    ↓
                Deployment
```

### 5.2 Peta Dependency Rinci

```
                        ┌─────────────────────────────────────┐
                        │  PHASE 0 + 1 · CORE PLATFORM        │
                        │  schools · users · roles            │
                        │  Global Scope school_id · RBAC      │
                        │  White-label · Locale · Audit       │
                        └───────────────┬─────────────────────┘
                                        │  (semua fase bergantung)
              ┌─────────────────────────┼──────────────────────────┐
              │                         │                          │
     ┌────────▼─────────┐               │                          │
     │ Tahun Ajaran     │◄──────────────┼──────────────┐           │
     │ academic_years   │               │              │           │
     └────────┬─────────┘               │              │           │
              │                         │              │           │
     ┌────────▼─────────┐      ┌────────▼────────┐     │           │
     │ SIS              │◄─────┤ PPDB            │     │           │
     │ students         │enroll│ ppdb_registr.   │     │           │
     └────┬────────┬────┘      └─────────────────┘     │           │
          │        │                                    │           │
          │        └──────────────────┐                 │           │
          │                           │                 │           │
 ┌────────▼──────────┐      ┌─────────▼─────────┐       │           │
 │ Kelas / Rombel    │      │ FINANCE           │       │           │
 │ classes           │      │ fee_types         │       │           │
 │ student_classes   │      │ student_fees      │       │           │
 └────────┬──────────┘      │ payments          │       │           │
          │                 │ transactions      │       │           │
 ┌────────▼──────────┐      └─────────┬─────────┘       │           │
 │ Mapel + Pengampu  │                │                 │           │
 │ subjects          │                │                 │           │
 │ class_subjects    │                │                 │           │
 └────┬─────────┬────┘                │                 │           │
      │         │                     │                 │           │
┌─────▼─────┐ ┌─▼──────────────┐      │                 │           │
│ Jadwal    │ │ Penilaian      │      │                 │           │
│ schedules │ │ grades         │      │                 │           │
│           │ │ grade_configs  │      │                 │           │
└─────┬─────┘ └─┬──────────────┘      │                 │           │
      │         │                     │                 │           │
      │  ┌──────▼──────────┐   ┌──────▼──────────┐      │           │
      │  │ E-Rapor         │   │ REPORTING       │      │           │
      │  │ report_cards    │   │ dashboard·export│      │           │
      │  └──────┬──────────┘   └──────┬──────────┘      │           │
      │         │                     │                 │           │
      └─────────┼─────────────────────┼─────────────────┘           │
                │                     │                             │
        ┌───────▼─────────────────────▼──────────┐                  │
        │ NOTIFICATION                           │◄─────────────────┘
        │ notifications · notification_reads     │  (trigger: PPDB, SPP, Rapor)
        └───────────────────┬────────────────────┘
                            │
        ┌───────────────────▼────────────────────┐
        │ PORTAL                                 │
        │ Parent · Student · Teacher             │
        │ (agregator: nilai + tagihan + jadwal   │
        │  + notifikasi + kehadiran*)            │
        └────────────────────────────────────────┘
                 * sumber data kehadiran: Belum dijelaskan dalam blueprint
```

### 5.3 Matriks Dependency Antar Fase

| Fase | Bergantung pada | Dibutuhkan oleh |
|---|---|---|
| **Phase 0** Project Initialization | — | Seluruh fase |
| **Phase 1** Authentication & RBAC | Phase 0 | Seluruh fase |
| **Phase 2** Master Data | Phase 0, 1 | Phase 3, 4, 5, 6, 7, 8 |
| **Phase 3** PPDB | Phase 0, 1, 2 | Phase 2 (sumber siswa baru), Phase 7 (trigger) |
| **Phase 4** Academic | Phase 0, 1, 2 | Phase 6, 7 (trigger), 8 |
| **Phase 5** Finance | Phase 0, 1, 2 | Phase 6, 7 (trigger), 8 |
| **Phase 8** Reporting | Phase 0, 1, 2, 4, 5 | — |
| **Phase 6** Portal | Phase 0, 1, 2, 4, 5, 7 (komponen notifikasi) | — |
| **Phase 7** Notification | Phase 0, 1, 2, 3, 4, 5, 6 | Phase 6 (notification center) |
| **Phase 9** Testing | Phase 0–8 | Phase 10 |
| **Phase 10** Deployment | Phase 0 (setup), Phase 9 (go-live) | — |

### 5.4 Jalur Kritis

```
Phase 0 → Phase 1 → Phase 2 → ┬→ Phase 4 → ┬→ Phase 8 → Phase 6 → Phase 7 → Phase 9 → Phase 10
                              └→ Phase 5 → ┘
                              └→ Phase 3 (paralel dengan Phase 4)
```

**Titik paling kritis:** Phase 0 dan Phase 1. Global Scope `school_id` yang tidak benar sejak awal akan menjalar ke seluruh modul dan melanggar CON-26 (toleransi kebocoran data nol).

**Titik konvergensi terbanyak:** Phase 6 (Portal) bergantung pada 6 fase sebelumnya — sesuai dengan penempatannya di Sprint 7.

### 5.5 Dependency Lintas-Sprint yang Perlu Diperhatikan

| Ketergantungan | Kondisi | Penanganan dalam roadmap ini |
|---|---|---|
| PPDB-04 (Sprint 3) membutuhkan generator wa.me (Sprint 8) | Modul Notifikasi baru dijadwalkan Sprint 8 | Generator wa.me dibangun sebagai **komponen bersama pada Sprint 3**, diperluas menjadi bulk pada Sprint 8. Penanganan resmi menurut blueprint: **Belum dijelaskan dalam blueprint.** |
| NOTIF-03 (Sprint 8) memicu event dari PPDB, SPP, dan Rapor | Ketiga sumber selesai pada Sprint 3, 5, dan 4 | Trigger dipasang mundur pada Sprint 8 |
| AUTH-05 Bilingual (Sprint 9) menyentuh seluruh sprint sebelumnya | Terjemahan EN baru dikerjakan di akhir | Seluruh teks ditulis sebagai translation key sejak Sprint 1 |
| Notification center (Sprint 8) ditampilkan di Portal (Sprint 7) | Portal selesai lebih dahulu | Slot notification center disiapkan pada Sprint 7, diisi pada Sprint 8 |
| Rapor & Parent Portal membutuhkan data kehadiran | Modul Presensi Digital berada di Phase 2 blueprint | **Belum dijelaskan dalam blueprint** — perlu keputusan pemilik blueprint sebelum Sprint 2 |

---

## 6. Risks

Risiko diberi kode **RSK-xx** dan dikelompokkan menjadi tiga kategori. Penilaian probabilitas dan dampak adalah penilaian roadmap ini; sumber faktanya berasal dari blueprint dan PRD.

**Skala:** Rendah · Sedang · Tinggi · Kritis

### 6.1 Risiko Kesenjangan & Inkonsistensi Requirement

| ID | Risiko | Probabilitas | Dampak | Fase Terdampak |
|---|---|:---:|:---:|---|
| **RSK-01** | **Data kehadiran dibutuhkan Phase 1 tetapi modul Presensi Digital ada di Phase 2 dan tidak ada tabel absensi di ERD.** Terdampak: SIS-04 ("status kehadiran hari ini"), PORTAL-01 ("kehadiran bulan ini"), `report_cards.attend_*`, deskripsi role Wali Kelas | Tinggi | **Tinggi** | Phase 2, 4, 6 |
| **RSK-02** | **Tabel `audit_logs` disyaratkan NFR-12 dan §3.4 tetapi tidak ada dalam daftar 21 tabel ERD**; strukturnya tidak didefinisikan | Tinggi | Sedang | Phase 1 |
| **RSK-03** | **Konflik kewenangan guru membuat pengumuman** — matriks izin ❌ vs shortcut "Buat Pengumuman" pada PORTAL-02 | Tinggi | Sedang | Phase 6, 7 |
| **RSK-04** | **Angka dashboard ortu tidak konsisten** — PORTAL-01 menyebut 3 nilai terbaru, API Map menyebut 5 mapel | Tinggi | Rendah | Phase 6 |
| **RSK-05** | **Status `INACTIVE` pada SIS-02 tidak ada di ENUM `students.status`** | Tinggi | Sedang | Phase 2 |
| **RSK-06** | **Mekanisme approval Kepala Sekolah tidak terdefinisi** meski disebut pada deskripsi role | Sedang | Sedang | Phase 1 |
| **RSK-07** | **Presedensi bobot ganda** — `grades.weight` (nullable) vs `grade_configs.components`; hasil nilai akhir dapat berbeda tergantung sumber bobot | Sedang | Sedang | Phase 4 |
| **RSK-08** | **Tidak ada mekanisme unpublish / koreksi rapor** setelah nilai terkunci | Sedang | Sedang | Phase 4 |
| **RSK-09** | **Rumus `rank_in_class` dan aturan tie-break tidak dijelaskan** | Sedang | Rendah | Phase 4 |
| **RSK-10** | **Perilaku generate tagihan `YEARLY` dan `ONCE` tidak dijelaskan**; SPP-02 hanya membahas bulanan | Sedang | Sedang | Phase 5 |
| **RSK-11** | **Mekanisme autentikasi disebut dua cara** — "JWT + session" (NFR) vs Laravel Sanctum (§3.4 & API) | Sedang | Rendah | Phase 1 |
| **RSK-12** | **Format upload foto tidak konsisten** — SIS-03 mengizinkan WEBP, §3.4 hanya JPG/PNG/PDF | Sedang | Rendah | Phase 2 |
| **RSK-13** | **Relasi orang tua ↔ banyak anak hanya melalui `students.parent_user_id`**; skenario dua wali untuk satu siswa tidak dijelaskan | Sedang | Sedang | Phase 6 |
| **RSK-14** | **Aturan normalisasi nomor HP ke awalan `62` dan penanganan penerima tanpa WhatsApp tidak dijelaskan** | Tinggi | Sedang | Phase 3, 7 |

### 6.2 Risiko Teknis & Arsitektur

| ID | Risiko | Probabilitas | Dampak | Fase Terdampak |
|---|---|:---:|:---:|---|
| **RSK-15** | **Kebocoran data antar tenant** — pola shared schema; satu query yang lolos dari Global Scope sudah cukup melanggar NFR-06 (isolasi 100%) | Sedang | **Kritis** | Seluruh fase |
| **RSK-16** | **Bypass Super Admin memperlemah proteksi** — jalur `isSuperAdmin()` melewati Global Scope untuk semua query | Sedang | Tinggi | Phase 1, 8 |
| **RSK-17** | **Single point of failure** — Nginx, PHP-FPM, MySQL, dan Queue seluruhnya pada satu VPS 2C/2GB, sementara target uptime 99% | Sedang | Tinggi | Phase 10 |
| **RSK-18** | **Target 200 user konkuren pada VPS 2C/2GB dengan cache & queue database driver** (bukan Redis) berisiko tidak tercapai | Sedang | Tinggi | Phase 9 |
| **RSK-19** | **Job berat bersamaan** — generate tagihan massal dan generate PDF rapor serentak (mis. akhir semester) pada queue database driver | Sedang | Sedang | Phase 4, 5 |
| **RSK-20** | **Storage 40 GB SSD terbatas** untuk foto siswa, bukti bayar, dokumen PPDB, dan scan nota | Sedang | Sedang | Phase 2, 3, 5 |
| **RSK-21** | **Deteksi konflik jadwal rawan bug** dan berpotensi mahal secara query (tiga dimensi: guru, ruang, kelas) | Sedang | Sedang | Phase 2 |
| **RSK-22** | **`report_cards.final_scores` dan `grade_configs.components` disimpan sebagai JSON** — menyulitkan query dan agregasi lintas siswa untuk pelaporan | Sedang | Sedang | Phase 4, 8 |
| **RSK-23** | **Backup hanya tersimpan lokal di VPS pada Phase 1** — jika VPS hilang, backup ikut hilang | Rendah | **Tinggi** | Phase 10 |
| **RSK-24** | **Limit email SMTP gratis 500–2.000/hari** untuk reset password dan verifikasi | Rendah | Rendah | Phase 1 |

### 6.3 Risiko Operasional & Proses

| ID | Risiko | Probabilitas | Dampak | Fase Terdampak |
|---|---|:---:|:---:|---|
| **RSK-25** | **Pengiriman WhatsApp masih manual** — admin harus klik satu per satu untuk ratusan penerima; berpotensi menghambat adopsi | Tinggi | Tinggi | Phase 3, 7 |
| **RSK-26** | **Ketergantungan pada 1 developer selama 18 minggu** — bus factor = 1 | Sedang | Tinggi | Seluruh fase |
| **RSK-27** | **Blueprint masih berstatus DRAFT** — perubahan scope di tengah pengerjaan akan membatalkan estimasi | Sedang | Tinggi | Seluruh fase |
| **RSK-28** | **Bilingual EN baru dikerjakan Sprint 9** padahal AUTH-05 bersifat cross-cutting — retrofit string dari 8 sprint sebelumnya | Tinggi | Sedang | Phase 9 |
| **RSK-29** | **Generator wa.me dibutuhkan Sprint 3 tetapi dijadwalkan Sprint 8** — urutan blueprint tidak menjelaskan penanganannya | Tinggi | Sedang | Phase 3, 7 |
| **RSK-30** | **Migrasi data historis dari spreadsheet dan pelatihan pengguna tidak direncanakan** dalam blueprint | Sedang | Tinggi | Phase 10 |
| **RSK-31** | **Tidak ada rencana UAT, CI/CD, rollback, maupun environment staging** dalam blueprint | Sedang | Sedang | Phase 9, 10 |
| **RSK-32** | **Tidak ada SLA support pasca go-live** yang didefinisikan | Sedang | Sedang | Maintenance |

---

## 7. Mitigation

Setiap mitigasi merujuk ke risiko pada [§6](#6-risks). Mitigasi yang **sudah ditetapkan blueprint** ditandai pada kolom Sumber; sisanya adalah langkah pelaksanaan yang tidak mengubah requirement.

### 7.1 Mitigasi Risiko Kesenjangan Requirement

| Risiko | Mitigasi | Kapan | Sumber |
|---|---|---|---|
| **RSK-01** | **Ajukan sebagai pertanyaan klarifikasi prioritas tertinggi kepada pemilik blueprint sebelum Sprint 2 dimulai.** Sampai ada jawaban, tandai AC-SIS-08, AC-PORTAL-02, dan rekap kehadiran pada rapor sebagai *pending*, dan **jangan mengarang sumber data**. Bangun seluruh fitur lain di ketiga fase terdampak sehingga hanya bagian kehadiran yang tertunda | Sebelum Sprint 2 | Roadmap |
| **RSK-02** | Konfirmasi apakah `audit_logs` termasuk scope Phase 1. Jika ya, minta definisi struktur tabel diterbitkan sebagai revisi blueprint (CON-55). Sementara itu, implementasikan pencatatan sesuai field yang disebut §3.4: user, action, table, id, timestamp, IP | Sprint 1 | §3.4 |
| **RSK-03** | Minta keputusan tertulis: matriks izin atau PORTAL-02 yang berlaku. Implementasikan sesuai keputusan, jangan pilih sendiri | Sebelum Sprint 7 | Roadmap |
| **RSK-04** | Minta penetapan satu angka (3 atau 5). Sampai diputuskan, gunakan angka pada user story PORTAL-01 karena PRD memperlakukan user story sebagai requirement utama, dan catat sebagai keputusan sementara | Sebelum Sprint 7 | Roadmap |
| **RSK-05** | Minta penyelarasan: tambahkan `INACTIVE` ke ENUM, atau ganti rujukan SIS-02 ke salah satu nilai ENUM yang ada. Keputusan wajib datang dari pemilik blueprint | Sebelum Sprint 2 | Roadmap |
| **RSK-06** | Minta definisi objek dan alur approval. Jika tidak ada, catat secara eksplisit bahwa Kepala Sekolah hanya memiliki hak baca (⭕) sesuai matriks izin, dan komunikasikan ke pemangku kepentingan agar tidak muncul ekspektasi yang tidak terpenuhi | Sprint 1 | §1.1.2 |
| **RSK-07** | Tetapkan presedensi sebelum implementasi engine nilai. Tulis unit test yang mengunci perilaku yang disepakati agar tidak berubah diam-diam | Sebelum Sprint 4 | Roadmap |
| **RSK-08** | Komunikasikan konsekuensi CON-40 (rapor terkunci permanen) kepada pemangku kepentingan sebelum Sprint 4. Jika koreksi dibutuhkan, itu adalah **requirement baru** dan wajib melalui revisi blueprint | Sebelum Sprint 4 | CON-55 |
| **RSK-09** | Minta rumus dan aturan tie-break. Jika tidak tersedia, biarkan `rank_in_class` tidak terisi dan catat sebagai *pending* — jangan menciptakan rumus sendiri | Sprint 4 | Roadmap |
| **RSK-10** | Minta penjelasan perilaku generate untuk `YEARLY` dan `ONCE`. Implementasikan `MONTHLY` sesuai SPP-02, dan tandai dua frekuensi lain sebagai *pending* | Sprint 5 | Roadmap |
| **RSK-11** | Pilih satu mekanisme di Sprint 1 dan terapkan konsisten. Blueprint §3.4 dan seluruh API Map menunjuk Laravel Sanctum, sehingga rujukan itu yang paling spesifik — namun keputusan tetap perlu dikonfirmasi | Sprint 1 | §3.4 |
| **RSK-12** | Minta penyelarasan daftar format. Sampai diputuskan, terapkan irisan yang aman (JPG/PNG) dan catat WEBP sebagai *pending* | Sprint 2 | Roadmap |
| **RSK-13** | Verifikasi kebutuhan saat desain Parent Portal. Implementasikan sesuai ERD (`students.parent_user_id`); skenario dua wali dicatat sebagai belum didukung | Sprint 7 | ERD |
| **RSK-14** | Minta aturan normalisasi nomor HP dan penanganan penerima tanpa WhatsApp. Sementara itu, tampilkan peringatan pada daftar penerima yang nomornya tidak dapat dikonversi | Sebelum Sprint 3 | Roadmap |

### 7.2 Mitigasi Risiko Teknis & Arsitektur

| Risiko | Mitigasi | Kapan | Sumber |
|---|---|---|---|
| **RSK-15** | **Prinsip tenant-first:** setiap model baru wajib langsung memiliki Global Scope beserta unit test isolasinya pada sprint yang sama — bukan ditambahkan belakangan. **Unit test Global Scope wajib lulus 100%** dan dijalankan sebagai regresi di setiap sprint. Uji manual lintas-tenant (Madani vs Cinangka) dilakukan pada Sprint 1 dan diulang Sprint 9 | Setiap sprint | Lampiran A.3 #1, #2 |
| **RSK-16** | Buat **test case khusus jalur Super Admin** yang memverifikasi bahwa bypass hanya berlaku untuk role tersebut dan tidak dapat dipicu oleh manipulasi input. Verifikasi ulang saat dashboard lintas cabang dibangun (Phase 8) | Sprint 1 & 6 | Roadmap |
| **RSK-17** | Aktifkan **monitoring uptime** (UptimeRobot / Better Stack) sebelum go-live. Blueprint hanya menyediakan skalabilitas vertikal sebagai jalur peningkatan; strategi HA/failover **Belum dijelaskan dalam blueprint** dan perlu diangkat ke pemilik blueprint sebagai keputusan | Pra go-live | Lampiran A.3 #10 |
| **RSK-18** | **Load test 200 user konkuren di Sprint 9** sesuai checklist go-live. Jika target tidak tercapai, opsi yang tersedia menurut blueprint adalah upgrade vertikal VPS; upgrade ke Redis baru tersedia pada Phase 2 | Sprint 9 | Lampiran A.3 #3 |
| **RSK-19** | Jalankan seluruh job berat melalui **Laravel Queue + Supervisor** sesuai desain blueprint. Uji generate tagihan untuk seluruh siswa aktif dan generate PDF sekelas pada Sprint 5 dan Sprint 4 | Sprint 4 & 5 | §3.1, §3.3.1 |
| **RSK-20** | Terapkan batas ukuran yang sudah ditetapkan (foto 2 MB, bukti bayar 5 MB) secara ketat. Pantau penggunaan disk; blueprint menyediakan jalur migrasi ke Backblaze B2 bila disk penuh | Berkelanjutan | §3.1; Lampiran A.2 |
| **RSK-21** | Tulis **unit test khusus deteksi konflik jadwal** untuk ketiga dimensi (guru, ruang, kelas) termasuk kasus batas waktu bersinggungan | Sprint 2 | CON-48 |
| **RSK-22** | Rancang query agregasi dengan hati-hati sejak Phase 4. Jika pelaporan lintas siswa terbukti lambat, pertimbangkan kolom turunan atau caching — tanpa mengubah struktur tabel yang ditetapkan ERD | Sprint 4 & 6 | Roadmap |
| **RSK-23** | Uji restore backup sebelum go-live (checklist #7). Angkat ke pemilik blueprint bahwa backup lokal pada Phase 1 adalah risiko tinggi; blueprint sendiri sudah menyebut Backblaze B2 sebagai tujuan pada Phase 2 | Pra go-live | Lampiran A.3 #7; §3.4 |
| **RSK-24** | Pantau volume email. Blueprint menilai limit gratis cukup untuk skala awal (800–1.500 akun) | Berkelanjutan | Lampiran A.2 |

### 7.3 Mitigasi Risiko Operasional & Proses

| Risiko | Mitigasi | Kapan | Sumber |
|---|---|---|---|
| **RSK-25** | Sampaikan konsekuensi operasional secara eksplisit kepada pemangku kepentingan sebelum go-live: pengiriman manual adalah **keputusan desain Phase 1**, bukan kekurangan implementasi. Sediakan fitur filter dan salin pada daftar penerima agar beban admin berkurang (sudah menjadi bagian NOTIF-02). Upgrade ke WhatsApp API tersedia pada Phase 2 | Sprint 8 & pra go-live | NOTIF-02; §1.3 |
| **RSK-26** | Gunakan opsi **2 developer** yang disediakan blueprint untuk memparalelkan Sprint 3–4 dan Sprint 5–6, sekaligus mengurangi bus factor. Pastikan dokumentasi (`docs/`) selalu mutakhir agar pengetahuan tidak terkunci pada satu orang | Sejak Sprint 1 | Lampiran A.1 |
| **RSK-27** | Terapkan CON-55 secara disiplin: setiap perubahan scope wajib diterbitkan sebagai versi blueprint baru, dan PRD serta roadmap diperbarui mengikutinya — bukan sebaliknya | Berkelanjutan | CON-55 |
| **RSK-28** | **Tulis seluruh teks sebagai translation key sejak Sprint 1.** Dengan demikian Sprint 9 hanya perlu mengisi berkas terjemahan EN, bukan menyisir ulang kode delapan sprint | Sejak Sprint 1 | PRD §2.2 |
| **RSK-29** | Bangun generator wa.me sebagai **komponen bersama pada Sprint 3** (dibutuhkan PPDB-04), lalu perluas menjadi bulk generator pada Sprint 8. Tidak ada fitur baru yang ditambahkan — hanya urutan pembangunan komponen | Sprint 3 | Roadmap |
| **RSK-30** | Angkat ke pemilik blueprint sebagai kebutuhan yang belum tercakup. Migrasi data dan pelatihan pengguna: **Belum dijelaskan dalam blueprint** — bila diperlukan, keduanya adalah pekerjaan tambahan di luar 18 minggu | Sebelum go-live | Roadmap |
| **RSK-31** | Jalankan minimal pengujian yang **sudah** disyaratkan blueprint (checklist Lampiran A.3). UAT, CI/CD, rollback, dan staging: **Belum dijelaskan dalam blueprint** — ajukan sebagai keputusan terpisah | Sprint 9 | Lampiran A.3 |
| **RSK-32** | Angkat kebutuhan SLA support pasca go-live kepada pemilik blueprint. Sementara itu, monitoring uptime dan backup harian menjadi pengaman operasional minimum yang sudah ditetapkan blueprint | Pra go-live | NFR-08, NFR-09 |

### 7.4 Ringkasan Mitigasi Prioritas Tertinggi

| Prioritas | Tindakan | Batas Waktu |
|:---:|---|---|
| 1 | **Klarifikasi sumber data kehadiran (RSK-01)** — memblokir tiga fase | Sebelum Sprint 2 |
| 2 | **Terapkan prinsip tenant-first + unit test isolasi 100% (RSK-15)** | Sejak Sprint 1, setiap sprint |
| 3 | **Tulis seluruh teks sebagai translation key (RSK-28)** | Sejak Sprint 1 |
| 4 | **Bangun generator wa.me sebagai komponen bersama (RSK-29)** | Sprint 3 |
| 5 | **Ajukan 14 isu terbuka PRD §17.3 sebagai satu paket klarifikasi** | Sebelum Sprint 1 selesai |

---

## 8. Milestone

Milestone adalah titik verifikasi yang dapat diperiksa pemangku kepentingan. Setiap milestone memiliki **exit criteria** yang harus terpenuhi sebelum pekerjaan berlanjut.

| ID | Milestone | Akhir Minggu | Sprint | Exit Criteria |
|---|---|:---:|:---:|---|
| **MS-0** | **Klarifikasi Requirement Selesai** | Sebelum minggu 1 | — | 14 isu terbuka pada PRD §17.3 telah diajukan; minimal RSK-01, RSK-05, dan RSK-11 memperoleh jawaban |
| **MS-1** | **Fondasi Multi-Tenant Siap** | 2 | 1 | Aplikasi berjalan via HTTPS · Login berfungsi · 8 role aktif sesuai matriks izin · White-label per cabang tampil benar · **Unit test tenant isolation lulus 100%** · Uji manual Madani vs Cinangka: nol pelanggaran · Backup harian terjadwal |
| **MS-2** | **Data Induk Akademik Lengkap** | 4 | 2 | CRUD siswa, tahun ajaran, kelas, mapel, jadwal berfungsi · Import/export Excel berjalan · Unit test CON-35…38 dan CON-48 lulus · Data 3 cabang dapat diinput |
| **MS-3** | **PPDB Beroperasi End-to-End** | 6 | 3 | Formulir publik dapat diakses tanpa login · Nomor pendaftaran ter-generate · Cek status publik berfungsi · Alur 5 status terverifikasi · Enroll satu klik menghasilkan record siswa · Uji encoding wa.me lulus |
| **MS-4** | **Siklus Akademik Lengkap** | 8 | 4 | Bobot penilaian dapat dikonfigurasi per mapel · Guru dapat menilai (satuan/bulk/import) · Nilai akhir terhitung otomatis 2 desimal · Rapor dapat di-publish dan terkunci · **Uji PDF rapor: format & data sesuai** |
| **MS-5** | **Siklus Keuangan Lengkap** | 10 | 5 | Jenis tagihan dapat dibuat · Generate massal + preview berfungsi · Pembayaran tercatat dengan bukti · Status tagihan bertransisi benar · Cicilan didukung |
| **MS-6** | **Pelaporan & Dashboard Siap** | 12 | 6 | Buku kas berfungsi · Dashboard cabang menampilkan saldo + grafik tren 6 bulan · Dashboard Super Admin menampilkan seluruh cabang + % lunas · Seluruh export Excel terverifikasi |
| **MS-7** | **Portal Pengguna Aktif** | 14 | 7 | Parent Portal responsive dengan switch antar anak · Portal Siswa 4 menu · Portal Guru dengan jadwal & shortcut · Pembatasan data per pengguna terverifikasi · Load halaman < 3 detik |
| **MS-8** | **Sistem Komunikasi Aktif** | 16 | 8 | Pengumuman bertarget berfungsi · wa.me bulk generator berfungsi · **Tiga trigger otomatis aktif** (PPDB, tagihan, rapor) · Notification center dengan badge unread berjalan · Retensi 90 hari terpasang |
| **MS-9** | **Siap Rilis (Release Candidate)** | 18 | 9 | Bilingual ID/EN lengkap · Responsive mobile terverifikasi · **Load test 200 user konkuren lulus** · Security audit selesai + tindak lanjut · Unit test isolasi lulus 100% (regresi) · Nol bug blocker |
| **MS-10** | **Go-Live** | 18 | 9 | **Seluruh 10 butir checklist Lampiran A.3 tertandatangani lengkap** (CON-56) · Monitoring uptime aktif · Backup terbukti dapat di-restore |
| **MS-11** | **Stabil 3 Bulan — Gerbang Phase 2** | +3 bulan setelah MS-10 | — | Sistem berjalan stabil minimal 3 bulan dengan uptime ≥ 99%/bulan (CON-54, SM-13). Baru setelah ini Phase 2 boleh dimulai |

### 8.1 Linimasa Milestone

```
Minggu:  0    2    4    6    8   10   12   14   16   18        +3 bulan
         │    │    │    │    │    │    │    │    │    │             │
       MS-0 MS-1 MS-2 MS-3 MS-4 MS-5 MS-6 MS-7 MS-8 MS-9           MS-11
         │    │    │    │    │    │    │    │    │    └─MS-10        │
         │    │    │    │    │    │    │    │    │      (Go-Live)    │
    Klarifi- Fon- Data PPDB Aka- Keu- Lapo- Por- Komu-  Rilis    Gerbang
      kasi   dasi Induk     demik angan ran   tal nikasi          Phase 2
```

### 8.2 Ketergantungan Antar Milestone

| Milestone | Tidak boleh dimulai sebelum |
|---|---|
| MS-1 | MS-0 (minimal isu pemblokir terjawab) |
| MS-2 | MS-1 |
| MS-3, MS-4 | MS-2 |
| MS-5 | MS-2 |
| MS-6 | MS-4, MS-5 |
| MS-7 | MS-4, MS-5, MS-6 |
| MS-8 | MS-3, MS-4, MS-5, MS-7 |
| MS-9 | MS-1 sampai MS-8 seluruhnya |
| MS-10 | MS-9 |
| MS-11 | MS-10 + 3 bulan operasional stabil |

---

## 9. Definition of Done

Definition of Done (DoD) berlaku berjenjang: **User Story → Modul → Sprint → Fase → Rilis**. Setiap jenjang mensyaratkan jenjang di bawahnya sudah terpenuhi.

### 9.1 DoD Tingkat User Story

Sebuah user story dinyatakan **DONE** apabila:

| # | Kriteria |
|---|---|
| 1 | **Seluruh Acceptance Criteria** pada PRD §9 untuk user story tersebut terpenuhi, tanpa pengecualian |
| 2 | Kode berjalan tanpa error dan mengikuti stack yang ditetapkan (CON-01…CON-11) |
| 3 | Seluruh query menuju tabel bisnis **ter-scope `school_id`** melalui Global Scope (CON-15) |
| 4 | **Unit test isolasi tenant** untuk model yang terlibat sudah ditulis dan lulus |
| 5 | Validasi input diterapkan melalui Laravel Form Request (PRD §11.2) |
| 6 | Otorisasi diterapkan sesuai matriks izin PRD §8.2 dan aturan akses tambahan §8.3 |
| 7 | Seluruh teks antarmuka ditulis sebagai **translation key**, bukan string keras |
| 8 | Aksi Create/Update/Delete tercatat di audit log (CON-33) |
| 9 | Tidak ada raw SQL selain `DB::select()` dengan binding (CON-10) |

### 9.2 DoD Tingkat Modul

Sebuah modul dinyatakan **DONE** apabila:

| # | Kriteria |
|---|---|
| 1 | Seluruh user story dalam modul memenuhi DoD §9.1 |
| 2 | **Seluruh AC modul pada PRD §10** untuk modul tersebut terpenuhi |
| 3 | Seluruh constraint aturan bisnis yang relevan (CON-35…CON-50) diverifikasi melalui unit test |
| 4 | Seluruh endpoint modul sesuai API Endpoint Map blueprint Bagian 4, termasuk auth level-nya |
| 5 | Tidak ada AC yang berstatus *pending* — **kecuali** yang penyebabnya tercatat sebagai "Belum dijelaskan dalam blueprint" dan sudah diajukan sebagai isu terbuka |
| 6 | Modul dapat dioperasikan oleh role yang dituju tanpa bantuan developer |

> **Aturan khusus:** modul yang memiliki blocker terbuka (mis. SIS dan Portal yang bergantung pada data kehadiran — RSK-01) **tidak boleh dinyatakan 100% DONE**. Statusnya adalah *DONE kecuali butir X*, dan butir tersebut wajib tercatat pada daftar isu terbuka.

### 9.3 DoD Tingkat Sprint

Sebuah sprint dinyatakan **DONE** apabila:

| # | Kriteria |
|---|---|
| 1 | Seluruh deliverable sprint pada [§4](#4-sprint-planning) tersedia dan dapat didemonstrasikan |
| 2 | Seluruh modul dalam sprint memenuhi DoD §9.2 |
| 3 | **Unit test tenant isolation lulus 100%** — termasuk regresi atas model dari sprint-sprint sebelumnya |
| 4 | Tidak ada bug blocker yang terbuka |
| 5 | Milestone sprint tersebut memenuhi exit criteria pada [§8](#8-milestone) |
| 6 | Dokumentasi di `docs/` diperbarui bila ada keputusan atau klarifikasi baru |

### 9.4 DoD Tingkat Fase

Sebuah fase dinyatakan **DONE** apabila:

| # | Kriteria |
|---|---|
| 1 | Seluruh sprint yang menyusun fase memenuhi DoD §9.3 |
| 2 | Seluruh **Exit Criteria** fase pada [§3](#3-development-phases) terpenuhi |
| 3 | Seluruh deliverable fase tersedia |
| 4 | Fase-fase yang bergantung padanya ([§5.3](#53-matriks-dependency-antar-fase)) dapat dimulai tanpa terhalang |
| 5 | Isu terbuka yang memblokir fase berikutnya sudah diajukan dan berstatus terjawab atau disepakati ditunda |

### 9.5 DoD Tingkat Rilis (Go-Live Phase 1)

Rilis Phase 1 dinyatakan **DONE** apabila:

| # | Kriteria | Sumber |
|---|---|---|
| 1 | Seluruh **38 functional requirement** memenuhi DoD §9.1 | PRD §9 |
| 2 | Seluruh **8 modul utama** memenuhi DoD §9.2 | PRD §10 |
| 3 | Seluruh **9 sprint** memenuhi DoD §9.3 | §4 |
| 4 | Seluruh **12 NFR** terverifikasi terpenuhi | PRD §11.1 |
| 5 | Unit test Global Scope (tenant isolation) lulus **100%** | Lampiran A.3 #1 |
| 6 | Uji akses lintas-tenant: user Madani tidak dapat melihat data Cinangka | Lampiran A.3 #2 |
| 7 | Load test: **200 user konkuren** mengakses dashboard tanpa error/timeout | Lampiran A.3 #3 |
| 8 | Uji wa.me link: seluruh template teks ter-encode dengan benar | Lampiran A.3 #4 |
| 9 | Uji PDF rapor: format dan data sesuai | Lampiran A.3 #5 |
| 10 | SSL aktif dan redirect HTTP→HTTPS berjalan | Lampiran A.3 #6 |
| 11 | Backup database otomatis berjalan **dan terbukti dapat di-restore** | Lampiran A.3 #7 |
| 12 | Seluruh password default diubah (MySQL root, admin panel) | Lampiran A.3 #8 |
| 13 | CORS dikonfigurasi hanya menerima dari `apps.smartsukses.sch.id` | Lampiran A.3 #9 |
| 14 | Monitoring uptime diaktifkan | Lampiran A.3 #10 |
| 15 | Tidak ada bug blocker yang tersisa | §3.11 |
| 16 | Seluruh isu terbuka berstatus terjawab atau disepakati secara tertulis untuk ditunda | PRD §17.3 |

> **Gerbang mutlak:** butir 5 hingga 14 adalah checklist Lampiran A.3 blueprint. **Go-live tidak boleh dilakukan bila salah satu belum terpenuhi** (CON-56).

### 9.6 DoD Gerbang Phase 2

| # | Kriteria | Sumber |
|---|---|---|
| 1 | Phase 1 telah **go-live** dan memenuhi DoD §9.5 | CON-56 |
| 2 | Sistem berjalan **stabil minimal 3 bulan** | CON-54; SM-13 |
| 3 | Uptime ≥ 99% per bulan selama masa tersebut | NFR-08 |

---

## 10. Timeline Visual (Mingguan)

Timeline ini memvisualkan rencana yang sudah ditetapkan pada [§3](#3-development-phases) dan [§4](#4-sprint-planning). **Tidak ada perubahan durasi maupun urutan** — total tetap 18 minggu, 9 sprint (CON-51).

### 10.1 Gantt Chart — 18 Minggu

```
                      W01 W02 W03 W04 W05 W06 W07 W08 W09 W10 W11 W12 W13 W14 W15 W16 W17 W18
Sprint                |--S1--||--S2--||--S3--||--S4--||--S5--||--S6--||--S7--||--S8--||--S9--|

P0 · Project Init     ████
P1 · Auth & RBAC          ████
P2 · Master Data              ████████
P3 · PPDB                             ████████
P4 · Academic                                 ████████
P5 · Finance                                          ████████████
P8 · Reporting                                                    ████
P6 · Portal                                                           ████████
P7 · Notification                                                             ████████
P9 · Testing                                                                          ████████
P10 · Deployment      ░░░░                                                                ░░░░

Milestone                 ▲       ▲       ▲       ▲       ▲       ▲       ▲       ▲       ▲
                        MS-1    MS-2    MS-3    MS-4    MS-5    MS-6    MS-7    MS-8   MS-9
                                                                                       MS-10
```

**Legenda**

| Simbol | Arti |
|:---:|---|
| `████` | Minggu kerja utama fase tersebut |
| `░░░░` | Aktivitas tercakup dalam sprint lain (Phase 10: setup di Sprint 1, go-live di Sprint 9) |
| `▲` | Titik verifikasi milestone di akhir minggu |
| `|--Sn--|` | Rentang Sprint ke-n (2 minggu) |

> **MS-0 (Klarifikasi Requirement)** berada sebelum W01 dan tidak tampil pada grafik. **MS-11 (Gerbang Phase 2)** jatuh 3 bulan setelah MS-10.

### 10.2 Rincian Mingguan

| Minggu | Sprint | Fase | Fokus Kerja Utama | Milestone Akhir Minggu |
|:---:|:---:|---|---|---|
| **W01** | S1 | Phase 0 + setup Phase 10 | Provisioning VPS, Nginx, PHP-FPM, MySQL, Supervisor, Certbot, Cloudflare · Instalasi Laravel 11 + Filament 3 + Livewire 3 + paket spatie · Migrasi tabel inti · **Global Scope `school_id`** · Kerangka translation key · Cron backup | — |
| **W02** | S1 | Phase 1 | Login & reset password · 8 role + matriks izin · Policy per model · White-label CSS variables · CRUD user + import Excel · CRUD cabang Super Admin · Audit log · **Unit test isolasi tenant** | **MS-1** Fondasi Multi-Tenant Siap |
| **W03** | S2 | Phase 2 | CRUD siswa + validasi NISN/NIS · Upload foto 400×400 · Soft update & nonaktifkan · Export/import Excel siswa | — |
| **W04** | S2 | Phase 2 | Tahun ajaran + aktivasi tunggal · Mata pelajaran · Kelas + wali kelas · Penempatan siswa · `class_subjects` · Jadwal + **deteksi konflik** · Unit test CON-35…38, CON-48 | **MS-2** Data Induk Akademik Lengkap |
| **W05** | S3 | Phase 3 | Form PPDB publik · Generator `reg_number` · Upload dokumen · Halaman cek status publik | — |
| **W06** | S3 | Phase 3 | Panel review admin + filter status + catatan alasan · **Komponen generator wa.me** · Template teks per status · Enroll satu klik · Uji encoding wa.me | **MS-3** PPDB Beroperasi End-to-End |
| **W07** | S4 | Phase 4 | `grade_configs` bobot per mapel · Input nilai satuan/massal/import Excel · Pembatasan guru pada kelas ajar | — |
| **W08** | S4 | Phase 4 | Engine nilai akhir berbobot 2 desimal · Validasi pra-publish · Publish rapor + penguncian nilai · Generator PDF via queue · **Uji PDF rapor** | **MS-4** Siklus Akademik Lengkap |
| **W09** | S5 | Phase 5a | CRUD `fee_types` · Job generate tagihan massal + preview wajib · Due date otomatis | — |
| **W10** | S5 | Phase 5a | Catat pembayaran + upload bukti · Akumulasi `amount_paid` · Transisi status `UNPAID`/`PARTIAL`/`PAID` · Cicilan · Pembebasan `WAIVED` | **MS-5** Siklus Keuangan Lengkap |
| **W11** | S6 | Phase 5b | CRUD `transactions` (`INCOME`/`EXPENSE`) + kategori + lampiran bukti | — |
| **W12** | S6 | Phase 8 | Dashboard keuangan cabang + **grafik tren 6 bulan** · Dashboard Super Admin lintas cabang + % lunas · Export laporan tagihan & keuangan · `/finance/summary`, `/finance/spp-report` · Verifikasi ulang seluruh export | **MS-6** Pelaporan & Dashboard Siap |
| **W13** | S7 | Phase 6 | Parent Portal: dashboard anak, switch antar anak, halaman nilai/tagihan/jadwal · Responsive mobile | — |
| **W14** | S7 | Phase 6 | Portal Guru (jadwal hari ini + 3 shortcut) · Portal Siswa (4 menu) · Verifikasi pembatasan data per pengguna | **MS-7** Portal Pengguna Aktif |
| **W15** | S8 | Phase 7 | CRUD notifikasi bertarget `ALL`/`CLASS`/`INDIVIDUAL` · Draft & terkirim · wa.me bulk generator · Editor template WA | — |
| **W16** | S8 | Phase 7 | **Tiga trigger otomatis** (PPDB, tagihan, rapor) · Bell icon + badge unread + `notification_reads` · Retensi 90 hari · Uji encoding menyeluruh | **MS-8** Sistem Komunikasi Aktif |
| **W17** | S9 | Phase 9 | Penyelesaian bilingual ID/EN · Toggle bahasa · Verifikasi responsive mobile seluruh portal · Uji performa halaman & API | — |
| **W18** | S9 | Phase 9 + go-live Phase 10 | **Load test 200 user konkuren** · Security audit · Regresi unit test isolasi 100% · Uji lintas-tenant · Uji restore backup · Password default diganti · CORS dikunci · Monitoring uptime · Bug fixing · **Checklist go-live 10 butir** | **MS-9** Release Candidate<br>**MS-10** Go-Live |

### 10.3 Distribusi Beban per Kelompok Pekerjaan

| Kelompok | Minggu | Porsi dari 18 Minggu |
|---|:---:|:---:|
| Fondasi (Phase 0 + 1) | W01–W02 | 11% |
| Data induk (Phase 2) | W03–W04 | 11% |
| Modul bisnis (Phase 3, 4, 5, 8) | W05–W12 | 44% |
| Antarmuka pengguna (Phase 6, 7) | W13–W16 | 22% |
| QA & rilis (Phase 9, 10) | W17–W18 | 11% |

### 10.4 Timeline Alternatif — 2 Developer

Blueprint menyatakan pengerjaan *"dapat dipercepat dengan 2 developer (paralel Sprint 3–4 dan Sprint 5–6)"* (Lampiran A.1). Hanya dua pasangan itu yang diizinkan diparalelkan (CON-52).

```
                      W01 W02 W03 W04 W05 W06 W07 W08 W09 W10 W11 W12 W13 W14
Dev A                 |--S1--||--S2--||--S3--||--S5--||--S7--||--S8--||--S9--|
Dev B                                 |--S4--||--S6--|

P0+P1 · Fondasi       ████████
P2 · Master Data              ████████
P3 · PPDB      (Dev A)                ████████
P4 · Academic  (Dev B)                ████████
P5 · Finance   (Dev A)                        ████████
P8 · Reporting (Dev B)                        ████████
P6 · Portal                                           ████████
P7 · Notification                                             ████████
P9 · Testing                                                          ████████
```

| Skenario | Total Durasi | Dasar |
|---|:---:|---|
| 1 developer full-stack | **18 minggu** | Lampiran A.1 — dinyatakan eksplisit |
| 2 developer (paralel S3∥S4, S5∥S6) | ~14 minggu | Implikasi aritmetika dari paralelisasi 2 × 2 minggu. **Angka total ini tidak dinyatakan blueprint** — blueprint hanya menyebut "dapat dipercepat" tanpa menyebut durasi hasilnya |

> **Peringatan pada skenario paralel:** Sprint 5 (Keuangan) dan Sprint 6 (Akuntansi & Pelaporan) memiliki ketergantungan data satu arah — Phase 8 membutuhkan data tagihan dan pembayaran dari Phase 5 ([§5.3](#53-matriks-dependency-antar-fase)). Paralelisasi keduanya memerlukan koordinasi kontrak data antar developer. Cara penanganannya: **Belum dijelaskan dalam blueprint.**

### 10.5 Timeline Pasca Go-Live

```
        Go-Live          Bulan 1          Bulan 2          Bulan 3        Gerbang Phase 2
           │                │                │                │                │
    ───────●────────────────┼────────────────┼────────────────┼────────────────●───────▶
         MS-10          Stabilisasi & pemantauan uptime ≥ 99%/bulan           MS-11
                        Backup harian · Monitoring · Maintenance
```

| Periode | Aktivitas | Sumber |
|---|---|---|
| Go-live → +3 bulan | Stabilisasi, pemantauan uptime ≥ 99%/bulan, backup harian, auto-renew SSL, pemantauan kapasitas disk | NFR-08, NFR-09; §2.6 |
| Setelah +3 bulan | **MS-11 — Gerbang Phase 2**. Phase 2 baru boleh dimulai | CON-54; SM-13 |

> Jadwal rilis patch, SLA support, dan prosedur incident response pada periode ini: **Belum dijelaskan dalam blueprint.**

---

## 11. Sprint Deliverables Register

Register ini merinci setiap deliverable yang sudah tercantum pada [§4 Sprint Planning](#4-sprint-planning), dilengkapi **kode deliverable**, jenis, cara verifikasi, dan pihak yang menerima. **Isi deliverable tidak berubah** — register ini hanya menambahkan cara melacaknya.

**Jenis deliverable:** `INFRA` (infrastruktur) · `KODE` (fitur aplikasi) · `DATA` (skema/migrasi) · `TEST` (bukti pengujian) · `DOK` (dokumen)

---

### 11.1 Sprint 1 — Foundation (W01–W02)

| ID | Deliverable | Jenis | Cara Verifikasi | Penerima |
|---|---|:---:|---|---|
| DLV-S1-01 | VPS + Nginx + PHP-FPM + MySQL + Supervisor + Certbot aktif | INFRA | Akses `https://apps.smartsukses.sch.id` berhasil; `systemctl status` seluruh layanan aktif | Tim Teknis |
| DLV-S1-02 | Tabel `schools`, `users`, `roles`, `model_has_roles` | DATA | Migrasi berjalan; struktur sesuai ERD §2.2 | Tim Teknis |
| DLV-S1-03 | Global Scope `school_id` + bypass Super Admin | KODE | Inspeksi kode + DLV-S1-04 | Tim Teknis |
| DLV-S1-04 | **Unit test tenant isolation lulus 100%** | TEST | Laporan hasil test; **gerbang wajib** (Lampiran A.3 #1) | Pemilik Produk |
| DLV-S1-05 | Login, reset password, ganti password | KODE | AC AUTH-01, AUTH-04 terpenuhi | Pemilik Produk |
| DLV-S1-06 | 8 role + matriks izin 15 modul | KODE | Uji login tiap role; menu sesuai PRD §8.2 | Pemilik Produk |
| DLV-S1-07 | CSS variables white-label ter-inject dinamis | KODE | Login 3 cabang menghasilkan 3 tampilan berbeda; ubah warna tanpa deploy ulang | Pemilik Produk |
| DLV-S1-08 | CRUD user + import Excel massal | KODE | AC PORTAL-04 terpenuhi | Admin Sekolah |
| DLV-S1-09 | CRUD cabang oleh Super Admin | KODE | Cabang baru dapat dibuat tanpa perubahan kode | Super Admin |
| DLV-S1-10 | Audit log berjalan | KODE | Aksi CUD tercatat: user, action, table, id, timestamp, IP | Tim Teknis |
| DLV-S1-11 | Backup harian terjadwal 02:00 WIB | INFRA | Cron aktif; berkas backup terbentuk | Tim Teknis |
| DLV-S1-12 | Kerangka translation key ID/EN | KODE | Tidak ada string keras pada kode sprint ini | Tim Teknis |

**Gerbang keluar Sprint 1:** DLV-S1-04 wajib lulus 100% sebelum Sprint 2 dimulai.

---

### 11.2 Sprint 2 — Core SIS (W03–W04)

| ID | Deliverable | Jenis | Cara Verifikasi | Penerima |
|---|---|:---:|---|---|
| DLV-S2-01 | CRUD siswa + validasi NISN 10 digit + NIS unik per sekolah | KODE | AC SIS-01; uji NIS sama di dua cabang harus lolos | Admin Sekolah |
| DLV-S2-02 | Soft update & nonaktifkan siswa | KODE | AC SIS-02; data tidak hilang dari database | Admin Sekolah |
| DLV-S2-03 | Upload foto (maks 2 MB → resize 400×400) | KODE | AC SIS-03; uji file >2 MB ditolak | Admin Sekolah |
| DLV-S2-04 | Export siswa `.xlsx` + import massal | KODE | Nama file `siswa_[kode_sekolah]_[tanggal].xlsx`; import mengembalikan error per baris | Admin Sekolah |
| DLV-S2-05 | CRUD tahun ajaran + aktivasi tunggal | KODE | Aktivasi satu TA menonaktifkan yang lain (CON-37) | Admin Sekolah |
| DLV-S2-06 | CRUD mata pelajaran | KODE | Mapel dapat berbeda antar cabang | Admin Sekolah |
| DLV-S2-07 | CRUD kelas + penetapan wali kelas | KODE | Uji 1 guru = 1 kelas/TA ditolak sistem (CON-36) | Admin Sekolah |
| DLV-S2-08 | Penempatan siswa ke kelas | KODE | Uji 1 siswa = 1 kelas/TA ditolak sistem (CON-35) | Admin Sekolah |
| DLV-S2-09 | Penetapan guru pengampu per mapel per kelas (`class_subjects`) | KODE | Relasi terbentuk sesuai ERD | Admin Sekolah |
| DLV-S2-10 | Jadwal + **deteksi konflik guru/ruang/kelas** | KODE | Uji tiga jenis konflik ditolak (CON-48) | Admin Sekolah |
| DLV-S2-11 | Tampilan jadwal mingguan guru | KODE | AC KELAS-04; guru hanya melihat jadwalnya sendiri | Guru |
| DLV-S2-12 | Unit test CON-35, CON-36, CON-37, CON-38, CON-48 | TEST | Seluruh test lulus | Tim Teknis |

**Catatan:** AC-SIS-08 (status kehadiran hari ini) berstatus *pending* — lihat RSK-01.

---

### 11.3 Sprint 3 — PPDB (W05–W06)

| ID | Deliverable | Jenis | Cara Verifikasi | Penerima |
|---|---|:---:|---|---|
| DLV-S3-01 | Halaman publik `/ppdb/[kode_sekolah]` tanpa login | KODE | Akses dalam mode incognito berhasil | Pemilik Produk |
| DLV-S3-02 | Generator `reg_number` `[KODE_CABANG]-[TAHUN]-[SEQ]` | KODE | Nomor unik dan berformat benar | Admin Sekolah |
| DLV-S3-03 | Upload dokumen pendaftaran | KODE | Path tersimpan pada kolom JSON `documents` | Admin Sekolah |
| DLV-S3-04 | Halaman cek status publik (no. daftar + tgl lahir) | KODE | AC PPDB-02; kelima status tampil benar | Pemilik Produk |
| DLV-S3-05 | Panel admin: tabel, filter status, ubah status + catatan alasan | KODE | AC PPDB-03; `status_notes` tersimpan | Admin Sekolah |
| DLV-S3-06 | **Komponen generator link wa.me** (dipakai ulang Sprint 8) | KODE | URL `wa.me/62[nomorHP]?text=` terbentuk benar | Tim Teknis |
| DLV-S3-07 | Template teks WA per perubahan status | KODE | Template dapat diedit Admin Sekolah | Admin Sekolah |
| DLV-S3-08 | Enroll satu klik → record `students` + `converted_student_id` | KODE | AC PPDB-05; status berubah `ENROLLED` | Admin Sekolah |
| DLV-S3-09 | Landing publik daftar cabang yang membuka PPDB | KODE | `GET /ppdb/schools` mengembalikan daftar cabang | Pemilik Produk |
| DLV-S3-10 | Uji encoding wa.me lulus | TEST | Lampiran A.3 #4 untuk seluruh template PPDB | Pemilik Produk |

---

### 11.4 Sprint 4 — Akademik (W07–W08)

| ID | Deliverable | Jenis | Cara Verifikasi | Penerima |
|---|---|:---:|---|---|
| DLV-S4-01 | CRUD `grade_configs` dengan bobot berbeda per mapel | KODE | AC NILAI-05; konfigurasi berbeda antar mapel | Admin Sekolah |
| DLV-S4-02 | Input nilai satuan, massal per `class_subject`, dan import Excel | KODE | AC NILAI-01; skala 0–100 tervalidasi | Guru |
| DLV-S4-03 | Pembatasan: guru hanya menilai kelas yang diampunya | KODE | Uji guru lain ditolak (`POST /grades`) | Tim Teknis |
| DLV-S4-04 | Engine nilai akhir berbobot, pembulatan 2 desimal | KODE | Unit test formula NILAI-02 | Pemilik Produk |
| DLV-S4-05 | Validasi pra-publish: semua mapel wajib punya nilai akhir | KODE | Publish ditolak bila ada mapel kosong (CON-41) | Wali Kelas |
| DLV-S4-06 | Publish rapor oleh Wali Kelas + penguncian nilai | KODE | Nilai tidak dapat diedit setelah publish (CON-40) | Wali Kelas |
| DLV-S4-07 | Tampilan nilai real-time untuk siswa & ortu | KODE | Nilai tampil segera setelah guru menyimpan | Siswa, Orang Tua |
| DLV-S4-08 | Generator rapor PDF via queue | KODE | PDF terbentuk tanpa timeout untuk satu kelas | Wali Kelas |
| DLV-S4-09 | **Uji PDF rapor: format & data sesuai** | TEST | Lampiran A.3 #5 | Pemilik Produk |

**Catatan:** rekap kehadiran pada rapor dan `rank_in_class` berstatus *pending* — lihat RSK-01 dan RSK-09.

---

### 11.5 Sprint 5 — Keuangan (W09–W10)

| ID | Deliverable | Jenis | Cara Verifikasi | Penerima |
|---|---|:---:|---|---|
| DLV-S5-01 | CRUD `fee_types` + nonaktif tanpa hapus histori | KODE | AC SPP-01; histori tetap utuh (CON-45) | Bendahara |
| DLV-S5-02 | Job generate tagihan massal via queue | KODE | Seluruh siswa aktif memperoleh `student_fees` tanpa timeout | Bendahara |
| DLV-S5-03 | **Preview daftar tagihan sebelum konfirmasi** | KODE | Generate tidak dapat dieksekusi tanpa preview (CON-47) | Bendahara |
| DLV-S5-04 | Due date otomatis | KODE | AC SPP-02 butir 2 | Bendahara |
| DLV-S5-05 | Form catat pembayaran + upload bukti (JPG/PNG/PDF maks 5 MB) | KODE | AC SPP-03; file >5 MB ditolak (CON-44) | Bendahara |
| DLV-S5-06 | Akumulasi `amount_paid` + transisi status `UNPAID`/`PARTIAL`/`PAID` | KODE | Unit test akumulasi & transisi | Bendahara |
| DLV-S5-07 | Dukungan cicilan (1 tagihan, banyak pembayaran) | KODE | Dua pembayaran parsial menghasilkan status `PAID` saat lunas | Bendahara |
| DLV-S5-08 | Pembebasan tagihan → `WAIVED` + alasan | KODE | `PATCH /student-fees/{id}/waive`; `waive_reason` tersimpan | Admin Sekolah |
| DLV-S5-09 | Data tagihan siap dikonsumsi Parent Portal | KODE | Endpoint `GET /students/{id}/fees` mengembalikan data benar | Tim Teknis |

**Catatan:** perilaku generate untuk frekuensi `YEARLY` dan `ONCE` berstatus *pending* — lihat RSK-10.

---

### 11.6 Sprint 6 — Akuntansi & Pelaporan (W11–W12)

| ID | Deliverable | Jenis | Cara Verifikasi | Penerima |
|---|---|:---:|---|---|
| DLV-S6-01 | CRUD `transactions` (`INCOME`/`EXPENSE`) + kategori + lampiran bukti | KODE | AC KAS-01 | Bendahara |
| DLV-S6-02 | Dashboard keuangan cabang: saldo kas, penerimaan SPP, pengeluaran bulan ini | KODE | AC KAS-02 butir 1 | Kepala Sekolah |
| DLV-S6-03 | **Grafik tren 6 bulan terakhir** | KODE | AC KAS-02 butir 2 | Kepala Sekolah |
| DLV-S6-04 | Dashboard Super Admin lintas cabang: total tagihan, terkumpul, **% lunas** | KODE | AC KAS-03; data seluruh cabang tampil (bypass Global Scope) | Super Admin |
| DLV-S6-05 | Filter tahun ajaran / bulan pada dashboard Super Admin | KODE | AC KAS-03 butir 2 | Super Admin |
| DLV-S6-06 | Export laporan tagihan `.xlsx` (filter kelas/periode/status) | KODE | Kolom sesuai AC SPP-05 | Bendahara |
| DLV-S6-07 | Export laporan keuangan `.xlsx` | KODE | `GET /finance/export` menghasilkan berkas valid | Bendahara |
| DLV-S6-08 | Endpoint `/finance/summary` dan `/finance/spp-report` | KODE | Response sesuai konvensi API PRD §11.4 | Tim Teknis |
| DLV-S6-09 | Verifikasi ulang seluruh export sprint sebelumnya (SIS-05, PDF rapor) | TEST | Berkas dapat dibuka; kolom sesuai spesifikasi | Pemilik Produk |

---

### 11.7 Sprint 7 — Portal (W13–W14)

| ID | Deliverable | Jenis | Cara Verifikasi | Penerima |
|---|---|:---:|---|---|
| DLV-S7-01 | Dashboard ortu: nilai terbaru, kehadiran bulan ini, tagihan belum lunas | KODE | AC PORTAL-01 butir 1 | Orang Tua |
| DLV-S7-02 | Pemilih profil anak untuk ortu dengan >1 anak | KODE | AC PORTAL-01 butir 2 | Orang Tua |
| DLV-S7-03 | Parent Portal responsive mobile | KODE | Uji pada viewport mobile (NFR-10) | Orang Tua |
| DLV-S7-04 | Halaman nilai, tagihan, dan jadwal anak | KODE | Endpoint `/parent/children/{id}/*` berfungsi | Orang Tua |
| DLV-S7-05 | Dashboard guru: jadwal hari ini + 3 shortcut | KODE | AC PORTAL-02 | Guru |
| DLV-S7-06 | Daftar kelas ajar guru | KODE | `GET /teacher/classes` hanya kelas yang diampu | Guru |
| DLV-S7-07 | Portal siswa: Jadwal, Nilai, Notifikasi, Profil | KODE | AC PORTAL-03 butir 1 | Siswa |
| DLV-S7-08 | Nilai siswa per mapel / semester / komponen | KODE | AC PORTAL-03 butir 2 | Siswa |
| DLV-S7-09 | Verifikasi pembatasan data per pengguna | TEST | Siswa hanya melihat dirinya; ortu hanya anaknya; guru hanya kelas ajarnya | Tim Teknis |

**Catatan:** "kehadiran bulan ini" (DLV-S7-01) berstatus *pending* — lihat RSK-01. Jumlah nilai terbaru (3 vs 5) menunggu keputusan — lihat RSK-04.

---

### 11.8 Sprint 8 — Notifikasi (W15–W16)

| ID | Deliverable | Jenis | Cara Verifikasi | Penerima |
|---|---|:---:|---|---|
| DLV-S8-01 | CRUD notifikasi dengan target `ALL`/`CLASS`/`INDIVIDUAL` + kategori | KODE | AC NOTIF-01 | Admin Sekolah |
| DLV-S8-02 | Status draft dan terkirim (`is_draft`, `sent_at`) | KODE | Transisi status tersimpan benar | Admin Sekolah |
| DLV-S8-03 | Generator wa.me bulk per penerima + filter + salin + tombol "Buka WA" | KODE | AC NOTIF-02 | Admin Sekolah |
| DLV-S8-04 | Editor template WA (`wa_template_ppdb`, `wa_template_spp`, `wa_template_rapor`) | KODE | Template dapat diedit dan tersimpan di tabel `schools` | Admin Sekolah |
| DLV-S8-05 | Trigger otomatis pada perubahan status PPDB | KODE | Uji end-to-end dari Sprint 3 | Pemilik Produk |
| DLV-S8-06 | Trigger otomatis pada penerbitan tagihan | KODE | Uji end-to-end dari Sprint 5 | Pemilik Produk |
| DLV-S8-07 | Trigger otomatis pada penerbitan rapor | KODE | Uji end-to-end dari Sprint 4 | Pemilik Produk |
| DLV-S8-08 | Bell icon + badge unread + `notification_reads` di seluruh portal | KODE | AC NOTIF-04 butir 1–2 | Semua pengguna |
| DLV-S8-09 | Retensi riwayat notifikasi 90 hari | KODE | Mekanisme pembersihan aktif (CON-46) | Tim Teknis |
| DLV-S8-10 | Uji encoding wa.me seluruh template | TEST | Lampiran A.3 #4 menyeluruh | Pemilik Produk |

---

### 11.9 Sprint 9 — Polish & QA (W17–W18)

| ID | Deliverable | Jenis | Cara Verifikasi | Penerima |
|---|---|:---:|---|---|
| DLV-S9-01 | Seluruh label, error, dan placeholder tersedia ID + EN | KODE | AC AUTH-05 butir 3 | Pemilik Produk |
| DLV-S9-02 | Toggle bahasa di navbar + preferensi tersimpan di `users.locale` | KODE | AC AUTH-05 butir 1–2 | Semua pengguna |
| DLV-S9-03 | Seluruh portal terverifikasi responsive mobile | TEST | Uji pada viewport mobile (NFR-10) | Pemilik Produk |
| DLV-S9-04 | **Load test 200 user konkuren lulus tanpa error/timeout** | TEST | Lampiran A.3 #3 — **gerbang wajib** | Pemilik Produk |
| DLV-S9-05 | Verifikasi load halaman < 3 detik dan API < 500 ms (p95) | TEST | NFR-01, NFR-02 | Tim Teknis |
| DLV-S9-06 | Laporan security audit + tindak lanjut | DOK | Rate limit, CSRF, HTTPS/HSTS, upload, password, CORS | Pemilik Produk |
| DLV-S9-07 | **Unit test tenant isolation lulus 100% (regresi)** | TEST | Lampiran A.3 #1 — **gerbang wajib** | Pemilik Produk |
| DLV-S9-08 | Uji manual lintas-tenant: nol pelanggaran | TEST | Lampiran A.3 #2 — **gerbang wajib** | Pemilik Produk |
| DLV-S9-09 | Uji encoding wa.me & PDF rapor menyeluruh | TEST | Lampiran A.3 #4, #5 | Pemilik Produk |
| DLV-S9-10 | Uji restore backup berhasil | TEST | Lampiran A.3 #7 | Tim Teknis |
| DLV-S9-11 | Password default diganti; CORS dikunci; monitoring uptime aktif | INFRA | Lampiran A.3 #8, #9, #10 | Tim Teknis |
| DLV-S9-12 | **Checklist go-live 10 butir tertandatangani lengkap** | DOK | CON-56 — **gerbang mutlak go-live** | Pemilik Produk |

---

### 11.10 Rekapitulasi Deliverable

| Sprint | Jumlah | INFRA | KODE | DATA | TEST | DOK |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| Sprint 1 | 12 | 2 | 8 | 1 | 1 | 0 |
| Sprint 2 | 12 | 0 | 11 | 0 | 1 | 0 |
| Sprint 3 | 10 | 0 | 9 | 0 | 1 | 0 |
| Sprint 4 | 9 | 0 | 8 | 0 | 1 | 0 |
| Sprint 5 | 9 | 0 | 9 | 0 | 0 | 0 |
| Sprint 6 | 9 | 0 | 8 | 0 | 1 | 0 |
| Sprint 7 | 9 | 0 | 8 | 0 | 1 | 0 |
| Sprint 8 | 10 | 0 | 9 | 0 | 1 | 0 |
| Sprint 9 | 12 | 1 | 2 | 0 | 7 | 2 |
| **TOTAL** | **92** | **3** | **72** | **1** | **14** | **2** |

### 11.11 Deliverable Berstatus Gerbang Wajib

Deliverable berikut memblokir kelanjutan pekerjaan bila belum terpenuhi:

| ID | Deliverable | Memblokir | Dasar |
|---|---|---|---|
| DLV-S1-04 | Unit test tenant isolation lulus 100% | Sprint 2 dan seterusnya | Lampiran A.3 #1; CON-26 |
| DLV-S4-09 | Uji PDF rapor sesuai | MS-4 | Lampiran A.3 #5 |
| DLV-S5-03 | Preview sebelum generate tagihan massal | MS-5 | CON-47 |
| DLV-S9-04 | Load test 200 user konkuren | MS-9 | Lampiran A.3 #3 |
| DLV-S9-07 | Regresi unit test isolasi 100% | MS-9 | Lampiran A.3 #1 |
| DLV-S9-08 | Uji lintas-tenant nol pelanggaran | MS-9 | Lampiran A.3 #2 |
| DLV-S9-12 | Checklist go-live 10 butir lengkap | **MS-10 Go-Live** | CON-56 |

### 11.12 Deliverable Berstatus *Pending* karena Isu Terbuka

| Deliverable terdampak | Bagian yang tertunda | Isu |
|---|---|---|
| DLV-S2-01…12 (modul SIS) | "Status kehadiran hari ini" pada daftar siswa kelas ajar | RSK-01 |
| DLV-S4-08 (rapor PDF) | Rekap kehadiran (`attend_*`) dan `rank_in_class` | RSK-01, RSK-09 |
| DLV-S5-02 (generate massal) | Perilaku frekuensi `YEARLY` dan `ONCE` | RSK-10 |
| DLV-S7-01 (dashboard ortu) | "Kehadiran bulan ini" dan jumlah nilai terbaru (3 vs 5) | RSK-01, RSK-04 |

> Deliverable dengan bagian *pending* **tidak boleh ditandai selesai 100%** (DoD §9.2). Statusnya adalah *selesai kecuali butir tersebut*, dan butirnya wajib tercatat pada daftar isu terbuka.

---

## 12. Prioritas Modul

### 12.1 Dasar Penilaian

Prioritas modul diturunkan dari tiga sumber yang **sudah ada** — tidak ada penilaian baru di luar blueprint dan PRD:

| Sumber | Isi |
|---|---|
| **MoSCoW per FR** | Ditetapkan pada PRD §9 (`Must Have` / `Should Have`) |
| **Nilai bisnis** | Objective OBJ-01…08 dan Problem Statement P-01…06 pada PRD §3 dan §4 |
| **Kekritisan teknis** | Jumlah modul yang bergantung padanya ([§5.3](#53-matriks-dependency-antar-fase)) |

**Tingkat prioritas:**

| Tingkat | Nama | Arti |
|:---:|---|---|
| **P0** | Fondasi / Kritis | Seluruh modul lain menggantung padanya. Tidak boleh ditunda, tidak boleh dikerjakan sebagian |
| **P1** | Tinggi | Menjawab masalah inti (P-01…P-06) dan menjadi prasyarat modul lain |
| **P2** | Sedang | Menjawab masalah inti tetapi tidak menjadi prasyarat modul lain |
| **P3** | Pelengkap | Bernilai tambah; satu-satunya kelompok yang punya ruang fleksibilitas jadwal |

### 12.2 Prioritas per Modul

| Modul | FR | MoSCoW (PRD) | Nilai Bisnis | Kekritisan Teknis | **Prioritas** | Fase |
|---|---|:---:|:---:|:---:|:---:|:---:|
| **Core Platform** — multi-tenant, auth, RBAC, white-label, audit | AUTH-01…04, PORTAL-04 | Must | Tinggi (OBJ-07) | **Tertinggi** — 100% modul bergantung | **P0** | 0, 1 |
| **Tahun Ajaran** | — | Must | Sedang | Tinggi — 8 modul bergantung | **P0** | 2 |
| **SIS — Data Siswa** | SIS-01, SIS-02, SIS-04 | Must | Tinggi (OBJ-01, P-01) | Tinggi — 7 modul bergantung | **P1** | 2 |
| **Kelas, Mapel & Jadwal** | KELAS-01…04 | Must | Sedang | Tinggi — 5 modul bergantung | **P1** | 2 |
| **Penilaian & E-Rapor** | NILAI-01…05 | Must | Tinggi (OBJ-05) | Sedang — Portal & Notifikasi bergantung | **P1** | 4 |
| **Tagihan & Pembayaran SPP** | SPP-01…04 | Must | Tinggi (OBJ-04, P-04) | Sedang — Portal & Pelaporan bergantung | **P1** | 5 |
| **PPDB Online** | PPDB-01…05 | Must | Tinggi (OBJ-03, P-03) | Rendah — hanya menulis ke SIS | **P2** | 3 |
| **Buku Kas** | KAS-01 | Must | Sedang | Rendah — hanya Pelaporan bergantung | **P2** | 5 |
| **Pelaporan & Dashboard** | KAS-02, KAS-03 | Must | Tinggi (OBJ-02, P-02) | Rendah — konsumen akhir | **P2** | 8 |
| **Parent Portal** | PORTAL-01 | Must | Tinggi (OBJ-05, P-05) | Rendah — konsumen akhir | **P2** | 6 |
| **Portal Siswa** | PORTAL-03 | Must | Sedang (OBJ-05) | Rendah — konsumen akhir | **P2** | 6 |
| **Portal Guru** | PORTAL-02 | Must | Sedang | Rendah — konsumen akhir | **P2** | 6 |
| **Notifikasi & wa.me** | NOTIF-01…04 | Must | Tinggi (OBJ-06, P-06) | Rendah — konsumen akhir | **P2** | 7 |
| **Upload Foto Siswa** | SIS-03 | **Should** | Rendah | Tidak ada | **P3** | 2 |
| **Export Data Siswa** | SIS-05 | **Should** | Rendah | Tidak ada | **P3** | 2 |
| **Export Laporan Tagihan** | SPP-05 | **Should** | Sedang | Tidak ada | **P3** | 8 |
| **Bilingual EN** | AUTH-05 | Must | Rendah (NFR-11) | Tidak ada | **P3** | 9 |

### 12.3 Ringkasan Distribusi Prioritas

| Prioritas | Jumlah Modul | Modul |
|:---:|:---:|---|
| **P0** | 2 | Core Platform · Tahun Ajaran |
| **P1** | 4 | SIS · Kelas & Jadwal · Penilaian & E-Rapor · Tagihan SPP |
| **P2** | 7 | PPDB · Buku Kas · Pelaporan · Parent Portal · Portal Siswa · Portal Guru · Notifikasi |
| **P3** | 4 | Upload Foto · Export Siswa · Export Tagihan · Bilingual EN |

### 12.4 Implikasi Prioritas terhadap Pengelolaan Jadwal

**Temuan penting:** dari 38 functional requirement pada PRD, **hanya 3 yang berstatus `Should Have`** — SIS-03, SIS-05, dan SPP-05. Seluruh sisanya `Must Have`.

| Implikasi | Penjelasan |
|---|---|
| **Ruang fleksibilitas jadwal hampir nol** | Bila terjadi keterlambatan, satu-satunya cakupan yang secara sah dapat ditunda tanpa melanggar requirement adalah tiga FR `Should Have` tersebut — dan itu pun hanya menghemat sebagian kecil pekerjaan |
| **Pengurangan cakupan lain = perubahan requirement** | Menunda FR `Must Have` mana pun berarti mengubah scope, dan **wajib melalui revisi blueprint** (CON-55) — bukan keputusan sepihak tim pengembang |
| **P0 tidak boleh dikompromikan** | Global Scope `school_id` yang dikerjakan sebagian melanggar CON-26 (toleransi kebocoran data nol) dan menjalar ke seluruh modul |
| **P3 Bilingual EN adalah `Must Have` yang dijadwalkan terakhir** | AUTH-05 berstatus `Must Have`, namun blueprint menjadwalkannya di Sprint 9. Prioritas eksekusinya rendah, tetapi **tidak boleh dihapus dari cakupan** |

### 12.5 Urutan Prioritas Eksekusi

Urutan eksekusi mengikuti sprint blueprint, bukan semata tingkat prioritas — karena dependency teknis mengunci sebagian urutan:

```
P0 Core Platform ─┬─▶ P0 Tahun Ajaran ─▶ P1 SIS ─┬─▶ P1 Kelas & Jadwal ─▶ P1 Penilaian & E-Rapor
                  │                              │
                  │                              ├─▶ P2 PPDB
                  │                              │
                  │                              └─▶ P1 Tagihan SPP ─▶ P2 Buku Kas ─▶ P2 Pelaporan
                  │
                  └──────────────────────────────────────────▶ P2 Portal ─▶ P2 Notifikasi ─▶ P3 Bilingual
```

**Catatan:** P2 PPDB dieksekusi pada Sprint 3 — lebih awal daripada beberapa modul P1 — karena blueprint menempatkannya demikian, dan karena PPDB adalah pintu masuk data siswa baru setiap tahun ajaran. Roadmap ini **tidak mengubah urutan blueprint**.

### 12.6 Prioritas Penanganan Isu Terbuka

Isu terbuka diprioritaskan berdasarkan tingkat modul yang diblokirnya:

| Prioritas | Isu | Modul Terdampak | Batas Waktu |
|:---:|---|---|---|
| **P0** | Mekanisme autentikasi (JWT vs Sanctum) — RSK-11 | Core Platform | Sprint 1 |
| **P0** | Struktur tabel `audit_logs` — RSK-02 | Core Platform | Sprint 1 |
| **P1** | Sumber data kehadiran — RSK-01 | SIS, E-Rapor, Parent Portal | Sebelum Sprint 2 |
| **P1** | Status `INACTIVE` pada `students.status` — RSK-05 | SIS | Sebelum Sprint 2 |
| **P1** | Presedensi bobot nilai — RSK-07 | Penilaian | Sebelum Sprint 4 |
| **P1** | Generate tagihan `YEARLY`/`ONCE` — RSK-10 | Tagihan SPP | Sebelum Sprint 5 |
| **P2** | Normalisasi nomor HP — RSK-14 | PPDB, Notifikasi | Sebelum Sprint 3 |
| **P2** | Kewenangan guru buat pengumuman — RSK-03 | Portal Guru, Notifikasi | Sebelum Sprint 7 |
| **P3** | Format upload foto WEBP — RSK-12 | Upload Foto (Should Have) | Sebelum Sprint 2 |
| **P3** | Jumlah nilai dashboard ortu (3 vs 5) — RSK-04 | Parent Portal | Sebelum Sprint 7 |

---

## 13. Risk Matrix (Impact vs Probability)

Matriks ini memetakan **32 risiko** yang sudah didaftar pada [§6](#6-risks) ke dalam kisi Dampak × Probabilitas. **Tidak ada risiko baru** — hanya visualisasi dan pemeringkatan dari penilaian yang sudah ada.

### 13.1 Skala Penilaian

| Probabilitas | Bobot | | Dampak | Bobot |
|---|:---:|---|---|:---:|
| Tinggi | 3 | | Kritis | 4 |
| Sedang | 2 | | Tinggi | 3 |
| Rendah | 1 | | Sedang | 2 |
| | | | Rendah | 1 |

**Skor Risiko = Probabilitas × Dampak** (rentang 1–12)

| Skor | Zona | Warna | Strategi Respons |
|:---:|---|:---:|---|
| 9–12 | **Kritis** | 🔴 | Eskalasi ke pemilik blueprint · mitigasi segera · pantau setiap sprint |
| 6–8 | **Tinggi** | 🟠 | Mitigasi terjadwal · verifikasi pada milestone terkait |
| 3–5 | **Sedang** | 🟡 | Pantau · mitigasi bila muncul gejala |
| 1–2 | **Rendah** | 🟢 | Terima · tinjau ulang bila kondisi berubah |

### 13.2 Matriks Risiko

```
                                     D A M P A K
                  ┌───────────────┬───────────────┬───────────────┬───────────────┐
                  │    RENDAH     │    SEDANG     │    TINGGI     │    KRITIS     │
                  │      (1)      │      (2)      │      (3)      │      (4)      │
  ┌───────────────┼───────────────┼───────────────┼───────────────┼───────────────┤
  │               │   🟡 skor 3   │   🟠 skor 6   │   🔴 skor 9   │  🔴 skor 12   │
  │  TINGGI  (3)  │               │    RSK-02     │    RSK-01     │       —       │
  │               │    RSK-04     │    RSK-03     │    RSK-25     │               │
P │               │               │    RSK-05     │               │               │
R │               │               │    RSK-14     │               │               │
O │               │               │    RSK-28     │               │               │
B │               │               │    RSK-29     │               │               │
A ├───────────────┼───────────────┼───────────────┼───────────────┼───────────────┤
B │               │   🟡 skor 2   │   🟡 skor 4   │   🟠 skor 6   │   🟠 skor 8   │
I │               │    RSK-09     │ RSK-06 RSK-19 │    RSK-16     │  RSK-15  ⚠    │
L │  SEDANG  (2)  │    RSK-11     │ RSK-07 RSK-20 │    RSK-17     │               │
I │               │    RSK-12     │ RSK-08 RSK-21 │    RSK-18     │               │
T │               │               │ RSK-10 RSK-22 │    RSK-26     │               │
A │               │               │ RSK-13 RSK-31 │    RSK-27     │               │
S │               │               │    RSK-32     │    RSK-30     │               │
  ├───────────────┼───────────────┼───────────────┼───────────────┼───────────────┤
  │               │   🟢 skor 1   │   🟡 skor 2   │   🟡 skor 3   │   🟡 skor 4   │
  │  RENDAH  (1)  │    RSK-24     │       —       │    RSK-23     │       —       │
  │               │               │               │               │               │
  └───────────────┴───────────────┴───────────────┴───────────────┴───────────────┘

  ⚠ RSK-15 dieskalasi ke zona KRITIS meskipun skornya 8 — lihat §13.4
```

### 13.3 Sebaran Risiko per Zona

| Zona | Jumlah | Persentase | Risiko |
|---|:---:|:---:|---|
| 🔴 **Kritis** | 3 | 9% | RSK-01, RSK-25, **RSK-15** (eskalasi) |
| 🟠 **Tinggi** | 12 | 38% | RSK-02, RSK-03, RSK-05, RSK-14, RSK-16, RSK-17, RSK-18, RSK-26, RSK-27, RSK-28, RSK-29, RSK-30 |
| 🟡 **Sedang** | 13 | 41% | RSK-04, RSK-06, RSK-07, RSK-08, RSK-10, RSK-13, RSK-19, RSK-20, RSK-21, RSK-22, RSK-23, RSK-31, RSK-32 |
| 🟢 **Rendah** | 4 | 12% | RSK-09, RSK-11, RSK-12, RSK-24 |
| **TOTAL** | **32** | **100%** | |

### 13.4 Eskalasi Khusus — RSK-15

**RSK-15 (kebocoran data antar tenant)** memperoleh skor 8 (Sedang × Kritis), yang secara numerik masuk zona Tinggi. Namun risiko ini **dinaikkan ke zona Kritis** dengan alasan berikut:

| Alasan | Dasar |
|---|---|
| Blueprint menetapkan **toleransi nol** — isolasi data harus 100% | NFR-06; CON-26 |
| Satu pelanggaran tunggal sudah cukup membatalkan pemenuhan NFR | NFR-06 |
| Dampaknya tidak dapat dipulihkan — data yang bocor tidak bisa "dibocorkan kembali" | Sifat risiko |
| Blueprint menjadikannya **dua dari sepuluh butir** checklist go-live | Lampiran A.3 #1, #2 |

Pemeringkatan berbasis skor tidak menangkap sifat *zero-tolerance* ini. Karena itu RSK-15 diperlakukan sebagai **risiko prioritas tertinggi sepanjang seluruh 18 minggu**, bukan hanya pada fase tertentu.

### 13.5 Peringkat Risiko Teratas

| Rank | ID | Risiko | P | D | Skor | Zona | Mitigasi Utama |
|:---:|---|---|:---:|:---:|:---:|:---:|---|
| 1 | **RSK-15** | Kebocoran data antar tenant | S | Kritis | 8 ⚠ | 🔴 | Prinsip tenant-first + unit test isolasi 100% setiap sprint (§7.2) |
| 2 | **RSK-01** | Data kehadiran dibutuhkan Phase 1, modulnya Phase 2 | T | T | 9 | 🔴 | Klarifikasi prioritas tertinggi sebelum Sprint 2 (§7.1) |
| 3 | **RSK-25** | Pengiriman WhatsApp masih manual | T | T | 9 | 🔴 | Komunikasikan sebagai keputusan desain Phase 1; filter & salin pada daftar penerima (§7.3) |
| 4 | RSK-02 | Tabel `audit_logs` tidak ada di ERD | T | S | 6 | 🟠 | Konfirmasi scope; implementasi sesuai field §3.4 |
| 5 | RSK-03 | Konflik kewenangan guru buat pengumuman | T | S | 6 | 🟠 | Minta keputusan tertulis sebelum Sprint 7 |
| 6 | RSK-05 | Status `INACTIVE` tidak ada di ENUM | T | S | 6 | 🟠 | Minta penyelarasan sebelum Sprint 2 |
| 7 | RSK-14 | Normalisasi nomor HP tidak dijelaskan | T | S | 6 | 🟠 | Minta aturan; tampilkan peringatan pada nomor yang gagal dikonversi |
| 8 | RSK-28 | Bilingual EN baru Sprint 9 | T | S | 6 | 🟠 | Translation key sejak Sprint 1 |
| 9 | RSK-29 | Generator wa.me dibutuhkan Sprint 3, dijadwalkan Sprint 8 | T | S | 6 | 🟠 | Bangun sebagai komponen bersama di Sprint 3 |
| 10 | RSK-16 | Bypass Super Admin memperlemah proteksi | S | T | 6 | 🟠 | Test case khusus jalur Super Admin |
| 11 | RSK-17 | Single point of failure pada satu VPS | S | T | 6 | 🟠 | Monitoring uptime; HA belum dijelaskan blueprint |
| 12 | RSK-18 | 200 user konkuren pada 2C/2GB dengan DB driver | S | T | 6 | 🟠 | Load test Sprint 9; opsi upgrade vertikal |
| 13 | RSK-26 | Bus factor = 1 developer | S | T | 6 | 🟠 | Opsi 2 developer; dokumentasi mutakhir |
| 14 | RSK-27 | Blueprint masih DRAFT | S | T | 6 | 🟠 | Disiplin CON-55 |
| 15 | RSK-30 | Migrasi data & pelatihan tidak direncanakan | S | T | 6 | 🟠 | Angkat sebagai kebutuhan di luar 18 minggu |

### 13.6 Sebaran Risiko per Kategori

| Kategori | 🔴 Kritis | 🟠 Tinggi | 🟡 Sedang | 🟢 Rendah | Total |
|---|:---:|:---:|:---:|:---:|:---:|
| Kesenjangan requirement (RSK-01…14) | 1 | 4 | 6 | 3 | 14 |
| Teknis & arsitektur (RSK-15…24) | 1 | 4 | 4 | 1 | 10 |
| Operasional & proses (RSK-25…32) | 1 | 4 | 3 | 0 | 8 |
| **TOTAL** | **3** | **12** | **13** | **4** | **32** |

> **Pengamatan:** kategori terbesar adalah **kesenjangan requirement (14 risiko, 44%)** — bukan risiko teknis. Ini konsisten dengan status blueprint yang masih **DRAFT** dan dengan 14 isu terbuka pada PRD §17.3. Konsekuensinya, mitigasi paling berdampak pada tahap awal bukanlah pekerjaan teknis, melainkan **menyelesaikan klarifikasi requirement sebelum sprint terkait dimulai**.

### 13.7 Sebaran Risiko per Fase

| Fase | Risiko Terdampak | Zona Tertinggi |
|---|---|:---:|
| Seluruh fase | RSK-15, RSK-26, RSK-27 | 🔴 |
| Phase 0–1 (Fondasi) | RSK-02, RSK-06, RSK-11, RSK-16, RSK-24 | 🟠 |
| Phase 2 (Master Data) | RSK-01, RSK-05, RSK-12, RSK-20, RSK-21 | 🔴 |
| Phase 3 (PPDB) | RSK-14, RSK-20, RSK-25, RSK-29 | 🔴 |
| Phase 4 (Academic) | RSK-01, RSK-07, RSK-08, RSK-09, RSK-19, RSK-22 | 🔴 |
| Phase 5 (Finance) | RSK-10, RSK-19, RSK-20 | 🟡 |
| Phase 8 (Reporting) | RSK-16, RSK-22 | 🟠 |
| Phase 6 (Portal) | RSK-01, RSK-03, RSK-04, RSK-13 | 🔴 |
| Phase 7 (Notification) | RSK-03, RSK-14, RSK-25, RSK-29 | 🔴 |
| Phase 9 (Testing) | RSK-18, RSK-28, RSK-31 | 🟠 |
| Phase 10 (Deployment) | RSK-17, RSK-23, RSK-30, RSK-31 | 🟠 |
| Maintenance | RSK-32 | 🟡 |

### 13.8 Rencana Penutupan Risiko

| Risiko | Ditutup pada | Bukti Penutupan |
|---|---|---|
| RSK-11, RSK-02, RSK-06 | Akhir Sprint 1 | Keputusan tertulis pemilik blueprint + implementasi |
| RSK-16 | Akhir Sprint 1 (verifikasi ulang Sprint 6) | Test case jalur Super Admin lulus |
| RSK-05, RSK-12, RSK-21 | Akhir Sprint 2 | Unit test aturan bisnis lulus |
| RSK-14, RSK-29 | Akhir Sprint 3 | Komponen wa.me berfungsi + uji encoding lulus |
| RSK-07, RSK-08, RSK-09, RSK-19 | Akhir Sprint 4 | Unit test formula + uji PDF rapor lulus |
| RSK-10 | Akhir Sprint 5 | Keputusan perilaku `YEARLY`/`ONCE` + implementasi |
| RSK-22 | Akhir Sprint 6 | Query agregasi memenuhi target performa |
| RSK-03, RSK-04, RSK-13 | Akhir Sprint 7 | Keputusan tertulis + implementasi portal |
| RSK-18, RSK-28, RSK-31 | Akhir Sprint 9 | Load test lulus + bilingual lengkap + laporan audit |
| RSK-17, RSK-23, RSK-30 | Pra go-live | Monitoring aktif + uji restore + keputusan pemilik blueprint |
| **RSK-15** | **Tidak pernah ditutup** | Diverifikasi ulang setiap sprint sepanjang umur sistem |
| RSK-01 | **Belum dapat dijadwalkan** | Bergantung pada keputusan pemilik blueprint; bila menunggu Phase 2, tetap terbuka sepanjang Phase 1 |
| RSK-20, RSK-24, RSK-25, RSK-26, RSK-27, RSK-32 | Berkelanjutan | Dipantau sepanjang proyek dan masa maintenance |

---

## 14. Lampiran

### 14.1 Referensi Silang: Fase ↔ FR ↔ Modul PRD

| Fase | FR | Modul PRD (AC) | Tabel Utama |
|---|---|---|---|
| Phase 0 | — | — | `schools`, `users`, `roles`, `model_has_roles` |
| Phase 1 | AUTH-01…04, PORTAL-04 | §10.1 (M0) | `schools`, `users`, `roles`, `model_has_roles` |
| Phase 2 | SIS-01…05, KELAS-01…04 | §10.2 (SIS), §10.4 (Kelas) | `students`, `academic_years`, `classes`, `student_classes`, `subjects`, `class_subjects`, `schedules` |
| Phase 3 | PPDB-01…05 | §10.3 (PPDB) | `ppdb_registrations`, `students` |
| Phase 4 | NILAI-01…05 | §10.5 (Penilaian) | `grades`, `grade_configs`, `report_cards` |
| Phase 5 | SPP-01…04, KAS-01 | §10.6 (Keuangan) | `fee_types`, `student_fees`, `payments`, `transactions` |
| Phase 8 | KAS-02, KAS-03, SPP-05 | §10.6 (Keuangan) | `student_fees`, `payments`, `transactions` |
| Phase 6 | PORTAL-01…03 | §10.8 (Portal) | Agregasi seluruh tabel |
| Phase 7 | NOTIF-01…04 | §10.7 (Notifikasi) | `notifications`, `notification_reads` |
| Phase 9 | AUTH-05 | Seluruh modul (regresi) | — |
| Phase 10 | — | §10.9 (Rilis) | — |

### 14.2 Roadmap Phase 2 (Setelah Gerbang MS-11)

Disajikan sebagai gambaran; **bukan bagian dari 18 minggu Phase 1**. Prasyarat: Phase 1 stabil minimal 3 bulan (CON-54).

| Urutan | Modul | Estimasi |
|:---:|---|---|
| 1 | LMS — Ruang Kelas Virtual (Google Meet) | 4–6 minggu |
| 2 | LMS — CBT / Ujian Online | 6–8 minggu |
| 3 | LMS — Bank Materi | 3–4 minggu |
| 4 | Presensi Digital | 4–5 minggu |
| 5 | Konseling & BK | 3–4 minggu |
| 6 | E-Library | 4–5 minggu |
| 7 | Manajemen Inventaris | 3–4 minggu |
| 8 | Payroll Guru & Staf | 5–6 minggu |
| 9 | Payment Gateway (Midtrans/Xendit) | 4–6 minggu |
| 10 | WhatsApp API (Fonnte / Meta Cloud API) | 2–3 minggu |
| 11 | DAPODIK Export | 3–4 minggu |

> Urutan mengikuti penyajian blueprint. Prioritas bisnis definitif Phase 2: **Belum dijelaskan dalam blueprint.**
>
> **Catatan keterkaitan:** modul **Presensi Digital** (urutan 4) adalah sumber data yang dibutuhkan RSK-01. Bila keputusan pemilik blueprint adalah menunggu Phase 2, maka fitur kehadiran pada SIS-04, PORTAL-01, dan rapor tetap berstatus *pending* sepanjang Phase 1.

### 14.3 Daftar Isu Terbuka yang Memblokir Fase

| # | Isu (PRD §17.3) | Memblokir | Batas Waktu Jawaban |
|---|---|---|---|
| 1 | Sumber data kehadiran/absensi Phase 1 | Phase 2, 4, 6 | Sebelum Sprint 2 |
| 5 | Status `INACTIVE` pada `students.status` | Phase 2 | Sebelum Sprint 2 |
| 12 | Format upload foto (WEBP vs JPG/PNG/PDF) | Phase 2 | Sebelum Sprint 2 |
| 10 | Mekanisme autentikasi (JWT+session vs Sanctum) | Phase 1 | Sprint 1 |
| 2 | Struktur tabel `audit_logs` | Phase 1 | Sprint 1 |
| 6 | Objek & alur approval Kepala Sekolah | Phase 1 | Sprint 1 |
| 14 | Normalisasi nomor HP ke awalan `62` | Phase 3, 7 | Sebelum Sprint 3 |
| 11 | Penjadwalan generator wa.me | Phase 3, 7 | Sebelum Sprint 3 |
| 7 | Presedensi bobot `grades.weight` vs `grade_configs` | Phase 4 | Sebelum Sprint 4 |
| 8 | Mekanisme unpublish/koreksi rapor | Phase 4 | Sebelum Sprint 4 |
| 13 | Rumus & tie-break `rank_in_class` | Phase 4 | Sprint 4 |
| 9 | Generate tagihan `YEARLY` / `ONCE` | Phase 5 | Sebelum Sprint 5 |
| 3 | Kewenangan guru membuat pengumuman | Phase 6, 7 | Sebelum Sprint 7 |
| 4 | Jumlah nilai terbaru dashboard ortu (3 vs 5) | Phase 6 | Sebelum Sprint 7 |

### 14.4 Referensi Dokumen

| Dokumen | Peran |
|---|---|
| `blueprint/SmartSukses_FullBlueprint_v1.0.0.docx` | Sumber kebenaran tunggal seluruh requirement |
| `docs/01-Analisis-Blueprint.md` | Analisis awal blueprint + daftar dokumentasi yang direncanakan |
| `docs/01-PRD.md` (v1.1) | Requirement resmi: 38 FR, 12 NFR, 21 ASM, 56 CON, AC per modul |
| `docs/02-ROADMAP.md` | Dokumen ini — rencana pengerjaan, sprint, risiko, milestone, DoD |

---

## Riwayat Revisi Dokumen

| Versi | Tanggal | Penulis | Keterangan |
|---|---|---|---|
| v1.0 | — | Tim Pengembang | Roadmap awal, diturunkan dari `SmartSukses_FullBlueprint_v1.0.0.docx` dan `docs/01-PRD.md` v1.1 |
| v1.1 | — | Tim Pengembang | Penambahan §10 Timeline Visual (Mingguan), §11 Sprint Deliverables Register, §12 Prioritas Modul, dan §13 Risk Matrix. Lampiran bergeser dari §10 ke §14. **Isi §1–§9 tidak diubah sama sekali; tidak ada requirement, fase, sprint, durasi, maupun risiko baru.** |

---

*Dokumen ini disusun sepenuhnya berdasarkan `blueprint/SmartSukses_FullBlueprint_v1.0.0.docx` dan `docs/01-PRD.md`. Tidak ada requirement yang ditambahkan, dikurangi, atau diubah. Seluruh informasi yang tidak tercantum dalam blueprint ditandai secara eksplisit sebagai "Belum dijelaskan dalam blueprint."*

**Smart Sukses School · Development Roadmap v1.1 · KONFIDENSIAL**
