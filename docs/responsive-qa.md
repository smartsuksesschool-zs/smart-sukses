# QA Responsif — Audit, Perbaikan, dan Daftar Uji Manual

Batch S9.4.

---

## 1. Metode, dan batasnya

**Tidak ada satu pun permukaan yang diverifikasi secara visual.**

Peramban otomatis tidak dapat dijangkau saat batch ini dikerjakan (ekstensi
tidak terhubung), sehingga tidak ada tangkapan layar, tidak ada pengukuran tata
letak, dan tidak ada pernyataan "terlihat benar". Setiap baris di §6 karena itu
berstatus **BELUM DIUJI VISUAL**, bukan PASS.

Yang benar-benar dikerjakan:

| Cara | Apa yang dihasilkan |
| --- | --- |
| Analisis CSS pada tata letak milik sendiri | Perangkap struktural yang pasti — aturan yang keberadaannya menentukan perilaku |
| Pembacaan HTML **terender sungguhan** dari server lokal | Isi navbar, markup form, kelas yang benar-benar keluar — bukan tebakan dari template |
| Perhitungan lebar min-content dari isi terender | Perkiraan berapa rem yang dibutuhkan navbar dibanding 22,5rem yang tersedia di 360px |
| Pembacaan Blade | Pembungkus tabel, sasaran sentuh, pembungkusan teks |

Yang **tidak** dapat disimpulkan dengan cara itu: apakah hasil akhirnya nyaman
dipakai. Itu menuntut mata pada layar sungguhan, dan itulah gunanya §6.

## 2. Permukaan yang diaudit

Publik: halaman muka, daftar cabang PPDB, formulir PPDB, cek status PPDB.
Auth: masuk panel (Filament), masuk siswa, masuk orang tua.
Siswa: dasbor, jadwal, nilai, notifikasi, profil, daftar ujian, pengerjaan
ujian, hasil ujian.
Orang tua: dasbor, pemilih anak, nilai/rapor, tagihan, notifikasi.
Guru: dasbor, jadwal, kelas ajar, daftar siswa kelas.
Filament: tabel/form siswa, kelas & mata pelajaran, input nilai, aksi rapor,
tabel keuangan, notifikasi, penyusunan ujian CBT, editor soal, tabel hasil CBT,
aksi Masukkan ke Nilai.

## 3. Temuan dan perbaikan

Enam cacat konkret. Masing-masing dapat ditunjukkan sebabnya, bukan dicurigai.

### T1 — Navbar halaman muka terpotong, bukan tergulung (berat)

`.nav__links` adalah flex item **dan** punya `overflow-x: auto`. Flex item punya
`min-width: auto`: ia menolak menyusut di bawah lebar min-content-nya. Akibatnya
penggulungnya tidak pernah aktif — isinya meluber keluar `.nav__inner`, lalu
`body { overflow-x: hidden }` memotongnya.

Diukur dari HTML terender: isi navbar ≈ **30rem**, ruang tersedia pada 360px
**22,5rem** (dikurangi merek 9rem dan padding, tersisa ≈ 10rem). Yang terpotong
adalah dua kendali paling penting, karena keduanya duduk paling kanan: tombol
**Masuk** dan **pemilih bahasa** yang baru ditambahkan S9.3.

**Perbaikan:** `min-width: 0` pada `.nav__links`. Penggulungnya kini benar-benar
bekerja — perilaku yang memang diniatkan sejak awal (butir 346).

### T2 — Baris pengguna portal dapat melebihi layar (sedang)

`.portal-user` memuat pemilih bahasa (5,5rem sejak S9.3), lonceng notifikasi,
nama pengguna, dan tombol keluar — tanpa `flex-wrap`, dan nama penggunanya tanpa
pembungkusan. Nama pengguna adalah **data**, panjangnya tidak terbatas.

**Perbaikan:** `flex-wrap: wrap` + `min-width: 0` pada `.portal-user`; kelas
`.portal-user__name` baru dengan `overflow-wrap: anywhere`.

### T3 — Tata letak PPDB tanpa pagar lebar halaman (sedang)

Portal dan halaman muka sama-sama memasang `body { overflow-x: hidden }`,
`img { max-width: 100% }`, dan `-webkit-text-size-adjust: 100%`. Tata letak PPDB
tidak punya satu pun — justru permukaan yang paling banyak dibuka dari ponsel,
karena di sanalah orang tua mendaftarkan anaknya.

**Perbaikan:** ketiganya ditambahkan, menyamakan PPDB dengan dua tata letak lain.

### T4 — Sasaran sentuh PPDB di bawah standar sistem (sedang)

Seluruh sistem memakai 2,75rem (44px). Tombol dan kolom isian PPDB hanya
setinggi padding-nya, sekitar 2,3rem — pada formulir yang diisi dari ponsel.

**Perbaikan:** `min-height: 2.75rem` pada `.btn` dan pada
`.field input, .field select, .field textarea`. `.btn` juga menjadi
`inline-flex` supaya teksnya benar-benar terpusat pada tinggi baru.

### T5 — Pasangan label/nilai halaman status PPDB tidak dapat membungkus (ringan)

`dl.detail div` memakai `display: flex; justify-content: space-between` tanpa
`flex-wrap`. Nilainya data — nama sekolah, nama calon siswa — dan tidak dapat
menyusut di bawah min-content-nya.

**Perbaikan:** `flex-wrap: wrap`, dan `min-width: 0; overflow-wrap: anywhere`
pada `dd`.

### T6 — Tabel tren keuangan tanpa penggulung (ringan)

`laporan-keuangan.blade.php` merender tabel tiga kolom rupiah tanpa pembungkus
`overflow-x-auto`, padahal halaman saudaranya `laporan-keuangan-cabang.blade.php`
memakainya. Yang menggulung akhirnya halamannya, bukan tabelnya.

**Perbaikan:** dibungkus `overflow-x-auto`, menyamakan keduanya.

### T7 — Aksi baris Filament terlalu padat pada tabel lebar (sedang)

`ReportCardResource` punya **empat** aksi baris berdampingan (Lihat, Terbitkan,
PDF, Unduh PDF); `StudentFeeResource` punya tiga (Lihat, Catat Pembayaran,
Bebaskan). Pada 360px kolom aksi berada jauh di kanan tabel yang sudah lebar,
sehingga "Terbitkan" — aksi paling menentukan di halaman rapor — dan "Catat
Pembayaran" — aksi harian Bendahara — baru terlihat setelah menggulung sampai
ujung.

**Perbaikan:** keduanya dibungkus `Tables\Actions\ActionGroup`. Ini affordance
Filament sendiri, bukan kartu mobile buatan sendiri: nama aksinya tidak berubah,
otorisasinya tidak berubah, dan 748 uji grading + keuangan tetap hijau.

## 4. Yang diperiksa dan ternyata sudah benar

Dicatat supaya tidak diaudit ulang, dan supaya tidak ada perubahan spekulatif:

- **Halaman pengerjaan ujian CBT** — permukaan mobile paling berisiko, dan
  ternyata sudah dikeraskan sejak Batch C3: baris judul/timer `flex-wrap` dengan
  `min-width:0`, peta nomor soal membungkus dengan tombol 2,75rem, pilihan
  jawaban 2,75rem dengan `overflow-wrap:anywhere`, teks soal `white-space:
  pre-line`, tombol kumpulkan selebar kartu. **Tidak diubah.**
- **Navigasi portal** — `.portal-nav__inner` adalah anak elemen blok, bukan flex
  item, sehingga `overflow-x: auto`-nya memang bekerja. Tidak terkena T1.
- **Grid halaman muka** — mobile-first sejati: satu kolom sebagai bawaan, melebar
  pada 40/48/64rem. `.hero__cta` dan `.cta__buttons` sudah `flex-wrap`.
- **Tabel nilai siswa dan orang tua** — sudah di dalam `.portal-scroll`.
- **Tampilan Filament custom** — seluruhnya memakai kelas mobile-first (`sm:`,
  `lg:`), termasuk halaman tautan WhatsApp yang menumpuk pada layar sempit.
- **Lebar piksel tetap** — tidak ada sama sekali di tampilan web. Satu-satunya
  `width: 1px` adalah keterangan pembaca layar; `width: 90px` hanya ada di PDF,
  yang memang dicetak di kertas.

## 5. Risiko tata letak bilingual (S9.3)

Teks Inggris umumnya lebih panjang. Yang diperiksa: apakah wadahnya boleh
membungkus, sehingga label yang lebih panjang menambah **tinggi**, bukan lebar.

| Permukaan | ID → EN | Penilaian |
| --- | --- | --- |
| Pemilih bahasa | `ID`/`EN` pada kedua bahasa | Lebarnya tetap 5,5rem. Tidak ada risiko bilingual — risikonya justru ia menambah beban baris, yaitu T1 dan T2. |
| Navigasi halaman muka | "Akses Pengguna" → "User Access" (lebih pendek), "Masuk" → "Sign in" (lebih panjang) | Impas. T1 memperbaiki penyebab sebenarnya. |
| Tombol PPDB | "Kirim Pendaftaran" → "Submit Application" | Lebih panjang; tombolnya `inline-flex` dengan padding, teksnya membungkus. |
| Navigasi portal | "Notifikasi" → "Notifications", "Jadwal" → "Schedule" | Lebih panjang, tetapi `.portal-nav__inner` menggulung dengan benar. |
| Status/aksi CBT | "Kumpulkan Ujian" → "Submit Exam" (lebih pendek) | Aman. |
| Label keuangan | "Bayar Sebagian" → "Partially Paid" | Setara; berada di dalam badge yang membungkus. |
| Aksi custom Filament | "Masukkan ke Nilai" → "Add to Grades" | Setara; kini di dalam `ActionGroup` (T7). |

**Tidak ada terjemahan yang dipendekkan agar muat.** Makna didahulukan; wadahnya
yang disesuaikan.

## 6. Daftar uji manual — dijalankan saat QA akhir

Diisi oleh manusia dengan peramban sungguhan. **Belum ada satu baris pun yang
boleh ditandai PASS**, karena belum ada yang diuji secara visual.

Lebar yang diuji: **360px**, **390px**, **768px**, desktop.
Bahasa: **ID** dan **EN**.

Isi kolom hasil dengan `PASS`, `FAIL`, atau `N/A` — kosong berarti belum diuji.

| ID | Peran | Permukaan | Aksi kritis | Hasil yang diharapkan | 360 | 390 | 768 | Desktop |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| R-01 | Publik | Halaman muka | Tekan "Masuk" dan pemilih bahasa di navbar | Keduanya dapat dicapai; navbar menggulung ke samping, halaman tidak | | | | |
| R-02 | Publik | Halaman muka | Baca hero dan kartu fitur dalam EN | Tidak ada teks terpotong; kartu menumpuk satu kolom | | | | |
| R-03 | Publik | Daftar cabang PPDB | Pilih cabang | Kartu cabang menumpuk; tautan dapat ditekan jari | | | | |
| R-04 | Publik | Formulir PPDB | Isi seluruh kolom, kirim | Kolom selebar layar, tinggi ≥44px; galat validasi terbaca penuh | | | | |
| R-05 | Publik | Formulir PPDB | Unggah berkas | Kendali unggah tidak melebihi layar | | | | |
| R-06 | Publik | Cek status PPDB | Cek dengan nomor + tanggal lahir | Pasangan label/nilai membungkus, tidak terpotong | | | | |
| R-07 | Publik | Semua PPDB | Ganti ke EN | Tidak ada tombol/label yang meluber | | | | |
| R-08 | Semua | Masuk panel Filament | Masuk | Form terpusat; pemilih bahasa di topbar tetap dapat ditekan | | | | |
| R-09 | Siswa | Masuk portal siswa | Masuk | Pemilih bahasa di atas kartu; tombol selebar kartu | | | | |
| R-10 | Orang Tua | Masuk portal orang tua | Masuk | Sama seperti R-09 | | | | |
| R-11 | Siswa | Dasbor | Baca jadwal hari ini & nilai terbaru | Kartu menumpuk; nama pengguna di header membungkus, tidak terpotong | | | | |
| R-12 | Siswa | Jadwal | Gulir jadwal mingguan | Tidak ada gulir horizontal pada halaman | | | | |
| R-13 | Siswa | Nilai | Buka rincian komponen per mapel | Tabel yang menggulung, bukan halaman | | | | |
| R-14 | Siswa | Notifikasi | Buka satu notifikasi | Isi pesan membungkus; lencana terbaca | | | | |
| R-15 | Siswa | Profil | Baca seluruh baris | Nilai panjang membungkus | | | | |
| R-16 | Siswa | Daftar ujian | Buka ujian yang sedang dibuka | Judul panjang membungkus; tombol dapat ditekan | | | | |
| R-17 | Siswa | **Pengerjaan ujian** | Pilih jawaban, pindah soal, kumpulkan | Timer terlihat tanpa menggulung; peta nomor membungkus; pilihan panjang membungkus; tombol ≥44px | | | | |
| R-18 | Siswa | **Pengerjaan ujian** | Amati autosave saat memilih | Keadaan tersimpan terlihat tanpa menggeser tata letak | | | | |
| R-19 | Siswa | **Pengerjaan ujian** | Ujian kedaluwarsa / sudah dikumpulkan | Pesan keadaannya terbaca penuh | | | | |
| R-20 | Siswa | Hasil ujian | Baca nilai dan rincian | Nilai besar tidak memotong kartu | | | | |
| R-21 | Orang Tua | Dasbor | Ganti anak lewat pemilih anak | Pemilih membungkus bila anak lebih dari dua | | | | |
| R-22 | Orang Tua | Nilai/rapor | Unduh rapor PDF | Tombol unduh dapat dicapai | | | | |
| R-23 | Orang Tua | Tagihan | Buka riwayat pembayaran | Angka rupiah tidak terpotong; `details` membuka rapi | | | | |
| R-24 | Orang Tua | Notifikasi | Tandai semua dibaca | Tombol tidak menabrak judul | | | | |
| R-25 | Guru | Dasbor | Buka pintasan Input Nilai | Tiga pintasan menumpuk; pintasan mati terlihat jelas | | | | |
| R-26 | Guru | Jadwal / kelas ajar | Buka daftar siswa kelas | Daftar terbaca; lencana kehadiran tidak meluber | | | | |
| R-27 | Admin Sekolah | Tabel & form siswa | Tambah dan sunting siswa | Form Filament satu kolom; tabel menggulung sendiri | | | | |
| R-28 | Admin Sekolah | Kelas & mata pelajaran | Tambahkan mapel ke kelas | Modal tidak melebihi layar | | | | |
| R-29 | Guru | Input nilai | Simpan nilai satu kelas | Kendali nilai dapat ditekan; tombol simpan terjangkau | | | | |
| R-30 | Wali Kelas | Rapor | **Terbitkan** rapor lewat menu aksi | Aksi ditemukan dalam satu ketukan lewat `ActionGroup` | | | | |
| R-31 | Wali Kelas | Rapor | Generate PDF sekelas | Notifikasi hasil terbaca | | | | |
| R-32 | Bendahara | Tagihan siswa | **Catat Pembayaran** lewat menu aksi | Aksi ditemukan dalam satu ketukan; modal form muat | | | | |
| R-33 | Bendahara | Tagihan siswa | Bebaskan tagihan | Modal konfirmasi terbaca penuh | | | | |
| R-34 | Bendahara | Transaksi kas | Catat transaksi + unggah bukti | Form dan unggah muat di layar | | | | |
| R-35 | Bendahara | Laporan keuangan | Baca tren 6 bulan | Tabel tren menggulung sendiri, halaman tidak | | | | |
| R-36 | Admin Sekolah | Notifikasi | Buat & kirim pengumuman | Editor isi tidak melebihi layar | | | | |
| R-37 | Admin Sekolah | Tautan WhatsApp | Salin satu tautan | Tombol menumpuk pada layar sempit | | | | |
| R-38 | Guru | Penyusunan ujian CBT | Buat ujian, terbitkan | Form dan aksi terbitkan terjangkau | | | | |
| R-39 | Guru | Editor soal | Tambah soal + pilihan jawaban | Repeater tidak meluber; tombol tambah terlihat | | | | |
| R-40 | Guru | Tabel hasil CBT | **Masukkan ke Nilai** | Aksi terjangkau; konfirmasi terbaca | | | | |
| R-41 | Semua | Seluruh permukaan | Ganti ID ↔ EN | Tidak ada label EN yang memotong tata letak | | | | |

## 7. Batasan yang tetap terbuka

1. **Tidak ada verifikasi visual.** Seluruh §6 menunggu manusia dengan peramban.
2. **Internal Filament tidak diaudit.** Tabel dan form Filament responsif menurut
   vendornya; yang diaudit di sini hanya konfigurasi dan tampilan milik sendiri.
3. **Peramban sungguhan tidak diuji.** Safari iOS, Chrome Android, dan mode
   membaca belum pernah dibuka pada permukaan mana pun.
4. **Zoom dan ukuran teks besar** (200% teks, `prefers-reduced-motion`) di luar
   lingkup S9.4.
5. **Landscape pada ponsel** belum diperiksa.
