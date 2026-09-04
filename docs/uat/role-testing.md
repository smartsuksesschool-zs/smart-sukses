# UAT — pengujian per peran

Panduan penguji untuk Smart Sukses School. Ditujukan bagi Pak Akbar, guru,
wali kelas, bendahara, siswa, dan orang tua yang akan mencoba aplikasi di
staging sebelum dipakai sungguhan.

**Seluruh data pada panduan ini sintetis.** Tidak ada nama, NIS, NISN, nomor
telepon, maupun surel siswa atau staf sungguhan. Impor data siswa sungguhan
adalah alur terpisah dan **di luar cakupan UAT** — lihat
`docs/migration/m5-production-import-readiness.md`.

**Produksi di luar cakupan dokumen ini.** Setiap perintah di sini dijalankan di
basis data staging yang terpisah. `SimulationSeeder` sendiri menolak berjalan
bila `APP_ENV=production`.

---

## 1. Prasyarat

Sebelum penguji diberi alamat:

| Prasyarat | Keterangan |
|---|---|
| Server staging berjalan | Lihat `docs/deployment/staging-uat.md` §7 |
| `APP_ENV=staging`, `APP_DEBUG=false` | Halaman galat tidak boleh membocorkan konfigurasi |
| `SEED_ADMIN_PASSWORD` sudah disetel | Tanpa ini seeding **berhenti sebelum satu baris pun ditulis** |
| `php artisan config:cache` dijalankan **sesudah** env lengkap | Lihat catatan di bawah |
| `MAIL_MAILER=log` | Satu-satunya surel keluar (reset kata sandi panel) berhenti di `storage/logs` (staging-uat.md §10) |
| Basis data staging terpisah dari produksi | Bukan skema lain pada server yang sama bila dapat dihindari |

### Catatan `config:cache`

Kata sandi seeding dibaca lewat `config('seeding.admin_password')`, bukan
`env()` langsung. Bila `config:cache` terlanjur dibangun sebelum
`SEED_ADMIN_PASSWORD` diisi, nilainya ikut terpanggang kosong dan seeding akan
menolak dengan pesan yang terasa keliru. Perbaikannya:

```sh
php artisan config:clear
php artisan config:cache
```

---

## 2. Perintah penyiapan — urutan pasti

Dijalankan di server staging, dari direktori aplikasi:

```sh
php artisan migrate --force
php artisan db:seed --force
php artisan db:seed --class=SimulationSeeder --force
```

Urutannya tidak dapat ditukar:

1. **`migrate --force`** — struktur tabel.
2. **`db:seed --force`** — memanggil tepat tiga seeder prasyarat:
   `RolePermissionSeeder` (peran & izin), `SchoolSeeder` (cabang PUSAT),
   `UserSeeder` (Super Admin + Admin Sekolah). Tanpa peran, setiap policy
   menolak semua orang; tanpa cabang, tidak ada `school_id` yang dapat
   digantungi.
3. **`db:seed --class=SimulationSeeder --force`** — seluruh data UAT. Seeder ini
   memanggil `UserSeeder` dan `Sprint4DemoSeeder` sendiri, jadi keduanya tidak
   perlu dipanggil terpisah.

Menjalankan langkah 3 tanpa langkah 2 gagal dengan
`There is no role named 'SUPER_ADMIN' for guard 'web'`. Itu bukan kerusakan,
melainkan urutan yang terlewat.

### Opsional — isi halaman publik

```sh
php artisan db:seed --class=PublicSiteSeeder --force
```

Dijalankan hanya bila penguji perlu melihat halaman muka dengan isi contoh.
Sengaja terpisah: ia menerbitkan isi ke halaman yang dilihat publik, termasuk
enam baris galeri yang belum berfoto.

### Yang **tidak** berjalan otomatis

`SimulationSeeder`, `Sprint4DemoSeeder`, dan `PublicSiteSeeder` tidak terdaftar
di `DatabaseSeeder`. Dua yang pertama membuat akun yang dapat login, dan akun
demo yang lahir tanpa diminta adalah pintu masuk, bukan data contoh. Adanya
staging tidak mengubah itu — staging pun harus memintanya dengan sengaja.

---

## 3. Akun penguji

Delapan akun, satu per peran. Alamat masuk **satu untuk semua**: `/login`.
Tidak ada halaman masuk per peran.

| Peran | Surel akun |
|---|---|
| Super Admin | `superadmin@smartsukses.sch.id` |
| Admin Sekolah | `admin.pusat@smartsukses.sch.id` |
| Kepala Sekolah | `kepsek.pusat@smartsukses.sch.id` |
| Guru Mata Pelajaran | `guru.pusat@smartsukses.sch.id` |
| Wali Kelas | `walikelas.pusat@smartsukses.sch.id` |
| Bendahara | `bendahara.pusat@smartsukses.sch.id` |
| Siswa | `siswa.pusat@smartsukses.sch.id` |
| Orang Tua | `ortu.pusat@smartsukses.sch.id` |

Daftar ini bersumber dari satu konstanta,
`SimulationSeeder::UAT_ACCOUNTS`, dan ada test yang menggagalkan build bila ada
peran pada `RoleName` yang tidak punya barisnya.

### Kata sandi

**Kata sandi tidak ada di dokumen ini, tidak di repositori, tidak di rute mana
pun, dan tidak dikirim lewat chat.**

Seluruh akun memakai satu nilai: `SEED_ADMIN_PASSWORD` yang disetel operator di
server staging. Operator membagikannya langsung kepada penguji lewat jalur yang
aman — sebaiknya satu per penguji, bukan satu pesan ke satu grup.

Tidak ada halaman kredensial di dalam aplikasi, dan tidak akan dibuat.

---

## 4. Isi data UAT

Satu cabang, satu tahun ajaran, satu kelas.

| Bagian | Isi |
|---|---|
| Cabang | Smart Sukses School Pusat (`PUSAT`) |
| Tahun ajaran | `2026/2027 Ganjil`, aktif |
| Kelas | `X-A`, wali kelas terpasang |
| Mata pelajaran | MTK, BIN, IPA — masing-masing dengan guru pengampu |
| Jadwal | 3 jam pelajaran, Senin–Rabu |
| Siswa | 3 siswa sintetis, seluruhnya ditempatkan di X-A |
| Akun portal | 1 siswa punya akun; siswa itu juga terhubung ke 1 akun orang tua |
| Konfigurasi nilai | MTK & BIN **ACTIVE**; IPA sengaja **tanpa konfigurasi** |
| Nilai | 39 entri: harian, UTS, UAS, satu formatif, satu sikap, satu SKILL di luar konfigurasi |
| Rapor | **sengaja kosong** — menerbitkannya adalah pengujiannya |
| CBT | 1 ujian terbit, 3 soal pilihan ganda, jendela terbuka 7 hari |
| Jenis tagihan | `SPP Bulanan Simulasi` (bulanan), `Uang Kegiatan Simulasi` (sekali) |
| Tagihan siswa | 3 tagihan periode `2026-07`: satu LUNAS, satu SEBAGIAN, satu BELUM BAYAR |
| Pembayaran | 2 pembayaran TUNAI |
| Buku kas | 1 pemasukan, 1 pengeluaran |

Tiga hal sengaja **dibiarkan kosong**, karena mengisinya berarti mengambil alih
pengujiannya:

* **rapor** — Wali Kelas yang menerbitkannya dari UI;
* **tagihan periode berjalan** — Bendahara yang menerbitkannya lewat Generate
  Tagihan;
* **pengerjaan ujian** — Siswa yang mengerjakannya.

Jendela ujian dihitung ulang setiap kali seeder dijalankan: mundur satu jam agar
sudah terbuka, maju tujuh hari agar tidak keburu tutup di tengah sesi UAT.

---

## 5. Daftar periksa per peran

Beri tanda pada yang berjalan, dan **catat apa yang Anda lihat** pada yang
tidak. Layar yang kosong juga temuan — tuliskan halaman mana.

### 5.1 Super Admin

- [ ] Masuk lewat `/login`, mendarat di panel admin
- [ ] Melihat data lintas cabang (saat ini baru ada satu cabang)
- [ ] Membuka pengaturan situs publik Smart Sukses School dan menyuntingnya
- [ ] Membuat cabang kedua, lalu memastikan data cabang pertama tidak bercampur
- [ ] Membuat satu akun pengguna baru dan menetapkan perannya
- [ ] Membuka log audit dan menemukan jejak perubahan yang baru saja dilakukan

### 5.2 Admin Sekolah

- [ ] Masuk lewat `/login`, mendarat di panel admin
- [ ] Membuka daftar siswa; menambah satu siswa sintetis
- [ ] Membuat / menyunting rombel dan menempatkan siswa
- [ ] Membuka data guru, mata pelajaran, dan jadwal
- [ ] Mengunduh templat Excel siswa
- [ ] Mengunggah berkas **sintetis** hasil templat itu dan membaca hasil impornya
- [ ] Memastikan **tidak** dapat menyunting isi situs payung Smart Sukses School

> Berkas impor UAT harus berkas karangan. Jangan pernah mengunggah berkas data
> siswa sungguhan ke staging.

### 5.3 Kepala Sekolah

- [ ] Masuk lewat `/login`, mendarat di panel admin
- [ ] Membaca data siswa, kelas, jadwal, nilai, dan rapor
- [ ] Membaca ringkasan dan laporan keuangan
- [ ] Memastikan **tidak** ada tombol simpan/hapus pada modul yang seharusnya
      hanya dibaca (siswa, nilai, tagihan)
- [ ] Mengirim / mengelola pemberitahuan

### 5.4 Guru Mata Pelajaran

- [ ] Masuk lewat `/login`, mendarat di panel admin
- [ ] Melihat kelas dan mata pelajaran yang diampu
- [ ] Memasukkan nilai baru dan menyuntingnya
- [ ] Melihat MTK & BIN terhitung, dan IPA dilaporkan belum lengkap beserta
      alasannya
- [ ] Melihat peringatan komponen SKILL pada BIN yang tidak ada di konfigurasi
- [ ] Membuka hasil ujian CBT dan memindahkannya menjadi nilai
- [ ] Memastikan **tidak** dapat membuka portal siswa maupun portal orang tua

### 5.5 Wali Kelas

- [ ] Masuk lewat `/login`, mendarat di panel admin
- [ ] Melihat kelas X-A sebagai tanggung jawabnya
- [ ] Memasukkan nilai seperti guru mata pelajaran
- [ ] **Generate Rapor Kelas** → meninjau → **Terbitkan**
- [ ] **Generate PDF Kelas**, lalu memastikan statusnya berpindah ke siap
- [ ] Mengunduh satu rapor dan memeriksa isinya

> Generate PDF satu kelas berjalan lewat antrean. Bila statusnya tidak pernah
> berpindah, worker antrean belum jalan — laporkan sebagai temuan operasional,
> bukan cacat rapor.

### 5.6 Bendahara

- [ ] Masuk lewat `/login`, mendarat di panel admin
- [ ] Membuka daftar tagihan; melihat tiga status berbeda pada periode `2026-07`
- [ ] Membuka **Generate Tagihan**, memilih SPP Bulanan dan **periode berjalan**
- [ ] Melihat pratinjau (siapa yang akan ditagih, siapa yang dilewati) sebelum
      menerbitkan
- [ ] Menerbitkan, lalu menjalankannya **sekali lagi** dan memastikan tidak ada
      tagihan ganda
- [ ] Mencatat satu pembayaran tunai dan melihat status tagihan berubah sendiri
- [ ] Membebaskan (waive) satu tagihan beserta alasannya
- [ ] Membuka buku kas dan laporan keuangan
- [ ] Memastikan **tidak** dapat menyunting siswa, nilai, akun, maupun situs
      payung

> Tidak ada penyedia pembayaran yang dihubungi. Pembayaran dicatat manual oleh
> bendahara; `PAYMENT_GATEWAY` hanya sebuah pilihan metode, bukan integrasi.

### 5.7 Siswa

- [ ] Masuk lewat `/login`, mendarat di portal siswa (**bukan** panel admin)
- [ ] Melihat dasbor, jadwal, dan nilainya sendiri
- [ ] Membuka daftar ujian dan mengerjakan `Ulangan Harian Simulasi`
- [ ] Menutup lalu membuka kembali halaman ujian: pengerjaan **dilanjutkan**,
      bukan diulang dari awal
- [ ] Mengumpulkan, lalu melihat hasilnya
- [ ] Memastikan **tidak** dapat membuka `/admin` maupun portal orang tua
- [ ] Memastikan tidak ada data siswa lain yang terlihat di mana pun

### 5.8 Orang Tua

- [ ] Masuk lewat `/login`, mendarat di portal orang tua
- [ ] Melihat **hanya** anak yang terhubung
- [ ] Membuka nilai, tagihan, dan rapor anaknya
- [ ] Memastikan **tidak** dapat membuka `/admin` maupun portal siswa
- [ ] Mencoba mengubah alamat dengan id siswa lain dan memastikan ditolak

### 5.9 Publik (tanpa masuk)

- [ ] Membuka halaman muka
- [ ] Mengganti bahasa ID ⇄ EN dan memastikan seluruh tulisan ikut berganti
- [ ] Membuka formulir PPDB dan mengirim **satu pendaftaran karangan**
- [ ] Membuka tautan artikel/blog bila sudah dikonfigurasi
- [ ] Membuka `/login` dan memastikan tidak ada pemilih peran di formulirnya
- [ ] Memastikan penanda **STAGING / UAT** terlihat di pojok kanan bawah

---

## 6. Hasil yang diharapkan

Setelah seeding, aplikasi berada tepat pada keadaan ini:

| Bagian | Jumlah |
|---|---|
| Akun pengguna | 8 |
| Peran terpakai | 8 dari 8 |
| Cabang | 1 |
| Tahun ajaran | 1 |
| Kelas | 1 |
| Siswa | 3 |
| Penempatan kelas | 3 |
| Mata pelajaran | 3 |
| Kelas–mapel | 3 |
| Jadwal | 3 |
| Konfigurasi nilai aktif | 2 |
| Entri nilai | 39 |
| Rapor | 0 (disengaja) |
| Ujian | 1 (terbit) |
| Soal / pilihan | 3 / 9 |
| Pengerjaan ujian | 0 (disengaja) |
| Jenis tagihan | 2 |
| Tagihan siswa | 3 (LUNAS 1, SEBAGIAN 1, BELUM BAYAR 1) |
| Pembayaran | 2 |
| Buku kas | 2 |
| Pendaftaran PPDB | 0 |

Menjalankan seeder untuk kedua kalinya menghasilkan **angka yang persis sama**.
Bila salah satu angka bertambah setelah pengulangan, itu temuan — laporkan.

---

## 7. Batasan UAT yang sudah diketahui

Bukan cacat. Tidak perlu dilaporkan.

1. **Satu cabang saja.** Pengujian lintas cabang perlu cabang kedua yang dibuat
   tangan oleh Super Admin.
2. **Satu siswa punya akun portal.** Dua siswa lain hanya data induk — memang
   keadaan yang wajar, dan berguna untuk menguji bahwa siswa tanpa akun tidak
   merusak apa pun.
3. **PPDB tanpa data awal.** Pendaftaran diuji dengan mengirim formulir publik,
   bukan dengan data seeding.
4. **Galeri tanpa foto.** `PublicSiteSeeder` menyiapkan enam baris galeri yang
   belum berfoto; bagian yang belum berisi memang tidak dirender.
5. **Rapor, tagihan periode berjalan, dan pengerjaan ujian kosong** — disengaja,
   lihat §4.
6. **Surel keluar hanya satu, dan berhenti di log.** Satu-satunya surel yang
   dikirim aplikasi ini adalah tautan reset kata sandi panel. Dengan
   `MAIL_MAILER=log` ia ditulis ke `storage/logs` dan tidak sampai ke alamat
   siapa pun. Bila penguji sungguhan menerima surel dari staging, **itu temuan
   serius** — hentikan dan laporkan.
7. **WhatsApp tidak mengirim apa pun sendiri.** Tidak ada integrasi WhatsApp
   API, tidak ada penyedia, dan tidak ada antrean pengiriman. Yang dibuat
   aplikasi hanya tautan `wa.me`; pesan baru terkirim bila **seseorang menekan
   tautan itu lalu menekan kirim** di aplikasi WhatsApp miliknya sendiri. UAT
   karena itu tidak dapat memicu pesan diam-diam.

   Nomor telepon pada data demo adalah nomor karangan yang dirakit dari NIS
   sintetis. Karangan bukan jaminan nomornya tidak terpakai orang lain, jadi
   **jangan benar-benar menekan kirim** saat menguji tautan WhatsApp — cukup
   pastikan tautannya terbentuk, isinya benar, dan yang tanpa nomor melaporkan
   alasannya.
8. **Impor siswa sungguhan di luar cakupan.** Jangan mengunggah berkas data
   siswa asli ke staging dalam bentuk apa pun.

---

## 8. Mengulang data UAT dari awal

Hanya pada basis data staging yang terpisah, dan hanya oleh operator.

```sh
# 1. Pastikan sedang menunjuk basis data staging, bukan produksi.
php artisan tinker --execute="echo config('database.connections.mysql.database');"

# 2. Bangun ulang skema dan isi ulang datanya.
php artisan migrate:fresh --force
php artisan db:seed --force
php artisan db:seed --class=SimulationSeeder --force
```

`migrate:fresh` **menghapus seluruh tabel**. Ia tidak pernah dijalankan di
produksi, dan langkah 1 bukan formalitas: ia satu-satunya yang memisahkan
"mengulang UAT" dari "menghapus sekolah".

Bila hanya ingin menyegarkan jendela ujian dan mengembalikan data demo yang
tersunting penguji, cukup jalankan ulang `SimulationSeeder` — ia idempoten dan
tidak menghapus apa pun. Yang **tidak** dikembalikannya: baris yang dibuat
penguji sendiri (siswa baru, tagihan baru, rapor terbit). Untuk menghapus itu
perlu `migrate:fresh`.

---

## 9. Cara melaporkan temuan

Sertakan:

* peran dan surel akun yang dipakai;
* alamat halaman;
* apa yang Anda lakukan, apa yang Anda harapkan, apa yang terjadi;
* jam kejadian (untuk mencocokkan dengan log server).

**Jangan menyertakan kata sandi** dalam laporan, tangkapan layar, maupun
rekaman layar.

---

## 10. Di luar cakupan

* Impor data siswa produksi — `docs/migration/m5-production-import-readiness.md`
* Penyiapan server, DNS, TLS, backup — `docs/deployment/staging-uat.md`
* Integrasi penyedia pembayaran — belum ada di project ini
* Migrasi WordPress / blog
