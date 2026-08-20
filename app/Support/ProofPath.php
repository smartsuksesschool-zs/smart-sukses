<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Pemeriksa jalur berkas bukti yang dikirim form.
 *
 * Dipakai bersama oleh bukti pembayaran (SPP-03) dan bukti transaksi kas
 * (KAS-01). Keduanya menyimpan berkas di disk privat dengan direktori terpisah
 * per cabang, dan keduanya menghadapi masalah yang sama: nilai yang sampai ke
 * server berasal dari state Livewire dan dapat dirakit sendiri, sehingga jalur
 * apa pun bisa menunjuk berkas cabang lain atau menyusup keluar direktori.
 *
 * Disatukan di sini, bukan disalin, karena dua salinan sebuah pagar keamanan
 * cepat atau lambat akan berbeda — dan yang tertinggal justru yang tidak
 * diperbaiki.
 */
class ProofPath
{
    /**
     * Jalur berkas yang sah, atau NULL bila tidak ada berkas yang diunggah.
     *
     * @param  string  $prefix  Direktori yang boleh memuat berkas ini, mis.
     *                          `payment-proofs/7`.
     * @param  string  $errorKey  Nama field untuk pesan validasinya.
     *
     * @throws ValidationException
     */
    public static function resolve(mixed $value, string $prefix, string $errorKey): ?string
    {
        if (is_array($value)) {
            // FileUpload menyerahkan state-nya sebagai array berkunci hash.
            $value = collect($value)->filter()->first();
        }

        if (! is_string($value)) {
            return null;
        }

        $path = trim($value);

        if ($path === '') {
            return null;
        }

        $path = str_replace('\\', '/', $path);
        $prefix = rtrim($prefix, '/').'/';

        if (! str_starts_with($path, $prefix) || str_contains($path, '..')) {
            throw ValidationException::withMessages([
                $errorKey => 'Berkas bukti tidak valid.',
            ]);
        }

        return $path;
    }
}
