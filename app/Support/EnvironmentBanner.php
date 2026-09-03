<?php

namespace App\Support;

/**
 * Lingkungan mana yang menandai dirinya sendiri di layar.
 *
 * Staging memakai nama host sungguhan, data yang mirip sungguhan, dan tampilan
 * yang identik dengan produksi. Satu-satunya pembeda di mata staf adalah alamat
 * di bilah URL, dan alamat adalah hal pertama yang berhenti dibaca orang
 * setelah hari kedua (butir 510).
 *
 * Produksi **tidak** ada di daftar ini, dan itu inti keputusannya: yang ada di
 * produksi tidak boleh punya elemen penanda dalam bentuk apa pun — bukan
 * disembunyikan lewat CSS, melainkan tidak dirender sama sekali. Lokal juga
 * tidak, karena `localhost` sudah menjelaskan dirinya.
 */
final class EnvironmentBanner
{
    /**
     * Lingkungan yang menampilkan penanda.
     *
     * @var array<int, string>
     */
    public const LABELLED = ['staging', 'uat', 'demo'];

    public static function shouldRender(): bool
    {
        return app()->environment(self::LABELLED);
    }

    /**
     * Teks penanda. Nama lingkungannya sendiri, bukan kalimat — pendek supaya
     * muat di layar 360px, dan cukup untuk membedakan staging dari produksi.
     */
    public static function label(): string
    {
        return match (app()->environment()) {
            'staging' => 'STAGING / UAT',
            'uat' => 'UAT',
            'demo' => 'DEMO',
            default => mb_strtoupper(app()->environment()),
        };
    }
}
