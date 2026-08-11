<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unique index yang tidak tercantum ERD 2.2 (subjects.code hanya ditandai biasa).
 *
 * Diperlukan karena ERD 2.2 menetapkan `report_cards.final_scores` berbentuk
 * {"MTK": 87.5, "BIN": 90} — kunci JSON memakai kode mata pelajaran. Tanpa
 * keunikan kode per cabang, dua mapel berkode sama akan saling menimpa di
 * dalam rapor. Preseden: Sprint 2 butir 6.
 *
 * Lihat docs/implementation-notes.md butir 29.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->unique(['school_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropUnique(['school_id', 'code']);
        });
    }
};
