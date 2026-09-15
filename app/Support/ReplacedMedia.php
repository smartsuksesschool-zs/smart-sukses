<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Membuang berkas lama sebuah kolom berkas setelah penggantinya tersimpan.
 *
 * Tanpa ini setiap penggantian logo, foto profil, atau foto siswa meninggalkan
 * berkas yatim: tidak dirujuk siapa pun, tidak dapat dibuang dari antarmuka,
 * dan — begitu media publik tinggal di Railway Volume — terus memakan ruang
 * yang ukurannya terbatas. Untuk foto siswa lebih buruk lagi: foto anak yang
 * sudah diganti tetap tersimpan tanpa alasan (butir 587).
 *
 * Pagarnya, dan masing-masing ada karena satu cara salah hapus:
 *
 *  - dipanggil dari event `updated`/`deleted`, dan penghapusannya ditunda
 *    sampai transaksi selesai — berkas lama tidak pernah hilang lebih dulu
 *    dari baris yang merujuknya;
 *  - hanya jalur di dalam direktori milik kolom itu yang dapat dihapus, dan
 *    `..` ditolak — nilai kolom tidak pernah menjadi perintah hapus
 *    sembarang (butir 474);
 *  - URL penuh tidak pernah dihapus — itu bukan berkas milik disk ini;
 *  - berkas yang masih dirujuk baris lain, di cabang mana pun, tidak dihapus.
 *
 * Tidak ada penyapuan massal. Yang dibuang hanya berkas yang baru saja
 * dilepaskan oleh satu baris.
 */
class ReplacedMedia
{
    /**
     * Setelah `$column` berganti nilai, buang berkas yang ditinggalkannya.
     */
    public static function afterUpdate(Model $model, string $column, string $disk, string $directory): void
    {
        if (! $model->wasChanged($column)) {
            return;
        }

        self::discard($model, $column, $model->getOriginal($column), $disk, $directory);
    }

    /**
     * Setelah barisnya dihapus, buang berkas yang dirujuknya.
     */
    public static function afterDelete(Model $model, string $column, string $disk, string $directory): void
    {
        self::discard($model, $column, $model->getAttribute($column), $disk, $directory);
    }

    /**
     * Apakah `$path` berada di dalam `$directory` dan aman dijadikan sasaran
     * penghapusan.
     */
    public static function within(mixed $path, string $directory): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        if (str_contains($path, '..') || str_contains($path, '\\') || str_starts_with($path, '/')) {
            return null;
        }

        return str_starts_with($path, rtrim($directory, '/').'/') ? $path : null;
    }

    protected static function discard(Model $model, string $column, mixed $path, string $disk, string $directory): void
    {
        $path = self::within($path, $directory);

        if ($path === null) {
            return;
        }

        $query = $model->newQueryWithoutScopes()->where($column, $path);

        DB::afterCommit(function () use ($query, $disk, $path): void {
            // Diperiksa sesudah transaksi, pada basis data yang sudah final:
            // baris lain yang merujuk berkas yang sama menahannya.
            if ($query->exists()) {
                return;
            }

            Storage::disk($disk)->delete($path);
        });
    }
}
