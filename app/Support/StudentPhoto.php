<?php

namespace App\Support;

use App\Models\Student;
use Illuminate\Support\Facades\Storage;

/**
 * Foto siswa — penyimpanan privat dan satu-satunya jalan menuju berkasnya.
 *
 * Sejak Sprint 2 foto siswa disimpan di disk `public` dengan nama acak, sebagai
 * penyimpangan yang dicatat dan ditunda (butir 17, 42). Setelah `storage:link`,
 * siapa pun yang memegang URL-nya dapat membukanya tanpa login — dan itu foto
 * anak di bawah umur. Railway Volume untuk media publik akan membuat berkas itu
 * menetap, jadi penundaannya berakhir di sini (butir 587).
 *
 * Disknya dapat dikonfigurasi dengan alasan yang sama seperti bukti pembayaran
 * (butir 585), dan dibaca lewat config — tidak pernah `env()` dari kelas ini.
 *
 * Direktorinya sengaja **baru** (`student-photos/`), bukan `students/` lama:
 * jalur lama menunjuk disk publik, dan tidak satu pun darinya boleh dianggap
 * sah di disk privat. Baris lama dengan jalur `students/…` diperlakukan sebagai
 * belum punya foto — bukan disajikan dari salinan publik yang tertinggal.
 */
class StudentPhoto
{
    /** Disk privat bawaan; berakar di `storage/app/private`. */
    public const DISK = 'local';

    public const DIRECTORY = 'student-photos';

    /**
     * Ekstensi yang dapat lahir dari unggahan SIS-03 (JPG/PNG/WEBP). Jalur
     * dengan ekstensi lain tidak pernah disajikan, apa pun isi kolomnya.
     */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Nilai kosong (`STUDENT_PHOTO_DISK=`) berarti bawaan, bukan string kosong:
     * `Storage::disk('')` diam-diam jatuh ke `FILESYSTEM_DISK`, dan foto anak
     * tidak boleh ikut ke mana pun disk bawaan itu kelak diarahkan.
     */
    public static function disk(): string
    {
        $disk = config('storage.student_photo_disk');

        return is_string($disk) && $disk !== '' ? $disk : self::DISK;
    }

    /**
     * Jalur yang sah, atau NULL bila nilainya tidak dapat dipercaya.
     *
     * Nilainya berasal dari basis data, bukan dari request — pagarnya tetap
     * dipasang dengan alasan yang sama seperti PpdbDocument::sanitise().
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

        if (! str_starts_with($path, self::DIRECTORY.'/')) {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, self::EXTENSIONS, true) ? $path : null;
    }

    /**
     * Jalur foto siswa ini bila berkasnya benar-benar ada, atau NULL.
     *
     * Kolom yang terisi tidak cukup: berkas yang tercatat tetapi hilang dari
     * penyimpanan harus berbunyi "belum ada foto", bukan gambar rusak.
     */
    public static function storedPath(Student $student): ?string
    {
        $path = self::sanitise($student->photo_url);

        if ($path === null) {
            return null;
        }

        return Storage::disk(self::disk())->exists($path) ? $path : null;
    }

    /**
     * URL rute berwenang untuk foto ini, atau NULL bila tidak ada foto.
     *
     * Tidak pernah `Storage::url()` dan tidak pernah URL bertanda tangan:
     * keduanya dapat dibuka siapa pun yang memegangnya. Rute ini melewati sesi
     * panel dan `StudentPolicy::view` pada setiap permintaan.
     */
    public static function url(Student $student): ?string
    {
        if (self::storedPath($student) === null) {
            return null;
        }

        return route('filament.admin.students.photo', [
            'student' => $student->getKey(),
            // Pemecah cache: foto yang diganti mendapat URL baru, sehingga
            // peramban tidak menampilkan salinan lama dari cache privatnya.
            'v' => substr(sha1((string) $student->photo_url), 0, 12),
        ]);
    }
}
