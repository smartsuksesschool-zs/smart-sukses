<?php

namespace App\Services\Finance;

use App\Enums\PaymentMethod;
use App\Enums\StudentFeeStatus;
use App\Models\Payment;
use App\Models\Scopes\SchoolScope;
use App\Models\StudentFee;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * SPP-03 — "mencatat pembayaran siswa secara manual (cash atau transfer)",
 * dengan "status tagihan otomatis berubah ke PAID/PARTIAL".
 *
 * Satu-satunya tempat sebuah baris `payments` ditulis. Halaman Filament hanya
 * mengumpulkan isian; seluruh aturan — metode yang sah pada Phase 1, arah
 * status, akumulasi cicilan, dan pagar tenant — ada di sini, sehingga jalur
 * lain (perintah artisan, REST API Phase 2) tidak bisa melewatinya.
 *
 * Uang tidak pernah disentuh floating point. `DECIMAL(12,2)` dibaca Eloquent
 * sebagai string dan tetap diperlakukan sebagai string lewat bcmath: 0,1 + 0,2
 * tidak boleh menyisakan tagihan yang bukan nol.
 */
class PaymentRecorder
{
    /**
     * Bukti pembayaran tidak boleh dapat diambil lewat URL statis
     * (`03-architecture/04-security.md` — "disimpan di storage/ (di luar web
     * root)"), sehingga disknya `local` (storage/app/private), bukan `public`
     * seperti foto siswa dan logo cabang. Pola yang sama dipakai PDF rapor.
     */
    public const PROOF_DISK = 'local';

    public const PROOF_DIRECTORY = 'payment-proofs';

    /** Security 3.4 — "Hanya JPG/PNG/PDF diperbolehkan"; SPP-03: maks 5 MB. */
    public const PROOF_MAX_KILOBYTES = 5120;

    /**
     * @var array<int, string>
     */
    public const PROOF_MIME_TYPES = ['image/jpeg', 'image/png', 'application/pdf'];

    /** Skala bcmath: mengikuti DECIMAL(12,2) pada ERD. */
    protected const SCALE = 2;

    /**
     * Metode yang dapat dicatat manual pada Phase 1.
     *
     * PAYMENT_GATEWAY tetap ada di enum dan di kolom ENUM database — ia bagian
     * dari ERD dan menghapusnya berarti mengubah skema blueprint. Yang belum
     * ada adalah integrasinya: `01-prd/03-phase2-overview.md` menempatkan
     * Midtrans/Xendit di Phase 2. Nilai itu karena itu bukan sekadar
     * disembunyikan dari dropdown melainkan ditolak di sini — pembayaran
     * gateway yang "dicatat manual" berarti uang yang tidak pernah
     * direkonsiliasi dengan siapa pun.
     *
     * @return array<int, PaymentMethod>
     */
    public static function allowedMethods(): array
    {
        return [PaymentMethod::Cash, PaymentMethod::Transfer];
    }

    /**
     * @return array<string, string>
     */
    public static function methodOptions(): array
    {
        return array_reduce(
            static::allowedMethods(),
            fn (array $carry, PaymentMethod $method) => $carry + [$method->value => $method->label()],
            [],
        );
    }

    /**
     * Direktori bukti untuk satu cabang. Dipisah per `school_id` supaya berkas
     * satu cabang tidak pernah berada di jalur yang sama dengan cabang lain.
     */
    public static function proofDirectory(int $schoolId): string
    {
        return self::PROOF_DIRECTORY."/{$schoolId}";
    }

    /**
     * Mencatat satu pembayaran atas satu tagihan.
     *
     * Hanya `student_fee_id` yang diambil dari pemanggil; `school_id`,
     * `student_id`, dan `received_by` diturunkan dari tagihan dan dari sesi.
     * Payload boleh memuat apa saja — ketiganya tidak pernah dibaca dari sana.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException|ValidationException
     */
    public function record(int $studentFeeId, array $input, User $receivedBy): Payment
    {
        $this->authorize($receivedBy);

        $method = $this->resolveMethod($input['payment_method'] ?? null);
        $amount = $this->resolveAmount($input['amount'] ?? null);
        $paymentDate = $this->resolvePaymentDate($input['payment_date'] ?? null);

        return DB::transaction(function () use ($studentFeeId, $input, $receivedBy, $method, $amount, $paymentDate): Payment {
            // Baris tagihan dikunci sebelum sisa tagihannya dibaca. Tanpa ini
            // dua pencatatan yang hampir bersamaan sama-sama membaca sisa yang
            // sudah basi, keduanya lolos pemeriksaan, dan akumulasi
            // `amount_paid` menjadi salah (lost update).
            $studentFee = $this->lockStudentFee($studentFeeId, $receivedBy);

            $this->guardStatus($studentFee);

            // Sumber kebenaran akumulasi adalah tabel `payments` itu sendiri,
            // bukan kolom ringkasannya: bila `amount_paid` pernah menyimpang,
            // pencatatan berikutnya mengembalikannya alih-alih meneruskan
            // simpangannya.
            $paidSoFar = $this->paidSoFar($studentFee);
            $remaining = bcsub((string) $studentFee->amount, $paidSoFar, self::SCALE);

            $this->guardRemaining($amount, $remaining);

            $proofPath = $this->resolveProofPath($input['proof_url'] ?? null, (int) $studentFee->school_id);

            $payment = Payment::query()->create([
                'school_id' => $studentFee->school_id,
                'student_fee_id' => $studentFee->getKey(),
                'student_id' => $studentFee->student_id,
                'payment_method' => $method->value,
                'amount_paid' => $amount,
                'reference_number' => $this->stringOrNull($input['reference_number'] ?? null),
                'proof_url' => $proofPath,
                'payment_date' => $paymentDate,
                'received_by' => $receivedBy->getKey(),
                'notes' => $this->stringOrNull($input['notes'] ?? null),
            ]);

            $totalPaid = bcadd($paidSoFar, $amount, self::SCALE);

            // `save()`, bukan mass update: jejak audit UPDATED ditulis listener
            // `eloquent.updated`, dan query builder tidak memicu model event
            // sama sekali (docs/implementation-notes.md butir 46).
            $studentFee->forceFill([
                'amount_paid' => $totalPaid,
                'status' => static::statusFor($totalPaid, (string) $studentFee->amount)->value,
            ])->save();

            return $payment;
        });
    }

    /**
     * Status tagihan sepenuhnya turunan dari akumulasi pembayaran — tidak ada
     * jalur yang menyetelnya secara manual.
     */
    public static function statusFor(string $totalPaid, string $amount): StudentFeeStatus
    {
        if (bccomp($totalPaid, '0', self::SCALE) <= 0) {
            return StudentFeeStatus::Unpaid;
        }

        return bccomp($totalPaid, $amount, self::SCALE) >= 0
            ? StudentFeeStatus::Paid
            : StudentFeeStatus::Partial;
    }

    /**
     * Sisa tagihan yang masih boleh dibayar.
     */
    public static function remainingFor(StudentFee $studentFee): string
    {
        $remaining = bcsub((string) $studentFee->amount, (string) $studentFee->amount_paid, self::SCALE);

        return bccomp($remaining, '0', self::SCALE) < 0 ? '0.00' : $remaining;
    }

    /**
     * Aksi di tabel dapat disembunyikan, dan penyembunyian bukan proteksi:
     * request Livewire tetap dapat dikirim langsung. Izinnya karena itu
     * diperiksa lagi di sini, pada jalur yang benar-benar menulis.
     *
     * @throws AuthorizationException
     */
    protected function authorize(User $receivedBy): void
    {
        if (Gate::forUser($receivedBy)->denies('create', Payment::class)) {
            throw new AuthorizationException('Anda tidak berwenang mencatat pembayaran.');
        }
    }

    /**
     * Tagihan yang boleh dibayar pengguna ini, terkunci untuk update.
     *
     * Global scope sengaja dilepas dan `school_id` disaring eksplisit dari akun
     * pencatat: scope bergantung pada sesi, sedangkan pagar tenant di jalur
     * tulis harus berasal dari argumen yang jelas. Super Admin tidak memiliki
     * `school_id` (Arsitektur 3.2.2) sehingga tidak tersaring — itulah baris ✅
     * mereka pada matriks — tetapi `school_id` pembayarannya tetap diambil dari
     * tagihan, bukan dari akun mereka.
     *
     * @throws ValidationException
     */
    protected function lockStudentFee(int $studentFeeId, User $receivedBy): StudentFee
    {
        $query = StudentFee::query()->withoutGlobalScope(SchoolScope::class);

        if (! $receivedBy->isSuperAdmin()) {
            // Akun School Level tanpa cabang tidak dapat membayar apa pun:
            // NULL di sini mencocokkan nol baris, bukan seluruh baris.
            $query->where('school_id', $receivedBy->school_id);
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
     * @throws ValidationException
     */
    protected function guardStatus(StudentFee $studentFee): void
    {
        // Pembebasan tagihan (WAIVED) belum punya UI, tetapi statusnya sudah
        // ada di ERD dan dapat muncul dari jalur lain. Blueprint tidak
        // menjelaskan apa yang terjadi bila tagihan yang sudah dibebaskan
        // menerima pembayaran; menerimanya berarti diam-diam membatalkan
        // pembebasan itu, jadi pilihannya menolak (butir 60).
        if ($studentFee->status === StudentFeeStatus::Waived) {
            throw ValidationException::withMessages([
                'amount' => 'Tagihan ini sudah dibebaskan (WAIVED) dan tidak dapat menerima pembayaran.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function guardRemaining(string $amount, string $remaining): void
    {
        if (bccomp($remaining, '0', self::SCALE) <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Tagihan ini sudah lunas.',
            ]);
        }

        // Blueprint tidak mengatur kelebihan bayar sama sekali — tidak ada
        // saldo siswa, tidak ada kembalian, dan tidak ada kolom untuk keduanya
        // di ERD. Menerimanya berarti mengarang salah satunya, jadi yang
        // dijaga adalah invarian yang memang tertulis: `amount_paid` tidak
        // pernah melampaui `amount` (butir 59).
        if (bccomp($amount, $remaining, self::SCALE) > 0) {
            throw ValidationException::withMessages([
                'amount' => "Jumlah melebihi sisa tagihan (Rp {$remaining}).",
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function resolveMethod(mixed $value): PaymentMethod
    {
        $method = $value instanceof PaymentMethod
            ? $value
            : PaymentMethod::tryFrom(is_string($value) ? $value : '');

        if ($method === null || ! in_array($method, static::allowedMethods(), true)) {
            throw ValidationException::withMessages([
                'payment_method' => 'Metode pembayaran tidak berlaku untuk pencatatan manual.',
            ]);
        }

        return $method;
    }

    /**
     * @throws ValidationException
     */
    protected function resolveAmount(mixed $value): string
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                'amount' => 'Jumlah pembayaran harus berupa angka.',
            ]);
        }

        $amount = number_format((float) $value, self::SCALE, '.', '');

        if (bccomp($amount, '0', self::SCALE) <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Jumlah pembayaran harus lebih besar dari 0.',
            ]);
        }

        return $amount;
    }

    /**
     * @throws ValidationException
     */
    protected function resolvePaymentDate(mixed $value): string
    {
        if (blank($value)) {
            throw ValidationException::withMessages([
                'payment_date' => 'Tanggal pembayaran wajib diisi.',
            ]);
        }

        try {
            return Carbon::parse($value instanceof \DateTimeInterface ? $value : (string) $value)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'payment_date' => 'Tanggal pembayaran tidak valid.',
            ]);
        }
    }

    /**
     * Jalur berkas bukti, atau NULL bila tidak ada bukti yang diunggah.
     *
     * Yang diterima hanya jalur di dalam direktori bukti milik cabang tagihan
     * ini. Nama berkasnya sendiri dibuat Filament (UUID), bukan berasal dari
     * nama unggahan pengguna; pemeriksaan di sini menutup sisanya, yaitu
     * payload yang menunjuk berkas cabang lain atau menyusup keluar direktori
     * lewat `..`.
     *
     * @throws ValidationException
     */
    protected function resolveProofPath(mixed $value, int $schoolId): ?string
    {
        if (is_array($value)) {
            // FileUpload menyerahkan state-nya sebagai array berkunci hash.
            $value = collect($value)->filter()->first();
        }

        $path = $this->stringOrNull($value);

        if ($path === null) {
            return null;
        }

        $path = str_replace('\\', '/', $path);
        $prefix = static::proofDirectory($schoolId).'/';

        if (! str_starts_with($path, $prefix) || str_contains($path, '..')) {
            throw ValidationException::withMessages([
                'proof_url' => 'Berkas bukti pembayaran tidak valid.',
            ]);
        }

        return $path;
    }

    /**
     * Akumulasi seluruh pembayaran sah atas satu tagihan.
     */
    protected function paidSoFar(StudentFee $studentFee): string
    {
        $sum = Payment::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('student_fee_id', $studentFee->getKey())
            ->sum('amount_paid');

        return number_format((float) $sum, self::SCALE, '.', '');
    }

    protected function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
