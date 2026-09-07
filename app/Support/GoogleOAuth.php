<?php

namespace App\Support;

/**
 * Apakah "Masuk dengan Google" tersedia di lingkungan ini.
 *
 * Satu tempat untuk satu pertanyaan, dibaca rute maupun halaman masuk. Kalau
 * masing-masing memeriksa sendiri, akan ada saat tombolnya tampil sementara
 * rutenya menjawab 404 — atau, yang lebih buruk, sebaliknya.
 *
 * Dibaca lewat `config()`, bukan `env()`: setelah `config:cache`, `env()` di
 * luar berkas konfigurasi mengembalikan NULL (butir 357).
 */
class GoogleOAuth
{
    public static function isEnabled(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }
}
