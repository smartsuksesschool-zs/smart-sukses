<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Locale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * AUTH-05 — pemilih bahasa ID/EN.
 *
 * Satu rute untuk tamu maupun pengguna yang login, karena tombolnya sama dan
 * memisahkannya hanya akan melahirkan dua jalur yang perlahan berbeda:
 *
 * - **Tamu** tidak punya baris untuk menyimpan preferensi, jadi pilihannya
 *   masuk ke sesi. Tanpa autentikasi, tanpa baris database (butir 379).
 * - **Pengguna yang login** menyimpannya di `users.locale` supaya pilihannya
 *   ikut ke perangkat lain. Yang ditulis **selalu** `$request->user()` —
 *   tidak ada id pengguna di URL maupun di badan request, sehingga tidak ada
 *   bentuk permintaan apa pun yang dapat mengubah bahasa akun orang lain
 *   (butir 380).
 *
 * Nilai bahasanya disaring `Locale::sanitize()` — bahasa yang tidak dikenal
 * jatuh ke Indonesia tanpa galat. Ini penting bukan hanya demi kerapian: nilai
 * locale ikut menentukan berkas terjemahan yang dimuat Laravel, dan nilai
 * sembarang dari URL tidak boleh pernah sampai ke sana (butir 377).
 */
class LocaleController extends Controller
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        $locale = Locale::sanitize($locale);

        // Sesi tetap ditulis untuk keduanya: pengguna yang login lalu keluar
        // tidak tiba-tiba kembali ke bahasa lain di halaman masuk.
        $request->session()->put(Locale::sessionKey(), $locale);

        $user = $request->user();

        if ($user instanceof User) {
            $user->forceFill(['locale' => $locale])->save();
        }

        // Kembali ke halaman asal, dan bila tidak diketahui ke halaman muka.
        // `back()` di sini aman: tujuannya berasal dari header Referer yang
        // sudah divalidasi Laravel terhadap host aplikasi sendiri.
        return back(fallback: route('landing'));
    }
}
