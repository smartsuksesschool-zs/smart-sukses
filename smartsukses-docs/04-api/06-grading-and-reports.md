> Sumber: SmartSukses_FullBlueprint_v1_0_0.docx (v1.0.0, Agustus 2025) — KONFIDENSIAL, internal only.

# 4.8 Penilaian & E-Rapor

| **Method** | **Endpoint** | **Auth Level** | **Deskripsi** |
| --- | --- | --- | --- |
| **GET** | /grades | Auth | Daftar nilai. Filter: student_id, class_subject_id, academic_year_id, grade_type |
| **POST** | /grades | Auth | Input satu nilai (Guru hanya bisa input nilai untuk kelas yang dia ampu) |
| **PUT** | /grades/{id} | Auth | Edit nilai. Hanya bisa selama rapor belum published |
| **POST** | /grades/bulk | Auth | Input nilai massal untuk satu class_subject (array of {student_id, score}) |
| **POST** | /grades/import | Auth | Import nilai dari Excel template |
| **GET** | /grade-configs | Auth | Konfigurasi bobot komponen per mata pelajaran tahun ajaran aktif |
| **POST** | /grade-configs | Admin | Set konfigurasi bobot komponen penilaian |
| **GET** | /report-cards | Auth | Daftar rapor. Filter: student_id, academic_year_id, is_published |
| **GET** | /report-cards/{id} | Auth | Detail rapor + semua nilai final per mapel |
| **POST** | /report-cards/generate | Auth | Generate draft rapor untuk semua siswa di kelas (Wali Kelas only) |
| **POST** | /report-cards/{id}/publish | Auth | Terbitkan rapor — nilai terkunci. Trigger notifikasi ke ortu |
| **GET** | /report-cards/{id}/pdf | Auth | Download rapor dalam format PDF |
