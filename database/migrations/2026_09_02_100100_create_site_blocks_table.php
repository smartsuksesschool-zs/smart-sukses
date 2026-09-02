<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Isi berulang halaman muka publik: unit pendidikan, program, galeri kegiatan,
 * dan pratinjau artikel.
 *
 * Keempatnya punya bentuk yang sama persis — judul, keterangan, gambar,
 * tautan, urutan, terbit/tidak — sehingga dipisah menjadi empat tabel hanya
 * akan menyalin skema yang identik empat kali beserta empat resource Filament
 * yang identik pula. Yang membedakan hanya `type`, dan itu satu kolom
 * (butir 465).
 *
 * Sama seperti `site_settings`: tanpa `school_id`, isi payung, bukan isi
 * cabang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_blocks', function (Blueprint $table) {
            $table->id();

            // 'unit' | 'program' | 'gallery' | 'article' — divalidasi enum
            // aplikasi (App\Enums\SiteBlockType), bukan ENUM MySQL: menambah
            // jenis baru lewat ENUM menuntut ALTER TABLE pada tabel yang
            // sedang dibaca halaman publik.
            $table->string('type', 30);

            $table->string('title', 150);
            $table->string('subtitle', 150)->nullable();
            $table->text('body')->nullable();

            // **Path, bukan bytes.** Berkasnya di disk `public`; kolom ini hanya
            // menyimpan kuncinya. NULL berarti "foto belum ada", dan halaman
            // merender placeholder yang jelas — bukan kotak kosong (butir 467).
            $table->string('image_path', 500)->nullable();

            $table->string('link_url', 500)->nullable();

            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_published')->default(true);

            $table->timestamps();

            // Satu-satunya query halaman muka: per jenis, yang terbit saja,
            // urut posisi.
            $table->index(['type', 'is_published', 'position'], 'site_blocks_listing_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_blocks');
    }
};
