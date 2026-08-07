> Sumber: SmartSukses_FullBlueprint_v1_0_0.docx (v1.0.0, Agustus 2025) — KONFIDENSIAL, internal only.

# 4.1 Konvensi API

| **Elemen** | **Konvensi** |
| --- | --- |
| Base URL | https://apps.smartsukses.sch.id/api/v1 |
| Content-Type | application/json |
| Auth Header | Authorization: Bearer {token} (Laravel Sanctum) |
| Auth Level: Public | Tidak perlu token — akses bebas (PPDB form, cek status) |
| Auth Level: Auth | Wajib token; akses dibatasi ke data sekolah user tersebut |
| Auth Level: Admin | Wajib token + role SCHOOL_ADMIN / SUPER_ADMIN |
| Auth Level: Super | Wajib token + role SUPER_ADMIN |
| Response sukses | { "success": true, "data": {...}, "message": "..." } |
| Response error | { "success": false, "message": "...", "errors": {...} } |
| Pagination | { "data": [...], "meta": { "total", "page", "per_page", "last_page" } } |
| Timestamp format | ISO 8601: 2025-08-06T10:30:00+07:00 |
| Tenant isolation | Semua endpoint Auth otomatis di-scope ke school_id user yang login |

# 4.2 Autentikasi

| **Method** | **Endpoint** | **Auth Level** | **Deskripsi** |
| --- | --- | --- | --- |
| **POST** | /auth/login | Public | Login email+password → return Bearer token + user info + school config (logo, colors) |
| **POST** | /auth/logout | Auth | Invalidate token sesi aktif |
| **GET** | /auth/me | Auth | Ambil profil user yang sedang login beserta role dan data sekolahnya |
| **POST** | /auth/refresh | Auth | Perbarui token yang hampir kedaluwarsa |
| **POST** | /auth/forgot-password | Public | Kirim link reset password ke email |
| **POST** | /auth/reset-password | Public | Reset password menggunakan token dari email |
| **PATCH** | /auth/me | Auth | Update profil sendiri: nama, telepon, avatar, locale (bahasa) |
| **PATCH** | /auth/me/password | Auth | Ganti password sendiri (wajib kirim current_password) |
