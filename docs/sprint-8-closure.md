# Penutupan Sprint 8 — Notifikasi

Ringkasan status implementasi Sprint 8 (NOTIF-01 sampai NOTIF-04) beserta buktinya.
Alasan tiap keputusan ada di [`implementation-notes.md`](implementation-notes.md)
butir 191–261; berkas ini hanya menyatakan **apa yang sudah ada, apa yang belum, dan
atas dasar apa**.

Dokumen sumber di `smartsukses-docs/` tidak diubah oleh satu batch Sprint 8 pun.

## Baseline saat penutupan

| | |
| --- | --- |
| Rute API | **41** (33 saat penutupan Sprint 7 → 40 di Batch 8.1 → 41 di Batch 8.4) |
| Rute web portal | orang tua **8** · guru **5** · siswa **8** |
| Halaman panel baru | Notifikasi Saya, Daftar Link WhatsApp, Template WhatsApp |
| Migrasi | **29 Ran, 0 pending** — 2 ditambahkan sepanjang Sprint 8, keduanya di Batch 8.1 |
| Perintah artisan baru | `notifications:prune` |
| Entri penjadwal | 1 (`notifications:prune`, harian) |

Angka test terakhir tercantum di bagian [Bukti test](#bukti-test).

## Batch Sprint 8

| Batch | Isi | Butir |
| --- | --- | --- |
| 8.1 | Fondasi notifikasi: skema, resolver penerima, NotificationCenter, API 4.10 | 191–205 |
| 8.2 | Kotak masuk tiga portal, lencana, tandai-baca | 206–216 |
| 8.3 | Kotak masuk penerima di panel admin | 217–221 |
| 8.4 | Tautan wa.me per penerima (NOTIF-02) | 222–233 |
| 8.5 | Tiga trigger otomatis + editor template (NOTIF-03) | 234–251 |
| 8.6 | Retensi 90 hari + penutupan | 252–261 |

## NOTIF-01 — Pengumuman manual bertarget

| Kriteria | Status | Bukti |
| --- | --- | --- |
| Field judul, isi, target, kategori | DONE | `NotificationFoundationTest`, `AnnouncementPanelTest` |
| Target ALL → semua pengguna **aktif** cabang | DONE | `NotificationFoundationTest` |
| Target CLASS → **hanya orang tua** siswa kelas itu | DONE | `NotificationFoundationTest`, `NotificationWaLinkTest` |
| Target INDIVIDUAL → satu pengguna cabang yang sama | DONE | `NotificationFoundationTest` |
| Draf dan terkirim terpisah | DONE | `AnnouncementPanelTest`, `NotificationApiTest` |

Kategori memakai enum ERD (ANNOUNCEMENT/BILLING/ACADEMIC/EMERGENCY/SYSTEM); "GENERAL"
yang disebut NOTIF-01 diterima sebagai alias masukan dan dinormalkan ke ANNOUNCEMENT
(butir 191). SYSTEM tidak dapat dipilih manual.

Kewenangan: `notification.manage` — SUPER_ADMIN, SCHOOL_ADMIN, KEPALA_SEKOLAH, sesuai
matriks 1.1.2 baris "Notifikasi (buat)" (butir 201).

## NOTIF-02 — Daftar link wa.me per penerima

| Kriteria | Status | Bukti |
| --- | --- | --- |
| URL `wa.me/62[nomorHP]?text=[pesan_ter-encode]` | DONE | `NotificationWaLinkTest`, `PpdbWaLinkTest` |
| Daftar dapat difilter | DONE | filter `search` + `availability` |
| Disalin satu per satu | DONE | satu tombol Salin per penerima |
| Tombol "Buka WA" | DONE | tautan `target="_blank" rel="noopener noreferrer"` |

`GET /api/v1/notifications/{id}/wa-links` — permukaan API 40 → 41.

Kewenangan **SUPER_ADMIN + SCHOOL_ADMIN saja**; Kepala Sekolah ditolak. Pemetaan peran
menempatkan NOTIF-02 pada Admin Sekolah, dan API 4.1 mendefinisikan Auth Level "Admin"
sebagai SCHOOL_ADMIN/SUPER_ADMIN. Pengecualian butir 201 yang meluluskan Kepala pada
NOTIF-01 **berhenti di NOTIF-01** (butir 223).

Penerima tanpa nomor yang dapat dipakai tetap muncul di daftar dengan alasannya —
`MISSING_PHONE`, `INVALID_PHONE`, `AMBIGUOUS_PHONE` — dan ringkasannya selalu
menghitung seluruh penerima, bukan hanya yang lolos filter (butir 226).

Pengirimannya **manual**. Tidak ada WhatsApp API, tidak ada antrean kirim, dan tidak
ada apa pun yang ditandai "sudah terkirim ke WhatsApp" (butir 228).

## NOTIF-03 — Trigger otomatis & template cabang

| Kriteria | Status | Bukti |
| --- | --- | --- |
| Trigger: status PPDB berubah | DONE untuk penerima yang dapat diwakili | `AutomaticNotificationTest` |
| Trigger: tagihan baru terbit | DONE | `AutomaticNotificationTest` |
| Trigger: rapor diterbitkan | DONE | `AutomaticNotificationTest` |
| Template dapat diedit Admin Sekolah | DONE | `WaTemplateSettingsTest` |
| Muncul di notification center + wa.me tersedia | **SEBAGIAN** — lihat celah di bawah | `AutomaticNotificationTest` |

Titik integrasinya tepat tiga, seluruhnya di jalur bisnis yang sudah ada:
`PpdbStatusUpdater::update()`, `StudentFeeGenerator::issue()`,
`ReportCardGenerator::publish()`. Tidak ada trigger keempat.

Notifikasi otomatis: `sender_id` NULL (penanda ERD untuk asal-sistem, tanpa akun
palsu), selalu terkirim dan tidak pernah draf, `target_type` INDIVIDUAL dengan
`target_id` sebuah `users.id` nyata. Teksnya **dipotret** ke `notifications.wa_template`
saat kejadian — mengubah template cabang sesudahnya tidak mengubah pesan yang sudah
terbit (butir 241).

Kewenangan editor template: **SUPER_ADMIN + SCHOOL_ADMIN saja** (butir 249).

## NOTIF-04 — Notification center penerima

| Kriteria | Status | Bukti |
| --- | --- | --- |
| Badge jumlah belum dibaca | DONE | tiga portal + panel |
| Klik menandai terbaca | DONE | idempoten, `read_at` pertama terjaga |
| Riwayat tersimpan 90 hari | DONE | `NotificationRetentionTest` |

Cakupan permukaan penerima:

| Permukaan | Status | Bukti |
| --- | --- | --- |
| Portal orang tua | DONE | `ParentNotificationTest` |
| Portal guru | DONE | `TeacherNotificationTest` |
| Portal siswa | DONE | `StudentNotificationTest` |
| Panel admin ("Notifikasi Saya") | DONE | `PanelNotificationCenterTest` |
| API 4.10 | DONE | `NotificationApiTest` |

NOTIF-04 tidak menyebut peran — yang menentukan penerimanya adalah penargetan — sehingga
Admin Sekolah, Kepala Sekolah, dan Bendahara yang menerima pengumuman ALL cabangnya
juga punya tempat membacanya (butir 217). Halaman itu **tidak** dipagari izin sama
sekali; yang menjaga isinya kepenerimaan (butir 218).

Retensi: `notifications:prune`, harian, lintas cabang, menghapus permanen notifikasi
terkirim yang `sent_at`-nya lebih tua dari 90 hari. Draf tidak pernah ikut.
`notification_reads` ikut lewat cascade FK. Ambangnya hidup di satu tempat — tidak ada
penyaring per-permintaan (butir 252–260).

## Keamanan

| Keputusan | Butir |
| --- | --- |
| Kepenerimaan, bukan izin, yang menentukan siapa boleh membaca sebuah notifikasi | 203 |
| Notifikasi cabang lain berakhir 404, bukan 403 | 116, 227 |
| Super Admin melewati `Gate::before` dan `SchoolScope` dan tetap bukan penerima | 220 |
| Kewenangan wa-links diperiksa **sebelum** satu nomor pun dibaca | 227 |
| Daftar-putih respons wa-links di satu tempat, dipakai API dan panel | 227 |
| Nomor berkode negara lain ditolak, tidak diberi awalan 62 | 222 |
| `target_id` otomatis tidak pernah diisi id entitas selain `users` | 240 |
| Penerima lintas cabang tidak pernah dipilih notifikasi otomatis | 243 |
| Pelonggaran scope retensi sempit — hanya di dalam service-nya | 256 |

## Performa

| Temuan | Butir |
| --- | --- |
| Umpan penerima: satu subquery keadaan-baca, bukan satu query per baris | 197 |
| Lencana panel: satu query hitungan per halaman | 219 |
| wa-links: dua query apa pun jumlah penerimanya | 230 |
| Penerbitan tagihan massal: cabang dan orang tua dibaca sekali per angkatan | 245 |
| Retensi: pembacaan per putaran, bukan per baris | 257 |

Seluruhnya dijaga test hitungan query yang menuntut angka **sama**, bukan sekadar
"tidak terlalu besar".

## Bukti test

| Suite | Isi |
| --- | --- |
| `NotificationFoundationTest` | resolver penerima, NotificationCenter, draf |
| `AnnouncementPanelTest` | pembuatan & pengiriman dari panel |
| `NotificationApiTest` | API 4.10 |
| `PortalNotificationIntegrationTest` | konsistensi lintas permukaan |
| `ParentNotificationTest` · `TeacherNotificationTest` · `StudentNotificationTest` | kotak masuk tiga portal |
| `PanelNotificationCenterTest` | kotak masuk panel, matriks peran |
| `NotificationWaLinkTest` | NOTIF-02 penuh |
| `AutomaticNotificationTest` | tiga trigger lewat jalur bisnis nyata |
| `WaTemplateSettingsTest` | editor template, matriks peran |
| `NotificationRetentionTest` | batas, draf, cascade, lintas cabang, penjadwal |

## Daftar penundaan

### A. Celah penerima eksternal NOTIF-03 — **butuh keputusan pemilik/skema**

`notifications.target_id` pada target INDIVIDUAL berarti `users.id`, dan hanya itu.
Sementara itu:

- `ppdb_registrations` **tidak menyimpan satu pun rujukan ke `users`** — kolomnya
  `parent_name`, `parent_phone`, `parent_email`. Calon siswa sebelum enroll karena itu
  **normalnya** tidak punya akun sama sekali. Alur enroll pun tidak membuat akun orang
  tua maupun mengisi `students.parent_user_id`.
- `students.parent_user_id` nullable, sehingga sebagian orang tua penerima tagihan dan
  rapor juga tidak punya akun portal.

Bagi mereka, sisi in-app NOTIF-03 **tidak ada**. Yang dilakukan: tidak ada baris
notifikasi yang ditulis sama sekali — bukan id pendaftaran, bukan id siswa, bukan
target ALL, bukan akun siswa sebagai pengganti, dan bukan akun palsu. Kejadian bisnisnya
tetap berjalan.

Kanal manualnya tetap ada: untuk PPDB, tautan wa.me per pendaftar dari Sprint 3 yang
membaca `parent_phone` langsung dan tidak membutuhkan akun.

Menutupnya menuntut perubahan skema — kolom penerima eksternal, atau akun portal yang
dibuat lebih awal pada alur PPDB — dan keduanya keputusan pemilik. Sprint 8 **tidak**
menambahkannya (butir 240).

### B. Shortcut "Buat Pengumuman" pada dasbor guru — **butuh keputusan pemilik**

PORTAL-02 poin 2 menyebut shortcut "Buat Pengumuman" di dasbor guru, sedangkan matriks
1.1.2 baris "Notifikasi (buat)" menandai GURU dan WALI_KELAS ❌. Konfliknya belum
diselesaikan dan Sprint 8 **tidak** menyelesaikannya.

Shortcut-nya tetap **nonaktif**, dan tidak ada kewenangan yang dilebarkan: Guru dan Wali
Kelas tetap ditolak `NotificationResource`, tetap ditolak `POST /notifications`, dan
tetap ditolak daftar wa.me. Yang mereka punya hanya kotak masuk sebagai penerima
(butir 213).

### C. Normalisasi nomor HP — pengerasan implementasi

Aturan normalisasi `+62`/`0`/`62`/pemisah, dan penolakan nomor berkode negara lain,
seluruhnya **aturan implementasi**. Blueprint hanya menuliskan bentuk URL-nya dan tidak
pernah menyebut bagaimana masing-masing bentuk diperlakukan. Satu cacat Sprint 3
diperbaiki di Batch 8.4 dan didokumentasikan sebagai perbaikan cacat (butir 222).

### D. Kosakata placeholder SPP & rapor — tidak didefinisikan sumber

Hanya `[nama]`, `[sekolah]`, `[ortu]` yang disediakan, yaitu token yang maknanya sudah
ditetapkan PPDB. Token nominal, jatuh tempo, periode, dan semester **tidak** disediakan
karena tidak ada sumber yang mendefinisikannya. Konsekuensinya: sekolah yang menulis
templatenya sendiri tidak dapat mencantumkan nominal pada pesan WhatsApp-nya
(butir 238).

### E. Kebutuhan deployment — entri cron

Penjadwal Laravel menuntut satu entri cron di server:
`* * * * * php artisan schedule:run`. Tanpa itu `notifications:prune` tidak akan pernah
berjalan dan retensi tidak terjadi — tanpa error, riwayatnya hanya tumbuh terus. VPS
produksi **tidak** dikonfigurasi pada Sprint 8 (butir 259).

### F. Isu di luar Sprint 8

Isu terbuka pada landing page, absensi, dan penilaian (F-1/F-3/F-4/F-5) **tidak**
disentuh Sprint 8 dan tetap terbuka sebagaimana adanya. Tidak ada satu pun di antaranya
yang diselesaikan atau boleh dianggap selesai oleh dokumen ini.

## Verdict

> **SPRINT 8 IMPLEMENTATION COMPLETE FOR THE CURRENT NOTIFICATION SCHEMA,
> WITH A DECLARED NOTIF-03 EXTERNAL-RECIPIENT GAP.**

Yang dibedakan dengan sengaja:

- **NOTIF-01, NOTIF-02, NOTIF-04** — seluruh kriteria terpenuhi, tanpa pengecualian.
- **NOTIF-03** — kriteria 1 dan 2 terpenuhi; kriteria 3 terpenuhi penuh untuk penerima
  yang **dapat diwakili** skema saat ini. Untuk penerima tanpa akun portal — yang pada
  PPDB adalah keadaan **normal, bukan pengecualian** — sisi in-app-nya tidak ada dan
  yang tersedia hanya kanal wa.me manual.

NOTIF-03 karena itu **tidak** dinyatakan selesai seluruhnya. Yang dibutuhkan untuk
menutupnya adalah keputusan pemilik atas skema penerima, bukan pekerjaan implementasi
lanjutan.

## Yang berpindah ke Sprint 9

Sprint 8 tidak meninggalkan pekerjaan notifikasi yang tertunda selain celah skema di
atas. Yang secara eksplisit **di luar** Phase 1 dan tidak dikerjakan:

- pengiriman WhatsApp otomatis (Fonnte / Meta Cloud API) — Phase 2 sesuai
  `03-phase2-overview.md`;
- pengingat jatuh tempo, pengingat pembayaran, dan pengingat ketidakhadiran — tidak
  disebut sumber mana pun dan tidak dibuat;
- trigger otomatis keempat — daftar NOTIF-03 tertutup pada tiga.
