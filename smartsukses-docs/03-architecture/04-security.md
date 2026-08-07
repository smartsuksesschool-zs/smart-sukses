> Sumber: SmartSukses_FullBlueprint_v1_0_0.docx (v1.0.0, Agustus 2025) — KONFIDENSIAL, internal only.

# 3.4 Arsitektur Keamanan

| **Aspek** | **Implementasi** | **Detail** |
| --- | --- | --- |
| Autentikasi | Laravel Sanctum (SPA mode) | Cookie-based session token untuk web app; Bearer token untuk API mobile future |
| Otorisasi | spatie/laravel-permission + Gate | RBAC berbasis peran; policy per model (StudentPolicy, GradePolicy, dll.) |
| Isolasi Tenant | Eloquent Global Scope | WHERE school_id wajib pada semua query; diverifikasi via unit test |
| Input Validation | Laravel Form Request | Validasi semua input sebelum pemrosesan; sanitasi XSS via htmlspecialchars |
| SQL Injection | Eloquent ORM (parameterized query) | Query langsung via raw SQL dilarang kecuali menggunakan DB::select() dengan binding |
| CSRF Protection | Laravel CSRF Middleware | Token CSRF wajib untuk semua request POST/PUT/DELETE dari form web |
| HTTPS | Let's Encrypt (TLS 1.2/1.3) | Redirect HTTP → HTTPS via Nginx; HSTS header diaktifkan |
| File Upload | Validasi MIME + ukuran | Hanya JPG/PNG/PDF diperbolehkan; disimpan di storage/ (di luar web root) |
| Password | Argon2id (Laravel default) | Minimum 8 karakter; password pertama wajib diganti saat login pertama |
| Rate Limiting | Laravel Throttle Middleware | Login: max 5 percobaan/menit; API: max 60 request/menit per user |
| Audit Log | Custom Middleware + Event | Semua aksi CUD (Create, Update, Delete) dicatat: user, action, table, id, timestamp, IP |
| Backup | mysqldump via cron | Backup harian pukul 02:00 WIB; retensi 30 hari; upload ke Backblaze B2 (Phase 1: lokal) |
