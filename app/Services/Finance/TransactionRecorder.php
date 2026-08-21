<?php

namespace App\Services\Finance;

use App\Enums\TransactionType;
use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Models\Transaction;
use App\Models\User;
use App\Support\ProofPath;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * KAS-01 — "Sebagai Bendahara, saya dapat mencatat pemasukan dan pengeluaran
 * kas sekolah"; API 4.9 — POST /transactions dan PUT /transactions/{id}.
 *
 * Satu-satunya jalur tulis `transactions`. Halaman Filament hanya mengumpulkan
 * isian; kewenangan, cabang, pencatat, dan pemeriksaan jalur bukti ada di sini,
 * sehingga jalur lain (perintah artisan, REST API Phase 2) tidak dapat
 * melewatinya.
 *
 * Termasuk penghapusan. API 4.9 menyebut DELETE /transactions/{id} sebagai
 * "soft delete"; kolom penyimpannya tidak ada di ERD dan ditambahkan sebagai
 * keputusan implementasi Phase 1 — lihat butir 128. Yang berwenang di sana
 * lebih sempit daripada yang berwenang mencatat (butir 129).
 */
class TransactionRecorder
{
    /**
     * Bukti transaksi ("scan nota/kwitansi") tidak boleh dapat diambil lewat
     * URL statis: `03-architecture/04-security.md` menetapkan berkas unggahan
     * "disimpan di storage/ (di luar web root)". Disknya sama dengan bukti
     * pembayaran dan PDF rapor.
     */
    public const PROOF_DISK = 'local';

    public const PROOF_DIRECTORY = 'transaction-proofs';

    /**
     * KAS-01 tidak menyebut batas ukuran. Yang dipakai adalah batas yang sudah
     * menjadi pola project untuk berkas bukti sejenis — 5 MB pada bukti
     * pembayaran (SPP-03) — bukan angka baru. Lihat butir 76.
     */
    public const PROOF_MAX_KILOBYTES = 5120;

    /**
     * Security 3.4 — "Hanya JPG/PNG/PDF diperbolehkan". KAS-01 sendiri hanya
     * menyebut "scan nota/kwitansi" tanpa format, sehingga aturan global itulah
     * yang berlaku; daftarnya tidak diperluas.
     *
     * @var array<int, string>
     */
    public const PROOF_MIME_TYPES = ['image/jpeg', 'image/png', 'application/pdf'];

    protected const SCALE = 2;

    /** ERD: `amount` DECIMAL(12,2) — sepuluh digit di depan koma. */
    protected const MAX_AMOUNT = '9999999999.99';

    /**
     * Direktori bukti untuk satu cabang. Dipisah per `school_id` supaya berkas
     * satu cabang tidak pernah berada di jalur yang sama dengan cabang lain.
     */
    public static function proofDirectory(int $schoolId): string
    {
        return self::PROOF_DIRECTORY."/{$schoolId}";
    }

    /**
     * Mencatat satu transaksi kas baru.
     *
     * `created_by` diambil dari sesi dan `school_id` diturunkan dari akun
     * pelakunya — kecuali Super Admin, yang memang tidak punya cabang dan
     * karena itu wajib memilihnya. Keduanya tidak pernah dibaca begitu saja
     * dari payload.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException|ValidationException
     */
    public function record(array $input, User $actor): Transaction
    {
        $this->authorize($actor, 'create');

        $schoolId = $this->resolveSchoolId($input['school_id'] ?? null, $actor);
        $attributes = $this->resolveAttributes($input, $schoolId);

        return DB::transaction(fn (): Transaction => Transaction::query()->create([
            ...$attributes,
            'school_id' => $schoolId,
            'created_by' => $actor->getKey(),
        ]));
    }

    /**
     * Mengubah satu transaksi kas. API 4.9 — PUT /transactions/{id}.
     *
     * `created_by` dan `school_id` tidak ikut berubah: yang pertama adalah
     * pencatat aslinya, yang kedua adalah cabang tempat uangnya bergerak.
     * Siapa yang mengubah tercatat di `audit_logs` — `transactions` memang
     * tidak punya `updated_at` untuk menyimpannya.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException|ValidationException
     */
    public function update(int $transactionId, array $input, User $actor): Transaction
    {
        return DB::transaction(function () use ($transactionId, $input, $actor): Transaction {
            $transaction = $this->findWithinTenant($transactionId, $actor);

            if (Gate::forUser($actor)->denies('update', $transaction)) {
                throw new AuthorizationException('Anda tidak berwenang mengubah transaksi kas.');
            }

            $attributes = $this->resolveAttributes($input, (int) $transaction->school_id);

            // `save()`, bukan mass update: jejak audit UPDATED ditulis listener
            // `eloquent.updated`, dan query builder tidak memicu model event
            // sama sekali (butir 46).
            $transaction->forceFill($attributes)->save();

            return $transaction;
        });
    }

    /**
     * Menghapus satu transaksi kas — API 4.9.2: "Hapus transaksi (soft
     * delete)".
     *
     * Yang terjadi hanya `deleted_at` terisi. Tidak ada satu pun kolom bisnis
     * yang berubah, dan berkas buktinya tidak disentuh: nota yang sudah
     * diunggah adalah dokumen sumber, dan transaksi yang dihapus karena salah
     * catat justru sering perlu ditelusuri kembali lewat notanya (butir 132).
     *
     * Urutannya sengaja: cari dulu, baru periksa kewenangan. Transaksi cabang
     * lain karena itu tidak pernah sampai ke pemeriksaan izin — keberadaannya
     * tidak terkonfirmasi, persis seperti pada `update()`.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function delete(int $transactionId, User $actor): Transaction
    {
        return DB::transaction(function () use ($transactionId, $actor): Transaction {
            $transaction = $this->findWithinTenant($transactionId, $actor);

            if (Gate::forUser($actor)->denies('delete', $transaction)) {
                throw new AuthorizationException('Anda tidak berwenang menghapus transaksi kas.');
            }

            // `delete()` pada model, bukan mass update: jejak audit DELETED
            // ditulis listener `eloquent.deleted`, dan query builder tidak
            // memicu model event sama sekali (butir 46). SoftDeletes membuat
            // pemanggilan ini mengisi `deleted_at`, bukan menghapus barisnya.
            $transaction->delete();

            return $transaction;
        });
    }

    /**
     * Isian yang boleh ditulis pengguna, sudah tervalidasi dan dinormalkan.
     *
     * `school_id` dan `created_by` sengaja **tidak** ada di sini: keduanya
     * bukan isian.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    protected function resolveAttributes(array $input, int $schoolId): array
    {
        return [
            'type' => $this->resolveType($input['type'] ?? null)->value,
            'category' => $this->resolveCategory($input['category'] ?? null),
            'amount' => $this->resolveAmount($input['amount'] ?? null),
            'transaction_date' => $this->resolveDate($input['transaction_date'] ?? null),
            'description' => $this->resolveDescription($input['description'] ?? null),
            'reference_number' => $this->resolveReferenceNumber($input['reference_number'] ?? null),
            'proof_url' => ProofPath::resolve(
                $input['proof_url'] ?? null,
                static::proofDirectory($schoolId),
                'proof_url',
            ),
        ];
    }

    /**
     * @throws AuthorizationException
     */
    protected function authorize(User $actor, string $ability): void
    {
        if (Gate::forUser($actor)->denies($ability, Transaction::class)) {
            throw new AuthorizationException('Anda tidak berwenang mencatat transaksi kas.');
        }
    }

    /**
     * Transaksi yang boleh disentuh pengguna ini.
     *
     * Global scope dilepas dan `school_id` disaring eksplisit dari akun
     * pelakunya: scope bergantung pada sesi, sedangkan pagar tenant di jalur
     * tulis harus berasal dari argumen yang jelas.
     *
     * @throws ValidationException
     */
    protected function findWithinTenant(int $transactionId, User $actor): Transaction
    {
        $query = Transaction::query()->withoutGlobalScope(SchoolScope::class);

        if (! $actor->isSuperAdmin()) {
            // Akun School Level tanpa cabang tidak dapat menyentuh apa pun:
            // NULL di sini mencocokkan nol baris, bukan seluruh baris.
            $query->where('school_id', $actor->school_id);
        }

        $transaction = $query->find($transactionId);

        if ($transaction === null) {
            throw ValidationException::withMessages([
                'id' => 'Transaksi tidak ditemukan pada cabang Anda.',
            ]);
        }

        return $transaction;
    }

    /**
     * Cabang tempat transaksi dicatat.
     *
     * Nilai dari form hanya dipercaya bila pelakunya Super Admin — merekalah
     * satu-satunya peran yang `school_id`-nya NULL (Arsitektur 3.2.2) dan
     * karena itu wajib memilih cabang. Bagi peran School Level, apa pun yang
     * muncul di payload adalah selundupan dan diabaikan sepenuhnya. Pola sama
     * dengan FeeTypeResource::resolveSchoolId().
     *
     * @throws ValidationException
     */
    protected function resolveSchoolId(mixed $formValue, User $actor): int
    {
        if (! $actor->isSuperAdmin()) {
            if ($actor->school_id === null) {
                throw ValidationException::withMessages([
                    'school_id' => 'Akun Anda belum terhubung ke cabang mana pun.',
                ]);
            }

            return (int) $actor->school_id;
        }

        if (blank($formValue) || ! is_numeric($formValue)) {
            throw ValidationException::withMessages([
                'school_id' => 'Cabang sekolah wajib dipilih.',
            ]);
        }

        $exists = School::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->whereKey((int) $formValue)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'school_id' => 'Cabang sekolah tidak ditemukan.',
            ]);
        }

        return (int) $formValue;
    }

    /**
     * @throws ValidationException
     */
    protected function resolveType(mixed $value): TransactionType
    {
        $type = $value instanceof TransactionType
            ? $value
            : TransactionType::tryFrom(is_string($value) ? $value : '');

        if ($type === null) {
            throw ValidationException::withMessages([
                'type' => 'Jenis transaksi harus pemasukan atau pengeluaran.',
            ]);
        }

        return $type;
    }

    /**
     * ERD: `category` VARCHAR(100) NOT NULL — teks bebas, bukan enum.
     *
     * "Gaji, Pembelian Alat, Dana BOS, Sumbangan" pada ERD adalah contoh, dan
     * membatasi isian ke keempatnya berarti membuat master kategori yang tidak
     * diminta siapa pun (butir 77).
     *
     * @throws ValidationException
     */
    protected function resolveCategory(mixed $value): string
    {
        $category = is_string($value) ? trim($value) : '';

        if ($category === '') {
            throw ValidationException::withMessages([
                'category' => 'Kategori wajib diisi.',
            ]);
        }

        if (mb_strlen($category) > 100) {
            throw ValidationException::withMessages([
                'category' => 'Kategori maksimal 100 karakter.',
            ]);
        }

        return $category;
    }

    /**
     * Nominal selalu positif. Arah kasnya ditentukan `type`, bukan tandanya.
     *
     * @throws ValidationException
     */
    protected function resolveAmount(mixed $value): string
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                'amount' => 'Jumlah harus berupa angka.',
            ]);
        }

        $amount = number_format((float) $value, self::SCALE, '.', '');

        if (bccomp($amount, '0', self::SCALE) <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Jumlah harus lebih besar dari 0.',
            ]);
        }

        if (bccomp($amount, self::MAX_AMOUNT, self::SCALE) > 0) {
            throw ValidationException::withMessages([
                'amount' => 'Jumlah melebihi batas kolom DECIMAL(12,2).',
            ]);
        }

        return $amount;
    }

    /**
     * Keterangan wajib diisi.
     *
     * ERD memberi kolomnya NULL, dan nullability itu **tidak** diubah: kolom
     * database dan aturan alur kerja adalah dua hal berbeda. Yang menetapkan
     * kewajibannya adalah aturan validasi KAS-01 (butir 81) — sebuah baris
     * buku kas tanpa keterangan adalah uang yang bergerak tanpa penjelasan.
     *
     * @throws ValidationException
     */
    protected function resolveDescription(mixed $value): string
    {
        $description = is_string($value) ? trim($value) : '';

        if ($description === '') {
            throw ValidationException::withMessages([
                'description' => 'Keterangan wajib diisi.',
            ]);
        }

        return $description;
    }

    /**
     * Nomor referensi wajib diisi.
     *
     * Sama seperti keterangan: kolomnya tetap NULL di ERD, kewajibannya ada di
     * alur kerja. Berbeda dari `payments.reference_number`, yang memang boleh
     * kosong karena pembayaran tunai tidak punya nomor transfer (butir 57
     * bagian referensi), setiap baris buku kas bersandar pada nota atau
     * kuitansi yang nomornya dapat ditelusuri.
     *
     * @throws ValidationException
     */
    protected function resolveReferenceNumber(mixed $value): string
    {
        $reference = is_string($value) ? trim($value) : '';

        if ($reference === '') {
            throw ValidationException::withMessages([
                'reference_number' => 'Nomor referensi wajib diisi.',
            ]);
        }

        if (mb_strlen($reference) > 100) {
            throw ValidationException::withMessages([
                'reference_number' => 'Nomor referensi maksimal 100 karakter.',
            ]);
        }

        return $reference;
    }

    /**
     * @throws ValidationException
     */
    protected function resolveDate(mixed $value): string
    {
        if (blank($value)) {
            throw ValidationException::withMessages([
                'transaction_date' => 'Tanggal transaksi wajib diisi.',
            ]);
        }

        try {
            return Carbon::parse($value instanceof \DateTimeInterface ? $value : (string) $value)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'transaction_date' => 'Tanggal transaksi tidak valid.',
            ]);
        }
    }
}
