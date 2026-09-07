<?php

namespace App\Services\Auth;

use App\Enums\ClaimMatchOutcome;
use App\Enums\RoleName;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use Illuminate\Database\Eloquent\Collection;

/**
 * Mencocokkan NIS + NISN dengan data induk siswa.
 *
 * **NIS dan NISN bukan rahasia autentikasi.** Keduanya tercetak di kartu
 * pelajar, beredar di grup wali murid, dan tertulis di lembar jawaban. Yang
 * dibuktikan pencocokan ini hanyalah bahwa pemohon menyebut siswa yang memang
 * ada — bukan bahwa ia siswa itu, dan sama sekali bukan bahwa ia orang tuanya.
 * Karena itu hasilnya tidak pernah langsung menjadi akun: ia menjadi antrean
 * yang dibaca manusia (butir 530).
 *
 * Nama sengaja tidak pernah ikut dicocokkan, dan tidak ada satu pun jalur di
 * kelas ini yang membacanya. Nama tidak unik, tidak stabil ejaannya, dan
 * mencocokkannya berarti dua siswa bernama sama menjadi satu.
 */
class StudentClaimMatcher
{
    /**
     * @param  RoleName  $role  peran yang diminta; menentukan kolom tautan mana
     *                          yang harus masih kosong
     */
    public function match(string $nis, string $nisn, RoleName $role): ClaimMatch
    {
        $nis = self::normalize($nis);
        $nisn = self::normalize($nisn);

        if ($nis === '' || $nisn === '') {
            return ClaimMatch::failed(ClaimMatchOutcome::MatchFailed);
        }

        $byNis = $this->activeStudentsWhere('nis', $nis);
        $byNisn = $this->activeStudentsWhere('nisn', $nisn);

        // Salah satu nilai tidak menunjuk siapa pun.
        if ($byNis->isEmpty() || $byNisn->isEmpty()) {
            return ClaimMatch::failed(ClaimMatchOutcome::MatchFailed);
        }

        $ids = $byNisn->modelKeys();
        $both = $byNis->filter(fn (Student $student) => in_array($student->getKey(), $ids, true));

        /*
         * Keduanya menunjuk seseorang, tetapi bukan orang yang sama.
         *
         * Ini bukan sekadar salah ketik: seseorang menyebut NIS satu siswa dan
         * NISN siswa lain. Ia dibedakan dari MATCH_FAILED **hanya di catatan
         * internal** — dari luar keduanya satu kalimat yang sama.
         */
        if ($both->isEmpty()) {
            return ClaimMatch::failed(ClaimMatchOutcome::IdentityConflict);
        }

        /*
         * Lebih dari satu siswa cocok pada kedua nilai. NIS unik per cabang dan
         * NISN unik secara nasional, jadi keadaan ini berarti data induknya
         * sendiri bermasalah. Menebak salah satunya berarti berpeluang menaut
         * seseorang ke anak orang lain, jadi tidak ada yang ditebak.
         */
        if ($both->count() > 1) {
            return ClaimMatch::failed(ClaimMatchOutcome::MatchFailed);
        }

        /** @var Student $student */
        $student = $both->first();

        if ($this->isAlreadyLinked($student, $role)) {
            return ClaimMatch::failed(ClaimMatchOutcome::AccountAlreadyLinked);
        }

        return ClaimMatch::matched($student);
    }

    /**
     * Kolom tautan yang harus masih kosong untuk peran ini.
     */
    public function isAlreadyLinked(Student $student, RoleName $role): bool
    {
        return match ($role) {
            RoleName::Siswa => $student->user_id !== null,
            RoleName::OrangTua => $student->parent_user_id !== null,
            default => true,
        };
    }

    /**
     * Trim, lalu buang seluruh spasi di dalamnya.
     *
     * Yang dibuang hanya spasi. NIS di beberapa cabang memuat huruf, sehingga
     * menyeragamkan kapitalisasi berarti mengubah nilai yang dicari menjadi
     * nilai yang tidak pernah ada di basis data.
     */
    public static function normalize(string $value): string
    {
        return (string) preg_replace('/\s+/u', '', trim($value));
    }

    /**
     * Siswa aktif di cabang yang aktif, lintas cabang.
     *
     * Tanpa scope tenant, karena pemohonnya tamu: ia tidak punya cabang, dan
     * memintanya memilih cabang lebih dulu berarti mengembalikan pertanyaan
     * "kamu ini siapa" yang justru hendak dihapus alur ini. Yang mempersempit
     * hasilnya adalah pasangan NIS + NISN, bukan cabang.
     *
     * Cabang nonaktif ikut disaring: sebuah cabang yang sudah dimatikan tidak
     * boleh tetap menjadi pintu pendaftaran akun baru.
     *
     * @return Collection<int, Student>
     */
    protected function activeStudentsWhere(string $column, string $value): Collection
    {
        return Student::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->active()
            ->where($column, $value)
            ->whereHas('school', fn ($query) => $query->where(
                (new School)->qualifyColumn('is_active'), true,
            ))
            ->get();
    }
}
