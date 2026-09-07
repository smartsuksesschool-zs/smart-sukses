<?php

namespace App\Enums;

/**
 * Hasil pencocokan NIS/NISN terhadap data induk siswa.
 *
 * **Seluruh kegagalan terlihat sama dari luar.** Enum ini hidup di sisi dalam:
 * ia menerangkan catatan internal dan menjadi bahasa test, sementara pemohon
 * selalu membaca satu kalimat yang sama. Membedakan "NIS tidak ditemukan" dari
 * "NIS benar tetapi NISN salah" berarti mengubah formulir ini menjadi alat
 * penebak NIS: yang pertama menjawab "tebakanmu salah", yang kedua menjawab
 * "tebakanmu benar, tinggal satu nilai lagi" (butir 533).
 */
enum ClaimMatchOutcome: string
{
    /** NIS dan NISN menunjuk satu siswa yang sama. */
    case Matched = 'MATCHED';

    /** Salah satu nilai tidak menunjuk siapa pun. */
    case MatchFailed = 'MATCH_FAILED';

    /** NIS menunjuk siswa A, NISN menunjuk siswa B. */
    case IdentityConflict = 'IDENTITY_CONFLICT';

    /** Siswanya cocok, tetapi peran yang diminta sudah ada pemiliknya. */
    case AccountAlreadyLinked = 'ACCOUNT_ALREADY_LINKED';

    public function isMatch(): bool
    {
        return $this === self::Matched;
    }
}
