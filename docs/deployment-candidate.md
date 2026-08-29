# Kandidat Rilis — Daftar Urutan Deployment

Batch S9.5.

**Status: BELUM DIJALANKAN. Tidak ada satu pun langkah di sini yang sudah
dieksekusi.**

Dokumen ini **bukan** pengganti `docs/deployment-production.md`. Runbook itu
menjelaskan *bagaimana* setiap langkah dikerjakan, lengkap dengan perintahnya.
Yang ada di sini adalah *urutan dan gerbangnya*: apa yang harus benar sebelum
langkah berikutnya boleh dimulai, dan di titik mana pekerjaan harus berhenti
untuk menunggu keputusan pemilik.

Rujukan bagian ditulis sebagai `runbook §N`.

---

## Gerbang 0 — sebelum apa pun dibeli

**Berhenti di sini sampai Pak Akbar menjawab.** Seluruh butir di bawah menuntut
uang atau kepemilikan, dan tidak satu pun boleh diputuskan sendiri oleh
pengembang.

| | Butir | Perlu biaya? | Keterangan |
| --- | --- | --- | --- |
| P-01 | VPS produksi (Ubuntu, ±2 Core / 2 GB) | **Ya** | Spesifikasi minimum roadmap. Belum ada. |
| P-02 | Domain `smartsukses.sch.id` | **Mungkin** | Perlu dipastikan sudah dimiliki atau belum. |
| P-03 | SMTP | **Mungkin** | Opsi gratis ada tetapi berkuota. Aplikasi Phase 1 **belum mengirim surel sama sekali**, jadi ini belum menghalangi rilis — lihat catatan di bawah. |
| P-04 | VPS staging terpisah | **Ya** | Hanya bila uji beban tidak boleh menyentuh produksi. Sangat disarankan. |
| P-05 | Peningkatan penyimpanan | **Ya** | Bergantung jumlah lampiran bukti bayar dan PDF rapor. |
| P-06 | WhatsApp API (Fonnte dsb.) | **Ya** | **Phase 2.** Phase 1 memakai tautan wa.me manual dan tidak menuntut ini. |
| P-07 | Payment gateway | **Ya** | **Phase 2.** Tidak dipakai Phase 1. |

Catatan P-03: `php artisan production-check` menandai `MAIL_MAILER=log` sebagai
gagal, dan itu benar sebagai kesiapan konfigurasi. Tetapi tidak ada satu pun
Mailable, notifikasi kanal mail, maupun pemanggilan facade `Mail` di dalam
aplikasi — jadi tidak ada surel yang gagal terkirim hari ini. SMTP menjadi wajib
saat pemulihan kata sandi lewat surel dipakai sungguhan.

---

## PRE-DEPLOY

Dikerjakan setelah Gerbang 0 dijawab.

| | Langkah | Rujukan | Selesai bila |
| --- | --- | --- | --- |
| D-01 | VPS aktif, akses SSH kunci publik, `root` dimatikan untuk login langsung | runbook §2 | Dapat masuk sebagai pengguna non-root |
| D-02 | DNS `apps.smartsukses.sch.id` mengarah ke VPS | — | `dig` mengembalikan alamat VPS |
| D-03 | Cloudflare: proxy menyala, mode TLS **Full (strict)** | runbook §4, §5 | Bukan "Flexible" — Flexible membuat lalu lintas Cloudflare→server tetap polos |
| D-04 | Basis data produksi + pengguna khusus aplikasi dibuat | runbook §2 | Bukan `root`, dan haknya hanya pada satu skema |
| D-05 | Rahasia disiapkan **di luar repositori**: `DB_PASSWORD`, `SEED_ADMIN_PASSWORD`, kredensial SMTP bila ada | runbook §3 | Tidak satu pun tertulis di git, tiket, atau chat |
| D-06 | Kata sandi `root` MySQL diganti dari bawaan | — | Hanya dapat diverifikasi di server |

---

## DEPLOY

| | Langkah | Rujukan | Selesai bila |
| --- | --- | --- | --- |
| D-07 | Paket sistem: PHP 8.3 + ekstensi, Nginx, MySQL 8, Supervisor, Certbot | runbook §2 | `php -m` memuat seluruh ekstensi yang disebut runbook |
| D-08 | Clone repositori pada rilis yang ditentukan | runbook §3 | `git rev-parse HEAD` cocok dengan commit kandidat |
| D-09 | `composer install --no-dev --optimize-autoloader` | runbook §3 | Tanpa galat; `vendor/` terisi |
| D-10 | `.env` diisi dari `.env.production.example` | runbook §3 | Tidak ada placeholder tersisa |
| D-11 | `php artisan key:generate` **di server** | runbook §3 | `APP_KEY` terisi dan **tidak** disalin dari mesin lain |
| D-12 | Kepemilikan dan izin `storage/` serta `bootstrap/cache/` | runbook §3 | Pengguna web dapat menulis, orang lain tidak |
| D-13 | `php artisan storage:link` | runbook §3 | `public/storage` menjadi symlink |
| D-14 | `php artisan migrate --force` | runbook §3 | 34 migrasi Ran, 0 pending |
| D-15 | Seed peran dan admin awal dengan `SEED_ADMIN_PASSWORD` terisi | runbook §3 | Seeder menolak berjalan bila variabel itu kosong — itu memang pagarnya |
| D-16 | `config:cache`, `route:cache`, `view:cache` | runbook §3 | Ketiganya sukses |
| D-17 | Nginx: server block, root ke `public/`, header keamanan | runbook §3, §5 | `nginx -t` lolos |
| D-18 | PHP-FPM: pool, `pm.max_children` disetel sesuai RAM | runbook §2 | Layanan berjalan |
| D-19 | Supervisor: worker antrean, `--timeout` di bawah `retry_after` | runbook §6 | `supervisorctl status` RUNNING |
| D-20 | Cron: penjadwal Laravel + backup harian | runbook §7, §8 | `crontab -l` memuat keduanya |
| D-21 | Certbot: sertifikat terbit, pembaruan otomatis aktif | runbook §5 | `certbot renew --dry-run` lolos |

Landing tidak menuntut build Vite baru. Aset yang dipakainya sudah ada di
repositori; tidak ada langkah `npm` di daftar ini, dan itu disengaja.

---

## POST-DEPLOY

Tidak ada rilis yang diumumkan sebelum seluruh baris di bawah terisi.

| | Langkah | Rujukan | Selesai bila |
| --- | --- | --- | --- |
| D-22 | `php artisan production-check` | runbook §3 | **13 dari 13** hijau |
| D-23 | Halaman muka, `/ppdb`, `/admin/login`, `/siswa/masuk`, `/portal/masuk` terbuka | — | Kelimanya 200 |
| D-24 | `http://` dialihkan ke `https://`, HSTS terkirim | runbook §5 | Header `Strict-Transport-Security` ada |
| D-25 | Alamat IP klien benar di `audit_logs.ip_address` | runbook §4 | Setelah satu kali login lewat https, alamatnya alamat penguji — bukan `127.0.0.1`, bukan alamat Cloudflare |
| D-26 | Worker antrean benar-benar mengerjakan job | runbook §6 | Generate PDF satu kelas → status QUEUED berpindah ke READY |
| D-27 | Penjadwal berjalan | runbook §7 | `schedule:list` cocok, dan pemangkasan notifikasi tercatat |
| D-28 | SMTP — **hanya bila P-03 diadakan** | runbook §3 | Surel uji sampai |
| D-29 | Backup harian menghasilkan berkas | runbook §8, `docs/backup-restore.md` | Ada `.sql.gz` baru keesokan harinya |
| D-30 | **Restore diuji ke basis data terpisah** | `docs/backup-restore.md` | Dump produksi dipulihkan ke skema uji dan jumlah barisnya cocok. Backup yang belum pernah dipulihkan bukan backup |
| D-31 | Cadangan berkas `storage/app/*` | — | **Belum ada otomatisasinya.** Bukti bayar dan PDF rapor tidak ikut dump basis data |
| D-32 | QA manusia | `docs/human-qa-handoff.md` | H-01…H-20 terisi, dan H-05…H-07 serta H-09 **LULUS** |
| D-33 | QA responsif | `docs/responsive-qa.md` | R-01…R-41 terisi |
| D-34 | Uji beban `smoke` (5 VU) | `docs/load-testing.md` | Skripnya berjalan tanpa galat. Ini membuktikan skrip, bukan kapasitas |
| D-35 | Uji beban `baseline` (50 VU) | `docs/load-testing.md` | Ambang terpenuhi, metrik server ikut dicatat |
| D-36 | Uji beban `target` (200 VU) | `docs/load-testing.md` | Ambang roadmap terpenuhi. **Hanya setelah ini** butir 3 checklist go-live boleh berpindah dari NOT DONE |
| D-37 | Pemantauan uptime terpasang | runbook §9 | Ada pemberitahuan saat layanan mati |

---

## Yang tetap tidak boleh dilakukan

- Menjalankan `ops/load-tests/05-cbt-autosave.js` terhadap produksi. Skenario
  itu menulis jawaban ujian, dan host produksi ditolak di dalam kode — bukan
  lewat variabel environment yang dapat tersetel tidak sengaja.
- Menjalankan uji beban terhadap ujian yang sedang dikerjakan siswa sungguhan.
- Menjalankan `Sprint4DemoSeeder` di produksi. Ia sengaja tidak terdaftar di
  `DatabaseSeeder`.
- Memperbaiki temuan QA langsung di server. Perbaikannya kembali ke repositori,
  lalu kandidat berikutnya dirilis ulang.
