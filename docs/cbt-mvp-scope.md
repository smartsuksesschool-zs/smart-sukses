# Batas MVP CBT yang Dipercepat

Asal-usul dan provenance ada di [`owner-scope-changes.md`](owner-scope-changes.md).
Dokumen ini menjawab satu pertanyaan saja: **apa yang termasuk, dan apa yang tidak.**

Ditulis lebih dulu, sebelum implementasinya selesai, supaya batasnya tidak bergeser
mengikuti apa yang kebetulan sempat dikerjakan.

> **Ini bukan CBT Phase 2.** Blueprint memperkirakan CBT penuh 6–8 minggu
> (`01-prd/03-phase2-overview.md`). Yang di bawah ini jauh lebih kecil.

---

## 1. Termasuk

### Guru
- membuat ujian untuk kelas-mata pelajaran **yang ia ampu**
- judul, deskripsi, durasi, jadwal buka–tutup
- membuat soal **pilihan ganda** beserta pilihan jawabannya dan kuncinya
- menerbitkan, dan menarik kembali selama belum ada yang mengerjakan
- melihat daftar pengerjaan dan nilainya

### Siswa
- melihat ujian yang terbit untuk kelas aktifnya, dalam rentang waktunya
- memulai, menjawab, dan mengumpulkan
- jawaban tersimpan otomatis saat berpindah soal
- melanjutkan pengerjaan selama belum dikumpulkan dan belum lewat waktunya
- melihat nilainya sendiri

### Sistem
- penilaian pilihan ganda **di sisi server**, otomatis, saat pengumpulan
- hasilnya tersimpan dan langsung terlihat
- isolasi cabang pada seluruh permukaannya

---

## 2. Tidak termasuk — tetap scope Phase 2 yang belum dikerjakan

| Tidak dikerjakan | Keterangan |
| --- | --- |
| **Soal uraian (essay)** | Disebut sumber Phase 2. Menuntut antrean penilaian guru, keadaan "menunggu dinilai", penilaian per jawaban, dan alur nilai ulang. Skema sudah menyiapkan tempatnya (`question_type` memuat `ESSAY`, `exam_answers.answer_text` ada); aplikasi rilis ini menolaknya. |
| Bank soal / memakai ulang soal antar kelas | — |
| Pengacakan soal dan pilihan jawaban | Berinteraksi dengan "lanjutkan pengerjaan" dan menuntut urutan tersimpan per percobaan. |
| Percobaan lebih dari satu, dan reset oleh guru | Punya konsekuensi jejak audit yang harus dirancang, bukan diimprovisasi. |
| Dasbor hasil real-time | "Hasil real-time" dalam arti sumbernya. Yang ada di sini: hasil tersimpan dan terlihat. |
| Anti-curang, kunci layar penuh, pemantauan tab/peramban | Tidak diminta, tidak andal, dan mahal. |
| Gambar, audio, atau lampiran pada soal | — |
| Impor soal dari Excel | — |
| Ruang Kelas Virtual (Google Meet), Bank Materi | Modul Phase 2 yang lain. |
| REST API CBT | Permukaan API tetap **41** endpoint. Seluruh CBT berjalan lewat web/Livewire, pola yang sama dengan ketiga portal. |

---

## 3. Hasil CBT dan nilai akademik

**Keputusan pemilik (R-1).** Pengumpulan siswa menghitung dan menyimpan hasilnya saat
itu juga, dan hasil itu langsung terlihat di permukaan CBT. Pengumpulan **tidak pernah**
membuat baris `grades`.

Nilai akademik hanya lahir dari tindakan guru **"Masukkan ke Nilai"**, yang:

- memakai `GradeType` dan `AssessmentType` yang **sudah ada** — tidak ada GradeType baru;
- berbawaan `AssessmentType` = **FORMATIVE**, sehingga pilihan yang aman adalah pilihan
  yang diambil bila guru tidak memikirkannya;
- menempuh jalur penyimpanan nilai yang sudah ada, termasuk snapshot bobot pada
  `Grade::booted()` dan peringatan `ConfigurationGapWarner`.

Konsekuensi yang dikehendaki: semantik GradeConfig, `ComponentScoreAggregator`,
`FinalScoreCalculator`, dan kunci rapor terbit **tidak tersentuh sama sekali** oleh CBT.

Jembatan itu **belum dibangun**. Yang sudah ada hanya tempatnya:
`exam_attempts.grade_id`.

---

## 4. Penilaian

```
nilai = round( Σ points_earned / Σ points_soal × 100 , 2 )
```

- bobot per soal: `exam_questions.points`, bawaan `1.00`, boleh diubah guru
- soal yang dilewati: 0 poin, tidak pernah negatif
- skala hasil: 0.00–100.00, `decimal(5,2)` — sama persis dengan `grades.score`
- pembulatan: `round($x, 2)`, panggilan yang sama dengan `ComponentScoreAggregator`
- penilaian **hanya** di sisi server; peramban tidak pernah mengirim nilai
- kunci jawaban tidak pernah sampai ke peramban siswa sebelum pengerjaannya final
- ujian tanpa soal atau bertotal bobot nol tidak boleh terbit, sehingga pembagi nol
  tidak mungkin terjadi

---

## 5. Aturan pengerjaan — **bawaan implementasi, bukan permintaan pemilik**

Pemilik tidak menentukan satu pun di antaranya. Seluruhnya dipilih implementasi, dan
dicatat di sini sebagai pilihan supaya dapat ditinjau:

| Aturan | Bawaan |
| --- | --- |
| Jumlah percobaan | satu per ujian per siswa, dijamin UNIQUE di database |
| Buka–tutup | ditentukan server |
| Durasi | ditentukan server; `expires_at` ditetapkan sekali saat memulai |
| Simpan otomatis | ya, saat berpindah soal |
| Lanjutkan | ya, selama belum dikumpulkan dan belum lewat waktunya |
| Terlambat | ditolak; server mengumpulkan sendiri apa yang sudah tersimpan |
| Kumpulkan | mengunci pengerjaan dan menghitung nilainya |
| Ulang / reset | tidak ada |
| Acak soal & jawaban | tidak ada |
| Anti-curang | tidak ada |

---

## 6. Kewenangan

Diturunkan dari izin modul terdekat yang sudah ada, "Input Nilai"
(`grade.view` / `grade.manage`) — CBT tidak punya barisnya sendiri di matriks PRD 1.1.2
karena matriks itu ditulis untuk Phase 1.

| Peran | CBT |
| --- | --- |
| SUPER_ADMIN | penuh (lewat `Gate::before`) |
| SCHOOL_ADMIN | mengelola ujian di cabangnya sendiri |
| KEPALA_SEKOLAH | melihat saja |
| GURU | mengelola hanya kelas-mapel yang ia ampu |
| WALI_KELAS | sama dengan Guru — karena **mengajar**, bukan karena menjadi wali |
| BENDAHARA | ditolak |
| SISWA | mengerjakan ujiannya sendiri; tidak pernah membuat |
| ORANG_TUA | ditolak — tidak ada permukaan CBT untuk orang tua, dan tidak diminta |

---

## 7. Urutan batch

| Batch | Isi | Keadaan |
| --- | --- | --- |
| **C1** | dokumen provenance, skema, model, enum, policy, factory, test integritas & isolasi | **selesai** |
| **C2** | UI penulis: daftar & CRUD ujian, soal, pilihan jawaban, siklus terbit/tarik/tutup | **selesai** |
| **C3** | alur siswa: daftar, mengerjakan, simpan otomatis, pengatur waktu, mengumpulkan, penilaian server, halaman hasil | **selesai** |
| **C4** | daftar hasil untuk guru, jembatan "Masukkan ke Nilai", penutupan | **selesai** |
| L1 | landing page publik (terpisah dari CBT) | belum |

C1–C3 tidak menulis satu baris `grades` pun. C4 menulisnya **hanya** lewat tindakan guru
yang disengaja. Tidak satu pun dari keempatnya menyentuh `/`.

Penutupannya: [`cbt-mvp-closure.md`](cbt-mvp-closure.md).

---

## 8. Aturan penulisan soal (C2)

### Siklus hidup

```
DRAFT ──terbitkan──► PUBLISHED ──tutup──► CLOSED
  ▲                       │
  └──tarik kembali────────┘   (hanya selama nol pengerjaan)
```

Satu-satunya pemegang aturannya `App\Services\Cbt\ExamPublisher`. Ujian selalu lahir
sebagai DRAFT; status tidak pernah menjadi field yang dapat diisi.

### Syarat terbit

Dua belas, seluruhnya wajib: status draf · aktor berwenang · kelas-mapel satu cabang ·
tahun ajaran sesuai kelas-mapelnya · durasi > 0 · waktu tutup setelah waktu buka ·
sedikitnya satu soal · seluruh soal bertipe yang didukung · bobot tiap soal > 0 ·
sedikitnya 2 pilihan per soal · tepat satu kunci per soal · total bobot > 0.

Gagal satu saja: ujian tetap draf, tidak ada perubahan sebagian, dan alasannya disebut
dalam bahasa Indonesia beserta nomor soalnya.

### Keabadian setelah dikerjakan

| Keadaan | Metadata | Soal & pilihan | Tarik kembali | Tutup | Hapus |
| --- | --- | --- | --- | --- | --- |
| DRAFT, nol pengerjaan | boleh | boleh | — | — | boleh |
| PUBLISHED, nol pengerjaan | tarik kembali dulu | tarik kembali dulu | boleh | boleh | tidak |
| PUBLISHED, ada pengerjaan | tidak | tidak | **tidak** | boleh | **tidak** |
| CLOSED | tidak | tidak | tidak | tidak | tidak |

Penghapusan ujian yang sudah dikerjakan ditutup **aplikasi**, bukan skema: database
memang meneruskan penghapusan ke pengerjaan dan jawaban, dan justru karena itu tombolnya
tidak pernah disediakan.

Tidak ada penjadwal yang menutup ujian otomatis setelah `available_until`. C3
memperlakukan ujian terbit yang jadwalnya lewat sebagai tidak tersedia, tanpa mengubah
statusnya.

### Kunci jawaban

`ExamPolicy::viewAnswerKey()` terpisah dari `view()`. Aturannya: **yang boleh melihat
kunci adalah yang boleh menulisnya.**

| | Melihat ujian & soal | Melihat kunci |
| --- | --- | --- |
| SUPER_ADMIN | ya | ya |
| SCHOOL_ADMIN (cabangnya) | ya | ya |
| GURU / WALI (kelas yang diampu) | ya | ya |
| KEPALA_SEKOLAH | ya | **tidak** |
| BENDAHARA / SISWA / ORANG_TUA | tidak | tidak |

Kunci tidak pernah muncul sebagai kolom daftar mana pun — daftar soal hanya menampilkan
**jumlah** pilihan. Ia hanya ada di form penyuntingan soal, yang tertutup bagi siapa pun
yang tidak dapat mengubah ujiannya.

---

## 9. Alur siswa (C3)

### Rute

| URI | Nama |
| --- | --- |
| `/siswa/ujian` | `student.exams` |
| `/siswa/ujian/{examId}` | `student.exam` |
| `/siswa/ujian/{examId}/hasil` | `student.exam-result` |

Tidak ada id siswa di mana pun. Identitasnya selalu dari akun yang login lewat
`StudentPortalService` — pola yang sama dengan seluruh halaman portal lain.

Menu **Ujian** ditambahkan ke navigasi portal siswa, di antara Nilai dan Notifikasi.
Ia tambahan scope pemilik, di luar keempat menu PORTAL-03.

### Keadaan yang dilihat siswa

Diturunkan, tidak disimpan — bukan kolom database:

| Keadaan | Syarat | Tindakan |
| --- | --- | --- |
| Belum dibuka | terbit, `available_from` belum tiba | — |
| Dapat dikerjakan | terbit, di dalam rentang, belum dikerjakan | Mulai Ujian |
| Sedang dikerjakan | ada pengerjaan, belum lewat batas | Lanjutkan |
| Sudah dikumpulkan | pengerjaan berstatus SUBMITTED | Lihat Hasil |
| Terlewat | rentangnya habis tanpa pernah dikerjakan, atau ujiannya ditutup | — |

Ujian **draf** tidak pernah terlihat. Ujian yang sudah **ditutup** tetap terlihat bila
siswa punya hasil di sana.

### Yang dijamin server

| | |
| --- | --- |
| Identitas | dari sesi, tidak pernah dari request |
| `started_at`, `expires_at`, `submitted_at` | ditetapkan server |
| `expires_at` | `min(mulai + durasi, available_until)` |
| Hitung mundur di layar | gambar; setiap aksi memeriksa ulang jam server |
| `is_correct`, `points_earned`, `score` | hanya ditulis penilaian; bukan parameter jalur mana pun yang dapat dipanggil peramban |
| Kunci jawaban | tidak pernah ikut dalam SELECT alur siswa |

### Simpan otomatis

Tersimpan per soal saat dipilih, lewat upsert atas UNIQUE
`(exam_attempt_id, exam_question_id)`. Mengganti pilihan memperbarui baris yang sama.
Setelah dikumpulkan, tidak ada jawaban yang dapat berubah.

### Kedaluwarsa

Tanpa penjadwal. Pengerjaan yang lewat batas ditutup saat siswa menyentuhnya lagi,
memakai jawaban yang sudah tersimpan dan mesin penilaian yang sama dengan pengumpulan
biasa. `submitted_at` diisi `expires_at` — detik pengerjaannya benar-benar berakhir.

Konsekuensi yang diterima: pengerjaan siswa yang tidak pernah kembali tetap
IN_PROGRESS sampai ada yang membukanya. Nilainya tidak hilang, hanya belum dihitung.

### Hasil

Nilai 0–100, waktu pengumpulan, durasi. **Tidak** ada kunci jawaban dan **tidak** ada
ulasan per soal — siswa yang mengumpulkan lebih awal tidak boleh menjadi sumber kunci
bagi teman sekelasnya.

### Nilai akademik

C3 tidak membuat, mengubah, atau menghapus satu baris `grades` pun, dan tidak menyentuh
rapor maupun GradeConfig. Jembatan "Masukkan ke Nilai" adalah C4.

---

## 10. Hasil & jembatan nilai (C4)

### Daftar hasil

Relation manager **"Hasil Ujian"** pada ExamResource: siswa, waktu mulai, waktu
dikumpulkan, status, nilai, dan keadaan integrasi nilai ("Belum masuk nilai" / "Sudah
masuk nilai"). Tidak ada kunci jawaban di tabel ini.

Membukanya juga menutup pengerjaan yang batas waktunya sudah lewat — **hanya untuk ujian
yang sedang dibuka**, memakai mesin penilaian yang sama dengan jalur siswa. Tetap tanpa
penjadwal.

### Kewenangan — dua hal yang berbeda

| Peran | Melihat hasil | Masukkan ke Nilai |
| --- | --- | --- |
| SUPER_ADMIN | ya | ya |
| SCHOOL_ADMIN (cabangnya) | ya | ya |
| GURU / WALI (kelas yang diampu) | ya | ya |
| GURU (kelas orang lain) | **tidak** | tidak |
| KEPALA_SEKOLAH | ya | **tidak** |
| BENDAHARA / SISWA / ORANG_TUA | tidak | tidak |

Kewenangan jembatan diperiksa dari **dua** sisi: `ExamPolicy::bridgeToGrade()` dan
`GradePolicy::gradeClassSubject()` — kewenangan yang sama yang menjaga input nilai biasa.

### Jembatan

```
hasil CBT (SUBMITTED, bernilai)
   → guru memilih jenis nilai + jenis penilaian
      → Grade::query()->create()   [snapshot bobot berlaku]
         → exam_attempts.grade_id
```

| Aturan | |
| --- | --- |
| Jenis nilai | DAILY · ASSIGNMENT · MIDTERM · FINAL |
| Tidak ditawarkan | ATTITUDE, SKILL |
| Jenis penilaian | **FORMATIF** (bawaan) · SUMATIF (sengaja) |
| Nilai | disalin apa adanya dari `exam_attempts.score` |
| Keterangan | `"CBT: <judul>"` pada kolom `description` yang sudah ada |
| Penilai | aktor yang login, pada `graded_by` |
| Ganda | ditolak — barisnya dikunci, `grade_id` diperiksa ulang di dalam transaksi |
| Rapor sudah terbit | **ditolak** |
| Konfigurasi LOCKED | nilai tetap tersimpan tanpa bobot — perilaku input nilai yang sudah ada |
| Nilai dihapus | `grade_id` kembali NULL, dan hasilnya dapat dimasukkan lagi |
| Aksi massal | ada; yang gagal dilaporkan beserta namanya, tidak disembunyikan |

### Apa yang **tidak** berubah

`GradeConfig`, `ComponentScoreAggregator`, `FinalScoreCalculator`, `ReportCardGenerator`,
`Grade::isLocked()`, dan `GradePolicy` tidak disentuh sama sekali. Nilai formatif tidak
menggeser nilai akhir, dan nilai sumatif mengikuti rata-rata komponen serta bobot Grade
Config — keduanya karena aturan yang sudah ada, bukan karena aturan CBT.
