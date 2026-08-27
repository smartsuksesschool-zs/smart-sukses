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
| C2 | UI guru: CRUD ujian, soal, pilihan, aturan terbit | belum |
| C3 | alur siswa: daftar, mengerjakan, simpan otomatis, mengumpulkan, penilaian server, halaman hasil | belum |
| C4 | daftar hasil untuk guru, jembatan "Masukkan ke Nilai", penutupan | belum |
| L1 | landing page publik (terpisah dari CBT) | belum |

C1 **tidak** membangun satu pun UI, tidak menilai, dan tidak menyentuh `/`.
