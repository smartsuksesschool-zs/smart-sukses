> Sumber: SmartSukses_FullBlueprint_v1_0_0.docx (v1.0.0, Agustus 2025) — KONFIDENSIAL, internal only.

# 1.4 Kebutuhan Non-Fungsional

| **Kategori** | **Requirement** | **Target / Standar** |
| --- | --- | --- |
| Performa | Waktu load halaman utama | < 3 detik pada koneksi 4G (10 Mbps) |
| Performa | Response time API | < 500ms untuk 95% request |
| Skalabilitas | Jumlah tenant (cabang) | Dapat menampung 10+ cabang tanpa refactoring |
| Skalabilitas | Jumlah user konkuren | Minimal 200 user konkuren pada VPS 2C/2GB |
| Keamanan | Autentikasi | JWT + session, HTTPS wajib, CSRF protection |
| Keamanan | Data isolation | 100% — tidak ada kebocoran data antar tenant |
| Keamanan | Password | Bcrypt/Argon2, minimal 8 karakter, wajib ganti password pertama |
| Ketersediaan | Uptime target | 99% per bulan (~7 jam downtime/bulan) |
| Backup | Frekuensi backup database | Harian otomatis, retensi 30 hari |
| Aksesibilitas | Perangkat yang didukung | Desktop (Chrome/Firefox/Edge) + Mobile (iOS/Android browser) |
| Lokalisasi | Bahasa | Bahasa Indonesia (default) + English, dapat diperluas |
| Audit | Log aktivitas | Semua aksi CRUD dicatat di tabel audit_logs dengan user & timestamp |

**BAGIAN 2**
