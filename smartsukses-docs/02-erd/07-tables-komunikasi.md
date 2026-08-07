> Sumber: SmartSukses_FullBlueprint_v1_0_0.docx (v1.0.0, Agustus 2025) — KONFIDENSIAL, internal only.

# 2.2 Definisi Tabel — Kelompok Komunikasi

Tabel: `notifications`, `notification_reads`.

### Tabel: notifications

Pengumuman dan notifikasi yang dibuat oleh Admin atau dipicu otomatis oleh sistem.

| **Field** | **Tipe** | **Null** | **Key** | **Keterangan** |
| --- | --- | --- | --- | --- |
| id | BIGINT UNSIGNED | NO | PK |  |
| school_id | BIGINT UNSIGNED | NO | FK |  |
| sender_id | BIGINT UNSIGNED | YES | FK | FK → users.id. NULL jika notifikasi sistem otomatis |
| title | VARCHAR(200) | NO |  | Judul notifikasi |
| message | TEXT | NO |  | Isi pesan lengkap |
| type | ENUM(ANNOUNCEMENT,BILLING,ACADEMIC,EMERGENCY,SYSTEM) | NO | IX | Kategori notifikasi |
| target_type | ENUM(ALL,CLASS,INDIVIDUAL) | NO |  | Target penerima |
| target_id | BIGINT UNSIGNED | YES |  | class_id atau user_id jika bukan ALL |
| wa_template | TEXT | YES |  | Template teks WA yang sudah diisi variabel |
| is_draft | TINYINT(1) | NO |  | 1=draft, 0=terkirim. Default: 1 |
| sent_at | TIMESTAMP | YES |  | Waktu notifikasi diterbitkan |
| created_at | TIMESTAMP | YES |  |  |
| updated_at | TIMESTAMP | YES |  |  |

### Tabel: notification_reads

Tabel pivot yang merecord siapa saja yang sudah membaca notifikasi tertentu.

| **Field** | **Tipe** | **Null** | **Key** | **Keterangan** |
| --- | --- | --- | --- | --- |
| id | BIGINT UNSIGNED | NO | PK |  |
| notification_id | BIGINT UNSIGNED | NO | FK | FK → notifications.id |
| user_id | BIGINT UNSIGNED | NO | FK | FK → users.id |
| read_at | TIMESTAMP | NO |  | Waktu pertama kali dibaca |
