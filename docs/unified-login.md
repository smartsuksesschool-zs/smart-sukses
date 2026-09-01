# Pintu Masuk Tunggal

Keputusan pemilik, menggantikan tiga pintu masuk terpisah.

---

## 1. Yang ada sebelumnya

Tiga halaman masuk, satu tabel pengguna:

| Alamat | Bentuk | Untuk |
| --- | --- | --- |
| `/admin/login` | halaman Filament | Admin Sekolah, Kepala Sekolah, Guru, Wali Kelas, Bendahara, Super Admin |
| `/siswa/masuk` | Livewire `Student\StudentLogin` | Siswa |
| `/portal/masuk` | Livewire `Portal\PortalLogin` | Orang Tua |

Ketiganya memakai guard `web`, provider `users`, dan tabel yang sama. Tidak ada
satu pun perbedaan teknis di antaranya — yang berbeda hanya **alamatnya**.

Akibatnya pengunjung harus menjawab pertanyaan yang tidak pernah perlu
ditanyakan: "saya jenis pengguna apa?" Padahal jawabannya sudah tersimpan di
akun yang sedang ia buktikan. Seorang wali murid yang salah menebak akan berakhir
di halaman masuk yang menolaknya dengan pesan yang sengaja tidak menjelaskan
apa-apa.

Efek samping yang lebih halus: **tiga ruang nama throttle**. Masing-masing lima
percobaan per menit, sehingga satu alamat surel sebenarnya punya lima belas.

## 2. Yang berlaku sekarang

Satu alamat: **`GET /login`**, bernama `login`, komponen
`App\Livewire\Auth\Login`.

```
kredensial → Auth::attempt() → peran dibaca dari basis data → pengalihan
```

Tidak ada pemilih peran di formulir. Tidak ada `/login/siswa`, `/login/admin`,
maupun `/login/guru`.

### Peran tidak pernah datang dari request

Ini inti keamanannya, dan sengaja tidak bergantung pada ketiadaan field di
formulir. `App\Support\LoginDestination` **tidak pernah membaca request sama
sekali** — tidak `$request`, tidak query string, tidak properti komponen. Satu-
satunya sumbernya relasi `roles` milik pengguna yang kredensialnya sudah
terbukti.

Nilai `role` yang diselipkan ke query string atau ke payload Livewire karena itu
tidak punya satu pun tempat untuk masuk, dan tesnya membuktikan itu, bukan
sekadar memeriksa bahwa formulirnya tidak punya `<select>`.

## 3. Peta tujuan

| Peran | Tujuan |
| --- | --- |
| Siswa | `student.dashboard` |
| Orang Tua | `portal.dashboard` |
| Guru | panel |
| Wali Kelas | panel |
| Bendahara | panel |
| Kepala Sekolah | panel |
| Admin Sekolah | panel |
| Super Admin | panel |

Guru **tidak** diarahkan ke `/teacher` hanya karena rute itu ada. Hari ini guru
masuk lewat panel dan mendarat di panel; batch ini tidak memindahkan siapa pun
tanpa dasar dari sumber. Portal guru tetap dapat dibuka seperti sebelumnya.

## 4. Tepat satu peran

Aturan produknya satu peran utama per pengguna (PRD 1.1.1). `UserResource`
menegakkannya lewat `maxItems(1)`, dan seluruh seeder serta factory memakai
`syncRoles([$satu])`.

Skema Spatie sendiri many-to-many, jadi keadaan "dua peran" **mungkin** lahir
lewat jalur lain. Ketika itu terjadi ia diperlakukan sebagai **cacat data**, dan
akunnya ditolak masuk:

| Jumlah peran | Perlakuan |
| --- | --- |
| 1 | lanjut |
| 0 | ditolak |
| > 1 | ditolak |

`roles->first()` sengaja tidak dipakai untuk memilih tujuan. Urutannya tidak
ditentukan apa pun, sehingga seseorang dengan dua peran akan mendarat di tempat
yang bergantung pada urutan baris yang kebetulan dikembalikan basis data —
kadang panel, kadang portal. Menebak di sini lebih buruk daripada menolak.

> Catatan: `User::canAccessPanel()` masih memakai `primaryRole()`, yang memang
> `roles->first()`. Itu tidak diubah pada batch ini karena ia perilaku yang sudah
> ada dan diuji; halaman masuk cukup menolak keadaan dua peran sebelum sampai ke
> sana. Bila keadaan itu ingin ditutup rapat, `canAccessPanel()` adalah tempat
> berikutnya yang perlu dilihat.

## 5. Kegagalan yang seragam

Satu kalimat untuk seluruh sebab: surel tak dikenal, kata sandi salah, akun
nonaktif, tanpa peran, berperan ganda, tidak berhak panel, dan tidak lolos
kelayakan portal.

> Email atau kata sandi tidak cocok.

Membedakannya berarti memberi tahu penyerang mana yang benar (butir 115).

Sebab sesungguhnya dicatat ke log aplikasi lewat `LoginDestination::diagnosisFor()`
— **tanpa kata sandi, tanpa surel, tanpa nama**; hanya alasan dan alamat IP.

Satu pengecualian yang disengaja: **kata sandi sementara**. Bagi akun portal ia
bukan penolakan diam-diam melainkan petunjuk ("Kata sandi sementara wajib
diganti…"), dan kalimat itu sudah ada sejak butir 158. Menyeragamkannya akan
menghilangkan satu-satunya petunjuk yang membuat keadaan itu dapat diselesaikan.

## 6. Kata sandi sementara

Logikanya **tidak digandakan**:

- **Staf** — halaman masuk tidak memeriksanya sama sekali. Ia mengantar ke panel,
  dan `EnsurePasswordIsChanged` di dalam panel yang mengalihkan ke halaman ganti
  kata sandi. Sesudah diganti, tujuan normalnya langsung dapat dicapai.
- **Portal** — ditolak di halaman masuk dengan petunjuk di atas, persis seperti
  sebelumnya (`PortalEligibility`).

> **Kesenjangan yang sudah ada, dan tidak diciptakan batch ini:** akun portal
> dengan kata sandi sementara diminta memakai tautan lupa kata sandi, sementara
> pengiriman surel belum berjalan (§8). Hari ini jalan keluarnya adalah admin
> menyetel ulang kata sandi akun tersebut dari panel. Ini menghilang dengan
> sendirinya begitu SMTP diadakan.

## 7. Kompatibilitas rute

Tidak ada penanda halaman yang menjadi 404:

| Alamat lama | Sekarang |
| --- | --- |
| `/siswa/masuk` | 302 → `/login` (nama rute `student.login` tetap ada) |
| `/portal/masuk` | 302 → `/login` (nama rute `portal.login` tetap ada) |
| `/admin/login` | 302 → `/login` (nama rute `filament.admin.auth.login` tetap ada) |

Ketiga **nama rute** dipertahankan karena tiga belas tempat merujuknya —
middleware portal, halaman muka, dan sejumlah tes — termasuk middleware Filament
sendiri.

### Filament

`Panel::login()` menerima `Closure`, dan itulah bentuk terkecil yang tetap
didukung:

```php
->login(fn () => redirect()->route('login'))
```

Rutenya tetap terdaftar dengan nama aslinya, `/admin/login` tetap bukan 404, dan
tamu yang membuka `/admin` tetap dialihkan Filament ke halaman masuknya sendiri —
yang kini meneruskan ke `/login`. **Tidak satu pun internal autentikasi Filament
yang di-fork.**

Halaman reset kata sandi Filament dibiarkan utuh dan tetap berfungsi secara
internal; yang tidak dilakukan hanyalah mengiklankannya.

## 8. Ketergantungan operasional: SMTP

Tautan "Lupa kata sandi?" **sengaja belum ditampilkan**.

Aplikasi ini belum mengirim satu surel pun: tidak ada Mailable, tidak ada
`MailMessage`, tidak ada pemanggilan facade `Mail`, dan `MAIL_MAILER=log`.
Menawarkan pemulihan yang tidak dapat mengirim apa pun lebih buruk daripada tidak
menawarkannya — pengguna akan menunggu surel yang tidak akan pernah datang.

Ini menjadi wajib diadakan sebelum pemulihan kata sandi dapat diaktifkan; lihat
P-03 di `docs/deployment-candidate.md`. Ketika SMTP berjalan, yang perlu
dikerjakan hanya menampilkan kembali tautannya — halaman resetnya sudah ada.

## 9. Yang tidak berubah

Kata sandi tetap di-hash sama, guard tetap `web`, provider tetap `users`, CSRF
tetap berlaku, sesi tetap diregenerasi setelah masuk, peristiwa `Login` tetap
dikirim (sehingga `last_login_at` dan jejak audit tetap tercatat), locale tetap
dibaca `SetUserLocale`, dan seluruh policy, `canAccessPanel()`, global scope
tenant, serta middleware portal tetap berlaku sesudah masuk.

**Halaman masuk hanya memilih tujuan. Otorisasi tetap yang berwenang.**

Kredensial siswa yang sah tetap tidak pernah memperoleh kemampuan admin: ia
diantar ke portal siswa, dan panel tetap menolaknya lewat `canAccessPanel()`.
