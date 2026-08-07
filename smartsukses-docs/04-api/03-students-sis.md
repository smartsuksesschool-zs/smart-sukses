> Sumber: SmartSukses_FullBlueprint_v1_0_0.docx (v1.0.0, Agustus 2025) — KONFIDENSIAL, internal only.

# 4.5 Siswa (SIS)

| **Method** | **Endpoint** | **Auth Level** | **Deskripsi** |
| --- | --- | --- | --- |
| **GET** | /students | Auth | Daftar siswa. Filter: status, class_id, academic_year_id. Guru: hanya siswa kelas ajarnya |
| **POST** | /students | Admin | Tambah siswa baru. Validasi NIS unik per sekolah |
| **GET** | /students/{id} | Auth | Detail siswa: data pribadi, kelas aktif, info ortu |
| **PUT** | /students/{id} | Admin | Update data siswa |
| **PATCH** | /students/{id}/status | Admin | Ubah status: ACTIVE, GRADUATED, DROPPED_OUT, TRANSFERRED |
| **POST** | /students/{id}/photo | Admin | Upload foto siswa (multipart/form-data). Auto-resize 400×400 |
| **GET** | /students/{id}/grades | Auth | Nilai siswa untuk tahun ajaran aktif. Grouped per mata pelajaran |
| **GET** | /students/{id}/fees | Auth | Tagihan siswa. Filter: status, period. Ortu hanya bisa lihat anaknya sendiri |
| **GET** | /students/export | Admin | Export data siswa ke Excel. Filter: class_id, status |
| **POST** | /students/import | Admin | Import siswa massal dari Excel |
