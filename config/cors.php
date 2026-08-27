<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| Checklist go-live A.3: "CORS dikonfigurasi untuk hanya menerima dari domain
| apps.smartsukses.sch.id."
|
| Berkas ini ada supaya perilakunya **tidak** bergantung pada bawaan framework,
| yang mengizinkan seluruh origin (`*`). Tanpa berkas ini, satu pembaruan
| Laravel dapat menggeser kebijakan lintas-origin aplikasi tanpa satu baris pun
| berubah di repository (butir 359).
|
| CORS **bukan** otorisasi. Ia hanya menentukan apakah peramban mengizinkan
| sebuah halaman di origin lain membaca balasan. Yang menjaga data tetap
| policy, SchoolScope, dan token — CORS adalah lapisan tambahan, bukan
| penggantinya.
|
*/

$origins = env('CORS_ALLOWED_ORIGINS');

// `blank()`, bukan nilai bawaan `env()`: variabel yang ada tetapi dikosongkan
// menghasilkan string kosong, bukan NULL, sehingga nilai bawaan tidak pernah
// terpakai dan daftarnya diam-diam menjadi kosong.
if (blank($origins)) {
    $origins = env('APP_URL', 'http://localhost');
}

return [

    /*
    | Hanya API.
    |
    | `sanctum/csrf-cookie` sengaja **tidak** termasuk. Rutenya memang
    | terdaftar karena Sanctum mendaftarkannya sendiri, tetapi aplikasi ini
    | tidak memakai mode SPA berbasis cookie: API-nya memakai token Bearer
    | (`auth:sanctum` tanpa `statefulApi()`), dan panel serta portal memakai
    | sesi pada origin yang sama. Membuka jalur cookie CSRF lintas origin
    | berarti membuka sesuatu yang tidak dipakai siapa pun (butir 360).
    */
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    /*
    | Bawaannya APP_URL — sehingga produksi otomatis benar begitu APP_URL
    | disetel, dan pemasangan lokal otomatis benar tanpa dikonfigurasi.
    | Beberapa origin dipisah koma. Daftar kosong berarti tidak ada origin
    | lintas-domain yang diizinkan: gagal tertutup, bukan terbuka.
    */
    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) $origins)),
        fn (string $origin) => $origin !== '',
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    /*
    | Tidak ada cookie lintas origin. API memakai token Bearer, sehingga
    | kredensial lintas origin tidak dibutuhkan — dan menyalakannya akan
    | menjadikan daftar origin di atas satu-satunya yang berdiri antara sesi
    | pengguna dan halaman asing.
    */
    'supports_credentials' => false,

];
