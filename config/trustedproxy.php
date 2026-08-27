<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Proxy tepercaya
|--------------------------------------------------------------------------
|
| Arsitektur 3.3.1 — Internet → Cloudflare → Nginx → PHP-FPM. Tanpa pengaturan
| ini Laravel membaca alamat **proxy** sebagai alamat klien, sehingga kolom
| `audit_logs.ip_address` yang diwajibkan Arsitektur 3.4 berisi alamat Nginx
| pada setiap baris — dan salahnya tidak terlihat sampai ada yang membutuhkan
| jejak itu (butir 356).
|
| Nilainya dibaca dari env **di dalam berkas config**, bukan di
| `bootstrap/app.php` maupun di service provider. Dua alasan, keduanya nyata:
| callback middleware pada `bootstrap/app.php` dijalankan sebelum berkas .env
| dimuat, dan `env()` di luar berkas config mengembalikan NULL begitu
| `config:cache` dijalankan — yaitu tepat di produksi (butir 357).
|
| Bawaannya **tidak memercayai siapa pun**. Pemasangan lokal tanpa proxy karena
| itu berperilaku persis seperti sebelumnya, dan header `X-Forwarded-*` yang
| dikirim siapa pun diabaikan.
|
*/

$proxies = trim((string) env('TRUSTED_PROXIES', ''));

return [

    /*
    | Alamat proxy yang boleh dipercaya.
    |
    | NULL  — tidak memercayai siapa pun (bawaan, dan bawaan untuk lokal).
    | daftar— alamat/CIDR dipisah koma, misalnya "127.0.0.1".
    | "*"   — memercayai proxy mana pun. Hanya sah bila server origin tidak
    |         dapat dihubungi langsung dari internet; lihat
    |         docs/deployment-production.md.
    */
    'proxies' => $proxies === ''
        ? null
        : ($proxies === '*'
            ? '*'
            : array_values(array_filter(array_map('trim', explode(',', $proxies))))),

    /*
    | Header yang dipercaya dari proxy tersebut.
    |
    | Sengaja lebih sempit daripada bawaan Laravel, yang juga memercayai
    | `X-Forwarded-Host`, `X-Forwarded-Prefix`, dan header AWS ELB.
    |
    | `X-Forwarded-Host` yang dipercaya berarti proxy — atau siapa pun yang
    | dapat menjangkau origin — dapat menentukan host yang dipakai Laravel
    | membangun URL. Aplikasi ini mengirim tautan atur ulang kata sandi lewat
    | surel, dan tautan itu dibangun dari host tersebut. Nginx sudah meneruskan
    | `Host` yang benar, jadi tidak ada yang hilang dengan tidak memercayainya.
    | Header AWS ELB tidak relevan pada topologi ini (butir 358).
    */
    'headers' => Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO,

];
