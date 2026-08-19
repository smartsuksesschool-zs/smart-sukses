# Menjalankan Demo Sprint 4 — Penilaian & E-Rapor

Runbook untuk memperagakan Sprint 4 dari UI, dari data kosong sampai PDF rapor terunduh.
Keputusan dan alasan implementasinya ada di [`implementation-notes.md`](implementation-notes.md);
berkas ini hanya berisi perintah dan urutan.

## 1. Prasyarat

| | |
| --- | --- |
| PHP | 8.3+ dengan ekstensi **`zip`** dan **`gd`** aktif — `zip` dipakai PhpSpreadsheet (import/template Excel), `gd` dipakai DomPDF |
| Database | MySQL 8.x berjalan, database aplikasi sudah dibuat |
| `.env` | `QUEUE_CONNECTION=database`, `FILESYSTEM_DISK=local` (keduanya sudah menjadi bawaan `.env.example`) |
| Password seeder | `SEED_ADMIN_PASSWORD` di `.env`. Bila dibiarkan kosong/tidak ada, seluruh akun seeder memakai `Password123` |

```bash
php artisan migrate          # termasuk tabel jobs & failed_jobs
```

## 2. Menyiapkan data demo

```bash
php artisan db:seed                              # peran, cabang PUSAT, akun awal
php artisan db:seed --class=Sprint4DemoSeeder    # data demo Sprint 4
```

`Sprint4DemoSeeder` sengaja **tidak** didaftarkan di `DatabaseSeeder` supaya tidak pernah
ikut berjalan saat deploy produksi — karena itu ia dipanggil terpisah. Seeder-nya aman
dijalankan berulang: setiap baris dibuat lewat `updateOrCreate` pada natural key-nya dan
tidak ada data yang dihapus.

Rapor **sengaja belum dibuat** oleh seeder — menekan Generate Rapor → Terbitkan →
Generate PDF dari UI justru inti peragaannya.

## 3. Menjalankan aplikasi

Tiga proses, masing-masing di terminal sendiri:

```bash
php artisan serve            # http://localhost:8000
php artisan queue:work       # WAJIB — tanpa ini Generate PDF Kelas menggantung di QUEUED
npm run dev                  # hanya bila aset belum di-build; alternatifnya `npm run build` sekali
```

Atau sekaligus lewat skrip yang sudah ada di `composer.json`:

```bash
composer dev                 # serve + queue:listen + pail + vite dalam satu perintah
```

`queue:work` memuat kode satu kali di awal; setelah mengubah kode, hentikan lalu jalankan
ulang worker-nya. `composer dev` memakai `queue:listen` yang memuat ulang sendiri tiap job,
jadi lebih nyaman selama pengembangan dan lebih lambat di produksi.

> **Queue worker bukan opsional.** `QUEUE_CONNECTION=database` dan
> `App\Jobs\GenerateReportCardPdf` berjalan di antrean (Tech stack 3.1 — *"Background jobs
> (generate tagihan massal, PDF rapor)"*). Tanpa worker, tombol Generate PDF Kelas tetap
> melaporkan *"N PDF rapor masuk antrean"*, tetapi kolom PDF berhenti di **Dalam antrean**
> selamanya. Di produksi peran worker ini dipegang supervisor (Arsitektur 3.3).

## 4. Akun demo

Semua memakai password dari `SEED_ADMIN_PASSWORD` (bawaan `Password123`).

| Email | Peran | Dipakai untuk |
| --- | --- | --- |
| `superadmin@smartsukses.sch.id` | Super Admin | Grade Config & Pengaturan Penilaian lintas cabang — satu-satunya peran yang melihat field **Cabang Sekolah** |
| `admin.pusat@smartsukses.sch.id` | Admin Sekolah | Grade Config cabang PUSAT, master data |
| `guru.pusat@smartsukses.sch.id` | Guru | Input Nilai, Import Excel |
| `walikelas.pusat@smartsukses.sch.id` | Wali Kelas X-A | Generate Rapor, Terbitkan, Generate PDF |

Dua akun pertama dibuat `UserSeeder` dengan `must_change_password = true`, jadi login
pertamanya akan meminta ganti sandi. Dua akun terakhir dibuat `Sprint4DemoSeeder` dengan
`must_change_password = false` supaya peragaan tidak terpotong layar ganti sandi.

## 5. Data yang disiapkan seeder

Cabang **PUSAT**, tahun ajaran **2026/2027 Ganjil**, kelas **X-A** (wali kelas di atas),
tiga siswa: NIS `240001`, `240002`, `240003`.

Tiga mata pelajaran sengaja dibuat dalam kondisi berbeda:

| Mapel | Kondisi | Yang diperagakan |
| --- | --- | --- |
| **MTK** | Grade Config ACTIVE (Harian 40 · UTS 30 · UAS 30), nilai lengkap, plus satu nilai FORMATIVE dan satu ATTITUDE | Rata-rata harian, formatif tidak ikut dihitung, predikat sikap. Nilai harian siswa pertama 80 · 90 · 70 → rata-rata tepat **80** |
| **BIN** | Grade Config ACTIVE, nilai lengkap, plus satu **SKILL sumatif yang tidak ada di config** | Peringatan C-6 — komponen di luar Grade Config |
| **IPA** | **Tanpa Grade Config sama sekali** | Rapor tampil "belum lengkap" beserta alasannya, dan membuktikan "belum dikonfigurasi" ≠ "sudah LOCKED" |

## 6. Alur peragaan

1. **Input Nilai** — masuk sebagai `guru.pusat`, buka **Input Nilai**, pilih X-A — MTK,
   komponen Harian, jenis Sumatif, isi nilai beberapa siswa, simpan.
2. **Import Excel** — masih sebagai `guru.pusat`, buka **Nilai** → **Unduh Template**
   (`template_nilai.xlsx`, dua kolom: `nis`, `nilai`) → isi dengan NIS `240001`–`240003` →
   **Import Excel**, pilih X-A — MTK, komponen dan jenis penilaian, unggah berkasnya.
   Hasilnya dilaporkan sebagai *"N nilai berhasil diimport"* beserta daftar error per baris
   bila ada.
3. **Peringatan komponen di luar Grade Config** — ulangi import (atau input satuan) untuk
   **X-A — BIN** dengan komponen **Keterampilan (SKILL)** dan jenis **Sumatif**. Nilai tetap
   tersimpan, tetapi muncul peringatan *"Komponen ini tidak masuk nilai akhir"*. Tidak ada
   konfigurasi yang diubah otomatis — siklus DRAFT → ACTIVE → LOCKED tetap milik Admin.
4. **Generate Rapor Kelas** — masuk sebagai `walikelas.pusat`, buka **Rapor** →
   **Generate Rapor Kelas** → pilih X-A. Ringkasannya menyebut mapel yang belum lengkap
   (IPA, karena tanpa Grade Config) dan komponen yang diabaikan (SKILL pada BIN).
5. **Terbitkan** — **Terbitkan Sekelas** untuk X-A. Rapor yang belum lengkap tertahan dan
   alasannya disebutkan. Setelah terbit, nilainya terkunci dan Grade Config mapel terkait
   berpindah ke LOCKED.
6. **Generate PDF Kelas** — tombol **Generate PDF Kelas**, pilih X-A. Notifikasi menyebut
   *"N PDF rapor masuk antrean"*. Kolom **PDF** berubah **Dalam antrean → Siap diunduh**
   begitu worker menyelesaikannya; segarkan halaman untuk melihatnya.
7. **Unduh PDF** — buka salah satu rapor → **Unduh PDF**. Berkasnya tersimpan di
   `storage/app/private/rapor/{school_id}/rapor_{id}.pdf`.

## 7. Memeriksa antrean bila PDF tidak kunjung siap

```bash
php artisan queue:work                 # pastikan worker memang berjalan
php artisan queue:failed               # daftar job yang gagal setelah 3 percobaan
php artisan queue:retry all            # coba ulang seluruh job gagal
php artisan queue:flush                # kosongkan daftar job gagal
```

Job PDF mencoba ulang **3 kali** dengan jeda 10 detik. Bila seluruh percobaan habis,
statusnya menjadi **GAGAL** dan barisnya muncul di `php artisan queue:failed`. PDF versi
sebelumnya (bila ada) sengaja tidak dihapus — berkas lama masih sah dan tetap dapat
diunduh.

Berkas unggahan import disimpan sementara di `storage/app/private/imports/` dan dihapus
begitu import selesai, baik berhasil, ditolak, maupun gagal dibaca.
