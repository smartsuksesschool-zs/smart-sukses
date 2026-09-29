# Owner Decision Register

Pertanyaan yang **tidak dapat dijawab oleh implementasi**, karena jawabannya
menentukan requirement dan bukan menentukan cara mengoding.

## Cara memakai dokumen ini

Setiap butir menyajikan pilihan **tanpa memilih**. Di tempat implementasi sudah
berjalan dengan salah satu bentuk, itu ditulis apa adanya sebagai keadaan sekarang
— bukan sebagai rekomendasi dan bukan sebagai keputusan yang sudah diambil.

Begitu sebuah butir dijawab, jawabannya pindah ke
[`approved-deviations.md`](approved-deviations.md) beserta buktinya, dan barisnya
di sini dihapus. Per **CON-55** (`docs/blueprint/01-PRD.md:354`), perubahan
signifikan pada scope, arsitektur, atau teknologi wajib diterbitkan sebagai versi
blueprint baru — jadi sebagian butir di bawah menuntut revisi blueprint, bukan
sekadar jawaban lisan.

Kolom **Memblokir rilis** menilai satu hal saja: apakah rilis akan menghasilkan
data salah atau kerusakan bila butir ini dibiarkan terbuka. Butir yang tidak
memblokir tetap perlu dijawab; ia hanya tidak perlu dijawab **hari ini**.

---

## Ringkasan

| # | Pertanyaan singkat | Memblokir rilis |
| --- | --- | --- |
| OD-01 | `spatie/laravel-multitenancy` wajib (CON-05) tetapi tidak dipasang | TIDAK |
| OD-02 | Google OAuth + klaim akun tidak ada di requirement mana pun | TIDAK |
| OD-03 | Sumber data kehadiran (RSK-01) | TIDAK |
| OD-04 | Rumus `rank_in_class` dan tie-break (RSK-09) | TIDAK |
| OD-05 | Perilaku generate tagihan `YEARLY` dan `ONCE` (RSK-10) | **BERSYARAT — YA** bila ada jenis tagihan non-bulanan |
| OD-06 | Jumlah nilai terbaru di dashboard ortu: 3 atau 5 (RSK-04) | TIDAK |
| OD-07 | Guru boleh membuat pengumuman atau tidak | TIDAK |
| OD-08 | Struktur, retensi, dan pembaca `audit_logs` (RSK-02) | TIDAK |
| OD-09 | Kosakata kategori notifikasi | TIDAK |
| OD-10 | Cara pengiriman sandi sementara | TIDAK |
| OD-11 | "Reset Password" pada akun siswa/orang tua | TIDAK |
| OD-12 | Semantik versi bobot penilaian (CON-42) | TIDAK |
| OD-13 | `students.deleted_at` vs `05-DATABASE.md` §3.6 | TIDAK |
| OD-14 | Aturan normalisasi nomor telepon (ASM-06) | TIDAK |
| OD-15 | Presedensi `grades.weight` vs `grade_configs.components` | TIDAK |
| OD-16 | Status `06-API.md`: kontrak atau dokumen desain | TIDAK |
| OD-17 | Tailwind 4 vs Tailwind CSS 3 pada PRD | TIDAK |
| OD-18 | Mekanisme koreksi/unpublish rapor | TIDAK |

---

## OD-01 · `spatie/laravel-multitenancy` diwajibkan tetapi tidak dipasang

**Pertanyaan.** CON-05 (`01-PRD.md:277`) berbunyi multi-tenancy **wajib**
menggunakan `spatie/laravel-multitenancy` 3.x. Paket itu tidak ada di
`composer.json`. Apakah CON-05 diubah, atau implementasinya yang harus diubah?

**Mengapa penting.** CON-05 memakai kata "wajib", dan CON-55 menuntut perubahan
teknologi diterbitkan sebagai versi blueprint baru. Selama tidak dijawab, ada
constraint blueprint yang dilanggar di atas kertas.

**Keadaan sekarang.** Isolasi ditulis tangan: `Models/Scopes/SchoolScope.php` +
`Concerns/BelongsToSchool.php`, dipakai 24 dari 28 model. Diuji 107 test isolasi
lintas cabang yang hijau di SQLite dan MySQL, termasuk akun tanpa cabang yang
dipaksa `1 = 0` dan penyelundupan ID lintas cabang. `03-USER_FLOW.md` §10.6 dan
`06-API.md` §9.4 juga menyebut `TenantMiddleware` dari paket itu; middleware
tersebut tidak ada — scope membaca `Auth::user()->school_id` per kueri.

**Pilihan.**

1. Ratifikasi implementasi sekarang, terbitkan CON-05 revisi yang menyebut
   `SchoolScope` sebagai mekanisme resmi.
2. Pasang paketnya dan pindahkan isolasi ke sana.
3. Pasang paketnya hanya sebagai pembungkus, biarkan `SchoolScope` yang bekerja.

**Yang berubah.** (1) hanya dokumen; kode tidak disentuh. (2) menyentuh setiap
model ber-tenant dan seluruh 107 test isolasi — pekerjaan besar dengan risiko
kebocoran tenant selama peralihan, dan tidak menambah satu pun kemampuan. (3)
menambah dependensi tanpa manfaat, dan membuat dua mekanisme hidup bersama.

---

## OD-02 · Google OAuth dan klaim akun tidak ada di requirement mana pun

**Pertanyaan.** Apakah masuk lewat Google dan pendaftaran mandiri dengan
NIS/NISN memang diinginkan? Bila ya, ia harus masuk blueprint. Bila tidak, ia
harus dilepas.

**Mengapa penting.** Seluruh 40 requirement Phase 1 tidak menyebutnya. Pencarian
`google|oauth|sso|socialite` di ketujuh dokumen sumber hanya menemukan **Google
Meet untuk LMS Phase 2** — tidak satu pun tentang autentikasi.
`docs/owner-scope-changes.md` juga tidak mencatatnya sebagai permintaan pemilik,
padahal landing page dan CBT tercatat di sana. Ini satu-satunya butir di dokumen
ini di mana "biarkan dulu" sendiri membawa risiko: ia jalan pembuatan akun
mandiri yang tidak dikenal satu pun tabel peran di blueprint.

**Keadaan sekarang.** Berjalan dan teruji: `GoogleAuthController`,
`Livewire/Auth/GoogleClaim`, `AccountClaimRegistrar/Reviewer`,
`StudentClaimMatcher`, enum `AccountClaim`/`AuthProvider`/`ClaimMatchOutcome`,
antrean persetujuan admin, 34 test termasuk penolakan surel belum terverifikasi
dan pagar lintas cabang. Tidak ada token Google yang disimpan.

**Pilihan.**

1. Ratifikasi sebagai requirement baru; terbitkan sebagai revisi blueprint
   (CON-55) beserta barisnya di matriks peran.
2. Matikan pintunya tanpa membuang kodenya (rute dan tombol dilepas, tabel
   tinggal).
3. Lepas seluruhnya beserta tabel dan migration-nya.

**Yang berubah.** (1) dokumen saja; kode sudah ada dan teruji. (2) satu berkas
rute dan satu tombol; dapat dibalik kapan pun; tabel `account_claims` menjadi
mati. (3) menghapus data klaim yang sudah ada — bila sudah ada akun yang lahir
dari jalur ini, penghapusannya menyentuh akun sungguhan.

---

## OD-03 · Sumber data kehadiran

**Pertanyaan.** Dari mana data kehadiran berasal pada Phase 1 — atau apakah
fitur kehadiran memang ditunda ke Phase 2?

**Mengapa penting.** `02-ROADMAP.md` mendaftarkannya sebagai **RSK-01**, risiko
kritis, dan menyebutnya "memblokir tiga fase". Blueprint menuntut data kehadiran
di Phase 1 sementara modul Presensi Digital ada di Phase 2 dan **tidak ada tabel
absensi di ERD**. Terdampak: SIS-04 AC-2, AC-PORTAL-02, dan `report_cards.attend_*`.

**Keadaan sekarang.** Mengikuti resep mitigasi RSK-01 apa adanya.
`ParentPortalService.php:511-532` mengembalikan bentuk eksplisit
`available: false, reason: 'attendance_source_not_available'`, dan rekap kehadiran
di PDF rapor tampil sebagai `—`. Resepnya berbunyi **"jangan mengarang sumber
data"**, dan itu dipatuhi. `02-ROADMAP.md:1163` juga melarang modul yang punya
blocker terbuka dinyatakan 100% DONE — sebab itu SIS-04 dan PORTAL-01 tidak
ditandai COMPLETE.

**Pilihan.**

1. Tunda ke Phase 2; nyatakan resmi bahwa kehadiran kosong pada Phase 1.
2. Sediakan modul presensi minimal di Phase 1 (tabel absensi + input guru).
3. Impor kehadiran dari sumber luar (mis. lembar kerja sekolah) secara berkala.

**Yang berubah.** (1) dokumen saja; kode sudah dalam keadaan yang benar. (2)
tabel baru, layar input guru, dan pengisian tiga tempat yang kini kosong —
pekerjaan fitur penuh. (3) perkakas impor plus keputusan siapa yang menjalankannya
dan seberapa sering.

---

## OD-04 · Rumus `rank_in_class` dan aturan tie-break

**Pertanyaan.** Bagaimana peringkat kelas dihitung, dan bagaimana nilai seri
dipisahkan?

**Mengapa penting.** **RSK-09**. Kolomnya ada di `report_cards` tetapi rumusnya
tidak dijelaskan di blueprint mana pun. Peringkat yang salah rumus lebih buruk
daripada peringkat yang kosong, karena ia dibaca orang tua sebagai fakta.

**Keadaan sekarang.** Mengikuti resep RSK-09: kolom dibiarkan kosong, PDF
menampilkan `—`. Resepnya berbunyi **"jangan menciptakan rumus sendiri"**.

**Pilihan.**

1. Biarkan kosong secara permanen; buang kolomnya dari rapor.
2. Tetapkan rumusnya (mis. urutan nilai akhir rata-rata) beserta aturan seri
   (peringkat sama, atau dipisah dengan kriteria tertentu).

**Yang berubah.** (1) satu baris di template PDF. (2) layanan perhitungan baru,
pengisian saat publish, dan test yang mengikat aturan serinya.

---

## OD-05 · Perilaku generate tagihan `YEARLY` dan `ONCE`

**Pertanyaan.** Ketika jenis tagihan berfrekuensi `YEARLY` atau `ONCE`
di-generate massal, berapa baris yang lahir dan untuk periode apa?

**Mengapa penting.** **RSK-10**, dan `01-PRD.md:1141` menyatakannya sendiri
sebagai "**Blocker terbuka** — modul tidak dapat dinyatakan lengkap untuk kedua
frekuensi tersebut". Berbeda dari OD-03 dan OD-04, blueprint **tidak** meresepkan
perilaku sementara di sini.

**Keadaan sekarang.** `Services/Finance/StudentFeeGenerator.php` **tidak pernah
membaca `fee_type.frequency`** — seluruh frekuensi diperlakukan seperti bulanan.
Jenis tagihan `YEARLY` karena itu dapat di-generate ulang sekali untuk setiap
string periode. Ini pilihan implementasi yang belum diratifikasi, dan satu-satunya
butir di dokumen ini yang dapat menghasilkan **tagihan salah kepada orang tua**.

**Pilihan.**

1. `YEARLY` menghasilkan satu tagihan per tahun ajaran; `ONCE` satu tagihan
   sepanjang masa sekolah siswa.
2. Tolak generate massal untuk kedua frekuensi itu; keduanya dibuat manual.
3. Buang kedua frekuensi dari pilihan sampai diputuskan.

**Yang berubah.** (1) pembacaan `frequency` di generator plus pagar anti-ganda
per frekuensi. (2) satu penjaga di generator dan pesan yang menerangkan. (3) satu
baris di enum pilihan, dan pemeriksaan apakah sudah ada jenis tagihan non-bulanan
yang terpakai.

**Catatan pemblokiran.** Butir ini memblokir rilis **hanya bila** sudah ada jenis
tagihan `YEARLY` atau `ONCE` di basis data produksi. Bila seluruh jenis tagihan
bulanan, ia tidak memblokir apa pun hari ini. Pemeriksaannya satu kueri, dan
sebaiknya dilakukan sebelum rilis.

---

## OD-06 · Tiga atau lima nilai terbaru di dashboard orang tua

**Pertanyaan.** Satu angka, bukan dua: 3 atau 5?

**Mengapa penting.** **RSK-04**. PORTAL-01 (`01-PRD.md:916`) menyebut 3 nilai
terbaru; ringkasan pada `06-API.md` menyebut 5 mapel.

**Keadaan sekarang.** Keduanya dilayani: `ParentPortalService` memakai
`DASHBOARD_SUBJECTS = 3` untuk dashboard dan `SUMMARY_SUBJECTS = 5` untuk
ringkasan API. Ini mengikuti resep RSK-04, yang menyuruh memakai angka pada user
story dan mencatatnya sebagai keputusan sementara.

**Pilihan.** (1) tetapkan 3. (2) tetapkan 5. (3) biarkan berbeda dan perbaiki
dokumennya agar menyebutkan keduanya secara sengaja.

**Yang berubah.** Satu konstanta dan test yang mengikatnya; dokumen yang kalah
harus dikoreksi.

---

## OD-07 · Bolehkah guru membuat pengumuman

**Pertanyaan.** PORTAL-02 (`01-PRD.md:931`) mendaftarkan shortcut "Buat
Pengumuman" di dashboard guru, sedangkan matriks izin (`01-PRD.md:395` dan
`06-API.md:2087`) memberi GURU tanda tidak boleh pada notifikasi. Mana yang
berlaku?

**Mengapa penting.** Dua bagian dokumen yang sama saling bertentangan, dan
`01-PRD.md:425`/`:1690` sudah mencatat pertentangan itu sebagai isu terbuka.

**Keadaan sekarang.** Kode berpihak pada matriks izin: shortcut-nya tidak ada,
dan GURU tidak memegang izin `notification.*`. Ini dipagari test, sehingga
mengubahnya berarti mengubah test yang sekarang menegaskan perilaku sempit itu.

**Pilihan.** (1) matriks yang berlaku; koreksi PORTAL-02. (2) PORTAL-02 yang
berlaku; beri GURU izin membuat pengumuman dan koreksi matriks. (3) hanya Wali
Kelas yang boleh, bukan semua guru.

**Yang berubah.** (1) satu baris dokumen. (2) izin di seeder, shortcut di
dashboard, dan test matriks izin. (3) sama seperti (2), ditambah pemisahan
GURU/WALI_KELAS yang `01-PRD.md` §8.5 sendiri belum tentukan.

---

## OD-08 · Struktur, retensi, dan pembaca `audit_logs`

**Pertanyaan.** Apa yang wajib tercatat, berapa lama disimpan, dan siapa yang
boleh membacanya?

**Mengapa penting.** **RSK-02**: tabel `audit_logs` disyaratkan NFR-12 dan §3.4
tetapi **tidak ada dalam 21 tabel ERD**, dan strukturnya tidak didefinisikan.
Resep RSK-02 menyuruh mengimplementasikan field yang disebut §3.4 sambil meminta
struktur resminya diterbitkan sebagai revisi blueprint.

**Keadaan sekarang.** Mengikuti resep: user, action, table, id, timestamp, IP.
Baris tidak dapat diubah, tanpa retensi, dan hanya Super Admin yang dapat membaca
(`AuditLogPolicy` meminjam izin `tenant.view`). Aksi baca sengaja tidak dicatat.
`Support/AuditLogger.php:62-67` mengecualikan `Role`, `Permission`, dan
`PersonalAccessToken`; `model_has_roles` tidak memicu event model sama sekali,
sehingga pemberian izin di luar seeder dan penerbitan/pencabutan token API tidak
meninggalkan baris audit.

**Pilihan.** (1) ratifikasi keadaan sekarang sebagai struktur resmi. (2) perluas
cakupan ke perubahan peran/izin/token. (3) tetapkan retensi dan siapa lagi yang
boleh membaca.

**Yang berubah.** (1) dokumen. (2) listener tambahan untuk tabel pivot — tidak
besar, tetapi menambah baris audit pada setiap perubahan peran. (3) perintah prune
baru plus penyesuaian policy.

---

## OD-09 · Kosakata kategori notifikasi

**Pertanyaan.** Daftar kategori mana yang resmi?

**Mengapa penting.** `01-PRD.md:861` menyebut `ACADEMIC/BILLING/EMERGENCY/GENERAL`;
ERD menyebut `ANNOUNCEMENT/BILLING/ACADEMIC/EMERGENCY/SYSTEM` (`01-PRD.md:1641`).

**Keadaan sekarang.** `GENERAL` diterima sebagai alias `ANNOUNCEMENT`, dan
`SYSTEM` tidak ditawarkan pada pembuatan manual. Keduanya dipagari test.

**Pilihan.** (1) pakai daftar PRD. (2) pakai daftar ERD. (3) ratifikasi alias
yang sekarang.

**Yang berubah.** Nilai enum, migrasi data bila ada baris berkategori lama, dan
label dua bahasa.

---

## OD-10 · Cara pengiriman sandi sementara

**Pertanyaan.** Sandi sementara dikirim ke penggunanya, atau diserahkan admin di
luar sistem?

**Mengapa penting.** PORTAL-04 (`01-PRD.md:961`), `03-USER_FLOW.md:927-929`, dan
`06-API.md` semuanya berbunyi sandi sementara "dikirim via notifikasi". Kanal
surelnya belum ada (`MAIL_MAILER=log`).

**Keadaan sekarang.** `UserResource.php:187-208` menampilkannya **di layar kepada
admin**, sekali, lewat notifikasi Filament yang persisten. Tidak ada yang dikirim
ke penggunanya.

**Pilihan.** (1) sediakan SMTP dan kirim sungguhan seperti bunyi requirement —
ini juga membuka AUTH-04. (2) ratifikasi penyerahan di luar sistem dan koreksi
ketiga dokumen agar tidak lagi berbunyi "dikirim via notifikasi". (3) kirim lewat
wa.me manual, seperti pola notifikasi lain di produk ini.

**Yang berubah.** (1) infrastruktur, bukan kode. (2) dokumen saja. (3)
pembuat tautan wa.me untuk sandi — dan perlu dipertimbangkan bahwa sandi menjadi
tertulis di riwayat percakapan.

---

## OD-11 · "Reset Password" pada akun siswa dan orang tua

**Pertanyaan.** Apakah tombol "Reset Password" boleh dipakai pada akun berperan
SISWA dan ORANG_TUA?

**Mengapa penting.** Aksi itu menyalakan `must_change_password` secara eksplisit,
dan `Support/PortalEligibility.php` menolak kedua peran itu masuk selama penanda
menyala. Staf punya jalan keluar — `EnsurePasswordIsChanged` mengalihkan mereka ke
halaman ganti sandi di dalam panel — sedangkan portal tidak punya halaman itu.
Akibatnya akun portal yang di-reset menjadi **tidak dapat masuk** sampai
sandinya disetel ulang lewat Ubah Pengguna.

**Keadaan sekarang.** `UserPolicy::resetPassword` sama dengan `update`, sehingga
tombol itu **tampil** untuk akun portal. Jalan pemulihannya ada dan bekerja: admin
menyetel sandi lewat Ubah Pengguna, dan `User::booted` melepas penanda pada
penyimpanan yang sama. Kalimat di layar sudah diperbaiki agar menunjuk admin
sekolah, bukan menunjuk tautan lupa sandi yang sengaja tidak ditampilkan.

**Pilihan.** (1) sembunyikan tombolnya untuk kedua peran portal. (2) biarkan
tampil, tambahkan peringatan pada dialog konfirmasinya. (3) beri portal halaman
ganti sandi sendiri sehingga penanda itu berfungsi seperti pada staf.

**Yang berubah.** (1) satu kondisi `visible()` plus test. (2) satu kalimat pada
modal. (3) halaman baru di tiga portal — pekerjaan fitur, dan ia mengubah alur
masuk yang sekarang teruji.

---

## OD-12 · Semantik versi bobot penilaian

**Pertanyaan.** Bolehkah konfigurasi bobot berubah di tengah tahun ajaran?

**Mengapa penting.** CON-42 (`01-PRD.md:334`) dan AC-NILAI-03 berbunyi perubahan
bobot **hanya berlaku untuk tahun ajaran baru**. Bobot yang berubah di tengah
tahun mengubah nilai akhir yang sudah dilihat orang tua.

**Keadaan sekarang.** `GradeConfigVersionManager` mengizinkan versi ACTIVE baru
**di dalam tahun ajaran yang sama** (`GradeConfigVersioningTest:265`), dan
penguncian baru terjadi setelah seluruh rapor dipublikasikan. Lebih longgar
daripada bunyi CON-42. Nilai yang sudah terhitung dilindungi lewat snapshot bobot,
sehingga nilai lama tidak berubah surut.

**Pilihan.** (1) tegakkan CON-42: tolak versi ACTIVE baru di TA yang sama. (2)
ratifikasi kelonggaran sekarang dan koreksi CON-42. (3) izinkan hanya sebelum ada
satu nilai pun masuk di TA itu.

**Yang berubah.** (1) satu penjaga di manajer versi, dan test yang sekarang
menegaskan kelonggaran harus dibalik. (2) dokumen. (3) penjaga bersyarat plus test.

---

## OD-13 · `students.deleted_at` sedangkan ERD menyatakan tidak ada

**Pertanyaan.** Apakah arsip lewat `deleted_at` adalah bacaan resmi dari
"nonaktifkan siswa", atau harus memakai nilai enum `INACTIVE`?

**Mengapa penting.** `05-DATABASE.md` §3.6 menyatakan "**tidak ada satu pun
tabel** dalam 21 entitas" yang memiliki `deleted_at`. Sementara itu CON-45 dan
AC-M0-13 melarang penghapusan siswa, dan §3.6 serta §8.3 dokumen yang sama
**mengakui kesenjangannya sendiri**: API Map menyebut `DELETE /transactions/{id}`
sebagai soft delete padahal tidak ada kolomnya, dan mekanismenya "belum
dijelaskan". Jadi implementasi mengisi kekosongan yang dokumen akui, bukan
melawan aturan yang dokumen tegakkan.

**Keadaan sekarang.** `students.deleted_at` ada; arsip dan pulihkan berjalan;
penghapusan permanen tidak tersedia di panel mana pun dan
`StudentPolicy::forceDelete` mengembalikan `false`. Status akademik tidak disentuh
oleh arsip. Alasan teknisnya di butir 589 — dan itu alasan implementasi, bukan
persetujuan.

**Pilihan.** (1) ratifikasi arsip sebagai mekanisme resmi; perbarui §3.6. (2)
tambahkan nilai enum `INACTIVE` dan pakai itu, bukan `deleted_at`. (3) pakai
keduanya dengan arti yang dipisahkan tegas.

**Yang berubah.** (1) dokumen. (2) migration enum, migrasi data baris terarsip,
dan pengubahan setiap kueri yang sekarang bersandar pada global scope — besar, dan
menyentuh modul yang baru saja distabilkan. (3) menambah satu keadaan yang harus
dijelaskan ke pengguna.

---

## OD-14 · Aturan normalisasi nomor telepon

**Pertanyaan.** Bagaimana nomor telepon dinormalkan untuk tautan wa.me, dan apa
yang dilakukan pada nomor yang tidak dikenali?

**Mengapa penting.** ASM-06 (`01-PRD.md:236`) menyatakan aturannya "belum
dijelaskan", sedangkan setiap notifikasi produk ini dikirim lewat wa.me. ASM-05
juga belum menjawab apa yang dilakukan untuk penerima yang tidak memakai WhatsApp.

**Keadaan sekarang.** `Support/WhatsAppLink.php:46-71` memutuskan sendiri: buang
non-digit; `0…` menjadi `62…`; `8…` menjadi `62 8…`; angka awal lain menghasilkan
**null** sehingga tautannya tidak dibuat; minimal 11 digit. Teruji.

**Pilihan.** (1) ratifikasi aturan sekarang. (2) tetapkan aturan lain. (3)
tetapkan pula perlakuan untuk penerima tanpa WhatsApp.

**Yang berubah.** (1) dokumen. (2) satu helper dan test-nya. (3) kanal alternatif
— pekerjaan fitur.

---

## OD-15 · Presedensi `grades.weight` vs `grade_configs.components`

**Pertanyaan.** Bila keduanya menyebut bobot, mana yang menang?

**Mengapa penting.** `05-DATABASE.md:1148` dan `03-USER_FLOW.md:1335`
membiarkannya terbuka, dan jawabannya menentukan angka nilai akhir.

**Keadaan sekarang.** Snapshot yang menang: `GradeWeightSnapshotter` menyimpan
bobot saat nilai dihitung, diuji di `FinalScoreCalculationTest:220`.

**Pilihan.** (1) ratifikasi snapshot-menang. (2) `grades.weight` selalu menang.
(3) `grade_configs` selalu menang, hitung ulang saat konfigurasi berubah.

**Yang berubah.** (1) dokumen. (2)/(3) perhitungan ulang nilai yang sudah ada —
dan (3) membuat nilai lama dapat berubah surut.

---

## OD-16 · Status `06-API.md`: kontrak atau dokumen desain

**Pertanyaan.** Apakah 101 endpoint pada `06-API.md` merupakan deliverable yang
harus ada, atau peta desain dari blueprint?

**Mengapa penting.** Dokumen itu menyatakan sendiri "Turunan blueprint. Tidak
menambah endpoint, requirement, maupun aturan baru", sedangkan CON-02 dan CON-03
mewajibkan Filament dan Livewire — artinya requirement fungsional memang dipenuhi
lewat panel dan portal, bukan lewat REST. Selama status ini kabur, setiap audit
akan terus melaporkan "endpoint hilang" untuk requirement yang sudah terpenuhi.

**Keadaan sekarang.** `routes/api.php` memuat **41 rute** — autentikasi (3 dari 8),
keuangan (13, lengkap), notifikasi (8, lengkap), portal (10), admin (2). Modul
siswa, kelas, mapel, jadwal, nilai, dan rapor tidak punya REST; keduanya dilayani
Filament. PPDB punya halaman Livewire, bukan endpoint.

**Pilihan.** (1) nyatakan `06-API.md` sebagai dokumen desain; endpoint yang belum
ada bukan kewajiban. (2) nyatakan ia kontrak; endpoint yang belum ada menjadi
pekerjaan Phase 1. (3) nyatakan ia kontrak untuk klien masa depan (mis. aplikasi
seluler) dan jadwalkan ke Phase 2.

**Yang berubah.** (1) satu kalimat status di dokumen, dan audit berhenti
melaporkannya. (2) puluhan endpoint, policy, dan test baru — pekerjaan sebesar
beberapa sprint, tanpa satu pun pengguna yang memintanya hari ini. (3) dokumen
plus baris roadmap.

---

## OD-17 · Tailwind 4 sedangkan PRD menyebut Tailwind CSS 3

**Pertanyaan.** Versi mana yang resmi?

**Mengapa penting.** `01-PRD.md:83` mencantumkan Tailwind CSS 3; `package.json`
memuat `tailwindcss ^4.0.0`. CON-55 menuntut perubahan teknologi diterbitkan
sebagai versi blueprint baru.

**Keadaan sekarang.** Dampaknya kecil dan perlu diketahui sebelum butir ini
dianggap penting: **tidak ada satu view pun yang memuat `@vite`**, tidak ada
`public/build`, dan runbook produksi tidak menyebut Node maupun npm. Artinya
portal memang berjalan tanpa build pipeline — CON-03 terpenuhi — dan Tailwind
tinggal sebagai dependensi pengembangan yang tidak dipakai oleh halaman yang
disajikan.

**Pilihan.** (1) perbarui PRD ke Tailwind 4. (2) turunkan ke Tailwind 3. (3)
lepas Tailwind dan Vite seluruhnya, karena tidak dipakai.

**Yang berubah.** (1) satu baris dokumen. (2) penyesuaian konfigurasi tanpa
manfaat yang terlihat. (3) `package.json` dan berkas konfigurasi; tidak ada
halaman yang terpengaruh.

---

## OD-18 · Mekanisme koreksi rapor yang sudah dipublikasikan

**Pertanyaan.** Bagaimana rapor yang sudah dipublikasikan dikoreksi bila
nilainya ternyata salah?

**Mengapa penting.** `05-DATABASE.md:1214` mencatat mekanismenya belum
dijelaskan. CON-40 mengunci nilai setelah publish, sehingga saat ini kekeliruan
tidak punya jalan keluar di dalam sistem.

**Keadaan sekarang.** Tidak ada unpublish dan tidak ada koreksi. Setelah publish,
nilai terkunci permanen (`Grade::isLocked()`, `GradePolicy`).

**Pilihan.** (1) izinkan Wali Kelas menarik publikasi dengan jejak audit. (2)
izinkan koreksi hanya oleh Admin Sekolah dengan alasan tertulis. (3) tetapkan
bahwa koreksi dilakukan di luar sistem lewat rapor pengganti.

**Yang berubah.** (1)/(2) membuka kembali kunci CON-40 — perlu dipastikan
konsistensinya dengan nilai yang sudah dilihat orang tua. (3) dokumen dan
prosedur, tanpa kode.

---

# Blocker infrastruktur

Bukan keputusan pemilik dan bukan pekerjaan koding. Dicatat di sini supaya tidak
tercampur dengan kesenjangan requirement.

| # | Hal | Akibat selama terbuka |
| --- | --- | --- |
| OD-Infra-1 | SMTP belum ada (`MAIL_MAILER=log`) | AUTH-04 tidak dapat dipakai pengguna; sandi sementara tidak dapat dikirim. URL reset tertulis ke log, sehingga akses log harus tetap dibatasi |
| OD-Infra-2 | TLS di server belum dipasang | Cookie `Secure` tidak pernah terkirim; HSTS tidak berlaku |
| OD-Infra-3 | Belum terbukti `schedule:run` berjalan di server | `notifications:prune` (retensi 90 hari, NOTIF-04) tidak berjalan. Registrasinya **aktif** di `routes/console.php` dan entri cron ada di `ops/smartsukses-cron`; yang belum ada buktinya di server |
| OD-Infra-4 | Backup terjadwal belum terbukti berjalan di server | Kedua skrip baru diuji dijalankan tangan |
| OD-Infra-5 | Pemulihan berkas `storage/app/*` belum pernah diuji | Jalur pemulihan berkas belum terbukti; basis data saja memulihkan baris yang menunjuk berkas yang tidak ada |
| OD-Infra-6 | Uji beban 200 pengguna belum pernah dijalankan | Checklist A.3 butir 3; lima skrip k6 siap di `ops/load-tests/` |
| OD-Infra-7 | Pemantauan uptime belum dikonfigurasi | `GET /up` tersedia, belum diarahkan ke mana pun |
| OD-Infra-8 | Dokumen PPDB lama masih di disk publik | Privasi penuh tercapai setelah `ppdb:privatize-documents --apply` dijalankan di lingkungan sasaran. Prosedurnya lengkap di `docs/ppdb-document-storage.md` §5-6 |
| OD-Infra-9 | Railway tidak menjalankan cron dan `healthcheckPath` NULL | Bila Railway tetap dipakai, tidak ada penjadwal dan tidak ada jaring pengaman saat deploy gagal |
| OD-Infra-10 | MySQL Railway berjalan dengan `--disable-log-bin` | Tidak ada point-in-time recovery; titik pulih hanya dump harian |
