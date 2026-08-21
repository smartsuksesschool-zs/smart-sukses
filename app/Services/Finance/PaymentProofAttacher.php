<?php

namespace App\Services\Finance;

use App\Models\Payment;
use App\Models\Scopes\SchoolScope;
use App\Models\User;
use App\Support\ProofPath;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Melampirkan bukti pada pembayaran yang **sudah** tercatat.
 *
 * Keputusan implementasi Phase 1 yang disetujui (butir 113 nomor 9): alur
 * lapangan yang wajar — transfer dicatat hari ini, scan buktinya tiba besok —
 * sebelumnya tidak punya jalan keluar sama sekali karena `payments` bersifat
 * append-only sepenuhnya (butir 64).
 *
 * Kelonggarannya sesempit mungkin dan disengaja demikian:
 *
 *  - satu kolom saja, `proof_url`
 *  - satu arah saja, kosong → terisi
 *  - bukti yang sudah ada tidak pernah ditimpa
 *
 * Nominal, metode, tanggal, tagihan, siswa, pencatat, dan catatan tetap tidak
 * dapat disentuh. `PaymentPolicy::update()` juga tetap menolak tanpa syarat —
 * yang ditambahkan adalah ability `attachProof` tersendiri, bukan pelonggaran
 * update umum (butir 119).
 *
 * Kelas ini sengaja terpisah dari PaymentRecorder: menjadikan pencatat
 * pembayaran sekaligus pengubah pembayaran akan mengaburkan satu-satunya
 * jaminan yang membuat `payments` dapat dipercaya sebagai riwayat.
 */
class PaymentProofAttacher
{
    /**
     * Melampirkan berkas bukti ke satu pembayaran.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function attach(int $paymentId, UploadedFile $file, User $actor): Payment
    {
        $payment = $this->findWithinTenant($paymentId, $actor);

        $this->authorize($payment, $actor);
        $this->guardEmpty($payment);
        $this->guardFile($file);

        $path = $this->store($file, (int) $payment->school_id);

        return $this->write($payment, $path, cleanUpOnFailure: true);
    }

    /**
     * Jalur panel: berkasnya sudah disimpan Filament FileUpload, yang
     * menyerahkan jalur — bukan UploadedFile.
     *
     * Pagarnya sama persis; yang berbeda hanya siapa yang menulis berkasnya.
     * Berkas tidak dihapus saat gagal di sini karena Filament yang memilikinya
     * dan dapat mengunggah ulang jalur yang sama.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function attachStoredPath(int $paymentId, mixed $path, User $actor): Payment
    {
        $payment = $this->findWithinTenant($paymentId, $actor);

        $this->authorize($payment, $actor);
        $this->guardEmpty($payment);

        if (blank($path)) {
            throw ValidationException::withMessages([
                'proof' => 'Berkas bukti pembayaran wajib diunggah.',
            ]);
        }

        return $this->write($payment, $path, cleanUpOnFailure: false);
    }

    /**
     * Menulis `proof_url` di bawah row lock — dan tidak satu kolom pun selain
     * itu.
     *
     * @throws ValidationException
     */
    protected function write(Payment $payment, mixed $path, bool $cleanUpOnFailure): Payment
    {
        try {
            return DB::transaction(function () use ($payment, $path): Payment {
                // Dibaca ulang di bawah lock: antara pemeriksaan di atas dan
                // penulisan ini, permintaan lain bisa saja sudah melampirkan
                // bukti pada pembayaran yang sama. Yang kalah harus ditolak,
                // bukan menimpa.
                $locked = Payment::query()
                    ->withoutGlobalScope(SchoolScope::class)
                    ->whereKey($payment->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->guardEmpty($locked);

                // Jalur diperiksa ulang lewat pagar yang sama dengan pencatatan
                // pembayaran, walaupun jalurnya dibuat sendiri di sini.
                $safePath = ProofPath::resolve(
                    $path,
                    PaymentRecorder::proofDirectory((int) $locked->school_id),
                    'proof',
                );

                // Hanya `proof_url`. `save()` memicu event `updated` sehingga
                // jejak auditnya tercatat listener yang sudah ada (butir 46).
                $locked->forceFill(['proof_url' => $safePath])->save();

                return $locked;
            });
        } catch (\Throwable $e) {
            // Berkas sudah terlanjur tersimpan sebelum transaksi dibuka.
            // Membiarkannya berarti menumpuk berkas yatim yang tidak ditunjuk
            // baris mana pun; menghapusnya di sini menutup kasus yang paling
            // umum tanpa membangun sistem retensi baru (butir 120).
            if ($cleanUpOnFailure && is_string($path)) {
                Storage::disk(PaymentRecorder::PROOF_DISK)->delete($path);
            }

            throw $e;
        }
    }

    /**
     * Pembayaran yang boleh disentuh pengguna ini.
     *
     * Global scope dilepas dan `school_id` disaring eksplisit dari akun
     * pelakunya: scope bergantung pada sesi dan tidak membatasi apa pun bagi
     * Super Admin, sedangkan pagar tenant di jalur tulis harus berasal dari
     * argumen yang jelas.
     *
     * @throws ValidationException
     */
    protected function findWithinTenant(int $paymentId, User $actor): Payment
    {
        $query = Payment::query()->withoutGlobalScope(SchoolScope::class);

        if (! $actor->isSuperAdmin()) {
            // Akun School Level tanpa cabang tidak dapat menyentuh apa pun:
            // NULL di sini mencocokkan nol baris, bukan seluruh baris.
            $query->where('school_id', $actor->school_id);
        }

        $payment = $query->find($paymentId);

        if ($payment === null) {
            throw ValidationException::withMessages([
                'payment' => 'Pembayaran tidak ditemukan pada cabang Anda.',
            ]);
        }

        return $payment;
    }

    /**
     * @throws AuthorizationException
     */
    protected function authorize(Payment $payment, User $actor): void
    {
        if (Gate::forUser($actor)->denies('attachProof', $payment)) {
            throw new AuthorizationException('Anda tidak berwenang melampirkan bukti pembayaran.');
        }
    }

    /**
     * Bukti yang sudah ada tidak pernah ditimpa.
     *
     * Mengganti bukti secara diam-diam menghapus dokumen yang mungkin sudah
     * dipakai mempertanggungjawabkan uang — dan tidak ada jejak apa pun yang
     * menyatakan berkas sebelumnya pernah ada.
     *
     * @throws ValidationException
     */
    protected function guardEmpty(Payment $payment): void
    {
        if (filled($payment->proof_url)) {
            throw ValidationException::withMessages([
                'proof' => 'Pembayaran ini sudah memiliki bukti. Bukti yang sudah ada tidak dapat diganti.',
            ]);
        }
    }

    /**
     * SPP-03 poin 2 — "JPG/PNG/PDF, maks 5 MB"; Security 3.4 membatasi format
     * yang sama secara global. Aturannya sama persis dengan pengunggahan saat
     * pencatatan, hanya jalur masuknya yang berbeda.
     *
     * @throws ValidationException
     */
    protected function guardFile(UploadedFile $file): void
    {
        validator(
            ['proof' => $file],
            ['proof' => [
                'required',
                'file',
                'max:'.PaymentRecorder::PROOF_MAX_KILOBYTES,
                'mimetypes:'.implode(',', PaymentRecorder::PROOF_MIME_TYPES),
            ]],
            [
                'proof.max' => 'Bukti pembayaran maksimal 5 MB.',
                'proof.mimetypes' => 'Bukti pembayaran harus berformat JPG, PNG, atau PDF.',
            ],
        )->validate();
    }

    /**
     * Nama berkas dibuat sendiri sebagai UUID; nama unggahan pengguna tidak
     * pernah dipakai untuk membentuk jalur (butir 63). Ekstensinya diturunkan
     * dari MIME yang sudah tervalidasi, bukan dari nama berkas kiriman.
     */
    protected function store(UploadedFile $file, int $schoolId): string
    {
        $extension = match ($file->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'pdf',
        };

        return $file->storeAs(
            PaymentRecorder::proofDirectory($schoolId),
            Str::uuid().'.'.$extension,
            ['disk' => PaymentRecorder::PROOF_DISK]
        );
    }
}
