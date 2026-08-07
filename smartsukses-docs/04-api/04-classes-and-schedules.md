> Sumber: SmartSukses_FullBlueprint_v1_0_0.docx (v1.0.0, Agustus 2025) — KONFIDENSIAL, internal only.

# 4.6 Tahun Ajaran, Kelas & Jadwal

| **Method** | **Endpoint** | **Auth Level** | **Deskripsi** |
| --- | --- | --- | --- |
| **GET** | /academic-years | Auth | Daftar tahun ajaran sekolah aktif |
| **POST** | /academic-years | Admin | Buat tahun ajaran baru |
| **PATCH** | /academic-years/{id}/activate | Admin | Set tahun ajaran sebagai aktif (menonaktifkan yang lain) |
| **GET** | /classes | Auth | Daftar kelas untuk tahun ajaran aktif |
| **POST** | /classes | Admin | Buat kelas baru + assign wali kelas |
| **GET** | /classes/{id} | Auth | Detail kelas + daftar siswa + daftar mata pelajaran |
| **POST** | /classes/{id}/students | Admin | Tambahkan siswa ke kelas (bisa multiple) |
| **DELETE** | /classes/{id}/students/{studentId} | Admin | Hapus siswa dari kelas (pindah kelas) |
| **GET** | /subjects | Auth | Daftar mata pelajaran aktif sekolah |
| **POST** | /subjects | Admin | Tambah mata pelajaran baru |
| **GET** | /schedules | Auth | Jadwal. Filter: class_id, teacher_id, day_of_week. Guru: jadwal diri sendiri |
| **POST** | /schedules | Admin | Buat jadwal baru. Validasi konflik otomatis |
