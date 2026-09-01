<?php

namespace App\Support\Migration;

use App\Enums\RoleName;

/**
 * M1 — pemilah isi kolom "Pelajaran" pada lembar Data Guru.
 *
 * Kolom itu satu sel teks bebas yang di dunia nyata memuat tiga hal berbeda
 * sekaligus, dipisah koma:
 *
 *     "Kepala Sekolah, PAI Akhlakul Karimah"   -> jabatan + mata pelajaran
 *     "BK, PJOK, Mentoring, Pramuka"           -> layanan + mapel + 2 ekskul
 *
 * Menyalin sel itu bulat-bulat ke `subjects.name` akan melahirkan mata pelajaran
 * bernama "Kepala Sekolah" — jabatan yang menjelma jadi mapel, lalu muncul di
 * rapor. Karena itu setiap token diklasifikasikan lebih dulu, dan **apa pun yang
 * tidak dikenali menjadi AMBIGUOUS, bukan mata pelajaran** (butir 452).
 *
 * Daftar di bawah hanya memuat token yang benar-benar ada pada berkas sekolah.
 * Menebak-nebak daftar mapel SMA yang "biasanya ada" akan mengarang penugasan
 * yang tidak pernah dinyatakan sekolah.
 */
class AssignmentClassifier
{
    public const SUBJECT = 'SUBJECT';

    public const ROLE = 'ROLE';

    /** Kegiatan nyata, tetapi bukan mata pelajaran ber-rapor. */
    public const NON_ACADEMIC = 'NON_ACADEMIC';

    public const AMBIGUOUS = 'AMBIGUOUS';

    /**
     * Jabatan. Nilai peta adalah peran sistem yang setara, bila ada.
     *
     * @var array<string, string>
     */
    protected const ROLES = [
        'kepala sekolah' => RoleName::KepalaSekolah->value,
        'wakil kepala sekolah' => RoleName::Guru->value,
        'bendahara' => RoleName::Bendahara->value,
        'wali kelas' => RoleName::WaliKelas->value,
        'admin sekolah' => RoleName::SchoolAdmin->value,
        'operator' => RoleName::SchoolAdmin->value,
    ];

    /**
     * Mata pelajaran — hanya yang tertulis pada berkas sumber.
     *
     * @var array<string, string>
     */
    protected const SUBJECTS = [
        'pai akhlakul karimah' => 'PAI Akhlakul Karimah',
        'al quran' => 'Al-Qur\'an',
        'praktek ibadah' => 'Praktek Ibadah',
        'english' => 'English',
        'pjok' => 'PJOK',
    ];

    /**
     * Kegiatan yang jelas bukan mata pelajaran. Dipisahkan dari AMBIGUOUS karena
     * kita tahu persis apa mereka — yang belum diputuskan sekolah hanyalah
     * apakah kegiatan ini perlu diwakili di sistem sama sekali.
     *
     * @var array<string, string>
     */
    protected const NON_ACADEMIC_TOKENS = [
        'bk' => 'Bimbingan Konseling',
        'bimbingan konseling' => 'Bimbingan Konseling',
        'mentoring' => 'Mentoring',
        'pramuka' => 'Pramuka',
    ];

    /**
     * @return array<int, array{token: string, kind: string, canonical: string, role: string|null}>
     */
    public function classify(string $cell): array
    {
        $out = [];

        foreach ($this->tokenise($cell) as $token) {
            $key = $this->key($token);

            if (isset(self::ROLES[$key])) {
                $out[] = [
                    'token' => $token,
                    'kind' => self::ROLE,
                    'canonical' => $token,
                    'role' => self::ROLES[$key],
                ];

                continue;
            }

            if (isset(self::SUBJECTS[$key])) {
                $out[] = [
                    'token' => $token,
                    'kind' => self::SUBJECT,
                    'canonical' => self::SUBJECTS[$key],
                    'role' => null,
                ];

                continue;
            }

            if (isset(self::NON_ACADEMIC_TOKENS[$key])) {
                $out[] = [
                    'token' => $token,
                    'kind' => self::NON_ACADEMIC,
                    'canonical' => self::NON_ACADEMIC_TOKENS[$key],
                    'role' => null,
                ];

                continue;
            }

            $out[] = [
                'token' => $token,
                'kind' => self::AMBIGUOUS,
                'canonical' => $token,
                'role' => null,
            ];
        }

        return $out;
    }

    /**
     * Peran seseorang ditentukan token jabatan bila ada, selain itu Guru.
     * Tidak pernah ditebak dari mata pelajaran yang diampu.
     *
     * @param  array<int, array<string, mixed>>  $classified
     */
    public function roleFor(array $classified): string
    {
        foreach ($classified as $item) {
            if ($item['kind'] === self::ROLE && $item['role'] !== null) {
                return (string) $item['role'];
            }
        }

        return RoleName::Guru->value;
    }

    /**
     * Kode mata pelajaran wajib dan unik per cabang, sementara berkas sekolah
     * tidak memuatnya. Kode diturunkan dari nama secara deterministik dan
     * dilaporkan sebagai **usulan** — sekolah yang mengesahkan, bukan importer
     * (butir 453). Kode bukan identitas orang, jadi menurunkannya tidak
     * melanggar larangan mengarang identitas.
     */
    public function proposeCode(string $subjectName): string
    {
        // Apostrof dibuang lebih dulu supaya "Al-Qur'an" terpecah jadi dua kata
        // ("Al", "Quran") dan bukan tiga.
        $words = preg_split('/[^\p{L}\p{N}]+/u', str_replace('\'', '', $subjectName), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) === 1) {
            return mb_strtoupper(mb_substr($words[0], 0, 4));
        }

        $code = '';

        foreach ($words as $word) {
            $code .= mb_strtoupper(mb_substr($word, 0, 1));
        }

        return mb_substr($code, 0, 20);
    }

    /**
     * @return array<int, string>
     */
    protected function tokenise(string $cell): array
    {
        $parts = preg_split('/\s*[,\/;]\s*/u', $cell, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $out = [];

        foreach ($parts as $part) {
            $part = trim(preg_replace('/\s+/u', ' ', $part) ?? '');

            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }

    protected function key(string $token): string
    {
        $key = mb_strtolower($token);
        // "Al-Qur'an", "Al Quran", "Al-Quran" adalah token yang sama.
        $key = str_replace(['\''], '', $key);
        $key = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $key) ?? '';

        return trim(preg_replace('/\s+/', ' ', $key) ?? '');
    }
}
