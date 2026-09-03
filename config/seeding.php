<?php

/*
|------------------------------------------------------------------------------
| Seeding
|------------------------------------------------------------------------------
|
| Kata sandi akun yang dibuat seeder, dan daftar lingkungan yang boleh memakai
| kata sandi bawaan repositori.
|
| Nilainya dibaca dari env **di dalam berkas config**, bukan langsung dari
| `env()` di kode aplikasi. `env()` di luar berkas config mengembalikan NULL
| begitu `config:cache` dijalankan — yaitu tepat di staging dan produksi
| (butir 357).
|
| Akibatnya nyata dan arahnya berbahaya: pagar kata sandi akan menyangka
| SEED_ADMIN_PASSWORD kosong padahal operator sudah mengisinya, lalu menolak
| seeding dengan pesan yang menyesatkan. Yang lebih buruk, `app:production-check`
| akan melaporkan kata sandi seeder "belum disetel" pada server yang sudah
| benar konfigurasinya — persis pemeriksaan yang seharusnya menumbuhkan
| kepercayaan (butir 511).
|
| Berkas ini tidak pernah memuat kata sandi. Ia hanya menyebut dari mana kata
| sandinya dibaca.
|
*/

return [

    /*
    | Kata sandi seluruh akun hasil seeding.
    |
    | Kosong berarti belum disetel. Di `local` dan `testing` itu wajar dan
    | ditutup nilai cadangan; di lingkungan lain mana pun seeding berhenti.
    | Lihat App\Support\SeedPassword.
    */
    'admin_password' => env('SEED_ADMIN_PASSWORD'),

    /*
    | Lingkungan yang boleh memakai kata sandi bawaan repositori: yang berjalan
    | di mesin pengembang dan di test runner.
    |
    | Sengaja bukan daftar "yang dilarang". Lingkungan baru (uat, demo, sandbox)
    | otomatis ikut terpagari tanpa perlu diingat menambahkannya — arah gagal
    | yang benar untuk sebuah kredensial (butir 509).
    */
    'password_optional_environments' => ['local', 'testing'],

];
