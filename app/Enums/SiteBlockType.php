<?php

namespace App\Enums;

/**
 * Jenis blok isi halaman muka publik.
 *
 * Keempatnya berbagi satu tabel karena bentuknya memang sama; enum inilah yang
 * membedakannya, dan enum aplikasi — bukan ENUM MySQL — supaya menambah jenis
 * kelima tidak menuntut ALTER TABLE pada tabel yang sedang dibaca publik
 * (butir 465).
 */
enum SiteBlockType: string
{
    /** Unit pendidikan di bawah payung Smart Sukses School (Smart Building, Smart Bee). */
    case Unit = 'unit';

    /** Program atau pendekatan pendidikan yang ditawarkan. */
    case Program = 'program';

    /** Foto kegiatan untuk bagian "Kehidupan di Smart Sukses School". */
    case Gallery = 'gallery';

    /** Pratinjau artikel yang tautannya menuju blog WordPress. */
    case Article = 'article';

    public function label(): string
    {
        return match ($this) {
            self::Unit => __('Unit Pendidikan'),
            self::Program => __('Program'),
            self::Gallery => __('Galeri Kegiatan'),
            self::Article => __('Artikel'),
        };
    }

    /**
     * Apakah jenis ini memang bergambar.
     *
     * Menentukan placeholder foto ditampilkan atau tidak: kartu program tanpa
     * gambar memang sudah utuh, sedangkan galeri tanpa gambar adalah lubang.
     */
    public function expectsImage(): bool
    {
        return match ($this) {
            self::Gallery, self::Unit, self::Article => true,
            self::Program => false,
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
