# Runbook Staging / UAT — `staging.smartsukses.sch.id`

Status: **belum ada servernya.** Dokumen ini menyiapkan repositori dan mencatat
apa yang masih harus disediakan operator. Tidak ada DNS yang diubah, tidak ada
server yang disentuh, dan tidak ada yang di-deploy oleh tugas ini.

Cutover produksi **di luar cakupan** dokumen ini. Yang dibahas hanya staging.

---

## 1. Topologi yang dituju

| Nama host | Isi | Status |
| --- | --- | --- |
| `smartsukses.sch.id` | situs publik + sistem Laravel | rencana |
| `blog.smartsukses.sch.id` | WordPress artikel/berita/lowongan | **tidak disentuh** |
| `staging.smartsukses.sch.id` | UAT Laravel | **dokumen ini** |

WordPress tidak dipindahkan, tidak dimigrasikan, dan tidak diubah. Alamat blog
sudah dapat dikonfigurasi dari panel admin (`blog_url`), sehingga menautkannya
nanti tidak menuntut perubahan kode.

---

## 2. Yang sebenarnya dibutuhkan aplikasi saat berjalan

Hasil audit repositori, bukan asumsi.

| Komponen | Status | Catatan |
| --- | --- | --- |
| PHP 8.2+ (dipakai 8.3) | **REQUIRED** | `composer.json` menyebut `^8.2` |
| Ekstensi PHP | **REQUIRED** | `mysql, mbstring, xml, curl, zip, gd, bcmath, intl` |
| Composer | **REQUIRED** | `composer install --no-dev --optimize-autoloader` |
| MySQL | **REQUIRED** | sesi, cache, antrean, dan data semuanya di MySQL |
| Web server + PHP-FPM | **REQUIRED** | document root `public/` |
| `storage:link` | **REQUIRED** | `public/storage` diabaikan git |
| Penyimpanan persisten | **REQUIRED** | `storage/app/private` dan `storage/app/public` |
| Worker antrean | **REQUIRED** | PDF rapor & tagihan massal; tanpa worker menggantung diam-diam |
| Cron penjadwal | **REQUIRED** | `notifications:prune` (retensi 90 hari) |
| HTTPS | **REQUIRED** | `SESSION_SECURE_COOKIE=true` menuntutnya |
| **Node / Vite / `npm run build`** | **TIDAK DIPAKAI** | lihat di bawah |
| Redis | **TIDAK DIPAKAI** | semua driver memakai MySQL |
| Surel keluar | **OPSIONAL UNTUK UAT** | satu-satunya surel adalah reset kata sandi panel |
| Backup terjadwal | **OPSIONAL UNTUK UAT** | data staging sintetis dan dapat dibuat ulang |
| Rotasi log | OPSIONAL | `LOG_STACK=daily` + `LOG_DAILY_DAYS` sudah menanganinya |
| Docker / CI | **TIDAK ADA** | belum ada di repositori |

### Tidak ada langkah build frontend

Ini temuan yang menghemat banyak pekerjaan server: **tidak ada satu pun
`@vite` di seluruh `resources/views/`**, `public/build` tidak ada, dan aset
Filament yang sudah terkompilasi ikut terlacak git (`public/css/filament/*`,
`public/js/filament/*`). CSS halaman muka ditulis inline di layout-nya.

Akibatnya server staging **tidak membutuhkan Node, npm, maupun Vite sama
sekali**. `package.json` hanya melayani pengembangan lokal.

Satu-satunya kewajiban terkait aset: jalankan `php artisan filament:assets`
sesudah memperbarui paket Filament.

---

## 3. Vercel — tidak cocok untuk aplikasi ini

Dievaluasi eksplisit, dan jawabannya tidak. Bukan karena Vercel buruk, tetapi
karena bentuk aplikasinya tidak cocok dengan bentuk Vercel:

1. **PHP bukan runtime kelas satu.** Vercel menjalankan fungsi Node/Python/Go;
   Laravel hanya dapat berjalan lewat runtime komunitas pihak ketiga yang tidak
   didukung resmi. Ini sistem sekolah yang akan menyimpan data siswa — bukan
   tempat bergantung pada runtime tak resmi.
2. **Berkas tidak persisten.** PPDB menyimpan dokumen identitas, keuangan
   menyimpan bukti pembayaran, dan halaman muka menyimpan foto kegiatan, semua
   di `storage/app`. Filesystem fungsi serverless bersifat sementara: berkas
   yang diunggah penguji akan hilang. Menyiasatinya menuntut S3 — layanan
   berbayar yang belum disetujui, dan perubahan arsitektur penyimpanan yang
   tidak diminta.
3. **`storage:link` tidak ada artinya** pada filesystem yang tidak persisten.
4. **Worker antrean tidak mungkin.** `GenerateReportCardPdf` dan
   `GenerateStudentFees` menuntut proses yang hidup terus. Fungsi serverless
   berumur pendek dan tidak punya `queue:work`.
5. **Cron penjadwal** menuntut proses terjadwal di sisi server.
6. **Sesi dan cache di MySQL** menuntut koneksi TCP yang tahan lama ke basis
   data; kolam koneksi dari fungsi serverless adalah masalah tersendiri.
7. **Filament** adalah panel penuh yang berjalan di sisi server (Livewire),
   bukan frontend statis. Tidak ada bagian yang menjadi lebih ringan di Vercel.

**Yang cocok**: satu VPS Linux biasa (Ubuntu) dengan Nginx + PHP-FPM + MySQL +
Supervisor + cron — persis seperti rencana produksi di
[`../deployment-production.md`](../deployment-production.md). Staging sebaiknya
menyerupai produksi; itu justru gunanya.

Cukup satu VPS kecil (mis. 2 vCPU / 2–4 GB RAM). Staging boleh berbagi mesin
dengan produksi **hanya** bila basis data, direktori, akun MySQL, dan
`storage/` seluruhnya terpisah — memisahkan mesinnya lebih aman.

---

## 4. Kebijakan data staging

**Staging tidak menjadi salinan data sekolah sungguhan.**

Yang dipakai:

- akun demo sintetis dari `SimulationSeeder` dan `Sprint4DemoSeeder`;
- siswa, PPDB, nilai, dan tagihan karangan;
- tidak ada dokumen identitas sungguhan;
- tidak ada bukti pembayaran sungguhan;
- tidak ada klon basis data produksi kecuali disetujui eksplisit **dan**
  disanitasi lebih dulu (belum pernah dilakukan, dan tidak dilakukan di sini).

`SimulationSeeder` aman dipakai di staging: pagarnya menolak `production`, dan
pagar itu **tidak dilemahkan** oleh pekerjaan ini. Ia juga tetap tidak
didaftarkan otomatis di `DatabaseSeeder` — harus dipanggil dengan sengaja.

### Seeder yang berjalan otomatis, dan yang tidak

`php artisan db:seed` memanggil **tepat tiga** seeder, dan ketiganya prasyarat
struktural — bukan data contoh:

| Seeder | Mengapa otomatis |
| --- | --- |
| `RolePermissionSeeder` | tanpa peran dan izin, setiap policy menolak semua orang |
| `SchoolSeeder` | cabang PUSAT; seluruh data lain menggantung padanya |
| `UserSeeder` | akun awal; tanpanya panel tidak dapat dimasuki sama sekali |

Yang **tidak** otomatis, dan harus diminta satu per satu: `SimulationSeeder`,
`Sprint4DemoSeeder`, `PublicSiteSeeder`. Dua yang pertama membuat akun yang
dapat login; yang ketiga menerbitkan isi ke halaman publik. Adanya staging tidak
mengubah itu (butir 513).

Perintah yang disetujui untuk UAT ada di §7 langkah 8 — **hanya tiga**, dan
tidak ada instruksi "jalankan semua seeder" di mana pun dokumen ini.

### Data siswa sungguhan (M3) tetap terpisah

Berkas 40 siswa sungguhan **tidak** diimpor ke staging oleh tugas ini. NIS,
NISN, nomor telepon, alamat, dan kontak orang tua tidak disalin ke repositori
maupun ke staging. UAT berjalan lebih dulu dengan data sintetis; impor data
sungguhan adalah keputusan tersendiri dengan pagarnya sendiri (lihat
[`../migration/m3-test-import.md`](../migration/m3-test-import.md)).

---

## 5. Kontrak environment

Berkas contoh: **`.env.staging.example`** (nama variabel dan placeholder saja —
tidak ada satu pun nilai rahasia, dan tidak boleh pernah ada).

Yang menentukan:

| Variabel | Nilai staging | Sebab |
| --- | --- | --- |
| `APP_ENV` | `staging` | menyalakan penanda layar **dan** mewajibkan `SEED_ADMIN_PASSWORD` |
| `APP_DEBUG` | `false` | halaman galat Laravel memuat isi variabel dan potongan kode |
| `APP_URL` | `https://staging.smartsukses.sch.id` | dipakai membangun URL berkas publik |
| `SESSION_SECURE_COOKIE` | `true` | cookie hanya lewat HTTPS |
| `SESSION_DOMAIN` | `null` | cookie terikat persis pada host staging, tidak bocor ke subdomain lain |
| `MAIL_MAILER` | `log` | penguji tidak dapat menyurati siapa pun |
| `DB_DATABASE` | `smartsukses_staging` | **bukan** basis data produksi |
| `CORS_ALLOWED_ORIGINS` | host staging | jangan `*` |
| `LOG_LEVEL` | `debug` | aman selama `APP_DEBUG=false`; yang bertambah rinci hanya berkas log |

Tidak pernah masuk git: `APP_KEY`, `DB_PASSWORD`, `MAIL_PASSWORD`,
`SEED_ADMIN_PASSWORD`.

### Dibaca lewat config, bukan `env()`

`SEED_ADMIN_PASSWORD` dibaca lewat **`config/seeding.php`**, tidak pernah lewat
`env()` langsung dari kode aplikasi.

Ini bukan gaya penulisan melainkan syarat kebenaran: `env()` di luar berkas
config mengembalikan NULL begitu `config:cache` dijalankan — yaitu tepat di
staging dan produksi. Pagar yang membacanya langsung akan menolak seeding di
server yang justru **sudah benar** konfigurasinya, dan `app:production-check`
akan melaporkan kata sandi seeder "belum disetel" pada server yang sudah diisi
(butir 357, butir 511).

Keduanya sudah diperbaiki dan ada tesnya.

### Temuan keamanan yang diperbaiki di batch ini

Kata sandi akun hasil seeding punya nilai cadangan yang tertulis di dalam
repositori. Pagarnya semula hanya menyebut `production`, sehingga
`APP_ENV=staging` melewatinya begitu saja — seluruh akun awal, termasuk Super
Administrator, akan lahir dengan kata sandi yang diketahui publik, di alamat
yang dapat dibuka siapa pun.

Dua celah sekaligus: `SimulationSeeder` dan `Sprint4DemoSeeder` membaca env yang
sama **tanpa pagar apa pun**, dan keduanya dapat dijalankan sendiri tanpa
`UserSeeder`.

Keduanya kini ditutup di satu tempat, `App\Support\SeedPassword`, yang menolak
kata sandi bawaan di lingkungan mana pun selain `local` dan `testing`. Yang
dipagari adalah "bukan lokal", bukan daftar nama — sehingga `uat`, `demo`, atau
nama lingkungan berikutnya ikut terlindungi tanpa perlu diingat.

---

## 6. Penyimpanan berkas

| Kelas | Disk | Letak | Boleh publik? |
| --- | --- | --- | --- |
| Logo & galeri halaman muka | `public` | `storage/app/public` | ya, lewat `storage:link` |
| Dokumen PPDB | `local` | `storage/app/private` | **tidak** |
| Bukti pembayaran | `local` | `storage/app/private` | **tidak** |
| Bukti transaksi kas | `local` | `storage/app/private` | **tidak** |
| Berkas import sementara | `local` | `storage/app/private/imports` | **tidak**, dihapus setelah dipakai |

Berkas privat hanya dapat diambil lewat controller yang memeriksa policy, dan
itu **tidak diubah** untuk mempermudah hosting. Host yang tidak mempertahankan
berkas antar-deploy tidak cocok untuk aplikasi ini (lihat §3).

Direktori yang harus dapat ditulis PHP-FPM: `storage/` dan `bootstrap/cache/`.

---

## 7. Urutan deployment staging

Perintahnya diambil dari repositori ini, bukan dikarang.

```sh
# 1. ambil commit yang disetujui (bukan sembarang master)
cd /var/www/smartsukses-staging
git fetch --all
git checkout <commit-yang-disetujui>

# 2. dependensi PHP  (Node TIDAK dibutuhkan — lihat §2)
composer install --no-dev --optimize-autoloader

# 3. environment
cp .env.staging.example .env
# isi DB_PASSWORD dan SEED_ADMIN_PASSWORD sekarang, sebelum langkah 4.
php artisan key:generate

# 4. cache konfigurasi — SEBELUM migrate dan seed
#    Kata sandi seeder dibaca lewat config (butir 511), jadi cache harus sudah
#    memuat .env yang lengkap. Menjalankan config:cache SESUDAH seeding tetap
#    benar, tetapi menjalankannya lebih dulu membuat seluruh langkah berikutnya
#    membaca konfigurasi yang persis sama dengan yang nanti dipakai web server.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:optimize

# 5. basis data — dibuat kosong lebih dulu, terpisah dari produksi
#    CREATE DATABASE smartsukses_staging CHARACTER SET utf8mb4;

# 6. skema
php artisan migrate --force

# 7. penyimpanan
php artisan storage:link
sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;

# 8. data sintetis — HANYA tiga perintah berikut, dan tidak lebih.
#    Jangan "jalankan semua seeder"; yang tidak disebut di sini memang tidak
#    disetujui untuk UAT (butir 513).

#    8a. prasyarat struktural + akun awal. Berhenti dengan galat bila
#        SEED_ADMIN_PASSWORD belum disetel — itu disengaja (butir 509).
php artisan db:seed --force

#    8b. dataset demo sintetis: cabang, kelas, siswa, nilai, jadwal, ujian,
#        bekal keuangan, dan akun untuk kedelapan peran. Memanggil UserSeeder
#        dan Sprint4DemoSeeder sendiri, jadi keduanya tidak perlu disebut lagi.
#
#        WAJIB SESUDAH 8a, tidak pernah menggantikannya. Pada basis data yang
#        masih kosong, perintah ini gagal dengan "There is no role named
#        'SUPER_ADMIN' for guard 'web'" karena peran, cabang, dan akun awal
#        dibuat 8a. Kegagalan itu bukan kerusakan, melainkan urutan yang
#        terlewat.
php artisan db:seed --class=SimulationSeeder --force

#    8c. isi awal halaman muka publik. OPSIONAL — lewati bila pemilik akan
#        mengisinya sendiri lewat panel (butir 481).
php artisan db:seed --class=PublicSiteSeeder --force

# 9. worker
sudo supervisorctl restart smartsukses-worker:*
php artisan queue:restart
```

Sesudah setiap deployment berikutnya, ulangi langkah 2, 4, 6, dan 9 — **bukan**
langkah 8. Seeder hanya untuk penyiapan awal; menjalankannya ulang aman
(seluruhnya `updateOrCreate`) tetapi akan mengembalikan data demo yang mungkin
sudah diubah penguji.

### Bila `config:cache` sudah terlanjur dibangun tanpa kata sandi

Gejalanya: `php artisan db:seed` menolak dengan pesan `SEED_ADMIN_PASSWORD wajib
disetel` walaupun `.env` sudah diisi. Sebabnya konfigurasi yang di-cache masih
yang lama.

```sh
php artisan config:clear
php artisan config:cache
```

`migrate:fresh`, `db:wipe`, dan `migrate:refresh` **tidak dipakai** di
lingkungan bersama. Bila staging perlu dikosongkan, jatuhkan dan buat ulang
basis datanya secara sadar lewat MySQL, bukan lewat perintah artisan yang
sewaktu-waktu bisa salah sasaran.

### Worker dan penjadwal

Template sudah ada di repositori dan tinggal disalin dengan jalur disesuaikan:

- `ops/smartsukses-worker.conf` — Supervisor
- `ops/smartsukses-cron` — penjadwal + backup

`--timeout` worker (60) wajib lebih kecil daripada `retry_after` antrean (90).
Periksa `timedatectl` sebelum memasang cron: jamnya ditulis untuk zona
Asia/Jakarta, dan `APP_TIMEZONE` tidak mengubah zona cron.

---

## 8. Migrasi: yang dijanjikan dan yang tidak

`php artisan migrate --force` boleh dijalankan di staging **sesudah** tiga hal
dipastikan: basis data yang benar (`smartsukses_staging`), lingkungan yang benar
(`APP_ENV=staging`), dan salinan basis data terakhir masih ada bila isinya
sedang dipakai penguji.

Yang **tidak** dijanjikan: migrasi ini belum diverifikasi dapat dibalik.
`migrate:rollback` ada, tetapi tidak seluruh migrasi punya `down()` yang teruji,
dan rollback yang menjatuhkan kolom akan menghapus datanya. Rencana pemulihan
yang sesungguhnya untuk staging adalah **memulihkan basis data dari salinan**,
bukan mengandalkan rollback migrasi.

---

## 9. Antrean dan penjadwal — apa yang benar-benar membutuhkannya

| Fitur | Antrean? | Akibat bila worker mati |
| --- | --- | --- |
| PDF rapor satu kelas | ya | status `QUEUED` selamanya, tanpa pesan galat |
| PDF rapor satu siswa | tidak | tetap berjalan langsung |
| Generate tagihan massal | ya | tidak pernah selesai |
| Notifikasi in-app | tidak | langsung tersimpan |
| `notifications:prune` | cron | notifikasi lama menumpuk |

Antrean memakai driver `database`. **Redis tidak dibutuhkan** dan tidak perlu
dipasang.

---

## 10. Surel dan WhatsApp — staging tidak boleh menghubungi siapa pun

**Surel.** Satu-satunya surel keluar aplikasi ini adalah tautan reset kata sandi
panel Filament. Tidak ada `Mail::send`, tidak ada kelas `Mailable`, dan tidak
ada `Notification` surel di seluruh `app/`.

Staging memakai `MAIL_MAILER=log`: tautannya ditulis ke `storage/logs` dan tidak
terkirim ke alamat siapa pun. Operator mengambilnya dari log bila perlu. Bila
alur reset perlu diuji sungguhan, pakai penangkap surel lokal (Mailpit/MailHog —
gratis, tidak mengirim ke luar). **Jangan** arahkan staging ke SMTP produksi.

**WhatsApp.** Tidak ada integrasi WhatsApp API. Yang ada hanya tautan `wa.me`
yang dirakit sebagai URL dan ditampilkan sebagai tautan; pesan baru terkirim
bila manusia menekannya lalu menekan kirim di aplikasi WhatsApp-nya sendiri.
Tidak ada jalur pengiriman otomatis, dan UAT tidak dapat memicunya diam-diam.

Meski begitu, tetap pakai nomor karangan di data demo agar tidak ada penguji
yang tidak sengaja membuka percakapan ke nomor sungguhan.

---

## 11. Akses penguji

Peran yang perlu dicoba: Super Admin, Admin Sekolah, Kepala Sekolah,
Guru Mata Pelajaran, Wali Kelas, Bendahara, Siswa, Orang Tua — delapan, satu
untuk setiap case pada `RoleName`.

`SimulationSeeder` membuat akun untuk kedelapannya, seluruhnya memakai
`SEED_ADMIN_PASSWORD` yang disetel operator.

Akun-akun itu **tidak** wajib mengganti kata sandi pada login pertama.
Penandanya sengaja dilepas — hanya di seeder yang sudah menolak berjalan di
produksi — karena layar ganti kata sandi menghentikan pengujian sebelum satu
menu pun terbuka (butir 463). Pagar `must_change_password` milik `UserSeeder`
sendiri tetap utuh untuk produksi.

**Kata sandi tidak ditulis di repositori ini, tidak di dokumen ini, dan tidak
dikirim lewat chat.** Operator menyebarkannya lewat jalur yang aman, satu per
penguji bila memungkinkan.

Daftar surel akun beserta perannya, urutan perintah penyiapan, dan daftar
periksa per peran ada di **`docs/uat/role-testing.md`**. Sumber tunggalnya
konstanta `SimulationSeeder::UAT_ACCOUNTS`.

Satu pintu masuk tetap `/login` untuk semua peran; tidak ada halaman login
per-peran, dan itu tidak diubah.

---

## 12. Penanda lingkungan

Staging memakai nama host sungguhan, data yang mirip sungguhan, dan tampilan
yang identik dengan produksi. Satu-satunya pembeda di mata staf adalah alamat di
bilah URL — dan alamat adalah hal pertama yang berhenti dibaca orang setelah
hari kedua.

Karena itu ada penanda kecil bertuliskan **STAGING / UAT** di pojok kanan bawah,
muncul di halaman publik, PPDB, portal, dan panel admin. Ia `position: fixed`,
`pointer-events: none` sehingga tidak pernah menghalangi tombol, menghormati
safe-area iOS, dan tidak ikut tercetak.

Di produksi ia **tidak dirender sama sekali** — bukan disembunyikan lewat CSS,
melainkan tidak ada markupnya. Ada tesnya.

---

## 13. Kesehatan aplikasi

Endpoint `GET /up` **sudah ada** (bawaan Laravel 11, terdaftar di
`bootstrap/app.php`). Ia mengembalikan halaman statis tanpa versi PHP, tanpa
nama basis data, tanpa isi konfigurasi, dan tanpa data pribadi — aman diarahkan
ke pemantau publik.

Tidak ada endpoint kesehatan baru yang dibuat: menambah endpoint kedua hanya
menggandakan permukaan tanpa menambah informasi.

Perlu diketahui batasnya: `/up` membuktikan PHP hidup, **bukan** bahwa MySQL
tersambung. Bila pemantauan staging nanti perlu memastikan basis data juga,
Laravel menyediakan event `DiagnosingHealth` sebagai tempatnya — belum
dipasang, dan sengaja tidak dipasang tanpa permintaan.

---

## 14. Keamanan staging

| Pemeriksaan | Status |
| --- | --- |
| `APP_DEBUG=false` | disetel di berkas contoh, ada tesnya |
| HTTPS | wajib; TLS disiapkan operator |
| `SESSION_SECURE_COOKIE=true` | disetel di berkas contoh |
| `SESSION_DOMAIN=null` | cookie tidak bocor ke subdomain lain |
| CSRF | aktif; jalur API memakai token Bearer tanpa cookie |
| Throttle login | ada, satu ruang nama untuk seluruh peran |
| Throttle API | `throttle:5,1` untuk login, `throttle:60,1` sesudahnya |
| Berkas privat | hanya lewat controller ber-policy |
| `.env` tidak terjangkau | document root `public/`; `.env` di atasnya |
| Listing direktori | dimatikan di konfigurasi web server (tugas operator) |
| Telescope / Debugbar | **tidak terpasang** — tidak ada yang perlu dimatikan |
| Kredensial bawaan | ditutup di batch ini (§5) |
| Seeding gagal-tertutup | penolakan terjadi sebelum satu baris pun ditulis |

Autentikasi **tidak** dilonggarkan untuk mempermudah UAT. Penguji memakai akun
sungguhan dengan peran sungguhnya.

Satu hal yang perlu diputuskan operator: apakah staging dibuka ke internet umum
atau dibatasi (Basic Auth di Nginx, atau daftar IP). Aplikasi tidak memaksakan
salah satunya. Membatasinya adalah lapisan tambahan yang murah.

---

## 15. Backup dan rollback

**Kode.** Kembali ke commit sebelumnya yang diketahui baik, lalu ulangi langkah
2–9 di §7. Karena tidak ada langkah build, rollback kode berarti `git checkout`
dan `composer install`.

**Basis data.** Ambil salinan sebelum deployment yang mengubah skema:

```sh
ops/backup-database.sh /var/backups/smartsukses-staging
```

Pemulihan lewat `ops/restore-database.sh`, yang punya pagar menolak menimpa
basis data sungguhan kecuali diminta eksplisit.

**Berkas.** `storage/app/` harus bertahan melewati rollback kode. Ia berada di
luar direktori yang di-checkout, jadi selama deployment tidak menghapus
`storage/`, berkas yang diunggah penguji tetap ada. **Backup berkas belum pernah
diuji** — dicatat apa adanya, bukan diklaim aman.

Infrastruktur backup berbayar tidak dipasang dan tidak disarankan untuk staging:
data staging sintetis dan dapat dibuat ulang dengan seeder.

---

## 16. Daftar smoke test

Dijalankan sesudah setiap deployment staging.

**Publik**
- [ ] `/` terbuka, tanpa galat
- [ ] penanda **STAGING / UAT** terlihat
- [ ] pengalih bahasa ID ↔ EN bekerja
- [ ] tombol PPDB mengarah ke alamat yang benar
- [ ] tombol artikel/blog muncul bila `blog_url` disetel, hilang bila tidak
- [ ] tata letak 360px tanpa luberan mendatar

**Autentikasi**
- [ ] `/login` terbuka
- [ ] tidak ada `/login/siswa`, `/login/guru`, `/login/admin`
- [ ] masing-masing peran diarahkan ke tujuan yang benar
- [ ] kata sandi wajib diganti pada login pertama
- [ ] throttle login bekerja sesudah percobaan berulang

**Panel admin**
- [ ] login panel berhasil
- [ ] penanda STAGING terlihat di panel
- [ ] Pengaturan Situs Publik dapat disimpan dan langsung tampil di `/`
- [ ] Data Siswa tampil dan terbatas pada cabangnya
- [ ] **Unduh Template Excel** menghasilkan berkas dua lembar
- [ ] import template yang diisi berhasil, dan pesannya sesuai kenyataan

**Siswa**
- [ ] dasbor terbuka
- [ ] jadwal dan nilai tampil
- [ ] alur CBT dasar berjalan bila data demo tersedia

**Orang tua**
- [ ] portal terbuka
- [ ] nilai dan tagihan anak tampil
- [ ] tidak dapat melihat siswa lain

**Guru**
- [ ] daftar kelas tampil
- [ ] input nilai tersimpan
- [ ] rapor satu siswa dapat diunduh

**Keuangan**
- [ ] generate tagihan massal selesai (**membuktikan worker hidup**)
- [ ] pembayaran tercatat
- [ ] laporan keuangan tampil

**PPDB**
- [ ] halaman publik terbuka
- [ ] pendaftaran tersimpan
- [ ] cek status bekerja
- [ ] dokumen hanya dapat diunduh peran yang berhak

**Berkas**
- [ ] logo dan foto halaman muka tampil (membuktikan `storage:link`)
- [ ] URL berkas privat langsung **ditolak** tanpa autentikasi
- [ ] berkas bertahan setelah deployment berikutnya

**Antrean**
- [ ] PDF rapor satu kelas selesai (bukan `QUEUED` selamanya)

---

## 17. Yang masih dibutuhkan dari operator server

Tidak satu pun ada di repositori, dan tidak satu pun boleh masuk ke repositori.
**Jangan kirim nilainya lewat chat.**

| Kebutuhan | Keterangan |
| --- | --- |
| Server staging | VPS Linux; sistem operasi dan spesifikasi ditentukan operator |
| Alamat IP tujuan | untuk record DNS — belum diketahui, dan tidak ditebak di sini |
| Record DNS | `staging.smartsukses.sch.id` → IP server (**tidak diubah tugas ini**) |
| Sertifikat TLS | Let's Encrypt sudah cukup dan gratis |
| Akses SSH | untuk operator, bukan untuk repositori |
| Basis data MySQL | `smartsukses_staging` + akun khusus, **bukan root** |
| `DB_PASSWORD` | dibuat operator |
| `APP_KEY` | `php artisan key:generate` di server |
| `SEED_ADMIN_PASSWORD` | dibuat operator; disebarkan lewat jalur aman |
| Konfigurasi Nginx | document root `public/`, listing direktori mati |
| Supervisor | untuk worker antrean |
| Entri cron | untuk penjadwal |
| Keputusan akses | staging terbuka publik, atau dibatasi Basic Auth / daftar IP |

Tidak ada layanan berbayar yang dibutuhkan untuk staging. Yang di luar VPS dan
domain — S3, layanan surel berbayar, backup terkelola, APM — **tidak** dipasang
dan tidak diasumsikan.

---

## 18. Di luar cakupan

Sengaja tidak dikerjakan: penyediaan server, perubahan DNS, pembelian hosting,
deployment itu sendiri, migrasi WordPress, cutover produksi, impor data siswa
sungguhan, dan perubahan konfigurasi produksi mana pun.
