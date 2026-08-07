> Sumber: SmartSukses_FullBlueprint_v1_0_0.docx (v1.0.0, Agustus 2025) — KONFIDENSIAL, internal only.

# 2.2 Definisi Tabel — Kelompok PPDB

Tabel: `ppdb_registrations`.

### Tabel: ppdb_registrations

Data pendaftar PPDB. Dapat diisi tanpa login (public form). Setelah diterima, data ini menjadi sumber untuk membuat record students.

| **Field** | **Tipe** | **Null** | **Key** | **Keterangan** |
| --- | --- | --- | --- | --- |
| id | BIGINT UNSIGNED | NO | PK |  |
| school_id | BIGINT UNSIGNED | NO | FK | FK → schools.id |
| academic_year_id | BIGINT UNSIGNED | YES | FK | FK → academic_years.id (tahun ajaran yang didaftar) |
| reg_number | VARCHAR(20) | NO | UQ | Nomor pendaftaran unik: [KODE_CABANG]-[TAHUN]-[SEQ] |
| full_name | VARCHAR(150) | NO |  |  |
| gender | ENUM(L,P) | NO |  |  |
| birth_date | DATE | YES |  |  |
| origin_school | VARCHAR(150) | YES |  | Asal SMP/MTs |
| parent_name | VARCHAR(150) | YES |  |  |
| parent_phone | VARCHAR(20) | YES |  |  |
| parent_email | VARCHAR(150) | YES |  |  |
| documents | JSON | YES |  | Path file dokumen yang diupload (array URL) |
| status | ENUM(REGISTERED,DOCUMENT_REVIEW,PASSED,FAILED,ENROLLED) | NO | IX | Status alur PPDB. Default: REGISTERED |
| status_notes | TEXT | YES |  | Catatan alasan perubahan status |
| converted_student_id | BIGINT UNSIGNED | YES | FK | FK → students.id (setelah di-enroll) |
| registered_at | TIMESTAMP | NO |  | Waktu submit formulir |
| created_at | TIMESTAMP | YES |  |  |
| updated_at | TIMESTAMP | YES |  |  |
