<?php

namespace App\Support;

use App\Models\School;
use App\Models\Student;
use App\Models\User;

/**
 * NOTIF-03 poin 2 — template teks WA yang dapat diedit Admin Sekolah, untuk dua
 * kejadian yang berpusat pada siswa: tagihan baru (`schools.wa_template_spp`)
 * dan rapor terbit (`schools.wa_template_rapor`).
 *
 * Sintaksnya **tidak** baru. Ia persis sintaks yang sudah mapan sejak Sprint 3
 * di PpdbWaTemplate: token `[…]` di dalam teks biasa, diganti `str_replace`.
 * Tidak ada Blade, tidak ada eval, tidak ada HTML, dan tidak ada kode pengguna
 * yang dijalankan — template adalah teks, dan tetap teks (butir 237).
 *
 * Kosakata tokennya sengaja **sempit**. Blueprint menyebut kolom templatenya
 * dapat diedit, tetapi tidak pernah mendefinisikan daftar token untuk SPP
 * maupun rapor. Yang dipakai di sini hanya tiga token yang maknanya sudah
 * ditetapkan PPDB dan berpindah ke sini tanpa ditafsirkan ulang:
 *
 *  - `[nama]`    — nama siswa (di PPDB: nama calon siswa);
 *  - `[sekolah]` — nama cabang;
 *  - `[ortu]`    — nama orang tua/wali.
 *
 * Token untuk nominal, jatuh tempo, periode, atau semester **tidak** disediakan.
 * Tidak satu pun sumber mendefinisikannya, dan mengarangnya berarti membuat
 * kosakata template baru hanya demi kenyamanan. Ada alasan kedua yang kebetulan
 * searah: teks WA dikirim manual ke nomor yang bisa saja salah, dan nominal
 * tagihan bukan hal yang sebaiknya ikut terkirim ke sana (butir 238).
 */
class StudentWaTemplate
{
    /**
     * @return array<int, string>
     */
    public static function placeholders(): array
    {
        return ['[nama]', '[sekolah]', '[ortu]'];
    }

    public static function fill(string $template, Student $student, ?School $school, ?User $parent): string
    {
        return str_replace(
            static::placeholders(),
            [
                (string) $student->full_name,
                (string) ($school?->name ?? ''),
                (string) ($parent?->name ?: 'Bapak/Ibu'),
            ],
            $template,
        );
    }

    /**
     * Template cabang bila diisi, teks bawaan bila tidak.
     *
     * Kolom templatenya nullable dan memang boleh kosong: kejadian bisnisnya
     * tidak boleh gagal hanya karena sekolah belum menulis template
     * (butir 239).
     */
    public static function render(?string $schoolTemplate, string $default, Student $student, ?School $school, ?User $parent): string
    {
        $template = is_string($schoolTemplate) && trim($schoolTemplate) !== ''
            ? $schoolTemplate
            : $default;

        return static::fill($template, $student, $school, $parent);
    }
}
