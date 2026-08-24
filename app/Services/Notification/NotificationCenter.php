<?php

namespace App\Services\Notification;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\NotificationRead;
use App\Models\Scopes\SchoolScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * NOTIF-04 — sisi penerima: daftar notifikasi, lencana belum dibaca, dan
 * penandaan terbaca.
 *
 * Seluruh pertanyaan "notifikasi mana yang milik pengguna ini" dijawab
 * NotificationRecipientResolver, bukan ditulis ulang di sini (butir 196).
 */
class NotificationCenter
{
    /** API 4.10 — "Limit: 50 terbaru". */
    public const FEED_LIMIT = 50;

    public function __construct(protected NotificationRecipientResolver $recipients) {}

    /**
     * Notifikasi yang terlihat oleh pengguna ini.
     *
     * `withRead()` menyertakan keadaan terbaca lewat satu subquery, bukan satu
     * query per baris — dan membaca daftar **tidak pernah** mengubah keadaan
     * itu (butir 203).
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Notification>
     */
    public function feed(User $user, array $filters = []): Collection
    {
        $query = $this->visible($user)
            ->with('sender:id,name')
            ->when(
                ($filters['type'] ?? null) instanceof NotificationType,
                fn (Builder $q) => $q->where('type', $filters['type']->value),
            );

        $query = $this->withReadState($query, $user);

        // Disaring lewat subquery yang sama dengan `unreadCount()`, bukan lewat
        // alias `read_at` hasil `selectSub`: MySQL tidak mengizinkan alias
        // SELECT dipakai di WHERE, sedangkan SQLite mengizinkannya — dan
        // perbedaan itu hanya terlihat saat suite dijalankan terhadap MySQL.
        if (array_key_exists('is_read', $filters) && $filters['is_read'] !== null) {
            $query = $filters['is_read']
                ? $query->whereExists(fn ($read) => $this->readSubquery($read, $user))
                : $query->whereNotExists(fn ($read) => $this->readSubquery($read, $user));
        }

        return $query
            // Urutan tetap: terbaru dulu, `id` sebagai pemutus supaya dua
            // notifikasi yang terbit pada detik yang sama tidak berpindah
            // tempat antar permintaan.
            ->orderByDesc('sent_at')
            ->orderByDesc('notifications.id')
            ->limit(self::FEED_LIMIT)
            ->get();
    }

    /**
     * API 4.10 — "Jumlah notifikasi belum dibaca (untuk badge)".
     *
     * Satu query agregat: tidak menarik notifikasinya, hanya menghitung.
     */
    public function unreadCount(User $user): int
    {
        return $this->visible($user)
            ->whereNotExists(fn ($read) => $this->readSubquery($read, $user))
            ->count();
    }

    /**
     * Satu notifikasi yang benar-benar ditujukan kepada pengguna ini.
     *
     * Notifikasi yang bukan untuknya — termasuk draf dan milik cabang lain —
     * menjadi ModelNotFoundException, sehingga keberadaannya tidak
     * terkonfirmasi (butir 204).
     *
     * @throws ModelNotFoundException
     */
    public function show(User $user, int $notificationId): Notification
    {
        $query = $this->withReadState($this->visible($user), $user);

        return $query->with('sender:id,name')->findOrFail($notificationId);
    }

    /**
     * NOTIF-04 poin 2 — menandai notifikasi sebagai dibaca.
     *
     * Idempoten: pemanggilan kedua tidak membuat baris kedua dan tidak
     * mengubah `read_at` yang pertama — kolom itu berarti "waktu **pertama
     * kali** dibaca". `firstOrCreate` dipagari indeks unik, sehingga dua
     * permintaan yang tiba bersamaan pun hanya menyisakan satu baris
     * (butir 192).
     *
     * @throws ModelNotFoundException
     */
    public function markRead(User $user, int $notificationId): NotificationRead
    {
        $notification = $this->visible($user)->findOrFail($notificationId);

        return $this->recordRead($user, (int) $notification->getKey());
    }

    /**
     * API 4.10 — POST /notifications/mark-all-read.
     *
     * @return int jumlah notifikasi yang baru ditandai
     */
    public function markAllRead(User $user): int
    {
        $unread = $this->visible($user)
            ->whereNotExists(fn ($read) => $this->readSubquery($read, $user))
            ->pluck('notifications.id');

        if ($unread->isEmpty()) {
            return 0;
        }

        $now = now();

        // Satu penyisipan untuk seluruhnya, bukan satu per notifikasi. Baris
        // yang sudah ada diabaikan oleh indeks unik, sehingga pemanggilan
        // berulang tetap idempoten dan tidak menimpa waktu baca pertama.
        DB::table('notification_reads')->insertOrIgnore(
            $unread->map(fn ($id) => [
                'notification_id' => $id,
                'user_id' => $user->getKey(),
                'read_at' => $now,
            ])->all(),
        );

        return $unread->count();
    }

    protected function recordRead(User $user, int $notificationId): NotificationRead
    {
        return NotificationRead::query()->firstOrCreate(
            ['notification_id' => $notificationId, 'user_id' => $user->getKey()],
            ['read_at' => now()],
        );
    }

    /**
     * Notifikasi terkirim yang ditujukan kepada pengguna ini.
     *
     * Global scope dilepas dan cabangnya disaring resolver dari akun
     * penggunanya: scope bergantung pada sesi, sedangkan pagar di sini harus
     * berasal dari argumen yang jelas.
     *
     * @return Builder<Notification>
     */
    protected function visible(User $user): Builder
    {
        $query = Notification::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->sent();

        return $this->recipients->visibleTo($query, $user);
    }

    /**
     * Menempelkan `read_at` pengguna ini sebagai subquery.
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    protected function withReadState(Builder $query, User $user): Builder
    {
        return $query
            ->select('notifications.*')
            ->selectSub(
                NotificationRead::query()
                    ->select('read_at')
                    ->whereColumn('notification_reads.notification_id', 'notifications.id')
                    ->where('notification_reads.user_id', $user->getKey())
                    ->limit(1),
                'read_at',
            );
    }

    /**
     * Pivot tidak punya `school_id`, jadi ia tidak pernah dipakai sebagai pagar
     * cabang — ia selalu disandarkan pada notifikasi yang sudah tersaring
     * (butir 193).
     */
    protected function readSubquery($read, User $user)
    {
        return $read->from('notification_reads')
            ->whereColumn('notification_reads.notification_id', 'notifications.id')
            ->where('notification_reads.user_id', $user->getKey());
    }
}
