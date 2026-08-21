<?php

namespace App\Support\Finance;

use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Cabang yang menjadi konteks satu operasi keuangan satu-cabang.
 *
 * Aturannya sudah dipakai sejak SPP-05 dan Batch 6.5, tetapi disalin utuh di
 * dua exporter. Karena Batch 6.7 menambah dua pembaca lagi — ringkasan
 * keuangan dan laporan SPP — aturannya dipindahkan ke satu tempat alih-alih
 * disalin untuk keempat kalinya. Perilakunya tidak berubah sedikit pun; yang
 * berubah hanya di mana ia ditulis (butir 133).
 *
 * Intinya satu kalimat: nilai dari payload hanya dipercaya bila pelakunya
 * Super Admin. Merekalah satu-satunya peran yang `school_id`-nya NULL
 * (Arsitektur 3.2.2) dan karena itu wajib memilih cabang. Bagi peran School
 * Level, apa pun yang muncul di payload adalah selundupan dan diabaikan
 * sepenuhnya.
 */
class SchoolContext
{
    /**
     * @throws ValidationException
     */
    public static function resolve(mixed $requested, User $actor): int
    {
        if (! $actor->isSuperAdmin()) {
            if ($actor->school_id === null) {
                throw ValidationException::withMessages([
                    'school_id' => 'Akun Anda belum terhubung ke cabang mana pun.',
                ]);
            }

            return (int) $actor->school_id;
        }

        if (blank($requested) || ! is_numeric($requested)) {
            throw ValidationException::withMessages([
                'school_id' => 'Cabang sekolah wajib dipilih.',
            ]);
        }

        $exists = School::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->whereKey((int) $requested)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'school_id' => 'Cabang sekolah tidak ditemukan.',
            ]);
        }

        return (int) $requested;
    }
}
