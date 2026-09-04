# M5 — Kesiapan otorisasi impor siswa produksi

> **Yang dibuat batch ini kemampuan, bukan izin.**
>
> Perintahnya ada supaya impor produksi kelak dapat dijalankan tanpa mengubah
> satu baris kode. Apakah ia boleh dijalankan, kapan, dan oleh siapa adalah
> keputusan terpisah yang **belum diambil**.
>
> Tidak ada satu baris data sungguhan yang diimpor ke produksi oleh batch ini,
> dan tidak ada basis data jarak jauh mana pun yang disentuh.

Dokumen ini mencatat keputusan, bukan data. Tidak ada NIS, NISN, nama, atau
alamat siswa di sini. Berkas sumber tetap di luar repositori.

---

## 1. Mengapa impor produksi sebelumnya mustahil

Hasil audit M3/M4, bukan asumsi.

| Bagian | Perannya | Yang menghalangi produksi |
| --- | --- | --- |
| `MigrasiDryRun` | analisis, tidak pernah menulis | — |
| `MigrasiTerapkanUji` | mode terap | memanggil `StudentImportApply` yang dipagari |
| `MigrationWriteGuard` | pagar tulis | menolak `production`, **dan** menolak nama basis data yang bukan `_test` |
| `StudentImportPlan` | satu-satunya penilai baris | — |
| `StudentImportApply` | penulis | `run()` melempar bila pagar menolak |
| `CanonicalRombel` | daftar rombel + alias | — |
| `MigrasiSiapkanRombel` | penyiapan struktur | tidak dipagari, memang disengaja (butir 515) |

Jadi jalur terapnya bukan "belum diizinkan" melainkan **tidak ada**: satu-satunya
perintah terap menolak produksi lewat dua syarat sekaligus.

`migrasi:terapkan-uji` **tetap utuh dan tetap dipagari**. Ia tidak diberi opsi
`--produksi` dan tidak diganti nama.

---

## 2. Perintah tersendiri

```
migrasi:terapkan-produksi
```

Nama yang menyebut apa adanya. Satu bendera yang salah ketik tidak boleh
memisahkan basis data uji dari basis data sekolah, dan dua nama perintah yang
berbeda tidak dapat tertukar oleh riwayat shell (butir 522).

```sh
# 1. Pratinjau — tidak menulis apa pun, mencetak sidik jari.
php artisan migrasi:terapkan-produksi "<berkas .xlsx>" \
    --school=PUSAT --tahun-ajaran="2026/2027 Ganjil"

# 2. Terap — sesudah angkanya ditinjau manusia.
php artisan migrasi:terapkan-produksi "<berkas .xlsx>" \
    --school=PUSAT --tahun-ajaran="2026/2027 Ganjil" \
    --sidik-jari=<dari langkah 1> \
    --backup-terverifikasi \
    --konfirmasi
```

---

## 3. Tujuh pagar

Seluruhnya harus terbuka bersamaan. **Tidak ada bendera yang dapat melewati satu
pun di antaranya**, dan tidak ada mode non-interaktif.

| # | Pagar | Bila tertutup |
| --- | --- | --- |
| 1 | `APP_ENV=production` | ditolak, menyarankan `migrasi:terapkan-uji` |
| 2 | `--school` disebut eksplisit | ditolak; tidak ada cabang bawaan |
| 3 | `--tahun-ajaran` disebut eksplisit | ditolak; **tidak pernah** jatuh ke tahun aktif |
| 4 | berkas sumber ada dan terbaca | ditolak |
| 5 | analisis kering bersih (§5) | ditolak, seluruh alasannya disebut sekaligus |
| 6 | `--sidik-jari` cocok | ditolak |
| 7 | `--backup-terverifikasi` + `--konfirmasi` + kalimat diketik | ditolak |

Alasan penolakan dikembalikan sebagai **daftar**, bukan alasan pertama saja:
operator berhak tahu semua yang harus dibereskan sekali jalan, bukan
menemukannya satu per satu lewat percobaan berulang di produksi.

### Otorisasi adalah bukti, bukan bendera

`StudentImportApply` menerima objek `ProductionImportAuthorization`, bukan
`bool $production = true`. Objek itu hanya dapat lahir lewat `grant()` yang
memeriksa ulang setiap syarat, sehingga tidak ada jalan memanggil impor produksi
dengan `true` yang tersalin dari contoh kode atau tertinggal dari eksperimen
(butir 520).

Objeknya membawa rencana yang disahkannya. Sebelum menulis, `StudentImportApply`
memeriksa bahwa rencana yang hendak ditulis benar-benar rencana yang sama —
otorisasi yang dapat dipakai ulang untuk berkas lain sama saja dengan tidak ada
otorisasi.

---

## 4. Sidik jari

Menutup kekeliruan yang sangat mungkin terjadi dan hampir mustahil terlihat:
analisis kering atas berkas A, mode terap atas berkas B. Keduanya mencetak angka
yang meyakinkan, dan yang masuk ke basis data bukan yang ditinjau siapa pun
(butir 519).

Sidik jarinya menutup **empat** hal:

1. isi berkas — `sha256` penuh atas byte-nya;
2. cabang tujuan;
3. tahun ajaran tujuan;
4. seluruh angka rekonsiliasi yang sudah dinormalkan.

Angka rekonsiliasi ikut karena berkas yang sama dapat menghasilkan rencana yang
berbeda bila basis datanya berubah di antara kedua perintah — rombel dihapus,
siswa ditambahkan tangan, tahun ajaran diganti. Sidik jari yang hanya membaca
berkas akan menyatakan keduanya sama padahal yang akan ditulis sudah berbeda.

Panjangnya 16 karakter heksadesimal: cukup untuk tidak bertabrakan secara
kebetulan, cukup pendek untuk disalin dengan tangan.

**Tidak memuat data pribadi.** Yang masuk hitungan hanya hash berkas dan angka;
tidak ada NIS, NISN, nama, maupun isi baris, dan sidik jarinya tidak dapat
dikembalikan menjadi isi berkas. Ada tesnya.

Tidak ada berkas persetujuan yang disimpan di mana pun: sidik jarinya dihitung
ulang dari berkas dan basis data setiap kali, sehingga tidak ada artefak berisi
metadata impor yang tertinggal di disk.

---

## 5. Rekonsiliasi wajib bersih

Impor produksi **ditolak** bila salah satu di bawah ini bukan nol:

| Keadaan | Sebab ditolak |
| --- | --- |
| `REJECTED_MASTER_INCOMPLETE` | ada baris yang datanya tidak lengkap |
| `PENDING_DUPLICATE_NIS` | identitas ganda di dalam berkas |
| `PENDING_DUPLICATE_NISN` | NISN ganda sesudah normalisasi |
| `PENDING_INVALID_NISN` | NISN perlu diperiksa manusia |
| `PPDB_RECONCILIATION_REQUIRED` | kemungkinan tumpang tindih dengan pendaftar PPDB |
| `CLASS_NOT_FOUND` | rombel tujuan belum ada |
| `CLASS_AMBIGUOUS` | rombel bernama sama lebih dari satu |
| `CLASS_LABEL_MISSING` | label kelas kosong pada baris yang siap |
| `ACADEMIC_YEAR_MISSING` | tahun ajaran tujuan tidak ada |

Rekonsiliasi juga harus seimbang (`sumber = siap + tertunda + ditolak`) dan
minimal ada satu baris yang siap.

**Tidak ada bendera yang dapat melewatkan syarat ini.** Kalau ada, ia akan
dipakai — biasanya larut malam, biasanya oleh orang yang sedang terburu-buru.

---

## 6. Satu siswa yang tertunda

`PENDING_MISSING_NIS` **bukan** penghalang. Satu siswa yang menunggu NIS resmi
adalah keadaan yang sudah diputuskan dan diterima, dan ia tidak boleh menahan
39 yang lain (butir 487).

Tetapi ia ditampilkan menonjol sebelum konfirmasi:

```
TERTUNDA    1 TIDAK IKUT — PENDING_MISSING_NIS=1
```

Satu siswa yang tidak ikut adalah hal yang harus disadari **sebelum** menekan
enter, bukan ditemukan sesudahnya (butir 524).

Untuknya tetap: tidak ada NIS sementara, tidak ada penempatan kelas, tidak ada
akun.

---

## 7. Kalimat konfirmasi

Diketik utuh, dan **diturunkan dari rencana yang sebenarnya**:

```
IMPOR 39 SISWA PUSAT 2026/2027 Ganjil
```

Jumlah, cabang, dan tahun ajaran datang dari rencana, bukan ditulis tetap di
kode. Kalimat yang selalu sama akan dihafal dan diketik tanpa dibaca; kalimat
yang memuat angka yang berubah memaksa operator membaca apa yang sedang
disetujuinya (butir 521).

Spasi berlebih dan besar-kecil huruf dimaafkan; isinya tidak.

---

## 8. Pernyataan backup

`--backup-terverifikasi` **tidak membuktikan** adanya backup, dan perintahnya
tidak berpura-pura bisa memeriksanya. Yang diminta pernyataan operator bahwa ia
sudah memverifikasi sendiri adanya salinan yang benar-benar **dapat dipulihkan**
(butir 523).

Tidak ada infrastruktur backup yang dibuat di sini. Skrip yang sudah ada
(`ops/backup-database.sh`, `ops/restore-database.sh`) tidak diubah.

---

## 9. Akun tetap terpisah

Impor produksi **tidak** membuat akun. Tidak ada surel yang dikarang, tidak ada
kata sandi yang dibangkitkan, tidak ada akun orang tua. `students.user_id` tetap
NULL, dan login terpadu tidak disentuh.

Laporan memisahkan ketiganya:

```
MASTER_IMPORTED      dibuat + dicocokkan
PLACEMENT_IMPORTED   penempatan kelas yang dibuat
ACCOUNT_PENDING      seluruhnya — penyediaan akun alur terpisah
```

---

## 10. Idempotensi dan interupsi

Seluruh jaminan M3 tetap berlaku di jalur produksi, dan ada tesnya: NIS resmi
yang sudah ada dicocokkan bukan diduplikasi; nama **tidak pernah** dipakai
mencocokkan; jalan kedua menghasilkan nol siswa dan nol penempatan baru; kolom
yang sudah terisi tidak ditimpa (hanya `nisn` yang kosong yang diisikan);
isolasi cabang tetap.

### Semantik interupsi

Setiap siswa ditulis di dalam **transaksinya sendiri**, bersama penempatan
kelasnya. Bila impor 39 siswa terhenti di siswa ke-20 — koneksi putus, proses
dimatikan, galat basis data:

- 19 siswa yang sudah selesai **tetap tersimpan**;
- siswa ke-20 **dibatalkan seluruhnya** — tidak ada siswa tanpa penempatan, dan
  tidak ada penempatan tanpa siswa;
- sisanya belum tersentuh;
- **menjalankan ulang perintahnya aman**: 19 yang pertama dicocokkan, sisanya
  dilanjutkan.

Impor **tidak** dibungkus satu transaksi raksasa, dan itu disengaja. Transaksi
sepanjang 39 siswa menahan kunci lebih lama, membesarkan undo log, dan yang
terburuk: kegagalan di siswa ke-39 membuang 38 yang sudah benar. Karena setiap
baris idempoten, melanjutkan jauh lebih murah daripada mengulang dari nol.

Yang perlu diketahui operator: sesudah interupsi, **jalankan ulang pratinjaunya
lebih dulu**. Angkanya akan berubah (sebagian sudah menjadi `READY_MATCH`), dan
karena itu sidik jarinya juga berubah — itu memang perilaku yang benar.

---

## 11. Jejak audit

Dua lapis, keduanya memakai arsitektur yang sudah ada — tidak ada kerangka
pencatatan baru.

**Per baris.** `Student` dan `StudentClass` ditulis lewat Eloquent, sehingga
listener wildcard di `AppServiceProvider` sudah menuliskan baris `audit_logs`
untuk masing-masing (`CREATED`, dengan `school_id` dan `auditable_id`). Ini
sudah berjalan sejak dulu dan tidak diubah.

**Agregat.** Satu baris di log aplikasi lewat `Log::info`, mengikuti pola yang
sudah dipakai `Login.php` untuk peristiwa yang bukan CUD model. `audit_logs`
tidak dipakai untuk ini karena barisnya selalu menunjuk satu model, dan kolom
bebas sengaja tidak pernah ditambahkan ke tabel itu supaya ia tidak menjadi
salinan data pribadi (butir 45, butir 525).

Yang dicatat:

```
operator_user_id · school_code · academic_year · database
source_file (nama saja) · source_sha256 · fingerprint
source_rows · ready · pending · rejected
created · matched · placed · skipped · accounts_created
```

Yang **tidak pernah** dicatat: nama, NIS, NISN, alamat, kontak, jalur berkas
lengkap, maupun isi berkas. Ada tesnya — jejaknya diperiksa tidak memuat satu
pun identitas, **bahkan yang karangan sekalipun**.

---

## 12. Tampilan sebelum konfirmasi

```
SASARAN        LINGKUNGAN · BASIS DATA · CABANG · TAHUN AJARAN
               BERKAS SUMBER (nama saja) · LEMBAR · SIDIK JARI
REKONSILIASI   SUMBER · SIAP · TERTUNDA · DITOLAK
NISN           VALID · NORMALIZED · BLANK · INVALID
PENEMPATAN     PLACEMENT_READY · PLACEMENT_EXISTS
               CLASS_NOT_FOUND · CLASS_AMBIGUOUS
AKAN DITULIS   BUAT BARU · COCOKKAN · AKUN PENGGUNA (selalu 0)
```

Tidak ada satu pun data per baris. Jalur berkas tidak ditampilkan — hanya nama
berkasnya — karena jalurnya privat.

---

## 13. Daftar periksa operator

Sebelum menjalankan impor produksi yang sesungguhnya:

- [ ] Otorisasi tertulis dari sekolah untuk mengimpor data siswa ke produksi.
- [ ] Cabang dan tahun ajaran tujuan disepakati dan tertulis.
- [ ] Keempat rombel sudah ada — `migrasi:siapkan-rombel` mode kering menunjukkan
      "sudah ada" untuk semuanya, dan **nol nama ganda**.
- [ ] Backup basis data diambil **dan pemulihannya diuji**, bukan sekadar
      diambil.
- [ ] Pratinjau dijalankan; seluruh angkanya ditinjau manusia.
- [ ] `CLASS_NOT_FOUND = 0` dan `CLASS_AMBIGUOUS = 0`.
- [ ] `DITOLAK = 0`.
- [ ] Jumlah `TERTUNDA` diketahui dan diterima secara sadar.
- [ ] Sidik jari dicatat dari pratinjau itu.
- [ ] Terap dijalankan dengan sidik jari yang sama, di sesi yang sama, sebelum
      ada yang mengubah berkas atau basis data.
- [ ] Rekonsiliasi sesudahnya diperiksa; jumlah akun tetap nol.
- [ ] Penyediaan akun **tidak** dijalankan — alur terpisah.

---

## 14. Yang masih di luar batch ini

Tidak dikerjakan, dan sengaja tidak: menjalankan impor produksi, menyentuh basis
data jarak jauh mana pun, membuat infrastruktur backup, menyediakan akun siswa
atau orang tua, dan melonggarkan `MigrationWriteGuard`.

Mode non-interaktif juga sengaja tidak dibuat. Bila kelak dibutuhkan, itu
keputusan tersendiri dengan pagarnya sendiri — bukan jalan pintas yang
ditinggalkan di sini untuk ditemukan orang lain.
