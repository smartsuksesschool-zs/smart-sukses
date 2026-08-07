> Sumber: SmartSukses_FullBlueprint_v1_0_0.docx (v1.0.0, Agustus 2025) — KONFIDENSIAL, internal only.

# 3.2 Arsitektur Multi-Tenant

## 3.2.1 Pola Isolasi Data (Shared Database, Shared Schema)

Semua cabang menggunakan satu database dan satu set tabel yang sama. Isolasi data dilakukan melalui kolom school_id yang wajib hadir di semua tabel bisnis. Laravel Global Scope memastikan setiap query secara otomatis menambahkan kondisi WHERE school_id = [current_tenant_id].

## 3.2.2 Alur Identifikasi Tenant

**1. **Pengguna mengakses apps.smartsukses.sch.id (single URL untuk semua cabang).

**2. **Pengguna memasukkan email dan password di halaman login.

**3. **Sistem melakukan lookup users.email → mendapatkan users.school_id.

**4. **TenantMiddleware (spatie/laravel-multitenancy) melakukan bootstrapping: menyimpan school_id ke context sesi.

**5. **Semua query Eloquent selanjutnya otomatis di-scope dengan school_id via Global Scope.

**6. **Sistem membaca schools.logo_url, schools.primary_color dari database → inject sebagai CSS variables ke halaman.

**7. **Pengguna melihat tampilan white-label sesuai cabangnya.

📌 **Catatan: **Super Admin (school_id = NULL) melewati Global Scope dan dapat mengakses data semua tenant. Ini diimplementasikan dengan pengecekan: if (auth()->user()->isSuperAdmin()) { return $query; } // skip scope.

## 3.2.3 White-Label Theming Flow

Setiap kali pengguna berhasil login, middleware membaca konfigurasi visual dari tabel schools dan menyuntikkan CSS variables ke dalam <head> halaman:

<style>
  :root {
  --color-primary: [schools.primary_color];
  --color-secondary: [schools.secondary_color];
  }
</style>

Logo cabang di-load dari schools.logo_url. Semua komponen UI (Filament admin panel + portal Livewire) menggunakan var(--color-primary) sehingga perubahan tema langsung berlaku di seluruh antarmuka tanpa deployment ulang.
