> Sumber: SmartSukses_FullBlueprint_v1_0_0.docx (v1.0.0, Agustus 2025) — KONFIDENSIAL, internal only.

# 2.2 Definisi Tabel — Kelompok Keuangan

Tabel: `fee_types`, `student_fees`, `payments`, `transactions`.

### Tabel: fee_types

Jenis tagihan yang dapat dibuat oleh Bendahara. Setiap jenis dapat memiliki frekuensi dan nominal berbeda.

| **Field** | **Tipe** | **Null** | **Key** | **Keterangan** |
| --- | --- | --- | --- | --- |
| id | BIGINT UNSIGNED | NO | PK |  |
| school_id | BIGINT UNSIGNED | NO | FK |  |
| name | VARCHAR(100) | NO |  | Nama tagihan: SPP, Uang Gedung, Kegiatan OSIS, dll. |
| amount | DECIMAL(12,2) | NO |  | Nominal tagihan dalam Rupiah |
| frequency | ENUM(MONTHLY,YEARLY,ONCE) | NO |  | Frekuensi penerbitan |
| academic_year_id | BIGINT UNSIGNED | YES | FK | FK → academic_years.id. NULL untuk tagihan berulang |
| description | TEXT | YES |  | Keterangan tambahan |
| is_active | TINYINT(1) | NO |  | Default: 1 |
| created_at | TIMESTAMP | YES |  |  |

### Tabel: student_fees

Tagihan per siswa per periode. Di-generate secara massal oleh Bendahara.

| **Field** | **Tipe** | **Null** | **Key** | **Keterangan** |
| --- | --- | --- | --- | --- |
| id | BIGINT UNSIGNED | NO | PK |  |
| school_id | BIGINT UNSIGNED | NO | FK |  |
| student_id | BIGINT UNSIGNED | NO | FK |  |
| fee_type_id | BIGINT UNSIGNED | NO | FK |  |
| academic_year_id | BIGINT UNSIGNED | YES | FK |  |
| amount | DECIMAL(12,2) | NO |  | Nominal tagihan |
| amount_paid | DECIMAL(12,2) | NO |  | Total yang sudah dibayar. Default: 0.00 |
| due_date | DATE | NO |  | Batas waktu pembayaran |
| period | VARCHAR(7) | NO | IX | Periode: format YYYY-MM (misal: 2025-08 untuk Agustus 2025) |
| status | ENUM(UNPAID,PARTIAL,PAID,WAIVED) | NO | IX | Default: UNPAID |
| waive_reason | VARCHAR(200) | YES |  | Alasan dibebaskan (jika status WAIVED) |
| created_at | TIMESTAMP | YES |  |  |
| updated_at | TIMESTAMP | YES |  |  |

### Tabel: payments

Riwayat setiap transaksi pembayaran. Satu tagihan dapat memiliki beberapa pembayaran (cicilan).

| **Field** | **Tipe** | **Null** | **Key** | **Keterangan** |
| --- | --- | --- | --- | --- |
| id | BIGINT UNSIGNED | NO | PK |  |
| school_id | BIGINT UNSIGNED | NO | FK |  |
| student_fee_id | BIGINT UNSIGNED | NO | FK | FK → student_fees.id |
| student_id | BIGINT UNSIGNED | NO | FK | FK → students.id (denormalized untuk query cepat) |
| payment_method | ENUM(CASH,TRANSFER,PAYMENT_GATEWAY) | NO |  |  |
| amount_paid | DECIMAL(12,2) | NO |  | Jumlah yang dibayarkan |
| reference_number | VARCHAR(100) | YES |  | Nomor referensi transfer / kwitansi |
| proof_url | VARCHAR(500) | YES |  | Path bukti pembayaran yang diupload |
| payment_date | DATE | NO |  | Tanggal pembayaran |
| received_by | BIGINT UNSIGNED | NO | FK | FK → users.id (bendahara yang mencatat) |
| notes | TEXT | YES |  |  |
| created_at | TIMESTAMP | YES |  |  |

### Tabel: transactions

Buku kas sekolah. Mencatat semua pemasukan dan pengeluaran umum di luar tagihan SPP.

| **Field** | **Tipe** | **Null** | **Key** | **Keterangan** |
| --- | --- | --- | --- | --- |
| id | BIGINT UNSIGNED | NO | PK |  |
| school_id | BIGINT UNSIGNED | NO | FK |  |
| type | ENUM(INCOME,EXPENSE) | NO | IX | Jenis transaksi |
| category | VARCHAR(100) | NO |  | Kategori: Gaji, Pembelian Alat, Dana BOS, Sumbangan, dll. |
| amount | DECIMAL(12,2) | NO |  | Jumlah dalam Rupiah |
| description | TEXT | YES |  | Keterangan detail transaksi |
| reference_number | VARCHAR(100) | YES |  | Nomor nota / referensi |
| proof_url | VARCHAR(500) | YES |  | Scan nota / bukti |
| transaction_date | DATE | NO |  | Tanggal transaksi |
| created_by | BIGINT UNSIGNED | NO | FK | FK → users.id |
| created_at | TIMESTAMP | YES |  |  |
