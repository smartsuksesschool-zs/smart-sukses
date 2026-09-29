# Analisis Blueprint — Smart Sukses School

**Sumber tunggal:** `blueprint/SmartSukses_FullBlueprint_v1.0.0.docx`
**Versi blueprint:** v1.0.0 · Agustus 2025 · Status: DRAFT (Untuk Review & Development)
**Sifat dokumen ini:** Analisis/derivatif. Tidak menambah, mengurangi, atau mengubah requirement.
**Konvensi:** Setiap hal yang tidak tercantum dalam blueprint ditulis **"Belum dijelaskan dalam blueprint."**

---

## 1. Ringkasan Aplikasi

Smart Sukses School adalah **platform SaaS manajemen sekolah multi-cabang berbasis web**, diakses melalui satu domain tunggal `apps.smartsukses.sch.id`.

| Aspek | Isi Blueprint |
|---|---|
| Model | Multi-Tenant · Single Domain · White-Label per Cabang |
| Pola isolasi data | Shared Database, Shared Schema — isolasi via kolom `school_id` |
| Organisasi | Jaringan SMA Terbuka: Smart Pusat, Smart Madani, Smart Cinangka, + cabang yang akan berkembang |
| Fase | Phase 1 (MVP, detail) + Phase 2 (overview/roadmap) |
| Stack | Laravel 11 (PHP 8.3) · Filament PHP 3 · Livewire 3 + Alpine.js · Tailwind 3 · MySQL 8 · Nginx · Ubuntu 22.04 LTS · VPS 2C/2GB |
| Multi-tenancy | `spatie/laravel-multitenancy` 3.x |
| Auth & RBAC | Laravel Sanctum + `spatie/laravel-permission` |
| Bahasa UI | Bilingual — Bahasa Indonesia (default) + English |
| Kurikulum | Kustom per sekolah — tidak mengikuti format Merdeka/K13 secara kaku |
| Biaya infrastruktur | Rp 90.000–130.000 / bulan |
| Estimasi Phase 1 | 18 minggu (~4,5 bulan), 1 developer full-stack Laravel |

**Skala awal:** 3 cabang · 50–200 siswa per cabang · ~800–1.500 akun · target 10+ cabang tanpa refactoring.

**Masalah yang diselesaikan (6 poin dari blueprint):**
1. Data siswa tersebar di spreadsheet per cabang — tidak ada single source of truth.
2. Kepala Sekolah & Admin Pusat tidak bisa memonitor akademik + keuangan semua cabang secara real-time.
3. PPDB masih manual, rawan salah data, lambat mengabarkan status ke pendaftar.
4. Tagihan SPP terlambat terbit dan sulit dilacak pembayarannya.
5. Orang tua tidak punya akses langsung ke nilai, absensi, dan tagihan anak.
6. Pengumuman disebar via WhatsApp personal, tidak terstruktur.

---

## 2. Tujuan Aplikasi

Diturunkan langsung dari bagian "Solusi" dan "Masalah yang Diselesaikan":

| # | Tujuan | Bukti di Blueprint |
|---|---|---|
| T1 | Menyediakan **single source of truth** data siswa lintas cabang | Ringkasan Eksekutif — masalah #1 |
| T2 | Memberi **monitoring terpusat & real-time** untuk Kepala Sekolah dan Pusat (akademik + keuangan) | Masalah #2; KAS-03; `GET /admin/dashboard` |
| T3 | **Mendigitalkan PPDB** dari pendaftaran sampai enroll menjadi siswa aktif | Masalah #3; PPDB-01…PPDB-05 |
| T4 | **Mendigitalkan tagihan & pembayaran SPP** agar terbit tepat waktu dan terlacak | Masalah #4; SPP-01…SPP-05 |
| T5 | Membuka **akses mandiri orang tua & siswa** ke nilai, absensi, tagihan | Masalah #5; PORTAL-01, PORTAL-03 |
| T6 | **Menstrukturkan komunikasi sekolah** (pengumuman terpusat + wa.me link) | Masalah #6; NOTIF-01…NOTIF-04 |
| T7 | Menjalankan **satu aplikasi untuk banyak cabang** dengan data terisolasi 100% dan identitas visual masing-masing | Solusi; NFR Keamanan "Data isolation 100%"; AUTH-02, AUTH-03 |
| T8 | Menekan **biaya operasional** hingga terjangkau untuk skala 3 cabang | Lampiran A.2 — total Rp 90–130 ribu/bln |

---

## 3. Target Pengguna

**Pengguna langsung (memiliki akun):**

| Kelompok | Level | Perkiraan volume |
|---|---|---|
| Super Administrator (Pusat) | Platform | `school_id = NULL` |
| Admin Sekolah, Kepala Sekolah, Bendahara | Sekolah | per cabang |
| Guru Mata Pelajaran & Wali Kelas | Sekolah | per cabang |
| Siswa | Sekolah | 50–200 per cabang |
| Orang Tua / Wali Murid | Sekolah | dapat memiliki >1 anak (PORTAL-01) |

Total pengguna awal: **~800–1.500 akun**.

**Pengguna tidak langsung (tanpa akun / publik):**
- **Calon siswa & orang tua calon siswa** — mengakses form PPDB publik `/ppdb/[kode_sekolah]` dan halaman cek status tanpa login (PPDB-01, PPDB-02).

**Perangkat yang didukung:** Desktop (Chrome/Firefox/Edge) + Mobile (browser iOS/Android). Portal orang tua wajib responsive mobile (PORTAL-01).

> Catatan: aplikasi mobile native **Belum dijelaskan dalam blueprint.** (Blueprint hanya menyebut "Bearer token untuk API mobile future".)

---

## 4. Seluruh Role Beserta Hak Akses

### 4.1 Daftar Role (8 role, tepat satu peran utama per pengguna)

| Kode | Nama | Level | Deskripsi |
|---|---|---|---|
| `SUPER_ADMIN` | Super Administrator | Platform | Akses penuh semua cabang, konfigurasi sistem, manajemen tenant |
| `SCHOOL_ADMIN` | Admin Sekolah | Sekolah | Seluruh operasional satu cabang: siswa, guru, keuangan, pengaturan |
| `KEPALA_SEKOLAH` | Kepala Sekolah | Sekolah | Monitoring & approval: laporan akademik, keuangan, dashboard cabang |
| `GURU` | Guru Mata Pelajaran | Sekolah | Input nilai, daftar siswa kelas ajar, jadwal mengajar |
| `WALI_KELAS` | Wali Kelas | Sekolah | Semua akses guru + kelola rapor & absensi kelas yang diampu |
| `SISWA` | Siswa | Sekolah | Lihat nilai, jadwal, notifikasi, data pribadi sendiri |
| `ORANG_TUA` | Orang Tua / Wali Murid | Sekolah | Parent portal: nilai anak, absensi anak, tagihan, notifikasi |
| `BENDAHARA` | Bendahara | Sekolah | Tagihan SPP, catat pembayaran, akuntansi & laporan keuangan |

### 4.2 Matriks Izin per Modul (Phase 1)

Legenda: ✅ akses penuh · ⭕ baca/view saja · ❌ tidak ada akses

| Modul | SUPER_ADMIN | SCHOOL_ADMIN | KEPALA | GURU/WALI | BENDAHARA | SISWA | ORTU |
|---|---|---|---|---|---|---|---|
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

### 4.3 Auth Level pada API (turunan role)

| Auth Level | Ketentuan |
|---|---|
| Public | Tanpa token — PPDB form, info PPDB, cek status, login, forgot/reset password |
| Auth | Wajib token; data otomatis di-scope ke `school_id` user |
| Admin | Wajib token + role `SCHOOL_ADMIN` / `SUPER_ADMIN` |
| Super | Wajib token + role `SUPER_ADMIN` |

### 4.4 Aturan Akses Tambahan yang Eksplisit di Blueprint

- Super Admin memiliki `school_id = NULL` dan **melewati Global Scope** (`if (auth()->user()->isSuperAdmin()) { return $query; }`).
- Guru hanya melihat siswa **kelas yang dia ampu** (`GET /students`) dan hanya bisa input nilai untuk **class_subject yang dia ampu** (`POST /grades`).
- Orang tua hanya bisa melihat **tagihan anaknya sendiri** (`GET /students/{id}/fees`).
- Publish rapor: **Wali Kelas only** (`POST /report-cards/generate`, NILAI-03).
- Satu guru hanya boleh jadi wali kelas **satu kelas per tahun ajaran** (KELAS-01).

> **Catatan analisis (perlu klarifikasi ke pemilik blueprint, bukan perubahan requirement):**
> - Matriks menyatakan **GURU/WALI ❌ untuk "Notifikasi (buat)"**, sementara PORTAL-02 memberi guru shortcut **"Buat Pengumuman"**. Dua pernyataan ini tidak konsisten dalam blueprint.
> - Deskripsi role `KEPALA_SEKOLAH` menyebut **"approval"**, namun tidak ada satu pun user story Phase 1 yang mendefinisikan alur approval. Mekanisme approval: **Belum dijelaskan dalam blueprint.**
> - Deskripsi role `WALI_KELAS` menyebut **"kelola absensi"**, namun modul Presensi Digital berada di Phase 2 dan tidak ada tabel absensi di ERD. Lihat bagian 9 (Risiko R-01).
> - `SUPER_ADMIN` diberi ✅ pada modul level sekolah (mis. Portal Siswa, Parent Portal), namun apakah Super Admin dapat "impersonate"/masuk ke portal cabang: **Belum dijelaskan dalam blueprint.**

---

## 5. Semua Fitur yang Ada

### 5.1 Phase 1 — MVP (dengan ID User Story)

**A. Cross-Cutting: Multi-Tenant, White-Label & Autentikasi**

| ID | Fitur | Acceptance Criteria kunci |
|---|---|---|
| AUTH-01 | Login email + password | Error tidak membocorkan detail sistem; menghasilkan JWT/session token; token kedaluwarsa setelah **8 jam tidak aktif** |
| AUTH-02 | Auto-deteksi `school_id` & filter data | Semua query wajib `WHERE school_id`; Super Admin lintas cabang; tidak ada jalan bagi user biasa mengakses cabang lain |
| AUTH-03 | White-label UI per cabang | Baca `logo_url`, `primary_color`, `secondary_color`; CSS variables di-inject dinamis; berlaku **tanpa deployment ulang** |
| AUTH-04 | Reset password via email | Link berlaku **60 menit**; semua sesi aktif di-invalidate setelah reset |
| AUTH-05 | Ganti bahasa ID ⇄ EN | Toggle di navbar; tersimpan di field `locale`; semua label/error/placeholder dwibahasa |

**B. Sistem Informasi Siswa (SIS)**

| ID | Fitur | Acceptance Criteria kunci |
|---|---|---|
| SIS-01 | Tambah siswa manual | Field wajib: nama, NIS, NISN, tgl lahir, JK, agama, alamat, nama ortu, no. HP ortu; NISN 10 digit; **NIS unik per sekolah** |
| SIS-02 | Edit & nonaktifkan siswa | Soft update (histori tidak hilang); status `INACTIVE` tidak dihapus dari DB; tidak muncul di daftar kelas aktif |
| SIS-03 | Upload foto siswa | JPG/PNG/WEBP; maks **2 MB**; auto-resize **400×400 px** |
| SIS-04 | Guru lihat daftar siswa kelas ajar | Hanya siswa aktif; tampil nama, NIS, foto, **status kehadiran hari ini** |
| SIS-05 | Export siswa ke Excel | Semua field; nama file `siswa_[kode_sekolah]_[tanggal].xlsx` |

Tambahan dari API map: `POST /students/import` (import siswa massal dari Excel).

**C. PPDB Online**

| ID | Fitur | Acceptance Criteria kunci |
|---|---|---|
| PPDB-01 | Form pendaftaran publik tanpa login | URL `/ppdb/[kode_sekolah]`; field: nama, JK, tgl lahir, asal sekolah, nama ortu, no. HP, email; keluar **nomor pendaftaran unik** |
| PPDB-02 | Cek status pendaftaran publik | Status: `REGISTERED` → `DOCUMENT_REVIEW` → `PASSED`/`FAILED` → `ENROLLED`; cek pakai no. daftar + tanggal lahir |
| PPDB-03 | Admin kelola pendaftar | Tabel: no. daftar, nama, asal sekolah, status, tgl daftar; filter per status; perubahan status disimpan **dengan catatan alasan** |
| PPDB-04 | Generate wa.me notifikasi | Template teks per perubahan status; link wa.me otomatis; admin klik "Buka WhatsApp" untuk **kirim manual** |
| PPDB-05 | Enroll pendaftar LULUS jadi siswa (1 klik) | Data PPDB mengisi otomatis form siswa; admin dapat melengkapi sebelum konfirmasi; status → `ENROLLED` |

Tambahan dari API map: `GET /ppdb/schools` (landing publik daftar cabang yang buka PPDB), `GET /ppdb/{schoolCode}/info` (syarat, jadwal, kuota), upload dokumen (`ppdb_registrations.documents` JSON).

**D. Manajemen Kelas & Jadwal**

| ID | Fitur | Acceptance Criteria kunci |
|---|---|---|
| KELAS-01 | Buat kelas + tentukan wali kelas | Field: nama kelas, tingkat, wali kelas, kapasitas; wali dipilih dari guru aktif; 1 guru = 1 kelas per tahun ajaran |
| KELAS-02 | Tambah siswa ke kelas | Hanya siswa aktif yang belum berkelas; **1 siswa = 1 kelas per tahun ajaran** |
| KELAS-03 | Buat jadwal pelajaran | Field: kelas, mapel, guru, hari, jam mulai/selesai, ruang; **deteksi konflik** guru/ruang/kelas |
| KELAS-04 | Guru lihat jadwal mengajar mingguan | Format tabel/kalender mingguan; klik → detail kelas, mapel, ruang |

Tambahan dari API map: manajemen Tahun Ajaran (`/academic-years`, aktivasi satu tahun ajaran) dan Mata Pelajaran (`/subjects`).

**E. E-Rapor & Penilaian**

| ID | Fitur | Acceptance Criteria kunci |
|---|---|---|
| NILAI-01 | Guru input nilai per komponen (Harian/UTS/UAS) | Skala **0–100**; satuan atau import Excel; editable selama `published = false` |
| NILAI-02 | Hitung otomatis nilai akhir per bobot | Bobot dikonfigurasi Admin (mis. Harian 40%, UTS 30%, UAS 30%); pembulatan **2 desimal** |
| NILAI-03 | Wali Kelas publish rapor sekelas | Validasi semua mapel punya nilai akhir; setelah publish **nilai terkunci**; tersedia di portal siswa & ortu |
| NILAI-04 | Siswa/Ortu lihat nilai real-time + rapor final | Nilai tampil segera setelah guru simpan; rapor final setelah publish; **cetak PDF** |
| NILAI-05 | Admin konfigurasi komponen & bobot per mapel | Boleh beda antar mapel; perubahan **hanya berlaku untuk tahun ajaran baru** |

Komponen nilai di ERD: `DAILY`, `MIDTERM`, `FINAL`, `ASSIGNMENT`, `SKILL`, `ATTITUDE`. Rapor menyimpan `final_scores` (JSON), nilai sikap A–D, rekap kehadiran, `rank_in_class`, catatan wali kelas.

**F. Tagihan & Pembayaran SPP Digital**

| ID | Fitur | Acceptance Criteria kunci |
|---|---|---|
| SPP-01 | Buat jenis tagihan | Field: nama, jumlah (Rp), frekuensi `MONTHLY`/`YEARLY`/`ONCE`; nonaktif tanpa hapus histori |
| SPP-02 | Generate tagihan massal 1 klik | Buat `student_fees` untuk semua siswa aktif; due date otomatis (mis. tanggal 10); **preview sebelum konfirmasi** |
| SPP-03 | Catat pembayaran manual (cash/transfer) | Form: siswa, periode, metode, jumlah, tanggal, referensi; upload bukti JPG/PNG/PDF maks **5 MB**; status otomatis `PAID`/`PARTIAL` |
| SPP-04 | Ortu lihat tagihan & riwayat | Daftar per periode; belum lunas ditandai merah/warning; riwayat memuat tanggal & metode bayar |
| SPP-05 | Export laporan tagihan ke Excel | Kolom: nama, kelas, periode, tagihan, bayar, sisa, status; filter kelas/periode/status |

Tambahan dari ERD/API: status `WAIVED` + `waive_reason` (`PATCH /student-fees/{id}/waive`); pembayaran **cicilan** (1 tagihan → banyak `payments`).

**G. Akuntansi & Kas Sekolah**

| ID | Fitur | Acceptance Criteria kunci |
|---|---|---|
| KAS-01 | Catat pemasukan & pengeluaran | Field: `INCOME`/`EXPENSE`, kategori, jumlah, tanggal, keterangan, no. referensi; lampiran bukti |
| KAS-02 | Ringkasan keuangan bulanan (Kepsek/Admin) | Saldo kas, penerimaan SPP bulan ini, pengeluaran bulan ini; **grafik tren 6 bulan** |
| KAS-03 | Dashboard keuangan semua cabang (Super Admin) | Per cabang: total tagihan, total terkumpul, % lunas; filter tahun ajaran/bulan |

**H. Notifikasi & Pengumuman (wa.me)**

| ID | Fitur | Acceptance Criteria kunci |
|---|---|---|
| NOTIF-01 | Buat pengumuman bertarget | Field: judul, isi, target, kategori `ACADEMIC`/`BILLING`/`EMERGENCY`/`GENERAL`; target ALL/CLASS/INDIVIDUAL |
| NOTIF-02 | Daftar wa.me link siap kirim | Format `wa.me/62[nomorHP]?text=[pesan_ter-encode]`; dapat difilter & disalin; tombol "Buka WA" |
| NOTIF-03 | Trigger notifikasi otomatis | Untuk event: **PPDB status berubah**, **tagihan baru terbit**, **rapor diterbitkan**; template editable oleh Admin Sekolah |
| NOTIF-04 | Notification center in-app | Badge jumlah belum dibaca (bell icon); klik = dibaca; **riwayat tersimpan 90 hari** |

Enum tipe notifikasi di ERD: `ANNOUNCEMENT`, `BILLING`, `ACADEMIC`, `EMERGENCY`, `SYSTEM`. Terdapat status `is_draft`.

**I. Portal Orang Tua, Guru, dan Siswa**

| ID | Fitur | Acceptance Criteria kunci |
|---|---|---|
| PORTAL-01 | Dashboard Orang Tua | 3 nilai terbaru, kehadiran bulan ini, tagihan belum lunas (jumlah & nominal); **switch antar anak**; responsive mobile |
| PORTAL-02 | Dashboard Guru | Jadwal hari ini; shortcut Input Nilai, Daftar Siswa Kelas, Buat Pengumuman |
| PORTAL-03 | Portal Siswa | Menu: Jadwal, Nilai, Notifikasi, Profil; nilai per mapel/semester/komponen; notifikasi ber-timestamp |
| PORTAL-04 | Manajemen akun pengguna oleh Admin | Buat/edit/nonaktifkan/reset password; **import Excel massal** untuk guru & siswa; reset menghasilkan password sementara |

Catatan API: dashboard ortu (`/parent/children/{id}/summary`) menyebut **5 mapel nilai terbaru**, sedangkan PORTAL-01 menyebut **3 nilai terbaru** — inkonsistensi internal blueprint.

### 5.2 Kebutuhan Non-Fungsional

| Kategori | Requirement | Target |
|---|---|---|
| Performa | Load halaman utama | < 3 detik pada 4G (10 Mbps) |
| Performa | Response time API | < 500 ms untuk 95% request |
| Skalabilitas | Jumlah tenant | 10+ cabang tanpa refactoring |
| Skalabilitas | User konkuren | Minimal 200 pada VPS 2C/2GB |
| Keamanan | Autentikasi | JWT + session, HTTPS wajib, CSRF protection |
| Keamanan | Data isolation | **100%** — tanpa kebocoran antar tenant |
| Keamanan | Password | Bcrypt/Argon2, min 8 karakter, wajib ganti saat login pertama |
| Ketersediaan | Uptime | 99%/bulan (~7 jam downtime) |
| Backup | Database | Harian otomatis, retensi 30 hari (pukul 02:00 WIB via mysqldump) |
| Aksesibilitas | Perangkat | Desktop (Chrome/Firefox/Edge) + Mobile browser |
| Lokalisasi | Bahasa | ID (default) + EN, dapat diperluas |
| Audit | Log aktivitas | Semua CRUD dicatat di tabel `audit_logs` (user, action, table, id, timestamp, IP) |

Keamanan tambahan: rate limit login **5 percobaan/menit**, API **60 request/menit per user**; upload hanya JPG/PNG/PDF disimpan di luar web root; HSTS aktif; Argon2id.

### 5.3 Phase 2 — Di Luar Scope MVP

Dimulai **setelah Phase 1 stabil dan dipakai minimal 3 bulan**.

| Modul | Fitur | Estimasi |
|---|---|---|
| LMS — Ruang Kelas Virtual | Integrasi Google Meet, link meeting per kelas, jadwal sesi online | 4–6 minggu |
| LMS — CBT (Ujian Online) | Soal PG & essay, kunci otomatis, hasil real-time | 6–8 minggu |
| LMS — Bank Materi | Upload PDF/video per mapel | 3–4 minggu |
| Presensi Digital | Absensi GPS/selfie, rekap bulanan, notifikasi alpa | 4–5 minggu |
| Konseling & BK | Pelanggaran/prestasi, skor poin, rekam jejak BK | 3–4 minggu |
| E-Library | Katalog buku, tracking peminjaman, notifikasi jatuh tempo | 4–5 minggu |
| Manajemen Inventaris | Aset sekolah, kondisi, lokasi | 3–4 minggu |
| Payroll Guru & Staf | Komponen gaji, potongan, slip PDF | 5–6 minggu |
| Payment Gateway | Midtrans/Xendit untuk SPP online | 4–6 minggu |
| WhatsApp API | Upgrade wa.me → Fonnte/Meta Cloud API | 2–3 minggu |
| DAPODIK Export | Export format Dapodik Kemdikbud | 3–4 minggu |

---

## 6. Alur Bisnis Aplikasi

### 6.1 Alur Identifikasi Tenant & Login (7 langkah, dari §3.2.2)

1. Pengguna mengakses `apps.smartsukses.sch.id` (satu URL untuk semua cabang).
2. Pengguna memasukkan email + password.
3. Sistem lookup `users.email` → memperoleh `users.school_id`.
4. `TenantMiddleware` (spatie/laravel-multitenancy) bootstrap: simpan `school_id` ke context sesi.
5. Semua query Eloquent otomatis di-scope `school_id` via Global Scope.
6. Sistem membaca `schools.logo_url` & `schools.primary_color` → inject CSS variables.
7. Pengguna melihat tampilan white-label sesuai cabangnya.

Super Admin (`school_id = NULL`) melewati Global Scope.

### 6.2 Alur PPDB (end-to-end)

```
Calon siswa buka /ppdb/[kode_sekolah] (publik, tanpa login)
   → Isi formulir + upload dokumen
   → Sistem terbitkan reg_number: [KODE_CABANG]-[TAHUN]-[SEQ]   status = REGISTERED
   → Admin Sekolah review berkas                                 status = DOCUMENT_REVIEW
   → Keputusan seleksi                                           status = PASSED / FAILED
        └─ setiap perubahan status: simpan catatan alasan
                                  + generate wa.me link (admin kirim manual)
                                  + notifikasi otomatis (NOTIF-03)
   → Pendaftar PASSED → Admin klik "Enroll" (PPDB-05)
        └─ data PPDB mengisi form siswa → admin lengkapi → konfirmasi
        └─ terbentuk record students, ppdb.converted_student_id terisi
                                                                  status = ENROLLED
   → Calon siswa dapat cek status kapan saja (publik, no. daftar + tgl lahir)
```

### 6.3 Alur Akademik (per tahun ajaran)

```
Admin buat Tahun Ajaran → aktifkan (hanya 1 aktif per sekolah)
   → Admin buat Mata Pelajaran (kustom per cabang)
   → Admin buat Kelas (rombel) + tunjuk Wali Kelas (1 guru = 1 kelas/TA)
   → Admin masukkan Siswa ke Kelas (1 siswa = 1 kelas/TA)
   → Admin tetapkan class_subjects (mapel + guru pengampu per kelas)
   → Admin susun Jadwal per class_subject (sistem deteksi konflik guru/ruang/kelas)
   → Admin set grade_configs (bobot komponen per mapel per TA)
   → Guru input nilai per komponen (manual / bulk / import Excel)
        └─ Siswa & Ortu melihat nilai real-time
   → Sistem hitung nilai akhir = Σ (nilai komponen × bobot), 2 desimal
   → Wali Kelas generate draft rapor sekelas
        └─ validasi: semua mapel sudah punya nilai akhir
   → Wali Kelas publish rapor → nilai TERKUNCI, published_at & published_by terisi
        └─ trigger notifikasi ke Ortu
        └─ rapor tampil di Portal Siswa & Parent Portal, dapat diunduh PDF
```

### 6.4 Alur Keuangan (SPP)

```
Bendahara buat Jenis Tagihan (fee_types: nama, nominal, MONTHLY/YEARLY/ONCE)
   → Generate tagihan massal per periode (YYYY-MM) untuk semua siswa aktif
        └─ preview daftar sebelum konfirmasi
        └─ terbentuk student_fees, due_date otomatis, status = UNPAID
        └─ trigger notifikasi "tagihan baru terbit"
   → Ortu melihat tagihan di Parent Portal (belum lunas ditandai merah)
   → Pembayaran cash / transfer
   → Bendahara catat pembayaran (payments) + upload bukti (maks 5 MB)
        └─ amount_paid ter-akumulasi pada student_fees
        └─ status → PARTIAL (sebagian) atau PAID (lunas)
        └─ alternatif: Admin waive tagihan + alasan → status WAIVED
   → Bendahara export laporan tagihan per periode ke Excel
   → Rekap masuk ke ringkasan keuangan (KAS-02) & dashboard Pusat (KAS-03)
```

Alur kas umum berjalan paralel: Bendahara mencatat `transactions` (INCOME/EXPENSE + kategori + bukti) → dashboard saldo kas + grafik tren 6 bulan.

### 6.5 Alur Notifikasi

```
Sumber A — Manual:  Admin/Kepsek buat pengumuman (judul, isi, kategori, target ALL/CLASS/INDIVIDUAL)
Sumber B — Otomatis: event PPDB status berubah / tagihan terbit / rapor terbit
        ↓
notifications tersimpan (is_draft → terkirim, sent_at terisi)
        ↓
   ├─ In-app: muncul di bell icon, badge unread, notification_reads mencatat read_at
   └─ WhatsApp: sistem generate wa.me/62[HP]?text=[encoded] per penerima
                 → Admin klik "Buka WA" → kirim MANUAL satu per satu
        ↓
Riwayat notifikasi disimpan 90 hari
```

> Penting: pada Phase 1 pengiriman WhatsApp **tidak otomatis** — sistem hanya menyiapkan link, pengiriman dilakukan manual oleh Admin. Otomatisasi baru ada di Phase 2 (Fonnte/Meta Cloud API).

---

## 7. Modul Utama

### 7.1 Modul Fungsional

| Kode | Modul | Fase | Cakupan | Tabel Inti | Endpoint Utama |
|---|---|---|---|---|---|
| M0 | Core Platform (Cross-Cutting) | 1 | Multi-tenant, RBAC, Auth, White-label, Bilingual, Audit | `schools`, `users`, `roles`, `model_has_roles` | `/auth/*`, `/admin/schools/*`, `/users/*` |
| M1 | Administrasi & Akademik | 1 | SIS, PPDB, Kelas & Jadwal, E-Rapor & Penilaian | `students`, `academic_years`, `classes`, `student_classes`, `subjects`, `class_subjects`, `schedules`, `ppdb_registrations`, `grades`, `report_cards`, `grade_configs` | `/students`, `/classes`, `/schedules`, `/ppdb`, `/grades`, `/report-cards` |
| M3 | Keuangan | 1 | Tagihan SPP, Pembayaran, Akuntansi & Kas, Laporan | `fee_types`, `student_fees`, `payments`, `transactions` | `/fee-types`, `/student-fees`, `/payments`, `/transactions`, `/finance/*` |
| M5 | Komunikasi & Portal | 1 | Notifikasi & wa.me, Parent/Guru/Siswa Portal | `notifications`, `notification_reads` | `/notifications`, `/parent/*`, `/teacher/*`, `/student/*` |
| — | Modul 2 & Modul 4 | — | Penomoran modul melompat dari 1 → 3 → 5. Isi Modul 2 dan Modul 4 **Belum dijelaskan dalam blueprint.** | — | — |

**Sub-modul Phase 1 (unit kerja implementasi):**

- M0.1 Manajemen Tenant/Cabang · M0.2 Autentikasi & Session · M0.3 RBAC & Policy · M0.4 White-Label Theming · M0.5 User Management & Import Excel · M0.6 Lokalisasi ID/EN · M0.7 Audit Log
- M1.1 SIS · M1.2 PPDB Online · M1.3 Tahun Ajaran · M1.4 Kelas & Rombel · M1.5 Mata Pelajaran & Pengampu · M1.6 Jadwal Pelajaran · M1.7 Penilaian · M1.8 E-Rapor & PDF
- M3.1 Jenis Tagihan · M3.2 Generate Tagihan Massal · M3.3 Pencatatan Pembayaran · M3.4 Buku Kas · M3.5 Laporan & Export Keuangan
- M5.1 Notifikasi In-App · M5.2 wa.me Link Generator & Template · M5.3 Parent Portal · M5.4 Portal Siswa · M5.5 Portal Guru

### 7.2 Modul Phase 2

LMS (Virtual Class, CBT, Bank Materi) · Presensi Digital · Konseling & BK · E-Library · Manajemen Inventaris · Payroll · Payment Gateway · WhatsApp API · DAPODIK Export.

### 7.3 Lapisan Arsitektur

| Lapisan | Isi |
|---|---|
| Presentasi | Filament 3 (admin panel) + Livewire 3 & Alpine.js (portal) + Tailwind 3 |
| Aplikasi | Laravel 11 (routing, Form Request, Policy, Queue, Cache) |
| Domain/Data | Eloquent + Global Scope `school_id`, MySQL 8 shared schema |
| Integrasi | wa.me link, SMTP, DomPDF/Browsershot, (Phase 2: Google Meet, payment gateway, WA API) |
| Infrastruktur | Nginx + PHP-FPM 8.3, Supervisor (queue), Certbot, Cloudflare, Ubuntu 22.04 |

---

## 8. MVP yang Harus Dibuat Terlebih Dahulu

MVP = **Phase 1** sesuai definisi blueprint: Modul 1 (Administrasi & Akademik), Modul 3 (Keuangan), Modul 5 (Komunikasi & Portal), plus cross-cutting Multi-tenant, White-label, RBAC, Bilingual.

### 8.1 Urutan Sprint yang Direkomendasikan Blueprint (Lampiran A.1)

| Sprint | Durasi | Target | Isi |
|---|---|---|---|
| 1 | 2 mgg | Foundation | Setup VPS, Laravel, Filament, Multi-tenant, Auth, RBAC, White-label theming, User management |
| 2 | 2 mgg | Core SIS | Data siswa, Tahun Ajaran, Kelas & Jadwal, Mata Pelajaran, Import Excel |
| 3 | 2 mgg | PPDB | Form publik, Review admin, Status update, wa.me generator, Enroll siswa |
| 4 | 2 mgg | Akademik | Input nilai, Grade config, Auto-hitung nilai akhir, Generate & publish rapor, PDF rapor |
| 5 | 2 mgg | Keuangan | Jenis tagihan, Generate SPP massal, Catat pembayaran, Upload bukti, Laporan SPP |
| 6 | 2 mgg | Akuntansi | Buku kas, Laporan keuangan, Export Excel, Dashboard Bendahara |
| 7 | 2 mgg | Portal | Parent Portal, Portal Siswa, Portal Guru, Jadwal view |
| 8 | 2 mgg | Notifikasi | Notifikasi in-app, wa.me bulk link, Template WA, Trigger otomatis |
| 9 | 2 mgg | Polish & QA | Bilingual EN, Responsive mobile, Load testing, Security audit, Bug fixing |

**Total: 18 minggu (~4,5 bulan)** dengan 1 developer full-stack Laravel. Dapat dipercepat dengan 2 developer (paralel Sprint 3–4 dan Sprint 5–6).

### 8.2 Prioritas Absolut (fondasi yang tidak boleh dilewati)

Yang **wajib pertama** karena semua modul lain menggantung padanya:

1. `schools` + `users` + RBAC + Global Scope `school_id` (AUTH-01, AUTH-02) — tanpa ini semua fitur lain tidak bisa dites dengan benar.
2. White-label theming (AUTH-03) — dinyatakan berlaku tanpa deployment ulang.
3. Tahun Ajaran aktif — hampir semua tabel akademik & keuangan mereferensikannya.
4. Data Siswa (SIS) — prasyarat kelas, nilai, tagihan, portal.

### 8.3 Definisi Selesai MVP — Checklist Go-Live (Lampiran A.3)

- [ ] Unit test Global Scope (tenant isolation) lulus 100%
- [ ] Uji akses lintas-tenant: user Madani tidak bisa melihat data Cinangka
- [ ] Load test 200 user konkuren tanpa error/timeout
- [ ] Uji wa.me link: semua template ter-encode benar
- [ ] Uji PDF rapor: format & data sesuai
- [ ] SSL aktif + redirect HTTP→HTTPS
- [ ] Backup DB otomatis berjalan dan dapat di-restore
- [ ] Semua password default diubah (MySQL root, admin panel)
- [ ] CORS hanya menerima `apps.smartsukses.sch.id`
- [ ] Monitoring uptime aktif (UptimeRobot free / Better Stack)

---

## 9. Risiko Pengembangan

Risiko berikut diturunkan dari isi blueprint (bukan requirement baru). Skala dampak/probabilitas adalah penilaian analisis.

### 9.1 Risiko Konsistensi Requirement (gap internal blueprint)

| ID | Risiko | Bukti | Dampak | Mitigasi (usulan, perlu keputusan pemilik blueprint) |
|---|---|---|---|---|
| R-01 | **Data kehadiran/absensi dibutuhkan Phase 1 tetapi modulnya Phase 2 dan tabelnya tidak ada di ERD** | SIS-04 "status kehadiran hari ini"; PORTAL-01 "kehadiran bulan ini"; `report_cards.attend_present/sick/permission/absent`; role WALI_KELAS "kelola absensi" — sementara Presensi Digital = Phase 2, dan 21 tabel tidak memuat tabel absensi | **Tinggi** — 4 fitur MVP bergantung pada data yang sumbernya tidak didefinisikan | Klarifikasi ke pemilik blueprint. Sumber data kehadiran Phase 1: **Belum dijelaskan dalam blueprint.** |
| R-02 | **Tabel `audit_logs` disyaratkan NFR & arsitektur keamanan tetapi tidak masuk daftar 21 tabel ERD** | NFR Audit; §3.4 baris "Audit Log"; §2.1 daftar 21 entitas | Sedang — kepatuhan audit tidak terpenuhi jika terlewat | Konfirmasi apakah `audit_logs` termasuk scope Phase 1; struktur tabelnya **Belum dijelaskan dalam blueprint.** |
| R-03 | **Konflik hak akses guru atas pembuatan pengumuman** | Matriks: GURU/WALI ❌ "Notifikasi (buat)" vs PORTAL-02 shortcut "Buat Pengumuman" | Sedang — salah implementasi RBAC | Tetapkan satu sumber kebenaran sebelum Sprint 8 |
| R-04 | **Angka dashboard ortu tidak konsisten** | PORTAL-01 "3 nilai terbaru" vs `GET /parent/children/{id}/summary` "5 mapel nilai terbaru" | Rendah | Tetapkan satu angka sebelum Sprint 7 |
| R-05 | **Mekanisme "approval" Kepala Sekolah tidak terdefinisi** | Deskripsi role menyebut approval; tidak ada user story approval; matriks hanya beri ⭕ | Sedang — ekspektasi stakeholder tidak terpenuhi | Objek yang di-approve & alurnya: **Belum dijelaskan dalam blueprint.** |
| R-06 | **Status siswa berbeda antara user story dan ERD** | SIS-02 menyebut `status = INACTIVE`; `students.status` ENUM = `ACTIVE, GRADUATED, DROPPED_OUT, TRANSFERRED` (tanpa `INACTIVE`) | Sedang — salah mapping status | Samakan definisi sebelum Sprint 2 |
| R-07 | **Relasi orang tua ↔ banyak anak hanya lewat satu kolom** | PORTAL-01 mendukung >1 anak; ERD menyimpan `students.parent_user_id` (satu arah, dari sisi siswa) | Rendah–Sedang — perlu kehati-hatian query; skenario 2 wali/1 siswa **Belum dijelaskan dalam blueprint.** | Verifikasi kebutuhan saat desain Parent Portal |
| R-08 | **Tidak ada entitas guru terpisah** | Guru direpresentasikan sebagai `users` (`class_subjects.teacher_id`, `classes.homeroom_teacher_id`) | Rendah — data kepegawaian (NIP, mapel keahlian) tidak tertampung | Data induk guru: **Belum dijelaskan dalam blueprint.** |
| R-09 | **Aturan bobot & sumber `weight` ganda** | `grades.weight` nullable "jika mengacu `grade_configs`" | Sedang — hasil nilai akhir bisa berbeda tergantung sumber bobot | Tetapkan presedensi sebelum Sprint 4 |
| R-10 | **Perhitungan `rank_in_class` tidak didefinisikan** | Field ada di `report_cards`; tidak ada AC yang menjelaskan rumus/tie-break | Rendah | **Belum dijelaskan dalam blueprint.** |
| R-11 | **Generate tagihan massal hanya dijelaskan untuk frekuensi bulanan** | SPP-02 fokus SPP bulanan; `fee_types.frequency` juga punya `YEARLY` & `ONCE` | Rendah–Sedang | Perilaku generate untuk YEARLY/ONCE: **Belum dijelaskan dalam blueprint.** |
| R-12 | **Batas ukuran file tidak seragam** | Foto siswa maks 2 MB (SIS-03), bukti bayar maks 5 MB (SPP-03); dokumen PPDB **Belum dijelaskan dalam blueprint.** | Rendah | Tetapkan saat Sprint 3 |
| R-13 | **Mekanisme auth disebut dua cara** | NFR & AUTH-01 menyebut "JWT + session"; §3.4 & API menyebut Laravel Sanctum (cookie SPA + Bearer) | Rendah | Pilih satu implementasi konsisten di Sprint 1 |

### 9.2 Risiko Teknis & Arsitektur

| ID | Risiko | Dampak | Mitigasi (sudah ada di blueprint) |
|---|---|---|---|
| R-14 | **Kebocoran data antar tenant** — shared schema, satu Global Scope yang lupa diterapkan = pelanggaran NFR "isolasi 100%" | **Kritis** | Unit test Global Scope 100% + uji akses lintas-tenant (checklist go-live) |
| R-15 | **Bypass Super Admin memperlemah scope** — `isSuperAdmin()` melewati scope untuk semua query | Tinggi | Perlu pengujian khusus jalur Super Admin |
| R-16 | **Single point of failure** — semua komponen (Nginx, PHP-FPM, MySQL, Queue) di satu VPS 2C/2GB | Tinggi — uptime target 99% | Blueprint hanya menyediakan skalabilitas vertikal; strategi HA/failover **Belum dijelaskan dalam blueprint.** |
| R-17 | **Beban target 200 user konkuren pada 2C/2GB** dengan cache & queue driver database (bukan Redis) | Tinggi | Load test di Sprint 9; upgrade Redis baru di Phase 2 |
| R-18 | **Job berat pada queue DB driver** — generate tagihan massal & PDF rapor serentak (mis. akhir semester) | Sedang–Tinggi | Supervisor worker; blueprint menyebut queue untuk bulk fee & PDF |
| R-19 | **Storage lokal terbatas 40 GB SSD** untuk foto siswa, bukti bayar, dokumen PPDB, scan nota | Sedang | Blueprint: upgrade Backblaze B2 bila penuh (Phase 2) |
| R-20 | **Deteksi konflik jadwal** (guru/ruang/kelas) rawan bug dan mahal secara query | Sedang | Perlu test case khusus di Sprint 2 |
| R-21 | **Rapor terkunci setelah publish** — tidak ada alur koreksi bila nilai salah terlanjur diterbitkan | Sedang | Mekanisme unpublish/revisi: **Belum dijelaskan dalam blueprint.** |
| R-22 | **`report_cards.final_scores` & `grade_configs.components` disimpan sebagai JSON** — sulit di-query/di-agregat untuk laporan lintas siswa | Sedang | Pertimbangkan indeks/derivasi saat Sprint 4 |
| R-23 | **Backup hanya lokal di VPS pada Phase 1** — jika VPS hilang, backup ikut hilang | Tinggi | Blueprint: upload Backblaze B2 (Phase 1 lokal) |

### 9.3 Risiko Operasional & Proses

| ID | Risiko | Dampak | Catatan |
|---|---|---|---|
| R-24 | **Pengiriman WhatsApp masih manual** — Admin harus klik satu per satu untuk ratusan penerima | Tinggi (beban kerja) | Sesuai desain Phase 1; otomatisasi di Phase 2 |
| R-25 | **Ketergantungan pada 1 developer selama 18 minggu** — bus factor = 1 | Tinggi | Blueprint menawarkan opsi 2 developer paralel |
| R-26 | **Status blueprint masih DRAFT** — perubahan scope di tengah jalan | Sedang | Blueprint: setiap perubahan signifikan harus jadi versi baru |
| R-27 | **Bilingual EN baru dikerjakan di Sprint 9** padahal AUTH-05 bersifat cross-cutting — retrofit string di 8 sprint sebelumnya | Sedang | Biasakan pakai translation key sejak Sprint 1 |
| R-28 | **Adopsi pengguna & migrasi data dari spreadsheet lama** | Sedang–Tinggi | Rencana migrasi data historis & pelatihan pengguna: **Belum dijelaskan dalam blueprint.** |
| R-29 | **Limit email SMTP gratis 500–2.000/hari** untuk reset password & verifikasi | Rendah | Blueprint menilai cukup untuk skala awal |

> Tidak dijelaskan dalam blueprint: rencana **UAT**, **strategi testing otomatis selain unit test Global Scope**, **SLA support pasca go-live**, **rencana rollback deployment**, dan **CI/CD**. Semuanya: **Belum dijelaskan dalam blueprint.**

---

## 10. Dependency Antar Modul

### 10.1 Peta Dependency (arah panah = "membutuhkan")

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
     │ academic_years   │◄──────────────┼──────────────┐           │
     │ (Tahun Ajaran)   │               │              │           │
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
                 * sumber data kehadiran: Belum dijelaskan dalam blueprint (R-01)
```

### 10.2 Matriks Dependency Modul

| Modul | Bergantung pada | Dibutuhkan oleh |
|---|---|---|
| M0 Core Platform | — | Semua modul |
| M1.3 Tahun Ajaran | M0 | Kelas, class_subjects, Jadwal, Nilai, Rapor, grade_configs, fee_types, student_fees, PPDB |
| M1.1 SIS (students) | M0, M1.3 | Kelas, Nilai, Rapor, Tagihan, Pembayaran, Parent Portal, Portal Siswa |
| M1.2 PPDB | M0, M1.3, **M1.1 (untuk enroll)**, M5.2 (wa.me link) | SIS (sumber siswa baru) |
| M1.4 Kelas & Rombel | M0, M1.3, M1.1 | class_subjects, Jadwal, Rapor, notifikasi target CLASS, Portal |
| M1.5 Mapel & Pengampu | M0, M1.3, M1.4, users(guru) | Jadwal, Nilai, grade_configs |
| M1.6 Jadwal | M0, M1.5 | Portal Guru, Portal Siswa, Parent Portal |
| M1.7 Penilaian | M0, M1.1, M1.5, M1.3, grade_configs | E-Rapor, Portal Siswa, Parent Portal |
| M1.8 E-Rapor & PDF | M0, M1.7, M1.4, M1.1, **data kehadiran (gap R-01)** | Portal Siswa, Parent Portal, trigger notifikasi |
| M3.1 Jenis Tagihan | M0, M1.3 | student_fees |
| M3.2 Generate Tagihan | M0, M3.1, M1.1 (siswa aktif) | Pembayaran, Parent Portal, Laporan, trigger notifikasi |
| M3.3 Pembayaran | M0, M3.2 | Laporan keuangan, Parent Portal, Dashboard Pusat |
| M3.4 Buku Kas | M0 | Laporan keuangan, KAS-02, KAS-03 |
| M3.5 Laporan & Export | M0, M3.2, M3.3, M3.4, M1.4 (filter kelas) | Dashboard Kepsek & Super Admin |
| M5.1 Notifikasi In-App | M0 | Semua portal |
| M5.2 wa.me Generator | M0, `users.phone` / `students.parent_phone`, template di `schools` | PPDB, SPP, Rapor, Pengumuman |
| M5.3 Parent Portal | M0, M1.1, M1.7, M1.8, M1.6, M3.2, M3.3, M5.1 | — (konsumen akhir) |
| M5.4 Portal Siswa | M0, M1.1, M1.6, M1.7, M1.8, M5.1 | — |
| M5.5 Portal Guru | M0, M1.5, M1.6, M1.4, M5.1 | — |

### 10.3 Jalur Kritis (critical path)

```
M0 Core (tenant + auth + RBAC)
  → M1.3 Tahun Ajaran
    → M1.1 SIS
      → M1.4 Kelas → M1.5 class_subjects → M1.7 Nilai → M1.8 Rapor
      → M3.2 Tagihan → M3.3 Pembayaran
        → M5.3 Parent Portal (bergantung pada keduanya)
```

Parent Portal adalah **titik konvergensi terbanyak** (7 dependency) — konsisten dengan penempatannya di Sprint 7, setelah akademik (Sprint 4) dan keuangan (Sprint 5–6) selesai.

### 10.4 Dependency Eksternal

| Dependensi | Dipakai oleh | Catatan |
|---|---|---|
| WhatsApp (wa.me) | M5.2, PPDB-04, NOTIF-02 | Manual, gratis, tanpa API di Phase 1 |
| SMTP (Gmail/Mailtrap) | AUTH-04 reset password, verifikasi email | Limit 500–2.000 email/hari |
| DomPDF / Browsershot | NILAI-04 rapor PDF, laporan keuangan | — |
| Let's Encrypt + Certbot | HTTPS | Auto-renew 90 hari |
| Cloudflare Free | DNS, DDoS, CDN statis | — |
| Library Excel (import/export) | SIS-05, SPP-05, PORTAL-04, NILAI-01, `/finance/export` | Nama library **Belum dijelaskan dalam blueprint.** |
| `spatie/laravel-multitenancy`, `spatie/laravel-permission`, Laravel Sanctum | M0 | Ditetapkan blueprint |
| Google Meet | Phase 2 (LMS) | Link generation, gratis |

### 10.5 Konsekuensi Dependency terhadap Urutan Kerja

- **Tidak boleh dikerjakan sebelum M0 selesai:** apa pun. Global Scope yang menyusul belakangan berisiko meninggalkan query tanpa filter (R-14).
- **PPDB (Sprint 3) butuh SIS (Sprint 2)** karena enroll menulis ke `students` — urutan sprint blueprint sudah benar.
- **Notifikasi (Sprint 8) dijadwalkan setelah PPDB/SPP/Rapor**, padahal ketiganya adalah *trigger* notifikasi (NOTIF-03) dan PPDB-04 membutuhkan wa.me generator di Sprint 3. Artinya wa.me generator perlu tersedia lebih awal (parsial di Sprint 3) atau trigger dipasang mundur di Sprint 8. Blueprint tidak menjelaskan cara menanganinya: **Belum dijelaskan dalam blueprint.**
- **Paralelisasi yang aman menurut blueprint:** Sprint 3–4 (PPDB & Akademik) dan Sprint 5–6 (Keuangan & Akuntansi) dengan 2 developer.

---

## Daftar File Dokumentasi yang Akan Dibuat Sebelum Coding

Semua dokumen di bawah adalah **turunan blueprint** — tidak menambah requirement. Ditempatkan di folder `docs/`.

### Kelompok A — Analisis & Perencanaan

| No | Nama File | Isi | Prioritas |
|---|---|---|---|
| 1 | `01-Analisis-Blueprint.md` | Dokumen ini — ringkasan, tujuan, pengguna, role, fitur, alur, modul, MVP, risiko, dependency | ✅ Selesai |
| 2 | `02-Daftar-Requirement-Traceability-Matrix.md` | Tabel seluruh ID user story (AUTH/SIS/PPDB/KELAS/NILAI/SPP/KAS/NOTIF/PORTAL) ↔ modul ↔ tabel ↔ endpoint ↔ sprint ↔ status | Tinggi |
| 3 | `03-Daftar-Pertanyaan-Klarifikasi-Blueprint.md` | Kompilasi seluruh butir "Belum dijelaskan dalam blueprint" + inkonsistensi R-01…R-13, untuk diajukan ke pemilik blueprint | **Tertinggi** |
| 4 | `04-Scope-Phase1-vs-Phase2.md` | Batas tegas in-scope / out-of-scope MVP agar tidak terjadi scope creep | Tinggi |
| 5 | `05-Rencana-Sprint-dan-Milestone.md` | Penjabaran 9 sprint Lampiran A.1 menjadi task list + deliverable + definition of done per sprint | Tinggi |
| 6 | `06-Analisis-Risiko-dan-Mitigasi.md` | Register risiko R-01…R-29 dengan owner, status, dan rencana mitigasi | Sedang |

### Kelompok B — Spesifikasi Teknis (rancangan, belum implementasi)

| No | Nama File | Isi | Prioritas |
|---|---|---|---|
| 7 | `07-Spesifikasi-Peran-dan-Matriks-Hak-Akses.md` | Detail 8 role, matriks izin per modul, pemetaan ke permission `spatie/permission`, aturan Policy per model | Tinggi |
| 8 | `08-Spesifikasi-Data-Dictionary.md` | Kamus 21 tabel + tipe, null, key, keterangan, seluruh ENUM dan artinya | Tinggi |
| 9 | `09-Diagram-ERD.md` | Diagram relasi antar 21 entitas (visual) + kardinalitas | Tinggi |
| 10 | `10-Diagram-Alur-Bisnis.md` | Flowchart: Login & tenant identification, PPDB end-to-end, siklus akademik, siklus SPP, alur notifikasi | Tinggi |
| 11 | `11-Spesifikasi-API-Endpoint.md` | Rekap seluruh endpoint per modul + auth level + konvensi request/response/pagination/error | Sedang |
| 12 | `12-Arsitektur-Sistem-dan-Multi-Tenancy.md` | Stack per layer, pola shared schema, alur Global Scope, white-label theming flow, topologi deployment | Tinggi |
| 13 | `13-Spesifikasi-Non-Fungsional-dan-Keamanan.md` | Target performa, skalabilitas, uptime, backup + 12 aspek keamanan (§3.4) | Sedang |
| 14 | `14-Spesifikasi-Template-Notifikasi-wa.me.md` | Template WA per event (PPDB, SPP, Rapor), variabel yang tersedia, aturan URL-encoding | Sedang |
| 15 | `15-Spesifikasi-Format-Import-Export-Excel.md` | Struktur kolom template import siswa/user/nilai dan format file export (siswa, tagihan, keuangan) | Sedang |

### Kelompok C — Rencana Kualitas & Operasional

| No | Nama File | Isi | Prioritas |
|---|---|---|---|
| 16 | `16-Rencana-Pengujian.md` | Skenario uji per user story, uji isolasi tenant (wajib 100%), load test 200 user konkuren, uji PDF & wa.me | Tinggi |
| 17 | `17-Checklist-Go-Live.md` | 10 butir Lampiran A.3 dalam bentuk checklist yang dapat ditandatangani | Sedang |
| 18 | `18-Panduan-Deployment-dan-Infrastruktur.md` | Komponen VPS, konfigurasi Nginx, supervisor queue, Certbot, Cloudflare, backup cron, estimasi biaya | Sedang |
| 19 | `19-Glosarium-dan-Konvensi-Penamaan.md` | Glosarium blueprint (tenant, school_id, PPDB, SPP, rapor, wa.me link, dll.) + konvensi penamaan tabel/endpoint/status | Rendah |
| 20 | `20-Rencana-Lokalisasi-ID-EN.md` | Daftar area teks yang wajib dwibahasa (label, error, placeholder) dan strategi translation key sejak sprint awal | Rendah |

**Urutan pengerjaan yang disarankan:** No. 3 (pertanyaan klarifikasi) lebih dahulu — jawabannya memengaruhi isi dokumen 7, 8, 9, dan 10. Setelah itu Kelompok B, lalu Kelompok C sebelum sprint pertama dimulai.

---

*Analisis ini disusun sepenuhnya dari `SmartSukses_FullBlueprint_v1.0.0.docx`. Tidak ada requirement yang ditambahkan atau diubah.*
