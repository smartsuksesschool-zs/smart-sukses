# Cakupan Bilingual ID / EN

Batch S9.3. Dokumen ini menyebut **apa yang benar-benar diterjemahkan**, apa yang
sengaja tidak, dan bagaimana kedua klaim itu dapat diperiksa ulang.

Klaim yang dibuat batch ini:

> Alur kerja utama Phase 1 tersedia dalam Bahasa Indonesia dan English. Label
> internal dan label berfrekuensi rendah yang belum diterjemahkan tercatat di
> bawah.

Klaim yang **tidak** dibuat: bukan "100% setiap string sudah diterjemahkan".

---

## 1. Mekanisme

| Hal | Nilai |
| --- | --- |
| Bahasa bawaan | `id` (Bahasa Indonesia) |
| Bahasa kedua | `en` (English) |
| Fallback | `en` |
| Penyimpanan terjemahan | `lang/id.json` + `lang/en.json` (JSON bawaan Laravel 11) |
| Paket pihak ketiga | tidak ada |
| Migrasi baru | tidak ada — `users.locale` sudah ada sejak Sprint 1 |
| Pilihan tamu | sesi (`session('locale')`) — tanpa akun, tanpa baris database |
| Pilihan pengguna | kolom `users.locale`, ditulis hanya untuk `$request->user()` |
| Urutan | preferensi akun menang atas sesi |
| Rute pemilih | `POST /bahasa/{locale}` → `locale.switch`, `->whereAlpha('locale')` |
| Metode | **POST saja.** Rutenya menulis, jadi ia dilindungi CSRF grup `web`. `GET /bahasa/en` menjawab 405 (butir 388) |
| Nilai tidak dikenal | jatuh ke `id`, tanpa galat; jalur/berkas tidak pernah mencapai controller |

Kunci terjemahannya adalah kalimat Indonesianya sendiri. `lang/id.json` tetap ada
— alasannya di butir 385 (`trans_choice()` jatuh ke `fallback_locale` bila kunci
tidak ditemukan untuk locale aktif, sehingga tanpa berkas itu bentuk jamak
dirender dalam bahasa Inggris di halaman berbahasa Indonesia).

## 2. Letak pemilih bahasa

| Permukaan | Letak |
| --- | --- |
| Halaman muka publik | navbar, di sebelah tombol Masuk |
| PPDB publik (daftar cabang, formulir, cek status) | header cabang |
| Halaman masuk siswa & orang tua | strip di atas kartu masuk |
| Portal siswa / guru / orang tua | header portal, di sebelah lonceng notifikasi |
| Panel Filament | topbar, sebelum menu pengguna |

Bentuknya satu `<form method="POST">` dengan dua tombol submit `ID` / `EN`,
masing-masing membawa `formaction` sendiri — bukan `<select>` dan tanpa satu
baris JavaScript pun. Satu token CSRF untuk keduanya. Bahasa yang sedang aktif
dirender `<span aria-current="true">`, bukan tombol ke dirinya sendiri. Sasaran
sentuhnya 2.75rem pada ketiga tata letak publik, sama dengan tombol portal lain.

Rutenya menulis — sesi bagi tamu, `users.locale` bagi pengguna yang login —
sehingga ia POST, bukan GET. GET tidak dilindungi CSRF dan dapat dipicu prefetch
peramban maupun `<img src>` di situs lain; alasannya lengkap di butir 388.

## 3. Cakupan per permukaan yang diminta

| # | Permukaan | Status | Bukti uji |
| --- | --- | --- | --- |
| A | Halaman muka publik | ✅ | `test_the_public_landing_page_reads_in_both_languages`, `test_the_landing_feature_list_reads_in_both_languages` |
| B | Halaman publik PPDB | ✅ | `test_the_public_ppdb_branch_list_…`, `test_the_public_ppdb_form_…`, `test_the_public_ppdb_status_check_…` |
| C | Portal siswa | ✅ | `test_the_student_portal_navigation_…`, `test_the_student_grades_page_…` |
| D | Portal orang tua | ✅ | `test_the_parent_portal_reads_…`, `test_the_parent_fees_page_…` |
| E | Portal guru | ✅ | `test_the_teacher_portal_reads_…`, `test_the_teacher_schedule_page_…` |
| F | Halaman masuk / auth | ✅ | `test_the_student_login_page_…`, `test_the_parent_login_page_…` |
| G | Navigasi, resource & page Filament | ✅ | `test_filament_navigation_and_resource_labels_…`, `test_filament_custom_page_titles_…`, `test_the_admin_panel_opens_in_english_for_every_panel_role` |
| H | Pesan validasi & galat umum | ✅ | `test_validation_messages_…`, `test_a_login_validation_error_…` |
| I | Label utama CBT | ✅ | `test_the_student_exam_list_…`, `test_the_answer_key_stays_hidden_when_the_page_is_in_english` |
| J | Label penilaian & rapor | ✅ | `test_enum_labels_translate_but_stored_values_never_change`, `test_the_formative_value_stays_formative_in_english` |
| K | Label keuangan | ✅ | `test_financial_figures_are_identical_in_both_languages` |
| L | Label notifikasi | ✅ | `test_the_notification_inbox_reads_in_both_languages` |

Semantik HTTP pemilih bahasa diuji tersendiri di `LocaleSwitchTest`:
`test_no_get_route_performs_the_locale_persistence`,
`test_the_switch_route_is_csrf_protected`,
`test_the_switch_posts_with_a_csrf_token_on_every_surface`,
`test_the_switch_form_is_never_nested_inside_another_form`.

Peran yang dapat menjalankan alur utamanya dalam bahasa Inggris: Admin Sekolah,
Kepala Sekolah, Guru, Wali Kelas, Bendahara (lewat panel), Siswa dan Orang Tua
(lewat portal). Ketujuhnya diuji.

## 4. Angka cakupan

Dihitung oleh pemindai di `BilingualCoverageTest`, bukan diperkirakan.

| Ukuran | Jumlah |
| --- | --- |
| Kunci `__()` / `trans_choice()` literal di `app/`, `resources/views/`, `routes/` | **763** |
| Kunci yang ada di `lang/en.json` | **763** (100%) |
| Kunci yang ada di `lang/id.json` | **763** (100%) |
| Entri total `lang/en.json` | 790 |
| Entri total `lang/id.json` | 790 |
| Entri EN yang identik dengan kuncinya | 19 (2,4%) |
| Berkas enum yang labelnya diterjemahkan | 23 |
| Berkas Filament yang labelnya diterjemahkan | 54 |
| Berkas Blade yang teksnya diterjemahkan | 39 |
| Komponen Livewire yang judul halamannya diterjemahkan | 21 |

Selisih 790 − 763 = 27 adalah label statis Filament (`static::$navigationLabel`,
`$navigationGroup`, `$modelLabel`, `$pluralModelLabel`, `$title`). Label-label itu
diterjemahkan lewat override getter `__(static::$navigationLabel)`, sehingga
literalnya tidak muncul di dalam `__()` dan tidak terlihat oleh pemindai. Keduanya
diuji tersendiri pada `test_filament_navigation_and_resource_labels_read_in_both_languages`.

Ke-19 entri EN yang identik dengan kuncinya memang identik dalam kedua bahasa:
`Audit Log`, `Draft`, `Email`, `Filter`, `Guard`, `IP`, `Level`, `Logo`,
`Master Data`, `No.`, `PDF`, `Password`, `Payment Gateway`, `Platform`,
`Reset Password`, `Semester`, `Slug`, `Status`, `Super Administrator`.

## 5. Yang sengaja **tidak** diterjemahkan

| Kategori | Alasan |
| --- | --- |
| Isi yang ditulis pengguna — nama siswa, judul ujian, isi pengumuman, alasan pembebasan, keterangan transaksi | Menerjemahkannya berarti mengarang data. |
| Nilai enum yang tersimpan (`FORMATIVE`, `UNPAID`, `PUBLISHED`, …) | Nilai database; label-nya yang diterjemahkan, bukan nilainya. |
| Kode & identitas — kode cabang, NIS, NISN, slug, nomor pendaftaran | Identitas, bukan kalimat. |
| Keluaran PDF rapor (`resources/views/pdf/report-card.blade.php`) | Dokumen resmi sekolah, berbahasa Indonesia. Di luar lingkup S9.3. |
| Log, pesan pengecualian, keluaran perintah artisan, komentar kode | String pengembang, bukan antarmuka pengguna. |
| Nilai data audit (`AuditLog.old_values` / `new_values`) | Rekaman apa adanya. |
| String vendor Filament (tombol tabel, paginasi, notifikasi bawaan) | Sudah ditangani berkas locale `id` milik vendor. |
| Contoh isian `MTK`, `X-A`, `2024/2025 Semester 1` | Kode contoh, bukan kalimat. |
| Dokumen sumber di `smartsukses-docs/` | Tidak boleh disentuh. |

## 6. Sisa yang belum diterjemahkan — dan diketahui

Tercatat supaya tidak diklaim selesai:

1. **Isi PDF rapor.** Judul, label bagian, dan tanda tangan pada
   `resources/views/pdf/report-card.blade.php` tetap Indonesia. Rapor adalah
   dokumen resmi; menerjemahkannya adalah keputusan sekolah, bukan keputusan
   teknis.
2. **Sebagian pesan galat sistem berfrekuensi rendah** yang berasal dari Laravel
   sendiri (mis. halaman 419/429) memakai teks bawaan framework.
3. **Nama hari dan bulan** mengikuti `translatedFormat()` Carbon dan karena itu
   mengikuti locale aktif; format tanggalnya sendiri tetap `d M Y` pada kedua
   bahasa — tidak dilokalkan menjadi format Amerika/Britania.
4. **Format angka** tetap gaya Indonesia (`750.000`) pada kedua bahasa. Ini
   disengaja: mengubah pemisah ribuan mengubah tampilan angka keuangan, dan S9.3
   tidak boleh menyentuh perilaku keuangan.
5. **String vendor Filament yang tidak punya terjemahan `id`** akan tampil dalam
   bahasa Inggris pada mode Indonesia. Ini perilaku vendor, sama seperti sebelum
   batch ini.

## 7. Yang dijaga tetap tidak berubah

Diuji, bukan diasumsikan:

- **Nilai enum.** `AssessmentType::Formative->value === 'FORMATIVE'` pada kedua
  bahasa, dan `AssessmentType::tryFrom('Formative (not counted)')` mengembalikan
  `null`. Seluruh case dari sembilan enum dibandingkan antar-locale.
- **Angka keuangan.** Nominal tagihan, jumlah dibayar, dan sisa tampil identik
  pada kedua bahasa; baris `student_fees` tidak berubah setelah bahasa diganti.
- **Kunci jawaban CBT.** Halaman pengerjaan berbahasa Inggris diuji dengan
  penanda unik: pilihan yang benar tetap tidak dapat dibedakan dari yang salah.
- **Logika penilaian.** `Grade`, `GradePolicy`, `GradeConfig`, dan
  `FinalScoreCalculator` tidak disentuh batch ini.
- **Logika penerima notifikasi.** Tidak disentuh; hanya labelnya.
- **Tidak ada `{!! __(...) !!}`.** Seluruh `resources/views` dipindai; keluaran
  terjemahan selalu ter-escape.

## 8. Cara memverifikasi ulang

```sh
php artisan test tests/Feature/I18n
php artisan test tests/Feature/Cbt/StudentExamKeyLeakTest.php
```

Untuk melihat bahasa berganti tanpa akun:

```
GET  /             → Bahasa Indonesia
POST /bahasa/en    → beralih, redirect kembali ke halaman asal
GET  /             → English
POST /bahasa/fr    → jatuh ke Bahasa Indonesia, tanpa galat
GET  /bahasa/en    → 405 Method Not Allowed, tidak menyimpan apa pun
```

Pemilihnya di halaman adalah form; menekannya dari peramban sudah membawa token
CSRF-nya sendiri.
