> Sumber: SmartSukses_FullBlueprint_v1_0_0.docx (v1.0.0, Agustus 2025) — KONFIDENSIAL, internal only.

# 3.1 Stack Teknologi Detail

| **Layer** | **Komponen** | **Versi** | **Peran dalam Sistem** |
| --- | --- | --- | --- |
| Backend | PHP | 8.3+ | Bahasa server-side utama |
| Backend | Laravel | 11.x | Framework MVC: routing, ORM (Eloquent), queue, cache, auth |
| Admin Panel | Filament PHP | 3.x | UI admin panel, form builder, table builder, multi-tenant theming |
| Multi-tenancy | spatie/laravel-multitenancy | 3.x | Tenant identification, tenancy bootstrapping, global scopes |
| Auth & RBAC | Laravel Sanctum + spatie/permission | – | Token auth, role & permission management |
| Frontend Portal | Livewire | 3.x | Reactive component PHP-driven (tanpa JS build pipeline) |
| Frontend Utils | Alpine.js | 3.x | Ringan JS untuk interaktivitas sederhana (dropdown, modal) |
| CSS Framework | Tailwind CSS | 3.x | Utility-first styling (via Filament & Livewire) |
| Database | MySQL | 8.0+ | Relasional database utama (shared schema multi-tenant) |
| Cache | Laravel Cache (DB driver) | – | Caching query berat. Dapat diupgrade ke Redis di Phase 2 |
| Queue | Laravel Queue (DB driver) | – | Background jobs (generate tagihan massal, PDF rapor) |
| File Storage | Local Disk | – | Penyimpanan upload (foto, bukti bayar). Backblaze B2 di Phase 2 |
| PDF Generation | DomPDF / Browsershot | – | Generate rapor PDF dan laporan keuangan |
| Email | SMTP (Gmail / Mailtrap) | – | Notifikasi email (reset password, verifikasi) |
| SSL | Let's Encrypt (via Certbot) | – | HTTPS otomatis dan gratis |
| DNS & Proxy | Cloudflare (Free) | – | DNS management, DDoS protection, CDN cache statis |
| Web Server | Nginx | – | Reverse proxy + PHP-FPM |
| OS | Ubuntu | 22.04 LTS | Sistem operasi VPS |
| VPS | Niagahoster / IDCloudHost | – | 2 Core, 2 GB RAM, 40 GB SSD. ~Rp 85.000–120.000/bln |
