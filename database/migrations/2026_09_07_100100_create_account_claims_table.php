<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permintaan akun — di luar ERD 2.2, keputusan pemilik (M7 / M7.1).
 *
 * Sebuah baris di sini berarti: *"identitas Google ini meminta akses sebagai
 * jenis pemohon ini"*. Ia bukan akun, dan sengaja bukan akun.
 *
 * Untuk siswa dan orang tua, yang dibuktikan Google hanyalah penguasaan sebuah
 * kotak surel, dan yang dibuktikan NIS/NISN hanyalah bahwa seseorang
 * mengetahuinya. Untuk staf, tidak ada yang dibuktikan sama sekali di luar
 * kotak surelnya: sekolah ini tidak memegang satu pun pengenal induk pegawai
 * (butir 545). Ketiganya karena itu berhenti di sini sampai dibaca manusia
 * (butir 530).
 *
 * NIS dan NISN **tidak** disalin ke sini. Keduanya hanya dipakai sekali untuk
 * menemukan `student_id`, dan sesudah itu `student_id` sudah menyebut baris
 * induknya dengan lebih tepat daripada salinan yang dapat basi. Menyimpannya
 * berarti menaruh pengenal siswa di tabel yang diisi publik (butir 532).
 *
 * Token Google tidak disimpan sama sekali: setelah callback tidak ada satu pun
 * pemanggilan API Google yang dilakukan aplikasi ini, jadi tidak ada yang perlu
 * disimpan untuk dipakai nanti.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_claims', function (Blueprint $table) {
            $table->id();

            /*
             * Cabang selalu terisi, tetapi asalnya berbeda per jenis.
             *
             * Siswa dan orang tua: diturunkan dari siswa yang cocok — pemohon
             * tidak pernah menyebut cabang, dan tidak perlu tahu.
             *
             * Staf: dipilih pemohon dari daftar cabang aktif. Daftar itu sudah
             * publik (halaman PPDB menampilkannya), jadi tidak ada yang bocor —
             * dan tanpa cabang, barisnya tidak akan pernah terlihat oleh Admin
             * Sekolah mana pun, karena global scope tenant menyaring tepat pada
             * kolom ini (butir 546).
             */
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();

            $table->string('provider', 20);
            $table->string('provider_subject', 191);

            // Surel terverifikasi penyedia, sudah dinormalisasi (trim + huruf
            // kecil). Keterangan bagi admin yang menilai, bukan identitas.
            $table->string('email', 150);

            // Nama tampilan dari Google. Admin memerlukannya untuk menilai:
            // "Budi Santoso" yang meminta akses sebagai staf adalah keterangan,
            // sedangkan surel saja bukan.
            $table->string('name', 150)->nullable();

            /*
             * Yang **diminta** pemohon: SISWA, ORANG_TUA, atau STAF_SEKOLAH.
             *
             * Bukan sebuah peran. `STAF_SEKOLAH` tidak ada pada matriks izin dan
             * tidak dapat diberikan kepada siapa pun — ia pertanyaan, bukan
             * jawaban (butir 544).
             */
            $table->string('requested_type', 20);

            /*
             * Yang **diberikan** admin. NULL selama belum disetujui.
             *
             * Untuk siswa dan orang tua ia mengikuti jenisnya dan tidak dapat
             * dipilih. Untuk staf, admin memilihnya dari daftar putih yang tidak
             * pernah memuat SCHOOL_ADMIN maupun SUPER_ADMIN (butir 547).
             */
            $table->string('approved_role', 20)->nullable();

            /*
             * Siswa yang dirujuk — NULL untuk permintaan staf.
             *
             * Staf tidak merujuk siswa mana pun, dan mengarang sebuah rujukan
             * hanya supaya kolomnya terisi berarti menaruh hubungan yang tidak
             * ada di dalam basis data.
             */
            $table->foreignId('student_id')->nullable()->constrained('students')->cascadeOnDelete();

            $table->string('status', 20)->default('PENDING')->index();

            $table->timestamp('requested_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            // Alasan penolakan untuk catatan internal. Tidak pernah ditampilkan
            // kepada pemohon: yang ia lihat satu kalimat yang sama untuk seluruh
            // sebab (butir 533).
            $table->string('review_notes', 255)->nullable();

            // Akun yang lahir (atau dipakai ulang) saat permintaan disetujui.
            $table->foreignId('approved_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            /*
             * Dua aturan keunikan yang berbeda, ditegakkan basis data.
             *
             * MySQL dan SQLite sama-sama mengizinkan banyak baris NULL di dalam
             * satu indeks unik, sehingga kolom yang bernilai hanya selama
             * keadaannya berlaku — dan NULL sesudahnya — menghasilkan *partial
             * unique index* yang portabel.
             *
             * Keduanya berupa **kunci string**, bukan indeks komposit atas
             * kolom aslinya. Sebabnya `student_id` kini nullable: pada indeks
             * komposit, satu anggota yang NULL membuat seluruh barisnya
             * dianggap berbeda — sehingga aturannya diam-diam berhenti berlaku
             * justru untuk permintaan staf, satu-satunya yang `student_id`-nya
             * memang NULL. Kunci string menyusun ketiadaan itu menjadi nilai
             * yang tetap dibandingkan (butir 548).
             *
             * Keduanya disusun model, bukan pemanggil — lihat AccountClaim,
             * yang menurunkannya pada setiap penyimpanan supaya tidak pernah
             * dapat berselisih dengan `status` (butir 531).
             */
            $table->string('pending_key', 191)->nullable()->unique();
            $table->string('approved_key', 191)->nullable()->unique();

            $table->index(['provider', 'provider_subject'], 'account_claims_identity_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_claims');
    }
};
