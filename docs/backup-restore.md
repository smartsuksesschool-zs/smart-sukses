# Backup & Pemulihan

Bagian dari [`deployment-production.md`](deployment-production.md), dipisahkan
karena isinya prosedur yang dijalankan orang di bawah tekanan — ketika ada yang
sudah hilang.

> **Backup yang belum pernah dipulihkan bukan backup.** Dokumen ini memuat bukti
> uji pemulihan yang benar-benar dijalankan, beserta batas apa yang belum
> terbukti.

---

## 1. Apa yang dicadangkan

| | Berkas | Sudah otomatis? |
| --- | --- | --- |
| Basis data MySQL | `ops/backup-database.sh` | ya (lewat cron) |
| `storage/app/public` — logo cabang, bukti pembayaran | **belum** | tidak |
| `storage/app/private` — PDF rapor | **belum** | tidak |

Ini yang paling sering terlewat: **backup basis data saja tidak cukup.** Baris
`payments.proof_path` dan `report_cards.pdf_path` menunjuk berkas di disk. Basis
data yang dipulihkan tanpa berkasnya menghasilkan sistem yang tampak utuh
sampai seseorang menekan "Unduh Bukti" (butir 367).

Untuk Phase 1, unggahan dicadangkan bersama backup sistem berkas VPS, atau
manual:

```sh
tar czf storage-$(date +%Y%m%d).tar.gz storage/app/public storage/app/private
```

Backblaze B2 tetap opsional/Phase 2 sesuai `02-infrastructure-costs.md`.

---

## 2. Backup

```sh
ops/backup-database.sh [direktori-tujuan]
```

| | |
| --- | --- |
| Tujuan bawaan | `storage/app/private/backups` |
| Nama berkas | `smartsukses-<database>-YYYYMMDD-HHMMSS.sql.gz` |
| Retensi | 30 hari (`BACKUP_KEEP_DAYS`) |
| Jadwal | 02:00 harian lewat cron — lihat `ops/smartsukses-cron` |

**Kredensial.** Dibaca dari `.env` aplikasi, lalu dilewatkan ke `mysqldump`
lewat berkas opsi sementara ber-mode 600 yang dihapus saat skrip berakhir —
termasuk saat gagal. Kata sandi **tidak pernah** menjadi argumen baris perintah:
argumen terlihat seluruh pengguna server lewat `ps`.

`--single-transaction` membuat dump konsisten tanpa mengunci tabel InnoDB,
sehingga situs tetap melayani selama backup berjalan.

**Retensi** hanya menghapus berkas di direktori backup itu sendiri
(`-maxdepth 1`), bertipe berkas biasa, dan bernama `smartsukses-*.sql*`. Tidak
ada penghapusan rekursif di dalam skrip ini.

---

## 3. Pemulihan

```sh
ops/restore-database.sh <berkas-dump> <database-tujuan>
```

Skrip **menolak** sasaran yang tidak berakhiran `_test`, `_testing`, `_restore`,
atau `_drill`:

```
restore: menolak menimpa 'smartsukses'.
```

Memulihkan basis data sungguhan menuntut persetujuan eksplisit:

```sh
ALLOW_PRODUCTION_RESTORE=yes ops/restore-database.sh <dump> smartsukses
```

Pagarnya ada karena satu salah ketik pada argumen kedua akan menghapus basis
data yang sedang dipakai. Jangan hapus pagar itu (butir 372).

### Prosedur pemulihan produksi

1. Hentikan worker: `sudo supervisorctl stop smartsukses-worker:*`
2. Nyalakan mode pemeliharaan: `php artisan down`
3. **Backup dulu keadaan sekarang** — walaupun rusak, ia satu-satunya salinan
   dari beberapa jam terakhir.
4. `ALLOW_PRODUCTION_RESTORE=yes ops/restore-database.sh <dump> smartsukses`
5. `php artisan migrate:status` → harus 34 Ran / 0 pending
6. Pulihkan `storage/app/*` dari arsipnya
7. `php artisan config:cache && php artisan queue:restart`
8. `php artisan up`
9. Nyalakan worker kembali

---

## 4. Uji pemulihan — dijalankan 28 Agustus 2026

Dijalankan sungguhan di mesin pengembangan terhadap **`smartsukses_test`**.
Basis data pengembangan `smartsukses` tidak disentuh sama sekali.

**Yang dibuktikan:**

| Langkah | Hasil |
| --- | --- |
| 1. `smartsukses_test` diisi data dua cabang | 39 tabel · 34 migrasi · 3 sekolah · 2 siswa · 2 nilai · 2 ujian · 4 pilihan jawaban |
| 2. `ops/backup-database.sh` | dump 8.761 byte terkompresi |
| 3. Pagar pemulihan diuji terhadap `smartsukses` | **ditolak**, keluar dengan kode 77; `smartsukses` tetap 39 tabel |
| 4. `smartsukses_test` **benar-benar dihancurkan** | DROP DATABASE → 0 tabel |
| 5. `ops/restore-database.sh` | selesai tanpa galat |
| 6. Verifikasi | **identik dengan langkah 1** |

Data yang diperiksa satu per satu setelah pemulihan:

- jumlah tabel kembali **39**, `migrations` **34 Ran / 0 pending**;
- kedua cabang kembali, beserta siswanya;
- nilai bertahan **persis**: 87,50 dan 65,25 — termasuk desimalnya;
- keterkaitan relasional bertahan: nilai → siswa → cabang tetap tersambung
  benar, dan tidak tertukar antar cabang;
- isi CBT bertahan, termasuk bobot soal 2,50 dan penanda kunci jawaban.

**Yang uji ini TIDAK buktikan, dan tidak boleh diklaim:**

- backup terjadwal benar-benar berjalan di server (cron belum terpasang);
- pemulihan berkas `storage/app/*` (belum diuji sama sekali);
- pemulihan basis data berukuran produksi;
- pemulihan pada MySQL 8 di Ubuntu — uji ini berjalan di MySQL 8.4.3 Windows.

Karena itu checklist go-live butir 7 tetap **PARTIAL**, bukan PASS.

---

## 5. Worker antrean

Template: `ops/smartsukses-worker.conf`

```sh
sudo cp ops/smartsukses-worker.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status smartsukses-worker:*
```

Setelah setiap deploy:

```sh
php artisan queue:restart
```

Worker memuat kode ke memori saat mulai; tanpa `queue:restart` ia terus
menjalankan kode versi lama sampai `--max-time` habis.

**Satu batas yang tidak boleh dilanggar:** `--timeout` worker harus lebih kecil
daripada `retry_after` antrean (`config/queue.php`, bawaan 90 detik). Bila
terbalik, job yang **masih berjalan** akan diantrekan ulang dan dikerjakan dua
kali — pada penerbitan tagihan massal itu berarti tagihan ganda. Nilai
sekarang: timeout 60 < retry_after 90 (butir 368).

Bila kelak ada job yang butuh lebih dari 60 detik, naikkan
`DB_QUEUE_RETRY_AFTER` lebih dulu, baru `--timeout`.

Job yang gagal:

```sh
php artisan queue:failed
php artisan queue:retry <uuid>
php artisan queue:flush
```

---

## 6. Penjadwal

Template: `ops/smartsukses-cron`

```sh
sudo crontab -u www-data -e
```

**Periksa zona waktu server lebih dulu:**

```sh
timedatectl
```

Jam pada template ditulis dengan asumsi server ber-zona Asia/Jakarta. Bila
server memakai UTC — bawaan banyak penyedia VPS — 02:00 WIB adalah **19:00 UTC
hari sebelumnya**. `APP_TIMEZONE=Asia/Jakarta` **tidak** mengubah zona waktu
cron; ia hanya mengubah zona waktu PHP (butir 369).

Verifikasi jadwal aplikasi:

```sh
php artisan schedule:list
```

Saat ini satu tugas: `notifications:prune` pukul 03:10 (retensi 90 hari,
NOTIF-04). `schedule:list` membuktikan tugasnya **terdaftar**, bukan bahwa cron
**menjalankannya** — itu fakta server.
