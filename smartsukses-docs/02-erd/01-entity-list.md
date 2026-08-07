> Sumber: SmartSukses_FullBlueprint_v1_0_0.docx (v1.0.0, Agustus 2025) — KONFIDENSIAL, internal only.

# 2.1 Daftar Entitas

Platform menggunakan 21 tabel utama dalam satu database MySQL yang di-share antar tenant, dengan isolasi data melalui kolom school_id (Shared Database, Shared Schema pattern).

| **No.** | **Nama Tabel** | **Kelompok** | **Deskripsi Singkat** |
| --- | --- | --- | --- |
| 1 | schools | Core | Data dan konfigurasi setiap cabang sekolah (tenant) |
| 2 | users | Core | Semua pengguna sistem (multi-role, multi-tenant) |
| 3 | roles | Core | Definisi peran (spatie/permission) |
| 4 | model_has_roles | Core | Pivot: assignment peran ke user |
| 5 | students | Akademik | Data induk siswa per cabang |
| 6 | academic_years | Akademik | Tahun ajaran (semester 1 & 2) per cabang |
| 7 | classes | Akademik | Rombongan belajar (rombel) per tahun ajaran |
| 8 | student_classes | Akademik | Pivot: siswa masuk kelas tertentu per tahun ajaran |
| 9 | subjects | Akademik | Daftar mata pelajaran per cabang |
| 10 | class_subjects | Akademik | Mata pelajaran yang diajar guru di kelas tertentu |
| 11 | schedules | Akademik | Jadwal pelajaran per class_subject |
| 12 | ppdb_registrations | PPDB | Data pendaftaran calon siswa baru |
| 13 | grades | Penilaian | Nilai siswa per komponen per mata pelajaran |
| 14 | report_cards | Penilaian | Rapor final per siswa per tahun ajaran |
| 15 | grade_configs | Penilaian | Konfigurasi bobot komponen penilaian per mata pelajaran |
| 16 | fee_types | Keuangan | Jenis tagihan (SPP, uang gedung, dll.) |
| 17 | student_fees | Keuangan | Tagihan per siswa per periode |
| 18 | payments | Keuangan | Riwayat pembayaran tagihan |
| 19 | transactions | Keuangan | Buku kas umum sekolah (pemasukan & pengeluaran) |
| 20 | notifications | Komunikasi | Pengumuman dan notifikasi per cabang |
| 21 | notification_reads | Komunikasi | Pivot: status baca notifikasi per user |

📌 **Catatan: **Setiap tabel yang memiliki kolom school_id otomatis terisolasi per tenant. Semua query pada aplikasi WAJIB menggunakan global scope Laravel yang menambahkan WHERE school_id = auth()->user()->school_id secara otomatis.
