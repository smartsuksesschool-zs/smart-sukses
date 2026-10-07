# Provisioning Akun Production

Urutan membuat akun nyata untuk ketujuh peran, memakai panel yang sudah ada.
Tidak ada perkakas baru yang dibutuhkan, dan tidak ada satu pun akun demo yang
boleh dibawa ke produksi.

**Tidak ada kata sandi sungguhan yang ditulis di dokumen ini, dan tidak boleh
ditambahkan ke sini nanti.**

## 0. Yang harus dipahami lebih dulu

Tiga sifat sistem yang menentukan seluruh prosedur di bawah:

1. **`MAIL_MAILER=log` — tidak ada satu pun sandi yang terkirim otomatis.**
   Seluruh penyerahan sandi awal dilakukan **di luar sistem**: tatap muka, atau
   WhatsApp ke nomor yang sudah diketahui. URL reset sandi pun hanya tertulis ke
   log server, sehingga akses log harus tetap dibatasi.
2. **Peran portal tidak punya halaman ganti sandi.** `SISWA` dan `ORANG_TUA`
   tidak pernah masuk panel admin, sehingga penanda "wajib ganti sandi" tidak
   punya tempat untuk dilepas. Karena itu aksi **Reset Password tidak
   ditawarkan** untuk kedua peran itu.
3. **Satu akun = satu peran.** Form Peran menerima tepat satu pilihan.

## 1. Bootstrap — sekali saja, sebelum apa pun

```sh
# 1. Setel sandi seeding (sandi kuat, sekali pakai, JANGAN ditulis ke dokumen).
#    Tanpa variabel ini, seeding di produksi menolak berjalan.
#    SEED_ADMIN_PASSWORD=<...>

# 2. Hanya ini. Tanpa --class apa pun.
php artisan db:seed
```

Menghasilkan **dua** akun, keduanya dengan penanda wajib-ganti-sandi menyala:

| Akun | Peran |
| --- | --- |
| `superadmin@smartsukses.sch.id` | Super Administrator |
| `admin.pusat@smartsukses.sch.id` | Admin Sekolah |

Lalu:

3. Masuk sebagai masing-masing akun. Panel **memaksa** ke halaman profil sampai
   sandinya diganti. Ganti keduanya.
4. **Hapus `SEED_ADMIN_PASSWORD` dari env**, lalu `php artisan config:cache`.
   Variabel itu hanya dibutuhkan untuk bootstrap.

> **Jangan** menjalankan `db:seed --class=SimulationSeeder` atau
> `--class=Sprint4DemoSeeder` di produksi. Keduanya membuat akun yang dapat
> login, dan keduanya kini **menolak** berjalan di `production` — tetapi
> larangannya tetap berlaku sebagai prosedur, bukan hanya sebagai kode.
> `PublicSiteSeeder` tidak membuat akun, tetapi ia menerbitkan isi ke halaman
> publik; jalankan hanya bila itu memang dikehendaki.

## 2. Cabang (School)

Super Admin → **Cabang Sekolah** → pastikan setiap cabang ada dan `is_active`
benar. Cabang nonaktif tidak muncul sebagai pilihan saat membuat akun.

## 3. Admin Sekolah — satu per cabang

Super Admin → **Pengguna** → Buat:

| Field | Isi |
| --- | --- |
| Nama, Surel, HP | data orang yang sesungguhnya |
| **Cabang Sekolah** | **wajib dipilih** |
| Peran | `Admin Sekolah` |
| Bahasa | `id` |
| Aktif | ya |
| Password | sandi kuat yang diketik admin |

**Cabang wajib untuk setiap peran selain Super Administrator.** Form menolak
menyimpan tanpa cabang: akun School Level tanpa cabang dapat masuk panel tetapi
seluruh layarnya kosong tanpa penjelasan — tampak seperti sistem rusak, bukan
akun yang salah dibuat.

Serahkan sandinya di luar sistem, lalu minta yang bersangkutan masuk dan
menggantinya.

## 4. Staf — Kepala Sekolah, Bendahara, Guru, Wali Kelas

Dikerjakan **Admin Sekolah** (bukan Super Admin, agar cabangnya terisi sendiri).

Admin Sekolah → **Pengguna** → Buat. Field Cabang **tidak tampil** dan diisi
otomatis dengan cabang admin pembuatnya. Peran: satu dari empat di atas.

Untuk sandi awal, pilih salah satu:

- **Disarankan** — buat dengan sandi sementara apa pun, lalu klik **Reset
  Password** pada barisnya. Sistem membuat sandi acak 12 karakter, menampilkannya
  **sekali**, dan menyalakan penanda wajib-ganti. Catat, serahkan di luar sistem,
  lalu tutup notifikasinya. Staf dipaksa mengganti pada login pertama.
- Atau ketik sandinya sendiri dan minta mereka mengganti lewat halaman profil.
  Tanpa Reset Password, tidak ada pemaksaan.

### Penugasan setelah akun ada

| Peran | Penugasan |
| --- | --- |
| **Wali Kelas** | Kelas → pilih sebagai Wali Kelas. Hanya guru **aktif di cabang itu** yang dapat dipilih, dan satu guru hanya boleh menjadi wali satu kelas per tahun ajaran |
| **Guru** | Mata Pelajaran per Kelas → tugaskan guru ke mapel dan kelasnya |
| **Kepala Sekolah, Bendahara** | tidak perlu penugasan kelas |

## 5. Data siswa

Dua hal yang **berbeda**: baris siswa, dan akun portalnya. Baris siswa dibuat
lebih dulu, dan akun portal **opsional** — siswa tidak wajib memilikinya.

- **Satuan**: Siswa → Buat. NIS unik per cabang (**termasuk** siswa yang
  diarsipkan — bila NIS ditolak padahal tidak ada di daftar, siswa itu kemungkinan
  terarsip; pulihkan alih-alih membuat baru).
- **Massal**: Siswa → Impor Excel. Template tersedia dari layar yang sama. Galat
  dilaporkan **per baris**, dan baris yang sah tetap masuk.

Lalu tempatkan siswa ke kelas pada tahun ajaran aktif.

## 6. Akun portal siswa (`SISWA`)

1. **Pengguna** → Buat, peran `Siswa`. Cabang terisi otomatis.
2. **Siswa** → Ubah siswanya → **Akun Portal Siswa** → pilih akun tadi.

Yang ditolak form:

- akun dari **cabang lain** — bahkan bila id-nya dikirim langsung;
- akun **berperan bukan Siswa**;
- akun yang **sudah tertaut ke siswa lain** — satu akun untuk satu siswa.

## 7. Akun portal orang tua (`ORANG_TUA`)

**Satu akun per orang tua, bukan per anak.**

1. **Pengguna** → Buat, peran `Orang Tua / Wali Murid`.
2. Untuk **setiap anak**: Siswa → Ubah → **Akun Portal Orang Tua** → pilih akun
   yang sama.

Portal orang tua menampilkan pemilih anak ketika lebih dari satu anak tertaut,
dan menampilkan **seluruh** anak — termasuk yang sudah lulus, agar riwayat
tagihannya tidak hilang.

Akun lintas cabang dan akun berperan keliru ditolak, sama seperti akun siswa.

## 8. Sandi dan login pertama

| | Staf (panel) | Siswa & Orang Tua (portal) |
| --- | --- | --- |
| Sandi awal | diketik admin, atau Reset Password | **diketik admin saja** |
| Dipaksa ganti | ya, bila Reset Password dipakai | tidak tersedia |
| **Reset Password** | boleh | **JANGAN** — aksi ini tidak ditawarkan, dan memaksanya akan membuat akun tidak dapat masuk |
| Ganti sandi berikutnya | halaman profil panel | **Admin: Ubah Pengguna → isi Password** |
| Lupa sandi mandiri | tidak dipakai (`MAIL_MAILER=log`) | tidak dipakai |

Mengubah sandi lewat **Ubah Pengguna** melepas penanda wajib-ganti pada
penyimpanan yang sama, sehingga akun langsung dapat dipakai. Itulah satu-satunya
cara yang benar untuk menyetel ulang sandi akun portal.

Reset sandi yang berhasil **mencabut seluruh sesi dan token** pengguna itu — login
di perangkat lain berakhir. Itu memang yang dikehendaki.

## 9. Surel ganda

Surel **unik di seluruh sistem**, bukan per cabang. Bila form menolak surel:

1. Cari akunnya di **Pengguna** — Super Admin melihat seluruh cabang, Admin
   Sekolah hanya cabangnya.
2. Bila akunnya ada **di cabang lain** dan orangnya memang pindah: akun tidak
   dapat dipindahkan oleh Admin Sekolah. Minta Super Admin mengubah cabangnya,
   atau nonaktifkan yang lama dan pakai surel berbeda.
3. Bila orangnya memang perlu dua peran: **tidak didukung** — satu akun satu
   peran. Gunakan dua surel berbeda.
4. Jangan menambahkan angka pada surel hanya untuk melewati penolakan. Surel yang
   tidak dapat dibaca pemiliknya membuat pemulihan akun mustahil.

## 10. Akun yang salah cabang

| Keadaan | Yang dilakukan |
| --- | --- |
| Akun staf dibuat di cabang keliru | Super Admin → Ubah Pengguna → ubah Cabang. Periksa juga penugasan kelas/mapel yang sudah dibuat atas namanya |
| Akun portal tertaut ke siswa cabang lain | Tidak akan terjadi lewat form — ia ditolak. Bila ditemukan pada data lama: lepaskan tautannya, lalu tautkan ke akun cabang yang benar |
| Akun portal tampak "kosong" saat login | Hampir selalu berarti cabang akun ≠ cabang siswa. Portal memeriksa **keduanya**, jadi akun yang salah cabang berhasil masuk tetapi tidak melihat apa pun |
| Akun School Level tanpa cabang | Form kini menolaknya. Pada data lama: isi cabangnya lewat Ubah Pengguna |

## 11. Checklist verifikasi per peran

Jalankan `php artisan app:production-check` lebih dulu, lalu uji **satu akun per
peran**:

| Peran | Yang harus terjadi |
| --- | --- |
| Super Administrator | Masuk panel; melihat seluruh cabang; dapat membuat cabang dan akun |
| Admin Sekolah | Masuk panel; **hanya** melihat data cabangnya; dapat membuat siswa dan akun staf |
| Kepala Sekolah | Masuk panel; melihat laporan cabangnya; tidak dapat mengubah nilai |
| Bendahara | Masuk panel; melihat dan mencatat keuangan cabangnya |
| Guru | Masuk panel/portal guru; melihat **hanya** kelas yang diajarnya; dapat memasukkan nilai |
| Wali Kelas | Seperti Guru, ditambah: dapat mempublikasikan rapor kelas yang diwalikannya |
| Siswa | Masuk **portal**, bukan panel; melihat jadwal, nilai, dan notifikasinya sendiri |
| Orang Tua | Masuk **portal**; melihat anaknya sendiri; bila lebih dari satu anak, pemilih anak muncul |

Untuk dua peran terakhir, verifikasi tambahan yang penting: masuk sebagai orang
tua **lain** dan pastikan anak yang tampil berbeda. Isolasi antar-cabang dan
antar-keluarga sudah dipagari test, tetapi satu kali pemeriksaan manusia pada data
sungguhan tetap layak dilakukan sebelum akun dibagikan.

## 12. Impor massal akun guru & siswa

PORTAL-04 AC-1 (`docs/blueprint/01-PRD.md:960`, diulang AC-M0-12 `:1010`). Dipakai
untuk puluhan sampai ratusan akun; untuk belasan, cara satuan di atas lebih cepat.

**Siapa yang boleh.** Admin Sekolah dan Super Administrator — himpunan yang sama
dengan `POST /users/import` pada matriks izin (`06-API.md:2016`). Peran lain tidak
melihat tombolnya.

**Urutannya, dan urutan ini tidak boleh ditukar:**

1. **Data siswa lebih dulu.** Impor akun **tidak** membuat baris siswa; ia hanya
   menautkan akun ke siswa yang sudah ada. Jalankan Siswa → Impor Excel dahulu.
2. **Pengguna → Import Akun → Langkah 1**: unduh templatnya. Jangan memakai
   berkas lama — judul kolom pada templat dibangkitkan dari kode, sehingga
   templat yang baru selalu yang sah.
3. **Langkah 2 (hanya Super Admin)**: pilih cabang tujuan. Admin Sekolah tidak
   melihat langkah ini; cabangnya selalu cabang akunnya sendiri.
4. **Langkah 3**: unggah `.xlsx` yang sudah diisi, lalu **Periksa & Buat Akun**.

### Arti setiap kolom

Lembar **`Akun Guru`**:

| Kolom | Wajib | Arti |
| --- | --- | --- |
| `nama` | ya | Nama lengkap sesuai dokumen resmi |
| `email` | ya | Dipakai sebagai nama pengguna. **Unik di seluruh sistem**, bukan per cabang |
| `peran` | ya | `GURU` atau `WALI_KELAS` — keduanya "guru" menurut ASM-11 |
| `hp` | tidak | Untuk tautan WhatsApp |
| `bahasa` | tidak | `id` atau `en`; kosong berarti `id` |

Lembar **`Akun Siswa`**:

| Kolom | Wajib | Arti |
| --- | --- | --- |
| `nis` | ya | NIS siswa yang **sudah ada** di cabang tujuan. Format sel sebagai teks |
| `email` | ya | Sama seperti di atas |
| `hp` | tidak | — |
| `bahasa` | tidak | — |

**Tidak ada kolom nama** di lembar siswa: nama akun diambil dari data induknya,
sehingga akun dan baris siswa tidak dapat berbeda nama.

**Tidak ada kolom cabang.** Cabang ditentukan di luar berkas, sehingga tidak ada
baris Excel yang dapat menyeberang ke cabang lain.

**Tidak ada kolom kata sandi.** Sandi dibuat sistem — lihat di bawah.

### Penanganan kesalahan

**Satu kesalahan membatalkan seluruh impor.** Berkasnya diperiksa seluruhnya lebih
dulu; bila ada satu galat saja, **tidak ada satu akun pun yang dibuat** dan daftar
galat per baris ditampilkan (lembar, baris, dan sebabnya).

Itu berbeda dari impor siswa, yang memasukkan baris sah dan melaporkan sisanya.
Perbedaannya disengaja: setiap akun lahir bersama sandi yang hanya terlihat sekali,
dan impor yang berhenti di tengah meninggalkan akun hidup dengan sandi yang tidak
tercatat siapa pun.

Baris ditolak bila: surel sudah dipakai (di berkas maupun di basis data) · NIS
tidak ada di cabang tujuan · siswanya diarsipkan · siswanya sudah punya akun
portal · peran di luar `GURU`/`WALI_KELAS` · kolom wajib kosong.

### Penyerahan kredensial

Setelah impor berhasil, berkas **`kredensial_akun_<tanggal>.xlsx`** terunduh
sekali: nama, surel, peran, dan sandi sementara.

- **Simpan saat itu juga.** Sandinya tidak dapat ditampilkan kembali — yang
  tersimpan di basis data hanya hash-nya.
- Berkas itu **tidak pernah ditulis ke disk server** dan tidak masuk log.
- Serahkan per orang **di luar sistem**. Dengan `MAIL_MAILER=log` tidak ada yang
  terkirim otomatis.
- **Hapus berkasnya** setelah seluruh sandi diserahkan. Jangan menaruhnya di
  folder bersama atau grup percakapan.

### Login pertama

| | Guru / Wali Kelas | Siswa |
| --- | --- | --- |
| Masuk ke | panel admin | portal siswa |
| Dipaksa ganti sandi | **ya**, pada login pertama | tidak — peran portal tidak punya halaman itu |
| Ganti sandi berikutnya | halaman profil | Admin: Ubah Pengguna → isi Password |

### Menjalankan ulang

Aman dijalankan ulang. Akun yang sudah jadi akan ditolak sebagai "surel sudah
dipakai", dan siswa yang sudah tertaut ditolak sebagai "sudah memiliki akun
portal" — jadi berkas yang sama tidak akan membuat akun ganda. Tetapi karena satu
kesalahan membatalkan seluruh impor, **buang baris yang sudah berhasil** dari
berkasnya sebelum mengunggah ulang.

Bila data siswa berubah antara pemeriksaan dan penulisan — misalnya admin lain
menautkan siswa yang sama — seluruh impor dibatalkan dengan pesan tersendiri, dan
tidak ada akun yang terbuat. Jalankan ulang.

### Yang masih belum ada

**Akun orang tua tidak termasuk.** Requirement hanya menyebut guru dan siswa, dan
tidak ada requirement impor massal untuk orang tua. Akun orang tua tetap lewat
prosedur satuan di bagian 7.

**Struktur kolom templatnya belum diratifikasi pemilik.** `06-API.md:722`
menyatakan strukturnya "Belum dijelaskan dalam blueprint", sehingga bentuk yang
dipakai sekarang adalah keputusan implementasi — tercatat sebagai **OD-19** di
`docs/requirements/owner-decisions.md`. Sebaiknya diratifikasi **sebelum** sekolah
menyiapkan berkas dalam jumlah besar, sebab mengubah format sesudahnya berarti
menyusun ulang berkas yang sudah diisi.
