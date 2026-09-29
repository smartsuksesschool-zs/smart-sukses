# Approved Deviation Register

Perbedaan yang **sengaja** antara implementasi dan `docs/blueprint/`, yang
**sudah memiliki bukti keputusan**.

## Syarat masuk register ini

Satu baris hanya boleh ada di sini bila buktinya dapat ditunjuk: keputusan pemilik
yang tercatat, bukan alasan yang disusun implementasi sendiri.

**Butir di `docs/implementation-notes.md` bukan bukti persetujuan** — ia catatan
alasan teknis. Ia menjadi bukti hanya ketika butirnya sendiri mengutip keputusan
pemilik, dan kutipan itu yang dirujuk di kolom bukti.

Yang belum punya bukti persetujuan **tidak** ditulis di sini. Ia berstatus
`OWNER-DECISION` dan tinggal di [`owner-decisions.md`](owner-decisions.md) sebagai
pertanyaan. Tidak ada tanggal, nomor keputusan, atau persetujuan yang dikarang
untuk mengisi kolom yang kosong.

---

## AD-01 · PPDB-01 — Pendaftaran publik bermuara ke Google Form

| | |
| --- | --- |
| **Requirement ID** | PPDB-01 (`docs/blueprint/01-PRD.md:571`), AC-PPDB-02…04, AC-PPDB-13 |
| **Requirement asli** | Formulir pendaftaran publik di `/ppdb/[kode]` dengan 7 field dan nomor pendaftaran unik, dilayani aplikasi ini |
| **Implementasi sekarang** | `app/Http/Middleware/RedirectPpdbToConfiguredForm.php` mengalihkan `/ppdb` dan `/ppdb/{kode}` ke Google Form yang disetel pemilik pada `ppdb_url`. Alur PPDB internal **tidak dihapus** dan tetap berdiri sebagai cadangan |
| **Alasan** | Keputusan pemilik: pendaftaran publik bermuara ke satu alamat saja |
| **Bukti keputusan** | `docs/implementation-notes.md` butir 554, yang berbunyi "Keputusan pemilik M7.2". Perilakunya dipagari `tests/Feature/PublicSite/PpdbSingleEntryTest.php` |
| **Tanggal keputusan** | Tidak tercatat di repositori. Yang tercatat nomor keputusannya (M7.2), bukan tanggalnya — dan tanggal tidak dikarang untuk melengkapinya |
| **Dampak** | Selama `ppdb_url` terisi, `ppdb_registrations` **tidak menerima satu baris pun**. Akibatnya PPDB-03 (kelola pendaftar), PPDB-04 (link wa.me), dan PPDB-05 (konversi menjadi siswa) tetap berfungsi dan teruji tetapi **tanpa masukan**, dan AC-PPDB-12 (`converted_student_id`) tidak tercapai dalam praktik. Data pendaftar tinggal di sistem pihak lain: di luar `school_id`, di luar jejak audit, dan di luar backup repositori ini |
| **Reversibilitas** | **Tinggi, dan disengaja demikian.** Pengalihannya `302` dan bukan `301` — keputusannya berbunyi "untuk saat ini", dan pengalihan permanen tersimpan di peramban setiap pengunjung serta tidak dapat ditarik dari sisi server. Mengosongkan `ppdb_url` memulihkan formulir aplikasi seketika, tanpa deploy dan tanpa migration |
| **Status** | `APPROVED-DEVIATION` |

Satu hal yang perlu diketahui pemilik dan **belum** merupakan bagian dari
keputusan M7.2: bila formulir Google itu kelak ditutup, dipindah, atau kehabisan
kuota, pendaftar yang sudah masuk ke sana **tidak** ikut pindah ke aplikasi ini.
Yang kembali adalah formulirnya, bukan datanya.

---

## AD-02 · Halaman muka publik — penambahan langsung pemilik

| | |
| --- | --- |
| **Requirement ID** | Tidak ada. Blueprint tidak memuat halaman muka umum; `GET /ppdb/schools` yang dirujuk `04-api/05-ppdb.md:7` adalah daftar cabang PPDB, bukan halaman muka |
| **Requirement asli** | — (penambahan scope, bukan penyimpangan) |
| **Implementasi sekarang** | `app/Http/Controllers/LandingController.php`, `resources/views/landing.blade.php`, `Support/PublicSite.php`, model `SiteSetting` dan `SiteBlock` |
| **Alasan** | Permintaan langsung pemilik |
| **Bukti keputusan** | `docs/owner-scope-changes.md` §A, dan §E R-5 yang menyatakan penempatannya di `apps.smartsukses.sch.id/` sudah **terjawab** untuk penyerahan ini |
| **Dampak** | Permukaan publik tanpa autentikasi yang tidak ada di blueprint. Dipagari agar tidak membocorkan data cabang: `Landing/LandingPageTest::test_no_private_branch_data_reaches_the_page` dan `test_the_landing_page_never_wears_one_branch_white_label` |
| **Reversibilitas** | Sedang. Rute, view, dan dua tabel isi situs harus dilepas bersama; tidak ada data akademik yang bergantung padanya |
| **Status** | `APPROVED-DEVIATION` (penambahan scope yang disetujui) |

Yang **masih** menunggu pemilik pada penambahan ini tercatat di
`docs/owner-scope-changes.md` §E R-6: naskah halaman muka, dan data kontak yang
belum dapat ditampilkan karena sumbernya belum ada.

---

## AD-03 · CBT / ujian online — percepatan potongan Phase 2

| | |
| --- | --- |
| **Requirement ID** | Tidak ada di Phase 1. CBT adalah bagian Phase 2 pada `docs/blueprint/01-PRD.md` dan `02-ROADMAP.md` |
| **Requirement asli** | Phase 2 |
| **Implementasi sekarang** | Modul ujian: `Exam`, `ExamAttempt`, `ExamGradeBridge`, portal siswa `/siswa/ujian*` |
| **Alasan** | Permintaan pemilik untuk mempercepat sebagian CBT |
| **Bukti keputusan** | `docs/owner-scope-changes.md` §B, dan §D Q-1: "Yang diserahkan adalah **MVP CBT yang dipercepat**, bukan CBT Phase 2" |
| **Dampak** | Menambah permukaan fitur di luar Phase 1. Isolasi tenant dan policy-nya diuji tersendiri (`Cbt/ExamTenantIsolationTest`, `Cbt/ExamPolicyTest`) |
| **Reversibilitas** | Rendah. Sudah memiliki tabel dan data percobaan siswa; melepasnya berarti kehilangan data |
| **Status** | `APPROVED-DEVIATION` (percepatan scope yang disetujui) |

### Keputusan turunan yang ikut berbukti

Ketiganya dari `docs/owner-scope-changes.md` §D, dan dicatat di sini karena
masing-masing membatasi bentuk AD-03:

| # | Keputusan | Bukti |
| --- | --- | --- |
| AD-03a | Hasil CBT **tidak** otomatis menjadi nilai akademik; nilai hanya lahir dari tindakan guru | §D R-1 |
| AD-03b | Tipe nilainya memakai `GradeType`/`AssessmentType` yang sudah ada; bawaan `AssessmentType` = `FORMATIVE`; tidak ada `GradeType` baru | §D R-1a |
| AD-03c | Soal uraian **tidak** masuk rilis ini; pilihan ganda saja | §D R-3 |

---

## Yang **tidak** masuk register ini, dan mengapa

Empat hal sering dianggap deviasi yang sudah disetujui. Tidak ada satu pun yang
memiliki bukti keputusan pemilik, sehingga keempatnya tinggal di
[`owner-decisions.md`](owner-decisions.md):

| Hal | Mengapa bukan APPROVED-DEVIATION | Nomor |
| --- | --- | --- |
| `spatie/laravel-multitenancy` tidak dipasang, padahal CON-05 menyebut "wajib" | Tidak ada catatan pemilik. CON-55 justru menuntut perubahan teknologi diterbitkan sebagai versi blueprint baru | OD-01 |
| Google OAuth + klaim akun NIS/NISN | Tidak ada requirement-nya, dan tidak ada permintaan pemilik yang tercatat | OD-02 |
| `students.deleted_at` sedangkan `05-DATABASE.md` §3.6 menyatakan tidak ada tabel ber-`deleted_at` | Butir 589 menerangkan alasan teknisnya, dan alasan implementasi bukan persetujuan | OD-13 |
| Tailwind 4 sedangkan `01-PRD.md:83` menyebut Tailwind CSS 3 | Diketahui, tetapi tidak ada catatan keputusan di repositori | OD-17 |
