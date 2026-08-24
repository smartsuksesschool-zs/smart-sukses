<?php

namespace App\Filament\Resources\NotificationResource\Pages;

use App\Filament\Resources\NotificationResource;
use App\Services\Notification\AnnouncementPublisher;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * NOTIF-01 — dua tindakan yang eksplisit: simpan sebagai draf, atau kirim.
 *
 * Penulisannya diserahkan ke AnnouncementPublisher alih-alih ke `create()`
 * bawaan Filament, supaya cabang, pengirim, dan target diperiksa dengan aturan
 * yang sama dengan endpoint API (butir 200).
 */
class CreateNotification extends CreateRecord
{
    protected static string $resource = NotificationResource::class;

    /** Diisi tombol mana yang ditekan sebelum record dibuat. */
    protected bool $shouldSend = false;

    protected function getFormActions(): array
    {
        return [
            Actions\Action::make('saveDraft')
                ->label('Simpan Draf')
                ->color('gray')
                ->action(fn () => $this->submitAs(send: false)),

            Actions\Action::make('send')
                ->label('Kirim Sekarang')
                ->requiresConfirmation()
                ->modalHeading('Kirim pengumuman')
                ->modalDescription('Setelah terkirim, isi dan target pengumuman tidak dapat diubah lagi.')
                ->action(fn () => $this->submitAs(send: true)),

            $this->getCancelFormAction(),
        ];
    }

    protected function submitAs(bool $send): void
    {
        $this->shouldSend = $send;

        $this->create();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(AnnouncementPublisher::class)->create($data, Auth::user(), $this->shouldSend);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return $this->shouldSend ? 'Pengumuman terkirim' : 'Draf pengumuman tersimpan';
    }
}
