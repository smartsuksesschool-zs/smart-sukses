<?php

namespace App\Models;

use App\Enums\SiteBlockType;
use App\Support\ReplacedMedia;
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
        $path = ReplacedMedia::within($this->image_path, SiteSetting::MEDIA_DIRECTORY);

        // Kolom yang terisi tidak cukup. Berkas yang tercatat tetapi hilang —
        // di Railway: setiap redeploy tanpa Volume — dirender sebagai "belum
        // ada foto", bukan gambar rusak di halaman yang dibuka tamu (butir 587).
        if ($path === null || ! Storage::disk(SiteSetting::MEDIA_DISK)->exists($path)) {
            return null;
        }

        return Storage::disk(SiteSetting::MEDIA_DISK)->url($path);
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
     *
     * Penghapusan tidak pernah keluar dari `site/` — sebuah nilai seperti
     * `../../.env` tidak menghapus apa pun, ia hanya ditolak (butir 474). Sejak
     * butir 587 berkas lama dibuang sesudah penggantinya tersimpan, bukan
     * sebelumnya, lewat pagar yang sama dengan kolom berkas lainnya.
     */
    protected static function booted(): void
    {
        static::updated(fn (self $block) => ReplacedMedia::afterUpdate(
            $block, 'image_path', SiteSetting::MEDIA_DISK, SiteSetting::MEDIA_DIRECTORY,
        ));

        static::deleted(fn (self $block) => ReplacedMedia::afterDelete(
            $block, 'image_path', SiteSetting::MEDIA_DISK, SiteSetting::MEDIA_DIRECTORY,
        ));
    }
}
