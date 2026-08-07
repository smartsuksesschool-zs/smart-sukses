<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERD 2.2 — Tabel: schools.
 *
 * Tabel inti tenant (Shared Database, Shared Schema). Setiap baris mewakili
 * satu cabang sekolah dan menyimpan konfigurasi white-label cabang tersebut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('code', 20)->unique();
            $table->string('slug', 50)->unique();
            $table->string('logo_url', 500)->nullable();
            $table->string('primary_color', 7)->default('#1B3A6B');
            $table->string('secondary_color', 7)->default('#E07020');
            $table->text('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('head_name', 150)->nullable();
            $table->text('wa_template_ppdb')->nullable();
            $table->text('wa_template_spp')->nullable();
            $table->text('wa_template_rapor')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};
