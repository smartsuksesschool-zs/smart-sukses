<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERD 2.2 — Tabel: users.
 *
 * Melengkapi tabel users bawaan Laravel agar sesuai ERD: kolom tenant
 * (school_id, NULL untuk Super Admin) dan kolom profil pengguna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('school_id')
                ->nullable()
                ->after('id')
                ->constrained('schools')
                ->nullOnDelete();

            $table->string('phone', 20)->nullable()->after('password');
            $table->string('avatar_url', 500)->nullable()->after('phone');
            $table->string('locale', 5)->default('id')->after('avatar_url');
            $table->boolean('is_active')->default(true)->after('locale')->index();
            $table->timestamp('last_login_at')->nullable()->after('email_verified_at');

            // PENYIMPANGAN DARI ERD — disengaja.
            //
            // Kolom ini TIDAK ada pada ERD 2.2 (smartsukses-docs/02-erd/02-tables-core.md).
            // Ditambahkan untuk memenuhi requirement keamanan pada
            // smartsukses-docs/03-architecture/04-security.md, baris "Password":
            //   "Argon2id (Laravel default) | Minimum 8 karakter;
            //    password pertama wajib diganti saat login pertama."
            //
            // ERD belum menyediakan atribut apa pun yang merepresentasikan status
            // "wajib ganti password", sedangkan requirement tersebut menuntut penanda
            // persisten per pengguna (bertahan lintas sesi & lintas perangkat).
            // Penegakannya: App\Http\Middleware\EnsurePasswordIsChanged serta aksi
            // "Reset Password" pada App\Filament\Resources\UserResource.
            //
            // Catatan penyimpangan selengkapnya: docs/implementation-notes.md
            $table->boolean('must_change_password')->default(false)->after('password');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('name', 150)->change();
            $table->string('email', 150)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_id');
            $table->dropColumn([
                'phone',
                'avatar_url',
                'locale',
                'is_active',
                'last_login_at',
                'must_change_password',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->change();
            $table->string('email')->change();
        });
    }
};
