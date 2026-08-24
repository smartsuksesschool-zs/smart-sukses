<?php

namespace App\Livewire\Portal;

use App\Services\Notification\NotificationCenter;
use App\Services\Notification\NotificationPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * NOTIF-04 — halaman Notifikasi untuk ketiga portal.
 *
 * Satu komponen, tiga rute. Orang tua, guru, dan siswa membaca kotak masuk
 * dengan aturan yang sama persis, jadi tiga komponen akan berarti tiga salinan
 * dari perilaku yang identik — dan ketika salah satu diperbaiki, dua lainnya
 * tertinggal. Yang membedakan ketiga rute adalah middleware portalnya, bukan
 * isi halaman ini (butir 208).
 *
 * Komponen ini tidak menyaring penerima sama sekali: seluruh pertanyaan
 * "notifikasi mana milik pengguna ini" tetap dijawab NotificationCenter, yang
 * juga menjadi pagar keamanannya. Peran pengguna tidak pernah diperiksa di
 * sini, karena kepenerimaan bukan soal peran (butir 203).
 */
class NotificationInbox extends Component
{
    /**
     * Notifikasi yang isinya sedang dibentangkan.
     *
     * Halaman selalu dibuka dalam keadaan tidak ada yang terbentang: memuat
     * halaman bukan tindakan membaca, dan hanya klik yang menandai terbaca —
     * NOTIF-04 poin 2 menyebut klik, bukan tampil (butir 209).
     */
    public ?int $openId = null;

    /**
     * NOTIF-04 poin 2 — "Klik notifikasi menandainya sebagai dibaca".
     *
     * Satu tindakan, dua akibat yang tidak dapat dipisahkan: pesannya terbuka
     * dan notifikasinya tertandai. Penandaannya diserahkan NotificationCenter,
     * yang memeriksa kepenerimaan lebih dulu — notifikasi yang bukan miliknya
     * menjadi 404, sama seperti di API (butir 204).
     *
     * Idempoten: klik kedua tidak membuat baris bacaan kedua dan tidak mengubah
     * `read_at` yang pertama (butir 192).
     */
    public function open(int $notificationId): void
    {
        // Klik pada notifikasi yang sedang terbentang menutupnya kembali, dan
        // tidak perlu menandai apa pun lagi.
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
     * Memakai implementasi kanonik Batch 8.1, jadi yang tertandai hanya yang
     * benar-benar ditujukan kepada pengguna ini: draf, notifikasi cabang lain,
     * dan notifikasi milik orang lain tidak tersentuh. Tanpa notifikasi belum
     * dibaca, ini tidak melakukan apa pun.
     */
    public function markAllRead(): void
    {
        app(NotificationCenter::class)->markAllRead(Auth::user());
    }

    public function render(): View
    {
        $user = Auth::user();
        $center = app(NotificationCenter::class);
        $presenter = app(NotificationPresenter::class);

        // Satu query untuk daftarnya (ditambah satu untuk nama pengirim), dan
        // satu agregat untuk hitungannya — tidak ada satu query per notifikasi
        // (butir 197).
        $items = $presenter->details($center->feed($user));
        $unreadCount = $center->unreadCount($user);

        return view('livewire.portal.notification-inbox', [
            'items' => $items,
            'unreadCount' => $unreadCount,
        ])->layout('layouts.portal', ['title' => 'Notifikasi']);
    }
}
