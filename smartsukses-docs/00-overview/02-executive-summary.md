> Sumber: SmartSukses_FullBlueprint_v1_0_0.docx (v1.0.0, Agustus 2025) — KONFIDENSIAL, internal only.

# Ringkasan Eksekutif

Smart Sukses School adalah jaringan SMA Terbuka yang terdiri dari beberapa cabang (Smart Pusat, Smart Madani, Smart Cinangka, dan cabang-cabang yang akan berkembang). Saat ini pengelolaan operasional — mulai dari data siswa, penilaian, tagihan SPP, hingga komunikasi dengan orang tua — masih dilakukan secara manual menggunakan spreadsheet dan aplikasi terpisah-pisah, sehingga sulit dimonitor secara terpusat oleh Pusat.

## Masalah yang Diselesaikan

- Data siswa tersebar di spreadsheet masing-masing cabang — tidak ada sumber kebenaran tunggal (single source of truth)

- Kepala Sekolah dan Admin Pusat tidak bisa memonitor kondisi akademik dan keuangan semua cabang secara real-time

- Proses PPDB masih manual, rawan kesalahan data dan lambat dalam komunikasi status kepada pendaftar

- Tagihan SPP sering terlambat diterbitkan dan sulit dilacak pembayarannya

- Orang tua tidak memiliki akses langsung ke perkembangan nilai, absensi, dan tagihan anak

- Penyebaran pengumuman masih via WhatsApp personal, tidak terstruktur

## Solusi

Platform SaaS berbasis web (apps.smartsukses.sch.id) dengan arsitektur multi-tenant single domain. Setiap cabang memiliki data terisolasi (diidentifikasi melalui school_id) namun berjalan dalam satu aplikasi yang sama. Setiap cabang dapat memiliki tampilan white-label sendiri (logo dan warna khas). Semua pengguna mengakses URL yang sama; visibilitas data ditentukan oleh peran (role) dan sekolah tempat pengguna terdaftar.

## Cakupan Phase 1 (MVP)

- Modul 1 — Administrasi & Akademik: SIS, PPDB Online, Manajemen Kelas & Jadwal, E-Rapor & Penilaian

- Modul 3 — Keuangan: Tagihan SPP Digital, Pencatatan Pembayaran, Akuntansi & Kas

- Modul 5 — Komunikasi & Portal: Parent Portal, Portal Guru & Siswa, Notifikasi via wa.me

- Cross-cutting: Multi-tenant, White-label UI, RBAC (Role-Based Access Control), Bilingual ID/EN

## Target Skala Awal

| **Parameter** | **Detail** |
| --- | --- |
| Jumlah Cabang | 3 cabang (Pusat, Madani, Cinangka) — dapat ditambah tanpa pengembangan ulang |
| Siswa per Cabang | 50–200 siswa |
| Total Pengguna Awal | ~800–1.500 akun (siswa, guru, orang tua, admin, bendahara) |
| Bahasa Antarmuka | Bilingual — Bahasa Indonesia (default) & English |
| Kurikulum | Kustom per sekolah — tidak mengikuti format Merdeka/K13 secara kaku |

## Stack Teknologi

| **Layer** | **Teknologi** | **Lisensi / Biaya** |
| --- | --- | --- |
| Backend Framework | Laravel 11 (PHP 8.3) | Open-source (MIT) |
| Admin Panel | Filament PHP 3 | Open-source (MIT) |
| Multi-tenancy | spatie/laravel-multitenancy | Open-source (MIT) |
| Frontend Portal | Livewire 3 + Alpine.js | Open-source (MIT) |
| Database | MySQL 8.0 | Open-source (GPL) |
| Cache & Queue | Laravel Cache (database driver) | Gratis (dalam Laravel) |
| Storage | Local disk / Backblaze B2 | Gratis s.d. 10 GB (Backblaze) |
| DNS & SSL | Cloudflare Free + Let's Encrypt | Gratis |
| Hosting | VPS 2 Core / 2 GB RAM | ~Rp 85.000–120.000/bln |
| WhatsApp Notifikasi | wa.me Manual Link (Phase 1) | Gratis |
| Video Conference | Google Meet Link Generation | Gratis |
