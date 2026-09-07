# Pendaftaran Mandiri lewat Google & Persetujuan Akun

Keputusan pemilik (M7, diperluas M7.1). Menggantikan rencana penyediaan akun
siswa, orang tua, **dan staf sekolah** secara manual oleh administrator.

**Tiga jenis pemohon publik:** Siswa, Orang Tua/Wali, dan Staf Sekolah.

**Pendaftaran publik tidak pernah dapat memberikan `SUPER_ADMIN` maupun
`SCHOOL_ADMIN`** — lihat §7.

> **Ini bukan PPDB.** Halaman ini tentang **klaim akun** oleh orang yang sudah
> tercatat di sekolah. Penerimaan siswa baru berjalan lewat Google Form yang
> disetel pemilik — lihat §15.

Dokumen ini menjelaskan **mengapa** alurnya berbentuk seperti ini, bukan hanya
apa saja langkahnya. Requirement aslinya tetap di `smartsukses-docs/`, yang tidak
disunting; penyimpangan teknis per butir dicatat di
[`../implementation-notes.md`](../implementation-notes.md) butir 529–552.

---

## 1. Mengapa Google, dan mengapa bukan sekadar "admin membuatkan akun"

Rencana sebelumnya menuntut administrator mengumpulkan alamat surel setiap siswa
dan orang tua, membuatkan akunnya satu per satu, menetapkan kata sandi awal, lalu
menyampaikan kata sandi itu ke masing-masing orang. Tiga masalahnya nyata:

1. **Pengumpulan alamat surel itu sendiri pekerjaan berbulan-bulan**, dan
   hasilnya sudah usang sebelum selesai.
2. **Kata sandi awal harus disampaikan lewat kanal yang tidak aman** — dibacakan,
   difoto, atau dikirim di grup WhatsApp — dan hampir tidak pernah diganti.
3. **Administrator menjadi penghalang bagi setiap perubahan**: satu orang tua
   yang ganti nomor berarti satu tiket.

Masuk dengan Google memindahkan dua hal pertama ke pihak yang memang sudah
menanganinya: Google memverifikasi alamat surel, dan Google yang menyimpan kata
sandinya. Aplikasi ini tidak pernah melihat, meminta, menyimpan, ataupun
membuatkan kata sandi bagi akun-akun ini.

Yang **tidak** dipindahkan adalah keputusannya. Lihat bagian berikutnya.

---

## 1b. Jenis pemohon bukan peran

Yang dipilih di halaman publik adalah **jenis permintaan**, bukan peran:

| Jenis (`AccountClaimType`) | Dicocokkan dengan | Peran hasilnya |
| --- | --- | --- |
| `SISWA` | NIS + NISN | `SISWA`, otomatis |
| `ORANG_TUA` | NIS + NISN anaknya | `ORANG_TUA`, otomatis |
| `STAF_SEKOLAH` | **tidak ada** — hanya memilih cabang | **dipilih admin** |

`STAF_SEKOLAH` bukan sebuah `RoleName`, tidak ada pada matriks izin PRD 1.1.2,
dan tidak membawa satu izin pun. Ia kalimat *"saya bekerja di sekolah ini"* yang
menunggu dijawab manusia.

Pemisahan ini yang membuat seluruh alur staf aman. Kalau pemohon memilih sebuah
**peran**, maka setiap pagar yang menahannya adalah daftar hitam yang harus
diingat seseorang. Karena ia memilih sebuah **jenis**, nilai `GURU` yang
diselundupkan ke payload tidak sekadar ditolak — ia tidak punya kolom untuk
mendarat.

## 2. NIS dan NISN adalah kunci pencocokan, bukan rahasia autentikasi

Ini keputusan keamanan terpenting di seluruh batch ini.

NIS dan NISN tercetak di kartu pelajar, tertulis di lembar jawaban ujian, beredar
di grup wali murid, dan diketahui setiap teman sekelas. Keduanya **pengenal**,
bukan rahasia — persis seperti nomor rekening: berguna untuk menunjuk sesuatu,
tidak berguna untuk membuktikan kepemilikan.

Karena itu, mengetahui NIS dan NISN seorang anak hanya membuktikan satu hal:

> Permintaan ini menunjuk siswa yang memang ada.

Ia **tidak** membuktikan bahwa pemohon adalah siswa itu, dan sama sekali tidak
membuktikan bahwa ia orang tuanya. Kalau pencocokan saja sudah cukup untuk
membuat akun, maka kartu pelajar yang terjatuh menjadi kunci portal — termasuk
tagihan, nilai, dan rapor anak orang lain.

**Setiap permintaan awal karena itu menunggu persetujuan admin.** Tidak ada satu
pun jalur di dalam kode ini yang berakhir pada akun aktif tanpa seorang manusia
menekan Setujui.

---

## 3. Alur siswa

```
/login  →  "Masuk dengan Google"  →  Google  →  callback
                                                   │
                          sudah punya akun? ───────┤
                                   ya │            │ tidak
                                      ↓            ↓
                          portal/dasbor    /masuk/google/lengkapi
                                                   │
                                    "Saya Siswa" + NIS + NISN
                                                   ↓
                                      permintaan MENUNGGU
                                                   ↓
                                       admin: Setujui / Tolak
```

Aturan pencocokannya (`App\Services\Auth\StudentClaimMatcher`):

| Keadaan | Hasil internal | Yang dilihat pemohon |
| --- | --- | --- |
| NIS dan NISN menunjuk satu siswa yang sama | `MATCHED` | permintaan terkirim |
| NIS menunjuk siswa A, NISN menunjuk siswa B | `IDENTITY_CONFLICT` | *Data tidak dapat dicocokkan.* |
| salah satu nilai tidak menunjuk siapa pun | `MATCH_FAILED` | *Data tidak dapat dicocokkan.* |
| siswanya sudah punya akun untuk peran itu | `ACCOUNT_ALREADY_LINKED` | *Data tidak dapat dicocokkan.* |

Empat keadaan, **satu kalimat**. Membedakannya di layar akan mengubah formulir
ini menjadi alat menebak NIS: "tidak ditemukan" dan "NIS benar, NISN salah"
adalah dua jawaban yang sangat berbeda nilainya bagi orang yang sedang menebak.
Perbedaannya tetap ada di catatan internal, tempat ia berguna dan tidak berbahaya.

Hal-hal yang **tidak pernah** dipakai mencocokkan:

* **nama** — tidak unik, ejaannya tidak stabil, dan dua siswa bernama sama akan
  menjadi satu;
* **cabang** — pemohonnya tamu dan tidak punya cabang; yang mempersempit hasilnya
  adalah pasangan NIS + NISN, dan cabang justru **diturunkan** dari siswa yang
  cocok;
* **apa pun dari request** selain kedua nilai itu.

Siswa yang statusnya bukan `ACTIVE`, dan siswa di cabang yang sudah dinonaktifkan,
tidak pernah ikut dicocokkan.

---

## 4. Alur orang tua

Sama persis sampai pencocokan, dengan satu perbedaan yang menentukan: **anak yang
cocok tidak membuktikan hubungan apa pun.**

Yang dikatakan sebuah permintaan orang tua hanyalah:

> Identitas Google ini mengaku berkaitan dengan siswa ini.

Persetujuan admin karena itu bukan formalitas melainkan satu-satunya pembuktian
yang ada. Admin melihat surel terverifikasi, nama pada akun Google, nama siswa,
kelasnya, nama orang tua pada data induk, dan keadaan tautan yang berlaku
sekarang — cukup untuk mencocokkannya dengan yang ia ketahui, atau untuk
mengangkat telepon.

Sesudah disetujui:

* akun `ORANG_TUA` dibuat (atau dipakai ulang — lihat §5);
* `students.parent_user_id` diisi;
* portal orang tua menampilkan **hanya** anak yang tautannya sudah disetujui.

---

## 4b. Alur staf sekolah

```
/login → "Masuk dengan Google" → Google → callback → /masuk/google/lengkapi
                                                          │
                                              "Saya Staf Sekolah"
                                                          │
                                                 pilih cabang
                                                          ↓
                                        permintaan STAF_SEKOLAH — MENUNGGU
                                                          ↓
                            admin memilih peran: GURU / WALI_KELAS /
                                     BENDAHARA / KEPALA_SEKOLAH
                                                          ↓
                                          akun aktif → panel admin
```

**Tidak ada yang dicocokkan.** Sekolah ini tidak memegang satu pun pengenal induk
pegawai: tidak ada tabel guru, dan NIP bukan data yang dipegang aplikasi ini.
Mengarang sebuah pengenal hanya supaya alurnya terlihat setara dengan alur siswa
akan menghasilkan pagar yang tidak menahan apa pun.

Sebuah permintaan staf karena itu berarti tepat satu hal:

> Identitas Google terverifikasi ini meminta akses sebagai staf di cabang ini.

Persetujuan admin di sini bukan lapisan tambahan — ia satu-satunya lapisan yang
ada, dan itulah sebabnya perannya tidak boleh ikut dipilih pemohon.

**Cabang dipilih pemohon** dari daftar cabang aktif. Daftar itu sudah publik
(halaman PPDB menampilkannya), jadi tidak ada keterangan baru yang dibuka. Ia
bukan bukti apa-apa; ia menentukan **antrean siapa** yang akan membacanya —
tanpa cabang, barisnya tidak akan pernah terlihat Admin Sekolah mana pun, karena
global scope tenant menyaring tepat pada kolom itu.

Permintaan staf tidak menyentuh satu baris siswa pun: `student_id` bernilai NULL,
dan tidak ada `user_id` maupun `parent_user_id` yang diisi.

## 5. Anak kedua dan seterusnya

Satu akun Google berhak atas **satu** pengguna. Orang tua dengan dua anak karena
itu menjadi satu `users` dengan dua tautan, bukan dua akun bersurel sama.

Setiap anak diminta dan disetujui **terpisah**. Tidak ada satu pun kesimpulan
yang ditarik dari nama orang tua, nomor telepon, alamat, atau surel pada data
induk: dua siswa dengan `parent_name` dan `parent_phone` yang identik tetap
memerlukan dua permintaan dan dua persetujuan.

Jalan masuknya: setelah masuk ke portal, tautan **"Daftarkan anak lainnya"** pada
dasbor orang tua membuka kembali halaman pencocokan. Tanpa tautan itu alurnya
mustahil dijangkau — begitu anak pertama disetujui, akun orang tuanya sudah ada,
sehingga setiap "Masuk dengan Google" berikutnya langsung mendarat di portal.

Di halaman itu perannya **terkunci** `ORANG_TUA`: akun yang sudah ada tidak dapat
memakainya untuk meminta peran kedua.

### Batasan skema yang diketahui

| Batasan | Akibatnya | Sumbernya |
| --- | --- | --- |
| Satu siswa hanya punya **satu** akun orang tua | ayah dan ibu tidak dapat punya akun sendiri-sendiri untuk anak yang sama | `students.parent_user_id` tunggal (ERD 2.2) |
| Satu akun hanya berada di **satu** cabang | orang tua dengan anak di dua cabang tidak dapat memakai satu akun | `users.school_id` tunggal (ERD 2.2) |

Keduanya **tidak** diakali di batch ini. Yang kedua ditolak secara eksplisit saat
persetujuan, dengan pesan yang menerangkan sebabnya kepada admin. Mengubahnya
berarti mengganti relasi satu-ke-banyak menjadi tabel pivot — perubahan ERD yang
menyentuh portal orang tua, penyaringan notifikasi, tagihan, dan rapor, dan itu
keputusan pemilik, bukan efek samping sebuah batch autentikasi.

---

## 6. Kata sandi

Akun yang lahir dari Google **tidak punya kata sandi**: `users.password` kini
nullable, dan kolomnya diisi `NULL`.

Alternatifnya adalah menyimpan hash acak yang tidak dapat dipakai siapa pun.
Itu ditolak karena nilai semacam itu berbohong tentang keadaan akun: setiap
pembaca kolomnya — sekarang dan bertahun-tahun lagi — akan mengira akun itu punya
kata sandi. `NULL` mengatakan yang sebenarnya.

Aman tanpa aturan tambahan apa pun: `AbstractHasher::check()` mengembalikan
`false` untuk hash `NULL`, sehingga `Auth::attempt()` atas akun Google selalu
gagal — termasuk dengan kata sandi kosong.

Yang **tidak** dilakukan:

* tidak menyimpan kata sandi Google;
* tidak meminta kata sandi Google;
* tidak membuat kata sandi bersama;
* tidak mencetak kata sandi yang dibangkitkan ke mana pun.

Akun staf berkata sandi **tidak tersentuh**. Kolomnya bertambah dengan nilai
`NULL`, tidak ada satu baris pun yang diubah, dan halaman masuk berkata sandi
bekerja persis seperti sebelumnya.

> **Catatan.** Fitur reset kata sandi panel secara teknis memungkinkan pemilik
> kotak surel menyetel kata sandi pada akun Google-nya. Itu bukan celah — yang
> diperlukan tetap penguasaan kotak surel yang sama yang diverifikasi Google —
> tetapi perlu diketahui: akun Google **dapat** memperoleh kata sandi bila
> pemiliknya sendiri memintanya lewat surel.

---

## 7. Persetujuan admin

Menu **Manajemen Akses → Permintaan Akun**.

| | |
| --- | --- |
| **Izin** | modul `user` pada matriks PRD 1.1.2 — `user.view` untuk melihat, `user.manage` untuk memutuskan |
| **Yang berhak** | Super Admin, Admin Sekolah |
| **Yang tidak** | Kepala Sekolah, Guru, Wali Kelas, Bendahara, Siswa, Orang Tua |
| **Isolasi** | global scope tenant + policy per record; permintaan cabang lain tidak terlihat dan tidak dapat disetujui |

Tidak ada modul izin baru. Permintaan akun adalah permintaan membuat sebuah
`users`, jadi ia memakai izin modul "User Management" apa adanya — menambahkan
modul ke-17 berarti menambahkan baris yang tidak ada di dokumen sumber mana pun.

Yang dapat dilakukan admin persis dua: **Setujui** dan **Tolak**. Tidak ada
penyuntingan, tidak ada pembuatan manual, tidak ada penghapusan, dan **tidak ada
aksi massal** — menyetujui berarti menaut seseorang ke seorang anak, atau
memberinya kewenangan di dalam panel, dan tombol "setujui 40 yang tercentang"
adalah cara termurah untuk melewatkan seluruhnya tanpa membaca satu pun.

### Peran yang dapat diberikan seorang peninjau

Untuk permintaan **siswa** dan **orang tua**, perannya mengikuti jenis
permintaannya dan tidak dapat dipilih. Pemilih peran tidak muncul sama sekali di
sana — kalau muncul, ia menjadi kesempatan menyetujui permintaan siswa sebagai
bendahara.

Untuk permintaan **staf**, admin memilih tepat satu dari:

| Boleh diberikan | Tidak pernah dapat diberikan lewat jalur ini |
| --- | --- |
| `GURU` | `SCHOOL_ADMIN` |
| `WALI_KELAS` | `SUPER_ADMIN` |
| `BENDAHARA` | `SISWA`, `ORANG_TUA` |
| `KEPALA_SEKOLAH` | |

Sebabnya: `SCHOOL_ADMIN` dan `SUPER_ADMIN` sama-sama dapat **membuat pengguna
lain**, dan `SUPER_ADMIN` bahkan melewati seluruh policy lewat `Gate::before`.
Sebuah jalur publik yang dapat berakhir di salah satunya berarti pendaftaran
mandiri yang, dengan satu kekeliruan seorang admin, menyerahkan seluruh cabang —
atau seluruh platform.

`SCHOOL_ADMIN` tetap dibuat lewat menu **Pengguna** yang sudah ada, tempat
pembuatnya sudah terbukti dan tercatat. `SUPER_ADMIN` tetap milik platform.

Daftar putihnya ditegakkan **dua kali**: sebagai pilihan pada formulir Filament,
dan lagi di dalam `AccountClaimReviewer`. Yang pertama menahan kekeliruan; yang
kedua menahan pemanggilan kode di kemudian hari yang melewati formulir sama
sekali.

Surel **disamarkan di daftar** (`bu•••@example.test`) dan utuh di halaman
rincian. Daftar dibuka untuk menyapu antrean dan sering tampil di layar yang
dilihat bersama-sama; rinciannya dibuka justru untuk memutuskan.

Persetujuan berjalan di dalam satu transaksi, dan setiap syarat diperiksa ulang
di dalamnya atas baris yang sudah dikunci. Lapisan terakhirnya bukan kode
melainkan indeks unik `account_claims_approved_unique`.

---

## 8. Perlindungan pengambilalihan

| Skenario | Yang menahannya |
| --- | --- |
| mengklaim siswa yang sudah punya akun | pemeriksaan tautan saat pencocokan **dan** saat persetujuan, atas baris terkunci |
| dua identitas Google atas satu siswa | indeks unik `(requested_role, student_id, approved_marker)` |
| identitas Google yang sama mengklaim dua siswa sebagai SISWA | ditolak saat persetujuan |
| klaim orang tua menimpa tautan akun siswa | dua kolom terpisah; masing-masing hanya disentuh perannya sendiri |
| surel Google sama dengan surel akun yang sudah ada | ditolak; **tidak** digabungkan diam-diam |
| satu identitas Google dipakai dua `users` | indeks unik `(auth_provider, provider_subject)` |
| dua permintaan menunggu yang identik | indeks unik `(provider, provider_subject, requested_role, student_id, pending_marker)` |
| klik Setujui dua kali | baris dikunci, status diperiksa ulang, lalu indeks unik |
| meminta sebuah peran dari jalur publik | tidak ada kolom perannya: `requested_type` hanya menerima `AccountClaimType` |
| admin memberikan SCHOOL_ADMIN/SUPER_ADMIN lewat klaim | daftar putih `AccountClaim::REVIEWER_ASSIGNABLE_ROLES` pada formulir **dan** pada service |
| menyetujui permintaan staf tanpa peran | ditolak `AccountClaimException::roleRequired()` |
| satu identitas Google memperoleh dua akun staf | indeks unik `(auth_provider, provider_subject)` pada `users` |
| menumpuk permintaan staf yang menunggu | kunci unik `pending_key`, yang menyusun ketiadaan siswa menjadi nilai tetap |

Tidak ada satu pun akun yang digabungkan secara diam-diam. Setiap benturan
berhenti sebagai keputusan admin.

---

## 8b. Guru: tidak ada tabel kedua yang perlu ditaut

Temuan arsitektur M7.1, dan ia menyederhanakan seluruh alur staf.

**Project ini tidak punya model maupun tabel `teachers`.** Guru **adalah** sebuah
`users` yang berperan `GURU` atau `WALI_KELAS`, dan kedua kolom yang menyebut
guru menunjuk `users` secara langsung:

| Kolom | Menunjuk | Dipilih di |
| --- | --- | --- |
| `classes.homeroom_teacher_id` | `users` berperan `WALI_KELAS` | Kelas → Wali Kelas |
| `class_subjects.teacher_id` | `users` berperan `GURU` atau `WALI_KELAS` | Kelas → Mata Pelajaran |

Akibatnya seluruh bahaya yang biasanya menyertai "penautan ke record guru" tidak
dapat terjadi di sini:

* tidak ada yang perlu dicocokkan dari nama — tidak ada yang bisa dicocokkan;
* tidak ada record guru yang dapat dikarang diam-diam — tidak ada tabelnya;
* tidak ada duplikat guru yang dapat lahir — sama, tidak ada tabelnya;
* guru cabang lain tidak pernah ikut ditawarkan, karena kedua pemilih memakai
  `User::query()` yang membawa global scope tenant.

Menyetujui permintaan staf sebagai `GURU` karena itu **sudah** membuat identitas
gurunya. Akun itu langsung muncul di kedua pemilih, tanpa satu langkah penautan
tambahan dan tanpa mapping paralel apa pun.

### Penugasan tetap milik layar yang sudah ada

Persetujuan menetapkan **identitas + peran**, dan berhenti di situ. Yang berikut
ini tidak pernah ditanyakan kepada pemohon dan tidak diduplikasi ke dalam layar
Permintaan Akun:

| Penugasan | Layarnya |
| --- | --- |
| mata pelajaran yang diampu | Kelas → relasi Mata Pelajaran (`class_subjects`) |
| kelas yang diajar | layar yang sama |
| kelas perwalian | Kelas → field Wali Kelas |
| jadwal | Jadwal |

### Wali kelas tetap satu orang, satu akun

Akun yang disetujui sebagai `WALI_KELAS` **tidak** memerlukan akun `GURU` kedua,
identitas Google kedua, atau record guru kedua.

Matriks izin PRD 1.1.2 sudah menyusunnya demikian: `WALI_KELAS` memiliki seluruh
izin `GURU` (`student.view`, `class_schedule.view`, `grade.manage`,
`student_portal.view`) **ditambah** `report_card.manage`. Pemilih "Guru Pengajar"
pun memuat `GURU` dan `WALI_KELAS` sekaligus, dan portal guru menerima keduanya.
Satu akun mengajar dan mewalikan; ini diuji, bukan diasumsikan.

---

## 9. Pembatasan laju

| Jalur | Batas |
| --- | --- |
| `/masuk/google`, `/masuk/google/callback`, `/masuk/google/lengkapi` | 20 per menit per IP |
| pengiriman NIS/NISN | 5 per 10 menit, per identitas Google **dan** IP |

Yang dilindungi batas kedua bukan satu akun melainkan seluruh daftar siswa:
percobaan berulang atas NIS yang berganti-ganti adalah pemindaian, bukan lupa.
Ia lebih ketat daripada halaman masuk berkata sandi karena akibat keberhasilannya
lebih luas.

Batas kedua tinggal di dalam komponen Livewire-nya, bukan di rute: kiriman
Livewire tidak melewati rute halaman itu.

---

## 10. Privasi & pencatatan

**Tidak pernah dicatat:** token akses, token penyegar, NIS mentah, NISN mentah,
surel pemohon, isi payload permintaan, kata sandi, header otorisasi.

**Yang dicatat:** id permintaan, peran yang diminta, id siswa, status, id
peninjau, waktu, dan IP pada baris penolakan.

**Token Google tidak disimpan sama sekali.** Setelah callback, aplikasi ini tidak
pernah memanggil API Google lagi, jadi tidak ada satu pun yang perlu disimpan
untuk dipakai nanti. Skemanya sendiri tidak menyediakan tempatnya.

NIS dan NISN **tidak disalin** ke `account_claims`. Keduanya dipakai sekali untuk
menemukan `student_id`, dan sesudah itu `student_id` sudah menyebut baris induknya
dengan lebih tepat daripada salinan yang dapat basi.

Yang dititipkan ke sesi antara callback dan formulir hanya tiga nilai: `subject`,
`email`, `name`.

---

## 11. Peran Google Form sesudah batch ini

Google Form yang selama ini dipakai mengumpulkan data akun **tidak lagi menjadi
mekanisme utama pembuatan akun**. Data yang sudah terkumpul tidak dihapus.

Pembagian kewenangannya sekarang:

| Sumber | Menentukan |
| --- | --- |
| **Google OAuth** | identitas akun — siapa yang masuk, dan surel apa yang terbukti miliknya |
| **Data induk siswa (TU)** | identitas siswa — NIS, NISN, nama, kelas, status |
| **Data staf** | tidak dipegang aplikasi ini; nama, mapel, dan penugasan dimasukkan lewat layar admin seperti sebelumnya |
| **Permintaan akun** | hubungan antara keduanya, dan siapa yang menyetujuinya |
| **Peninjau (admin)** | peran staf — satu-satunya sumber kewenangan, tidak pernah dari pemohon |
| **Google Form / pendataan kontak** | keterangan kontak tambahan (nomor telepon), **opsional** |

Google Form tidak pernah lagi menjadi tempat kata sandi dikumpulkan atau
dibagikan.

---

## 12. Konfigurasi

```env
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI="${APP_URL}/masuk/google/callback"
```

Ketiganya kosong = fitur mati: tombol "Masuk dengan Google" tidak muncul, dan
ketiga rutenya menjawab 404. Lingkungan yang belum disetel karena itu tidak
memperlihatkan pintu yang tidak dapat dibuka.

`GOOGLE_REDIRECT_URI` harus sama **persis** dengan Authorized redirect URI pada
Google Cloud Console, termasuk skema dan port.

Tidak ada satu pun kredensial di dalam repository. `.env.example` hanya memuat
namanya, dengan nilai kosong.

Cakupan yang diminta hanya `openid profile email` — layar persetujuan Google
tidak pernah meminta orang tua menyerahkan kalender, kontak, atau Drive-nya.

---

## 13. Batas cakupan saat ini

Keputusan berikut sudah diambil dan **bukan** penghalang implementasi. Ia dicatat
di sini supaya tidak dibuka lagi sebagai pertanyaan terbuka:

| Hal | Keputusan saat ini |
| --- | --- |
| ayah dan ibu punya akun terpisah untuk satu anak | **ditunda**; skema satu-orang-tua-per-anak tetap berlaku |
| satu orang tua lintas cabang | **ditunda**; skema satu-cabang-per-akun tetap berlaku |
| persetujuan otomatis orang tua | **dimatikan** |
| surel orang tua sebagai data berwenang | **tidak diperlukan** untuk alur persetujuan manual saat ini |
| kredensial Google Cloud | konfigurasi staging/deployment, **tidak diperlukan** untuk implementasi maupun test lokal |

---

## 13b. Yang **belum** dilakukan, dan syaratnya

### Persetujuan otomatis untuk orang tua

**Tidak diaktifkan, dan tidak boleh diaktifkan hanya dari NIS/NISN.**

Aturan yang lebih kuat *dapat* ditambahkan kelak, dengan tiga syarat sekaligus:

1. surel Google terverifikasi, **dan**
2. surel itu cocok dengan alamat orang tua yang tersimpan pada data induk sebagai
   sumber yang berwenang, **dan**
3. NIS/NISN anaknya cocok.

Syarat kedua belum dapat dipenuhi hari ini: `students.parent_email` diisi dari
pendataan yang tidak diverifikasi siapa pun, sehingga memakainya sebagai bukti
sama saja dengan mempercayai ketikan orang lain. Selama itu belum berubah,
otomatisasi ini **tidak boleh** dinyalakan.

### Persetujuan otomatis untuk siswa

Dapat dipertimbangkan setelah UAT, tetapi batch ini secara sengaja memilih
persetujuan eksplisit untuk keduanya.

### Keputusan yang masih milik pemilik

1. Apakah ayah **dan** ibu perlu akun terpisah untuk anak yang sama (menuntut
   perubahan ERD).
2. Apakah orang tua dengan anak di dua cabang perlu dilayani satu akun (menuntut
   perubahan ERD).
3. Apakah alamat surel orang tua akan dijadikan data berwenang yang diverifikasi
   TU — prasyarat satu-satunya bagi persetujuan otomatis.
4. Kapan Google Cloud project dan OAuth consent screen disiapkan, dan atas nama
   domain apa.

---

## 14. Cabang yang sedang disembunyikan

Sejak M7.2 hanya cabang yang **aktif** yang menerima pendaftaran akun. Cabang
Bandung sementara disembunyikan lewat `schools.is_active = false`.

Akibatnya pada alur di dokumen ini:

* pemilih cabang permintaan staf tidak menawarkannya;
* siswa dan orang tua di cabang itu tidak dapat mengklaim akun, karena
  `StudentClaimMatcher` ikut menyaring cabang aktif.

Arsitektur multi-cabang **tidak** dihapus: barisnya, datanya, `school_id` di
seluruh model bisnis, dan global scope tenant semuanya utuh. Mengaktifkannya
kembali adalah satu tombol di Manajemen Cabang.

Ketika hanya satu cabang yang aktif, pemilih cabang tidak ditampilkan sama
sekali — cabangnya diisi sendiri dan disebutkan sebagai keterangan. Pemilihnya
muncul kembali sendiri begitu ada dua cabang aktif.

---

## 15. Pendaftaran siswa baru (PPDB) — alur yang berbeda

Mudah tertukar karena keduanya berbunyi "mendaftar":

| | Untuk siapa | Tujuan |
| --- | --- | --- |
| **PPDB** | calon siswa, belum tercatat di basis data ini | Google Form pada `ppdb_url` |
| **Klaim akun Google** | siswa/orang tua/staf yang **sudah** tercatat | `/login` → Masuk dengan Google |

Sejak M7.2 seluruh CTA pendaftaran — navigasi, hero, bagian PPDB, footer, dan
tautan "Belum menjadi siswa?" di halaman masuk — menunjuk satu alamat yang sama,
yaitu `ppdb_url`. `/ppdb` dan `/ppdb/{kode}` mengalihkan ke sana (302) agar
penanda halaman lama tetap hidup.

Seorang pengunjung yang menekan "Daftar PPDB" **tidak pernah** masuk ke alur
klaim akun Google.

---

## 16. Berkas terkait

| Berkas | Perannya |
| --- | --- |
| `app/Http/Controllers/Auth/GoogleAuthController.php` | redirect + callback OAuth |
| `app/Livewire/Auth/GoogleClaim.php` | halaman pencocokan NIS/NISN |
| `app/Services/Auth/StudentClaimMatcher.php` | aturan pencocokan |
| `app/Services/Auth/AccountClaimRegistrar.php` | pembuatan permintaan |
| `app/Services/Auth/AccountClaimReviewer.php` | persetujuan & penolakan |
| `app/Filament/Resources/AccountClaimResource.php` | layar admin |
| `app/Policies/AccountClaimPolicy.php` | siapa yang boleh memutuskan |
| `app/Models/AccountClaim.php` | model + kunci keunikan + daftar putih peran peninjau |
| `app/Enums/AccountClaimType.php` | ketiga jenis pemohon — bukan peran |
| `tests/Feature/Auth/GoogleSignInTest.php` | lapisan OAuth |
| `tests/Feature/Auth/AccountClaimStudentTest.php` | alur siswa |
| `tests/Feature/Auth/AccountClaimParentTest.php` | alur orang tua |
| `tests/Feature/Auth/AccountClaimStaffTest.php` | alur staf + batas guru/wali kelas |
| `tests/Feature/Auth/AccountClaimSecurityTest.php` | pagar keamanan |
| `tests/Feature/Admin/AccountClaimReviewUiTest.php` | layar persetujuan |
