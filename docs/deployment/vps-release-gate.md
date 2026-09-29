# Release Gate VPS

Sasaran penempatan: **satu VPS 2 Core / 2 GB / 40 GB, Ubuntu 22.04 LTS**
(CON-20, CON-21). Dokumen ini memisahkan apa yang sudah terbukti dari apa yang
baru siap dipasang — dan Railway diperlakukan sebagai lingkungan transisional,
bukan sasaran akhir.

## Kosakata status

Tiga status saja, dan perbedaannya penting:

| Status | Artinya |
| --- | --- |
| `TERBUKTI` | Ada test otomatis yang menegaskan kontraknya, dan test itu hijau di kedua mesin basis data |
| `SIAP · BELUM TERBUKTI DI SERVER` | Templat/skrip ada, kontrak sisi aplikasi sudah dijaga test, tetapi hanya server yang dapat membuktikan sisanya |
| `BELUM ADA` | Belum ada artefaknya |

**Keberadaan berkas templat tidak pernah cukup untuk `TERBUKTI`.** Itu sebabnya
`ops/nginx-smartsukses.conf` yang ada di repositori tetap berstatus `SIAP` — yang
diuji baru **hubungan angka di dalamnya**, bukan bahwa Nginx di server benar-benar
memuatnya.

---

## A · VPS MUST-PASS

| # | Gate | Templat/berkas | Test | Verifikasi manual di server | Status |
| --- | --- | --- | --- | --- | --- |
| A-01 | Ubuntu 22.04 sebagai sasaran | `docs/deployment-production.md` §1-2 | — | `lsb_release -a` | SIAP · BELUM TERBUKTI DI SERVER |
| A-02 | Nginx server block | `ops/nginx-smartsukses.conf` | `BackupArtifactsTest::test_the_operational_artifacts_exist` | `sudo nginx -t && systemctl status nginx` | SIAP · BELUM TERBUKTI DI SERVER |
| A-03 | PHP 8.3 + ekstensi | `docs/deployment-production.md` §2 (gd, zip, intl, bcmath, mbstring, xml, curl, mysql) | — | `php -m` dan `php -v` | SIAP · BELUM TERBUKTI DI SERVER |
| A-04 | PHP-FPM pool bersizing 2 GB | `ops/php-fpm-smartsukses.conf` (`pm.max_children = 8`, anggaran RAM diuraikan) | artefak dijaga test | `sudo php-fpm8.3 -t`; ukur `ps -o rss= -C php-fpm8.3` | SIAP · BELUM TERBUKTI DI SERVER |
| A-05 | MySQL 8 + CON-22 localhost | `ops/mysql-smartsukses.cnf` | `BackupArtifactsTest::test_the_mysql_template_binds_to_localhost_only` | `mysql -e "SELECT @@bind_address, @@innodb_buffer_pool_size, @@log_bin;"` | SIAP · BELUM TERBUKTI DI SERVER |
| A-06 | Supervisor queue worker | `ops/smartsukses-worker.conf` | `BackupArtifactsTest` — worker memakai queue `database`, timeout di bawah `retry_after`, retry milik job bukan worker | `supervisorctl status smartsukses-worker` | SIAP · BELUM TERBUKTI DI SERVER |
| A-07 | Scheduler cron | `ops/smartsukses-cron` | `BackupArtifactsTest` — cron tiap menit, tidak menggandakan tugas, memperingatkan zona waktu server; jadwal aplikasi tepat memuat `notifications:prune` | `crontab -u www-data -l`; amati `notifications:prune` berjalan 03:10 | SIAP · BELUM TERBUKTI DI SERVER |
| A-08 | Certbot / sertifikat | `docs/deployment-production.md` §5 | — | `certbot certificates`; uji perpanjangan `certbot renew --dry-run` | BELUM ADA (di server) |
| A-09 | HTTPS melayani | `ops/nginx-smartsukses.conf` + certbot | sisi aplikasi: `ProductionConfigTest` (cookie `Secure`) | `curl -sI https://<domain>/` | BELUM ADA (di server) |
| A-10 | Pengalihan HTTP → HTTPS | disisipkan certbot ke server block | — | `curl -sI http://<domain>/` harus 301 ke https | BELUM ADA (di server) |
| A-11 | HSTS | `ops/nginx-smartsukses.conf` (`max-age=31536000; includeSubDomains`) | — | `curl -sI https://<domain>/ \| grep -i strict-transport` | SIAP · BELUM TERBUKTI DI SERVER |
| A-12 | CORS dibatasi | `config/cors.php` (milik repo) | **`ProductionConfigTest`** — bukan wildcard, `api/*` saja, credentials mati, origin asing tidak pernah disebut, origin tak terdaftar tidak memperoleh izin | `curl -H 'Origin: https://jahat.test' -I https://<domain>/api/v1/...` | **TERBUKTI** (sisi aplikasi) |
| A-13 | Batas unggah berkas | `ops/nginx-smartsukses.conf` + `ops/php-smartsukses.ini` | **`BackupArtifactsTest::test_the_upload_limits_of_nginx_and_php_stay_consistent`** dan `test_nginx_waits_longer_than_php_executes`; sisi aplikasi `StudentPhotoAccessTest` (2 MB ditolak, WEBP diterima, PDF ditolak) | unggah dokumen PPDB 5 × 2 MB di server; harus tidak 413 | **TERBUKTI** (kontrak angka) · server belum |
| A-14 | Izin direktori storage | `docs/deployment-production.md` §3 (`chmod 775` pada `storage`, `bootstrap/cache`) | — | `sudo -u www-data test -w storage/logs && echo ok` | SIAP · BELUM TERBUKTI DI SERVER |
| A-15 | Queue worker benar-benar memproses | `ops/smartsukses-worker.conf` | **`BackupArtifactsTest`** — tabel queue ada, queue database menerima dan menahan job | terbitkan satu rapor; `pdf_status` harus berpindah dari QUEUED | **TERBUKTI** (kontrak) · server belum |
| A-16 | Backup basis data | `ops/backup-database.sh` | **`BackupArtifactsTest`** — tanpa kredensial di `ps`, berkas opsi 600 dan selalu dihapus, retensi 30 hari, penghapusan tidak dapat keluar dari direktori backup | jalankan sekali di server; periksa `storage/logs/backup.log` | **TERBUKTI** (kontrak) · server belum |
| A-17 | Backup berkas unggahan | `ops/backup-storage.sh` | **`BackupArtifactsTest::test_the_storage_backup_never_archives_the_backup_directory`**; diuji dijalankan sungguhan pada dev (kedua cabang exclude, arsip rusak ditolak, retensi) | jalankan sekali di server; periksa ukuran arsip tidak tumbuh berlipat | **TERBUKTI** (kontrak) · server belum |
| A-18 | Pemulihan basis data | `ops/restore-database.sh` | **`BackupArtifactsTest`** — menolak sasaran produksi secara bawaan, tidak pernah memakai `migrate:fresh` | latihan pemulihan ke basis data `_restore` di server | **TERBUKTI** (kontrak); latihan lokal ke `smartsukses_test` sudah lolos |
| A-19 | Pemulihan berkas unggahan | — | — | latihan: hapus satu berkas, pulihkan dari arsip, buka dari panel | **BELUM ADA** — belum pernah diuji sama sekali |
| A-20 | Logrotate | `ops/logrotate-smartsukses` (`copytruncate`, `maxsize 100M`, reload FPM, flush slow log) | artefak dijaga test | `sudo logrotate --debug /etc/logrotate.d/smartsukses` | SIAP · BELUM TERBUKTI DI SERVER |
| A-21 | Prosedur rollback | `docs/deployment-production.md` §10 (kode saja, dengan migration, batas, sesudahnya) | — | latihan rollback di staging | SIAP · BELUM TERBUKTI DI SERVER |
| A-22 | Pemantauan | — | — | arahkan UptimeRobot/Better Stack ke `/up` | **BELUM ADA** |
| A-23 | Endpoint kesehatan | `bootstrap/app.php` (`health: '/up'`) | `StagingReadinessTest` menekan `/up` | `curl -s https://<domain>/up` | **TERBUKTI** (sisi aplikasi) |
| A-24 | Dokumen PPDB privat | `config/storage.php`, `app/Support/PpdbDocument.php` | **`Ppdb/PpdbDocumentAccessTest`** — tamu tidak dapat mengunduh, jalur ber-`..` ditolak, berkas lama tetap terlayani | jalankan `ppdb:privatize-documents` (lihat D-03) | **TERBUKTI** (kode); pemindahan berkas lama belum |
| A-25 | Foto & dokumen siswa privat | `app/Support/StudentPhoto.php`, `StudentPhotoController` | **`MasterData/StudentPhotoAccessTest`** (19 test) — tamu 302 ke login, guru hanya kelas ajarnya, jalur publik lama tidak pernah disajikan | `curl -sI https://<domain>/storage/student-photos/...` harus 403/404 | **TERBUKTI** |
| A-26 | APP_KEY & env produksi | `.env.production.example` | **`ProductionConfigTest`** (templat menyalakan cookie `Secure`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://`, tanpa rahasia), `ProductionCheckCommandTest`, `StagingReadinessTest` | `php artisan app:production-check` di server | **TERBUKTI** (kontrak templat) |
| A-27 | Optimasi cache/config produksi | `docs/deployment-production.md` §3 dan §10 | — | `php artisan config:cache route:cache view:cache`; reload FPM bila `opcache.validate_timestamps=0` | SIAP · BELUM TERBUKTI DI SERVER |

### Rekap kategori A

| Status | Jumlah |
| --- | --- |
| `TERBUKTI` (kontrak sisi aplikasi terbukti test) | 9 |
| `SIAP · BELUM TERBUKTI DI SERVER` | 14 |
| `BELUM ADA` | 4 (A-08, A-09/A-10 di server, A-19, A-22) |

Tidak satu pun dari 27 gate ini berstatus "selesai seluruhnya", sebab tidak satu
pun sudah dijalankan pada VPS sasaran. **Selama itu belum terjadi, istilah
"production ready" tidak dipakai di dokumen ini.**

---

## B · Railway — `TRANSITIONAL / NON-TARGET-INFRA`

Railway adalah tempat berjalannya UAT sekarang, **bukan** sasaran akhir. Tidak ada
requirement di `docs/blueprint/` yang menyebut Railway; CON-20 justru menyebut
satu VPS. Karena itu ketiga hal di bawah **tidak** diperbaiki dan **tidak**
dihitung sebagai gap VPS:

| # | Hal | Akibat di Railway | Di VPS |
| --- | --- | --- | --- |
| B-01 | Tidak ada scheduler | `notifications:prune` tidak berjalan; retensi 90 hari (NOTIF-04) tidak ditegakkan | Teratasi oleh `ops/smartsukses-cron` (A-07) |
| B-02 | `healthcheckPath` NULL pada web dan worker | Deploy yang gagal tidak tertahan apa pun; tidak ada jaring pengaman | Teratasi oleh `/up` + pemantauan (A-22, A-23) |
| B-03 | MySQL berjalan `--disable-log-bin` | Tidak ada point-in-time recovery; titik pulih hanya dump harian | `ops/mysql-smartsukses.cnf` membiarkan binlog aktif dengan retensi 7 hari, dan test menjaganya (A-05) |

Ketiganya baru menjadi pekerjaan bila pemilik menetapkan Railway sebagai sasaran
produksi final. Belum ada requirement semacam itu.

---

## C · Keputusan pemilik yang menyentuh penempatan

Tidak diputuskan di sini. Lengkapnya di
[`../requirements/owner-decisions.md`](../requirements/owner-decisions.md).

| # | Butir | Menahan gate |
| --- | --- | --- |
| C-01 | OD-10 — cara pengiriman sandi sementara | Menentukan apakah SMTP wajib disediakan (A-08 tidak, tetapi AUTH-04 ya) |
| C-02 | OD-16 — status `06-API.md` | Menentukan apakah ada permukaan API tambahan yang perlu dipagari di Nginx |
| C-03 | OD-05 — semantik YEARLY/ONCE | Bukan gate infrastruktur, tetapi **berpotensi memblokir rilis**; menuntut satu kueri read-only di produksi |
| C-04 | Apakah Railway menjadi sasaran final | Menentukan apakah B-01…B-03 menjadi pekerjaan |

---

## D · Langkah operasional produksi

Bukan koding, dan bukan pula gate infrastruktur: langkah yang dijalankan manusia
pada saat rilis, dengan urutan yang tidak boleh ditukar.

| # | Langkah | Prasyarat | Catatan |
| --- | --- | --- | --- |
| D-01 | Backup segar terverifikasi sebelum rilis | `ops/backup-database.sh` **dan** `ops/backup-storage.sh` | Ini yang menahan rilis sejak preflight sebelumnya. Rollback basis data tanpa backup segar berarti kehilangan pekerjaan sejak backup terakhir |
| D-02 | `php artisan migrate --force` | D-01 selesai | Periksa `migrate:status` lebih dulu; migration yang membuang kolom tidak dapat dibatalkan tanpa kehilangan data |
| D-03 | `ppdb:privatize-documents --apply` | D-01 selesai; simulasi dijalankan dan laporannya dibaca | Satu-satunya langkah yang **menghapus** sesuatu. Tidak ada tombol balik — lihat `docs/ppdb-document-storage.md` §6 |
| D-04 | Setel env disk privat | — | `STUDENT_PHOTO_DISK`, `REPORT_CARD_DISK`, dan kerabatnya; lihat `docs/deployment/staging-uat.md` §5c |
| D-05 | `php artisan app:production-check` | seluruh env terpasang | Harus lulus sebelum dinyatakan siap dilayani |
| D-06 | Kueri read-only `fee_types` | — | Menjawab OD-05; lihat C-03 |
| D-07 | Latihan pemulihan berkas | arsip `ops/backup-storage.sh` ada | Menutup A-19, yang kini satu-satunya jalur pemulihan yang belum pernah diuji |
| D-08 | Uji beban 200 pengguna | server menyerupai produksi | Lima skrip k6 siap di `ops/load-tests/` |
