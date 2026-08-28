# Uji Beban — Kesiapan dan Prosedur

Batch S9.4.

**Status: PREPARED / NOT YET EXECUTED.**

Skrip, ambang batas, dan prosedurnya siap. Tolok ukur 200 pengguna bersamaan
**belum pernah dijalankan** dan tidak boleh disebut PASS sampai dijalankan pada
server yang menyerupai produksi. Tidak ada staging/VPS yang tersedia saat batch
ini ditulis.

---

## 1. Alat

[k6](https://k6.io) — sumber terbuka, gratis, satu berkas biner.

- **Tidak menuntut Node.** k6 memakai runtime JavaScript-nya sendiri (goja).
  Tidak ada `npm install`, tidak ada `package.json`, tidak ada `node_modules`.
- **Tidak menuntut layanan berbayar.** k6 Cloud tidak dipakai.

k6 **belum terpasang** di mesin pengembang, sehingga skrip di repositori ini
belum pernah dieksekusi. Ia diperiksa secara statis oleh
`tests/Feature/Qa/LoadTestScriptTest.php`, bukan dijalankan.

Pemasangan (Ubuntu, gratis):

```sh
sudo gpg -k
sudo gpg --no-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg \
  --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" \
  | sudo tee /etc/apt/sources.list.d/k6.list
sudo apt-get update && sudo apt-get install k6
```

Windows: `winget install k6` atau `choco install k6`.

## 2. Inventaris skenario

| # | Berkas | Cakupan | Sifat |
| --- | --- | --- | --- |
| A | `ops/load-tests/01-landing.js` | halaman muka publik | baca |
| B | `ops/load-tests/02-ppdb.js` | daftar cabang, formulir, cek status | baca |
| C, D, H | `ops/load-tests/03-student-api.js` | `auth/me`, dasbor, nilai, jadwal siswa | baca (token) |
| E, F | `ops/load-tests/04-cbt-read.js` | daftar ujian dan halaman pengerjaan | baca (sesi) |
| G | `ops/load-tests/05-cbt-autosave.js` | autosave jawaban | **tulis — berpagar** |

Berkas pendukung: `lib/config.js` (konfigurasi, pagar, ambang),
`lib/auth.js` (token dan sesi), `lib/livewire.js` (memanggil metode Livewire).

Skenario C, D, dan H digabung dalam satu skrip karena seorang siswa yang membuka
aplikasinya memang melakukan ketiganya berurutan. Memisahkannya akan mengukur
bentuk lalu lintas yang tidak pernah terjadi.

## 3. Variabel environment

| Variabel | Wajib untuk | Keterangan |
| --- | --- | --- |
| `BASE_URL` | semua | URL dasar sasaran, mis. `https://staging.example`. Tanpa garis miring di ujung. |
| `STAGE` | semua | `smoke` \| `baseline` \| `target`. Bawaan `smoke`. |
| `VUS` | opsional | Menimpa jumlah VU dari `STAGE`. |
| `DURATION` | opsional | Menimpa durasi dari `STAGE`. |
| `SCHOOL_CODE` | B | Kode cabang untuk memuat formulir PPDB, mis. `madani`. |
| `STUDENT_EMAIL_PATTERN` | C, D, H | Pola surel akun fiksur; `{vu}` diganti nomor VU. |
| `STUDENT_PASSWORD` | C, D, H | Kata sandi akun fiksur. **Tidak pernah di-commit.** |
| `API_TOKEN` | opsional | Token Sanctum siap pakai, sebagai ganti menukar kredensial saat `setup()`. |
| `STUDENT_SESSION_COOKIE_PATTERN` | E, F, G | Header `Cookie` sesi web; `{vu}` diganti nomor VU. |
| `EXAM_ID` | F, G | Id ujian fiksur khusus uji. |
| `LOAD_TEST_ALLOW_WRITES` | G | Harus `true`. Tanpa ini skenario tulis menolak berjalan. |

Tidak ada satu pun nilai variabel ini yang tertulis di repositori. Berkas skrip
hanya menyebut namanya.

## 4. Fiksur yang dibutuhkan di staging

Skenario terautentikasi tidak dapat berjalan tanpa data. Yang harus disiapkan
lebih dulu di staging — **bukan** di produksi:

1. **Akun siswa khusus uji**, satu per VU yang direncanakan. Surelnya mengikuti
   `STUDENT_EMAIL_PATTERN`, mis. `loadtest+1@example.test` … `loadtest+200@example.test`.
   Seluruhnya `is_active = true` dan `must_change_password = false`.
2. **Setiap akun tertaut ke satu baris `students`**, dan setiap siswa terdaftar
   di kelas pada tahun ajaran aktif. Tanpa itu portal menampilkan halaman "akun
   belum terhubung" dan yang terukur bukan alur yang dimaksud.
3. **Satu ujian fiksur** berstatus `PUBLISHED`, jendela waktunya sedang terbuka,
   berisi beberapa soal pilihan ganda, dan ditujukan ke kelas para siswa fiksur
   itu. Id-nya menjadi `EXAM_ID`.
4. **Cookie sesi per siswa** untuk skenario E, F, dan G. Cara memperolehnya:
   masuk sebagai siswa fiksur di peramban, salin nilai cookie sesi dari devtools.
   Untuk 200 VU ini tidak praktis dilakukan manual — lihat batasan di §8.

### Mengapa cookie sesi, bukan login dari k6

Alur CBT tidak punya endpoint API sama sekali, dan halaman masuk siswa adalah
komponen Livewire. Mengautentikasi dari k6 berarti menirukan protokol internal
Livewire berikut snapshot ber-checksum-nya. Itu akan mengukur tiruan, bukan
aplikasi, dan akan rusak setiap kali Livewire naik versi. Cookie disuplai dari
luar sebagai gantinya — jujur, dan tercatat sebagai batasan.

Snapshot Livewire untuk skenario tulis **tidak** dikarang: `05-cbt-autosave.js`
memuat halamannya lebih dulu dan mengirimkan kembali snapshot yang baru saja
diterima dari server, persis seperti peramban.

## 5. Pagar untuk skenario tulis

`05-cbt-autosave.js` membuat/melanjutkan percobaan ujian dan menyimpan jawaban.
Menjalankannya terhadap data sungguhan berarti menulis jawaban atas nama siswa
sungguhan pada ujian yang sedang berlangsung. Tidak ada cara membatalkannya.

Dua lapis pagar:

1. **Mati secara bawaan.** Tanpa `LOAD_TEST_ALLOW_WRITES=true` skrip berhenti
   sebelum satu permintaan pun terkirim.
2. **Host produksi ditolak mutlak.** `apps.smartsukses.sch.id` dan
   `smartsukses.sch.id` (berikut subdomainnya) ada di daftar tolak di
   `lib/config.js`. **Tidak ada variabel environment yang dapat membukanya** —
   mengubahnya menuntut menyunting daftar itu, yaitu perubahan kode yang
   terlihat di review, bukan satu baris env yang dapat tersetel tidak sengaja di
   terminal seseorang.

Selain itu, setiap VU memakai sesi siswa yang berbeda. Modelnya satu percobaan
per siswa; bila seluruh VU memakai satu akun, ratusan permintaan akan berebut
satu baris `exam_attempts` dan yang terukur menjadi pertengkaran kunci baris di
MySQL, bukan kapasitas aplikasi.

## 6. Ambang batas

Diambil dari roadmap, dan **tidak dilonggarkan supaya keluarannya hijau**:

| Ukuran | Ambang | Sumber |
| --- | --- | --- |
| p95 halaman web | < 3000 ms | roadmap: "halaman utama < 3 detik" |
| p95 API | < 500 ms | roadmap: "API p95 < 500 ms" |
| Rasio gagal | < 1% | roadmap: "200 bersamaan tanpa error/timeout" |

Kegagalan ambang pada mesin pengembang **bukan** kegagalan produksi. Laragon di
Windows dengan `php artisan serve` berjalan satu proses, tanpa OPcache
terkonfigurasi produksi, tanpa Nginx, dan tanpa PHP-FPM. Setiap eksekusi lokal
ditandai LOCAL dan bersifat diagnostik saja.

## 7. Rencana bertahap

200 VU tidak pernah menjadi langkah pertama. Naik bertahap supaya titik
patahnya terlihat, bukan hanya diketahui bahwa sesuatu patah.

| Tahap | VU | Durasi | Tujuan |
| --- | --- | --- | --- |
| `smoke` | 5 | 30 detik | Membuktikan skripnya benar. Bukan membebani apa pun. |
| `baseline` | 50 | 3 menit | Bentuk kurva pada beban wajar. |
| `target` | 200 | 5 menit | Angka roadmap. |

```sh
# 1. Smoke — selalu dulu.
BASE_URL=https://staging.example STAGE=smoke k6 run ops/load-tests/01-landing.js

# 2. Baseline.
BASE_URL=https://staging.example STAGE=baseline k6 run ops/load-tests/01-landing.js

# 3. Target.
BASE_URL=https://staging.example STAGE=target k6 run ops/load-tests/01-landing.js
```

Skenario tulis, hanya di staging:

```sh
BASE_URL=https://staging.example \
EXAM_ID=12 \
STUDENT_SESSION_COOKIE_PATTERN='smartsukses_session={vu}' \
LOAD_TEST_ALLOW_WRITES=true \
STAGE=smoke \
k6 run ops/load-tests/05-cbt-autosave.js
```

## 8. Lingkungan tolok ukur

Sasaran akhir sesuai roadmap:

- Ubuntu VPS menyerupai produksi, sekitar **2 Core / 2 GB RAM**
- MySQL 8
- Nginx + PHP-FPM
- worker antrean database berjalan

Laptop pengembang (Windows/Laragon) **tidak setara** dan tidak dapat
menggantikannya.

## 9. Metrik yang dikumpulkan saat eksekusi staging

Dari k6:

- jumlah permintaan dan kegagalan
- p50, p95, p99 `http_req_duration`
- throughput (permintaan/detik)
- durasi per skenario lewat tag `name`

Dari server, diambil bersamaan:

- CPU dan RAM (`htop`, `vmstat 1`)
- saturasi PHP-FPM — `pm.max_children` tercapai atau tidak, lihat log
  `server reached pm.max_children`
- tekanan MySQL — `SHOW GLOBAL STATUS LIKE 'Threads_running'`, slow query log
- backlog antrean — jumlah baris `jobs` yang menunggu, dan apakah worker
  tertinggal
- galat Nginx 502/504

Tanpa angka-angka itu, hasil k6 hanya menyebut aplikasinya lambat tanpa
menyebut di mana.

## 10. Yang belum dan tidak boleh diklaim

| Butir | Status |
| --- | --- |
| Skrip k6 tersedia dan berpagar | **SIAP** |
| Ambang roadmap terkodekan | **SIAP** |
| Prosedur bertahap terdokumentasi | **SIAP** |
| Kebutuhan fiksur terdokumentasi | **SIAP** |
| Eksekusi 200 pengguna bersamaan | **PREPARED / NOT YET EXECUTED** |
| Bukti CPU/RAM produksi | **PREPARED / NOT YET EXECUTED** |
| Saturasi PHP-FPM | **PREPARED / NOT YET EXECUTED** |
| Latensi Cloudflare | **PREPARED / NOT YET EXECUTED** |
| Overhead TLS | **PREPARED / NOT YET EXECUTED** |
| Perilaku Nginx di bawah beban | **PREPARED / NOT YET EXECUTED** |

Syarat roadmap "200 pengguna bersamaan" **tidak** dinyatakan terpenuhi.
