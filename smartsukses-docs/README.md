# Smart Sukses School — Blueprint Docs (v1.0.0)

Dokumentasi ini adalah pecahan dari `SmartSukses_FullBlueprint_v1_0_0.docx` (Agustus 2025), disusun ulang menjadi file-file markdown modular agar mudah dikonsumsi oleh developer maupun AI coding assistant (Claude Code, Cursor, dll). Setiap file berdiri sendiri dan berisi satu topik.

**Platform:** apps.smartsukses.sch.id — SaaS Manajemen Sekolah Multi-Cabang
**Stack:** Laravel 11 · Filament PHP 3 · MySQL 8 · VPS (Nginx, Ubuntu 22.04)
**Model:** Multi-Tenant · Single Domain · White-Label per Cabang (isolasi via `school_id`)

> ⚠️ Dokumen KONFIDENSIAL — hanya untuk penggunaan internal tim Smart Sukses School.

## Struktur Folder

```
smartsukses-docs/
├── 00-overview/          ← Konteks umum: baca ini dulu
│   ├── 01-document-control.md      Riwayat revisi & glosarium istilah
│   └── 02-executive-summary.md     Masalah, solusi, scope MVP, target skala, stack ringkas
│
├── 01-prd/               ← Product Requirements Document
│   ├── 01-roles-and-access.md      8 peran RBAC + matriks izin per modul
│   ├── 02-features-phase1.md       Spesifikasi fitur MVP (SIS, PPDB, Rapor, SPP, Portal, dst)
│   ├── 03-phase2-overview.md       Roadmap fitur Phase 2 (LMS, CBT, Payroll, Payment Gateway)
│   └── 04-non-functional-requirements.md  Performa, keamanan, uptime, backup, audit
│
├── 02-erd/               ← Skema Database (21 tabel, shared schema multi-tenant)
│   ├── 01-entity-list.md           Daftar 21 entitas + aturan isolasi tenant
│   ├── 02-tables-core.md           schools, users
│   ├── 03-tables-akademik.md       students, academic_years, classes, student_classes,
│   │                               subjects, class_subjects, schedules
│   ├── 04-tables-ppdb.md           ppdb_registrations
│   ├── 05-tables-penilaian.md      grades, report_cards, grade_configs
│   ├── 06-tables-keuangan.md       fee_types, student_fees, payments, transactions
│   └── 07-tables-komunikasi.md     notifications, notification_reads
│
├── 03-architecture/      ← Arsitektur Sistem
│   ├── 01-tech-stack.md            Stack detail per layer + versi
│   ├── 02-multi-tenant.md          Pola isolasi data, alur identifikasi tenant, white-label flow
│   ├── 03-deployment.md            Topologi VPS single-server + konfigurasi Nginx
│   └── 04-security.md              Auth, RBAC, tenant isolation, CSRF, SQL injection, dsb
│
├── 04-api/               ← API Endpoint Map (RESTful)
│   ├── 01-conventions-and-auth.md  Konvensi API + endpoint autentikasi
│   ├── 02-tenant-and-users.md      Super Admin (manajemen tenant) + user & akses
│   ├── 03-students-sis.md          Endpoint Siswa (SIS)
│   ├── 04-classes-and-schedules.md Tahun ajaran, kelas & jadwal
│   ├── 05-ppdb.md                  PPDB Online
│   ├── 06-grading-and-reports.md   Penilaian & E-Rapor
│   ├── 07-finance.md               Tagihan/pembayaran SPP + akuntansi & kas
│   ├── 08-notifications.md         Notifikasi & pengumuman
│   └── 09-portal-and-dashboard.md  Parent portal, dashboard guru & siswa
│
└── 05-roadmap/           ← Rencana Eksekusi
    ├── 01-implementation-order.md  Urutan sprint Phase 1 berdasarkan dependensi modul
    ├── 02-infrastructure-costs.md  Estimasi biaya bulanan setelah go-live
    └── 03-golive-checklist.md      Checklist wajib sebelum go-live
```

## Alur Baca yang Disarankan

| Peran | Mulai dari |
| --- | --- |
| Product Owner / Reviewer | `00-overview` → `01-prd` → `05-roadmap` |
| Backend Developer | `00-overview/02` → `03-architecture` → `02-erd` → `04-api` |
| Frontend / Portal Developer | `01-prd/01` (roles) → `04-api` → `01-prd/02` (fitur) |
| DevOps | `03-architecture/03-deployment.md` → `05-roadmap/03-golive-checklist.md` |
| AI Coding Assistant | Muat `00-overview/02` + file spesifik sesuai task (mis. kerjakan modul PPDB → `01-prd/02` bagian PPDB, `02-erd/04`, `04-api/05`) |

## Aturan Kunci (Wajib Dipatuhi Semua Modul)

1. **Tenant isolation** — semua query WAJIB melalui global scope `WHERE school_id = auth()->user()->school_id`. Detail: `03-architecture/02-multi-tenant.md`.
2. **RBAC** — setiap endpoint/halaman mengikuti matriks izin di `01-prd/01-roles-and-access.md`.
3. **Urutan implementasi** — ikuti dependensi sprint di `05-roadmap/01-implementation-order.md` (Foundation → SIS → Akademik → Keuangan → Portal).
4. **Bilingual** — semua string UI melalui sistem lokalisasi (ID default, EN).
