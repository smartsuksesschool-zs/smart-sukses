<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * NOTIF-02 poin 1 — "Sistem generate URL: wa.me/62[nomorHP]?text=[pesan_ter-encode]".
 *
 * Dipakai PPDB-04 (link notifikasi per pendaftar) dan modul notifikasi lain.
 *
 * Satu-satunya tempat aturan normalisasi nomor ditulis. PPDB dan notifikasi
 * memakai aturan yang sama persis: dua aturan yang perlahan berbeda akan
 * membuat nomor yang sama menghasilkan dua tautan berbeda tergantung modul mana
 * yang membuatnya (butir 222).
 */
class WhatsAppLink
{
    /**
     * Kode negara Indonesia sesuai format pada NOTIF-02.
     */
    public const COUNTRY_CODE = '62';

    /**
     * Link wa.me siap kirim, atau NULL bila nomor HP tidak tersedia/tidak valid.
     */
    public static function to(?string $phone, string $message): ?string
    {
        $number = static::normalizePhone($phone);

        if ($number === null) {
            return null;
        }

        return 'https://wa.me/'.$number.'?text='.rawurlencode($message);
    }

    /**
     * Menormalkan nomor HP Indonesia ke format internasional tanpa tanda plus:
     * 0812…, +62812…, 62812…, dan 812… semuanya menjadi 62812….
     *
     * Spasi, tanda hubung, dan tanda kurung dibuang; hasilnya selalu angka saja.
     * Nomor yang bukan nomor Indonesia mengembalikan NULL, bukan diberi awalan
     * 62 (butir 222).
     */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (Str::startsWith($digits, '0')) {
            $digits = static::COUNTRY_CODE.Str::substr($digits, 1);
        } elseif (! Str::startsWith($digits, static::COUNTRY_CODE)) {
            // Ditulis tanpa awalan apa pun — lazim di Indonesia, dan nomor
            // seluler Indonesia selalu dimulai dengan 8 setelah kode negara.
            // Angka lain di posisi ini adalah kode negara asing, dan menambahkan
            // 62 di depannya tidak menghasilkan nomor orang itu melainkan nomor
            // Indonesia milik orang lain — yang akan menerima pesan sekolah
            // tanpa pernah ada hubungannya (butir 222).
            if (! Str::startsWith($digits, '8')) {
                return null;
            }

            $digits = static::COUNTRY_CODE.$digits;
        }

        // Nomor Indonesia terpendek (62 + 9 digit) — di bawah itu pasti salah ketik.
        return Str::length($digits) >= 11 ? $digits : null;
    }
}
