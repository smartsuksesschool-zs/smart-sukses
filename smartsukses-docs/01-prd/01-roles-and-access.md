> Sumber: SmartSukses_FullBlueprint_v1_0_0.docx (v1.0.0, Agustus 2025) — KONFIDENSIAL, internal only.

# 1.1 Peran Pengguna & Matriks Akses

Platform menggunakan sistem RBAC (Role-Based Access Control) berbasis paket spatie/laravel-permission. Setiap pengguna memiliki tepat satu peran utama. Terdapat dua level: Platform Level (lintas semua cabang) dan School Level (terikat pada satu cabang/school_id).

## 1.1.1 Daftar Peran

| **Kode Peran** | **Nama Peran** | **Level** | **Deskripsi Singkat** |
| --- | --- | --- | --- |
| SUPER_ADMIN | Super Administrator | Platform | Akses penuh ke semua cabang, konfigurasi sistem, manajemen tenant |
| SCHOOL_ADMIN | Admin Sekolah | Sekolah | Kelola seluruh operasional satu cabang: siswa, guru, keuangan, pengaturan |
| KEPALA_SEKOLAH | Kepala Sekolah | Sekolah | Monitoring & approval: laporan akademik, keuangan, dashboard cabang |
| GURU | Guru Mata Pelajaran | Sekolah | Input nilai, lihat daftar siswa kelas ajar, jadwal mengajar |
| WALI_KELAS | Wali Kelas | Sekolah | Semua akses guru + kelola rapor & absensi kelas yang diampu |
| SISWA | Siswa | Sekolah | Lihat nilai, jadwal, notifikasi, dan data pribadi sendiri |
| ORANG_TUA | Orang Tua / Wali Murid | Sekolah | Parent portal: nilai anak, absensi anak, tagihan, notifikasi |
| BENDAHARA | Bendahara | Sekolah | Kelola tagihan SPP, catat pembayaran, akuntansi & laporan keuangan |

## 1.1.2 Matriks Izin per Modul (Phase 1)

📌 **Catatan: **Tanda ✅ = akses penuh pada modul tersebut. ⭕ = akses baca/view saja. ❌ = tidak ada akses.

| **Modul** | **SUPER_ADMIN** | **SCHOOL_ADMIN** | **KEPALA** | **GURU/WALI** | **BENDAHARA** | **SISWA** | **ORTU** |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Manajemen Tenant/Cabang | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Data Siswa (SIS) | ✅ | ✅ | ⭕ | ⭕ | ⭕ | ⭕ | ⭕ |
| PPDB Online | ✅ | ✅ | ⭕ | ❌ | ❌ | ❌ | ❌ |
| Kelas & Jadwal | ✅ | ✅ | ⭕ | ⭕ | ❌ | ⭕ | ❌ |
| Input Nilai | ✅ | ✅ | ⭕ | ✅ | ❌ | ❌ | ❌ |
| Generate Rapor | ✅ | ✅ | ⭕ | ✅(Wali) | ❌ | ⭕ | ⭕ |
| Tagihan SPP | ✅ | ✅ | ⭕ | ❌ | ✅ | ❌ | ⭕ |
| Catat Pembayaran | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ |
| Akuntansi & Kas | ✅ | ✅ | ⭕ | ❌ | ✅ | ❌ | ❌ |
| Laporan Keuangan | ✅ | ✅ | ⭕ | ❌ | ✅ | ❌ | ❌ |
| Notifikasi (buat) | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Portal Siswa | ✅ | ✅ | ⭕ | ⭕ | ❌ | ✅ | ❌ |
| Parent Portal | ✅ | ✅ | ⭕ | ❌ | ❌ | ❌ | ✅ |
| White-label Settings | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| User Management | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
