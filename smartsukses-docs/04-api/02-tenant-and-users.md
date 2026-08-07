> Sumber: SmartSukses_FullBlueprint_v1_0_0.docx (v1.0.0, Agustus 2025) — KONFIDENSIAL, internal only.

# 4.3 Super Admin — Manajemen Tenant

| **Method** | **Endpoint** | **Auth Level** | **Deskripsi** |
| --- | --- | --- | --- |
| **GET** | /admin/schools | Super | Daftar semua cabang sekolah + statistik ringkas (jumlah siswa, guru) |
| **POST** | /admin/schools | Super | Daftarkan cabang baru (buat tenant baru) |
| **GET** | /admin/schools/{id} | Super | Detail lengkap satu cabang termasuk konfigurasi white-label |
| **PUT** | /admin/schools/{id} | Super | Update data dan konfigurasi white-label cabang |
| **PATCH** | /admin/schools/{id}/toggle | Super | Aktifkan / nonaktifkan cabang |
| **GET** | /admin/schools/{id}/stats | Super | Statistik: jumlah siswa, guru, tagihan terkumpul bulan ini, tunggakan |
| **GET** | /admin/dashboard | Super | Dashboard ringkasan semua cabang: total siswa, total SPP terkumpul, PPDB aktif |

# 4.4 User & Akses

| **Method** | **Endpoint** | **Auth Level** | **Deskripsi** |
| --- | --- | --- | --- |
| **GET** | /users | Admin | Daftar user di sekolah aktif. Filter: role, is_active |
| **POST** | /users | Admin | Buat user baru + assign role. Body: name, email, phone, role |
| **GET** | /users/{id} | Admin | Detail user beserta role dan histori login |
| **PUT** | /users/{id} | Admin | Update data user (bukan password) |
| **DELETE** | /users/{id} | Admin | Nonaktifkan user (soft deactivate, bukan hard delete) |
| **POST** | /users/import | Admin | Import user massal dari file Excel (.xlsx). Return: sukses + daftar error baris |
| **POST** | /users/{id}/reset-password | Admin | Reset password user → set temporary password → kirim notifikasi |
