# Smart Sukses School — API Specification

---

| Parameter | Detail |
|---|---|
| **Nama Produk** | Smart Sukses School |
| **Base URL** | `https://apps.smartsukses.sch.id/api/v1` |
| **Versi Dokumen** | v1.0 |
| **Sumber Utama** | `blueprint/SmartSukses_FullBlueprint_v1.0.0.docx` Bagian 4 (API Endpoint Map) · `docs/01-PRD.md` v1.1 · `docs/02-ROADMAP.md` v1.1 · `docs/03-USER_FLOW.md` v1.0 · `docs/04-ERD.md` v1.0 · `docs/05-DATABASE.md` v1.0 |
| **Jumlah Endpoint** | **101 endpoint** dalam 11 kelompok |
| **Autentikasi** | Laravel Sanctum — `Authorization: Bearer {token}` |
| **Format** | `application/json` |
| **Sifat Dokumen** | Turunan blueprint. Tidak menambah endpoint, requirement, maupun aturan baru. |
| **Kerahasiaan** | KONFIDENSIAL — hanya untuk penggunaan internal |

> **Konvensi dokumen:** Setiap informasi yang tidak tercantum dalam blueprint ditulis sebagai **"Belum dijelaskan dalam blueprint."**

> **Batasan dokumen:** Dokumen ini **tidak memuat kode Laravel, Controller, Route, maupun Swagger/OpenAPI** — hanya spesifikasi.

---

## Daftar Isi

| No | Section |
|---|---|
| 1 | [Overview](#1-overview) |
| 2 | [API Design Principles](#2-api-design-principles) |
| 3 | [Authentication API](#3-authentication-api) |
| 4 | [Module APIs](#4-module-apis) |
| 5 | [Request Validation](#5-request-validation) |
| 6 | [Response Format](#6-response-format) |
| 7 | [Error Handling](#7-error-handling) |
| 8 | [Permission Matrix](#8-permission-matrix) |
| 9 | [Security](#9-security) |
| 10 | [Future API](#10-future-api) |
| 11 | [Lampiran](#11-lampiran) |

---

## 1. Overview

### 1.1 Tujuan Dokumen

Dokumen ini menyusun **API Specification** sebagai acuan implementasi backend Laravel.

| ID | Tujuan | Penjelasan |
|---|---|---|
| **TA-01** | Mendaftarkan **seluruh 101 endpoint** beserta method, URL, auth level, dan deskripsinya | Blueprint Bagian 4 |
| **TA-02** | Menetapkan **konvensi API** yang berlaku seragam | Blueprint §4.1 |
| **TA-03** | Memetakan **aturan validasi** setiap endpoint ke acceptance criteria yang menjadi sumbernya | PRD §9 |
| **TA-04** | Memetakan **hak akses** setiap endpoint ke matriks izin 8 role | PRD §8.2 |
| **TA-05** | Menandai secara eksplisit bagian spesifikasi yang **belum dijelaskan blueprint** | Konvensi dokumen |
| **TA-06** | Menjadi dasar penyusunan skenario pengujian endpoint | Roadmap §2.4 |

### 1.2 Dokumen Ini Diturunkan dari Blueprint, PRD, User Flow, ERD, dan Database

```
blueprint/SmartSukses_FullBlueprint_v1.0.0.docx   ← sumber kebenaran tunggal
   Bagian 1 (PRD) · Bagian 2 (ERD) · Bagian 3 (Arsitektur) · Bagian 4 (API Map)
        │
        ├─▶ docs/01-PRD.md        → 38 FR · 12 NFR · 56 CON · matriks izin
        ├─▶ docs/02-ROADMAP.md    → fase · sprint · risiko
        ├─▶ docs/03-USER_FLOW.md  → alur pengguna · 6 lapis validasi
        ├─▶ docs/04-ERD.md        → 21 entitas · 56 relasi
        ├─▶ docs/05-DATABASE.md   → 219 kolom · tipe data · constraint
        │         │
        │         └─▶ docs/06-API.md   ← DOKUMEN INI
```

**Kontribusi tiap sumber terhadap dokumen ini:**

| Sumber | Menyumbang |
|---|---|
| **Blueprint Bagian 4** | Daftar 101 endpoint: method, URL, auth level, deskripsi · konvensi API |
| **Blueprint Bagian 3** | Mekanisme autentikasi, otorisasi, isolasi tenant, rate limiting, CSRF, audit log |
| **`01-PRD.md`** | Aturan validasi (dari acceptance criteria) · matriks izin 8 role · constraint CON-01…56 |
| **`03-USER_FLOW.md`** | Enam lapis validasi request · alur error |
| **`04-ERD.md`** | Relasi antar entitas yang memengaruhi struktur response |
| **`05-DATABASE.md`** | Nama kolom, tipe data, nullability, dan nilai ENUM untuk request/response |

### 1.3 Cakupan dan Batasan

| Tercakup | Tidak Tercakup |
|---|---|
| 101 endpoint Phase 1 dari Blueprint Bagian 4 | Endpoint Phase 2 (LMS, Presensi, BK, Payroll, dll.) |
| Konvensi API, autentikasi, otorisasi, isolasi tenant | Skema request/response per endpoint — **sebagian besar belum dijelaskan blueprint** |
| Aturan validasi yang bersumber dari acceptance criteria | Aturan validasi baru di luar blueprint |
| Matriks hak akses per endpoint | Kode HTTP status per skenario — **belum dijelaskan blueprint** |

### 1.4 Catatan Penting tentang Tingkat Kedalaman Blueprint

Blueprint Bagian 4 menyajikan API sebagai **peta endpoint (endpoint map)**, bukan spesifikasi lengkap. Untuk setiap endpoint, blueprint mencantumkan:

| Tersedia di blueprint | Tidak tersedia di blueprint |
|---|---|
| ✔ HTTP Method | ✘ Skema request body per endpoint |
| ✔ URL path | ✘ Skema response body per endpoint |
| ✔ Auth Level (Public / Auth / Admin / Super) | ✘ Kode HTTP status per skenario |
| ✔ Deskripsi singkat fungsi | ✘ Daftar parameter query lengkap |
| ✔ Sebagian menyebut filter yang tersedia | ✘ Contoh payload |
| ✔ Empat endpoint menyebut isi body secara ringkas | ✘ Aturan paginasi per endpoint |

> **Konsekuensi:** pada dokumen ini, bagian **Request** dan **Response** untuk mayoritas endpoint ditandai **"Belum dijelaskan dalam blueprint."** Bila terdapat informasi yang dapat diturunkan dari acceptance criteria PRD atau definisi kolom pada `05-DATABASE.md`, sumbernya dicantumkan secara eksplisit dan ditandai sebagai **turunan**, bukan ketentuan blueprint.

---

## 2. API Design Principles

### 2.1 Konvensi Umum (Blueprint §4.1)

| Elemen | Konvensi | Status |
|---|---|:---:|
| Base URL | `https://apps.smartsukses.sch.id/api/v1` | ✔ Blueprint |
| Content-Type | `application/json` | ✔ Blueprint |
| Auth Header | `Authorization: Bearer {token}` (Laravel Sanctum) | ✔ Blueprint |
| Response sukses | `{ "success": true, "data": {...}, "message": "..." }` | ✔ Blueprint |
| Response error | `{ "success": false, "message": "...", "errors": {...} }` | ✔ Blueprint |
| Pagination | `{ "data": [...], "meta": { "total", "page", "per_page", "last_page" } }` | ✔ Blueprint |
| Timestamp format | ISO 8601 — `2025-08-06T10:30:00+07:00` | ✔ Blueprint |
| Tenant isolation | Semua endpoint Auth otomatis di-scope ke `school_id` user yang login | ✔ Blueprint |

### 2.2 Authentication

| Aspek | Ketentuan | Dasar |
|---|---|---|
| Mekanisme | **Laravel Sanctum (SPA mode)** | Blueprint §3.4 |
| Token web | Cookie-based session token | Blueprint §3.4 |
| Token API | Bearer token — disiapkan untuk API mobile *future* | Blueprint §3.4 |
| Header | `Authorization: Bearer {token}` | Blueprint §4.1 |
| Masa berlaku | Token kedaluwarsa setelah **8 jam tidak aktif** | AUTH-01 AC-4; CON-29 |
| Perpanjangan | `POST /auth/refresh` | Blueprint §4.2 |
| Hash password | Argon2id, minimal 8 karakter | Blueprint §3.4; NFR-07 |
| Ganti password pertama | **Wajib** saat login pertama | NFR-07; CON-27 |

> **⚠ Catatan konsistensi:** NFR-05 menyebut *"JWT + session"*, sedangkan §3.4 dan seluruh API Map menyebut **Laravel Sanctum**. Blueprint memuat dua pernyataan berbeda. (PRD §17.3 #10; Roadmap RSK-11)

### 2.3 Authorization

Blueprint menetapkan **empat Auth Level** (§4.1):

| Auth Level | Ketentuan | Jumlah Endpoint |
|---|---|:---:|
| **Public** | Tidak perlu token — akses bebas | 6 |
| **Auth** | Wajib token; akses dibatasi ke data sekolah user tersebut | 40 |
| **Admin** | Wajib token + role `SCHOOL_ADMIN` / `SUPER_ADMIN` | 48 |
| **Super** | Wajib token + role `SUPER_ADMIN` | 7 |
| | **TOTAL** | **101** |

**Lapisan otorisasi tambahan (Blueprint §3.4):**

| Lapisan | Mekanisme |
|---|---|
| RBAC | `spatie/laravel-permission` + Gate |
| Policy per model | `StudentPolicy`, `GradePolicy`, dll. |
| Matriks izin modul | 15 modul × 8 role (PRD §8.2) |

**Aturan akses per-record yang dinyatakan blueprint:**

| Aturan | Endpoint terkait |
|---|---|
| Guru hanya melihat siswa kelas yang diampunya | `GET /students` |
| Guru hanya dapat input nilai untuk kelas yang diampunya | `POST /grades` |
| Orang tua hanya dapat melihat tagihan anaknya sendiri | `GET /students/{id}/fees` |
| Guru hanya melihat jadwal dirinya sendiri | `GET /schedules` |
| Generate rapor hanya oleh Wali Kelas | `POST /report-cards/generate` |
| Guru hanya melihat kelas yang diampunya | `GET /teacher/classes` |

### 2.4 Validation

| Aspek | Ketentuan | Dasar |
|---|---|---|
| Mekanisme | **Laravel Form Request** | Blueprint §3.4 |
| Cakupan | Validasi **semua input sebelum pemrosesan** | Blueprint §3.4 |
| Sanitasi XSS | `htmlspecialchars` | Blueprint §3.4 |
| SQL Injection | Eloquent parameterized query; raw SQL dilarang kecuali `DB::select()` dengan binding | Blueprint §3.4; CON-10 |
| Validasi upload | MIME type + ukuran berkas | Blueprint §3.4 |
| Format error validasi | `{ "success": false, "message": "...", "errors": {...} }` | Blueprint §4.1 |

Aturan validasi per endpoint dirinci pada [§5 Request Validation](#5-request-validation).

### 2.5 Error Handling

| Aspek | Status |
|---|---|
| Format response error | ✔ Ditetapkan blueprint §4.1 |
| Kode HTTP status per skenario | **Belum dijelaskan dalam blueprint.** |
| Kode error internal (error code) | **Belum dijelaskan dalam blueprint.** |
| Teks pesan error | **Belum dijelaskan dalam blueprint.** |
| Prinsip yang dinyatakan | Pesan error login **tidak boleh mengungkap detail sistem** (AUTH-01 AC-2; CON-34) |

Rincian pada [§7 Error Handling](#7-error-handling).

### 2.6 Response Format

| Aspek | Status |
|---|---|
| Struktur response sukses | ✔ Ditetapkan blueprint §4.1 |
| Struktur response error | ✔ Ditetapkan blueprint §4.1 |
| Struktur pagination | ✔ Ditetapkan blueprint §4.1 |
| Format timestamp | ✔ ISO 8601 dengan offset `+07:00` |
| Isi objek `data` per endpoint | **Belum dijelaskan dalam blueprint.** |

Rincian pada [§6 Response Format](#6-response-format).

### 2.7 Versioning

| Aspek | Ketentuan | Status |
|---|---|:---:|
| Strategi versioning | **URL path versioning** — `/api/v1` | ✔ Blueprint §4.1 |
| Versi aktif | `v1` | ✔ Blueprint |
| Kebijakan deprecation versi lama | **Belum dijelaskan dalam blueprint.** | ⚠ |
| Cara mengumumkan perubahan breaking | **Belum dijelaskan dalam blueprint.** | ⚠ |
| Dukungan multi-versi bersamaan | **Belum dijelaskan dalam blueprint.** | ⚠ |
| Header versi alternatif (mis. `Accept-Version`) | **Belum dijelaskan dalam blueprint.** | ⚠ |

> Blueprint menetapkan versi pada base URL, namun tidak mengatur tata kelola versinya. Perubahan signifikan pada API wajib diterbitkan sebagai revisi blueprint (CON-55).

### 2.8 Multi Tenant

| Aspek | Ketentuan | Dasar |
|---|---|---|
| Prinsip | *"Semua endpoint Auth otomatis di-scope ke `school_id` user yang login"* | Blueprint §4.1 |
| Mekanisme | Laravel Global Scope menambahkan `WHERE school_id = auth()->user()->school_id` | CON-15 |
| Paket | `spatie/laravel-multitenancy` 3.x | Blueprint §3.1 |
| Identifikasi tenant | Melalui `users.school_id` setelah login — **bukan** melalui subdomain, header, atau parameter | Blueprint §3.2.2 |
| Pengecualian | Super Admin (`school_id = NULL`) melewati Global Scope | CON-16 |
| Toleransi kebocoran | **Nol** — isolasi data 100% | NFR-06; CON-26 |
| Endpoint publik | PPDB tetap terikat `school_id` cabang melalui parameter `{schoolCode}` | CON-14 |

**Implikasi bagi desain API:**

| Implikasi | Penjelasan |
|---|---|
| Tidak ada parameter `school_id` pada request | Tenant ditentukan dari token, bukan dari input pengguna |
| Tidak ada header tenant | Blueprint tidak menyebut header khusus tenant |
| Endpoint Super Admin memakai prefix `/admin/schools` | Untuk mengakses data lintas cabang secara eksplisit |
| Data cabang lain tampak "tidak ditemukan" | Global Scope menyaringnya — perilaku yang dikehendaki (AUTH-02 AC-3) |

### 2.9 Rate Limiting

| Aspek | Ketentuan | Dasar |
|---|---|---|
| Login | Maksimal **5 percobaan per menit** | Blueprint §3.4; CON-28 |
| API umum | Maksimal **60 request per menit per user** | Blueprint §3.4; CON-28 |
| Mekanisme | Laravel Throttle Middleware | Blueprint §3.4 |
| Header sisa kuota | **Belum dijelaskan dalam blueprint.** |
| Response saat melebihi batas | **Belum dijelaskan dalam blueprint.** |

### 2.10 CORS

| Aspek | Ketentuan | Dasar |
|---|---|---|
| Origin yang diizinkan | **Hanya** `apps.smartsukses.sch.id` | Lampiran A.3 #9 |
| Verifikasi | Termasuk butir checklist go-live | Lampiran A.3 #9 |
| Header, method, dan credentials yang diizinkan | **Belum dijelaskan dalam blueprint.** |

### 2.11 Prinsip yang Tidak Dijelaskan Blueprint

| Aspek | Status |
|---|---|
| Idempotency key untuk operasi tulis | **Belum dijelaskan dalam blueprint.** |
| Caching response (ETag, Cache-Control) | **Belum dijelaskan dalam blueprint.** |
| Kompresi response | **Belum dijelaskan dalam blueprint.** |
| Webhook / callback | **Belum dijelaskan dalam blueprint.** |
| Batas ukuran request body | **Belum dijelaskan dalam blueprint** — kecuali batas berkas unggahan (2 MB foto, 5 MB bukti bayar) |
| Nilai `per_page` default dan maksimum | **Belum dijelaskan dalam blueprint** — kecuali `GET /notifications` yang dibatasi 50 terbaru |
| Sorting / ordering parameter | **Belum dijelaskan dalam blueprint.** |
| Pencarian teks bebas | **Belum dijelaskan dalam blueprint.** |
| Dokumentasi API interaktif | **Belum dijelaskan dalam blueprint.** |

---

## 3. Authentication API

Blueprint §4.2 mendefinisikan **8 endpoint autentikasi**.

### 3.1 Ringkasan Endpoint

| # | Method | Endpoint | Auth Level | Deskripsi |
|:---:|:---:|---|:---:|---|
| 1 | `POST` | `/auth/login` | Public | Login email + password |
| 2 | `POST` | `/auth/logout` | Auth | Invalidate token sesi aktif |
| 3 | `GET` | `/auth/me` | Auth | Ambil profil user yang sedang login |
| 4 | `POST` | `/auth/refresh` | Auth | Perbarui token yang hampir kedaluwarsa |
| 5 | `POST` | `/auth/forgot-password` | Public | Kirim link reset password ke email |
| 6 | `POST` | `/auth/reset-password` | Public | Reset password menggunakan token dari email |
| 7 | `PATCH` | `/auth/me` | Auth | Update profil sendiri |
| 8 | `PATCH` | `/auth/me/password` | Auth | Ganti password sendiri |

---

### 3.2 Login

| Aspek | Detail |
|---|---|
| **URL** | `/auth/login` |
| **HTTP Method** | `POST` |
| **Auth Level** | **Public** |
| **Description** | Login email + password → mengembalikan Bearer token + informasi user + konfigurasi sekolah (logo, warna) |
| **FR Terkait** | AUTH-01, AUTH-02, AUTH-03 |

**Request**

| Field | Sumber | Wajib | Keterangan |
|---|---|:---:|---|
| `email` | AUTH-01 AC-1 | ✔ | Email terdaftar — dipakai sebagai username |
| `password` | AUTH-01 AC-1 | ✔ | Password pengguna |

> Nama field persis dan field tambahan (mis. *remember me*): **Belum dijelaskan dalam blueprint.** Dua field di atas diturunkan dari AUTH-01 AC-1 (*"Form login minimal memiliki field email + password"*).

**Response — Sukses**

Blueprint menyatakan isi response secara ringkas: *"return Bearer token + user info + school config (logo, colors)"* (§4.2).

| Komponen | Keterangan | Sumber |
|---|---|---|
| Bearer token | Token sesi Laravel Sanctum | Blueprint §4.2 |
| User info | Informasi pengguna yang login | Blueprint §4.2 |
| School config | `logo_url`, `primary_color`, `secondary_color` | Blueprint §4.2; AUTH-03 AC-1 |

> Struktur field lengkap objek `data`: **Belum dijelaskan dalam blueprint.**

**Response — Gagal**

| Ketentuan | Sumber |
|---|---|
| Pesan error **tidak boleh mengungkap detail sistem** — tidak membedakan "email tidak terdaftar" dari "password salah" | AUTH-01 AC-2; CON-34 |
| Format: `{ "success": false, "message": "...", "errors": {...} }` | Blueprint §4.1 |
| Kode HTTP status | **Belum dijelaskan dalam blueprint.** |

**Validation**

| Aturan | Sumber |
|---|---|
| `email` dan `password` wajib diisi | AUTH-01 AC-1 |
| Rate limit: maksimal **5 percobaan per menit** | Blueprint §3.4; CON-28 |
| Password minimal 8 karakter (berlaku saat pembuatan/penggantian) | NFR-07; CON-27 |

**Permission**

| Aspek | Ketentuan |
|---|---|
| Auth Level | Public — tidak memerlukan token |
| Role | Seluruh 8 role menggunakan endpoint yang sama |
| Efek samping | `users.last_login_at` diperbarui · `school_id` di-bootstrap ke context sesi (Blueprint §3.2.2) |

**Notes**

- Token kedaluwarsa setelah **8 jam tidak aktif** (AUTH-01 AC-4).
- Bila login pertama kali, pengguna **wajib mengganti password** (NFR-07; CON-27). Mekanisme penandaan "login pertama": **Belum dijelaskan dalam blueprint.**
- Perilaku bila akun berstatus `is_active = 0`: **Belum dijelaskan dalam blueprint.**

---

### 3.3 Logout

| Aspek | Detail |
|---|---|
| **URL** | `/auth/logout` |
| **HTTP Method** | `POST` |
| **Auth Level** | **Auth** |
| **Description** | Invalidate token sesi aktif |
| **FR Terkait** | — (endpoint pendukung) |

**Request** — **Belum dijelaskan dalam blueprint.** Blueprint tidak menyebut body untuk endpoint ini.

**Response** — Format mengikuti standar sukses §4.1. Isi objek `data`: **Belum dijelaskan dalam blueprint.**

**Validation** — Token wajib valid (Auth Level: Auth).

**Permission** — Seluruh role yang sedang login.

**Notes**

- Halaman tujuan setelah logout: **Belum dijelaskan dalam blueprint.**
- Logout otomatis juga terjadi bila token kedaluwarsa atau setelah reset password berhasil (AUTH-04 AC-3).

---

### 3.4 Get Profile

| Aspek | Detail |
|---|---|
| **URL** | `/auth/me` |
| **HTTP Method** | `GET` |
| **Auth Level** | **Auth** |
| **Description** | Ambil profil user yang sedang login beserta **role** dan **data sekolahnya** |
| **FR Terkait** | AUTH-02, AUTH-03 |

**Request** — Tidak ada body. Token dikirim melalui header `Authorization`.

**Response**

Blueprint menyatakan isi response memuat: profil user + role + data sekolah (§4.2).

| Komponen | Kolom sumber (dari `05-DATABASE.md`) |
|---|---|
| Profil user | `users.name`, `email`, `phone`, `avatar_url`, `locale`, `is_active`, `last_login_at` |
| Role | Melalui `model_has_roles` → `roles` |
| Data sekolah | `schools.name`, `code`, `logo_url`, `primary_color`, `secondary_color` |

> Struktur field lengkap: **Belum dijelaskan dalam blueprint.** Daftar kolom di atas adalah **turunan** dari definisi tabel, bukan ketentuan blueprint.

**Validation** — Token wajib valid.

**Permission** — Seluruh role. Data yang dikembalikan hanya milik pengguna yang bersangkutan.

---

### 3.5 Refresh Token

| Aspek | Detail |
|---|---|
| **URL** | `/auth/refresh` |
| **HTTP Method** | `POST` |
| **Auth Level** | **Auth** |
| **Description** | Perbarui token yang hampir kedaluwarsa |
| **FR Terkait** | AUTH-01 |

**Request** — **Belum dijelaskan dalam blueprint.**

**Response** — Token baru. Struktur lengkap: **Belum dijelaskan dalam blueprint.**

**Validation** — Token lama wajib masih valid.

**Permission** — Seluruh role yang sedang login.

**Notes**

- Ambang waktu "hampir kedaluwarsa": **Belum dijelaskan dalam blueprint.**
- Apakah token lama langsung di-invalidate setelah refresh: **Belum dijelaskan dalam blueprint.**

---

### 3.6 Forgot Password

| Aspek | Detail |
|---|---|
| **URL** | `/auth/forgot-password` |
| **HTTP Method** | `POST` |
| **Auth Level** | **Public** |
| **Description** | Kirim link reset password ke email terdaftar |
| **FR Terkait** | AUTH-04 |

**Request**

| Field | Sumber | Wajib | Keterangan |
|---|---|:---:|---|
| `email` | AUTH-04 AC-1 | ✔ | Email terdaftar penerima link reset |

> Nama field persis: **Belum dijelaskan dalam blueprint.** Diturunkan dari AUTH-04 AC-1 (*"Link reset dikirim ke email terdaftar"*).

**Response** — Format standar sukses §4.1. Isi pesan: **Belum dijelaskan dalam blueprint.**

**Validation**

| Aturan | Sumber |
|---|---|
| Email wajib diisi | AUTH-04 AC-1 |
| Link reset berlaku **60 menit** | AUTH-04 AC-2; CON-30 |

**Permission** — Public.

**Notes**

- Apakah response membedakan email terdaftar dan tidak terdaftar: **Belum dijelaskan dalam blueprint.** Prinsip AUTH-01 AC-2 (tidak mengungkap detail sistem) berlaku untuk login, tidak dinyatakan untuk endpoint ini.
- Pengiriman email memakai SMTP Gmail/Mailtrap dengan limit 500–2.000 email/hari (Lampiran A.2).

---

### 3.7 Reset Password

| Aspek | Detail |
|---|---|
| **URL** | `/auth/reset-password` |
| **HTTP Method** | `POST` |
| **Auth Level** | **Public** |
| **Description** | Reset password menggunakan token dari email |
| **FR Terkait** | AUTH-04 |

**Request**

| Field | Sumber | Wajib | Keterangan |
|---|---|:---:|---|
| Token reset | AUTH-04 AC-2 | ✔ | Token dari link email |
| Password baru | AUTH-04; NFR-07 | ✔ | Minimal 8 karakter |

> Nama field persis dan keberadaan field konfirmasi password: **Belum dijelaskan dalam blueprint.**

**Response** — Format standar sukses §4.1.

**Validation**

| Aturan | Sumber |
|---|---|
| Token reset masih berlaku (maksimal **60 menit**) | AUTH-04 AC-2; CON-30 |
| Password minimal **8 karakter** | NFR-07; CON-27 |
| Password di-hash **Argon2id** | Blueprint §3.4 |

**Permission** — Public — dilindungi oleh validitas token reset.

**Notes**

- Setelah reset berhasil, **seluruh sesi aktif di-invalidate** (AUTH-04 AC-3; CON-30).
- Aturan kompleksitas password selain panjang minimum: **Belum dijelaskan dalam blueprint.**

---

### 3.8 Update Profile

| Aspek | Detail |
|---|---|
| **URL** | `/auth/me` |
| **HTTP Method** | `PATCH` |
| **Auth Level** | **Auth** |
| **Description** | Update profil sendiri: **nama, telepon, avatar, locale (bahasa)** |
| **FR Terkait** | AUTH-05 |

**Request**

Blueprint menyebut empat field yang dapat diperbarui (§4.2):

| Field | Kolom | Tipe | Keterangan |
|---|---|---|---|
| Nama | `users.name` | `VARCHAR(150)` | Nama lengkap pengguna |
| Telepon | `users.phone` | `VARCHAR(20)` | Nomor HP — dipakai untuk wa.me link |
| Avatar | `users.avatar_url` | `VARCHAR(500)` | Path foto profil |
| Locale | `users.locale` | `VARCHAR(5)` | `id` atau `en` |

> Nama field persis pada request: **Belum dijelaskan dalam blueprint.**

**Response** — Format standar sukses §4.1.

**Validation**

| Aturan | Sumber |
|---|---|
| `locale` hanya bernilai `id` atau `en` | Blueprint §2.2; AUTH-05 AC-2 |
| Perubahan bahasa tersimpan di field `locale` | AUTH-05 AC-2 |

> Aturan validasi untuk nama, telepon, dan avatar (format, ukuran berkas): **Belum dijelaskan dalam blueprint.**

**Permission** — Seluruh role, terbatas pada profil sendiri.

**Notes**

- Endpoint ini **tidak** dapat mengubah email maupun password.
- Batas ukuran dan format berkas avatar: **Belum dijelaskan dalam blueprint.** (Bandingkan: foto siswa dibatasi 2 MB oleh SIS-03.)

---

### 3.9 Change Password

| Aspek | Detail |
|---|---|
| **URL** | `/auth/me/password` |
| **HTTP Method** | `PATCH` |
| **Auth Level** | **Auth** |
| **Description** | Ganti password sendiri — **wajib mengirim `current_password`** |
| **FR Terkait** | AUTH-01; NFR-07 |

**Request**

| Field | Sumber | Wajib | Keterangan |
|---|---|:---:|---|
| `current_password` | Blueprint §4.2 | ✔ | **Disebut eksplisit oleh blueprint** |
| Password baru | NFR-07 | ✔ | Minimal 8 karakter |

> Nama field password baru dan keberadaan field konfirmasi: **Belum dijelaskan dalam blueprint.**

**Response** — Format standar sukses §4.1.

**Validation**

| Aturan | Sumber |
|---|---|
| `current_password` **wajib dikirim** dan harus cocok | Blueprint §4.2 |
| Password baru minimal **8 karakter** | NFR-07; CON-27 |
| Hash Argon2id | Blueprint §3.4 |

**Permission** — Seluruh role, terbatas pada akun sendiri.

**Notes**

- Endpoint ini juga menjadi jalur pemenuhan aturan **"wajib ganti password saat login pertama"** (NFR-07; CON-27).
- Apakah penggantian password melalui endpoint ini juga meng-invalidate sesi lain: **Belum dijelaskan dalam blueprint.** (Bandingkan: AUTH-04 AC-3 menyatakannya untuk alur reset password.)

---

### 3.10 Catatan Alur Autentikasi

Alur lengkap login, forgot password, sesi, logout, role validation, dan permission validation terdapat pada `docs/03-USER_FLOW.md` §4, termasuk **enam lapis validasi request**:

| Lapis | Nama | Mekanisme |
|:---:|---|---|
| 1 | Authentication | Laravel Sanctum |
| 2 | Auth Level Endpoint | Route middleware |
| 3 | Tenant Scope | Eloquent Global Scope |
| 4 | Matriks Izin Modul | `spatie/laravel-permission` |
| 5 | Policy per Model | Gate + Policy |
| 6 | Validasi Input | Laravel Form Request |

---

## 4. Module APIs

### 4.0 Pemetaan Modul terhadap Endpoint Blueprint

Beberapa modul pada daftar umum **tidak memiliki kelompok endpoint tersendiri** dalam blueprint. Tabel berikut memetakannya:

| Modul | Kelompok Endpoint Blueprint | Jumlah | Status |
|---|---|:---:|---|
| **School** | §4.3 Super Admin — Manajemen Tenant | 7 | ✔ Lengkap |
| **Users** | §4.4 User & Akses | 7 | ✔ Lengkap |
| **Roles** | — | **0** | ⚠ **Tidak ada endpoint khusus.** Role di-*assign* melalui `POST /users`. Endpoint CRUD role: **Belum dijelaskan dalam blueprint.** |
| **Students** | §4.5 Siswa (SIS) | 10 | ✔ Lengkap |
| **Teachers** | — | **0** | ⚠ **Tidak ada endpoint manajemen guru.** Guru dikelola sebagai `users` dengan role `GURU`/`WALI_KELAS`. Yang tersedia hanya endpoint **portal guru** (`/teacher/dashboard`, `/teacher/classes`) pada §4.11 |
| **Parents** | — | **0** | ⚠ **Tidak ada endpoint manajemen orang tua.** Ortu dikelola sebagai `users` dengan role `ORANG_TUA`. Yang tersedia hanya endpoint **parent portal** (`/parent/children/*`) pada §4.11 |
| **Classes** | §4.6 (sebagian) | 5 | ✔ Lengkap |
| **Subjects** | §4.6 (sebagian) | 2 | ✔ Lengkap |
| **Academic Year** | §4.6 (sebagian) | 3 | ✔ Lengkap |
| **PPDB** | §4.7 PPDB Online | 9 | ✔ Lengkap |
| **Academic** (Penilaian & E-Rapor) | §4.8 | 12 | ✔ Lengkap |
| **Finance** | §4.9.1 + §4.9.2 | 18 | ✔ Lengkap |
| **Announcement** | — | — | ⚠ **Bukan modul terpisah.** Pengumuman dan notifikasi berbagi satu kelompok endpoint dan satu tabel (`notifications`), dibedakan kolom `type` |
| **Notification** | §4.10 Notifikasi | 8 | ✔ Lengkap |
| **Portal** (Parent/Teacher/Student) | §4.11 | 10 | ✔ Lengkap |
| **Schedules** | §4.6 (sebagian) | 2 | ✔ Lengkap |
| | **TOTAL** | **93** | + 8 endpoint autentikasi = **101** |

> **Catatan tingkat kedalaman:** untuk seluruh endpoint di bawah, blueprint mencantumkan **method, URL, auth level, dan deskripsi**. Skema request/response tidak disediakan kecuali pada empat endpoint yang secara eksplisit menyebut isi body. Kolom **Request/Response** karena itu ditandai **"Belum dijelaskan dalam blueprint"** kecuali dinyatakan lain.

---

### 4.1 Modul School (Manajemen Tenant)

**Auth Level seluruh endpoint: Super** — hanya `SUPER_ADMIN`.
**Sumber:** Blueprint §4.3 · **FR:** Manajemen Tenant/Cabang (PRD §8.2) · **Tabel:** `schools`

#### Ringkasan Endpoint

| # | Method | Endpoint | Auth | Deskripsi |
|:---:|:---:|---|:---:|---|
| 1 | `GET` | `/admin/schools` | Super | Daftar semua cabang + statistik ringkas (jumlah siswa, guru) |
| 2 | `POST` | `/admin/schools` | Super | Daftarkan cabang baru (buat tenant baru) |
| 3 | `GET` | `/admin/schools/{id}` | Super | Detail lengkap satu cabang termasuk konfigurasi white-label |
| 4 | `PUT` | `/admin/schools/{id}` | Super | Update data dan konfigurasi white-label cabang |
| 5 | `PATCH` | `/admin/schools/{id}/toggle` | Super | Aktifkan / nonaktifkan cabang |
| 6 | `GET` | `/admin/schools/{id}/stats` | Super | Statistik: jumlah siswa, guru, tagihan terkumpul bulan ini, tunggakan |
| 7 | `GET` | `/admin/dashboard` | Super | Dashboard ringkasan semua cabang: total siswa, total SPP terkumpul, PPDB aktif |

#### Request

**Belum dijelaskan dalam blueprint.** Field yang dapat diturunkan dari kolom tabel `schools` (`05-DATABASE.md` §5.1): `name`, `code`, `slug`, `logo_url`, `primary_color`, `secondary_color`, `address`, `phone`, `email`, `head_name`, `wa_template_ppdb`, `wa_template_spp`, `wa_template_rapor`, `is_active`.

#### Response

Blueprint menyebut isi response secara ringkas untuk tiga endpoint:

| Endpoint | Isi yang disebut blueprint |
|---|---|
| `GET /admin/schools` | Daftar cabang + statistik ringkas: jumlah siswa, jumlah guru |
| `GET /admin/schools/{id}/stats` | Jumlah siswa, jumlah guru, tagihan terkumpul bulan ini, tunggakan |
| `GET /admin/dashboard` | Total siswa, total SPP terkumpul, PPDB aktif |

Struktur field lengkap: **Belum dijelaskan dalam blueprint.**

#### Validation

| Aturan | Sumber |
|---|---|
| `code` wajib unik lintas platform | `05-DATABASE.md` §5.1 — Key `UQ` |
| `slug` wajib unik lintas platform | `05-DATABASE.md` §5.1 — Key `UQ` |
| `primary_color` dan `secondary_color` wajib diisi | `05-DATABASE.md` §5.1 — NOT NULL |

#### Permission

| Role | Akses |
|---|:---:|
| `SUPER_ADMIN` | ✅ Penuh |
| Seluruh role lain | ❌ Tidak ada akses |

Modul Manajemen Tenant adalah satu-satunya baris pada matriks izin dengan ✅ hanya untuk Super Admin (PRD §8.2).

#### Notes

- Perubahan white-label **berlaku langsung tanpa deployment ulang** (AUTH-03 AC-3; CON-19).
- Penambahan cabang **tidak memerlukan perubahan kode** (ASM-02; NFR-03).
- Cabang dinonaktifkan melalui `toggle`, **bukan dihapus**.
- Endpoint ini melewati Global Scope karena Super Admin memiliki `school_id = NULL` (CON-16).

---

### 4.2 Modul Users

**Sumber:** Blueprint §4.4 · **FR:** PORTAL-04 · **Tabel:** `users`, `model_has_roles`

#### Ringkasan Endpoint

| # | Method | Endpoint | Auth | Deskripsi |
|:---:|:---:|---|:---:|---|
| 1 | `GET` | `/users` | Admin | Daftar user di sekolah aktif. Filter: `role`, `is_active` |
| 2 | `POST` | `/users` | Admin | Buat user baru + assign role |
| 3 | `GET` | `/users/{id}` | Admin | Detail user beserta role dan histori login |
| 4 | `PUT` | `/users/{id}` | Admin | Update data user (**bukan password**) |
| 5 | `DELETE` | `/users/{id}` | Admin | Nonaktifkan user (**soft deactivate**, bukan hard delete) |
| 6 | `POST` | `/users/import` | Admin | Import user massal dari Excel (.xlsx) |
| 7 | `POST` | `/users/{id}/reset-password` | Admin | Reset password → set temporary password → kirim notifikasi |

#### Request

**`POST /users`** — blueprint menyebut isi body secara eksplisit:

| Field | Sumber | Keterangan |
|---|---|---|
| `name` | Blueprint §4.4 | Nama lengkap |
| `email` | Blueprint §4.4 | Email unik lintas platform |
| `phone` | Blueprint §4.4 | Nomor HP |
| `role` | Blueprint §4.4 | Salah satu dari 8 role |

**`POST /users/import`** — berkas Excel (.xlsx). Struktur kolom template: **Belum dijelaskan dalam blueprint.**

Endpoint lain: **Belum dijelaskan dalam blueprint.**

#### Response

| Endpoint | Isi yang disebut blueprint |
|---|---|
| `GET /users/{id}` | Detail user + role + **histori login** |
| `POST /users/import` | **Sukses + daftar error per baris** |

Struktur field lengkap: **Belum dijelaskan dalam blueprint.**

#### Validation

| Aturan | Sumber |
|---|---|
| `email` wajib unik lintas seluruh platform | `05-DATABASE.md` §5.2 — Key `UQ`; ASM-12 |
| Password minimal 8 karakter | NFR-07; CON-27 |
| `locale` hanya `id` atau `en` | `05-DATABASE.md` §5.2 |
| Import Excel mengembalikan daftar error per baris | Blueprint §4.4 |

#### Permission

| Role | Akses | Dasar |
|---|:---:|---|
| `SUPER_ADMIN` | ✅ Penuh | PRD §8.2 — User Management |
| `SCHOOL_ADMIN` | ✅ Penuh (terbatas cabangnya) | PRD §8.2 |
| Seluruh role lain | ❌ Tidak ada akses | PRD §8.2 |

#### Notes

- `PUT /users/{id}` **tidak dapat mengubah password** — blueprint menyatakannya eksplisit.
- `DELETE /users/{id}` adalah **soft deactivate** melalui kolom `is_active`, bukan hard delete (CON-45).
- Reset password menghasilkan **password sementara** yang dikirim via notifikasi (PORTAL-04 AC-2).
- Pembuatan akun guru dan siswa dapat dilakukan **massal via import Excel** (PORTAL-04 AC-1).
- Nama library Excel yang dipakai: **Belum dijelaskan dalam blueprint.**

---

### 4.3 Modul Roles

| Aspek | Status |
|---|---|
| Endpoint khusus manajemen role | **Belum dijelaskan dalam blueprint.** Blueprint tidak menyediakan endpoint CRUD untuk `roles` maupun `permissions` |
| Cara role di-*assign* | Melalui field `role` pada `POST /users` (Blueprint §4.4) |
| Cara role dibaca | Melalui `GET /auth/me` dan `GET /users/{id}` yang menyertakan role |
| Cara role difilter | Melalui parameter `role` pada `GET /users` |

**Yang dijelaskan blueprint tentang role:**

| Aspek | Ketentuan | Dasar |
|---|---|---|
| Jumlah role | **8 role** | Blueprint §1.1.1 |
| Daftar role | `SUPER_ADMIN`, `SCHOOL_ADMIN`, `KEPALA_SEKOLAH`, `GURU`, `WALI_KELAS`, `SISWA`, `ORANG_TUA`, `BENDAHARA` | Blueprint §1.1.1 |
| Jumlah peran per pengguna | **Tepat satu peran utama** | Blueprint §1.1; ASM-08 |
| Paket | `spatie/laravel-permission` | Blueprint §3.1 |
| Matriks izin | 15 modul × 8 role | Blueprint §1.1.2; PRD §8.2 |

**Notes**

- Tabel `permissions` dan `role_has_permissions` bawaan paket **tidak didaftarkan** dalam 21 entitas ERD. → **Belum dijelaskan dalam blueprint.** (`04-ERD.md` §3.1)
- Apakah role dapat dibuat atau diubah melalui antarmuka: **Belum dijelaskan dalam blueprint.** Delapan role bersifat tetap menurut §1.1.1.
- Pemisahan permission antara `GURU` dan `WALI_KELAS` secara rinci: **Belum dijelaskan dalam blueprint** — matriks izin menggabungkan keduanya.

---

### 4.4 Modul Students (SIS)

**Sumber:** Blueprint §4.5 · **FR:** SIS-01…SIS-05 · **Tabel:** `students`

#### Ringkasan Endpoint

| # | Method | Endpoint | Auth | Deskripsi |
|:---:|:---:|---|:---:|---|
| 1 | `GET` | `/students` | Auth | Daftar siswa. Filter: `status`, `class_id`, `academic_year_id`. **Guru: hanya siswa kelas ajarnya** |
| 2 | `POST` | `/students` | Admin | Tambah siswa baru. Validasi NIS unik per sekolah |
| 3 | `GET` | `/students/{id}` | Auth | Detail siswa: data pribadi, kelas aktif, info ortu |
| 4 | `PUT` | `/students/{id}` | Admin | Update data siswa |
| 5 | `PATCH` | `/students/{id}/status` | Admin | Ubah status: `ACTIVE`, `GRADUATED`, `DROPPED_OUT`, `TRANSFERRED` |
| 6 | `POST` | `/students/{id}/photo` | Admin | Upload foto siswa (`multipart/form-data`). Auto-resize 400×400 |
| 7 | `GET` | `/students/{id}/grades` | Auth | Nilai siswa untuk tahun ajaran aktif, dikelompokkan per mata pelajaran |
| 8 | `GET` | `/students/{id}/fees` | Auth | Tagihan siswa. Filter: `status`, `period`. **Ortu hanya bisa lihat anaknya sendiri** |
| 9 | `GET` | `/students/export` | Admin | Export data siswa ke Excel. Filter: `class_id`, `status` |
| 10 | `POST` | `/students/import` | Admin | Import siswa massal dari Excel |

#### Request

**Belum dijelaskan dalam blueprint** secara struktural. Field yang **wajib** ada menurut SIS-01 AC-1:

> nama · NIS · NISN · tanggal lahir · jenis kelamin · agama · alamat · nama ortu · no. HP ortu

Kolom lengkap tabel `students` tersedia pada `05-DATABASE.md` §5.5.

**`POST /students/{id}/photo`** — `multipart/form-data` (dinyatakan blueprint §4.5).

#### Response

| Endpoint | Isi yang disebut blueprint |
|---|---|
| `GET /students/{id}` | Data pribadi + kelas aktif + info ortu |
| `GET /students/{id}/grades` | Nilai tahun ajaran aktif, **dikelompokkan per mata pelajaran** |
| `GET /students/export` | Berkas Excel bernama `siswa_[kode_sekolah]_[tanggal].xlsx` (SIS-05 AC-2) |

Struktur field lengkap: **Belum dijelaskan dalam blueprint.**

#### Validation

| Aturan | Sumber |
|---|---|
| **NISN wajib 10 digit angka** | SIS-01 AC-2; CON-38 |
| **NIS wajib unik dalam satu sekolah** | SIS-01 AC-3; CON-38 |
| Field wajib: nama, NIS, NISN, tanggal lahir, jenis kelamin, agama, alamat, nama ortu, no. HP ortu | SIS-01 AC-1 |
| Foto: format **JPG/PNG/WEBP**, maksimal **2 MB**, auto-resize **400×400 px** | SIS-03; CON-43 |
| Berkas disimpan di `storage/` — di luar web root | Blueprint §3.4; CON-31 |
| `status` hanya bernilai `ACTIVE`, `GRADUATED`, `DROPPED_OUT`, `TRANSFERRED` | `05-DATABASE.md` §5.5 |

#### Permission

| Role | Akses Modul | Pembatasan per Record |
|---|:---:|---|
| `SUPER_ADMIN` | ✅ | Seluruh cabang |
| `SCHOOL_ADMIN` | ✅ | Cabangnya sendiri |
| `KEPALA_SEKOLAH` | ⭕ | Baca saja |
| `GURU` / `WALI_KELAS` | ⭕ | **Hanya siswa kelas yang diampu** |
| `BENDAHARA` | ⭕ | Baca saja, untuk keperluan penagihan |
| `SISWA` | ⭕ | Data dirinya sendiri |
| `ORANG_TUA` | ⭕ | **Hanya anaknya sendiri** |

#### Notes

- **⚠** SIS-02 AC-2 menyebut status `INACTIVE` yang **tidak ada** dalam ENUM `students.status`. (PRD §17.3 #5)
- **⚠** SIS-03 mengizinkan **WEBP**, sedangkan Blueprint §3.4 menyatakan hanya JPG/PNG/PDF. (PRD §17.3 #12)
- **⚠** SIS-04 AC-2 menyebut kolom "status kehadiran hari ini" pada daftar siswa — sumber datanya **Belum dijelaskan dalam blueprint.** (PRD §17.3 #1)
- Edit tidak menghapus histori (*soft update*) — SIS-02 AC-1.
- Siswa nonaktif tidak muncul di daftar kelas aktif — SIS-02 AC-3.

---

### 4.5 Modul Teachers

| Aspek | Status |
|---|---|
| Endpoint manajemen guru | **Belum dijelaskan dalam blueprint.** Tidak ada kelompok endpoint `/teachers` |
| Cara guru dikelola | Sebagai `users` dengan role `GURU` atau `WALI_KELAS` melalui `/users` (§4.2 dokumen ini) |
| Tabel | Tidak ada tabel `teachers` — guru adalah baris pada `users` (`04-ERD.md` §3.1) |

**Endpoint terkait guru yang tersedia:**

| Method | Endpoint | Auth | Deskripsi | Kelompok |
|:---:|---|:---:|---|---|
| `GET` | `/teacher/dashboard` | Auth | Dashboard guru: jadwal hari ini, kelas aktif, notifikasi masuk | §4.11 Portal |
| `GET` | `/teacher/classes` | Auth | Kelas yang diampu guru yang login (tahun ajaran aktif) | §4.11 Portal |
| `GET` | `/users?role=GURU` | Admin | Daftar guru melalui filter role pada modul Users | §4.4 |
| `POST` | `/users` | Admin | Buat akun guru dengan `role = GURU` / `WALI_KELAS` | §4.4 |

**Notes**

- Data kepegawaian guru (NIP, mata pelajaran keahlian, status kepegawaian): **Belum dijelaskan dalam blueprint.**
- Guru dirujuk melalui `classes.homeroom_teacher_id` dan `class_subjects.teacher_id` — keduanya menunjuk `users.id`.
- Pembatasan bahwa kolom tersebut harus diisi user ber-role guru **tidak dijamin skema** (`05-DATABASE.md` §5.10).
- Endpoint portal guru dirinci pada [§4.13](#413-modul-portal-parent-teacher-student).

---

### 4.6 Modul Parents

| Aspek | Status |
|---|---|
| Endpoint manajemen orang tua | **Belum dijelaskan dalam blueprint.** Tidak ada kelompok endpoint `/parents` |
| Cara ortu dikelola | Sebagai `users` dengan role `ORANG_TUA` melalui `/users` (§4.2 dokumen ini) |
| Tabel | Tidak ada tabel `parents` — ortu adalah baris pada `users`, dirujuk `students.parent_user_id` |
| Data ortu tanpa akun | Tersimpan pada `students.parent_name`, `parent_phone`, `parent_email` |

**Endpoint terkait orang tua yang tersedia:**

| Method | Endpoint | Auth | Deskripsi | Kelompok |
|:---:|---|:---:|---|---|
| `GET` | `/parent/children` | Auth | Daftar anak dari user yang login (role `ORANG_TUA`) | §4.11 Portal |
| `GET` | `/parent/children/{studentId}/summary` | Auth | Dashboard anak | §4.11 Portal |
| `GET` | `/parent/children/{studentId}/grades` | Auth | Nilai lengkap anak | §4.11 Portal |
| `GET` | `/parent/children/{studentId}/fees` | Auth | Tagihan anak + status + riwayat bayar | §4.11 Portal |
| `GET` | `/parent/children/{studentId}/schedule` | Auth | Jadwal pelajaran kelas anak | §4.11 Portal |
| `GET` | `/users?role=ORANG_TUA` | Admin | Daftar akun ortu melalui filter role | §4.4 |

**Notes**

- **⚠** Skenario **dua wali untuk satu siswa**: **Belum dijelaskan dalam blueprint.** Kolom `students.parent_user_id` hanya menampung satu akun (`04-ERD.md` §5.2).
- **⚠** Ketika ortu memiliki akun, data kontak tersimpan di dua tempat (`students.parent_*` dan `users`). Sumber kebenaran mana yang berlaku: **Belum dijelaskan dalam blueprint.**
- Endpoint parent portal dirinci pada [§4.13](#413-modul-portal-parent-teacher-student).

---

### 4.7 Modul Academic Year, Classes, Subjects & Schedules

**Sumber:** Blueprint §4.6 · **FR:** KELAS-01…KELAS-04 · **Tabel:** `academic_years`, `classes`, `student_classes`, `subjects`, `class_subjects`, `schedules`

#### Ringkasan Endpoint — Academic Year (3)

| # | Method | Endpoint | Auth | Deskripsi |
|:---:|:---:|---|:---:|---|
| 1 | `GET` | `/academic-years` | Auth | Daftar tahun ajaran sekolah aktif |
| 2 | `POST` | `/academic-years` | Admin | Buat tahun ajaran baru |
| 3 | `PATCH` | `/academic-years/{id}/activate` | Admin | Set tahun ajaran sebagai aktif (**menonaktifkan yang lain**) |

#### Ringkasan Endpoint — Classes (5)

| # | Method | Endpoint | Auth | Deskripsi |
|:---:|:---:|---|:---:|---|
| 4 | `GET` | `/classes` | Auth | Daftar kelas untuk tahun ajaran aktif |
| 5 | `POST` | `/classes` | Admin | Buat kelas baru + assign wali kelas |
| 6 | `GET` | `/classes/{id}` | Auth | Detail kelas + daftar siswa + daftar mata pelajaran |
| 7 | `POST` | `/classes/{id}/students` | Admin | Tambahkan siswa ke kelas (**bisa multiple**) |
| 8 | `DELETE` | `/classes/{id}/students/{studentId}` | Admin | Hapus siswa dari kelas (pindah kelas) |

#### Ringkasan Endpoint — Subjects (2)

| # | Method | Endpoint | Auth | Deskripsi |
|:---:|:---:|---|:---:|---|
| 9 | `GET` | `/subjects` | Auth | Daftar mata pelajaran aktif sekolah |
| 10 | `POST` | `/subjects` | Admin | Tambah mata pelajaran baru |

#### Ringkasan Endpoint — Schedules (2)

| # | Method | Endpoint | Auth | Deskripsi |
|:---:|:---:|---|:---:|---|
| 11 | `GET` | `/schedules` | Auth | Jadwal. Filter: `class_id`, `teacher_id`, `day_of_week`. **Guru: jadwal diri sendiri** |
| 12 | `POST` | `/schedules` | Admin | Buat jadwal baru. **Validasi konflik otomatis** |

#### Request

**Belum dijelaskan dalam blueprint** secara struktural. Field yang disebut acceptance criteria:

| Endpoint | Field menurut AC |
|---|---|
| `POST /classes` | nama kelas · tingkat · wali kelas · kapasitas (KELAS-01 AC-1) |
| `POST /schedules` | kelas · mata pelajaran · guru · hari · jam mulai · jam selesai · ruang (KELAS-03 AC-1) |
| `POST /subjects` | **Belum dijelaskan dalam blueprint.** Kolom tabel: `name`, `code`, `credit_hours`, `description`, `is_active` |
| `POST /academic-years` | **Belum dijelaskan dalam blueprint.** Kolom tabel: `name`, `start_date`, `end_date`, `semester` |
| `POST /classes/{id}/students` | Daftar siswa — blueprint menyebut "bisa multiple". Struktur: **Belum dijelaskan dalam blueprint.** |

#### Response

| Endpoint | Isi yang disebut blueprint |
|---|---|
| `GET /classes/{id}` | Detail kelas + daftar siswa + daftar mata pelajaran |
| `GET /schedules` | Jadwal — untuk guru, hanya jadwal dirinya sendiri |

Struktur field lengkap: **Belum dijelaskan dalam blueprint.**

#### Validation

| Aturan | Sumber |
|---|---|
| **Hanya satu tahun ajaran aktif per sekolah** | `05-DATABASE.md` §5.6; CON-37 |
| Wali kelas hanya dipilih dari **guru aktif** | KELAS-01 AC-2 |
| **Satu guru = satu kelas per tahun ajaran** | KELAS-01 AC-3; CON-36 |
| Siswa dipilih dari **siswa aktif yang belum terdaftar di kelas manapun** untuk TA tersebut | KELAS-02 AC-1 |
| **Satu siswa = satu kelas per tahun ajaran** | KELAS-02 AC-2; CON-35 |
| **Sistem mendeteksi konflik jadwal** — guru, ruangan, atau kelas yang sama pada waktu bersamaan | KELAS-03 AC-2; CON-48 |
| `grade_level` bernilai 10, 11, atau 12 | `05-DATABASE.md` §5.7 |
| `day_of_week` bernilai 1–7 (1 = Senin) | `05-DATABASE.md` §5.11 |
| `semester` bernilai 1 atau 2 | `05-DATABASE.md` §5.6 |

#### Permission

| Role | Kelas & Jadwal | Keterangan |
|---|:---:|---|
| `SUPER_ADMIN` | ✅ | Seluruh cabang |
| `SCHOOL_ADMIN` | ✅ | Cabangnya sendiri |
| `KEPALA_SEKOLAH` | ⭕ | Baca saja |
| `GURU` / `WALI_KELAS` | ⭕ | Baca saja; jadwal hanya miliknya sendiri |
| `BENDAHARA` | ❌ | Tidak ada akses |
| `SISWA` | ⭕ | Baca saja |
| `ORANG_TUA` | ❌ | Tidak ada akses langsung — melalui parent portal |

#### Notes

- `PATCH /academic-years/{id}/activate` **menonaktifkan tahun ajaran lain** secara otomatis (Blueprint §4.6).
- **⚠** Tidak ada endpoint untuk mengelola `class_subjects` (penetapan guru pengampu) secara eksplisit pada API Map, padahal entitas ini menjadi prasyarat jadwal dan penilaian. → **Belum dijelaskan dalam blueprint.**
- **⚠** Tidak ada endpoint `PUT`/`DELETE` untuk `classes`, `subjects`, `schedules`, maupun `academic-years`. → **Belum dijelaskan dalam blueprint.**
- **⚠** Presedensi `classes.room` vs `schedules.room`: **Belum dijelaskan dalam blueprint.**

---

### 4.8 Modul PPDB

**Sumber:** Blueprint §4.7 · **FR:** PPDB-01…PPDB-05 · **Tabel:** `ppdb_registrations`

#### Ringkasan Endpoint

| # | Method | Endpoint | Auth | Deskripsi |
|:---:|:---:|---|:---:|---|
| 1 | `GET` | `/ppdb/schools` | **Public** | Daftar cabang yang membuka PPDB (untuk landing page publik) |
| 2 | `GET` | `/ppdb/{schoolCode}/info` | **Public** | Info PPDB satu cabang: syarat, jadwal, kuota |
| 3 | `POST` | `/ppdb/{schoolCode}/register` | **Public** | Submit formulir pendaftaran → mengembalikan nomor pendaftaran |
| 4 | `GET` | `/ppdb/check-status` | **Public** | Cek status berdasarkan nomor daftar + tanggal lahir |
| 5 | `GET` | `/admin/ppdb` | Admin | Daftar semua pendaftar cabang. Filter: `status`, `academic_year_id` |
| 6 | `GET` | `/admin/ppdb/{id}` | Admin | Detail pendaftar |
| 7 | `PATCH` | `/admin/ppdb/{id}/status` | Admin | Update status pendaftaran + catatan alasan |
| 8 | `GET` | `/admin/ppdb/{id}/wa-link` | Admin | Generate wa.me link notifikasi siap kirim |
| 9 | `POST` | `/admin/ppdb/{id}/enroll` | Admin | Konversi pendaftar menjadi siswa aktif |

**Empat endpoint bersifat Public** — jumlah terbanyak di antara seluruh modul.

#### Request

**`POST /ppdb/{schoolCode}/register`** — field menurut PPDB-01 AC-2:

> nama lengkap · jenis kelamin · tanggal lahir · asal sekolah · nama ortu · no. HP · email

Ditambah unggahan dokumen yang tersimpan pada kolom JSON `documents`.

**`GET /ppdb/check-status`** — parameter menurut Blueprint §4.7: **nomor daftar + tanggal lahir**.

**`PATCH /admin/ppdb/{id}/status`** — status baru + **catatan alasan** (PPDB-03 AC-3).

Struktur field persis: **Belum dijelaskan dalam blueprint.**

#### Response

| Endpoint | Isi yang disebut blueprint |
|---|---|
| `POST /ppdb/{schoolCode}/register` | **Nomor pendaftaran** berformat `[KODE_CABANG]-[TAHUN]-[SEQ]` |
| `GET /ppdb/check-status` | Status terkini: `REGISTERED`, `DOCUMENT_REVIEW`, `PASSED`, `FAILED`, `ENROLLED` |
| `GET /ppdb/{schoolCode}/info` | Syarat, jadwal, kuota |
| `GET /admin/ppdb` | Tabel: no. daftar, nama, asal sekolah, status, tanggal daftar (PPDB-03 AC-1) |
| `GET /admin/ppdb/{id}/wa-link` | Link wa.me dengan template teks siap kirim |

Struktur field lengkap: **Belum dijelaskan dalam blueprint.**

#### Validation

| Aturan | Sumber |
|---|---|
| `reg_number` wajib **unik** | `05-DATABASE.md` §5.12 — Key `UQ` |
| Perubahan status **wajib disertai catatan alasan** | PPDB-03 AC-3 |
| Alur status: `REGISTERED` → `DOCUMENT_REVIEW` → `PASSED`/`FAILED` → `ENROLLED` | PPDB-02 AC-2 |
| Enroll hanya untuk pendaftar berstatus `PASSED` | PPDB-05 |
| Setelah enroll, data PPDB mengisi form siswa; admin dapat melengkapi sebelum konfirmasi | PPDB-05 AC-1, AC-2 |
| Validasi NISN dan NIS berlaku saat pembuatan record siswa hasil enroll | SIS-01 AC-2, AC-3 |

#### Permission

| Role | Akses | Keterangan |
|---|:---:|---|
| Publik (tanpa login) | — | 4 endpoint Public: daftar cabang, info, register, cek status |
| `SUPER_ADMIN` | ✅ | Seluruh cabang |
| `SCHOOL_ADMIN` | ✅ | Cabangnya sendiri |
| `KEPALA_SEKOLAH` | ⭕ | Baca saja |
| Seluruh role lain | ❌ | Tidak ada akses |

#### Notes

- Meskipun endpoint bersifat publik, data tetap terikat `school_id` cabang yang dipilih melalui parameter `{schoolCode}` (CON-14).
- Setiap perubahan status **memicu notifikasi otomatis** (NOTIF-03 AC-1).
- Pengiriman WhatsApp bersifat **manual** — sistem hanya menyiapkan link (PPDB-04 AC-3; CON-49).
- **⚠** Batas ukuran dan format dokumen unggahan PPDB: **Belum dijelaskan dalam blueprint.**
- **⚠** Perilaku pendaftaran ganda, pembatalan pendaftaran, dan kuota penuh: **Belum dijelaskan dalam blueprint.**
- **⚠** Generator wa.me dibutuhkan pada Sprint 3 untuk endpoint ini, sementara modul Notifikasi dijadwalkan Sprint 8 (Roadmap RSK-29).

---

### 4.9 Modul Academic (Penilaian & E-Rapor)

**Sumber:** Blueprint §4.8 · **FR:** NILAI-01…NILAI-05 · **Tabel:** `grades`, `grade_configs`, `report_cards`

#### Ringkasan Endpoint

| # | Method | Endpoint | Auth | Deskripsi |
|:---:|:---:|---|:---:|---|
| 1 | `GET` | `/grades` | Auth | Daftar nilai. Filter: `student_id`, `class_subject_id`, `academic_year_id`, `grade_type` |
| 2 | `POST` | `/grades` | Auth | Input satu nilai. **Guru hanya bisa input untuk kelas yang dia ampu** |
| 3 | `PUT` | `/grades/{id}` | Auth | Edit nilai. **Hanya bisa selama rapor belum published** |
| 4 | `POST` | `/grades/bulk` | Auth | Input nilai massal untuk satu `class_subject` |
| 5 | `POST` | `/grades/import` | Auth | Import nilai dari template Excel |
| 6 | `GET` | `/grade-configs` | Auth | Konfigurasi bobot komponen per mapel tahun ajaran aktif |
| 7 | `POST` | `/grade-configs` | Admin | Set konfigurasi bobot komponen penilaian |
| 8 | `GET` | `/report-cards` | Auth | Daftar rapor. Filter: `student_id`, `academic_year_id`, `is_published` |
| 9 | `GET` | `/report-cards/{id}` | Auth | Detail rapor + semua nilai final per mapel |
| 10 | `POST` | `/report-cards/generate` | Auth | Generate draft rapor untuk semua siswa di kelas. **Wali Kelas only** |
| 11 | `POST` | `/report-cards/{id}/publish` | Auth | Terbitkan rapor — **nilai terkunci**. Trigger notifikasi ke ortu |
| 12 | `GET` | `/report-cards/{id}/pdf` | Auth | Download rapor dalam format PDF |

#### Request

**`POST /grades/bulk`** — blueprint menyebut isi body secara eksplisit:

> *"array of {student_id, score}"* untuk satu `class_subject`

**`POST /grades`** — field yang dapat diturunkan dari kolom `grades` (`05-DATABASE.md` §5.13): `student_id`, `class_subject_id`, `academic_year_id`, `grade_type`, `score`, `weight`, `description`.

**`POST /grade-configs`** — kolom `components` bertipe JSON:
`[{"type":"DAILY","weight":0.40},{"type":"MIDTERM","weight":0.30},…]`

**`POST /grades/import`** — berkas Excel. Struktur kolom template: **Belum dijelaskan dalam blueprint.**

Struktur field persis untuk endpoint lain: **Belum dijelaskan dalam blueprint.**

#### Response

| Endpoint | Isi yang disebut blueprint |
|---|---|
| `GET /report-cards/{id}` | Detail rapor + **semua nilai final per mapel** |
| `GET /report-cards/{id}/pdf` | Berkas PDF rapor |
| `GET /grade-configs` | Konfigurasi bobot untuk tahun ajaran aktif |

Struktur field lengkap: **Belum dijelaskan dalam blueprint.**

#### Validation

| Aturan | Sumber |
|---|---|
| Nilai dalam **skala 0–100** | NILAI-01 AC-1; CON-39 |
| **Guru hanya bisa input nilai untuk kelas yang dia ampu** | Blueprint §4.8; PRD §8.3 |
| Nilai dapat diedit **hanya selama rapor belum published** | NILAI-01 AC-3; Blueprint §4.8; CON-40 |
| Perhitungan nilai akhir: `(Harian × bobot) + (UTS × bobot) + (UAS × bobot)` | NILAI-02 AC-2 |
| Hasil dibulatkan **2 desimal** | NILAI-02 AC-3; CON-39 |
| Sebelum publish, **semua mata pelajaran wajib memiliki nilai akhir** | NILAI-03 AC-1; CON-41 |
| Generate dan publish rapor **hanya oleh Wali Kelas** | NILAI-03; Blueprint §4.8 |
| Perubahan konfigurasi bobot **hanya berlaku untuk tahun ajaran baru** | NILAI-05 AC-2; CON-42 |
| `grade_type` hanya bernilai `DAILY`, `MIDTERM`, `FINAL`, `ASSIGNMENT`, `SKILL`, `ATTITUDE` | `05-DATABASE.md` §5.13 |

#### Permission

| Role | Input Nilai | Generate Rapor | Keterangan |
|---|:---:|:---:|---|
| `SUPER_ADMIN` | ✅ | ✅ | Seluruh cabang |
| `SCHOOL_ADMIN` | ✅ | ✅ | Cabangnya sendiri |
| `KEPALA_SEKOLAH` | ⭕ | ⭕ | Baca saja |
| `GURU` | ✅ | ❌ | Hanya kelas yang diampu; tidak dapat publish rapor |
| `WALI_KELAS` | ✅ | ✅ (Wali) | Publish rapor kelas perwaliannya |
| `BENDAHARA` | ❌ | ❌ | Tidak ada akses |
| `SISWA` | ❌ | ⭕ | Melihat nilai dan rapor dirinya sendiri |
| `ORANG_TUA` | ❌ | ⭕ | Melihat nilai dan rapor anaknya |

> `POST /grade-configs` ber-Auth Level **Admin**, sehingga konfigurasi bobot tidak dapat diatur guru.

#### Notes

- Publish rapor **memicu notifikasi otomatis ke orang tua** (Blueprint §4.8; NOTIF-03 AC-1).
- Setelah publish, `is_published = 1`, `published_at`, dan `published_by` terisi; **seluruh nilai terkunci** (CON-40).
- Nilai tampil ke siswa dan ortu **segera setelah guru menyimpan**, sebelum rapor terbit (NILAI-04 AC-1).
- Generate PDF dijalankan melalui Laravel Queue (Blueprint §3.1).
- **⚠** Presedensi `grades.weight` vs `grade_configs.components`: **Belum dijelaskan dalam blueprint.** (PRD §17.3 #7)
- **⚠** Rumus dan tie-break `rank_in_class`: **Belum dijelaskan dalam blueprint.** (PRD §17.3 #13)
- **⚠** Tidak ada endpoint **unpublish** atau koreksi rapor: **Belum dijelaskan dalam blueprint.** (PRD §17.3 #8)
- **⚠** Rekap kehadiran pada rapor (`attend_*`) memerlukan data absensi yang sumbernya **Belum dijelaskan dalam blueprint.** (PRD §17.3 #1)
- **⚠** Tidak ada endpoint `DELETE /grades/{id}`: **Belum dijelaskan dalam blueprint.**

---

### 4.10 Modul Finance — Tagihan & Pembayaran

**Sumber:** Blueprint §4.9.1 · **FR:** SPP-01…SPP-05 · **Tabel:** `fee_types`, `student_fees`, `payments`

#### Ringkasan Endpoint

| # | Method | Endpoint | Auth | Deskripsi |
|:---:|:---:|---|:---:|---|
| 1 | `GET` | `/fee-types` | Auth | Daftar jenis tagihan aktif sekolah |
| 2 | `POST` | `/fee-types` | Admin | Buat jenis tagihan baru |
| 3 | `PUT` | `/fee-types/{id}` | Admin | Update jenis tagihan |
| 4 | `GET` | `/student-fees` | Auth | Daftar tagihan. Filter: `student_id`, `status`, `period`, `fee_type_id` |
| 5 | `POST` | `/student-fees/generate-bulk` | Admin | Generate tagihan massal untuk semua siswa aktif |
| 6 | `GET` | `/student-fees/{id}` | Auth | Detail satu tagihan + riwayat pembayaran |
| 7 | `PATCH` | `/student-fees/{id}/waive` | Admin | Bebaskan tagihan dengan alasan (status → `WAIVED`) |
| 8 | `GET` | `/student-fees/export` | Admin | Export laporan tagihan ke Excel. Filter: `period`, `class_id`, `status` |
| 9 | `POST` | `/payments` | Admin | Catat pembayaran baru |
| 10 | `POST` | `/payments/{id}/proof` | Admin | Upload bukti pembayaran (`multipart`) |
| 11 | `GET` | `/payments` | Admin | Riwayat semua pembayaran. Filter: `student_id`, `period`, `method` |

#### Request

**`POST /student-fees/generate-bulk`** — blueprint menyebut isi body secara eksplisit:

> `fee_type_id`, `period`, `due_date`

**`POST /payments`** — blueprint menyebut isi body secara eksplisit:

> `student_fee_id`, `amount`, `method`, `date`, `reference`

**`POST /payments/{id}/proof`** — `multipart` (dinyatakan blueprint §4.9.1).

**`POST /fee-types`** — field menurut SPP-01 AC-1: nama tagihan · jumlah (Rupiah) · frekuensi (`MONTHLY`/`YEARLY`/`ONCE`).

**`PATCH /student-fees/{id}/waive`** — alasan pembebasan (`waive_reason`).

#### Response

| Endpoint | Isi yang disebut blueprint |
|---|---|
| `GET /student-fees/{id}` | Detail tagihan + **riwayat pembayaran** |
| `GET /student-fees/export` | Berkas Excel — kolom: nama siswa, kelas, periode, jumlah tagihan, jumlah bayar, sisa, status (SPP-05 AC-1) |

Struktur field lengkap: **Belum dijelaskan dalam blueprint.**

#### Validation

| Aturan | Sumber |
|---|---|
| Field jenis tagihan: nama, jumlah (Rupiah), frekuensi | SPP-01 AC-1 |
| Jenis tagihan dapat dinonaktifkan **tanpa menghapus histori** | SPP-01 AC-2; CON-45 |
| **Preview daftar tagihan wajib ditampilkan sebelum konfirmasi generate** | SPP-02 AC-3; CON-47 |
| Generate massal menyasar **seluruh siswa aktif** | SPP-02 AC-1 |
| `due_date` diisi otomatis (mis. tanggal 10 bulan berjalan) | SPP-02 AC-2 |
| Field pembayaran: nama siswa, periode, metode bayar, jumlah, tanggal, referensi | SPP-03 AC-1 |
| Bukti pembayaran: **JPG/PNG/PDF, maksimal 5 MB** | SPP-03 AC-2; CON-44 |
| Status berubah otomatis ke `PAID` / `PARTIAL` | SPP-03 AC-3 |
| Pembebasan tagihan **wajib disertai alasan** | Blueprint §4.9.1 |
| `period` berformat `YYYY-MM` | `05-DATABASE.md` §5.17 |
| `payment_method` hanya `CASH`, `TRANSFER`, `PAYMENT_GATEWAY` | `05-DATABASE.md` §5.18 |
| Berkas disimpan di `storage/` — di luar web root | Blueprint §3.4; CON-31 |

#### Permission

| Role | Tagihan SPP | Catat Pembayaran | Keterangan |
|---|:---:|:---:|---|
| `SUPER_ADMIN` | ✅ | ✅ | Seluruh cabang |
| `SCHOOL_ADMIN` | ✅ | ✅ | Termasuk pembebasan tagihan |
| `KEPALA_SEKOLAH` | ⭕ | ❌ | **Tidak dapat mencatat pembayaran** |
| `GURU` / `WALI_KELAS` | ❌ | ❌ | Tidak ada akses |
| `BENDAHARA` | ✅ | ✅ | Kewenangan utama modul ini |
| `SISWA` | ❌ | ❌ | **Tidak memiliki akses ke modul tagihan** |
| `ORANG_TUA` | ⭕ | ❌ | Hanya melihat tagihan anaknya sendiri |

> **Catatan kewenangan:** `PATCH /student-fees/{id}/waive` ber-Auth Level **Admin**, sehingga pembebasan tagihan berada pada Admin Sekolah / Super Admin — **bukan Bendahara**.

#### Notes

- Penerbitan tagihan **memicu notifikasi otomatis** (NOTIF-03 AC-1).
- Satu tagihan dapat menerima **beberapa pembayaran** (cicilan) — `amount_paid` terakumulasi.
- Pembayaran dicatat **manual** oleh Bendahara — tidak ada payment gateway pada Phase 1 (CON-50).
- Job generate massal dijalankan melalui Laravel Queue agar tidak timeout.
- **⚠** Perilaku generate untuk frekuensi `YEARLY` dan `ONCE`: **Belum dijelaskan dalam blueprint.** (PRD §17.3 #9)
- **⚠** Alur unggah bukti bayar **oleh orang tua**: **Belum dijelaskan dalam blueprint** — hanya Bendahara yang mengunggah.
- **⚠** Endpoint pembatalan atau koreksi pembayaran: **Belum dijelaskan dalam blueprint.**
- **⚠** Reminder otomatis tagihan jatuh tempo: **Belum dijelaskan dalam blueprint.**

---

### 4.11 Modul Finance — Akuntansi & Kas

**Sumber:** Blueprint §4.9.2 · **FR:** KAS-01…KAS-03 · **Tabel:** `transactions`

#### Ringkasan Endpoint

| # | Method | Endpoint | Auth | Deskripsi |
|:---:|:---:|---|:---:|---|
| 1 | `GET` | `/transactions` | Auth | Daftar transaksi kas. Filter: `type`, `category`, `date_from`, `date_to` |
| 2 | `POST` | `/transactions` | Admin | Catat transaksi baru (income atau expense) |
| 3 | `PUT` | `/transactions/{id}` | Admin | Edit transaksi |
| 4 | `DELETE` | `/transactions/{id}` | Admin | Hapus transaksi (**soft delete**) |
| 5 | `GET` | `/finance/summary` | Auth | Ringkasan keuangan: total income, expense, saldo per bulan. Filter: `year`, `month` |
| 6 | `GET` | `/finance/spp-report` | Auth | Laporan SPP: total tagihan, terkumpul, tunggakan per periode |
| 7 | `GET` | `/finance/export` | Admin | Export laporan keuangan ke Excel |

#### Request

**`POST /transactions`** — field menurut KAS-01 AC-1:

> jenis (`INCOME`/`EXPENSE`) · kategori · jumlah · tanggal · keterangan · nomor referensi

Ditambah lampiran bukti scan nota/kwitansi (KAS-01 AC-2) yang tersimpan pada `proof_url`.

Struktur field persis: **Belum dijelaskan dalam blueprint.**

#### Response

| Endpoint | Isi yang disebut blueprint |
|---|---|
| `GET /finance/summary` | Total income, total expense, saldo per bulan |
| `GET /finance/spp-report` | Total tagihan, terkumpul, tunggakan per periode |
| `GET /finance/export` | Berkas Excel laporan keuangan |

Isi dashboard menurut acceptance criteria:

| Dashboard | Isi | Sumber |
|---|---|---|
| Cabang (Kepsek/Admin) | Saldo kas · total penerimaan SPP bulan ini · total pengeluaran bulan ini · **grafik tren 6 bulan terakhir** | KAS-02 |
| Lintas cabang (Super Admin) | Per cabang: total tagihan · total terkumpul · **persentase lunas** · filter tahun ajaran/bulan | KAS-03 |

Struktur field lengkap: **Belum dijelaskan dalam blueprint.**

#### Validation

| Aturan | Sumber |
|---|---|
| Field wajib: jenis, kategori, jumlah, tanggal, keterangan, nomor referensi | KAS-01 AC-1 |
| `type` hanya bernilai `INCOME` atau `EXPENSE` | `05-DATABASE.md` §5.19 |
| Bukti dapat dilampirkan (scan nota/kwitansi) | KAS-01 AC-2 |

#### Permission

| Role | Akuntansi & Kas | Laporan Keuangan |
|---|:---:|:---:|
| `SUPER_ADMIN` | ✅ | ✅ |
| `SCHOOL_ADMIN` | ✅ | ✅ |
| `KEPALA_SEKOLAH` | ⭕ | ⭕ |
| `GURU` / `WALI_KELAS` | ❌ | ❌ |
| `BENDAHARA` | ✅ | ✅ |
| `SISWA` | ❌ | ❌ |
| `ORANG_TUA` | ❌ | ❌ |

#### Notes

- Dashboard lintas cabang (`GET /admin/dashboard`, `GET /admin/schools/{id}/stats`) berada pada modul School — Auth Level **Super**.
- **⚠** `DELETE /transactions/{id}` disebut *soft delete*, namun tabel `transactions` **tidak memiliki kolom `deleted_at` maupun kolom status**. Mekanismenya: **Belum dijelaskan dalam blueprint.** (`05-DATABASE.md` §5.19)
- **⚠** Cara merekonsiliasi penerimaan SPP (`payments`) dengan buku kas umum (`transactions`) pada laporan: **Belum dijelaskan dalam blueprint.**
- Daftar kategori transaksi bersifat bebas (`VARCHAR`), bukan ENUM.

---

### 4.12 Modul Notification & Announcement

**Sumber:** Blueprint §4.10 · **FR:** NOTIF-01…NOTIF-04 · **Tabel:** `notifications`, `notification_reads`

> **Catatan:** blueprint **tidak memisahkan** Announcement dan Notification. Keduanya berbagi satu kelompok endpoint dan satu tabel, dibedakan oleh kolom `type` (`ANNOUNCEMENT`, `BILLING`, `ACADEMIC`, `EMERGENCY`, `SYSTEM`).

#### Ringkasan Endpoint

| # | Method | Endpoint | Auth | Deskripsi |
|:---:|:---:|---|:---:|---|
| 1 | `GET` | `/notifications` | Auth | Daftar notifikasi untuk user yang login. Filter: `type`, `is_read`. **Limit: 50 terbaru** |
| 2 | `GET` | `/notifications/unread-count` | Auth | Jumlah notifikasi belum dibaca (untuk badge) |
| 3 | `POST` | `/notifications` | Admin | Buat dan kirim notifikasi baru (target `ALL`/`CLASS`/`INDIVIDUAL`) |
| 4 | `GET` | `/notifications/{id}` | Auth | Detail notifikasi |
| 5 | `PATCH` | `/notifications/{id}/read` | Auth | Tandai notifikasi sebagai dibaca |
| 6 | `POST` | `/notifications/mark-all-read` | Auth | Tandai semua notifikasi sebagai dibaca |
| 7 | `GET` | `/notifications/{id}/wa-links` | Admin | Generate daftar wa.me link untuk semua penerima |
| 8 | `GET` | `/admin/notifications` | Admin | Semua notifikasi yang pernah dibuat di cabang ini (**termasuk draft**) |

#### Request

**`POST /notifications`** — field menurut NOTIF-01 AC-1:

> judul · isi pesan · target (`ALL`/`CLASS`/`INDIVIDUAL`) · kategori (`ACADEMIC`/`BILLING`/`EMERGENCY`/`GENERAL`)

Ditambah `target_id` bila target bukan `ALL`, dan `is_draft` untuk menyimpan sebagai draf.

Struktur field persis: **Belum dijelaskan dalam blueprint.**

#### Response

| Endpoint | Isi yang disebut blueprint |
|---|---|
| `GET /notifications` | Maksimal **50 notifikasi terbaru** milik user yang login |
| `GET /notifications/unread-count` | Jumlah notifikasi belum dibaca — untuk badge bell icon |
| `GET /notifications/{id}/wa-links` | Daftar URL `wa.me/62[nomorHP]?text=[pesan_ter-encode]` per penerima |
| `GET /admin/notifications` | Seluruh notifikasi cabang, **termasuk draft** |

Struktur field lengkap: **Belum dijelaskan dalam blueprint.**

#### Validation

| Aturan | Sumber |
|---|---|
| Field wajib: judul, isi pesan, target, kategori | NOTIF-01 AC-1 |
| Target `ALL` mengirim ke **semua user aktif di cabang** | NOTIF-01 AC-2 |
| Target `CLASS` mengirim **hanya ke orang tua siswa kelas tersebut** | NOTIF-01 AC-3 |
| Format URL: `wa.me/62[nomorHP]?text=[pesan_ter-encode]` | NOTIF-02 AC-1 |
| `type` hanya bernilai `ANNOUNCEMENT`, `BILLING`, `ACADEMIC`, `EMERGENCY`, `SYSTEM` | `05-DATABASE.md` §5.20 |
| `target_type` hanya bernilai `ALL`, `CLASS`, `INDIVIDUAL` | `05-DATABASE.md` §5.20 |
| Riwayat notifikasi disimpan **90 hari** | NOTIF-04 AC-3; CON-46 |

#### Permission

| Role | Buat Notifikasi | Terima Notifikasi |
|---|:---:|:---:|
| `SUPER_ADMIN` | ✅ | ✔ |
| `SCHOOL_ADMIN` | ✅ | ✔ |
| `KEPALA_SEKOLAH` | ✅ | ✔ |
| `GURU` / `WALI_KELAS` | ❌ ⚠ | ✔ |
| `BENDAHARA` | ❌ | ✔ |
| `SISWA` | ❌ | ✔ |
| `ORANG_TUA` | ❌ | ✔ |

> **⚠ Konflik:** matriks izin menyatakan GURU/WALI **❌** pada "Notifikasi (buat)", sementara PORTAL-02 AC-2 menyediakan shortcut **"Buat Pengumuman"** pada dashboard guru. Blueprint memuat dua pernyataan berbeda. (PRD §17.3 #3)

#### Notes

- **Tiga trigger otomatis** yang didefinisikan blueprint: perubahan status PPDB · penerbitan tagihan · penerbitan rapor (NOTIF-03 AC-1).
- Template WA tersimpan pada `schools.wa_template_ppdb`, `wa_template_spp`, `wa_template_rapor` dan dapat diedit Admin Sekolah (NOTIF-03 AC-2).
- Pengiriman WhatsApp bersifat **manual** — Admin mengklik "Buka WA" satu per satu (CON-49).
- Notifikasi sistem otomatis memiliki `sender_id = NULL`.
- **⚠** Daftar kategori pada NOTIF-01 memuat `GENERAL` yang **tidak ada** dalam ENUM `notifications.type`. (`05-DATABASE.md` §4.5)
- **⚠** Aturan normalisasi nomor HP ke awalan `62`: **Belum dijelaskan dalam blueprint.** (PRD §17.3 #14)
- **⚠** Penanganan penerima tanpa WhatsApp: **Belum dijelaskan dalam blueprint.**
- **⚠** Tidak ada endpoint `PUT`/`DELETE` untuk notifikasi: **Belum dijelaskan dalam blueprint.**
- **⚠** Mekanisme penerapan retensi 90 hari: **Belum dijelaskan dalam blueprint.**

---

### 4.13 Modul Portal (Parent, Teacher, Student)

**Sumber:** Blueprint §4.11 · **FR:** PORTAL-01…PORTAL-03 · **Tabel:** agregasi seluruh tabel

#### Ringkasan Endpoint — Parent Portal (5)

| # | Method | Endpoint | Auth | Deskripsi |
|:---:|:---:|---|:---:|---|
| 1 | `GET` | `/parent/children` | Auth | Daftar anak dari user yang login (role `ORANG_TUA`) |
| 2 | `GET` | `/parent/children/{studentId}/summary` | Auth | Dashboard anak: **nilai terbaru 5 mapel**, hadir bulan ini, tagihan pending |
| 3 | `GET` | `/parent/children/{studentId}/grades` | Auth | Nilai lengkap anak per tahun ajaran aktif |
| 4 | `GET` | `/parent/children/{studentId}/fees` | Auth | Semua tagihan anak + status + riwayat bayar |
| 5 | `GET` | `/parent/children/{studentId}/schedule` | Auth | Jadwal pelajaran kelas anak hari ini & minggu ini |

#### Ringkasan Endpoint — Teacher Portal (2)

| # | Method | Endpoint | Auth | Deskripsi |
|:---:|:---:|---|:---:|---|
| 6 | `GET` | `/teacher/dashboard` | Auth | Dashboard guru: jadwal hari ini, kelas aktif, notifikasi masuk |
| 7 | `GET` | `/teacher/classes` | Auth | Kelas yang diampu guru yang login (tahun ajaran aktif) |

#### Ringkasan Endpoint — Student Portal (3)

| # | Method | Endpoint | Auth | Deskripsi |
|:---:|:---:|---|:---:|---|
| 8 | `GET` | `/student/dashboard` | Auth | Dashboard siswa: jadwal hari ini, **5 nilai terbaru**, notifikasi |
| 9 | `GET` | `/student/schedule` | Auth | Jadwal pelajaran siswa (tahun ajaran aktif) |
| 10 | `GET` | `/student/grades` | Auth | Nilai siswa yang login (tahun ajaran aktif) |

#### Request

Seluruh endpoint portal bersifat `GET` tanpa body. Parameter yang disebut blueprint hanya `{studentId}` pada parent portal.

Parameter query tambahan (mis. filter periode): **Belum dijelaskan dalam blueprint.**

#### Response

| Endpoint | Isi yang disebut blueprint / AC |
|---|---|
| `/parent/children/{id}/summary` | Nilai terbaru **5 mapel** (§4.11) · hadir bulan ini · tagihan pending |
| PORTAL-01 AC-1 | Nilai terbaru (**3**) · kehadiran bulan ini · tagihan belum lunas (jumlah & nominal) |
| `/teacher/dashboard` | Jadwal hari ini · kelas aktif · notifikasi masuk |
| `/student/dashboard` | Jadwal hari ini · **5 nilai terbaru** · notifikasi |

Struktur field lengkap: **Belum dijelaskan dalam blueprint.**

> **⚠ Konflik angka:** PORTAL-01 AC-1 menyebut **3 nilai terbaru**, sedangkan `GET /parent/children/{studentId}/summary` menyebut **5 mapel**. (PRD §17.3 #4)

#### Validation

| Aturan | Sumber |
|---|---|
| Orang tua hanya dapat mengakses data **anaknya sendiri** | Blueprint §4.5 (`GET /students/{id}/fees`); PRD §8.3 |
| Guru hanya melihat **kelas yang diampunya** | Blueprint §4.11 |
| Siswa hanya melihat **data dirinya sendiri** | PRD §8.3 |
| Data diambil untuk **tahun ajaran aktif** | Blueprint §4.11 |
| Nilai tampil real-time sebelum rapor terbit; rapor final setelah publish | NILAI-04 AC-1, AC-2 |

#### Permission

| Role | Parent Portal | Portal Siswa | Portal Guru |
|---|:---:|:---:|:---:|
| `SUPER_ADMIN` | ✅ | ✅ | — |
| `SCHOOL_ADMIN` | ✅ | ✅ | — |
| `KEPALA_SEKOLAH` | ⭕ | ⭕ | — |
| `GURU` / `WALI_KELAS` | ❌ | ⭕ | ✔ |
| `BENDAHARA` | ❌ | ❌ | — |
| `SISWA` | ❌ | ✅ | — |
| `ORANG_TUA` | ✅ | ❌ | — |

> Matriks izin PRD §8.2 tidak memuat baris khusus "Portal Guru"; kolom di atas diturunkan dari ketersediaan endpoint `/teacher/*` bagi role guru.

#### Notes

- Parent Portal **wajib responsive** pada tampilan mobile (PORTAL-01 AC-3; NFR-10).
- Orang tua dengan lebih dari satu anak dapat berpindah antar profil anak (PORTAL-01 AC-2).
- Portal siswa memiliki empat menu: Jadwal · Nilai · Notifikasi · Profil (PORTAL-03 AC-1).
- Dashboard guru menyediakan shortcut: Input Nilai · Daftar Siswa Kelas · Buat Pengumuman (PORTAL-02 AC-2).
- Halaman portal wajib termuat **< 3 detik** pada koneksi 4G (NFR-01) dan tetap berfungsi pada **200 user konkuren** (NFR-04).
- **⚠** "Kehadiran bulan ini" memerlukan data absensi yang **Belum dijelaskan dalam blueprint.** (PRD §17.3 #1)
- **⚠** Tidak ada endpoint untuk siswa melihat tagihannya sendiri — matriks izin menandai Tagihan SPP **❌** untuk SISWA (PRD §8.2).

---

### 4.14 Rekapitulasi Seluruh Endpoint

| Kelompok | Blueprint | Jumlah | Public | Auth | Admin | Super |
|---|---|:---:|:---:|:---:|:---:|:---:|
| Autentikasi | §4.2 | 8 | 3 | 5 | 0 | 0 |
| School (Manajemen Tenant) | §4.3 | 7 | 0 | 0 | 0 | 7 |
| Users | §4.4 | 7 | 0 | 0 | 7 | 0 |
| Students (SIS) | §4.5 | 10 | 0 | 4 | 6 | 0 |
| Academic Year, Classes, Subjects, Schedules | §4.6 | 12 | 0 | 5 | 7 | 0 |
| PPDB | §4.7 | 9 | 4 | 0 | 5 | 0 |
| Academic (Penilaian & E-Rapor) | §4.8 | 12 | 0 | 11 | 1 | 0 |
| Finance — Tagihan & Pembayaran | §4.9.1 | 11 | 0 | 3 | 8 | 0 |
| Finance — Akuntansi & Kas | §4.9.2 | 7 | 0 | 3 | 4 | 0 |
| Notification | §4.10 | 8 | 0 | 5 | 3 | 0 |
| Portal (Parent/Teacher/Student) | §4.11 | 10 | 0 | 10 | 0 | 0 |
| **TOTAL** | | **101** | **7** | **46** | **41** | **7** |

**Sebaran menurut HTTP method:**

| Method | Jumlah | Keterangan |
|:---:|:---:|---|
| `GET` | 55 | Pembacaan data |
| `POST` | 30 | Pembuatan data, aksi, dan unggahan |
| `PATCH` | 9 | Perubahan sebagian (status, aktivasi, tandai dibaca) |
| `PUT` | 5 | Perubahan penuh (`schools`, `users`, `students`, `grades`, `fee-types`, `transactions`) |
| `DELETE` | 3 | `users`, `classes/{id}/students/{studentId}`, `transactions` |

**Operasi CRUD yang tidak tersedia (⚠):**

| Entitas | Operasi yang tidak ada | Status |
|---|---|---|
| `academic_years` | `PUT`, `DELETE` | **Belum dijelaskan dalam blueprint.** |
| `classes` | `PUT`, `DELETE` | **Belum dijelaskan dalam blueprint.** |
| `subjects` | `PUT`, `DELETE` | **Belum dijelaskan dalam blueprint.** |
| `schedules` | `PUT`, `DELETE` | **Belum dijelaskan dalam blueprint.** |
| `class_subjects` | Seluruh operasi | **Belum dijelaskan dalam blueprint.** |
| `grades` | `DELETE` | **Belum dijelaskan dalam blueprint.** |
| `grade_configs` | `PUT`, `DELETE` | **Belum dijelaskan dalam blueprint.** |
| `report_cards` | Unpublish, `DELETE` | **Belum dijelaskan dalam blueprint.** |
| `fee_types` | `DELETE` | **Belum dijelaskan dalam blueprint.** |
| `student_fees` | `PUT`, `DELETE` | **Belum dijelaskan dalam blueprint.** |
| `payments` | `PUT`, `DELETE` | **Belum dijelaskan dalam blueprint.** |
| `notifications` | `PUT`, `DELETE` | **Belum dijelaskan dalam blueprint.** |
| `roles` | Seluruh operasi | **Belum dijelaskan dalam blueprint.** |
| `schools` | `DELETE` | Cabang dinonaktifkan melalui `toggle`, bukan dihapus |

---

## 5. Request Validation

Seluruh aturan di bawah **bersumber dari acceptance criteria PRD §9, constraint PRD §7, dan definisi kolom `05-DATABASE.md`**. Tidak ada aturan baru.

### 5.1 Mekanisme Validasi

| Aspek | Ketentuan | Dasar |
|---|---|---|
| Lapisan | **Laravel Form Request** | Blueprint §3.4 |
| Cakupan | Validasi **semua input sebelum pemrosesan** | Blueprint §3.4 |
| Sanitasi | XSS via `htmlspecialchars` | Blueprint §3.4 |
| Format error | `{ "success": false, "message": "...", "errors": {...} }` | Blueprint §4.1 |
| Bahasa pesan | Wajib tersedia dalam **Bahasa Indonesia dan English** | AUTH-05 AC-3 |
| Bunyi pesan validasi | **Belum dijelaskan dalam blueprint.** | — |

### 5.2 Validasi Autentikasi & Akun

| Aturan | Endpoint | Sumber |
|---|---|---|
| `email` dan `password` wajib diisi | `POST /auth/login` | AUTH-01 AC-1 |
| Pesan error login **tidak boleh mengungkap detail sistem** | `POST /auth/login` | AUTH-01 AC-2; CON-34 |
| Rate limit login: **5 percobaan per menit** | `POST /auth/login` | Blueprint §3.4; CON-28 |
| Password minimal **8 karakter** | `POST /auth/reset-password`, `PATCH /auth/me/password`, `POST /users` | NFR-07; CON-27 |
| Hash password **Argon2id** | Seluruh endpoint password | Blueprint §3.4 |
| Password **wajib diganti saat login pertama** | Alur login | NFR-07; CON-27 |
| Link reset berlaku maksimal **60 menit** | `POST /auth/reset-password` | AUTH-04 AC-2; CON-30 |
| `current_password` **wajib dikirim** saat ganti password | `PATCH /auth/me/password` | Blueprint §4.2 |
| `email` wajib **unik lintas seluruh platform** | `POST /users` | `05-DATABASE.md` §5.2; ASM-12 |
| `locale` hanya bernilai `id` atau `en` | `PATCH /auth/me`, `POST /users` | AUTH-05 AC-2 |

### 5.3 Validasi Data Siswa

| Aturan | Endpoint | Sumber |
|---|---|---|
| Field wajib: nama, NIS, NISN, tanggal lahir, jenis kelamin, agama, alamat, nama ortu, no. HP ortu | `POST /students` | SIS-01 AC-1 |
| **NISN wajib 10 digit angka** | `POST /students`, `PUT /students/{id}` | SIS-01 AC-2; CON-38 |
| **NIS wajib unik dalam satu sekolah** | `POST /students` | SIS-01 AC-3; CON-38 |
| Foto: **JPG/PNG/WEBP**, maksimal **2 MB**, auto-resize **400×400 px** | `POST /students/{id}/photo` | SIS-03; CON-43 |
| `gender` hanya `L` atau `P` | `POST /students` | `05-DATABASE.md` §5.5 |
| `status` hanya `ACTIVE`, `GRADUATED`, `DROPPED_OUT`, `TRANSFERRED` | `PATCH /students/{id}/status` | `05-DATABASE.md` §5.5 |
| Import Excel mengembalikan **daftar error per baris** | `POST /students/import`, `POST /users/import` | Blueprint §4.4 |

### 5.4 Validasi Kelas, Jadwal & Tahun Ajaran

| Aturan | Endpoint | Sumber |
|---|---|---|
| Field kelas wajib: nama, tingkat, wali kelas, kapasitas | `POST /classes` | KELAS-01 AC-1 |
| Wali kelas hanya dipilih dari **guru aktif** | `POST /classes` | KELAS-01 AC-2 |
| **Satu guru = satu kelas per tahun ajaran** | `POST /classes` | KELAS-01 AC-3; CON-36 |
| Siswa dipilih dari **siswa aktif yang belum berkelas** pada TA tersebut | `POST /classes/{id}/students` | KELAS-02 AC-1 |
| **Satu siswa = satu kelas per tahun ajaran** | `POST /classes/{id}/students` | KELAS-02 AC-2; CON-35 |
| Field jadwal wajib: kelas, mapel, guru, hari, jam mulai, jam selesai, ruang | `POST /schedules` | KELAS-03 AC-1 |
| **Deteksi konflik jadwal** — guru, ruang, atau kelas yang sama pada waktu bersamaan | `POST /schedules` | KELAS-03 AC-2; CON-48 |
| **Hanya satu tahun ajaran aktif per sekolah** | `PATCH /academic-years/{id}/activate` | CON-37 |
| `grade_level` bernilai 10, 11, atau 12 | `POST /classes` | `05-DATABASE.md` §5.7 |
| `day_of_week` bernilai 1–7 | `POST /schedules` | `05-DATABASE.md` §5.11 |
| `semester` bernilai 1 atau 2 | `POST /academic-years` | `05-DATABASE.md` §5.6 |

### 5.5 Validasi PPDB

| Aturan | Endpoint | Sumber |
|---|---|---|
| Field wajib: nama lengkap, jenis kelamin, tanggal lahir, asal sekolah, nama ortu, no. HP, email | `POST /ppdb/{schoolCode}/register` | PPDB-01 AC-2 |
| `reg_number` wajib **unik** | `POST /ppdb/{schoolCode}/register` | `05-DATABASE.md` §5.12 |
| Cek status memerlukan **nomor daftar + tanggal lahir** | `GET /ppdb/check-status` | Blueprint §4.7 |
| Perubahan status **wajib disertai catatan alasan** | `PATCH /admin/ppdb/{id}/status` | PPDB-03 AC-3 |
| `status` hanya bernilai `REGISTERED`, `DOCUMENT_REVIEW`, `PASSED`, `FAILED`, `ENROLLED` | `PATCH /admin/ppdb/{id}/status` | `05-DATABASE.md` §5.12 |
| Enroll hanya untuk pendaftar berstatus `PASSED` | `POST /admin/ppdb/{id}/enroll` | PPDB-05 |
| Batas ukuran dan format dokumen unggahan | `POST /ppdb/{schoolCode}/register` | **Belum dijelaskan dalam blueprint.** |

### 5.6 Validasi Penilaian & Rapor

| Aturan | Endpoint | Sumber |
|---|---|---|
| Nilai dalam **skala 0–100** | `POST /grades`, `POST /grades/bulk`, `POST /grades/import` | NILAI-01 AC-1; CON-39 |
| **Guru hanya bisa input nilai untuk kelas yang dia ampu** | `POST /grades` | Blueprint §4.8 |
| Nilai dapat diedit **hanya selama rapor belum published** | `PUT /grades/{id}` | NILAI-01 AC-3; CON-40 |
| Hasil perhitungan dibulatkan **2 desimal** | Perhitungan nilai akhir | NILAI-02 AC-3; CON-39 |
| Sebelum publish, **semua mapel wajib memiliki nilai akhir** | `POST /report-cards/{id}/publish` | NILAI-03 AC-1; CON-41 |
| Generate rapor **hanya oleh Wali Kelas** | `POST /report-cards/generate` | Blueprint §4.8 |
| Perubahan konfigurasi bobot **hanya berlaku untuk TA baru** | `POST /grade-configs` | NILAI-05 AC-2; CON-42 |
| `grade_type` hanya bernilai `DAILY`, `MIDTERM`, `FINAL`, `ASSIGNMENT`, `SKILL`, `ATTITUDE` | `POST /grades` | `05-DATABASE.md` §5.13 |
| `attitude_score` hanya bernilai `A`, `B`, `C`, `D` | Publish rapor | `05-DATABASE.md` §5.14 |
| Validasi total bobot `components` = 1.00 | `POST /grade-configs` | **Belum dijelaskan dalam blueprint.** |

### 5.7 Validasi Keuangan

| Aturan | Endpoint | Sumber |
|---|---|---|
| Field jenis tagihan wajib: nama, jumlah, frekuensi | `POST /fee-types` | SPP-01 AC-1 |
| `frequency` hanya `MONTHLY`, `YEARLY`, `ONCE` | `POST /fee-types` | `05-DATABASE.md` §5.16 |
| **Preview wajib sebelum konfirmasi generate massal** | `POST /student-fees/generate-bulk` | SPP-02 AC-3; CON-47 |
| Generate massal menyasar **seluruh siswa aktif** | `POST /student-fees/generate-bulk` | SPP-02 AC-1 |
| Field pembayaran wajib: siswa, periode, metode, jumlah, tanggal, referensi | `POST /payments` | SPP-03 AC-1 |
| Bukti pembayaran: **JPG/PNG/PDF, maksimal 5 MB** | `POST /payments/{id}/proof` | SPP-03 AC-2; CON-44 |
| `payment_method` hanya `CASH`, `TRANSFER`, `PAYMENT_GATEWAY` | `POST /payments` | `05-DATABASE.md` §5.18 |
| Pembebasan tagihan **wajib disertai alasan** | `PATCH /student-fees/{id}/waive` | Blueprint §4.9.1 |
| `period` berformat `YYYY-MM` | `POST /student-fees/generate-bulk` | `05-DATABASE.md` §5.17 |
| Field transaksi kas wajib: jenis, kategori, jumlah, tanggal, keterangan, nomor referensi | `POST /transactions` | KAS-01 AC-1 |
| `type` hanya `INCOME` atau `EXPENSE` | `POST /transactions` | `05-DATABASE.md` §5.19 |

### 5.8 Validasi Notifikasi

| Aturan | Endpoint | Sumber |
|---|---|---|
| Field wajib: judul, isi pesan, target, kategori | `POST /notifications` | NOTIF-01 AC-1 |
| `target_type` hanya `ALL`, `CLASS`, `INDIVIDUAL` | `POST /notifications` | `05-DATABASE.md` §5.20 |
| `type` hanya `ANNOUNCEMENT`, `BILLING`, `ACADEMIC`, `EMERGENCY`, `SYSTEM` | `POST /notifications` | `05-DATABASE.md` §5.20 |
| Format URL wa.me: `wa.me/62[nomorHP]?text=[pesan_ter-encode]` | `GET /notifications/{id}/wa-links` | NOTIF-02 AC-1 |
| Aturan normalisasi nomor HP ke awalan `62` | `GET /notifications/{id}/wa-links` | **Belum dijelaskan dalam blueprint.** |

### 5.9 Validasi Berkas Unggahan

| Endpoint | Format | Ukuran Maks | Pemrosesan | Sumber |
|---|---|:---:|---|---|
| `POST /students/{id}/photo` | JPG, PNG, WEBP | **2 MB** | Auto-resize 400×400 px | SIS-03 |
| `POST /payments/{id}/proof` | JPG, PNG, PDF | **5 MB** | — | SPP-03 AC-2 |
| `POST /transactions` (lampiran bukti) | **Belum dijelaskan dalam blueprint.** | ⚠ | — | KAS-01 AC-2 |
| `POST /ppdb/{schoolCode}/register` (dokumen) | **Belum dijelaskan dalam blueprint.** | ⚠ | — | PPDB-01 |
| `PATCH /auth/me` (avatar) | **Belum dijelaskan dalam blueprint.** | ⚠ | — | Blueprint §4.2 |
| `POST /users/import`, `POST /students/import`, `POST /grades/import` | `.xlsx` | **Belum dijelaskan dalam blueprint.** | Mengembalikan daftar error per baris | Blueprint §4.4 |

**Ketentuan umum berkas unggahan (Blueprint §3.4):**

| Aturan | Ketentuan |
|---|---|
| Validasi | MIME type + ukuran |
| Format yang diizinkan | *"Hanya JPG/PNG/PDF diperbolehkan"* |
| Lokasi penyimpanan | `storage/` — **di luar web root** (CON-31) |

> **⚠ Konflik:** SIS-03 mengizinkan **WEBP** untuk foto siswa, sedangkan §3.4 menyatakan hanya JPG/PNG/PDF. (PRD §17.3 #12)

### 5.10 Aturan Validasi yang Belum Dijelaskan

| Aspek | Status |
|---|---|
| Bunyi pesan validasi dalam ID dan EN | **Belum dijelaskan dalam blueprint.** |
| Nama field persis pada request body | **Belum dijelaskan dalam blueprint.** |
| Aturan kompleksitas password selain panjang minimum | **Belum dijelaskan dalam blueprint.** |
| Format dan panjang nomor telepon | **Belum dijelaskan dalam blueprint.** |
| Validasi `end_time` > `start_time` pada jadwal | **Belum dijelaskan dalam blueprint.** |
| Validasi `end_date` > `start_date` pada tahun ajaran | **Belum dijelaskan dalam blueprint.** |
| Validasi kapasitas kelas saat penambahan siswa | **Belum dijelaskan dalam blueprint.** |
| Validasi total bobot penilaian = 100% | **Belum dijelaskan dalam blueprint.** |
| Batas nilai maksimum untuk kolom `amount` | **Belum dijelaskan dalam blueprint** — dibatasi tipe `DECIMAL(12,2)` |
| Struktur kolom template import Excel | **Belum dijelaskan dalam blueprint.** |

---

## 6. Response Format

Blueprint **menetapkan format response secara eksplisit** pada §4.1 Konvensi API.

### 6.1 Response Sukses

**Struktur yang ditetapkan blueprint:**

```
{
  "success": true,
  "data": { ... },
  "message": "..."
}
```

| Field | Keterangan | Status |
|---|---|:---:|
| `success` | Bernilai `true` untuk response sukses | ✔ Blueprint |
| `data` | Muatan data — objek atau array | ✔ Blueprint |
| `message` | Pesan yang menyertai response | ✔ Blueprint |
| Isi `data` per endpoint | Struktur field spesifik | ⚠ **Belum dijelaskan dalam blueprint.** |
| Bunyi `message` | Teks pesan | ⚠ **Belum dijelaskan dalam blueprint.** |

### 6.2 Response Gagal

**Struktur yang ditetapkan blueprint:**

```
{
  "success": false,
  "message": "...",
  "errors": { ... }
}
```

| Field | Keterangan | Status |
|---|---|:---:|
| `success` | Bernilai `false` untuk response gagal | ✔ Blueprint |
| `message` | Pesan kesalahan | ✔ Blueprint |
| `errors` | Objek rincian kesalahan | ✔ Blueprint |
| Struktur objek `errors` | Format per-field atau lainnya | ⚠ **Belum dijelaskan dalam blueprint.** |
| Kode error internal | Error code selain HTTP status | ⚠ **Belum dijelaskan dalam blueprint.** |

**Prinsip yang dinyatakan blueprint:** pesan error login **tidak boleh mengungkap detail sistem** — tidak membedakan "email tidak terdaftar" dari "password salah" (AUTH-01 AC-2; CON-34).

### 6.3 Response Berpaginasi

**Struktur yang ditetapkan blueprint:**

```
{
  "data": [ ... ],
  "meta": {
    "total": ...,
    "page": ...,
    "per_page": ...,
    "last_page": ...
  }
}
```

| Field | Keterangan | Status |
|---|---|:---:|
| `data` | Array data pada halaman ini | ✔ Blueprint |
| `meta.total` | Jumlah total record | ✔ Blueprint |
| `meta.page` | Nomor halaman saat ini | ✔ Blueprint |
| `meta.per_page` | Jumlah record per halaman | ✔ Blueprint |
| `meta.last_page` | Nomor halaman terakhir | ✔ Blueprint |
| Nilai `per_page` default dan maksimum | — | ⚠ **Belum dijelaskan dalam blueprint.** |
| Nama parameter query untuk paginasi | — | ⚠ **Belum dijelaskan dalam blueprint.** |
| Endpoint mana saja yang berpaginasi | — | ⚠ **Belum dijelaskan dalam blueprint.** |

> **Catatan:** struktur berpaginasi pada blueprint **tidak memuat field `success` maupun `message`**, berbeda dari struktur response sukses umum. Blueprint tidak menjelaskan apakah keduanya digabung atau berdiri sendiri. → **Belum dijelaskan dalam blueprint.**

**Satu-satunya batas yang disebut blueprint:** `GET /notifications` dibatasi **50 notifikasi terbaru** (§4.10).

### 6.4 Format Timestamp

| Aspek | Ketentuan | Contoh |
|---|---|---|
| Standar | **ISO 8601** dengan offset zona waktu | `2025-08-06T10:30:00+07:00` |
| Zona waktu | `+07:00` (WIB) | Blueprint §4.1 |

> Apakah nilai `TIMESTAMP` disimpan dalam UTC lalu dikonversi, atau disimpan langsung dalam waktu lokal: **Belum dijelaskan dalam blueprint.** (`05-DATABASE.md` §2.3)

### 6.5 Format Response Non-JSON

| Endpoint | Jenis Keluaran | Keterangan |
|---|---|---|
| `GET /report-cards/{id}/pdf` | Berkas PDF | Dihasilkan DomPDF / Browsershot (Blueprint §3.1) |
| `GET /students/export` | Berkas Excel `.xlsx` | Nama berkas: `siswa_[kode_sekolah]_[tanggal].xlsx` (SIS-05 AC-2) |
| `GET /student-fees/export` | Berkas Excel `.xlsx` | Kolom sesuai SPP-05 AC-1 |
| `GET /finance/export` | Berkas Excel `.xlsx` | Struktur kolom: **Belum dijelaskan dalam blueprint.** |

> Mekanisme pengiriman berkas (unduhan langsung, tautan sementara, atau lainnya): **Belum dijelaskan dalam blueprint.**

### 6.6 Ringkasan Kelengkapan Format Response

| Aspek | Ditetapkan Blueprint? |
|---|:---:|
| Struktur response sukses | ✔ |
| Struktur response gagal | ✔ |
| Struktur paginasi | ✔ |
| Format timestamp | ✔ |
| Content-Type | ✔ `application/json` |
| Isi objek `data` per endpoint | ✘ |
| Struktur objek `errors` | ✘ |
| Kode HTTP status | ✘ |
| Bunyi pesan | ✘ |
| Konvensi penamaan field (camelCase / snake_case) | ✘ |

> Untuk seluruh butir bertanda ✘, **format implementasi akan ditentukan pada tahap development** dan wajib didokumentasikan sebagai keputusan teknis. Bila keputusan tersebut mengubah kontrak API, perubahannya harus diterbitkan sebagai revisi blueprint (CON-55).

---

## 7. Error Handling

> **Catatan penting:** blueprint menetapkan **struktur response error** (§4.1) dan beberapa perilaku error spesifik, namun **tidak menetapkan kode HTTP status untuk skenario apa pun**. Seluruh pemetaan kode di bawah karena itu ditandai **"Belum dijelaskan dalam blueprint."**

### 7.1 Ringkasan Kode Error

| Kode | Skenario Umum | Dijelaskan Blueprint? | Yang dijelaskan blueprint |
|:---:|---|:---:|---|
| **400** | Request tidak valid secara struktural | ✘ | **Belum dijelaskan dalam blueprint.** |
| **401** | Tidak terautentikasi / token tidak valid / sesi habis | ✘ | Token kedaluwarsa setelah 8 jam tidak aktif (AUTH-01 AC-4) |
| **403** | Terautentikasi tetapi tidak berhak | ✘ | Matriks izin dan Auth Level menentukan penolakan (PRD §8.2, §8.4) |
| **404** | Sumber daya tidak ditemukan | ✘ | Data cabang lain tersaring Global Scope sehingga tampak tidak ada (AUTH-02 AC-3) |
| **422** | Validasi input gagal | ✘ | Objek `errors` pada response (§4.1); aturan validasi pada §5 dokumen ini |
| **429** | Melebihi rate limit | ✘ | Login 5/menit; API 60/menit per user (Blueprint §3.4) |
| **500** | Kesalahan server | ✘ | Monitoring uptime aktif (Lampiran A.3 #10) |

### 7.2 Error 400 — Bad Request

| Aspek | Status |
|---|---|
| Kode dan skenario penggunaan | **Belum dijelaskan dalam blueprint.** |
| Format response | ✔ `{ "success": false, "message": "...", "errors": {...} }` (§4.1) |
| Pembedaan dari 422 | **Belum dijelaskan dalam blueprint.** |

### 7.3 Error 401 — Unauthorized

**Skenario yang dijelaskan blueprint (tanpa kode status):**

| Skenario | Ketentuan | Sumber |
|---|---|---|
| Kredensial login salah | Pesan error **tidak boleh mengungkap detail sistem** | AUTH-01 AC-2; CON-34 |
| Token kedaluwarsa | Setelah **8 jam tidak aktif** | AUTH-01 AC-4; CON-29 |
| Sesi di-invalidate setelah reset password | Seluruh sesi aktif berakhir | AUTH-04 AC-3; CON-30 |
| Token tidak dikirim pada endpoint Auth | Endpoint memerlukan `Authorization: Bearer {token}` | Blueprint §4.1 |

| Aspek | Status |
|---|---|
| Kode HTTP status | **Belum dijelaskan dalam blueprint.** |
| Perilaku setelah sesi habis (redirect, peringatan) | **Belum dijelaskan dalam blueprint.** |
| Perilaku login pada akun `is_active = 0` | **Belum dijelaskan dalam blueprint.** |
| Jalur pencegahan | `POST /auth/refresh` memperbarui token yang hampir kedaluwarsa |

### 7.4 Error 403 — Forbidden

**Skenario yang dijelaskan blueprint (tanpa kode status):**

| Lapisan penolakan | Contoh | Sumber |
|---|---|---|
| Auth Level endpoint | `GURU` mengakses `POST /students` (butuh Admin) | Blueprint §4.1 |
| Auth Level Super | `SCHOOL_ADMIN` mengakses `GET /admin/schools` | Blueprint §4.3 |
| Matriks izin modul | `BENDAHARA` mencoba `POST /grades` (Input Nilai = ❌) | PRD §8.2 |
| Matriks izin modul | `KEPALA_SEKOLAH` mencoba `POST /payments` (Catat Pembayaran = ❌) | PRD §8.2 |
| Policy per record | Guru A menilai kelas yang diampu Guru B | Blueprint §4.8 |
| Policy per record | Orang tua membuka tagihan anak orang lain | Blueprint §4.5 |
| Policy per record | Guru selain wali kelas mencoba publish rapor | Blueprint §4.8 |

| Aspek | Status |
|---|---|
| Kode HTTP status | **Belum dijelaskan dalam blueprint.** |
| Pembedaan 401 vs 403 | **Belum dijelaskan dalam blueprint.** |
| Apakah upaya akses ditolak dicatat di audit log | **Belum dijelaskan dalam blueprint.** |

### 7.5 Error 404 — Not Found

**Perilaku yang dijelaskan blueprint:**

| Skenario | Ketentuan | Sumber |
|---|---|---|
| Data tidak ada | Query dijalankan dengan Global Scope | CON-15 |
| **Data milik cabang lain** | Tersaring Global Scope sehingga **tampak tidak ditemukan** — ini adalah **perilaku yang dikehendaki**: pengguna tidak boleh mengetahui keberadaan data cabang lain | AUTH-02 AC-3 |
| Routing | Nginx meneruskan seluruh request yang tidak cocok ke Laravel (`try_files $uri $uri/ /index.php?$query_string`) | Blueprint §3.3.2 |

| Aspek | Status |
|---|---|
| Kode HTTP status | **Belum dijelaskan dalam blueprint.** |
| Teks pesan | **Belum dijelaskan dalam blueprint.** |
| Apakah 404 dan 403 dibedakan untuk data cabang lain | **Belum dijelaskan dalam blueprint.** |

### 7.6 Error 422 — Unprocessable Entity

**Yang dijelaskan blueprint:**

| Aspek | Ketentuan | Sumber |
|---|---|---|
| Mekanisme validasi | Laravel Form Request | Blueprint §3.4 |
| Format response | `{ "success": false, "message": "...", "errors": {...} }` | Blueprint §4.1 |
| Daftar aturan validasi | 40+ aturan pada [§5](#5-request-validation) | PRD §9; CON-35…CON-50 |

**Contoh skenario validasi gagal (dari acceptance criteria):**

| Skenario | Sumber |
|---|---|
| NISN bukan 10 digit angka | SIS-01 AC-2 |
| NIS duplikat dalam satu sekolah | SIS-01 AC-3 |
| Foto siswa melebihi 2 MB | SIS-03 AC-2 |
| Bukti bayar melebihi 5 MB | SPP-03 AC-2 |
| Password kurang dari 8 karakter | NFR-07 |
| Nilai di luar skala 0–100 | NILAI-01 AC-1 |
| Konflik jadwal guru/ruang/kelas | KELAS-03 AC-2 |
| Satu siswa ditempatkan di dua kelas pada TA sama | KELAS-02 AC-2 |
| Satu guru menjadi wali dua kelas pada TA sama | KELAS-01 AC-3 |
| Publish rapor tanpa nilai lengkap | NILAI-03 AC-1 |
| Edit nilai setelah rapor published | NILAI-01 AC-3 |
| Perubahan status PPDB tanpa catatan alasan | PPDB-03 AC-3 |

| Aspek | Status |
|---|---|
| Kode HTTP status | **Belum dijelaskan dalam blueprint.** |
| Struktur objek `errors` (per-field atau lainnya) | **Belum dijelaskan dalam blueprint.** |
| Bunyi pesan validasi | **Belum dijelaskan dalam blueprint** — wajib dwibahasa (AUTH-05 AC-3) |

### 7.7 Error 429 — Too Many Requests

| Aspek | Ketentuan | Sumber |
|---|---|---|
| Rate limit login | **5 percobaan per menit** | Blueprint §3.4; CON-28 |
| Rate limit API | **60 request per menit per user** | Blueprint §3.4; CON-28 |
| Mekanisme | Laravel Throttle Middleware | Blueprint §3.4 |
| Kode HTTP status | **Belum dijelaskan dalam blueprint.** |
| Durasi pemblokiran | **Belum dijelaskan dalam blueprint.** |
| Header sisa kuota | **Belum dijelaskan dalam blueprint.** |

### 7.8 Error 500 — Internal Server Error

| Aspek | Status |
|---|---|
| Kode dan penanganan | **Belum dijelaskan dalam blueprint.** |
| Prinsip yang dapat dijadikan acuan | Pesan error tidak mengungkap detail sistem — dinyatakan blueprint untuk konteks login (AUTH-01 AC-2) |
| Pemantauan | Monitoring uptime aktif via UptimeRobot / Better Stack (Lampiran A.3 #10) |
| Target ketersediaan | Uptime 99% per bulan (NFR-08) |
| Logging error dan alerting | **Belum dijelaskan dalam blueprint.** |
| Prosedur incident response | **Belum dijelaskan dalam blueprint.** |

### 7.9 Ringkasan Kelengkapan Error Handling

| Aspek | Ditetapkan Blueprint? |
|---|:---:|
| Struktur response error | ✔ |
| Prinsip pesan login tidak bocorkan detail | ✔ |
| Rate limit (nilai batas) | ✔ |
| Perilaku Global Scope pada data cabang lain | ✔ |
| Aturan validasi (40+ butir) | ✔ |
| **Kode HTTP status per skenario** | ✘ |
| **Struktur objek `errors`** | ✘ |
| **Kode error internal** | ✘ |
| **Bunyi pesan error** | ✘ |
| **Halaman error (404, 500)** | ✘ |
| **Logging dan alerting** | ✘ |

> Untuk butir bertanda ✘, pemetaan kode dan penanganannya **akan ditentukan pada tahap development**. Daftar ini juga tercantum pada `03-USER_FLOW.md` §9 sebagai titik terbuka alur error.

---

## 8. Permission Matrix

### 8.1 Matriks Izin per Modul (Blueprint §1.1.2)

**Legenda:** ✅ akses penuh · ⭕ baca/view saja · ❌ tidak ada akses

| Modul | SUPER_ADMIN | SCHOOL_ADMIN | KEPALA | GURU/WALI | BENDAHARA | SISWA | ORTU |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| Manajemen Tenant/Cabang | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Data Siswa (SIS) | ✅ | ✅ | ⭕ | ⭕ | ⭕ | ⭕ | ⭕ |
| PPDB Online | ✅ | ✅ | ⭕ | ❌ | ❌ | ❌ | ❌ |
| Kelas & Jadwal | ✅ | ✅ | ⭕ | ⭕ | ❌ | ⭕ | ❌ |
| Input Nilai | ✅ | ✅ | ⭕ | ✅ | ❌ | ❌ | ❌ |
| Generate Rapor | ✅ | ✅ | ⭕ | ✅ (Wali) | ❌ | ⭕ | ⭕ |
| Tagihan SPP | ✅ | ✅ | ⭕ | ❌ | ✅ | ❌ | ⭕ |
| Catat Pembayaran | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ |
| Akuntansi & Kas | ✅ | ✅ | ⭕ | ❌ | ✅ | ❌ | ❌ |
| Laporan Keuangan | ✅ | ✅ | ⭕ | ❌ | ✅ | ❌ | ❌ |
| Notifikasi (buat) | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Portal Siswa | ✅ | ✅ | ⭕ | ⭕ | ❌ | ✅ | ❌ |
| Parent Portal | ✅ | ✅ | ⭕ | ❌ | ❌ | ❌ | ✅ |
| White-label Settings | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| User Management | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |

### 8.2 Matriks Izin per Endpoint

| Endpoint | Auth Level | SUPER | SCHOOL_ADMIN | KEPALA | GURU | WALI | BENDAHARA | SISWA | ORTU |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| **AUTENTIKASI** | | | | | | | | | |
| `POST /auth/login` | Public | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `POST /auth/logout` | Auth | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `GET /auth/me` | Auth | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `POST /auth/refresh` | Auth | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `POST /auth/forgot-password` | Public | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `POST /auth/reset-password` | Public | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `PATCH /auth/me` | Auth | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `PATCH /auth/me/password` | Auth | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| **SCHOOL / TENANT** | | | | | | | | | |
| `GET /admin/schools` | Super | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `POST /admin/schools` | Super | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `GET /admin/schools/{id}` | Super | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `PUT /admin/schools/{id}` | Super | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `PATCH /admin/schools/{id}/toggle` | Super | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `GET /admin/schools/{id}/stats` | Super | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `GET /admin/dashboard` | Super | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| **USERS** | | | | | | | | | |
| `GET /users` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `POST /users` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `GET /users/{id}` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `PUT /users/{id}` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `DELETE /users/{id}` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `POST /users/import` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `POST /users/{id}/reset-password` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| **STUDENTS** | | | | | | | | | |
| `GET /students` | Auth | ✔ | ✔ | ⭕ | ⭕¹ | ⭕¹ | ⭕ | ⭕² | ⭕³ |
| `POST /students` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `GET /students/{id}` | Auth | ✔ | ✔ | ⭕ | ⭕¹ | ⭕¹ | ⭕ | ⭕² | ⭕³ |
| `PUT /students/{id}` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `PATCH /students/{id}/status` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `POST /students/{id}/photo` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `GET /students/{id}/grades` | Auth | ✔ | ✔ | ⭕ | ⭕¹ | ⭕¹ | ✘ | ⭕² | ⭕³ |
| `GET /students/{id}/fees` | Auth | ✔ | ✔ | ⭕ | ✘ | ✘ | ✔ | ✘ | ⭕³ |
| `GET /students/export` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `POST /students/import` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| **ACADEMIC YEAR / CLASSES / SUBJECTS / SCHEDULES** | | | | | | | | | |
| `GET /academic-years` | Auth | ✔ | ✔ | ⭕ | ⭕ | ⭕ | ✘ | ⭕ | ✘ |
| `POST /academic-years` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `PATCH /academic-years/{id}/activate` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `GET /classes` | Auth | ✔ | ✔ | ⭕ | ⭕ | ⭕ | ✘ | ⭕ | ✘ |
| `POST /classes` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `GET /classes/{id}` | Auth | ✔ | ✔ | ⭕ | ⭕ | ⭕ | ✘ | ⭕ | ✘ |
| `POST /classes/{id}/students` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `DELETE /classes/{id}/students/{sid}` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `GET /subjects` | Auth | ✔ | ✔ | ⭕ | ⭕ | ⭕ | ✘ | ⭕ | ✘ |
| `POST /subjects` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `GET /schedules` | Auth | ✔ | ✔ | ⭕ | ⭕⁴ | ⭕⁴ | ✘ | ⭕ | ✘ |
| `POST /schedules` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| **PPDB** | | | | | | | | | |
| `GET /ppdb/schools` | Public | — publik, tanpa login — |||||||| |
| `GET /ppdb/{schoolCode}/info` | Public | — publik, tanpa login — |||||||| |
| `POST /ppdb/{schoolCode}/register` | Public | — publik, tanpa login — |||||||| |
| `GET /ppdb/check-status` | Public | — publik, tanpa login — |||||||| |
| `GET /admin/ppdb` | Admin | ✔ | ✔ | ⭕ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `GET /admin/ppdb/{id}` | Admin | ✔ | ✔ | ⭕ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `PATCH /admin/ppdb/{id}/status` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `GET /admin/ppdb/{id}/wa-link` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `POST /admin/ppdb/{id}/enroll` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| **ACADEMIC** | | | | | | | | | |
| `GET /grades` | Auth | ✔ | ✔ | ⭕ | ⭕¹ | ⭕¹ | ✘ | ⭕² | ⭕³ |
| `POST /grades` | Auth | ✔ | ✔ | ✘ | ✔¹ | ✔¹ | ✘ | ✘ | ✘ |
| `PUT /grades/{id}` | Auth | ✔ | ✔ | ✘ | ✔¹⁵ | ✔¹⁵ | ✘ | ✘ | ✘ |
| `POST /grades/bulk` | Auth | ✔ | ✔ | ✘ | ✔¹ | ✔¹ | ✘ | ✘ | ✘ |
| `POST /grades/import` | Auth | ✔ | ✔ | ✘ | ✔¹ | ✔¹ | ✘ | ✘ | ✘ |
| `GET /grade-configs` | Auth | ✔ | ✔ | ⭕ | ⭕ | ⭕ | ✘ | ✘ | ✘ |
| `POST /grade-configs` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `GET /report-cards` | Auth | ✔ | ✔ | ⭕ | ⭕ | ⭕ | ✘ | ⭕² | ⭕³ |
| `GET /report-cards/{id}` | Auth | ✔ | ✔ | ⭕ | ⭕ | ⭕ | ✘ | ⭕² | ⭕³ |
| `POST /report-cards/generate` | Auth | ✔ | ✔ | ✘ | ✘ | ✔⁶ | ✘ | ✘ | ✘ |
| `POST /report-cards/{id}/publish` | Auth | ✔ | ✔ | ✘ | ✘ | ✔⁶ | ✘ | ✘ | ✘ |
| `GET /report-cards/{id}/pdf` | Auth | ✔ | ✔ | ⭕ | ⭕ | ⭕ | ✘ | ⭕² | ⭕³ |
| **FINANCE** | | | | | | | | | |
| `GET /fee-types` | Auth | ✔ | ✔ | ⭕ | ✘ | ✘ | ✔ | ✘ | ✘ |
| `POST /fee-types` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✔ | ✘ | ✘ |
| `PUT /fee-types/{id}` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✔ | ✘ | ✘ |
| `GET /student-fees` | Auth | ✔ | ✔ | ⭕ | ✘ | ✘ | ✔ | ✘ | ⭕³ |
| `POST /student-fees/generate-bulk` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✔ | ✘ | ✘ |
| `GET /student-fees/{id}` | Auth | ✔ | ✔ | ⭕ | ✘ | ✘ | ✔ | ✘ | ⭕³ |
| `PATCH /student-fees/{id}/waive` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✘⁷ | ✘ | ✘ |
| `GET /student-fees/export` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✔ | ✘ | ✘ |
| `POST /payments` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✔ | ✘ | ✘ |
| `POST /payments/{id}/proof` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✔ | ✘ | ✘ |
| `GET /payments` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✔ | ✘ | ✘ |
| `GET /transactions` | Auth | ✔ | ✔ | ⭕ | ✘ | ✘ | ✔ | ✘ | ✘ |
| `POST /transactions` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✔ | ✘ | ✘ |
| `PUT /transactions/{id}` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✔ | ✘ | ✘ |
| `DELETE /transactions/{id}` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✔ | ✘ | ✘ |
| `GET /finance/summary` | Auth | ✔ | ✔ | ⭕ | ✘ | ✘ | ✔ | ✘ | ✘ |
| `GET /finance/spp-report` | Auth | ✔ | ✔ | ⭕ | ✘ | ✘ | ✔ | ✘ | ✘ |
| `GET /finance/export` | Admin | ✔ | ✔ | ✘ | ✘ | ✘ | ✔ | ✘ | ✘ |
| **NOTIFICATION** | | | | | | | | | |
| `GET /notifications` | Auth | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `GET /notifications/unread-count` | Auth | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `POST /notifications` | Admin | ✔ | ✔ | ✔ | ✘⚠ | ✘⚠ | ✘ | ✘ | ✘ |
| `GET /notifications/{id}` | Auth | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `PATCH /notifications/{id}/read` | Auth | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `POST /notifications/mark-all-read` | Auth | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| `GET /notifications/{id}/wa-links` | Admin | ✔ | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ |
| `GET /admin/notifications` | Admin | ✔ | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ | ✘ |
| **PORTAL** | | | | | | | | | |
| `GET /parent/children` | Auth | ✔ | ✔ | ⭕ | ✘ | ✘ | ✘ | ✘ | ✔ |
| `GET /parent/children/{sid}/summary` | Auth | ✔ | ✔ | ⭕ | ✘ | ✘ | ✘ | ✘ | ✔³ |
| `GET /parent/children/{sid}/grades` | Auth | ✔ | ✔ | ⭕ | ✘ | ✘ | ✘ | ✘ | ✔³ |
| `GET /parent/children/{sid}/fees` | Auth | ✔ | ✔ | ⭕ | ✘ | ✘ | ✘ | ✘ | ✔³ |
| `GET /parent/children/{sid}/schedule` | Auth | ✔ | ✔ | ⭕ | ✘ | ✘ | ✘ | ✘ | ✔³ |
| `GET /teacher/dashboard` | Auth | ✔ | ✔ | ⭕ | ✔ | ✔ | ✘ | ✘ | ✘ |
| `GET /teacher/classes` | Auth | ✔ | ✔ | ⭕ | ✔¹ | ✔¹ | ✘ | ✘ | ✘ |
| `GET /student/dashboard` | Auth | ✔ | ✔ | ⭕ | ⭕ | ⭕ | ✘ | ✔² | ✘ |
| `GET /student/schedule` | Auth | ✔ | ✔ | ⭕ | ⭕ | ⭕ | ✘ | ✔² | ✘ |
| `GET /student/grades` | Auth | ✔ | ✔ | ⭕ | ⭕ | ⭕ | ✘ | ✔² | ✘ |

**Keterangan catatan kaki:**

| # | Pembatasan per-record | Sumber |
|:---:|---|---|
| **1** | Guru hanya dapat mengakses **kelas yang diampunya** | Blueprint §4.5, §4.8, §4.11 |
| **2** | Siswa hanya dapat mengakses **data dirinya sendiri** | PRD §8.3 |
| **3** | Orang tua hanya dapat mengakses **data anaknya sendiri** | Blueprint §4.5; PRD §8.3 |
| **4** | Guru hanya melihat **jadwal dirinya sendiri** | Blueprint §4.6 |
| **5** | Edit nilai hanya selama **rapor belum published** | Blueprint §4.8; CON-40 |
| **6** | Generate dan publish rapor **hanya oleh Wali Kelas** | Blueprint §4.8 |
| **7** | Pembebasan tagihan ber-Auth Level **Admin** — bukan kewenangan Bendahara | Blueprint §4.9.1 |
| **⚠** | Konflik matriks izin vs shortcut PORTAL-02 untuk pembuatan pengumuman oleh guru | PRD §17.3 #3 |

### 8.3 Ringkasan Jumlah Endpoint per Role

| Role | Endpoint Dapat Diakses (penuh atau baca) | Endpoint Ditolak |
|---|:---:|:---:|
| `SUPER_ADMIN` | 101 | 0 |
| `SCHOOL_ADMIN` | 94 | 7 (Manajemen Tenant) |
| `KEPALA_SEKOLAH` | 46 | 55 |
| `GURU` | 27 | 74 |
| `WALI_KELAS` | 29 | 72 |
| `BENDAHARA` | 34 | 67 |
| `SISWA` | 22 | 79 |
| `ORANG_TUA` | 22 | 79 |
| Publik (tanpa login) | 7 | 94 |

> Angka di atas adalah **turunan** dari pemetaan Auth Level dan matriks izin, bukan pernyataan langsung blueprint.

---

## 9. Security

Seluruh ketentuan pada bagian ini bersumber dari **Blueprint §3.4 Arsitektur Keamanan** dan NFR pada §1.4.

### 9.1 Authentication

| Aspek | Implementasi | Detail |
|---|---|---|
| Mekanisme | **Laravel Sanctum (SPA mode)** | Cookie-based session token untuk web app; Bearer token untuk API mobile *future* |
| Header | `Authorization: Bearer {token}` | Blueprint §4.1 |
| Hash password | **Argon2id** (Laravel default) | Minimum 8 karakter |
| Ganti password pertama | **Wajib** saat login pertama | NFR-07; CON-27 |
| Masa berlaku token | **8 jam tidak aktif** | AUTH-01 AC-4; CON-29 |
| Link reset password | Berlaku **60 menit** | AUTH-04 AC-2; CON-30 |
| Invalidasi setelah reset | **Seluruh sesi aktif** di-invalidate | AUTH-04 AC-3 |
| Pesan error login | **Tidak boleh mengungkap detail sistem** | AUTH-01 AC-2; CON-34 |

### 9.2 Authorization

| Aspek | Implementasi | Detail |
|---|---|---|
| Mekanisme | `spatie/laravel-permission` + **Gate** | Blueprint §3.4 |
| Policy per model | `StudentPolicy`, `GradePolicy`, dll. | Blueprint §3.4 |
| Auth Level endpoint | Public / Auth / Admin / Super | Blueprint §4.1 |
| Matriks izin | 15 modul × 8 role | Blueprint §1.1.2 |
| Pembatasan per-record | Guru→kelas ajar · Ortu→anaknya · Siswa→dirinya · Wali Kelas→rapor kelasnya | Blueprint §4.5, §4.8, §4.11 |

### 9.3 RBAC

| Aspek | Ketentuan | Dasar |
|---|---|---|
| Paket | `spatie/laravel-permission` | Blueprint §3.1 |
| Jumlah role | **8 role** | Blueprint §1.1.1 |
| Level role | Platform (`SUPER_ADMIN`) dan Sekolah (7 lainnya) | Blueprint §1.1 |
| Peran per pengguna | **Tepat satu peran utama** | Blueprint §1.1; ASM-08 |
| Penyimpanan | Tabel `roles` dan `model_has_roles` | Blueprint §2.1 |
| Tabel `permissions` | **Belum dijelaskan dalam blueprint.** | `04-ERD.md` §3.1 |
| Pemisahan GURU vs WALI_KELAS | **Belum dijelaskan dalam blueprint** — matriks menggabungkan keduanya | PRD §8.5 |

### 9.4 Session

| Aspek | Ketentuan | Dasar |
|---|---|---|
| Pembentukan | Token terbit saat login berhasil | AUTH-01 AC-3 |
| Isolasi tenant | `school_id` tersimpan di context sesi oleh TenantMiddleware | Blueprint §3.2.2 |
| Masa berlaku | **8 jam tidak aktif** | AUTH-01 AC-4 |
| Perpanjangan | `POST /auth/refresh` | Blueprint §4.2 |
| Pengakhiran manual | `POST /auth/logout` | Blueprint §4.2 |
| Pengakhiran paksa | Reset password → seluruh sesi berakhir | AUTH-04 AC-3 |
| Rate limiting | Login 5/menit · API 60/menit per user | Blueprint §3.4; CON-28 |
| Perilaku setelah sesi habis | **Belum dijelaskan dalam blueprint.** | — |

### 9.5 CSRF

| Aspek | Ketentuan | Dasar |
|---|---|---|
| Mekanisme | **Laravel CSRF Middleware** | Blueprint §3.4 |
| Cakupan | Token CSRF **wajib** untuk semua request `POST`/`PUT`/`DELETE` dari form web | Blueprint §3.4 |
| Cakupan untuk `PATCH` | **Belum dijelaskan dalam blueprint** — blueprint hanya menyebut POST/PUT/DELETE, padahal terdapat 9 endpoint `PATCH` | — |
| Cakupan untuk request API dengan Bearer token | **Belum dijelaskan dalam blueprint.** | — |

### 9.6 Audit

| Aspek | Ketentuan | Dasar |
|---|---|---|
| Mekanisme | Custom Middleware + Event | Blueprint §3.4 |
| Cakupan | Semua aksi **Create/Update/Delete** | Blueprint §3.4; NFR-12 |
| Isi catatan | `user` · `action` · `table` · `id` · `timestamp` · `IP` | Blueprint §3.4 |
| Tabel | `audit_logs` | NFR-12 |
| **Struktur tabel** | **Belum dijelaskan dalam blueprint** — tidak termasuk 21 entitas ERD | PRD §17.3 #2 |
| Masa retensi | **Belum dijelaskan dalam blueprint.** | — |
| Siapa berhak membaca | **Belum dijelaskan dalam blueprint** — tidak ada endpoint audit log | — |
| Pencatatan upaya akses ditolak | **Belum dijelaskan dalam blueprint.** | — |

### 9.7 Lapisan Keamanan Lain

| Aspek | Implementasi | Dasar |
|---|---|---|
| HTTPS | Let's Encrypt (TLS 1.2/1.3); redirect HTTP→HTTPS; **HSTS aktif** | Blueprint §3.4 |
| CORS | Hanya menerima dari `apps.smartsukses.sch.id` | Lampiran A.3 #9 |
| Input validation | Laravel Form Request; sanitasi XSS via `htmlspecialchars` | Blueprint §3.4 |
| SQL Injection | Eloquent parameterized query; raw SQL dilarang kecuali `DB::select()` dengan binding | Blueprint §3.4; CON-10 |
| File upload | Validasi MIME + ukuran; disimpan di `storage/` di luar web root | Blueprint §3.4; CON-31 |
| Isolasi tenant | Eloquent Global Scope; **diverifikasi via unit test 100%** | Blueprint §3.4; NFR-06 |
| Proteksi DDoS | Cloudflare Free | Blueprint §3.1 |
| Database | MySQL hanya akses localhost — tidak diekspos publik | Blueprint §3.3.1; CON-22 |

### 9.8 Verifikasi Keamanan Sebelum Go-Live

| # | Butir Checklist | Dasar |
|:---:|---|---|
| 1 | Unit test Global Scope (tenant isolation) lulus **100%** | Lampiran A.3 #1 |
| 2 | Uji akses lintas-tenant: user Madani tidak dapat melihat data Cinangka | Lampiran A.3 #2 |
| 4 | Uji wa.me link: seluruh template teks ter-encode dengan benar | Lampiran A.3 #4 |
| 6 | SSL aktif dan redirect HTTP→HTTPS berjalan | Lampiran A.3 #6 |
| 8 | Seluruh password default diubah (MySQL root, admin panel) | Lampiran A.3 #8 |
| 9 | CORS hanya menerima dari `apps.smartsukses.sch.id` | Lampiran A.3 #9 |
| 10 | Monitoring uptime diaktifkan | Lampiran A.3 #10 |

### 9.9 Aspek Keamanan yang Belum Dijelaskan

| Aspek | Status |
|---|---|
| Struktur dan retensi `audit_logs` | **Belum dijelaskan dalam blueprint.** |
| Two-factor authentication | **Belum dijelaskan dalam blueprint.** |
| Kebijakan kedaluwarsa password berkala | **Belum dijelaskan dalam blueprint.** |
| Penguncian akun setelah percobaan gagal berulang | **Belum dijelaskan dalam blueprint.** |
| CAPTCHA pada login atau form publik PPDB | **Belum dijelaskan dalam blueprint.** |
| API key untuk integrasi pihak ketiga | **Belum dijelaskan dalam blueprint.** |
| Enkripsi kolom sensitif pada database | **Belum dijelaskan dalam blueprint.** |
| Prosedur incident response | **Belum dijelaskan dalam blueprint.** |
| Header keamanan selain HSTS (CSP, X-Frame-Options) | **Belum dijelaskan dalam blueprint.** |
| Impersonate oleh Super Admin | **Belum dijelaskan dalam blueprint.** |

---

## 10. Future API

Bagian ini **hanya memuat hal yang disebutkan blueprint**.

### 10.1 Modul Phase 2 dan Kebutuhan Endpoint-nya

Blueprint mendaftarkan **11 modul Phase 2**, namun **tidak mendefinisikan satu pun endpoint** untuk modul-modul tersebut:

| Modul Phase 2 | Estimasi | Endpoint yang akan dibutuhkan | Status Definisi |
|---|:---:|---|:---:|
| LMS — Ruang Kelas Virtual | 4–6 minggu | Sesi meeting, link Google Meet per kelas | **Belum dijelaskan dalam blueprint.** |
| LMS — CBT (Ujian Online) | 6–8 minggu | Bank soal, paket ujian, submit jawaban, hasil | **Belum dijelaskan dalam blueprint.** |
| LMS — Bank Materi | 3–4 minggu | Upload/unduh materi per mata pelajaran | **Belum dijelaskan dalam blueprint.** |
| Presensi Digital | 4–5 minggu | Absensi GPS/selfie, rekap bulanan, notifikasi alpa | **Belum dijelaskan dalam blueprint.** |
| Konseling & BK | 3–4 minggu | Catat pelanggaran/prestasi, skor poin, rekam jejak | **Belum dijelaskan dalam blueprint.** |
| E-Library | 4–5 minggu | Katalog buku, peminjaman, pengembalian | **Belum dijelaskan dalam blueprint.** |
| Manajemen Inventaris | 3–4 minggu | Aset sekolah, kondisi, lokasi | **Belum dijelaskan dalam blueprint.** |
| Payroll Guru & Staf | 5–6 minggu | Komponen gaji, potongan, slip gaji PDF | **Belum dijelaskan dalam blueprint.** |
| Payment Gateway | 4–6 minggu | Inisiasi pembayaran, callback, status transaksi | **Belum dijelaskan dalam blueprint.** |
| WhatsApp API | 2–3 minggu | Antrean pesan, status pengiriman | **Belum dijelaskan dalam blueprint.** |
| DAPODIK Export | 3–4 minggu | Export data siswa format Kemdikbud | **Belum dijelaskan dalam blueprint.** |

**Prasyarat memulai Phase 2:** Phase 1 stabil dan digunakan **minimal 3 bulan** (CON-54; SM-13).

### 10.2 Endpoint Phase 1 yang Sudah Menyiapkan Jalan bagi Phase 2

Meskipun tidak ada endpoint baru yang didefinisikan, beberapa bagian API Phase 1 **sudah menyiapkan jalan**:

| Elemen | Endpoint / Kolom | Menyiapkan untuk |
|---|---|---|
| Nilai ENUM `PAYMENT_GATEWAY` | `POST /payments` — `payment_method` | Integrasi Midtrans/Xendit — nilai ENUM sudah tersedia |
| Kolom rekap kehadiran | `report_cards.attend_*` | Presensi Digital — kolom sudah ada, sumber data belum |
| Template WA per event | `schools.wa_template_*`; `GET /notifications/{id}/wa-links` | WhatsApp API — template dapat dipakai ulang saat pengiriman menjadi otomatis |
| Data siswa lengkap | `GET /students/export` | DAPODIK Export — sumber data sudah lengkap |
| Bearer token | Seluruh endpoint Auth | Dukungan **API mobile** — Blueprint §3.4 menyebut *"Bearer token untuk API mobile future"* |
| Versioning `/api/v1` | Base URL | Penambahan versi API berikutnya |

### 10.3 Dukungan API Mobile

| Aspek | Ketentuan | Dasar |
|---|---|---|
| Yang dinyatakan blueprint | *"Cookie-based session token untuk web app; **Bearer token untuk API mobile future**"* | Blueprint §3.4 |
| Perubahan skema yang diperlukan | Tidak ada — mekanisme token sudah disiapkan | Blueprint §3.4 |
| Endpoint khusus mobile | **Belum dijelaskan dalam blueprint.** | — |
| Aplikasi mobile native | **Belum dijelaskan dalam blueprint.** | PRD §14.3 |

### 10.4 Perubahan Alur API akibat Fitur Phase 2

| Fitur Phase 2 | Endpoint Phase 1 yang terdampak | Perubahan |
|---|---|---|
| **Payment Gateway** | `POST /payments` | Orang tua dapat membayar langsung; pencatatan manual oleh Bendahara tidak lagi menjadi satu-satunya jalur |
| **WhatsApp API** | `GET /notifications/{id}/wa-links`, `GET /admin/ppdb/{id}/wa-link` | Pengiriman menjadi otomatis; endpoint generate link manual tidak lagi menjadi satu-satunya jalur |
| **Presensi Digital** | `GET /students`, `GET /parent/children/{id}/summary`, `POST /report-cards/generate` | Menyediakan sumber data kehadiran yang saat ini menjadi titik ⚠ |
| **DAPODIK Export** | `GET /students/export` | Menambah format export baru |

### 10.5 Endpoint yang Dibutuhkan Phase 1 tetapi Belum Didefinisikan

Berbeda dari Phase 2, kekosongan berikut **memengaruhi Phase 1**:

| Kebutuhan | Status | Memblokir |
|---|---|---|
| Endpoint manajemen `class_subjects` (penetapan guru pengampu) | **Belum dijelaskan dalam blueprint.** | Jadwal dan penilaian (Sprint 2 & 4) |
| Endpoint `PUT`/`DELETE` untuk `classes`, `subjects`, `schedules`, `academic-years` | **Belum dijelaskan dalam blueprint.** | Koreksi data master (Sprint 2) |
| Endpoint unpublish/koreksi rapor | **Belum dijelaskan dalam blueprint.** | Koreksi rapor (Sprint 4) |
| Endpoint pembatalan/koreksi pembayaran | **Belum dijelaskan dalam blueprint.** | Koreksi keuangan (Sprint 5) |
| Endpoint CRUD role/permission | **Belum dijelaskan dalam blueprint.** | Manajemen akses (Sprint 1) |
| Endpoint pembacaan audit log | **Belum dijelaskan dalam blueprint.** | Kepatuhan audit (Sprint 1) |
| Endpoint approval Kepala Sekolah | **Belum dijelaskan dalam blueprint.** | Deskripsi role menyebut approval (PRD §17.3 #6) |

> Seluruh butir di atas wajib diklarifikasi ke pemilik blueprint sebelum sprint terkait dimulai — lihat Roadmap §14.3.

---

## 11. Lampiran

### 11.1 Ringkasan Statistik API

| Metrik | Nilai |
|---|:---:|
| Total endpoint | **101** |
| Kelompok endpoint | 11 |
| Endpoint Public | 7 |
| Endpoint Auth | 46 |
| Endpoint Admin | 41 |
| Endpoint Super | 7 |
| Method `GET` | 55 |
| Method `POST` | 30 |
| Method `PATCH` | 9 |
| Method `PUT` | 5 |
| Method `DELETE` | 3 |
| Endpoint dengan body yang dijelaskan blueprint | **4** |
| Endpoint menghasilkan berkas non-JSON | 4 |
| Aturan validasi bersumber acceptance criteria | 40+ |

### 11.2 Endpoint dengan Isi Body yang Dijelaskan Blueprint

Hanya empat endpoint yang isi body-nya disebut eksplisit oleh blueprint:

| Endpoint | Body menurut blueprint | Bagian |
|---|---|---|
| `POST /users` | `name`, `email`, `phone`, `role` | §4.4 |
| `POST /grades/bulk` | array of `{student_id, score}` | §4.8 |
| `POST /student-fees/generate-bulk` | `fee_type_id`, `period`, `due_date` | §4.9.1 |
| `POST /payments` | `student_fee_id`, `amount`, `method`, `date`, `reference` | §4.9.1 |

Untuk **97 endpoint lainnya**, skema request: **Belum dijelaskan dalam blueprint.**

### 11.3 Referensi Silang: Endpoint ↔ FR ↔ Sprint

| Kelompok Endpoint | FR | Fase | Sprint |
|---|---|:---:|:---:|
| Autentikasi | AUTH-01…04 | Phase 1 | 1 |
| School / Tenant | — (Manajemen Tenant) | Phase 0–1 | 1 |
| Users | PORTAL-04 | Phase 1 | 1 |
| Students | SIS-01…05 | Phase 2 | 2 |
| Academic Year, Classes, Subjects, Schedules | KELAS-01…04 | Phase 2 | 2 |
| PPDB | PPDB-01…05 | Phase 3 | 3 |
| Academic | NILAI-01…05 | Phase 4 | 4 |
| Finance — Tagihan & Pembayaran | SPP-01…05 | Phase 5 | 5 |
| Finance — Akuntansi & Kas | KAS-01…03 | Phase 5, 8 | 6 |
| Portal | PORTAL-01…03 | Phase 6 | 7 |
| Notification | NOTIF-01…04 | Phase 7 | 8 |
| Bilingual (`locale`) | AUTH-05 | Phase 9 | 9 |

### 11.4 Daftar Titik ⚠ dalam Spesifikasi API

| # | Titik | Section | Isu PRD §17.3 |
|:---:|---|---|:---:|
| 1 | Skema request/response 97 dari 101 endpoint | §1.4, §11.2 | — |
| 2 | Kode HTTP status untuk seluruh skenario error | §7 | — |
| 3 | Struktur objek `errors` pada response gagal | §6.2 | — |
| 4 | Mekanisme autentikasi definitif (JWT vs Sanctum) | §2.2 | #10 |
| 5 | Kebijakan versioning dan deprecation | §2.7 | — |
| 6 | Nilai `per_page` default dan maksimum | §2.11, §6.3 | — |
| 7 | Parameter sorting dan pencarian | §2.11 | — |
| 8 | Tidak ada endpoint CRUD `roles`/`permissions` | §4.3 | — |
| 9 | Tidak ada endpoint manajemen `teachers` | §4.5 | — |
| 10 | Tidak ada endpoint manajemen `parents` | §4.6 | — |
| 11 | Tidak ada endpoint `class_subjects` | §4.7 | — |
| 12 | Tidak ada `PUT`/`DELETE` untuk sebagian entitas master | §4.14 | — |
| 13 | Tidak ada endpoint unpublish rapor | §4.9 | #8 |
| 14 | Tidak ada endpoint koreksi pembayaran | §4.10 | — |
| 15 | Tidak ada endpoint pembacaan audit log | §9.6 | #2 |
| 16 | Tidak ada endpoint approval Kepala Sekolah | §10.5 | #6 |
| 17 | Konflik kewenangan guru membuat pengumuman | §4.12, §8.2 | #3 |
| 18 | Konflik jumlah nilai dashboard ortu (3 vs 5) | §4.13 | #4 |
| 19 | Kategori `GENERAL` tidak ada dalam ENUM notifikasi | §4.12 | — |
| 20 | Sumber data kehadiran untuk endpoint portal dan rapor | §4.9, §4.13 | #1 |
| 21 | Presedensi bobot `grades.weight` vs `grade_configs` | §4.9 | #7 |
| 22 | Perilaku generate tagihan `YEARLY` / `ONCE` | §4.10 | #9 |
| 23 | Normalisasi nomor HP ke awalan `62` | §4.12, §5.8 | #14 |
| 24 | Batas ukuran dan format dokumen PPDB, avatar, bukti kas | §5.9 | — |
| 25 | Format WEBP vs JPG/PNG/PDF pada foto siswa | §5.9 | #12 |
| 26 | Struktur kolom template import Excel | §5.10 | — |
| 27 | Bunyi pesan validasi dalam ID dan EN | §5.1, §5.10 | — |
| 28 | Cakupan CSRF untuk method `PATCH` | §9.5 | — |
| 29 | Soft delete `transactions` tanpa kolom `deleted_at` | §4.11 | — |
| 30 | Perilaku sesi habis dan akun nonaktif | §7.3 | — |

### 11.5 Referensi Dokumen

| Dokumen | Peran |
|---|---|
| `blueprint/SmartSukses_FullBlueprint_v1.0.0.docx` | Sumber kebenaran tunggal — Bagian 4 memuat peta 101 endpoint |
| `docs/01-Analisis-Blueprint.md` | Analisis awal blueprint |
| `docs/01-PRD.md` (v1.1) | 38 FR · 12 NFR · 21 ASM · 56 CON · matriks izin · 14 isu terbuka |
| `docs/02-ROADMAP.md` (v1.1) | 11 fase · 9 sprint · 92 deliverable · 32 risiko |
| `docs/03-USER_FLOW.md` (v1.0) | Alur 8 role · 6 lapis validasi · 25 titik ⚠ |
| `docs/04-ERD.md` (v1.0) | 21 entitas · 56 relasi · 21 titik ⚠ |
| `docs/05-DATABASE.md` (v1.0) | 219 kolom · tipe data · constraint · 34 titik ⚠ |
| `docs/06-API.md` | Dokumen ini — 101 endpoint · 40+ aturan validasi · 30 titik ⚠ |

---

## Riwayat Revisi Dokumen

| Versi | Tanggal | Penulis | Keterangan |
|---|---|---|---|
| v1.0 | — | Tim Pengembang | API Specification awal, diturunkan dari `SmartSukses_FullBlueprint_v1.0.0.docx` Bagian 3 & 4 beserta seluruh dokumen turunan sebelumnya |

---

*Dokumen ini disusun sepenuhnya berdasarkan `blueprint/SmartSukses_FullBlueprint_v1.0.0.docx` dan dokumen turunannya. Tidak ada endpoint, parameter, aturan validasi, maupun requirement yang ditambahkan, dikurangi, atau diubah. Seluruh informasi yang tidak tercantum dalam blueprint ditandai secara eksplisit sebagai "Belum dijelaskan dalam blueprint."*

**Smart Sukses School · API Specification v1.0 · KONFIDENSIAL**
