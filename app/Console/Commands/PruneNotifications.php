<?php

namespace App\Console\Commands;

use App\Services\Notification\NotificationRetentionService;
use Illuminate\Console\Command;

/**
 * NOTIF-04 poin 3 — memangkas riwayat notifikasi yang sudah melewati 90 hari.
 *
 * Perintah ini tidak memuat satu pun aturan retensi; seluruhnya milik
 * NotificationRetentionService. Yang dikerjakannya hanya memanggil service itu
 * dan melaporkan hasilnya, sehingga penjadwal, CLI, dan test tidak dapat
 * berbeda pendapat tentang apa yang sudah lewat (butir 255).
 *
 * Ia pemeliharaan sistem: berjalan lintas cabang, tanpa pengguna yang login,
 * dan tanpa konteks tenant. Aman dijalankan ulang kapan saja — pemanggilan
 * kedua tidak menemukan apa pun dan tetap berhasil (butir 256).
 */
class PruneNotifications extends Command
{
    protected $signature = 'notifications:prune';

    protected $description = 'Menghapus riwayat notifikasi yang sudah melewati masa simpan 90 hari (NOTIF-04).';

    public function handle(NotificationRetentionService $retention): int
    {
        $cutoff = $retention->cutoff();
        $deleted = $retention->prune();

        $this->info(sprintf(
            '%d notifikasi dihapus (terkirim sebelum %s).',
            $deleted,
            $cutoff->toDateTimeString(),
        ));

        // Tidak ada yang perlu dihapus bukan kegagalan: itu keadaan yang wajar
        // pada pemasangan baru dan pada hari mana pun setelah pemangkasan
        // pertama.
        return self::SUCCESS;
    }
}
