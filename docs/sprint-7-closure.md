# Penutupan Sprint 7 — Portal

Ringkasan status implementasi Sprint 7 (Parent, Teacher, dan Student Portal) beserta
buktinya. Alasan tiap keputusan ada di [`implementation-notes.md`](implementation-notes.md)
butir 147–190; berkas ini hanya menyatakan **apa yang sudah ada, apa yang belum, dan atas
dasar apa**.

Dokumen sumber di `smartsukses-docs/` tidak diubah oleh Sprint 7 mana pun.

## Baseline saat penutupan

| | |
| --- | --- |
| HEAD Batch 7.4 | `3da34c3` |
| Rute API | **33** |
| Rute web portal | orang tua 7 · guru 4 · siswa 7 |
| Migrasi | **27 Ran, 0 pending**, tidak ada migrasi baru sepanjang Sprint 7 |
| Full SQLite terakhir (Batch 7.4) | **1509 passed / 5179 assertions** |
| Full MySQL terakhir (Batch 7.4) | **1509 passed / 5179 assertions** |

## Endpoint API §4.11

Kesepuluhnya terdaftar dan diuji keberadaannya oleh `SprintSevenClosureTest`.

| Endpoint | Status | Bukti |
| --- | --- | --- |
| `GET /parent/children` | DONE | `ParentPortalApiTest` |
| `GET /parent/children/{id}/summary` | DONE | `ParentPortalApiTest` |
| `GET /parent/children/{id}/grades` | DONE | `ParentDetailApiTest` |
| `GET /parent/children/{id}/fees` | DONE | `ParentDetailApiTest` |
| `GET /parent/children/{id}/schedule` | DONE | `ParentDetailApiTest` |
| `GET /teacher/dashboard` | DONE | `TeacherPortalApiTest` |
| `GET /teacher/classes` | DONE | `TeacherPortalApiTest` |
| `GET /student/dashboard` | DONE | `StudentPortalApiTest` |
| `GET /student/schedule` | DONE | `StudentPortalApiTest` |
| `GET /student/grades` | DONE | `StudentPortalApiTest` |

Tidak ada endpoint notifikasi — subsistemnya milik Sprint 8, dan tidak ada placeholder yang
dibuat (diuji: tidak ada rute mengandung `notification`/`notifikasi`/`pengumuman`).

## PORTAL-01 — Parent Portal

**Status: IMPLEMENTED WITH KNOWN EXTERNAL DATA GAP.**

| Kriteria PORTAL-01 | Status | Bukti / alasan |
| --- | --- | --- |
| Dashboard: 3 nilai terbaru | DONE | Service menyediakan 5, dashboard menampilkan 3 (butir 150) |
| Dashboard: kehadiran bulan ini | **DEFERRED** | Tidak ada sumber presensi di Phase 1 (butir 152) |
| Dashboard: tagihan belum lunas (jumlah & nominal) | DONE | `ParentPortalApiTest`, aturan UNPAID+PARTIAL (butir 154) |
| Berpindah antar profil anak (>1 anak) | DONE | Satu pemilih untuk empat halaman (butir 167) |
| Responsive mobile | DONE (struktur) | Lihat bagian *Responsif* di bawah |
| Halaman Nilai / Tagihan / Jadwal | DONE | `ParentDetailApiTest`, `ParentPortalPagesTest` |
| Unduh rapor terbit | DONE | Kepemilikan dari resolver portal, bukan policy (butir 162) |

**Kehadiran tidak selesai dan tidak boleh disebut selesai.** Tidak ada tabel kehadiran di
antara 21 tabel ERD, tidak ada satu pun baris presensi harian, dan "Presensi Digital"
tercantum sebagai fitur **Phase 2** di `01-prd/03-phase2-overview.md`. Kolom
`report_cards.attend_*` ada di ERD tetapi merupakan rekap satu tahun ajaran dan tidak
pernah diisi jalur mana pun. API mengembalikan `available: false`, UI menulis "Data
kehadiran belum tersedia" — bukan angka nol.

## PORTAL-02 — Teacher Portal

**Status: IMPLEMENTED WITH ONE SHORTCUT BLOCKED BY BLUEPRINT CONFLICT.**

| Kriteria PORTAL-02 | Status | Bukti / alasan |
| --- | --- | --- |
| Jadwal hari ini di halaman utama | DONE | `TeacherPortalApiTest`, `TeacherPortalUiTest` |
| Kelas aktif | DONE | Definisi kanonik penugasan mengajar (butir 172) |
| Shortcut Input Nilai | DONE | Menuju halaman `InputNilai` panel yang sudah ada (butir 178) |
| Shortcut Daftar Siswa Kelas | DONE | `/teacher/kelas/{id}`, kelas bukan miliknya 404 (butir 173) |
| Shortcut Buat Pengumuman | **BLOCKED** | Konflik kewenangan — lihat di bawah |

**Konflik yang tidak diselesaikan diam-diam.** PORTAL-02 poin 2 meminta pintasan Buat
Pengumuman, sedangkan NOTIF-01 menyatakan *"Sebagai **Admin Sekolah**, saya dapat membuat
pengumuman"* dan matriks PRD 1.1.2 baris "Notifikasi (buat)" menandai **GURU/WALI ❌**.
Yang bersifat kewenangan dan lebih spesifik menang: pintasannya tampil agar dasbornya tidak
diam-diam kehilangan satu dari tiga hal yang diminta, tetapi tidak dapat diklik dan
menyebutkan alasannya. Tidak ada izin notifikasi yang diberikan kepada guru.

Sprint 8 boleh memperjelas UX notifikasinya, **tetapi tidak boleh memberi Guru izin
membuat pengumuman tanpa perubahan matriks yang eksplisit**.

## PORTAL-03 — Student Portal

**Status: IMPLEMENTED EXCEPT THE NOTIFICATION SUBSYSTEM (Sprint 8).**

| Kriteria PORTAL-03 | Status | Bukti / alasan |
| --- | --- | --- |
| Menu Jadwal | DONE | `/siswa/jadwal` |
| Menu Nilai | DONE | `/siswa/nilai` |
| Menu Notifikasi | **DEFERRED** | Menu tampil, tidak dapat diklik, tanpa href (butir 183) |
| Menu Profil | DONE | `/siswa/profil`, tanpa id di alamatnya (butir 186) |
| Nilai per mata pelajaran | DONE | `StudentPortalApiTest` |
| Nilai per semester | DONE | `academic_years.semester` nyata dari ERD (butir 184) |
| Nilai per komponen | DONE | `grade_type` + `assessment_type` beserta labelnya |
| Notifikasi dengan timestamp | **DEFERRED** | Subsistem Sprint 8 |

Batas yang perlu disebut: karena API 4.11 sendiri menyatakan "tahun ajaran aktif", yang
tampil adalah semester yang sedang berjalan — bukan dua semester berdampingan.

## Matriks pagar baris

Diuji terpusat oleh `SprintSevenClosureTest`, di atas test masing-masing portal.

| Peran | Data siswa | Rapor | Tagihan | Pembayaran |
| --- | --- | --- | --- | --- |
| ORANG_TUA | anaknya saja | anaknya saja, terbit saja | anaknya saja | **403** |
| SISWA | dirinya saja | dirinya saja, terbit saja | **403** | **403** |
| GURU / WALI_KELAS | siswa kelas ajar + perwalian | perilaku akademik Sprint 4 tidak berubah | — | — |
| SCHOOL_ADMIN / KEPALA | seluruh cabangnya (tidak berubah) | tidak berubah | tidak berubah | tidak berubah |
| SUPER_ADMIN | lintas cabang (tidak berubah) | tidak berubah | tidak berubah | tidak berubah |

Lintas cabang: **nol kebocoran** — ketiga peran portal diuji terhadap siswa cabang lain.

Tiga kebocoran ditemukan dan ditutup sepanjang Sprint 7, seluruhnya **pre-existing**:

| Temuan | Sifat | Perbaikan |
| --- | --- | --- |
| `GET /student-fees` mengembalikan seluruh tagihan cabang kepada orang tua | **aktif** | `StudentVisibility` di policy + query (butir 170) |
| Guru melihat seluruh siswa & jadwal cabang di panel | **aktif** | `TeacherClassVisibility` di query resource (butir 176) |
| `StudentPolicy::view` meloloskan siswa/orang tua ke seluruh siswa cabang | laten | `StudentVisibility` di policy (butir 188) |

## Konsistensi autentikasi

`App\Support\PortalEligibility` adalah satu-satunya sumber syarat masuk portal, dibaca oleh
ketiga middleware portal dan kedua halaman masuk (butir 180). Empat syaratnya — akun aktif,
peran yang tepat, terhubung ke cabang, kata sandi sementara sudah diganti — diuji berlaku
identik untuk ketiga portal.

- Pesan penolakan seragam untuk nonaktif / peran keliru / tanpa cabang, sehingga keadaan
  akun tidak terbaca dari luar (butir 157).
- Regenerasi sesi setelah masuk berhasil; sesi dibuang penuh bila kredensial benar tetapi
  akunnya tidak berhak.
- Throttle 5 percobaan/menit pada kedua halaman masuk portal.
- Keluar hanya POST, berada di grup `web` (CSRF), meng-invalidate sesi dan merotasi token.
- Guru memakai sesi panel yang sudah ada — satu login, satu keluar (butir 171, 179).
- `must_change_password` menolak ketiga portal, termasuk sesi yang sudah berjalan.

## Navigasi dan tautan mati

Diuji dengan membuka setiap tautan navigasi sebagai peran yang melihatnya:

| Portal | Menu | Hasil |
| --- | --- | --- |
| Orang tua | Ringkasan · Nilai · Tagihan · Jadwal | keempatnya 200 |
| Guru | Dasbor · Kelas Ajar · Jadwal · Input Nilai | ketiganya 200; Input Nilai menyeberang ke panel dengan sengaja |
| Siswa | Jadwal · Nilai · Notifikasi · Profil | tiga 200; Notifikasi tanpa href |

Tidak ada portal yang menautkan ke rute portal lain (diuji pada **rute**, bukan teks).
Dua elemen sengaja tanpa tautan: menu Notifikasi siswa dan pintasan Buat Pengumuman guru —
keduanya `aria-disabled="true"` dengan keterangan, bukan `href` mati.

## Responsif — ruang lingkup verifikasi

**Yang benar-benar terbukti: struktur markup dan CSS**, diuji pada ketiga portal:
`viewport` meta, satu kolom sebagai bawaan dengan `@media (min-width: 48rem)` untuk
melebar, `overflow-x: hidden` pada body, sasaran sentuh `min-height: 2.75rem`, dan tabel
yang menggulung di dalam wadahnya sendiri (`.portal-scroll`), bukan mendorong halaman.

**Yang belum terbukti:** perilaku pada perangkat dan peramban sungguhan. Tidak ada
pengujian lintas perangkat yang dijalankan, dan berkas ini tidak mengklaimnya. Itu
pekerjaan QA Sprint 9.

## Performa — ruang lingkup verifikasi

Roadmap menyebut portal harus termuat < 3 detik pada 4G.

**Yang benar-benar terbukti:** jumlah query tetap konstan saat data bertambah — diuji pada
daftar anak, ringkasan, nilai, tagihan, jadwal, dasbor guru, kelas ajar, daftar siswa
kelas, dan seluruh halaman siswa. Tidak ada N+1 pada mata pelajaran, komponen nilai,
riwayat pembayaran, jam pelajaran, maupun per anak/siswa.

**NFR "< 3 detik pada 4G": NOT YET VERIFIED.** Tidak ada pengukuran ter-throttle pada
peramban sungguhan, dan angka apa pun yang disebutkan di sini tanpa pengukuran itu akan
menjadi klaim palsu. Diserahkan ke QA/performance Sprint 9.

## Daftar penundaan

| # | Item | Sebab | Diserahkan ke |
| --- | --- | --- | --- |
| A | Kehadiran pada dashboard orang tua | Tidak ada sumber presensi di Phase 1; "Presensi Digital" adalah fitur Phase 2 | Phase 2 |
| B | Pintasan "Buat Pengumuman" guru | PORTAL-02 berkonflik dengan NOTIF-01 dan matriks izin; tidak diselesaikan dengan memberi izin | Sprint 8 (tanpa memberi izin tanpa perubahan matriks) |
| C | Data notifikasi ketiga portal | Subsistem notifikasi Sprint 8 | Sprint 8 |
| D | Ubah profil siswa / `PATCH /auth/me` | Endpoint-nya memang belum pernah dibuat; ini backlog API, bukan fitur yang diklaim selesai | Backlog API |
| E | Halaman ganti kata sandi di dalam portal | Penegakan keamanannya sudah ada dan tidak dilonggarkan; yang belum ada kenyamanannya | Backlog UX |
| F | Verifikasi < 3 detik pada 4G | Belum diukur pada peramban ter-throttle | Sprint 9 |

Selain itu, di luar lingkup Sprint 7 tetapi masih terbuka pada Phase 1: REST untuk
manajemen tenant (API 4.3 sisa) dan pengguna (API 4.4), yang fungsinya sudah berjalan di
panel (butir 145, 146).

## Verdict

> **SPRINT 7 IMPLEMENTATION COMPLETE WITH DECLARED DEFERRED DEPENDENCIES.**

Ketiga portal terpasang dan berfungsi, kesepuluh endpoint §4.11 ada, pagar barisnya
terpasang lintas peran, dan tiga kebocoran pre-existing ditutup di sepanjang sprint.

Yang **tidak** boleh dikatakan: bahwa seluruh kriteria PORTAL-01/02/03 terpenuhi 100%.
Tiga di antaranya bergantung pada hal di luar Sprint 7 — sumber data Phase 2 (kehadiran),
subsistem Sprint 8 (notifikasi), dan satu konflik blueprint yang belum diputuskan
(kewenangan membuat pengumuman).
