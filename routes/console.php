<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * NOTIF-04 poin 3 — riwayat notifikasi tersimpan 90 hari.
 *
 * Blueprint menyebut lamanya, bukan kapan pemangkasannya berjalan. Jamnya
 * karena itu **detail implementasi**, bukan kebutuhan bisnis. Yang dipilih
 * 03:10 dengan alasan yang dapat diperiksa: Security 3.4 menjadwalkan backup
 * harian pukul 02:00 WIB, sehingga memangkas sesudahnya berarti baris yang
 * dihapus sudah ikut terbawa backup malam itu — masih dapat dipulihkan bila
 * ternyata dibutuhkan (butir 259).
 *
 * `withoutOverlapping()` menjaga dua eksekusi tidak berjalan bersamaan pada
 * pemasangan yang pemangkasannya panjang. Perintahnya sendiri sudah idempoten,
 * tetapi dua proses yang menghapus himpunan yang sama hanya membuang usaha.
 *
 * Pemangkasannya tidak diantrekan. Antrean dipakai project ini untuk pekerjaan
 * yang dipicu request dan tidak boleh menahan penggunanya (Tech stack 3.1 —
 * generate tagihan massal, PDF rapor); perintah terjadwal sudah berjalan di
 * luar request, jadi menambahkan antrean hanya menambah satu titik gagal.
 */
Schedule::command('notifications:prune')
    ->dailyAt('03:10')
    ->withoutOverlapping();
