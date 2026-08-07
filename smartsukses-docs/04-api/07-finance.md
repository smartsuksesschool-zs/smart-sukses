> Sumber: SmartSukses_FullBlueprint_v1_0_0.docx (v1.0.0, Agustus 2025) — KONFIDENSIAL, internal only.

# 4.9 Keuangan

## 4.9.1 Tagihan & Pembayaran SPP

| **Method** | **Endpoint** | **Auth Level** | **Deskripsi** |
| --- | --- | --- | --- |
| **GET** | /fee-types | Auth | Daftar jenis tagihan aktif sekolah |
| **POST** | /fee-types | Admin | Buat jenis tagihan baru |
| **PUT** | /fee-types/{id} | Admin | Update jenis tagihan |
| **GET** | /student-fees | Auth | Daftar tagihan. Filter: student_id, status, period, fee_type_id |
| **POST** | /student-fees/generate-bulk | Admin | Generate tagihan massal untuk semua siswa aktif (body: fee_type_id, period, due_date) |
| **GET** | /student-fees/{id} | Auth | Detail satu tagihan + riwayat pembayaran |
| **PATCH** | /student-fees/{id}/waive | Admin | Bebaskan tagihan dengan alasan (status → WAIVED) |
| **GET** | /student-fees/export | Admin | Export laporan tagihan ke Excel. Filter: period, class_id, status |
| **POST** | /payments | Admin | Catat pembayaran baru (body: student_fee_id, amount, method, date, reference) |
| **POST** | /payments/{id}/proof | Admin | Upload bukti pembayaran (multipart) |
| **GET** | /payments | Admin | Riwayat semua pembayaran. Filter: student_id, period, method |

## 4.9.2 Akuntansi & Kas

| **Method** | **Endpoint** | **Auth Level** | **Deskripsi** |
| --- | --- | --- | --- |
| **GET** | /transactions | Auth | Daftar transaksi kas. Filter: type, category, date_from, date_to |
| **POST** | /transactions | Admin | Catat transaksi baru (income atau expense) |
| **PUT** | /transactions/{id} | Admin | Edit transaksi |
| **DELETE** | /transactions/{id} | Admin | Hapus transaksi (soft delete) |
| **GET** | /finance/summary | Auth | Ringkasan keuangan: total income, expense, saldo per bulan. Filter: year, month |
| **GET** | /finance/spp-report | Auth | Laporan SPP: total tagihan, terkumpul, tunggakan per periode |
| **GET** | /finance/export | Admin | Export laporan keuangan ke Excel |
