<?php

namespace App\Services\Finance;

use App\Enums\StudentFeeStatus;
use App\Models\Payment;
use App\Models\Scopes\SchoolScope;
use App\Models\StudentFee;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * API 4.9 — PATCH /student-fees/{id}/waive: "bebaskan tagihan dengan alasan
 * (status → WAIVED)".
 *
 * Satu-satunya jalur yang memindahkan tagihan ke WAIVED. Aksi Filament hanya
 * mengumpulkan alasannya; kewenangan, keadaan yang boleh dibebaskan, dan
 * penguncian barisnya ada di sini — sehingga jalur lain (perintah artisan,
 * REST API Phase 2) tidak dapat melewatinya.
 *
 * Pembebasan **tidak** menyentuh uang. `amount` dan `amount_paid` tidak diubah,
 * riwayat `payments` tidak disentuh, dan tidak ada refund, kredit, maupun
 * pembalikan yang dibuat — tidak satu pun dari ketiganya ada di blueprint.
 */
class StudentFeeWaiver
{
    /** ERD 2.2 — `student_fees.waive_reason` VARCHAR(200). */
    public const REASON_MAX_LENGTH = 200;

    /**
     * Membebaskan satu tagihan.
     *
     * Hanya id tagihan dan alasannya yang datang dari pemanggil. Cabangnya
     * tidak pernah dibaca dari payload: tagihan diresolusi ulang di dalam
     * batas tenant pengguna, dan `school_id`-nya memang tidak berubah.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function waive(int $studentFeeId, mixed $reason, User $actor): StudentFee
    {
        return DB::transaction(function () use ($studentFeeId, $reason, $actor): StudentFee {
            // Baris yang sama dikunci PaymentRecorder. Itulah yang membuat
            // lomba antara "bebaskan" dan "catat pembayaran" berakhir pada satu
            // keadaan: yang kalah membaca hasil yang menang setelah commit,
            // lalu ditolak oleh guard-nya masing-masing.
            $studentFee = $this->lockStudentFee($studentFeeId, $actor);

            $this->authorize($studentFee, $actor);

            $reason = $this->resolveReason($reason);

            $this->guardState($studentFee);

            // `save()`, bukan mass update: jejak audit UPDATED ditulis listener
            // `eloquent.updated`, dan query builder tidak memicu model event
            // sama sekali (docs/implementation-notes.md butir 46).
            $studentFee->forceFill([
                'status' => StudentFeeStatus::Waived->value,
                'waive_reason' => $reason,
            ])->save();

            return $studentFee;
        });
    }

    /**
     * Tagihan yang boleh disentuh pengguna ini, terkunci untuk update.
     *
     * Global scope dilepas dan `school_id` disaring eksplisit dari akun pelaku:
     * scope bergantung pada sesi, sedangkan pagar tenant di jalur tulis harus
     * berasal dari argumen yang jelas. Super Admin tidak memiliki `school_id`
     * (Arsitektur 3.2.2) sehingga tidak tersaring — mereka memang lintas
     * cabang — dan policy tetap memeriksa recordnya sesudah ini.
     *
     * @throws ValidationException
     */
    protected function lockStudentFee(int $studentFeeId, User $actor): StudentFee
    {
        $query = StudentFee::query()->withoutGlobalScope(SchoolScope::class);

        if (! $actor->isSuperAdmin()) {
            // Akun School Level tanpa cabang tidak dapat membebaskan apa pun:
            // NULL di sini mencocokkan nol baris, bukan seluruh baris.
            $query->where('school_id', $actor->school_id);
        }

        $studentFee = $query->lockForUpdate()->find($studentFeeId);

        if ($studentFee === null) {
            throw ValidationException::withMessages([
                'student_fee_id' => 'Tagihan tidak ditemukan pada cabang Anda.',
            ]);
        }

        return $studentFee;
    }

    /**
     * Aksi di tabel dapat disembunyikan, dan penyembunyian bukan proteksi:
     * request Livewire tetap dapat dikirim langsung. Izinnya karena itu
     * diperiksa lagi di sini, pada jalur yang benar-benar menulis.
     *
     * @throws AuthorizationException
     */
    protected function authorize(StudentFee $studentFee, User $actor): void
    {
        if (Gate::forUser($actor)->denies('waive', $studentFee)) {
            throw new AuthorizationException('Anda tidak berwenang membebaskan tagihan.');
        }
    }

    /**
     * @throws ValidationException
     */
    protected function guardState(StudentFee $studentFee): void
    {
        if ($studentFee->isWaived()) {
            // Alasan yang sudah tercatat tidak ditimpa diam-diam: pembebasan
            // kedua atas tagihan yang sama tidak menambah apa pun kecuali
            // menghapus jejak keputusan pertama.
            throw ValidationException::withMessages([
                'waive_reason' => 'Tagihan ini sudah dibebaskan sebelumnya.',
            ]);
        }

        if (! $studentFee->status->canBeWaived() || $this->hasReceivedMoney($studentFee)) {
            throw ValidationException::withMessages([
                'waive_reason' => 'Tagihan yang sudah menerima pembayaran tidak dapat dibebaskan. '
                    .'Pembayaran yang sudah tercatat tidak dihapus oleh pembebasan.',
            ]);
        }
    }

    /**
     * Apakah tagihan ini sudah pernah menerima uang.
     *
     * Diperiksa dari tabel `payments`, bukan hanya dari kolom ringkasan
     * `amount_paid`: bila ringkasannya pernah menyimpang (butir 61), yang tidak
     * boleh terjadi adalah pembebasan yang lolos karena angka yang salah.
     */
    protected function hasReceivedMoney(StudentFee $studentFee): bool
    {
        if (bccomp((string) $studentFee->amount_paid, '0', 2) > 0) {
            return true;
        }

        return Payment::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('student_fee_id', $studentFee->getKey())
            ->exists();
    }

    /**
     * @throws ValidationException
     */
    protected function resolveReason(mixed $reason): string
    {
        $reason = is_string($reason) ? trim($reason) : '';

        if ($reason === '') {
            // ERD: "Alasan dibebaskan (jika status WAIVED)"; API 4.9:
            // "bebaskan tagihan **dengan alasan**". Pembebasan tanpa alasan
            // adalah tagihan yang hilang tanpa penjelasan.
            throw ValidationException::withMessages([
                'waive_reason' => 'Alasan pembebasan wajib diisi.',
            ]);
        }

        if (mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            throw ValidationException::withMessages([
                'waive_reason' => 'Alasan pembebasan maksimal '.self::REASON_MAX_LENGTH.' karakter.',
            ]);
        }

        return $reason;
    }
}
