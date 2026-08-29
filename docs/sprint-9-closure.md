# Sprint 9 — Penutupan Sisi Repositori

Batch S9.1 – S9.5.

> **SPRINT 9 REPOSITORY WORK COMPLETE — READY FOR DEPLOYMENT CANDIDATE**
>
> Ini **bukan** "GO-LIVE COMPLETE", bukan "PRODUCTION READY", dan bukan
> "ALL CHECKLIST PASSED". Tiga dari sepuluh butir checklist go-live menuntut
> server yang belum ada, dan dua lagi menuntut manusia yang belum menjalankannya.

---

## 1. Apa yang Sprint 9 kerjakan

Sprint 9 di dokumen sumber berisi lima hal: bilingual EN, responsif mobile, uji
beban, audit keamanan, dan perbaikan bug. Semuanya dikerjakan, dalam lima batch.

| Batch | Isi | Butir catatan |
| --- | --- | --- |
| S9.1 | Konfigurasi produksi & pengerasan keamanan: `production-check` (13 pemeriksaan), CORS, proxy tepercaya, cookie Secure, pagar kata sandi seeder | 352–363 |
| S9.2 | Backup, restore, penjadwal, antrean: skrip dump + retensi 30 hari, skrip restore berpagar, templat cron dan Supervisor | 364–375 |
| S9.3 | Bilingual ID/EN: `lang/id.json` + `lang/en.json`, pemilih bahasa **POST**, ID tetap bawaan | 376–389 |
| S9.4 | QA responsif (7 perbaikan) + kesiapan uji beban k6 (5 skenario, berpagar) | 390–401 |
| S9.5 | QA akhir, rekonsiliasi bukti, serah terima kandidat rilis | 402–405 |

CBT MVP dan halaman muka publik adalah **tambahan permintaan pemilik yang
selesai sebelum Sprint 9**, bukan bagian dari Sprint 9. Lihat
`docs/owner-scope-changes.md` dan `docs/cbt-mvp-closure.md`.

## 2. Angka pengujian

| | SQLite | MySQL 8.4 |
| --- | --- | --- |
| Sebelum Sprint 9 (akhir Sprint 8 + CBT + landing) | 2291 | 2291 |
| Sesudah S9.4 | 2417 / 11288 assertions | 2417 / 11288 assertions |
| **Sesudah S9.5** | **2423 / 11406 assertions** | **2423 / 11406 assertions** |

Kedua mesin dijalankan **berurutan, tidak pernah tumpang tindih**. Jumlah test
dan assertion-nya identik di kedua mesin; tidak ada test yang dilewati pada
salah satunya.

Yang S9.5 tambahkan: `tests/Feature/Qa/EntryPointBoundaryTest.php` — enam test
yang membaca tabel rute yang hidup, bukan daftar yang ditulis tangan (butir 402).

## 3. Dokumen sumber

`smartsukses-docs/` **tidak tersentuh sepanjang Sprint 9**. Commit terakhir yang
menyentuhnya adalah `239552a Initial Laravel 11 Setup`. Seluruh dokumentasi
implementasi hidup di `docs/`, terpisah.

## 4. Checklist go-live resmi

Sepuluh butir Arsitektur A.3, dengan buktinya masing-masing.

| # | Butir | Status | Bukti |
| --- | --- | --- | --- |
| 1 | Unit test isolasi tenant lulus 100% | **PASS** | 107 test isolasi lintas cabang hijau di kedua mesin, mencakup SchoolAdmin, Guru, Wali, Bendahara, Kepala, Siswa, Orang Tua, dan CBT (ujian, hasil, jembatan nilai). Ditambah 118 test batas akses & audit, dan `EntryPointBoundaryTest` yang menolak rute portal tanpa pagar |
| 2 | Uji manual lintas cabang | **NOT DONE** | Otomatis ≠ manual. Matriksnya disiapkan sebagai H-05…H-07 dan H-09 di `docs/human-qa-handoff.md`; belum dijalankan manusia |
| 3 | Uji beban 200 pengguna konkuren | **NOT DONE / PREPARED** | 5 skenario k6 + ambang roadmap + rencana bertahap 5→50→200 siap di `ops/load-tests/`. k6 tidak terpasang, tidak ada staging/VPS, **belum pernah dieksekusi**. Tidak ada satu angka pun yang dapat dilaporkan |
| 4 | Encoding tautan wa.me | **PASS** | 82 test encoding pada PPDB dan notifikasi hijau di kedua mesin, termasuk spasi, baris baru, dan tanda baca. Phase 1 tetap wa.me manual — tanpa API |
| 5 | Format & data PDF rapor | **PARTIAL** | 18 test: PDF benar-benar terbentuk (`%PDF`), memuat nama siswa, mapel, nilai, penanda DRAFT, dan menolak rapor cabang lain. Yang belum: mata manusia atas tata letak, blok tanda tangan, dan kewajaran data — H-13/H-14 |
| 6 | SSL aktif + redirect HTTP→HTTPS | **PARTIAL** | Sisi aplikasi siap dan teruji: `APP_URL` https, cookie Secure + HttpOnly, proxy tepercaya. TLS, Certbot, redirect, dan HSTS seluruhnya sisi server — belum ada server |
| 7 | Backup otomatis **dan** dapat di-restore | **PARTIAL** | Skrip dump + retensi 30 hari; kredensial lewat `--defaults-extra-file` ber-`chmod 600`, tidak pernah di baris perintah; restore menolak basis data produksi tanpa `ALLOW_PRODUCTION_RESTORE=yes`; latihan pemulihan lokal ke `smartsukses_test` lolos. Belum: backup terjadwal di server, dan **cadangan berkas `storage/app/*` belum ada otomatisasinya sama sekali** |
| 8 | Seluruh kata sandi bawaan diganti | **PARTIAL** | `UserSeeder` menolak berjalan di produksi tanpa `SEED_ADMIN_PASSWORD`; `Sprint4DemoSeeder` tetap tidak terdaftar di `DatabaseSeeder`; `must_change_password` memagari panel dan ketiga portal. Kata sandi `root` MySQL hanya dapat diverifikasi di server |
| 9 | CORS dibatasi ke domain | **PARTIAL** | `config/cors.php` tidak wildcard, dan `production-check` menggagalkan `*`. Verifikasi di server belum |
| 10 | Pemantauan uptime | **NOT DONE** | Menuntut layanan eksternal dan server. Tidak ada yang diadakan — tidak ada layanan berbayar yang dibeli sepanjang Sprint 9 |

**2 PASS · 5 PARTIAL · 3 NOT DONE.**

Tidak ada butir yang berpindah menjadi PASS pada S9.5, dan itu memang yang
diharapkan: seluruh sisanya menuntut server atau manusia, bukan kode.

## 5. Yang masih menunggu verifikasi

### Manusia

- `docs/human-qa-handoff.md` — 20 pemeriksaan kritis, **seluruhnya kosong**.
- `docs/responsive-qa.md` — 41 baris responsif, **seluruhnya kosong**. Tidak ada
  satu pun permukaan yang diverifikasi secara tampilan sepanjang Sprint 9;
  ekstensi peramban tidak pernah terhubung, dan tidak ada tangkapan layar yang
  dibuat.
- Tinjauan format PDF rapor.

### Server

TLS dan redirect HTTP→HTTPS, HSTS, alamat IP klien di balik Cloudflare, cron
penjadwal, cron backup, worker Supervisor, SMTP, pemantauan uptime, dan seluruh
uji beban 5→50→200 VU. Rinciannya berurutan di `docs/deployment-candidate.md`.

## 6. Keputusan pemilik yang masih terbuka

Tidak satu pun diputuskan sendiri oleh pengembang.

| | Perkara | Tercatat di |
| --- | --- | --- |
| 1 | **NOTIF-03** — celah penerima eksternal: penerima yang bukan pengguna sistem tidak punya sisi in-app. Menuntut keputusan skema | `docs/sprint-8-closure.md` §A |
| 2 | Shortcut **"Buat Pengumuman"** pada dasbor guru — PORTAL-02 memintanya, matriks izin PRD tidak memberikannya | `docs/sprint-8-closure.md` §B |
| 3 | Pembuatan nilai manual global setelah rapor terbit — pagar CBT bersifat lokal dan **tidak** diperluas menjadi kebijakan penilaian global | `docs/cbt-mvp-closure.md` |
| 4 | **F-1 / F-3 / F-4 / F-5** — pertanyaan penilaian yang belum dijawab; tidak disentuh sepanjang Sprint 9 | `docs/implementation-notes.md` |
| 5 | **R-2** — bolehkah Admin Sekolah menyusun soal, atau hanya guru pengampunya | `docs/cbt-mvp-closure.md` |
| 6 | **R-4** — apakah "satu percobaan per ujian" sesuai cara sekolahnya bekerja | `docs/cbt-mvp-closure.md` |
| 7 | Materi pemasaran dan kontak final halaman muka | `docs/owner-scope-changes.md` |
| 8 | Aturan CBT yang disengaja: status wali kelas **saja** tidak memberi hak mengelola ujian | `docs/cbt-mvp-closure.md` |

## 7. Phase 2 — tetap ditangguhkan

CBT Phase 2 penuh, penilaian esai/manual, bank soal, pengacakan soal,
pengulangan/reset percobaan, anti-curang, analitik lanjutan, soal bermedia, LMS
lain, presensi digital, BK, perpustakaan, inventaris, penggajian, WhatsApp API
otomatis, dan payment gateway.

CBT MVP yang dipercepat sudah terkirim dan tidak membuka satu pun di atas.

## 8. Langkah berikutnya, persisnya

1. **Tanyakan Pak Akbar** soal layanan berbayar — Gerbang 0 di
   `docs/deployment-candidate.md`. Tidak ada satu pun yang dibeli, disewa, atau
   diaktifkan sepanjang Sprint 9, dan tidak boleh ada yang dibeli sebelum
   dijawab.
2. Sediakan VPS + DNS, lalu jalankan PRE-DEPLOY → DEPLOY → POST-DEPLOY berurutan.
3. Jalankan `docs/human-qa-handoff.md`. Kegagalan pada H-05…H-07 atau H-09
   menunda rilis.
4. Jalankan uji beban `smoke` → `baseline` → `target`. **Hanya setelah tahap
   `target` lolos** butir 3 checklist boleh berpindah dari NOT DONE.
5. Pasang pemantauan uptime.

Aplikasinya **selesai secara fungsional**. Deployment-nya **belum dikerjakan**.
Dokumen ini sengaja tidak menyatukan keduanya.
