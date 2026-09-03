<?php

namespace App\Support\Migration;

/**
 * M4 — daftar rombel resmi tahun ajaran 2026/2027, dan koreksi label sumber.
 *
 * Satu tempat untuk dua hal yang selalu berubah bersama: rombel mana yang
 * **ada**, dan label sumber mana yang menunjuk ke rombel itu. Sebelumnya
 * aliasnya tinggal di StudentImportPlan dan daftar rombelnya tidak tertulis di
 * mana pun kecuali di kepala orang — sehingga perintah penyiapan dan pembaca
 * berkas dapat menyimpang tanpa ada yang menyadarinya (butir 514).
 *
 * Keputusan yang dikonfirmasi pengembang/pemilik: keempat nama berikut yang
 * dipakai Smart Sukses School untuk 2026/2027. Nama-nama ini berasal dari kolom
 * "Kelas di SMAN 11" pada berkas sumber, dan dipakai apa adanya sebagai nama
 * rombel di sistem — itu keputusan sadar, bukan kebetulan.
 *
 * Daftar ini **tidak** memberi izin membuat rombel dengan sendirinya. Importer
 * siswa tetap tidak pernah membuat kelas; yang membuatnya hanya perintah
 * penyiapan yang dijalankan dengan sengaja (butir 490).
 */
final class CanonicalRombel
{
    /**
     * Nama rombel -> tingkat. Urutannya urutan tampil di laporan.
     *
     * @var array<string, int>
     */
    public const CLASSES = [
        'X Terbuka - 2' => 10,
        'XI Terbuka - 1' => 11,
        'XII Terbuka - 1' => 12,
        'XII Terbuka - 2' => 12,
    ];

    /**
     * Koreksi data terkonfirmasi atas label sumber.
     *
     * `XII Terbuka - I` di berkas sumber adalah salah ketik; nilai yang
     * dimaksud `XII Terbuka - 1`. Koreksinya ditulis satu per satu di sini,
     * sebagai alias yang bisa dibaca dan dibantah, bukan sebagai aturan umum
     * yang mengubah angka Romawi di mana pun ia muncul. Aturan umum semacam itu
     * akan mengubah label yang belum pernah ditinjau siapa pun — termasuk label
     * di berkas yang belum ada (butir 506).
     *
     * Kuncinya huruf besar seluruhnya; pencocokannya label penuh yang persis,
     * bukan pola.
     *
     * @var array<string, string>
     */
    public const ALIASES = [
        'XII TERBUKA - I' => 'XII Terbuka - 1',
    ];

    /**
     * Label sumber setelah koreksi data terkonfirmasi. Label yang tidak
     * terdaftar dikembalikan apa adanya.
     */
    public static function canonicalLabel(string $label): string
    {
        return self::ALIASES[mb_strtoupper(trim($label))] ?? $label;
    }

    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_keys(self::CLASSES);
    }

    public static function isCanonical(string $label): bool
    {
        return array_key_exists(self::canonicalLabel($label), self::CLASSES);
    }

    /**
     * Tingkat menurut daftar resmi, bukan menurut tebakan atas namanya.
     */
    public static function gradeFor(string $label): ?int
    {
        return self::CLASSES[self::canonicalLabel($label)] ?? null;
    }
}
