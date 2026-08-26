<?php

namespace App\Filament\Resources\NotificationResource\Pages;

use App\Enums\NotificationTargetType;
use App\Filament\Resources\NotificationResource;
use App\Services\Notification\NotificationWaLinkService;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * NOTIF-02 — "Daftar Link WhatsApp" untuk satu pengumuman.
 *
 * Halaman ini milik sisi **pembuat**: ia hidup di bawah Pengumuman, bukan di
 * Notifikasi Saya. Kotak masuk penerima memperlihatkan apa yang ditujukan
 * kepada pengguna itu sendiri; halaman ini memperlihatkan kepada siapa saja
 * sebuah pengumuman ditujukan, lengkap dengan nomor mereka — dua pertanyaan
 * yang berbeda, dan pemisahan itu yang dijaga sejak butir 218 (butir 231).
 *
 * Tidak ada panggilan HTTP ke API sendiri: yang dipakai service domain yang
 * sama, sehingga daftar di panel dan di API tidak dapat berbeda.
 */
class NotificationWaLinks extends Page
{
    use InteractsWithRecord;

    protected static string $resource = NotificationResource::class;

    protected static string $view = 'filament.resources.notification-resource.pages.notification-wa-links';

    protected static ?string $title = 'Daftar Link WhatsApp';

    /** NOTIF-02 poin 2 — pencarian nama atau nomor. */
    public string $search = '';

    /** Kosong berarti semua; selain itu "available" atau "unavailable". */
    public string $availability = '';

    public function mount(int|string $record): void
    {
        // resolveRecord() memakai query model lengkap dengan SchoolScope, jadi
        // pengumuman cabang lain berakhir 404 — bukan 403 yang justru
        // mengonfirmasi keberadaannya.
        $this->record = $this->resolveRecord($record);

        abort_unless(Auth::user()?->can('waLinks', $this->record) ?? false, 403);
    }

    /**
     * Daftar tautan, atau NULL bila pengumumannya masih draf.
     *
     * Untuk draf, service-nya tidak dipanggil sama sekali: tidak ada nomor
     * telepon yang dibaca untuk sesuatu yang memang belum boleh dikirim
     * (butir 224).
     *
     * @return array<string, mixed>|null
     */
    public function links(): ?array
    {
        if (! $this->record->isSent()) {
            return null;
        }

        return app(NotificationWaLinkService::class)->linksFor($this->record, [
            'search' => $this->search,
            'availability' => $this->availability,
        ]);
    }

    /**
     * Penjelasan singkat tentang siapa yang masuk daftar ini.
     *
     * Berguna justru saat daftarnya terasa terlalu pendek: target CLASS hanya
     * menjangkau orang tua, bukan siswa atau gurunya (NOTIF-01 poin 3).
     */
    public function targetSummary(): string
    {
        $target = $this->record->target_type;

        if (! $target instanceof NotificationTargetType) {
            return '';
        }

        return $target->label();
    }

    public function getSubheading(): ?string
    {
        return $this->record->title;
    }
}
