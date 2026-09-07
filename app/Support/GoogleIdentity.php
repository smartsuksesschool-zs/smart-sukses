<?php

namespace App\Support;

use Illuminate\Support\Facades\Session;

/**
 * Identitas Google yang **sudah terbukti**, dibawa dari callback ke formulir.
 *
 * Antara callback OAuth dan pengiriman NIS/NISN, pemohon belum punya akun dan
 * karena itu belum dapat diautentikasi. Yang dititipkan ke sesi hanya tiga
 * nilai ini — tanpa token akses, tanpa token penyegar, tanpa satu pun kredensial
 * Google. Setelah callback, aplikasi ini tidak pernah memanggil API Google lagi,
 * jadi tidak ada satu pun yang perlu disimpan untuk dipakai nanti (butir 532).
 *
 * `subject` adalah `sub` dari Google: pengenal yang tidak pernah dipakai ulang,
 * bahkan setelah akunnya dihapus. Surel bukan pengenal — alamat Workspace yang
 * dilepas dapat diberikan kepada karyawan berikutnya — jadi surel hanya
 * disimpan sebagai keterangan.
 */
final class GoogleIdentity
{
    public const SESSION_KEY = 'oauth.google.identity';

    public function __construct(
        public readonly string $subject,
        public readonly string $email,
        public readonly ?string $name,
    ) {}

    /**
     * Trim + huruf kecil.
     *
     * Google mengembalikan alamat dalam bentuk kanoniknya, tetapi bentuk itu
     * bukan sesuatu yang dijanjikan kepada siapa pun. Menormalkannya di satu
     * tempat membuat pencarian akun tidak pernah bergantung pada kapitalisasi
     * yang kebetulan diterima.
     */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function putInSession(): void
    {
        Session::put(self::SESSION_KEY, [
            'subject' => $this->subject,
            'email' => $this->email,
            'name' => $this->name,
        ]);
    }

    public static function fromSession(): ?self
    {
        $data = Session::get(self::SESSION_KEY);

        if (! is_array($data)) {
            return null;
        }

        $subject = $data['subject'] ?? null;
        $email = $data['email'] ?? null;

        if (! is_string($subject) || $subject === '' || ! is_string($email) || $email === '') {
            return null;
        }

        $name = $data['name'] ?? null;

        return new self($subject, $email, is_string($name) ? $name : null);
    }

    public static function forgetSession(): void
    {
        Session::forget(self::SESSION_KEY);
    }
}
