<?php

namespace App\Support\Migration;

/**
 * M3 — NISN diperlakukan sebagai **string identitas**, bukan bilangan.
 *
 * Berkas sekolah menyimpan NISN di sel bertipe angka, sehingga Excel membuang
 * angka nol di depannya: NISN "0088888888" tersimpan sebagai 88888888. Itu
 * bukan kesalahan tata usaha, melainkan akibat wajar dari menaruh identitas di
 * kolom numerik. Meminta tata usaha mengirim ulang seluruh berkas hanya
 * memindahkan pekerjaan dan membuka kesempatan salah ketik baru pada data
 * identitas; keputusan pemilik: normalisasi dilakukan di sisi migrasi
 * (butir 483).
 *
 * Kontraknya sengaja sempit dan deterministik:
 *
 * - kosong / "-" / null      -> NULL, keadaan BLANK
 * - 10 digit                 -> disalin apa adanya, keadaan VALID
 * - 1..9 digit               -> diberi nol di depan hingga 10, keadaan NORMALIZED
 * - bukan digit              -> ditolak nilainya, keadaan INVALID
 * - lebih dari 10 digit      -> ditolak nilainya, keadaan INVALID
 *
 * Yang **tidak** dilakukan: memotong. NISN 11 digit bukan NISN 10 digit yang
 * kelebihan satu karakter — ia data yang salah, dan memotongnya menghasilkan
 * identitas yang tampak sah padahal milik orang lain. Kelebihan panjang selalu
 * dikembalikan untuk diperiksa manusia.
 */
final class NisnNormalizer
{
    public const VALID = 'NISN_VALID';

    public const NORMALIZED = 'NISN_NORMALIZED';

    public const BLANK = 'NISN_BLANK';

    public const INVALID = 'NISN_INVALID';

    public const LENGTH = 10;

    /**
     * Penanda "tidak ada isinya" yang lazim ditulis manusia di sel Excel.
     *
     * @var array<int, string>
     */
    protected const BLANK_TOKENS = ['', '-', '--', '–', '—', 'n/a', 'na', 'null', 'kosong', 'belum ada'];

    /**
     * @return array{value: ?string, state: string}
     */
    public static function normalise(mixed $raw): array
    {
        $text = self::plainText($raw);

        if (in_array(mb_strtolower($text), self::BLANK_TOKENS, true)) {
            return ['value' => null, 'state' => self::BLANK];
        }

        if (! ctype_digit($text)) {
            return ['value' => null, 'state' => self::INVALID];
        }

        if (mb_strlen($text) > self::LENGTH) {
            return ['value' => null, 'state' => self::INVALID];
        }

        if (mb_strlen($text) === self::LENGTH) {
            return ['value' => $text, 'state' => self::VALID];
        }

        return [
            'value' => str_pad($text, self::LENGTH, '0', STR_PAD_LEFT),
            'state' => self::NORMALIZED,
        ];
    }

    /**
     * Keadaan yang berarti nilainya boleh ditulis ke basis data.
     */
    public static function isWritable(string $state): bool
    {
        return $state === self::VALID || $state === self::NORMALIZED || $state === self::BLANK;
    }

    /**
     * @return array<string, int>
     */
    public static function emptyTally(): array
    {
        return [self::VALID => 0, self::NORMALIZED => 0, self::BLANK => 0, self::INVALID => 0];
    }

    /**
     * Sel angka yang panjang dikembalikan PhpSpreadsheet sebagai float, dan
     * `(string)` atas float besar bisa menghasilkan notasi ilmiah ("1.0135E+9").
     * Itu bukan NISN yang tidak valid, melainkan cara PHP mencetaknya — jadi
     * bentuknya dikembalikan lebih dulu sebelum dinilai.
     */
    protected static function plainText(mixed $raw): string
    {
        if ($raw === null) {
            return '';
        }

        if (is_float($raw) && $raw === floor($raw) && is_finite($raw)) {
            return sprintf('%.0F', $raw);
        }

        $text = trim((string) $raw);

        if (preg_match('/^\d+(?:\.\d+)?[eE]\+?\d+$/', $text) === 1) {
            return sprintf('%.0F', (float) $text);
        }

        return $text;
    }
}
