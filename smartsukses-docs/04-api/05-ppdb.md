> Sumber: SmartSukses_FullBlueprint_v1_0_0.docx (v1.0.0, Agustus 2025) — KONFIDENSIAL, internal only.

# 4.7 PPDB Online

| **Method** | **Endpoint** | **Auth Level** | **Deskripsi** |
| --- | --- | --- | --- |
| **GET** | /ppdb/schools | Public | Daftar cabang yang membuka PPDB (untuk landing page publik) |
| **GET** | /ppdb/{schoolCode}/info | Public | Info PPDB satu cabang: syarat, jadwal, kuota |
| **POST** | /ppdb/{schoolCode}/register | Public | Submit formulir pendaftaran. Return: nomor pendaftaran |
| **GET** | /ppdb/check-status | Public | Cek status pendaftaran berdasarkan nomor daftar + tanggal lahir |
| **GET** | /admin/ppdb | Admin | Daftar semua pendaftar cabang. Filter: status, academic_year_id |
| **GET** | /admin/ppdb/{id} | Admin | Detail pendaftar |
| **PATCH** | /admin/ppdb/{id}/status | Admin | Update status pendaftaran + catatan alasan |
| **GET** | /admin/ppdb/{id}/wa-link | Admin | Generate wa.me link notifikasi siap kirim untuk pendaftar ini |
| **POST** | /admin/ppdb/{id}/enroll | Admin | Konversi pendaftar menjadi siswa aktif |
