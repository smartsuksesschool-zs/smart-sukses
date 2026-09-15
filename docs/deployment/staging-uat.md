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

## 5b. Railway — staging yang benar-benar berjalan

Runbook di atas menyiapkan VPS `staging.smartsukses.sch.id`. Yang **sudah
berjalan** hari ini adalah staging di Railway, dan topologinya berbeda: TLS
publik berhenti di edge Railway, lalu diteruskan ke Caddy/FrankenPHP yang
dijalankan Railpack, baru ke PHP.

```
Railway edge (TLS berhenti di sini)
  → Caddy / FrankenPHP
    → Laravel
      → aplikasi
```

Ada **dua** hop, dan masing-masing harus memercayai hop sebelumnya. Melewatkan
salah satunya menghasilkan gejala yang sama persis, dan gejalanya tidak
menunjukkan lapisan mana yang salah.

### Yang harus disetel

| Lapisan | Variabel | Nilai |
| --- | --- | --- |
| Laravel | `TRUSTED_PROXIES` | `*` |
| Railpack/Caddy | `CADDY_GLOBAL_OPTIONS` | lihat di bawah |

```
servers {
    trusted_proxies static private_ranges 100.0.0.0/8
}
```

Nilai `private_ranges 100.0.0.0/8` mengikuti contoh konfigurasi resmi
Railway/Caddy untuk memercayai proxy Railway. Pengaturan ini
**khusus Railway/Railpack** — jangan disalin ke penyedia lain, yang punya
konfigurasi proxy dan dokumentasinya sendiri.

`TRUSTED_PROXIES=*` sah di sini karena origin tidak dapat dihubungi langsung
dari internet: lalu lintas publik hanya masuk lewat edge Railway.

### Gejala bila salah satunya terlewat

Peramban menampilkan `https://` di bilah alamat, tetapi Laravel membaca
permintaannya sebagai HTTP. Akibatnya seluruh URL absolut, action formulir, dan
URL aset lahir ber-skema `http://`:

* panel Filament tampil sebagai HTML polos dengan ikon SVG seukuran layar —
  asetnya diblokir peramban sebagai konten campuran;
* tombol keluar memicu peringatan Chrome *"The information you're about to
  submit is not secure"*;
* pengalihan menjawab `Location: http://…` pada permintaan yang jelas HTTPS.

### Smoke test sesudah deploy

Konfigurasi Caddy tidak dapat diuji dari PHPUnit — `TrustedProxyTest` hanya
menjamin lapisan Laravel. Yang memverifikasi hop pertama adalah permintaan
sungguhan ke staging:

```bash
U=https://<host-staging>

# 1. Pengalihan harus https
curl -sI "$U/admin/login" | grep -i '^location'

# 2. Harus NOL rujukan http://
curl -sL "$U/login" | grep -coE '(src|href|action)="http://[^"]*"'

# 3. Harus ada rujukan https://
curl -sL "$U/login" | grep -coE '(src|href|action)="https://[^"]*"'
```

Cara mengurung lapisan mana yang salah bila masih gagal: kirim permintaan
internal langsung ke Caddy/FrankenPHP dengan `X-Forwarded-Proto: https`. Kalau
permintaan internal itu **sudah** menghasilkan `https://` sementara permintaan
publik belum, yang belum benar adalah `CADDY_GLOBAL_OPTIONS`, bukan
`TRUSTED_PROXIES`.

---

## 5c. Railway — penyimpanan berkas privat yang bertahan

Berkas sistem sebuah service Railway bersifat **sementara**: setiap redeploy
mengembalikannya ke isi image. Lebih tajam lagi, `smart-sukses` (web) dan
`smart-sukses-worker` berjalan sebagai service terpisah dengan berkas sistem
masing-masing.

Akibatnya bukan berkas yang hilang nanti, melainkan berkas yang **tidak pernah
ada di tempat yang membutuhkannya**: `GenerateReportCardPdf` menulis PDF rapor
dari worker, dan web-lah yang harus menyajikannya. Tanpa penyimpanan bersama,
tombol unduhnya tidak pernah muncul dan tidak ada satu pun galat yang
menjelaskan mengapa.

### Lima disk yang dapat dikonfigurasi

| Variabel | Bawaan | Isi berkas | Dibagi web+worker? |
| --- | --- | --- | --- |
| `REPORT_CARD_DISK` | `local` | PDF rapor | **ya** |
| `PAYMENT_PROOF_DISK` | `local` | bukti pembayaran | tidak |
| `TRANSACTION_PROOF_DISK` | `local` | bukti transaksi kas | tidak |
| `PPDB_PRIVATE_DISK` | `local` | dokumen pendaftar PPDB | tidak |
| `STUDENT_PHOTO_DISK` | `local` | foto siswa | tidak |

Empat yang terakhir tidak dibagi antar service, tetapi ketahanannya sama
pentingnya — itu dokumen keuangan, dokumen identitas, dan foto anak.

Foto siswa baru masuk daftar ini di butir 587. Sebelumnya ia di disk publik dan
dapat dibuka siapa pun yang memegang URL-nya; kini ia hanya disajikan lewat rute
panel `/admin/siswa/{id}/foto`, yang memeriksa cabang, kelas ajar guru, dan
`StudentPolicy::view` pada setiap permintaan.

Kosongkan seluruhnya untuk pemasangan dengan berkas sistem yang menetap; di
sana `local` tetap jawaban yang benar.

### Yang TIDAK ikut pindah

`FILESYSTEM_DISK` **tetap `local`**. Jalur impor Excel memanggil
`Storage::disk('local')->path()` — lintasan berkas sungguhan yang tidak dimiliki
objek S3. Menyetel `FILESYSTEM_DISK=s3` akan menukar satu masalah dengan masalah
lain yang lebih sunyi.

Media publik (`SiteSetting::MEDIA_DISK`, `School::LOGO_DISK`,
`User::AVATAR_DISK`) juga tidak ikut. Ketiganya disajikan lewat URL disk publik,
sedangkan Railway Storage Bucket bersifat privat. Ketahanannya lewat Railway
Volume — lihat §5d.

### Variabel pada KEDUA service

Setel pada `smart-sukses` **dan** `smart-sukses-worker`, dengan nilai yang sama:

```
REPORT_CARD_DISK=s3
PAYMENT_PROOF_DISK=s3
TRANSACTION_PROOF_DISK=s3
PPDB_PRIVATE_DISK=s3
STUDENT_PHOTO_DISK=s3
```

`STUDENT_PHOTO_DISK` hanya dibaca web, tetapi menyetelnya juga di worker tidak
berbahaya dan menjaga kedua daftar variabel tetap identik. Tidak ada kredensial
baru: foto siswa memakai bucket dan referensi variabel yang sama persis.

Kredensial bucket masuk lewat **referensi variabel Railway**, bukan disalin:

| Variabel Laravel | Referensi ke bucket |
| --- | --- |
| `AWS_BUCKET` | `BUCKET` |
| `AWS_ACCESS_KEY_ID` | `ACCESS_KEY_ID` |
| `AWS_SECRET_ACCESS_KEY` | `SECRET_ACCESS_KEY` |
| `AWS_DEFAULT_REGION` | `REGION` |
| `AWS_ENDPOINT` | `ENDPOINT` |
| `AWS_USE_PATH_STYLE_ENDPOINT` | lihat tab Credentials bucket |

Nilai `AWS_USE_PATH_STYLE_ENDPOINT` **ditentukan tab Credentials bucket itu
sendiri**, tidak ditebak dan tidak diasumsikan `true`. Salah menebaknya
menghasilkan galat penandatanganan yang sulit dibaca.

Bucket-nya **privat** dan harus tetap privat. Rapor dan bukti keuangan tetap
disajikan lewat aksi yang sudah melewati policy — tidak ada `Storage::url()`,
tidak ada tautan bertanda tangan tanpa penjagaan, tidak ada symlink publik.

### Kesiapan kode bukan pemindahan berkas

Batch ini hanya membuat kodenya siap. Setelah di-deploy, langkah operator masih:

1. buat Railway Storage Bucket;
2. hubungkan kredensialnya ke `smart-sukses`;
3. hubungkan kredensial yang **sama** ke `smart-sukses-worker`;
4. setel kelima variabel disk di atas menjadi `s3` pada kedua service;
5. redeploy keduanya;
6. terbitkan rapor **baru** yang sintetis;
7. pastikan worker menulisnya;
8. pastikan web dapat mengunduhnya;
9. redeploy sekali lagi;
10. pastikan berkas itu **masih** dapat diunduh.

Langkah 9–10 yang membuktikannya, bukan langkah 8: berkas di berkas sistem
sementara pun dapat diunduh sesaat setelah ditulis.

PDF yang sudah telanjur ada di berkas sistem sementara **tidak perlu
dipindahkan**. Isinya data UAT sintetis dan seluruhnya dapat dibuat ulang dengan
menerbitkan rapornya lagi.

Untuk foto siswa, buktinya sama: unggah foto **sintetis** (bukan foto anak
sungguhan) pada satu siswa sintetis, pastikan tampil di Data Siswa, redeploy,
pastikan masih tampil — lalu pastikan URL gambarnya `/admin/siswa/{id}/foto`,
dan bahwa URL itu dibuka tanpa login berakhir di halaman masuk.

---

## 5d. Media publik — Railway Volume pada service web

Semua media publik ditulis ke disk `public` dan disajikan lewat
`Storage::url()` → `/storage/…`. Di Railway berkas itu ikut sementara: gambar
yang diunggah lewat panel hilang pada redeploy berikutnya, sementara basis data
tetap menyimpan lintasannya — hasilnya gambar rusak, bukan galat.

Jawabannya **Railway Volume pada service web, dipasang tepat di akar disk
publik**. Volume sendiri tidak menuntut perubahan kode; kontrak yang
diandalkannya dijaga `tests/Feature/Ops/PublicMediaStorageContractTest.php`
(butir 586).

### Yang tersimpan di disk publik — dan hanya itu isi Volume

| Kategori | Ditulis dari | Direktori |
| --- | --- | --- |
| Gambar blok halaman muka | Panel → Blok Situs | `site/` |
| Logo & gambar utama situs | Panel → Pengaturan Situs Publik | `site/` |
| Logo cabang | Panel → Cabang / Pengaturan Tampilan | `schools/logos/` |
| Foto profil pengguna | Panel → Pengguna | `avatars/` |

**Foto siswa bukan isi Volume ini.** Ia data pribadi anak dan sejak butir 587
tinggal di disk privat `STUDENT_PHOTO_DISK` (§5c), disajikan hanya lewat rute
panel berwenang.

Keempatnya ditulis **hanya oleh service web** (unggahan Filament). Tidak ada job
antrean yang menulis maupun membaca berkas publik, dan templat PDF rapor — yang
dirender worker — tidak menyematkan satu pun gambar. Karena itu Volume **tidak**
dipasang pada `smart-sukses-worker`. Bila kelak sebuah job mulai menyentuh media
publik, test kontrak di atas menjadi merah lebih dulu.

Berkas privat (§5c) tidak pernah menyentuh disk publik dan tidak terpengaruh
Volume ini.

### Lintasan mount: `/app/storage/app/public`

Akar aplikasi di image Railpack adalah `/app`, dan akar disk publik adalah
`storage_path('app/public')`. Lintasannya karena itu **`/app/storage/app/public`**
— bukan `/app/storage`, dan bukan `/app/public/storage`.

| Lintasan | Mengapa tidak |
| --- | --- |
| `/app/storage` | Ikut mempersistenkan `app/private` (temp impor Excel yang memuat data siswa, unggahan sementara Livewire, PDF rapor bawaan), `framework/` (cache view dan temp laravel-excel), serta log — semuanya ke Volume yang dimaksudkan untuk gambar publik. |
| `/app/public/storage` | Itu tautan simbolik yang dibuat `storage:link`, bukan direktori. Volume di sana menutupi tautannya, dan unggahan tetap ditulis ke direktori sementara. |

Satu-satunya berkas repositori di `storage/app/public` adalah `.gitignore`, dan
Volume akan menutupinya (Volume tidak dipasang sebagai overlay). Itu tidak
berpengaruh apa pun terhadap aplikasi.

### Tautan `/storage` sudah dibuat Railpack

`public/storage` di-gitignore dan tidak ada skrip composer yang menjalankan
`storage:link`. Yang membuatnya adalah skrip start Railpack: setiap container
start ia menjalankan `migrate --force`, lalu `php artisan storage:link`, lalu
`optimize`. Terbukti di staging yang berjalan: `/storage/.gitignore` menjawab
200 dengan isi berkas repositori.

Volume dipasang saat container start, bukan saat build — tautannya menunjuk
direktori mount itu sendiri, jadi urutan ini tetap benar tanpa perubahan.

### Batasan Railway Volume (dokumentasi resmi)

- **Satu Volume per service.** Service web tidak dapat punya Volume kedua kelak.
- **Replika tidak dapat dipakai bersama Volume.** Service web terkunci pada satu
  instance selama Volume terpasang.
- **Redeploy menimbulkan jeda singkat**, bahkan dengan healthcheck, karena
  Volume harus dilepas dari container lama sebelum dipasang ke yang baru.
- **Image non-root** membutuhkan `RAILWAY_RUN_UID=0` agar dapat menulis ke
  Volume. Setel hanya bila unggahan gagal dengan galat izin tulis.
- **Ukuran** mengikuti paket: 0,5 GB (Free/Trial), 5 GB (Hobby), 50 GB (Pro).
  Batas unggahan di panel 2–4 MB per berkas. Harga tidak dicantumkan di sini —
  lihat halaman Railway.
- **Backup** Volume tersedia (manual dan terjadwal); aktifkan sebelum data
  sungguhan masuk.

Dokumentasi Railway tidak menyatakan secara eksplisit bahwa isi Volume bertahan
melewati redeploy. Itu yang dibuktikan langkah 9–10 di bawah, bukan diasumsikan.

### Langkah operator (belum dijalankan)

0. **Prasyarat:** commit yang memindahkan foto siswa ke disk privat (butir 587)
   sudah ter-deploy di `smart-sukses`. Memasang Volume lebih dulu berarti foto
   siswa yang diunggah di antaranya ikut menetap di Volume publik.
1. Tambahkan Volume pada service **`smart-sukses`** saja — bukan worker.
2. Lintasan mount: `/app/storage/app/public`.
3. `FILESYSTEM_DISK` **tetap `local`**.
4. Variabel penyimpanan privat §5c (`REPORT_CARD_DISK` dan seterusnya, serta
   kredensial bucket) **tidak disentuh**.
5. Tidak ada variabel baru untuk media publik; `storage:link` sudah dijalankan
   Railpack.
6. Redeploy `smart-sukses`. Sesudahnya, `/storage/.gitignore` kini menjawab
   **403** (404 bila `APP_ENV=production`), bukan 200 — tanda Volume yang masih
   kosong sudah menutupi direktorinya. Bila masih 200, Volume belum terpasang di
   lintasan yang benar.
7. Unggah satu gambar **sintetis** (bukan foto orang) sebagai gambar blok
   halaman muka.
8. Pastikan gambarnya tampil di halaman muka dan URL `/storage/site/…`-nya
   menjawab 200.
9. Redeploy `smart-sukses` sekali lagi.
10. Pastikan gambar yang **sama** masih tampil dan URL yang sama masih 200.
11. Ganti gambarnya dengan gambar sintetis kedua: yang baru tampil, URL lama
    menjawab 403/404 (berkas lama dibuang sesudah penggantinya tersimpan).
    Hapus bloknya: URL-nya ikut hilang. Logo situs, logo cabang, dan foto
    profil berperilaku sama sejak butir 587.
12. Pastikan penyimpanan privat tidak terpengaruh: terbitkan rapor sintetis,
    unduh dari web, dan pastikan tidak ada berkas privat yang dapat diambil
    lewat `/storage/…` tanpa tanda tangan. Foto siswa sintetis tampil di Data
    Siswa lewat `/admin/siswa/{id}/foto`, **tidak pernah** lewat `/storage/…`,
    dan Volume tidak memuat direktori `student-photos/` maupun `students/`.

Langkah 9–10 yang membuktikannya. Gambar yang baru ditulis ke berkas sistem
sementara pun tampil sesaat setelah diunggah.

**Gambar yang diunggah sebelum Volume terpasang sudah hilang** — lintasannya
masih di basis data, berkasnya tidak. Logo situs, gambar utama, gambar blok,
dan logo cabang perlu **diunggah ulang** sesudah langkah 10. Sampai itu terjadi,
aplikasi tidak menampilkan gambar rusak: logo situs jatuh ke logo bawaan di
`public/images/brand` (ikut image), gambar blok ke penandanya, dan logo cabang
ke nama cabang sebagai teks (butir 587).

### Rollback

Lepaskan Volume dari service lalu redeploy. **Jangan hapus Volume-nya**: isinya
tetap utuh dan dapat dipasang kembali di lintasan yang sama. Selama terlepas,
aplikasi kembali ke perilaku sebelumnya — unggahan baru hilang pada redeploy
berikutnya — tanpa satu pun perubahan kode atau variabel yang perlu dibatalkan.

### Catatan: foto siswa lama

Sebelum butir 587 foto siswa disimpan di disk publik (`students/…`). Baris yang
masih menyimpan jalur lama itu kini diperlakukan sebagai **belum punya foto** —
tidak disajikan, dan tidak dicari di disk publik. Di Railway berkasnya memang
sudah tidak ada: berkas sistem sementara dikosongkan oleh deploy yang membawa
perubahan ini, sebelum Volume mana pun terpasang. Fotonya cukup diunggah ulang
lewat panel, dan akan mendarat di disk privat.

Foto anak sungguhan tetap tidak diunggah ke staging; UAT memakai gambar
sintetis.

---

## 6. Penyimpanan berkas

| Kelas | Disk | Letak | Boleh publik? |
| --- | --- | --- | --- |
| Logo & galeri halaman muka, logo cabang, foto profil | `public` | `storage/app/public` | ya, lewat `storage:link` (§5d) |
| Foto siswa | `local` (`STUDENT_PHOTO_DISK`) | `storage/app/private/student-photos` | **tidak** — hanya lewat rute panel berwenang |
| PDF rapor | `local` (`REPORT_CARD_DISK`) | `storage/app/private` | **tidak** |
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
