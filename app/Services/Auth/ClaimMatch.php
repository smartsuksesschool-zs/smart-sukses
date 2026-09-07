<?php

namespace App\Services\Auth;

use App\Enums\ClaimMatchOutcome;
use App\Models\Student;

/**
 * Hasil satu pencocokan: keputusannya, dan siswanya bila memang ada.
 *
 * `student` terisi **hanya** pada MATCHED. Pada ACCOUNT_ALREADY_LINKED pun ia
 * dibiarkan NULL: pemanggil yang memegang barisnya cepat atau lambat akan
 * menampilkan sesuatu darinya, dan yang ditampilkan itu adalah nama siswa
 * sungguhan kepada seseorang yang baru saja terbukti **bukan** pemiliknya.
 */
final class ClaimMatch
{
    private function __construct(
        public readonly ClaimMatchOutcome $outcome,
        public readonly ?Student $student = null,
    ) {}

    public static function matched(Student $student): self
    {
        return new self(ClaimMatchOutcome::Matched, $student);
    }

    public static function failed(ClaimMatchOutcome $outcome): self
    {
        return new self($outcome);
    }

    public function isMatch(): bool
    {
        return $this->outcome->isMatch();
    }
}
