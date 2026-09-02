<?php

namespace App\Models;

use App\Enums\SiteBlockType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Satu kartu isi halaman muka publik: unit pendidikan, program, foto kegiatan,
 * atau pratinjau artikel.
 *
 * Global, tanpa SchoolScope — alasannya sama persis dengan SiteSetting
 * (butir 464).
 */
class SiteBlock extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'title',
        'subtitle',
        'body',
        'image_path',
        'link_url',
        'position',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'type' => SiteBlockType::class,
            'position' => 'integer',
            'is_published' => 'boolean',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function scopeOfType(Builder $query, SiteBlockType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    /**
     * Urutan tampil: posisi yang disetel admin, lalu id sebagai pemutus seri.
     *
     * Tanpa pemutus seri, dua blok berposisi sama akan bertukar tempat antar
     * request menurut kehendak MySQL, dan halaman muka tampak berubah sendiri.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    /**
     * URL gambar, atau NULL bila fotonya memang belum ada.
     *
     * NULL bukan kegagalan: foto kegiatan sungguhan Smart Sukses School belum
     * diserahkan, jadi keadaan "belum ada foto" adalah keadaan normal yang
     * harus dirender rapi, bukan dihindari dengan gambar orang lain
     * (butir 467).
     */
    public function imageUrl(): ?string
    {
        if (blank($this->image_path)) {
            return null;
        }

        return Storage::disk(SiteSetting::MEDIA_DISK)->url($this->image_path);
    }

    public function hasImage(): bool
    {
        return $this->imageUrl() !== null;
    }

    /**
     * Berkas gambar mengikuti nasib barisnya.
     *
     * Tanpa ini disk publik menumpuk berkas yatim: setiap penggantian foto
     * meninggalkan berkas lama yang tidak lagi dirujuk siapa pun, tetap dapat
     * diunduh siapa pun yang pernah menyimpan alamatnya, dan tidak ada satu
     * pun tempat di antarmuka untuk membuangnya.
     */
    protected static function booted(): void
    {
        static::updating(function (self $block): void {
            if (! $block->isDirty('image_path')) {
                return;
            }

            $block->deleteStoredImage($block->getOriginal('image_path'));
        });

        static::deleted(fn (self $block) => $block->deleteStoredImage($block->image_path));
    }

    /**
     * Menghapus satu berkas, hanya bila ia memang berada di dalam direktori
     * media halaman muka.
     *
     * Path-nya berasal dari kolom basis data, dan kolom basis data dapat diisi
     * dari mana saja seiring waktu. Pagar ini memastikan penghapusan tidak
     * pernah keluar dari `site/` — sebuah nilai seperti `../../.env` tidak
     * menghapus apa pun, ia hanya ditolak (butir 474).
     */
    protected function deleteStoredImage(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        $prefix = SiteSetting::MEDIA_DIRECTORY.'/';

        if (! str_starts_with($path, $prefix) || str_contains($path, '..')) {
            return;
        }

        Storage::disk(SiteSetting::MEDIA_DISK)->delete($path);
    }
}
