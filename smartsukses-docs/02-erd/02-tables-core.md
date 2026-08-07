> Sumber: SmartSukses_FullBlueprint_v1_0_0.docx (v1.0.0, Agustus 2025) — KONFIDENSIAL, internal only.

# 2.2 Definisi Tabel — Kelompok Core

Tabel: `schools`, `users`. (Tabel `roles` & `model_has_roles` mengikuti skema standar spatie/laravel-permission.)

### Tabel: schools

Tabel inti tenant. Setiap baris mewakili satu cabang sekolah. Super Admin mengelola tabel ini.

| **Field** | **Tipe** | **Null** | **Key** | **Keterangan** |
| --- | --- | --- | --- | --- |
| id | BIGINT UNSIGNED | NO | PK | Auto-increment primary key |
| name | VARCHAR(150) | NO |  | Nama resmi cabang (misal: Smart Sukses School Madani) |
| code | VARCHAR(20) | NO | UQ | Kode unik cabang (misal: MADANI, PUSAT, CINANGKA) |
| slug | VARCHAR(50) | NO | UQ | URL-friendly identifier (misal: madani) |
| logo_url | VARCHAR(500) | YES |  | Path ke file logo untuk white-label UI |
| primary_color | VARCHAR(7) | NO |  | Hex warna utama (#1B3A6B) untuk white-label |
| secondary_color | VARCHAR(7) | NO |  | Hex warna aksen (#E07020) untuk white-label |
| address | TEXT | YES |  | Alamat lengkap sekolah |
| phone | VARCHAR(20) | YES |  | Nomor telepon sekolah |
| email | VARCHAR(150) | YES |  | Email resmi sekolah |
| head_name | VARCHAR(150) | YES |  | Nama kepala sekolah |
| wa_template_ppdb | TEXT | YES |  | Template teks WA untuk notifikasi PPDB |
| wa_template_spp | TEXT | YES |  | Template teks WA untuk notifikasi tagihan |
| wa_template_rapor | TEXT | YES |  | Template teks WA untuk notifikasi rapor |
| is_active | TINYINT(1) | NO | IX | Status aktif tenant. Default: 1 |
| created_at | TIMESTAMP | YES |  |  |
| updated_at | TIMESTAMP | YES |  |  |

### Tabel: users

Tabel pengguna sistem. Semua role (Super Admin, Admin, Guru, Siswa, Ortu) tersimpan di sini. Super Admin memiliki school_id = NULL.

| **Field** | **Tipe** | **Null** | **Key** | **Keterangan** |
| --- | --- | --- | --- | --- |
| id | BIGINT UNSIGNED | NO | PK | Auto-increment primary key |
| school_id | BIGINT UNSIGNED | YES | FK | FK → schools.id. NULL untuk Super Admin |
| name | VARCHAR(150) | NO |  | Nama lengkap pengguna |
| email | VARCHAR(150) | NO | UQ | Email unik, digunakan sebagai username login |
| password | VARCHAR(255) | NO |  | Bcrypt/Argon2 hash |
| phone | VARCHAR(20) | YES |  | Nomor HP (digunakan untuk generate wa.me link) |
| avatar_url | VARCHAR(500) | YES |  | Path foto profil |
| locale | VARCHAR(5) | NO |  | Preferensi bahasa: id atau en. Default: id |
| is_active | TINYINT(1) | NO | IX | Status aktif. Default: 1 |
| email_verified_at | TIMESTAMP | YES |  | Timestamp verifikasi email |
| last_login_at | TIMESTAMP | YES |  | Waktu login terakhir |
| remember_token | VARCHAR(100) | YES |  | Laravel remember token |
| created_at | TIMESTAMP | YES |  |  |
| updated_at | TIMESTAMP | YES |  |  |
