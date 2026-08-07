> Sumber: SmartSukses_FullBlueprint_v1_0_0.docx (v1.0.0, Agustus 2025) — KONFIDENSIAL, internal only.

# 1.2 Kebutuhan Fitur Phase 1

Setiap fitur ditulis dalam format User Story: "Sebagai [peran], saya dapat [aksi], sehingga [manfaat]". Acceptance Criteria mendefinisikan kondisi minimum agar fitur dianggap selesai.

## 1.2.1 Cross-Cutting: Multi-Tenant, White-Label & Autentikasi

| **ID** | **User Story** | **Acceptance Criteria** |
| --- | --- | --- |
| AUTH-01 | Sebagai Pengguna, saya dapat login menggunakan email dan password. | 1. Form login minimal memiliki field email + password. 2. Jika kredensial salah, tampil pesan error yang tidak mengungkap detail sistem. 3. Berhasil login menghasilkan JWT/session token. 4. Token kedaluwarsa setelah 8 jam tidak aktif. |
| AUTH-02 | Sebagai Sistem, setelah pengguna login, sistem otomatis mendeteksi school_id pengguna dan memfilter semua data berdasarkan school tersebut. | 1. Setiap query ke database wajib disertai WHERE school_id = [current_user.school_id]. 2. Super Admin yang tidak punya school_id dapat melihat semua data lintas cabang. 3. Tidak ada cara bagi pengguna biasa untuk mengakses data cabang lain. |
| AUTH-03 | Sebagai Pengguna, setelah login saya melihat tampilan (logo, warna utama) yang sesuai dengan cabang sekolah saya. | 1. Sistem membaca logo_url, primary_color, secondary_color dari tabel schools berdasarkan school_id. 2. CSS variables di-inject secara dinamis ke halaman. 3. Perubahan white-label oleh Admin Sekolah langsung berlaku tanpa deployment ulang. |
| AUTH-04 | Sebagai Pengguna, saya dapat me-reset password melalui email. | 1. Link reset dikirim ke email terdaftar. 2. Link berlaku selama 60 menit. 3. Setelah reset berhasil, semua sesi aktif di-invalidate. |
| AUTH-05 | Sebagai Pengguna, saya dapat mengganti bahasa antarmuka antara Bahasa Indonesia dan English. | 1. Tombol toggle bahasa tersedia di navbar. 2. Preferensi bahasa tersimpan di profil pengguna (locale field). 3. Semua label, pesan error, dan placeholder tersedia dalam dua bahasa. |

## 1.2.2 Sistem Informasi Siswa (SIS)

| **ID** | **User Story** | **Acceptance Criteria** |
| --- | --- | --- |
| SIS-01 | Sebagai Admin Sekolah, saya dapat menambahkan data siswa baru secara manual. | 1. Form minimal: nama, NIS, NISN, tanggal lahir, jenis kelamin, agama, alamat, nama ortu, no. HP ortu. 2. Validasi format NISN (10 digit angka). 3. NIS unik dalam satu sekolah. |
| SIS-02 | Sebagai Admin Sekolah, saya dapat mengedit dan menonaktifkan data siswa. | 1. Edit tidak menghapus histori (soft update). 2. Siswa dinonaktifkan (status = INACTIVE) tidak dihapus dari database. 3. Siswa tidak aktif tidak muncul di daftar kelas aktif. |
| SIS-03 | Sebagai Admin Sekolah, saya dapat mengunggah foto profil siswa. | 1. Format yang diterima: JPG, PNG, WEBP. 2. Ukuran maksimum: 2 MB. 3. Foto otomatis di-resize ke 400×400 px. |
| SIS-04 | Sebagai Guru, saya dapat melihat daftar siswa di kelas yang saya ampu. | 1. Hanya siswa aktif yang ditampilkan. 2. Informasi tampil: nama, NIS, foto, status kehadiran hari ini. |
| SIS-05 | Sebagai Admin Sekolah, saya dapat mengekspor data siswa ke format Excel (.xlsx). | 1. Ekspor mencakup semua field data siswa. 2. File ekspor diberi nama dengan format: siswa_[kode_sekolah]_[tanggal].xlsx. |

## 1.2.3 PPDB Online (Penerimaan Peserta Didik Baru)

| **ID** | **User Story** | **Acceptance Criteria** |
| --- | --- | --- |
| PPDB-01 | Sebagai Calon Siswa / Orang Tua, saya dapat mengisi formulir pendaftaran PPDB secara online tanpa perlu login. | 1. Halaman PPDB dapat diakses publik via URL: /ppdb/[kode_sekolah]. 2. Form: nama lengkap, jenis kelamin, tanggal lahir, asal sekolah, nama ortu, no. HP, email. 3. Setelah submit, tampil nomor pendaftaran unik. |
| PPDB-02 | Sebagai Calon Siswa, saya dapat mengecek status pendaftaran menggunakan nomor pendaftaran. | 1. Halaman cek status dapat diakses publik. 2. Tampil status terkini: REGISTERED, DOCUMENT_REVIEW, PASSED, FAILED, ENROLLED. |
| PPDB-03 | Sebagai Admin Sekolah, saya dapat melihat semua pendaftar, memfilter berdasarkan status, dan memperbarui status. | 1. Tampil dalam tabel dengan kolom: no. daftar, nama, asal sekolah, status, tanggal daftar. 2. Filter per status tersedia. 3. Perubahan status disimpan dengan catatan alasan. |
| PPDB-04 | Sebagai Admin Sekolah, saya dapat meng-generate link wa.me notifikasi untuk dikirim ke calon siswa. | 1. Sistem menyediakan template teks per perubahan status (misal: "Selamat, Ananda [nama] dinyatakan LULUS seleksi..."). 2. Link wa.me otomatis tergenerate dengan template teks siap kirim. 3. Admin tinggal klik "Buka WhatsApp" untuk mengirim manual. |
| PPDB-05 | Sebagai Admin Sekolah, saya dapat mengonversi pendaftar PPDB yang LULUS menjadi siswa aktif (enroll) dalam satu klik. | 1. Data dari formulir PPDB otomatis mengisi form siswa baru. 2. Admin dapat melengkapi data sebelum konfirmasi. 3. Status PPDB berubah menjadi ENROLLED setelah berhasil. |

## 1.2.4 Manajemen Kelas & Jadwal Pelajaran

| **ID** | **User Story** | **Acceptance Criteria** |
| --- | --- | --- |
| KELAS-01 | Sebagai Admin Sekolah, saya dapat membuat kelas baru untuk tahun ajaran aktif dan menentukan wali kelasnya. | 1. Field: nama kelas (misal: X-A), tingkat, wali kelas, kapasitas. 2. Wali kelas dipilih dari daftar guru aktif. 3. Satu guru hanya boleh menjadi wali kelas satu kelas per tahun ajaran. |
| KELAS-02 | Sebagai Admin Sekolah, saya dapat menambahkan siswa ke dalam kelas. | 1. Siswa dipilih dari daftar siswa aktif yang belum terdaftar di kelas manapun untuk tahun ajaran tersebut. 2. Satu siswa hanya boleh ada di satu kelas per tahun ajaran. |
| KELAS-03 | Sebagai Admin Sekolah, saya dapat membuat jadwal pelajaran per kelas dengan alokasi guru dan ruangan. | 1. Field: kelas, mata pelajaran, guru, hari, jam mulai, jam selesai, ruang. 2. Sistem mendeteksi konflik jadwal (guru/ruangan/kelas yang sama di waktu bersamaan). |
| KELAS-04 | Sebagai Guru, saya dapat melihat jadwal mengajar saya untuk minggu berjalan. | 1. Tampil dalam format tabel/kalender mingguan. 2. Klik jadwal menampilkan detail: kelas, mata pelajaran, ruang. |

## 1.2.5 E-Rapor & Penilaian

| **ID** | **User Story** | **Acceptance Criteria** |
| --- | --- | --- |
| NILAI-01 | Sebagai Guru, saya dapat menginput nilai per komponen (Harian, UTS, UAS) untuk setiap siswa di kelas yang saya ampu. | 1. Nilai dalam skala 0–100. 2. Input dapat dilakukan satu per satu atau melalui import Excel. 3. Nilai yang sudah diinput dapat diedit selama rapor belum diterbitkan (published = false). |
| NILAI-02 | Sebagai Sistem, nilai akhir per mata pelajaran dihitung otomatis berdasarkan bobot komponen yang dikonfigurasi Admin. | 1. Admin dapat mengatur bobot: contoh Harian 40%, UTS 30%, UAS 30%. 2. Formula: Nilai Akhir = (Harian × bobot) + (UTS × bobot) + (UAS × bobot). 3. Hasil pembulatan 2 desimal. |
| NILAI-03 | Sebagai Wali Kelas, saya dapat menerbitkan (publish) rapor untuk semua siswa di kelas saya. | 1. Sebelum publish, sistem memvalidasi bahwa semua mata pelajaran sudah memiliki nilai akhir. 2. Setelah publish, nilai terkunci (tidak dapat diedit). 3. Rapor tersedia di portal siswa dan orang tua. |
| NILAI-04 | Sebagai Siswa / Orang Tua, saya dapat melihat nilai real-time (sebelum rapor diterbitkan) dan rapor final. | 1. Nilai harian/UTS/UAS tampil segera setelah guru menyimpan. 2. Rapor final hanya tampil setelah Wali Kelas menerbitkan. 3. Rapor dapat dicetak dalam format PDF. |
| NILAI-05 | Sebagai Admin Sekolah, saya dapat mengkonfigurasi komponen penilaian dan bobot per mata pelajaran. | 1. Konfigurasi dapat berbeda antar mata pelajaran. 2. Perubahan konfigurasi hanya berlaku untuk tahun ajaran baru. |

## 1.2.6 Tagihan & Pembayaran SPP Digital

| **ID** | **User Story** | **Acceptance Criteria** |
| --- | --- | --- |
| SPP-01 | Sebagai Bendahara, saya dapat membuat jenis tagihan baru (SPP, Uang Gedung, dll.) dengan jumlah dan frekuensi tertentu. | 1. Field: nama tagihan, jumlah (Rupiah), frekuensi (MONTHLY/YEARLY/ONCE). 2. Jenis tagihan dapat dinonaktifkan tanpa menghapus histori. |
| SPP-02 | Sebagai Bendahara, saya dapat men-generate tagihan SPP bulanan untuk semua siswa aktif dalam satu klik. | 1. Sistem membuat record student_fees untuk setiap siswa aktif. 2. Due date otomatis diisi (misal: tanggal 10 bulan berjalan). 3. Preview daftar tagihan ditampilkan sebelum konfirmasi generate. |
| SPP-03 | Sebagai Bendahara, saya dapat mencatat pembayaran siswa secara manual (cash atau transfer). | 1. Form: nama siswa, periode, metode bayar, jumlah, tanggal, referensi. 2. Bukti transfer dapat diunggah (JPG/PNG/PDF, maks 5 MB). 3. Status tagihan otomatis berubah ke PAID/PARTIAL. |
| SPP-04 | Sebagai Orang Tua, saya dapat melihat daftar tagihan anak, status pembayaran, dan riwayat pembayaran. | 1. Tampil dalam daftar per periode. 2. Tagihan belum lunas ditandai merah/warning. 3. Riwayat pembayaran mencantumkan tanggal dan metode bayar. |
| SPP-05 | Sebagai Bendahara, saya dapat mengekspor laporan tagihan per periode ke Excel. | 1. Kolom: nama siswa, kelas, periode, jumlah tagihan, jumlah bayar, sisa, status. 2. Ekspor dapat difilter per kelas, periode, atau status. |

## 1.2.7 Akuntansi & Kas Sekolah

| **ID** | **User Story** | **Acceptance Criteria** |
| --- | --- | --- |
| KAS-01 | Sebagai Bendahara, saya dapat mencatat pemasukan dan pengeluaran kas sekolah. | 1. Field: jenis (INCOME/EXPENSE), kategori, jumlah, tanggal, keterangan, no. referensi. 2. Bukti dapat dilampirkan (scan nota/kwitansi). |
| KAS-02 | Sebagai Kepala Sekolah / Admin, saya dapat melihat ringkasan keuangan bulanan: total pemasukan, pengeluaran, dan saldo. | 1. Dashboard keuangan menampilkan: saldo kas, total penerimaan SPP bulan ini, total pengeluaran bulan ini. 2. Grafik tren 6 bulan terakhir. |
| KAS-03 | Sebagai Super Admin, saya dapat melihat ringkasan keuangan semua cabang dalam satu dashboard. | 1. Tampil per cabang: total tagihan, total terkumpul, persentase lunas. 2. Filter per tahun ajaran / bulan. |

## 1.2.8 Notifikasi & Pengumuman (wa.me)

| **ID** | **User Story** | **Acceptance Criteria** |
| --- | --- | --- |
| NOTIF-01 | Sebagai Admin Sekolah, saya dapat membuat pengumuman dengan target (semua, per kelas, atau individu). | 1. Field: judul, isi pesan, target, kategori (ACADEMIC/BILLING/EMERGENCY/GENERAL). 2. Target "semua" mengirim ke semua user aktif di cabang. 3. Target "per kelas" hanya untuk orang tua siswa kelas tersebut. |
| NOTIF-02 | Sebagai Admin, untuk setiap notifikasi saya mendapatkan daftar link wa.me siap kirim per penerima. | 1. Sistem generate URL: wa.me/62[nomorHP]?text=[pesan_ter-encode]. 2. Daftar dapat difilter dan disalin satu per satu. 3. Tombol "Buka WA" membuka WhatsApp dengan pesan terisi. |
| NOTIF-03 | Sebagai Sistem, notifikasi trigger otomatis tersedia untuk event tertentu. | 1. Trigger tersedia untuk: PPDB status berubah, tagihan baru terbit, rapor diterbitkan. 2. Template teks notifikasi dapat diedit oleh Admin Sekolah. 3. Notifikasi muncul di in-app notification center (bell icon) dan wa.me link tersedia. |
| NOTIF-04 | Sebagai Pengguna, saya dapat melihat notifikasi yang belum dibaca di panel notifikasi. | 1. Jumlah notifikasi belum baca tampil di badge (bell icon). 2. Klik notifikasi menandainya sebagai "dibaca". 3. Riwayat notifikasi tersimpan 90 hari. |

## 1.2.9 Portal Orang Tua, Guru, dan Siswa

| **ID** | **User Story** | **Acceptance Criteria** |
| --- | --- | --- |
| PORTAL-01 | Sebagai Orang Tua, setelah login saya dapat melihat dashboard anak yang menampilkan informasi penting secara ringkas. | 1. Dashboard menampilkan: 3 nilai terbaru, kehadiran bulan ini, tagihan belum lunas (jumlah & nominal). 2. Jika memiliki lebih dari satu anak, dapat berpindah antar profil anak. 3. Responsive untuk tampilan mobile. |
| PORTAL-02 | Sebagai Guru, saya dapat mengakses dasbor kerja yang menampilkan jadwal hari ini dan kelas yang aktif. | 1. Jadwal hari ini tampil di halaman utama. 2. Shortcut ke: Input Nilai, Daftar Siswa Kelas, Buat Pengumuman. |
| PORTAL-03 | Sebagai Siswa, saya dapat melihat jadwal pelajaran, nilai, dan notifikasi dari sekolah. | 1. Tab/menu: Jadwal, Nilai, Notifikasi, Profil. 2. Nilai menampilkan per mata pelajaran, per semester, per komponen. 3. Notifikasi dari sekolah tampil dengan timestamp. |
| PORTAL-04 | Sebagai Admin Sekolah, saya dapat mengelola akun pengguna (buat, edit, nonaktifkan, reset password). | 1. Pembuatan akun guru dan siswa bisa dilakukan massal via import Excel. 2. Reset password oleh Admin menghasilkan password sementara yang dikirim via notifikasi. |
