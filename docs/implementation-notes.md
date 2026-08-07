# Catatan Implementasi

Catatan penyimpangan implementasi terhadap `smartsukses-docs/`. Folder
`smartsukses-docs/` adalah satu-satunya sumber requirement dan **tidak diubah**;
dokumen ini hanya mencatat titik-titik di mana kode sengaja berbeda dari ERD,
beserta alasan dan referensi dokumennya.

## Phase 2 — Authentication & RBAC

### 1. Kolom `users.must_change_password`

| | |
| --- | --- |
| **Status** | Tambahan di luar ERD |
| **Referensi** | `03-architecture/04-security.md` — baris "Password" |
| **Bertentangan dengan** | `02-erd/02-tables-core.md` — Tabel: users |

Dokumen keamanan mensyaratkan: *"Argon2id (Laravel default) | Minimum 8 karakter;
password pertama wajib diganti saat login pertama."* ERD tidak menyediakan atribut
yang merepresentasikan status tersebut, sedangkan requirement-nya menuntut penanda
persisten per pengguna — bertahan lintas sesi dan lintas perangkat, sehingga tidak
bisa dititipkan ke session.

Kolom: `BOOLEAN NOT NULL DEFAULT 0`. Penegakan pada
`app/Http/Middleware/EnsurePasswordIsChanged.php` (mengunci seluruh halaman panel ke
halaman profil selama flag menyala) dan aksi "Reset Password" pada
`app/Filament/Resources/UserResource.php` (menyalakan flag saat admin membuat password
sementara). Flag padam otomatis begitu pengguna mengganti passwordnya sendiri
(`App\Models\User::booted()`).

**Jika ERD ingin dipertahankan literal:** hapus kolom dari migration, middleware dari
`AdminPanelProvider`, dan hook `booted()` di model User. Requirement 3.4 poin
"password pertama wajib diganti" otomatis tidak terpenuhi.

### 2. Tabel spatie lebih banyak daripada yang didaftar ERD

| | |
| --- | --- |
| **Status** | Konsekuensi paket, bukan keputusan desain |
| **Referensi** | `02-erd/02-tables-core.md` — *"mengikuti skema standar spatie/laravel-permission"* |

`02-erd/01-entity-list.md` hanya mendaftar `roles` dan `model_has_roles`. Skema standar
spatie membuat lima tabel: ditambah `permissions`, `model_has_permissions`, dan
`role_has_permissions`. Tiga tabel ekstra ini tidak dipakai untuk data bisnis dan tidak
memiliki `school_id` — definisi peran & izin bersifat platform-wide.

### 3. Tabel `personal_access_tokens`

| | |
| --- | --- |
| **Status** | Di luar 21 tabel ERD |
| **Referensi** | `03-architecture/01-tech-stack.md`; `04-api/01-conventions-and-auth.md` |

Dibawa oleh `laravel/sanctum` yang diwajibkan tech stack (`Authorization: Bearer {token}`).
Tabelnya sudah dimigrasikan sebagai fondasi; endpoint `/api/v1/auth/*` sendiri belum
diimplementasikan (modul terpisah).

### 4. DEFAULT pada `schools.primary_color` & `schools.secondary_color`

| | |
| --- | --- |
| **Status** | Penyimpangan kecil / kosmetik |
| **Referensi** | `02-erd/02-tables-core.md`; `03-architecture/02-multi-tenant.md` §3.2.3 |

ERD menandai kedua kolom NOT NULL tanpa default. Implementasi memberi default
`#1B3A6B` dan `#E07020` — nilai yang dicontohkan ERD dan bagian white-label theming —
supaya pembuatan cabang baru tanpa konfigurasi warna tidak gagal dan UI selalu punya
warna yang valid untuk di-inject sebagai CSS variable.

### 5. Konfigurasi di luar ERD

| Konfigurasi | Nilai | Referensi |
| --- | --- | --- |
| `SESSION_LIFETIME` | `480` (8 jam idle) | `01-prd/02-features-phase1.md` — AUTH-01 poin 4 |
| `HASH_DRIVER` | `argon2id` | `03-architecture/04-security.md` — baris "Password" |

Keduanya setelan `.env`, tidak menyentuh skema database.

## Catatan lain

- **Paket `spatie/laravel-multitenancy` tidak dipasang** meski tercantum di tech stack 3.1.
  Alur identifikasi tenant pada `03-architecture/02-multi-tenant.md` §3.2.2 berbasis
  `users.school_id` setelah login — bukan domain/subdomain — sehingga kebutuhannya
  terpenuhi oleh Eloquent Global Scope, persis seperti yang dicontohkan dokumen itu
  sendiri (`App\Models\Scopes\SchoolScope`). Paket tersebut tetap bisa ditambahkan
  belakangan bila dibutuhkan tenancy yang sadar queue/job.
- **Test suite berjalan di SQLite in-memory** (`phpunit.xml`), sedangkan runtime memakai
  MySQL 8. Verifikasi perilaku spesifik MySQL perlu smoke test terpisah.
