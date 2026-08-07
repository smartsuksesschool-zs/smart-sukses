> Sumber: SmartSukses_FullBlueprint_v1_0_0.docx (v1.0.0, Agustus 2025) — KONFIDENSIAL, internal only.

# 4.11 Parent Portal & Dashboard

| **Method** | **Endpoint** | **Auth Level** | **Deskripsi** |
| --- | --- | --- | --- |
| **GET** | /parent/children | Auth | Daftar anak yang terdaftar sebagai anak dari user yang login (role ORANG_TUA) |
| **GET** | /parent/children/{studentId}/summary | Auth | Dashboard anak: nilai terbaru 5 mapel, hadir bulan ini, tagihan pending |
| **GET** | /parent/children/{studentId}/grades | Auth | Nilai lengkap anak per tahun ajaran aktif |
| **GET** | /parent/children/{studentId}/fees | Auth | Semua tagihan anak + status + riwayat bayar |
| **GET** | /parent/children/{studentId}/schedule | Auth | Jadwal pelajaran kelas anak hari ini & minggu ini |
| **GET** | /teacher/dashboard | Auth | Dashboard guru: jadwal hari ini, kelas aktif, notifikasi masuk |
| **GET** | /teacher/classes | Auth | Kelas yang diampu guru yang login (tahun ajaran aktif) |
| **GET** | /student/dashboard | Auth | Dashboard siswa: jadwal hari ini, 5 nilai terbaru, notifikasi |
| **GET** | /student/schedule | Auth | Jadwal pelajaran siswa (tahun ajaran aktif) |
| **GET** | /student/grades | Auth | Nilai siswa yang login (tahun ajaran aktif) |
