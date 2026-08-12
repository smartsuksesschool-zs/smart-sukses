<?php

use App\Enums\ReportCardPdfStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Di luar ERD 2.2 — konsekuensi tech stack 3.1 yang menempatkan "PDF rapor"
 * sebagai background job dan 03-deployment.md yang menyediakan worker
 * supervisor untuk itu.
 *
 * Generate PDF sekelas berjalan asinkron, sehingga hasilnya perlu tempat
 * tinggal: tanpa kolom ini worker tidak punya cara memberi tahu UI bahwa
 * berkasnya sudah jadi. Unduhan satuan tetap sinkron dan tidak memakai kolom
 * ini sama sekali.
 *
 * Lihat docs/implementation-notes.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_cards', function (Blueprint $table) {
            // Relatif terhadap disk penyimpanan, contoh: rapor/1/rapor_12.pdf
            $table->string('pdf_path', 255)->nullable()->after('published_by');

            // NULL = belum pernah diminta. Lihat App\Enums\ReportCardPdfStatus.
            $table->enum('pdf_status', array_keys(ReportCardPdfStatus::options()))
                ->nullable()
                ->after('pdf_path');

            // Kapan berkas yang sekarang tersimpan selesai dibuat.
            $table->timestamp('pdf_generated_at')->nullable()->after('pdf_status');

            $table->index('pdf_status');
        });
    }

    public function down(): void
    {
        Schema::table('report_cards', function (Blueprint $table) {
            $table->dropIndex(['pdf_status']);
            $table->dropColumn(['pdf_path', 'pdf_status', 'pdf_generated_at']);
        });
    }
};
