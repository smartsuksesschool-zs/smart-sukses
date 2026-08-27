# Perubahan Scope atas Permintaan Pemilik

Dokumen ini mencatat pekerjaan yang **tidak** berasal dari blueprint, melainkan dari
permintaan langsung pemilik setelah blueprint ditulis.

`smartsukses-docs/` adalah sumber yang tidak diubah. Blueprint v1.0.0 tetap berbunyi
seperti aslinya, dan tidak ada satu barisnya pun yang disunting untuk mencocokkan
keadaan. Yang berubah dicatat di sini, di sisi implementasi, lengkap dengan asal-usulnya
— supaya kelak dapat dibedakan mana yang memang dirancang sejak awal dan mana yang
datang belakangan.

---

## A. Landing page publik — **tambahan langsung pemilik**

**Asal:** permintaan langsung Pak Akbar (Agustus 2026).

**Status di blueprint:** **tidak ada.**

Frasa "landing page" hanya muncul satu kali di seluruh `smartsukses-docs/`, yaitu pada
`04-api/05-ppdb.md:7` — `GET /ppdb/schools`, "Daftar cabang yang membuka PPDB (untuk
landing page publik)". Yang dimaksud di sana adalah daftar cabang PPDB, dan halaman itu
sudah ada sejak Sprint 3 di `/ppdb`. Halaman muka umum untuk sekolahnya **bukan** itu,
dan tidak pernah didefinisikan Phase 1.

**Karena itu:** landing page umum adalah **penambahan scope**, bukan penyelesaian
kekurangan. Tidak boleh disebut bagian dari Phase 1.

**Keadaan `/` sekarang:** **terkirim pada Batch L1.** Halaman bawaan Laravel diganti
halaman muka publik Smart Sukses School, dan `resources/views/welcome.blade.php` dihapus.

| | |
| --- | --- |
| Rute | `GET /` → `LandingController`, bernama `landing` |
| Tata letak | `resources/views/layouts/landing.blade.php` |
| Halaman | `resources/views/landing.blade.php` |
| Isi | navbar · hero · tentang · fitur · akses pengguna · cabang/PPDB · ajakan · footer |
| Pintu masuk | `/admin/login` · `/siswa/masuk` · `/portal/masuk` · `/ppdb` |
| Cabang | `School::active()`, semantik yang sama dengan halaman PPDB publik |
| CSS | ditulis tangan, inline, tanpa Vite — `npm run build` **tidak** dibutuhkan |
| Warna | konstanta platform, bukan white-label cabang mana pun |
| Naskah | naskah implementasi; pemilik belum menyerahkan teks pemasaran |
| Logo | tidak ada berkas logo di repository, dan tidak ada yang dikarang — dipakai wordmark |

Yang **tidak** ada, dan tidak boleh ditambahkan tanpa sumbernya: jumlah sekolah, jumlah
pengguna, persentase keberhasilan, testimoni, penghargaan, nama mitra, nomor telepon,
alamat surel, dan alamat kantor.

Rinciannya di [`implementation-notes.md`](implementation-notes.md) butir 344–353.

---

## B. CBT / ujian online — **percepatan potongan Phase 2**

**Asal:** permintaan langsung Pak Akbar (Agustus 2026) agar MVP-nya dikerjakan sebelum
penyerahan.

**Status di blueprint:** ada, dan **bukan** Phase 1.

`01-prd/03-phase2-overview.md` mencantumkannya:

| Modul | Fitur Utama | Estimasi Durasi |
| --- | --- | --- |
| LMS — CBT (Ujian Online) | Buat soal (pilihan ganda, essay), kunci otomatis, hasil real-time | 6–8 minggu |

Dokumen yang sama membuka dengan: *"Fitur-fitur berikut tidak termasuk dalam scope MVP
(Phase 1). Roadmap Phase 2 dapat dimulai setelah Phase 1 stabil dan telah digunakan
minimal 3 bulan."*

**Karena itu, tiga hal yang harus dinyatakan lurus:**

1. Yang dikerjakan adalah **potongan** CBT, bukan CBT Phase 2. Estimasi sumbernya 6–8
   minggu; yang dikerjakan di sini jauh lebih kecil dan sengaja demikian.
2. Percepatan ini mendahului dua syarat yang ditulis sumbernya sendiri — Phase 1 stabil,
   dan sudah dipakai tiga bulan. Itu keputusan pemilik, dicatat apa adanya di sini,
   bukan keputusan implementasi.
3. Batasannya ada di [`cbt-mvp-scope.md`](cbt-mvp-scope.md), dan CBT ini **tidak boleh**
   disebut selesai dengan menunjuk baris Phase 2 di atas.

---

## C. Hubungannya dengan Sprint 9

`05-roadmap/01-implementation-order.md` mendefinisikan Sprint 9 sebagai
**"Polish & QA — Bilingual (EN), Responsive mobile, Load testing, Security audit, Bug
fixing"**.

Landing page dan CBT **bukan** itu. Keduanya dikerjakan **mendahului** Sprint 9, atas
permintaan pemilik, dan Sprint 9 sebagaimana didefinisikan sumbernya tetap belum
dikerjakan. Menyebut pekerjaan ini "Sprint 9" akan membuat isi Sprint 9 yang sebenarnya
tampak sudah tertutup padahal belum tersentuh.

Penamaan yang dipakai: **Batch C1, C2, …** — C untuk CBT, terlepas dari penomoran sprint.

---

## D. Keputusan pemilik yang sudah diambil

Diambil setelah audit pra-implementasi, dan berlaku sebagai keputusan — bukan tafsiran
implementasi:

| # | Pertanyaan | Keputusan |
| --- | --- | --- |
| R-1 | Apakah hasil CBT otomatis menjadi nilai akademik? | **Tidak.** Pengumpulan siswa langsung menghitung dan menyimpan hasilnya, dan hasil itu langsung terlihat di permukaan CBT. Nilai akademik hanya lahir dari tindakan guru "Masukkan ke Nilai" nanti. |
| R-1a | Tipe nilainya? | `GradeType` dan `AssessmentType` yang **sudah ada**. Tidak ada GradeType baru. Bawaan `AssessmentType` = **FORMATIVE**. |
| R-3 | Soal uraian di rilis ini? | **Tidak.** Pilihan ganda saja. Uraian tetap scope Phase 2 yang belum dikerjakan. |
| Q-1 | Scope vs tenggat | Yang diserahkan adalah **MVP CBT yang dipercepat**, bukan CBT Phase 2. |

---

## E. Yang masih menunggu pemilik

Belum dijawab, dan **tidak** diputuskan sendiri oleh implementasi:

| # | Pertanyaan |
| --- | --- |
| R-2 | Bolehkah Admin Sekolah membuat soal ujian, atau hanya guru pengampunya? Batch C1 mengikuti arsitektur yang berlaku sekarang — matriks PRD 1.1.2 memberi Admin Sekolah ✅ penuh pada Input Nilai — dan itu dicatat sebagai mengikuti preseden, bukan sebagai keputusan pemilik. |
| R-4 | Apakah "satu percobaan per ujian" sesuai dengan cara sekolahnya bekerja? Bawaan implementasi menyatakan satu, tanpa reset. Kebutuhan mengulang karena koneksi putus adalah fitur tersendiri. |
| ~~R-5~~ | **Terjawab untuk penyerahan ini.** Halaman muka ditempatkan di `apps.smartsukses.sch.id/`, yaitu satu-satunya domain aplikasi yang sudah didefinisikan. Tidak ada arsitektur domain apex baru yang ditambahkan. Bila pemilik kelak menyediakan domain pemasaran tersendiri, pemindahannya pekerjaan terpisah. |
| R-6 | Naskah landing page. Yang terpasang sekarang naskah implementasi. Cabang ditampilkan otomatis dari cabang aktif. Kontak **belum** ada karena tidak ada sumbernya — bila pemilik menghendaki telepon/surel/alamat tampil, datanya harus disediakan lebih dulu. |
