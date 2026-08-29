# QA Manusia — Daftar Kritis Kandidat Rilis

Batch S9.5.

**Status: BELUM DIJALANKAN.** Seluruh kolom hasil sengaja kosong. Tidak ada
baris di bawah yang boleh ditandai lolos oleh siapa pun yang tidak benar-benar
menjalankannya.

---

## Cara memakai

Daftar ini **bukan** pengganti `docs/responsive-qa.md` (41 baris, khusus tata
letak). Ini daftar terpendek yang, bila seluruhnya lolos, menutup risiko
terbesar sebuah rilis: kebocoran antar-cabang, uang, nilai, dan ujian.

Jalankan pada kandidat rilis — staging atau produksi sebelum diumumkan — bukan
pada mesin pengembang.

Yang dibutuhkan sebelum mulai:

- satu akun per peran: SUPER_ADMIN, SCHOOL_ADMIN, KEPALA_SEKOLAH, GURU,
  WALI_KELAS, BENDAHARA, SISWA, ORANG_TUA;
- **dua cabang** berisi data, sebut Cabang A dan Cabang B. Tanpa cabang kedua,
  H-04 sampai H-06 tidak dapat dijalankan sama sekali;
- satu ponsel sungguhan (bukan hanya devtools) untuk H-15;
- satu ujian CBT terbit dengan jendela waktu terbuka.

Isi kolom **Hasil** dengan `LULUS` atau `GAGAL`. Bila `GAGAL`, isi **Catatan**
dengan langkah yang menghasilkannya — bukan kesimpulan.

---

## Daftar

| # | Area | Yang dikerjakan | Yang harus terjadi | Hasil | Catatan |
| --- | --- | --- | --- | --- | --- |
| H-01 | Halaman muka | Buka `/` di desktop lalu di ponsel | Muat < 3 detik, tanpa gulir mendatar, tombol **Masuk** dan pemilih bahasa terlihat tanpa dipotong | | |
| H-02 | Bahasa | Tekan **EN** di halaman muka, lalu **ID** | Teks berubah, halaman tetap di tempat yang sama, tidak ada teks tertimpa atau meluber | | |
| H-03 | Bahasa (persisten) | Masuk sebagai SISWA, pilih **EN**, keluar, masuk lagi | Portal tetap berbahasa Inggris setelah masuk kembali | | |
| H-04 | PPDB publik | Buka `/ppdb`, pilih cabang, isi formulir sampai selesai di **ponsel** | Formulir terkirim, nomor pendaftaran muncul dan dapat dicek di `/ppdb/cek-status` | | |
| H-05 | Batas SCHOOL_ADMIN | Masuk sebagai admin **Cabang A**. Coba buka data siswa, tagihan, dan nilai **Cabang B** — termasuk dengan menyunting angka id di URL | Tidak ada satu pun data Cabang B yang tampil. Yang muncul 403 atau daftar kosong, bukan data | | |
| H-06 | Batas GURU | Masuk sebagai guru Cabang A. Buka portal guru, daftar kelas, dan Input Nilai. Coba id kelas milik Cabang B lewat URL | Hanya kelas yang diampu yang tampil; id asing ditolak | | |
| H-07 | Batas BENDAHARA | Masuk sebagai bendahara Cabang A. Buka Tagihan Siswa, Transaksi, Laporan Keuangan. Coba id transaksi Cabang B lewat URL | Hanya keuangan Cabang A. Id asing ditolak | | |
| H-08 | Portal siswa | Masuk sebagai SISWA. Buka Jadwal, Nilai, Notifikasi, Profil | Keempatnya tampil dan berisi data siswa itu sendiri | | |
| H-09 | Isolasi anak | Masuk sebagai ORANG_TUA yang punya ≥1 anak. Ganti pemilih anak. Lalu sunting `studentId` di URL rapor menjadi anak orang lain | Pemilih anak bekerja; id anak orang lain ditolak, bukan ditampilkan | | |
| H-10 | CBT alur penuh | Sebagai SISWA: mulai ujian, jawab beberapa soal, tunggu autosave, **tutup tab**, buka lagi, lanjutkan, kumpulkan | Jawaban yang tersimpan kembali seperti semula; timer tidak mengulang dari awal; nilai muncul setelah dikumpulkan | | |
| H-11 | CBT kunci jawaban | Selama mengerjakan, buka **View Source** halaman ujian dan cari kunci jawaban | Tidak ada kunci jawaban di HTML, baik dalam mode ID maupun EN | | |
| H-12 | CBT ke nilai | Sebagai GURU: buka hasil ujian, salurkan nilai ke penilaian akademik | Nilai masuk sebagai komponen yang benar; rapor yang **sudah terbit** tidak ikut berubah | | |
| H-13 | Rapor PDF | Sebagai WALI_KELAS: terbitkan satu rapor, unduh PDF. Buka dengan pembaca PDF sungguhan | Identitas sekolah, nama+NIS+NISN siswa, **nama tahun ajaran memuat semester** (mis. "2024/2025 Semester 1"), nilai per mapel, sikap, ketidakhadiran, catatan, dan blok tanda tangan wali kelas — seluruhnya benar dan tidak terpotong. Tidak ada nama siswa cabang lain | | |
| H-14 | Rapor = portal | Bandingkan angka di PDF dengan yang dilihat ORANG_TUA dan SISWA di portal | Ketiganya sama persis | | |
| H-15 | Keuangan | Sebagai BENDAHARA: catat pembayaran sebagian pada satu tagihan, lalu lunasi sisanya | Status berpindah sebagian → lunas, sisa tagihan benar, dan Laporan Keuangan ikut berubah | | |
| H-16 | Notifikasi + wa.me | Buat pengumuman, buka daftar tautan WhatsApp, tekan satu tautan di **ponsel** | WhatsApp terbuka dengan nomor benar dan pesan sudah terisi, termasuk saat pesan memuat spasi, baris baru, dan tanda baca | | |
| H-17 | Ganti kata sandi | Masuk pertama kali dengan akun baru bertanda ganti-kata-sandi | Dipaksa mengganti sebelum dapat memakai panel; setelah diganti, tanda itu hilang dan tidak diminta lagi | | |
| H-18 | Akun nonaktif | Nonaktifkan satu akun yang **sedang** punya sesi terbuka, lalu muat ulang halamannya | Sesi lama langsung ditolak, bukan tetap berlaku | | |
| H-19 | 360px | Ulangi H-08, H-10, dan H-15 pada layar selebar 360px | Tidak ada gulir mendatar, tidak ada tombol yang tak terjangkau, tidak ada tabel terpotong tanpa penggulung | | |
| H-20 | HTTPS | Buka `http://` domain produksi | Dialihkan ke `https://`, gembok muncul, tidak ada peringatan konten campuran | | |

---

## Bila ada yang GAGAL

Jangan perbaiki di server. Catat langkahnya, kembalikan ke repositori sebagai
temuan, perbaiki di sana, dan jalankan ulang baris itu pada kandidat berikutnya.

H-05 sampai H-07 dan H-09 adalah baris yang tidak boleh ditawar: kegagalan di
salah satunya berarti data satu cabang terlihat oleh cabang lain, dan rilis
harus ditunda.
