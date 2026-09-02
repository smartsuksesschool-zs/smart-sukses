# Runbook Simulasi Smart Sukses School

Untuk dijalankan langsung saat rapat. Urut, singkat, dan setiap langkah punya
hasil yang dapat dilihat bersama.

Seluruh data yang dipakai **sinteris** — dibuat seeder, bukan data siswa
sungguhan. Tidak ada satu pun NIS, nama, alamat, atau nomor telepon nyata.

> Kata sandi **tidak** ditulis di dokumen ini. Ia berasal dari
> `SEED_ADMIN_PASSWORD` pada `.env` lokal. Siapkan sebelum rapat, sampaikan
> lisan.

---

## PREPARATION

Sekali, sebelum rapat. Lingkungan **lokal**, bukan produksi.

1. Nyalakan MySQL dari Laragon.
2. Siapkan data simulasi:

   ```
   php artisan migrate
   php artisan db:seed --class=SimulationSeeder
   ```

   Aman diulang. Jalankan **ulang sesaat sebelum rapat**: jendela ujian CBT
   dihitung dari waktu seeder dijalankan (terbuka sejak satu jam lalu, tutup
   tujuh hari lagi), sehingga menjalankannya ulang selalu memberi ujian yang
   sedang terbuka.

3. Nyalakan aplikasi:

   ```
   php artisan serve
   ```

4. Siapkan **dua jendela peramban terpisah** — satu biasa, satu penyamaran.
   Admin/guru di satu jendela, siswa di jendela lain. Tanpa ini setiap
   pergantian peran menuntut logout, dan itu memakan waktu rapat.

Akun yang tersedia (kata sandi sama untuk semuanya):

| Peran | Surel |
| --- | --- |
| Super Admin | `superadmin@smartsukses.sch.id` |
| Admin Sekolah | `admin.pusat@smartsukses.sch.id` |
| Kepala Sekolah | `kepsek.pusat@smartsukses.sch.id` |
| Bendahara | `bendahara.pusat@smartsukses.sch.id` |
| Guru | `guru.pusat@smartsukses.sch.id` |
| Wali Kelas | `walikelas.pusat@smartsukses.sch.id` |
| Siswa | `siswa.pusat@smartsukses.sch.id` |
| Orang Tua | `ortu.pusat@smartsukses.sch.id` |

---

## ADMIN FLOW

Jendela 1. Masuk sebagai **Admin Sekolah**.

| # | Langkah | Yang dilihat |
| --- | --- | --- |
| A1 | Buka `/login` | Satu halaman masuk untuk semua peran — tidak ada pilihan "masuk sebagai" |
| A2 | Masuk | Mendarat di panel admin `/admin` |
| A3 | **Akademik → Tahun Ajaran** | `2026/2027 Ganjil`, semester 1, bertanda aktif |
| A4 | **Master Data → Mata Pelajaran** | Matematika, Bahasa Indonesia, IPA |
| A5 | **Master Data → Kelas (Rombel)** | Satu kelas, tingkat 10, terikat tahun ajaran aktif |
| A6 | **Master Data → Data Siswa** | Tiga siswa, status Aktif |
| A7 | **Akademik → Jadwal Pelajaran** | Tiga jam pelajaran, Senin–Rabu |
| A8 | **Manajemen Akses → Pengguna** | Delapan akun, masing-masing satu peran |

Tunjukkan pada A3 bahwa **semester adalah kolomnya sendiri**, bukan sekadar
bagian nama. Ini pertanyaan yang masih menunggu jawaban sekolah.

---

## GURU FLOW

Jendela 1. Keluar, masuk sebagai **Guru**.

| # | Langkah | Yang dilihat |
| --- | --- | --- |
| G1 | Masuk lewat `/login` yang sama | Mendarat di panel admin, **menu lebih sedikit** daripada Admin Sekolah |
| G2 | **Akademik → Input Nilai** | Pilih kelas & mapel yang ia ampu; hanya miliknya yang muncul |
| G3 | Isi satu nilai, simpan | Tersimpan, tampil di daftar |
| G4 | **Akademik → Daftar Nilai** | Nilai tadi ada di sana |
| G5 | Buka `/teacher` | Portal guru: dasbor, Kelas Ajar, Jadwal |

Titik yang layak ditekankan pada G2: guru **tidak** melihat kelas yang tidak ia
ampu. Itu otorisasi, bukan tampilan.

---

## CBT FLOW

Bagian terpenting. Dua jendela dipakai bergantian.

### Sisi guru — jendela 1, sebagai **Guru**

| # | Langkah | Yang dilihat |
| --- | --- | --- |
| C1 | **Akademik → Ujian Online** | `Ulangan Harian Simulasi`, status **PUBLISHED** |
| C2 | Buka ujiannya | Judul, durasi 30 menit, jendela buka–tutup, kelas-mapel |
| C3 | Tab **soal** | Tiga soal pilihan ganda, bobot 1 masing-masing, kunci tersimpan |
| C4 | *(opsional)* Buat ujian baru: isi judul, durasi, jadwal → simpan sebagai DRAFT → tambah soal + kunci → **Terbitkan** | DRAFT → PUBLISHED. **Tarik Kembali** masih tersedia selama nol pengerjaan |

### Sisi siswa — jendela 2, sebagai **Siswa**

| # | Langkah | Yang dilihat |
| --- | --- | --- |
| C5 | Buka `/login`, masuk | Mendarat di portal siswa `/siswa` — **bukan** panel admin |
| C6 | Menu **Ujian** (`/siswa/ujian`) | Ujian simulasi tampil, berikut sisa waktunya |
| C7 | Buka ujiannya | Pengerjaan dimulai saat halaman dibuka; penghitung waktu berjalan |
| C8 | Jawab soal 1, tekan lanjut | Jawaban tersimpan otomatis saat berpindah soal |
| C9 | Muat ulang halaman di tengah jalan | Jawaban dan sisa waktu **tetap** — pengerjaan dilanjutkan, bukan diulang |
| C10 | Jawab sisanya, **Kumpulkan** | Nilai muncul saat itu juga, dihitung di sisi server |
| C11 | Buka ujiannya lagi | Sudah dikumpulkan; tidak bisa dikerjakan dua kali |

### Kembali ke guru — jendela 1

| # | Langkah | Yang dilihat |
| --- | --- | --- |
| C12 | **Ujian Online** → buka ujian → tab hasil | Nama siswa, waktu kumpul, status, nilai |
| C13 | Tekan **Masukkan ke Nilai** | Pilih jenis nilai; jenis penilaian berbawaan **Formatif** |
| C14 | **Akademik → Daftar Nilai** | Nilai CBT tadi kini menjadi baris nilai biasa |
| C15 | Jendela 2, siswa → menu **Nilai** | Nilai yang sama terlihat siswa |

**Yang harus dikatakan pada C13:** mengumpulkan ujian **tidak pernah**
membuat nilai akademik dengan sendirinya. Angka berpindah hanya lewat tindakan
guru ini. Itu keputusan yang disengaja, bukan keterbatasan.

---

## SISWA FLOW

Jendela 2, masih sebagai **Siswa**.

| # | Langkah | Yang dilihat |
| --- | --- | --- |
| S1 | **Dasbor** (`/siswa`) | Ringkasan kelas dan tahun ajaran aktif |
| S2 | **Jadwal** (`/siswa/jadwal`) | Tiga jam pelajaran |
| S3 | **Nilai** (`/siswa/nilai`) | Nilai per mata pelajaran, per komponen |
| S4 | **Profil** (`/siswa/profil`) | Datanya sendiri saja |
| S5 | **Keluar**, lalu masuk lagi | Kembali ke portal siswa, bukan ke panel |

Portal orang tua: masuk sebagai **Orang Tua** → `/portal` → Ringkasan, Jadwal,
Nilai, Tagihan untuk anaknya.

---

## SECURITY CHECK

Empat pemeriksaan singkat. Lakukan sungguhan — bukan diceritakan.

| # | Langkah | Hasil yang benar |
| --- | --- | --- |
| K1 | Sebagai **Siswa**, ketik `/admin` di alamat | Ditolak. Akun siswa tidak pernah masuk panel |
| K2 | Sebagai **Siswa**, ketik `/portal` | Ditolak. Portal orang tua bukan miliknya |
| K3 | Sebagai **Guru**, ketik `/siswa` | Ditolak |
| K4 | Masuk dengan surel benar + kata sandi salah | Pesan penolakan **sama persis** dengan surel yang tidak terdaftar — sistem tidak memberi tahu surel mana yang ada |

Tambahan bila sempat: nonaktifkan satu akun lewat **Manajemen Akses → Pengguna**,
lalu coba masuk dengan akun itu. Ditolak, dengan pesan yang sama.

---

## EXPECTED RESULT

Simulasi dianggap berhasil bila seluruhnya terjadi:

1. Ketiga peran masuk lewat **satu** halaman `/login` yang sama, dan masing-masing
   mendarat di tempat yang benar tanpa memilih apa pun.
2. Guru hanya melihat kelas dan mapel yang ia ampu.
3. Siswa mengerjakan CBT sampai selesai dan melihat nilainya seketika.
4. Muat ulang di tengah ujian tidak menghilangkan jawaban.
5. Nilai CBT masuk ke daftar nilai **hanya** setelah guru menekan
   "Masukkan ke Nilai".
6. Keempat pemeriksaan keamanan ditolak sebagaimana mestinya.

---

## KNOWN LIMITATIONS

Katakan di depan, bukan saat ditanya.

| Hal | Keadaan |
| --- | --- |
| **Soal uraian (essay)** | Belum ada. Hanya pilihan ganda. Skema sudah menyiapkan tempatnya |
| **Bank soal / pakai ulang soal** | Belum ada |
| **Pengacakan soal & pilihan** | Belum ada |
| **Ujian ulang / reset oleh guru** | Belum ada. Satu percobaan per ujian |
| **Anti-curang, kunci layar penuh, pemantauan tab** | Tidak ada, dan memang tidak dijanjikan |
| **Soal bergambar / audio** | Belum ada |
| **Impor soal dari Excel** | Belum ada |
| **Ulasan jawaban per soal untuk siswa** | Belum ada |
| **Dasbor hasil real-time** | Yang ada: hasil tersimpan dan terlihat, bukan papan langsung |
| **Surel** | Tidak ada surel keluar sama sekali. Karena itu **tidak ada** "Lupa kata sandi" — reset kata sandi lewat admin |
| **Data siswa sungguhan** | Belum masuk. Masih menunggu NIS dan jenis kelamin dari sekolah |
| **Rapor** | Dapat di-generate dari **Akademik → Rapor → Generate Rapor Kelas**; IPA sengaja tanpa konfigurasi sehingga tampil "belum lengkap" — itu perilaku yang benar, bukan kerusakan |

CBT ini adalah **MVP yang dipercepat**, bukan CBT Phase 2 yang lengkap.
Membedakannya di depan lebih murah daripada meluruskannya belakangan.

---

## FEEDBACK TO RECORD

Catat saat rapat, jangan diingat-ingat.

**Keputusan yang ditunggu dari Pak Akbar:**

1. **Semester** tahun ajaran 2026/2027 — Semester 1 atau 2? Kolomnya wajib dan
   menentukan rapor.
2. **Penomoran NIS** untuk siswa yang belum punya. Tanpa ini impor data siswa
   tidak dapat berjalan sama sekali.
3. **Rombel**: cukup satu kelas per tingkat (10, 11, 12), atau perlu dipecah A/B?
4. **BK, Mentoring, Pramuka** — mata pelajaran, ekstrakurikuler, atau tidak
   diwakili di sistem?
5. **Satu percobaan per ujian** — sesuai cara sekolah bekerja?
6. **Admin Sekolah boleh menyusun soal**, atau hanya guru pengampunya?
7. **Nilai manual setelah rapor terbit** — boleh atau ditolak? (CBT sudah
   menolaknya)

**Yang perlu dicatat dari reaksi peserta:**

- Istilah yang membingungkan (nama menu, label tombol).
- Langkah yang terasa berputar atau terlalu banyak klik.
- Hal yang mereka **kira** ada tetapi ternyata belum.
- Kecepatan halaman saat dipakai bersama-sama.
- Apa yang mereka minta duluan bila hanya satu yang bisa dikerjakan pekan ini.
