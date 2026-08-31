<?php

namespace App\Support;

use App\Models\PpdbRegistration;
use App\Models\School;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Berkas pendukung PPDB — penyimpanan privat dan pemetaan kunci ke jalur.
 *
 * Berkas ini semula disimpan di disk `public`. Setelah `storage:link`, isinya
 * dilayani langsung oleh web server tanpa autentikasi: siapa pun yang
 * mengetahui atau menebak jalurnya dapat mengunduhnya. Untuk berkas pendaftaran
 * sungguhan — kartu keluarga, akta kelahiran, dokumen identitas orang tua — itu
 * bukan tempat yang benar (M-1, docs/data-migration-readiness.md §11).
 *
 * Yang dipakai sekarang disk yang sama dengan bukti pembayaran (SPP-03) dan
 * bukti transaksi kas (KAS-01): `local`, yang berakar di `storage/app/private`
 * dan tidak pernah tersentuh `storage:link`. Pagar jalurnya mengikuti pola
 * App\Support\ProofPath, dan nama berkas unduhannya dibentuk dari data
 * pendaftaran seperti TransactionResource::proofFilenameFor() — bukan dari nama
 * unggahan asli, dan bukan dari jalur penyimpanannya.
 *
 * Direktorinya sengaja **tidak** berubah. Kolom `documents` menyimpan jalur apa
 * adanya tanpa keterangan disk, sehingga memindahkan berkas antar disk tidak
 * menuntut perubahan skema maupun penulisan ulang basis data — cukup berkasnya
 * yang pindah (butir 411).
 */
class PpdbDocument
{
    /** Disk privat; berakar di `storage/app/private`. */
    public const DISK = 'local';

    /**
     * Disk lama. Baris yang ditulis sebelum batch ini masih menunjuk ke sini,
     * dan tetap dapat diunduh lewat rute berwenang sampai
     * `ppdb:privatize-documents` dijalankan.
     */
    public const LEGACY_DISK = 'public';

    public const DIRECTORY = 'ppdb';

    public static function directoryFor(School $school): string
    {
        return self::DIRECTORY.'/'.Str::lower($school->code);
    }

    /**
     * Jalur yang sah, atau NULL bila nilainya tidak dapat dipercaya.
     *
     * Nilainya berasal dari basis data, bukan dari request — tetapi pagar ini
     * tetap dipasang: satu-satunya hal yang memisahkan `documents` dari
     * pembacaan berkas sembarang adalah asumsi bahwa isinya selalu benar, dan
     * asumsi bukan pagar.
     */
    public static function sanitise(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $path = str_replace('\\', '/', trim($path));

        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
            return null;
        }

        return str_starts_with($path, self::DIRECTORY.'/') ? $path : null;
    }

    /**
     * Jalur berkas ke-`$key` milik satu pendaftaran, atau NULL bila kuncinya
     * tidak ada.
     *
     * Kuncinya adalah indeks di dalam `documents` milik pendaftaran itu
     * sendiri, jadi jalur yang diunduh tidak pernah dirakit dari input request.
     */
    public static function pathFor(PpdbRegistration $registration, int $key): ?string
    {
        $documents = $registration->documents ?? [];

        if (! is_array($documents) || ! array_key_exists($key, $documents)) {
            return null;
        }

        return self::sanitise($documents[$key]);
    }

    /**
     * Disk tempat berkas itu benar-benar ada, atau NULL bila tidak ada di mana
     * pun. Disk privat diperiksa lebih dulu supaya berkas yang sudah dipindah
     * tidak pernah dilayani dari salinan publik yang tertinggal.
     */
    public static function diskFor(string $path): ?string
    {
        foreach ([self::DISK, self::LEGACY_DISK] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return $disk;
            }
        }

        return null;
    }

    /**
     * Nama berkas unduhan. Dibentuk dari nomor pendaftaran dan urutan berkas —
     * nama unggahan asli tidak pernah disimpan, dan nama acak di penyimpanan
     * tidak perlu diketahui siapa pun di luar server.
     */
    public static function filenameFor(PpdbRegistration $registration, int $key, string $path): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $name = Str::lower((string) $registration->reg_number).'-berkas-'.($key + 1);

        return $extension === '' ? $name : "{$name}.{$extension}";
    }
}
