<?php

namespace App\Filament\Pages;

use App\Services\Notification\NotificationCenter;
use App\Services\Notification\NotificationPresenter;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;

/**
 * NOTIF-04 di panel admin — kotak masuk penerima bagi pengguna yang bekerja di
 * panel, bukan di portal.
 *
 * Batch 8.2 menutup NOTIF-04 untuk orang tua, guru, dan siswa. Yang tertinggal
 * adalah pengguna panel: Admin Sekolah, Kepala Sekolah, dan Bendahara
 * benar-benar menerima notifikasi bertarget ALL — resolver memasukkan mereka
 * sebagai pengguna aktif cabang (butir 198) — tetapi tidak punya satu pun tempat
 * membacanya selain `GET /notifications`. NOTIF-04 berlaku untuk "Pengguna",
 * tanpa pengecualian peran, jadi halaman ini melengkapinya (butir 217).
 *
 * **Halaman ini bukan Pengumuman.** Keduanya sengaja berdampingan di grup
 * navigasi yang sama dan sengaja berbeda judul, ikon, dan isi:
 *
 *  - `NotificationResource` ("Pengumuman") — sisi **pembuat**: menulis, mengirim,
 *    dan riwayat satu cabang termasuk draf. Dipagari `notification.manage`.
 *  - Halaman ini ("Notifikasi Saya") — sisi **penerima**: hanya yang ditujukan
 *    kepada pengguna yang sedang masuk. Tidak dipagari izin sama sekali, karena
 *    kepenerimaan bukan soal izin (butir 203, 218).
 *
 * Seluruh pertanyaan "notifikasi mana milik pengguna ini" tetap dijawab
 * NotificationCenter. Tidak ada `Notification::query()` di halaman ini.
 */
class NotifikasiSaya extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-bell';

    protected static ?string $navigationGroup = 'Komunikasi';

    protected static ?string $navigationLabel = 'Notifikasi Saya';

    protected static ?string $title = 'Notifikasi Saya';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'notifikasi-saya';

    protected static string $view = 'filament.pages.notifikasi-saya';

    /**
     * Notifikasi yang isinya sedang dibentangkan.
     *
     * Halaman selalu dibuka tanpa satu pun terbentang: memuat halaman bukan
     * tindakan membaca, dan hanya klik yang menandai — NOTIF-04 poin 2 menyebut
     * klik, bukan tampil (butir 209).
     */
    public ?int $openId = null;

    /**
     * Terbuka untuk setiap pengguna panel, dan hanya itu syaratnya.
     *
     * Tidak ada izin yang diperiksa di sini secara sengaja: menuntut
     * `notification.manage` akan menutup halaman ini bagi Bendahara dan Guru,
     * yang justru penerima sah — dan akan menyamakan "boleh membuat" dengan
     * "boleh membaca miliknya sendiri", dua hal yang blueprint pisahkan
     * (butir 218). Isi halamannya sendiri sudah dipagari kepenerimaan, sehingga
     * pengguna yang bukan penerima apa pun melihat halaman kosong, bukan
     * notifikasi orang lain.
     */
    public static function canAccess(): bool
    {
        return Auth::check();
    }

    /**
     * NOTIF-04 poin 1 — lencana belum dibaca pada navigasi.
     *
     * Memakai `unreadCount()` kanonik: satu agregat yang tidak menarik satu baris
     * notifikasi pun. Nol tidak dicetak — lencananya hilang.
     *
     * Di halaman ini sendiri lencananya sengaja tidak ditampilkan. Filament
     * merender navigasi sebagai bagian dari tata letak, yang tidak ikut dirender
     * ulang ketika aksi Livewire berjalan, sehingga angkanya akan tertinggal basi
     * tepat di halaman tempat pengguna menandai bacaannya. Halaman ini menyebut
     * jumlahnya sendiri, hidup, di kepalanya (butir 219).
     */
    public static function getNavigationBadge(): ?string
    {
        if (request()->routeIs(static::getRouteName())) {
            return null;
        }

        $user = Auth::user();

        if ($user === null) {
            return null;
        }

        $unread = app(NotificationCenter::class)->unreadCount($user);

        return $unread > 0 ? (string) $unread : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /**
     * NOTIF-04 poin 2 — "Klik notifikasi menandainya sebagai dibaca".
     *
     * Penandaannya diserahkan NotificationCenter, yang memeriksa kepenerimaan
     * lebih dulu: notifikasi yang bukan miliknya menjadi 404, sama seperti di API
     * dan di portal (butir 204). Idempoten — klik kedua tidak membuat baris
     * bacaan kedua dan tidak mengubah `read_at` pertama (butir 192).
     */
    public function open(int $notificationId): void
    {
        if ($this->openId === $notificationId) {
            $this->openId = null;

            return;
        }

        try {
            app(NotificationCenter::class)->markRead(Auth::user(), $notificationId);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        $this->openId = $notificationId;
    }

    /**
     * API 4.10 / NOTIF-04 — "Tandai semua notifikasi sebagai dibaca".
     *
     * Implementasi kanonik Batch 8.1: yang tertandai hanya yang benar-benar
     * ditujukan kepada pengguna ini — draf, notifikasi cabang lain, dan
     * notifikasi milik orang lain tidak tersentuh.
     */
    public function markAllRead(): void
    {
        app(NotificationCenter::class)->markAllRead(Auth::user());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function items(): array
    {
        return app(NotificationPresenter::class)->details(
            app(NotificationCenter::class)->feed(Auth::user()),
        );
    }

    public function unreadCount(): int
    {
        return app(NotificationCenter::class)->unreadCount(Auth::user());
    }
}
