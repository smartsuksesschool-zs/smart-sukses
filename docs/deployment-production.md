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

Artefak Supervisor dibuat pada batch berikutnya (S9.2) bersama backup dan cron
penjadwal. Sampai itu ada, worker harus dijalankan manual dan status ini tetap
**belum selesai**.

---

## 7. Penjadwal

`notifications:prune` (retensi 90 hari, NOTIF-04) berjalan lewat penjadwal
Laravel dan membutuhkan satu entri cron:

```
* * * * * cd /var/www/smartsukses && php artisan schedule:run >> /dev/null 2>&1
```

Belum dikonfigurasi. Termasuk lingkup S9.2.

---

## 8. Backup

**Belum ada sama sekali.** Arsitektur 3.4 menuntut mysqldump harian pukul 02:00
WIB dengan retensi 30 hari, dan checklist menuntut restore yang **terbukti**.

Yang harus ikut terbackup, dan sering terlewat: `storage/app/public` (logo
cabang, bukti pembayaran) dan `storage/app/private` (PDF rapor). Backup basis
data saja akan memulihkan baris yang menunjuk berkas yang sudah tidak ada.

Termasuk lingkup S9.2.

---

## 9. Pemantauan

Endpoint kesehatan sudah tersedia: `GET /up`. Arahkan UptimeRobot atau Better
Stack ke sana. Belum dikonfigurasi.

---

## Status checklist

Diperbarui setelah S9.1. **Jangan menandai PASS sebelum diverifikasi di server.**

| # | Checklist A.3 | Status |
| --- | --- | --- |
| 1 | Unit test isolasi tenant lulus 100% | **PASS** — 2291 test hijau di kedua mesin |
| 2 | Uji manual lintas cabang | NOT DONE |
| 3 | Uji beban 200 pengguna konkuren | NOT DONE |
| 4 | Encoding tautan wa.me | **PASS** — regresi encoding pada PPDB & notifikasi |
| 5 | Format & data PDF rapor | PARTIAL — otomatis lulus; tinjauan format oleh manusia belum |
| 6 | SSL aktif + redirect HTTP→HTTPS | **PARTIAL** — sisi aplikasi siap (APP_URL, cookie Secure, proxy); TLS server belum |
| 7 | Backup otomatis **dan** dapat di-restore | NOT DONE |
| 8 | Seluruh kata sandi bawaan diganti | PARTIAL — pagar seeding produksi ada; root MySQL urusan server |
| 9 | CORS dibatasi ke domain | **PARTIAL** — `config/cors.php` ada dan tidak wildcard; verifikasi di server belum |
| 10 | Pemantauan uptime | NOT DONE |

**2 PASS · 4 PARTIAL · 4 NOT DONE.**

Aplikasinya **selesai secara fungsional**; deployment produksinya **belum siap
go-live**. Keduanya hal yang berbeda, dan dokumen ini tidak menyatukannya.
