> Sumber: SmartSukses_FullBlueprint_v1_0_0.docx (v1.0.0, Agustus 2025) — KONFIDENSIAL, internal only.

# Lampiran A — Rekomendasi Pengembangan

## A.1 Urutan Implementasi yang Disarankan

Berdasarkan dependensi antar modul, urutan implementasi Phase 1 yang direkomendasikan adalah sebagai berikut:

| **Sprint** | **Durasi** | **Target** | **Modul / Fitur** |
| --- | --- | --- | --- |
| Sprint 1 | 2 minggu | Foundation | Setup VPS, Laravel, Filament, Multi-tenant, Auth, RBAC, White-label theming, User management |
| Sprint 2 | 2 minggu | Core SIS | Data siswa (SIS), Tahun Ajaran, Kelas & Jadwal, Mata Pelajaran, Import Excel |
| Sprint 3 | 2 minggu | PPDB | Form PPDB publik, Review Admin, Status update, wa.me link generator, Enroll siswa |
| Sprint 4 | 2 minggu | Akademik | Input nilai, Grade config, Auto-hitung nilai akhir, Generate & publish rapor, PDF rapor |
| Sprint 5 | 2 minggu | Keuangan | Jenis tagihan, Generate SPP massal, Catat pembayaran, Upload bukti, Laporan SPP |
| Sprint 6 | 2 minggu | Akuntansi | Buku kas (income/expense), Laporan keuangan, Export Excel, Dashboard Bendahara |
| Sprint 7 | 2 minggu | Portal | Parent Portal (dashboard, nilai, tagihan), Portal Siswa, Portal Guru, Jadwal view |
| Sprint 8 | 2 minggu | Notifikasi | Sistem notifikasi in-app, wa.me bulk link, Template WA, Trigger otomatis |
| Sprint 9 | 2 minggu | Polish & QA | Bilingual (EN), Responsive mobile, Load testing, Security audit, Bug fixing |

📌 **Catatan: **Total estimasi Phase 1: 18 minggu (~4.5 bulan) dengan 1 developer full-stack berpengalaman Laravel. Dapat dipercepat dengan 2 developer (paralel Sprint 3–4 dan Sprint 5–6).
