# Penutupan MVP CBT yang Dipercepat

Dokumen sisi implementasi. `smartsukses-docs/` tidak diubah.

Provenance: [`owner-scope-changes.md`](owner-scope-changes.md) ·
Batas scope: [`cbt-mvp-scope.md`](cbt-mvp-scope.md) ·
Keputusan: [`implementation-notes.md`](implementation-notes.md) butir 262–343.

---

## Verdict

> **MVP CBT YANG DIPERCEPAT TERKIRIM.**
>
> **Ini bukan CBT Phase 2**, dan bukan Sprint 9.

Blueprint memperkirakan CBT penuh **6–8 minggu** (`01-prd/03-phase2-overview.md`) dan
menempatkannya di luar scope MVP Phase 1. Yang diserahkan di sini adalah potongannya:
pilihan ganda, dinilai otomatis, terintegrasi ke penilaian akademik lewat satu tindakan
guru yang disengaja.

Sprint 9 menurut roadmap adalah **Polish & QA** — bilingual (EN), responsive mobile, load
testing, security audit, bug fixing. Tidak satu pun di antaranya dikerjakan, dan dokumen
ini tidak menyatakan sebaliknya.

---

## Yang terkirim

### Guru & Admin

| | |
| --- | --- |
| Menyusun ujian | judul, deskripsi, durasi, jadwal buka–tutup, untuk kelas-mapel yang diampu |
| Menyusun soal | pilihan ganda, bobot per soal, nomor urut |
| Kunci jawaban | satu per soal, divalidasi saat menyimpan dan saat terbit |
| Siklus hidup | DRAFT → PUBLISHED → CLOSED, dan tarik-kembali selama nol pengerjaan |
| Daftar hasil | siswa, waktu, status, nilai, keadaan integrasi nilai |
| Masukkan ke Nilai | satuan dan massal, dengan jenis nilai dan jenis penilaian yang dipilih guru |

### Siswa

| | |
| --- | --- |
| Daftar ujian | hanya kelas aktifnya, pada tahun ajaran aktif |
| Mengerjakan | pilihan ganda, berpindah soal bebas, peta nomor soal |
| Simpan otomatis | per soal, dapat diubah sampai dikumpulkan |
| Melanjutkan | selama belum dikumpulkan dan belum lewat batas |
| Pengatur waktu | ditentukan server; hitung mundur di layar hanya gambar |
| Nilai | tampil segera setelah mengumpulkan |

### Sistem

- penilaian objektif **di sisi server**, `round(Σ points_earned / Σ points × 100, 2)`
- satu pengerjaan per siswa per ujian, dijamin UNIQUE database
- batas waktu = `min(mulai + durasi, penutupan ujian)`
- pengerjaan kedaluwarsa ditutup tanpa penjadwal, memakai mesin penilaian yang sama
- isolasi cabang pada seluruh permukaannya
- jejak audit lewat listener CUD yang sudah ada, tanpa aktor palsu

---

## Angka

| | |
| --- | --- |
| Batch | C1 (fondasi) · C2 (penulisan) · C3 (pengerjaan) · C4 (hasil & jembatan) |
| Tabel baru | 5 — `exams`, `exam_questions`, `exam_options`, `exam_attempts`, `exam_answers` |
| Migrasi | 29 → 34 (seluruhnya di C1; C2–C4 tanpa migrasi) |
| Permukaan API | **41**, tidak berubah — CBT tidak menambah satu endpoint REST pun |
| Rute siswa | 8 → 11 |
| Test CBT | 274 |
| Perubahan skema penilaian | **nol** — `grades`, `grade_configs`, `report_cards` tidak tersentuh |

---

## Integrasi ke nilai akademik

**Keputusan pemilik (R-1).** Pengumpulan siswa **tidak pernah** membuat nilai akademik.

Angka berpindah hanya lewat tindakan guru "Masukkan ke Nilai", dan hanya lewat
`App\Services\Cbt\ExamGradeBridge`.

| | |
| --- | --- |
| Jenis nilai yang ditawarkan | DAILY, ASSIGNMENT, MIDTERM, FINAL |
| Tidak ditawarkan | ATTITUDE (bukan nilai akademik), SKILL (tidak ada sumber yang mendukungnya — butir 328) |
| Jenis penilaian | FORMATIF (bawaan) atau SUMATIF (harus dipilih sengaja — butir 336) |
| GradeType baru | **tidak ada** |
| Jalur penyimpanan | `Grade::query()->create()`, sehingga snapshot bobot `Grade::booted()` tetap berlaku |
| Tautan balik | `exam_attempts.grade_id` |
| Ganda | dicegah penguncian baris di dalam transaksi — tepat satu nilai |
| Rapor sudah terbit | **ditolak** (pagar lokal, butir 332) |
| Rapor dibuat ulang otomatis | tidak — itu tetap alur guru/admin yang sudah ada |

Nilai hasil jembatan muncul di portal siswa, portal orang tua, dan panel lewat permukaan
yang **sudah ada**: ia baris `grades` biasa.

---

## Yang **tidak** termasuk — tetap scope Phase 2

| | Keterangan |
| --- | --- |
| Soal uraian & penilaian manual | Disebut sumber Phase 2. Skema menyiapkan tempatnya; aplikasi menolaknya. |
| Bank soal / pakai ulang soal | — |
| Pengacakan soal & pilihan | Berinteraksi dengan "lanjutkan pengerjaan". |
| Percobaan ulang, reset guru | Punya konsekuensi jejak audit yang harus dirancang. |
| Anti-curang, proctoring, kunci layar penuh | Tidak diminta, tidak andal. |
| Soal bermedia (gambar/audio/lampiran) | — |
| Impor soal dari Excel | — |
| Analitik lanjutan (analisis butir soal, statistik kelas) | — |
| Dasbor hasil real-time penuh | Yang ada: hasil tersimpan dan terlihat. |
| Ruang Kelas Virtual, Bank Materi, dan modul LMS lain | Modul Phase 2 yang lain. |
| Ulasan jawaban per soal untuk siswa | Belum dikerjakan; menuntut jendela ujian tertutup lebih dulu. |
| REST API CBT | Permukaan API sengaja tetap 41. |

---

## Landing page — **bukan bagian dari penutupan ini**

Halaman muka publik adalah **permintaan langsung pemilik yang terpisah**
(`owner-scope-changes.md` bagian A). Ia bukan bagian dari CBT, tidak termasuk dalam
verdict di atas, dan **belum dikerjakan**.

`/` masih mengembalikan halaman bawaan Laravel.

---

## Yang masih menunggu pemilik

| # | Pertanyaan |
| --- | --- |
| R-2 | Bolehkah Admin Sekolah menyusun soal, atau hanya guru pengampunya? Implementasi mengikuti preseden `GradePolicy` yang berlaku — keputusan arsitektur, bukan keputusan pemilik. |
| R-4 | Apakah "satu percobaan per ujian" sesuai cara sekolahnya bekerja? Tidak ada reset guru. |
| R-5 | Landing page di `apps.smartsukses.sch.id` atau domain lain? |
| R-6 | Isi landing page. |
| **Baru** | Celah `GradePolicy::create()` terhadap rapor terbit (butir 332). CBT menutupnya secara lokal; apakah **input nilai manual** juga harus ditutup adalah pertanyaan kebijakan yang belum dijawab. |

---

## Kebutuhan sebelum go-live

Terbawa dari penutupan Sprint 8 dan masih berlaku:

- entri cron `* * * * * php artisan schedule:run` — tanpanya `notifications:prune` tidak
  pernah berjalan. **CBT tidak membutuhkan penjadwal apa pun**, jadi ini tetap kebutuhan
  notifikasi, bukan kebutuhan CBT.
- pengerjaan CBT yang kedaluwarsa ditutup saat disentuh siswa atau saat gurunya membuka
  daftar hasil. Tidak ada penjadwal yang dibutuhkan, dan konsekuensinya dicatat di butir
  313 dan 334.
