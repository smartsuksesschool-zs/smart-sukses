> Sumber: SmartSukses_FullBlueprint_v1_0_0.docx (v1.0.0, Agustus 2025) — KONFIDENSIAL, internal only.

# 2.2 Definisi Tabel — Kelompok Penilaian

Tabel: `grades`, `report_cards`, `grade_configs`.

### Tabel: grades

Nilai siswa per komponen penilaian per mata pelajaran. Satu baris = satu entri nilai dari satu guru.

| **Field** | **Tipe** | **Null** | **Key** | **Keterangan** |
| --- | --- | --- | --- | --- |
| id | BIGINT UNSIGNED | NO | PK |  |
| school_id | BIGINT UNSIGNED | NO | FK |  |
| student_id | BIGINT UNSIGNED | NO | FK | FK → students.id |
| class_subject_id | BIGINT UNSIGNED | NO | FK | FK → class_subjects.id |
| academic_year_id | BIGINT UNSIGNED | NO | FK |  |
| grade_type | ENUM(DAILY,MIDTERM,FINAL,ASSIGNMENT,SKILL,ATTITUDE) | NO | IX | Komponen penilaian |
| score | DECIMAL(5,2) | NO |  | Nilai dalam skala 0.00 – 100.00 |
| weight | DECIMAL(4,2) | YES |  | Bobot komponen (misal: 0.40 untuk 40%). Nullable jika mengacu grade_configs |
| description | VARCHAR(200) | YES |  | Keterangan tambahan (misal: "Ulangan Harian Bab 3") |
| graded_by | BIGINT UNSIGNED | NO | FK | FK → users.id (guru yang menginput) |
| graded_at | TIMESTAMP | NO |  | Waktu nilai diinput |
| created_at | TIMESTAMP | YES |  |  |
| updated_at | TIMESTAMP | YES |  |  |

### Tabel: report_cards

Rapor final per siswa per semester. Dibuat oleh Wali Kelas. Setelah published, data terkunci.

| **Field** | **Tipe** | **Null** | **Key** | **Keterangan** |
| --- | --- | --- | --- | --- |
| id | BIGINT UNSIGNED | NO | PK |  |
| school_id | BIGINT UNSIGNED | NO | FK |  |
| student_id | BIGINT UNSIGNED | NO | FK |  |
| class_id | BIGINT UNSIGNED | NO | FK |  |
| academic_year_id | BIGINT UNSIGNED | NO | FK |  |
| final_scores | JSON | NO |  | Nilai akhir per mapel: {"MTK": 87.5, "BIN": 90, ...} |
| attitude_score | ENUM(A,B,C,D) | YES |  | Nilai sikap |
| attend_present | SMALLINT | YES |  | Jumlah hadir |
| attend_sick | SMALLINT | YES |  | Jumlah sakit |
| attend_permission | SMALLINT | YES |  | Jumlah izin |
| attend_absent | SMALLINT | YES |  | Jumlah alpa |
| rank_in_class | SMALLINT | YES |  | Peringkat di kelas |
| homeroom_notes | TEXT | YES |  | Catatan wali kelas |
| is_published | TINYINT(1) | NO | IX | Status penerbitan. 0=Draft, 1=Published. Default: 0 |
| published_at | TIMESTAMP | YES |  | Waktu rapor diterbitkan |
| published_by | BIGINT UNSIGNED | YES | FK | FK → users.id (wali kelas yang menerbitkan) |
| created_at | TIMESTAMP | YES |  |  |
| updated_at | TIMESTAMP | YES |  |  |

### Tabel: grade_configs

Konfigurasi bobot komponen penilaian per mata pelajaran per tahun ajaran. Dapat dibuat berbeda-beda per mapel.

| **Field** | **Tipe** | **Null** | **Key** | **Keterangan** |
| --- | --- | --- | --- | --- |
| id | BIGINT UNSIGNED | NO | PK |  |
| school_id | BIGINT UNSIGNED | NO | FK |  |
| subject_id | BIGINT UNSIGNED | NO | FK |  |
| academic_year_id | BIGINT UNSIGNED | NO | FK |  |
| components | JSON | NO |  | Definisi komponen & bobot: [{"type":"DAILY","weight":0.40},{"type":"MIDTERM","weight":0.30},...] |
| created_by | BIGINT UNSIGNED | NO | FK | FK → users.id |
| created_at | TIMESTAMP | YES |  |  |
