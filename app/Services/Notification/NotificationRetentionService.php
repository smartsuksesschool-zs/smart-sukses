<?php

namespace App\Services\Notification;

use App\Models\Notification;
use App\Models\Scopes\SchoolScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * NOTIF-04 poin 3 — "Riwayat notifikasi tersimpan 90 hari."
 *
 * Kalimat itu seluruh sumbernya. Blueprint menyebut lamanya penyimpanan dan
 * tidak menyebut satu pun mekanismenya: tidak ada penjadwal, perintah, job,
 * maupun penyaring yang dituliskan di mana pun. Cara pemangkasannya karena itu
 * **penafsiran implementasi**, dan seluruh keputusan di kelas ini dicatat
 * sebagai penafsiran — bukan keputusan pemilik (butir 252).
 *
 * Satu-satunya tempat aturan retensi ditulis. Perintah artisan, penjadwal, dan
 * test memanggilnya; tidak ada satu pun ambang 90 hari kedua yang tersebar di
 * controller, portal, atau panel (butir 255).
 */
class NotificationRetentionService
{
    /** NOTIF-04 poin 3 — satu-satunya angka yang disebut sumber. */
    public const RETENTION_DAYS = 90;

    /**
     * Banyaknya notifikasi yang dihapus dalam satu putaran.
     *
     * Riwayat lama bisa panjang, dan menariknya sekaligus ke PHP berarti
     * pemakaian memori yang tumbuh bersama umur pemasangan (butir 257).
     */
    protected const CHUNK = 500;

    /**
     * Dibaca lewat `static::` supaya besarnya dapat diturunkan pada subclass.
     * `self::` akan mengikat ke kelas ini pada waktu kompilasi, sehingga test
     * yang menurunkannya akan tetap berjalan satu putaran — dan membuktikan
     * sesuatu yang tidak sedang diuji.
     */
    protected function chunkSize(): int
    {
        return static::CHUNK;
    }

    /**
     * Batas waktu penyimpanan.
     *
     * Dihitung dari `sent_at`, bukan `created_at` maupun `updated_at`. Yang
     * disimpan 90 hari adalah **riwayat yang diterima penerima**, jadi yang
     * menghitung mundur adalah saat pengumumannya terbit. `updated_at`
     * khususnya tidak boleh dipakai: menandai notifikasi terbaca atau menyentuh
     * barisnya karena alasan teknis apa pun akan memperpanjang umurnya, dan
     * retensi menjadi bergantung pada aktivitas, bukan pada waktu (butir 253).
     */
    public function cutoff(?CarbonInterface $now = null): CarbonImmutable
    {
        return CarbonImmutable::instance($now ?? now())->subDays(self::RETENTION_DAYS);
    }

    /**
     * Notifikasi yang sudah melewati masa simpannya.
     *
     * Batasnya **inklusif terhadap yang disimpan**: notifikasi yang `sent_at`-nya
     * tepat di titik batas masih berada di dalam hari ke-90 dan karena itu
     * tetap ada. Hanya yang benar-benar lebih tua yang dihapus (butir 253).
     *
     * Draf tidak pernah ikut. Draf belum pernah sampai kepada siapa pun,
     * sehingga tidak ada "riwayat" yang sedang disimpan untuknya, dan
     * menghapus pekerjaan Admin yang belum selesai semata karena tanggalnya tua
     * adalah kerusakan, bukan pembersihan (butir 254).
     *
     * Query-nya melepas SchoolScope: pemangkasan adalah pemeliharaan sistem
     * lintas cabang, dan di dalam perintah CLI tidak ada pengguna yang dapat
     * menyimpulkan cabang (butir 256).
     *
     * @return Builder<Notification>
     */
    public function eligible(?CarbonInterface $now = null): Builder
    {
        return Notification::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('is_draft', false)
            ->whereNotNull('sent_at')
            ->where('sent_at', '<', $this->cutoff($now));
    }

    /** Berapa banyak yang akan dihapus, tanpa menghapus apa pun. */
    public function eligibleCount(?CarbonInterface $now = null): int
    {
        return $this->eligible($now)->count();
    }

    /**
     * Menghapus seluruh riwayat yang sudah lewat, dan mengembalikan jumlahnya.
     *
     * Aman dijalankan ulang: putaran kedua tidak menemukan apa pun dan
     * mengembalikan nol.
     *
     * Penghapusannya lewat model, bukan `delete()` massal query builder, dan itu
     * disengaja. Listener CUD di AppServiceProvider mendengarkan
     * `eloquent.deleted`, sedangkan penghapusan massal lewat query builder tidak
     * memicu model event sama sekali (butir 46) — memakainya berarti
     * pemangkasan tidak meninggalkan jejak sedikit pun, padahal Security 3.4
     * meminta seluruh aksi CUD tercatat. Barisnya tercatat dengan `user_id`
     * NULL, yang memang keadaan sebenarnya dan memang sudah disediakan skema
     * audit untuk perintah CLI (butir 258).
     *
     * `notification_reads` ikut terhapus lewat `cascadeOnDelete()` yang sudah
     * ada pada foreign key-nya sejak Batch 8.1 — bukan lewat penghapusan kedua
     * yang ditulis di sini, yang justru dapat berbeda dari aturan basis datanya.
     */
    public function prune(?CarbonInterface $now = null): int
    {
        $deleted = 0;

        // Sengaja bukan `chunkById()`: baris yang dibacanya ikut dihapus dalam
        // putaran yang sama, dan paging di atas himpunan yang menyusut selalu
        // berisiko melewatkan baris. Yang dipakai: ambil sekumpulan id, hapus,
        // ulangi sampai tidak ada lagi yang memenuhi syarat.
        while (true) {
            $ids = $this->eligible($now)
                ->orderBy('id')
                ->limit($this->chunkSize())
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += $this->deleteChunk($ids->all());
        }

        return $deleted;
    }

    /**
     * Satu putaran penghapusan, satu transaksi.
     *
     * Batas transaksinya per putaran, bukan satu untuk seluruh pemangkasan:
     * satu transaksi raksasa akan menahan kunci selama berjam-jam pada
     * pemasangan yang riwayatnya panjang, sedangkan putaran yang gagal hanya
     * membatalkan putaran itu — tidak ada notifikasi yang setengah terhapus
     * dengan jejak bacanya tertinggal (butir 257).
     *
     * @param  array<int, int>  $ids
     */
    protected function deleteChunk(array $ids): int
    {
        return DB::transaction(function () use ($ids): int {
            $deleted = 0;

            $notifications = Notification::query()
                ->withoutGlobalScope(SchoolScope::class)
                ->whereIn('id', $ids)
                ->get();

            foreach ($notifications as $notification) {
                $notification->delete();
                $deleted++;
            }

            return $deleted;
        });
    }
}
