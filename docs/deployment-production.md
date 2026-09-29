# Runbook Deployment Produksi

**Dokumen ini sendiri BUKAN bukti kesiapan go-live.** Ia menjelaskan cara
memasang aplikasinya dengan benar. Checklist go-live
(`05-roadmap/03-golive-checklist.md`) menuntut hal-hal yang hanya dapat
dibuktikan **di server** — restore backup yang benar-benar dicoba, uji beban
yang benar-benar dijalankan, dan TLS yang benar-benar aktif. Status terkininya
ada di bagian [Status checklist](#status-checklist) di bawah.

Sasaran: `apps.smartsukses.sch.id` · Ubuntu 22.04 · Nginx · PHP-FPM 8.3 ·
MySQL 8 · Supervisor · Certbot · Cloudflare (Arsitektur 3.3.1).

---

## 1. Yang harus disediakan operator

Tidak satu pun dari ini ada di repository, dan tidak satu pun boleh masuk ke
repository:

| Nilai | Dipakai untuk |
| --- | --- |
| `APP_KEY` | `php artisan key:generate` di server |
| `DB_PASSWORD` | akun MySQL khusus aplikasi (**bukan** root) |
| `SEED_ADMIN_PASSWORD` | kata sandi akun awal; seeding produksi berhenti tanpa ini |
| `MAIL_HOST` / `MAIL_USERNAME` / `MAIL_PASSWORD` | pengiriman surel |
| kata sandi root MySQL | diganti dari bawaan (checklist A.3) |

Salin `.env.production.example` menjadi `.env`, lalu isi. Jangan pernah
menyalin `.env` dari mesin lain apa adanya.

---

## 2. Persiapan sistem

```sh
# PHP 8.3 + ekstensi yang dipakai project ini
sudo apt install php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml \
                 php8.3-curl php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl
```

`gd` dibutuhkan dompdf (PDF rapor); `zip` dan `gd` dibutuhkan maatwebsite/excel
(import/export). `intl` dipakai pemformatan tanggal.

### Berkas konfigurasi server

Repositori menyediakan templatnya supaya tidak ada yang disusun dadakan di
server. Pasang **berurutan** — pool PHP-FPM harus hidup sebelum Nginx merujuk
socket-nya:

| # | Template | Tujuan pemasangan | Untuk |
| --- | --- | --- | --- |
| 1 | `ops/php-smartsukses.ini` | `/etc/php/8.3/fpm/conf.d/99-smartsukses.ini` | batas unggahan & eksekusi |
| 2 | `ops/php-fpm-smartsukses.conf` | `/etc/php/8.3/fpm/pool.d/smartsukses.conf` | jumlah proses pada 2 GB |
| 3 | `ops/nginx-smartsukses.conf` | `/etc/nginx/sites-available/smartsukses` | server block + `client_max_body_size` |
| 4 | `ops/mysql-smartsukses.cnf` | `/etc/mysql/mysql.conf.d/99-smartsukses.cnf` | CON-22 localhost, buffer pool, slow log |
| 5 | `ops/logrotate-smartsukses` | `/etc/logrotate.d/smartsukses` | log tidak memenuhi disk 40 GB |
| 6 | `ops/smartsukses-worker.conf` | `/etc/supervisor/conf.d/` | worker antrean (§6) |
| 7 | `ops/smartsukses-cron` | `crontab -u www-data` | penjadwal + backup (§7, §8) |

Perintah pemasangan lengkap ada sebagai komentar di kepala masing-masing berkas,
termasuk pool bawaan yang harus **dihapus** (`pool.d/www.conf`,
`sites-enabled/default`) — bila dibiarkan, keduanya memakan RAM untuk tidak
melayani apa pun, dan pada 2 GB itu terasa.

**Batas unggahan adalah kegagalan hari pertama yang paling mungkin.** Bawaan
Nginx `client_max_body_size` 1 MB dan bawaan PHP `post_max_size` 8 MB keduanya
lebih kecil daripada yang aplikasi terima: satu pendaftaran PPDB yang sah dapat
memuat 5 dokumen × 2 MB = 10 MB dalam satu permintaan, dan impor Excel menerima
berkas 5 MB. Yang dilihat pengguna adalah 413 tanpa jejak apa pun di log
aplikasi. Angka pada template (`12m` / `12M` / `5M`) diturunkan dari batas yang
sudah berlaku di kode, dan **harus dinaikkan bersama** bila batas itu berubah.

**Bit executable skrip ops.** Ketiga skrip di `ops/` kini tercatat `100755` di
git, sehingga clone ke server langsung dapat dijalankan. Sebelum ini keduanya
tercatat `100644`, dan entri cron yang memanggil `ops/backup-database.sh`
langsung akan gagal "Permission denied" — diam-diam, ke `storage/logs/backup.log`
yang tidak dibaca siapa pun. Bila berkas dipindahkan lewat zip atau rsync yang
membuang mode, pulihkan dengan:

```sh
chmod +x ops/*.sh
ls -l ops/*.sh          # harus -rwxr-xr-x
```

Verifikasi sesudah reload — dari SAPI yang benar, sebab `php -i` membaca
konfigurasi CLI dan bukan FPM:

```sh
sudo nginx -t && sudo php-fpm8.3 -t
sudo mysql -e "SELECT @@bind_address, @@innodb_buffer_pool_size, @@max_connections;"
curl -sI https://apps.smartsukses.sch.id/ | grep -i strict-transport
```

---

## 3. Pemasangan aplikasi

```sh
cd /var/www/smartsukses

composer install --no-dev --optimize-autoloader

cp .env.production.example .env
# isi seluruh placeholder
php artisan key:generate

php artisan migrate --force          # harapan: 34 Ran / 0 pending
php artisan db:seed --force          # berhenti bila SEED_ADMIN_PASSWORD kosong
php artisan storage:link

php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan app:production-check     # WAJIB lolos sebelum situs dibuka
```

Kepemilikan dan izin:

```sh
sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;
```

> `config:cache` membuat `env()` **berhenti bekerja** di luar berkas config.
> Seluruh nilai lingkungan pada project ini sudah dibaca dari config, jadi ini
> aman — tetapi jangan menambahkan `env()` di controller, service, atau
> provider.

---

## 4. Proxy tepercaya

Ini bagian yang paling mudah dipasang setengah jalan, dan akibatnya senyap:
`audit_logs.ip_address` — yang diwajibkan Arsitektur 3.4 — akan berisi alamat
proxy pada **setiap** baris, dan tidak ada yang menyadarinya sampai jejak itu
dibutuhkan.

Rantainya: **Internet → Cloudflare → Nginx → PHP-FPM**.

Laravel hanya melihat Nginx. Karena itu ada **dua** langkah, dan keduanya wajib:

**Langkah 1 — Nginx memulihkan alamat klien dari Cloudflare.**

```nginx
# /etc/nginx/conf.d/cloudflare-realip.conf
# Perbarui daftar ini dari https://www.cloudflare.com/ips/
set_real_ip_from 173.245.48.0/20;
set_real_ip_from 103.21.244.0/22;
# ... seluruh rentang Cloudflare (IPv4 dan IPv6) ...
real_ip_header CF-Connecting-IP;
```

**Langkah 2 — Nginx meneruskan alamat itu ke PHP-FPM.**

```nginx
proxy_set_header Host              $host;
proxy_set_header X-Real-IP         $remote_addr;
proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
proxy_set_header X-Forwarded-Proto $scheme;
```

Lalu di `.env`:

```
TRUSTED_PROXIES=127.0.0.1
```

**Mengapa `127.0.0.1` dan bukan `*`.** Nginx berjalan di mesin yang sama dengan
PHP-FPM, sehingga proxy langsung yang dilihat Laravel selalu localhost. Itu
nilai paling sempit yang benar. `*` berarti "percayai `X-Forwarded-For` dari
siapa pun yang dapat menjangkau PHP-FPM" — aman **hanya** bila PHP-FPM mustahil
dihubungi selain lewat Nginx. Itu asumsi infrastruktur, bukan sifat bawaan, dan
tidak boleh dipakai tanpa memenuhinya:

- PHP-FPM mendengarkan unix socket atau `127.0.0.1` saja — tidak pernah `0.0.0.0`;
- firewall hanya membuka 80/443, dan idealnya hanya dari rentang Cloudflare;
- Nginx **menimpa** `X-Forwarded-For` yang datang dari klien, tidak meneruskannya.

Header yang dipercaya sengaja dipersempit di `config/trustedproxy.php` menjadi
`X-Forwarded-For`, `-Port`, dan `-Proto`. `X-Forwarded-Host` **tidak** dipercaya:
aplikasi ini mengirim tautan atur ulang kata sandi lewat surel, dan tautan itu
dibangun dari host — memercayai header itu berarti mengizinkan host tautan
ditentukan dari luar.

**Verifikasi di server** (tidak dapat dibuktikan dari PHPUnit):

```sh
# Setelah login sekali lewat https, alamatnya harus alamat Anda,
# bukan 127.0.0.1 dan bukan alamat Cloudflare.
php artisan tinker --execute="echo App\Models\AuditLog::latest('id')->value('ip_address');"
```

---

## 5. TLS

```sh
sudo certbot --nginx -d apps.smartsukses.sch.id
```

Nginx harus mengalihkan HTTP → HTTPS dan mengirim HSTS:

```nginx
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
add_header X-Content-Type-Options    "nosniff" always;
add_header Referrer-Policy           "strict-origin-when-cross-origin" always;
```

Header keamanan sengaja berada di Nginx, bukan di middleware aplikasi: ia
batas yang tepat untuk kebijakan seluruh situs, dan menambahkan CSP yang agresif
dari aplikasi berisiko mematahkan Filament/Livewire tanpa peringatan.

Bila memakai Cloudflare, setel mode SSL **Full (strict)** — mode Flexible
membuat Cloudflare berbicara HTTP ke origin, dan cookie `Secure` tidak akan
pernah terkirim.

---

## 6. Antrean (worker wajib)

`GenerateReportCardPdf` dan `GenerateStudentFees` berjalan di antrean. Tanpa
worker keduanya menggantung **tanpa pesan galat** — rapor sekelas akan berstatus
QUEUED selamanya.

Template Supervisor: **`ops/smartsukses-worker.conf`**.

```sh
sudo cp ops/smartsukses-worker.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status smartsukses-worker:*
```

Setelah setiap deploy: `php artisan queue:restart`.

`--timeout` worker (60) wajib lebih kecil daripada `retry_after` antrean (90),
kalau tidak job yang masih berjalan dikerjakan dua kali. Rinciannya di
[`backup-restore.md`](backup-restore.md) bagian 5.

Template ada; **berjalannya di server belum diverifikasi.**

---

## 7. Penjadwal

`notifications:prune` (retensi 90 hari, NOTIF-04) berjalan lewat penjadwal
Laravel dan membutuhkan satu entri cron.

Template: **`ops/smartsukses-cron`** — memuat entri penjadwal **dan** entri
backup harian.

```sh
sudo crontab -u www-data -e
```

**Periksa `timedatectl` lebih dulu.** Jam pada template ditulis untuk server
ber-zona Asia/Jakarta; server ber-zona UTC menuntut penerjemahan.
`APP_TIMEZONE` tidak mengubah zona waktu cron.

Template ada; **terpasangnya di server belum diverifikasi.**

---

## 8. Backup

Skrip: **`ops/backup-database.sh`** — mysqldump, gzip, retensi 30 hari, tanpa
kata sandi di baris perintah. Dijadwalkan 02:00 lewat `ops/smartsukses-cron`.

Pemulihan: **`ops/restore-database.sh`**, dengan pagar yang menolak menimpa
basis data sungguhan kecuali diminta eksplisit.

**Uji pemulihan sungguhan sudah dijalankan** terhadap `smartsukses_test`:
dihancurkan lalu dipulihkan, dan seluruh data kembali utuh. Buktinya di
[`backup-restore.md`](backup-restore.md) bagian 4.

Berkas unggahan: **`ops/backup-storage.sh`** — tar+gzip atas `storage/app/public`
dan `storage/app/private`, retensi 30 hari, dijadwalkan 02:20 lewat
`ops/smartsukses-cron`. Arsipnya diverifikasi (`tar tzf`) sebelum disimpan, dan
arsip yang gagal diverifikasi dihapus alih-alih ditinggalkan sebagai backup palsu.
Direktori `storage/app/private/backups` dikecualikan tanpa syarat — ia tujuan
dump basis data, dan tanpa pengecualian itu setiap arsip berkas akan menggandakan
seluruh dump lama sampai disk 40 GB penuh.

Yang **belum** terbukti, dan karena itu checklist butir 7 tetap PARTIAL: kedua
backup berjalan **terjadwal di server** (keduanya baru diuji dengan dijalankan
tangan), dan **pemulihan** berkas `storage/app/*` belum pernah diuji sama sekali.
Backup basis data saja akan memulihkan baris yang menunjuk berkas yang sudah
tidak ada.

Backup yang gagal tidak memberi tahu siapa pun. Keluaran kedua skrip masuk
`storage/logs/backup.log`; sampai ada pemantauan (§9), satu-satunya yang
menemukan kegagalan adalah orang yang membacanya:

```sh
tail -n 20 /var/www/smartsukses/storage/logs/backup.log
ls -lh /var/www/smartsukses/storage/app/private/backups | tail -5
```

---

## 9. Pemantauan

Endpoint kesehatan sudah tersedia: `GET /up`. Arahkan UptimeRobot atau Better
Stack ke sana. Belum dikonfigurasi.

---

## 10. Rollback

Ditulis lebih dulu, sebab rollback disusun saat panik bila tidak disusun saat
tenang. Urutannya penting: **kode dan skema tidak dapat dibatalkan dengan cara
yang sama.**

**Sebelum rilis apa pun**, dan bukan sesudahnya:

```sh
cd /var/www/smartsukses
ops/backup-database.sh && ops/backup-storage.sh
git rev-parse HEAD > storage/app/private/backups/rilis-sebelumnya.txt
```

Commit yang sedang berjalan harus tercatat di luar kepala seseorang. Tanpa itu,
"kembalikan seperti semula" tidak punya sasaran.

### Kode saja (tidak ada migration pada rilis itu)

```sh
git fetch origin && git checkout <commit-sebelumnya>
composer install --no-dev --optimize-autoloader
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
sudo systemctl reload php8.3-fpm
```

`queue:restart` wajib: worker memuat kode lama ke memori dan akan terus
menjalankannya sampai daur ulang. Reload PHP-FPM wajib bila
`opcache.validate_timestamps=0`.

### Ada migration pada rilis itu

`php artisan migrate:rollback` **hanya** aman bila setiap migration pada rilis itu
memiliki `down()` yang benar. Periksa dulu, jangan andaikan:

```sh
php artisan migrate:status | tail -20
```

Migration yang menghapus kolom atau tabel **tidak dapat** dibatalkan tanpa
kehilangan data — `down()`-nya membuat ulang strukturnya, bukan isinya. Untuk
kasus itu jalurnya adalah pemulihan dari dump, bukan rollback:

```sh
ops/restore-database.sh <dump-sebelum-rilis> smartsukses
```

Pemulihan dump mengembalikan basis data ke keadaan pukul backup — pekerjaan
antara backup dan rollback **hilang**. Itu sebabnya backup diambil sesaat sebelum
rilis dan bukan semalam sebelumnya.

### Batas yang harus diketahui lebih dulu

- **Berkas tidak ikut rollback basis data.** Baris yang dipulihkan dapat menunjuk
  berkas yang sudah ditimpa. Pulihkan arsip `storage` dari waktu yang sama.
- **PITR bergantung pada binlog.** `ops/mysql-smartsukses.cnf` membiarkannya
  aktif dengan retensi 7 hari. Bila binlog dimatikan, satu-satunya titik pulih
  adalah dump harian.
- **Pemulihan berkas belum pernah diuji** (§8). Jangan menyebutnya jalur yang
  terbukti sampai ia dijalankan sekali di staging.

### Sesudah rollback

```sh
php artisan app:production-check
curl -sI https://apps.smartsukses.sch.id/up
tail -n 50 storage/logs/laravel.log
```

---

## Status checklist

Diperbarui setelah S9.5. **Jangan menandai PASS sebelum diverifikasi di server.**

Matriks lengkap berikut buktinya ada di `docs/sprint-9-closure.md` §4; yang di
bawah ini ringkasannya.

| # | Checklist A.3 | Status |
| --- | --- | --- |
| 1 | Unit test isolasi tenant lulus 100% | **PASS** — 107 test isolasi lintas cabang hijau di kedua mesin, plus pagar pintu masuk yang dibaca dari tabel rute |
| 2 | Uji manual lintas cabang | NOT DONE — matriksnya siap sebagai H-05…H-07 & H-09 di `docs/human-qa-handoff.md` |
| 3 | Uji beban 200 pengguna konkuren | NOT DONE / **PREPARED** — skrip k6, ambang, dan rencana bertahap siap; belum pernah dieksekusi |
| 4 | Encoding tautan wa.me | **PASS** — regresi encoding pada PPDB & notifikasi |
| 5 | Format & data PDF rapor | PARTIAL — otomatis lulus; tinjauan format oleh manusia belum (H-13/H-14) |
| 6 | SSL aktif + redirect HTTP→HTTPS | **PARTIAL** — sisi aplikasi siap (APP_URL, cookie Secure, proxy); TLS server belum |
| 7 | Backup otomatis **dan** dapat di-restore | **PARTIAL** — skrip ada, uji pemulihan lokal lolos; backup terjadwal di server & cadangan berkas `storage/app/*` belum |
| 8 | Seluruh kata sandi bawaan diganti | PARTIAL — pagar seeding produksi ada; root MySQL urusan server |
| 9 | CORS dibatasi ke domain | **PARTIAL** — `config/cors.php` ada dan tidak wildcard; verifikasi di server belum |
| 10 | Pemantauan uptime | NOT DONE |

**2 PASS · 5 PARTIAL · 3 NOT DONE.**

Yang berpindah pada S9.2: butir 7 dari NOT DONE menjadi PARTIAL. Yang berpindah
pada S9.4: butir 3 memperoleh keterangan PREPARED — perkakasnya ada, tetapi
statusnya **tetap** NOT DONE sampai dijalankan di server yang menyerupai
produksi. S9.5 tidak memindahkan satu butir pun: seluruh sisanya menuntut server
atau manusia, bukan kode.

Aplikasinya **selesai secara fungsional**; deployment produksinya **belum siap
go-live**. Keduanya hal yang berbeda, dan dokumen ini tidak menyatukannya.
