<?php

namespace App\Filament\Resources\NotificationResource\Pages;

use App\Filament\Resources\NotificationResource;
use App\Models\Notification;
use App\Services\Notification\AnnouncementPublisher;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Hanya draf yang sampai di sini — NotificationPolicy::update() menolak
 * pengumuman yang sudah terkirim (butir 195).
 */
class EditNotification extends EditRecord
{
    protected static string $resource = NotificationResource::class;

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

        $this->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Notification $record */
        return app(AnnouncementPublisher::class)
            ->update($record, $data, Auth::user(), $this->shouldSend);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
